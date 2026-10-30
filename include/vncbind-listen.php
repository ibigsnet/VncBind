<?php
/**
 * VNC Bind daemon.
 *  - Watches `virsh event --event lifecycle --loop`. On Defined it corrects a
 *    saved VNC listen on every interface to the local console address; on
 *    Started/Stopped/Undefined it rebuilds the forwarding plan right away
 *    (stopped VMs release their port before the next start picks one).
 *  - Forwards each checked host address to the VM console on 127.0.0.1.
 *  - Full sweep every 60 s (and when poked) in case an event was missed.
 */
require_once __DIR__ . '/vncbind-lib.php';

const VNCBIND_SWEEP_SECONDS = 60;
const VNCBIND_MAX_PEERS = 64;
const VNCBIND_MAX_PER_CLIENT = 4;
const VNCBIND_CONNECT_TIMEOUT = 10;
const VNCBIND_FIRST_BYTE_TIMEOUT = 60;
const VNCBIND_IDLE_TIMEOUT = 900;
const VNCBIND_BUF_MAX = 262144;

function vncbind_listen_open(string $addr, int $port) {
    $opts = ['so_reuseaddr' => true, 'backlog' => 32];
    if (filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $opts['ipv6_v6only'] = true;
    }
    $errno = 0;
    $errstr = '';
    $sock = @stream_socket_server(
        vncbind_tcp_uri($addr, $port),
        $errno,
        $errstr,
        STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
        stream_context_create(['socket' => $opts])
    );
    if (!is_resource($sock)) {
        return null;
    }
    stream_set_blocking($sock, false);
    return $sock;
}

/**
 * Open/close listeners to match the plan. Failed binds back off (2 s doubling
 * to 60 s) and are logged once per key until they succeed.
 */
function vncbind_listen_sync(array &$listeners, array &$backoff, array $plan): void {
    $want = [];
    foreach ($plan as $row) {
        $port = (int)($row['port'] ?? 0);
        $bind = (string)($row['bind'] ?? '');
        $dest = (string)($row['dest'] ?? '');
        if ($port < 1 || $port > 65535 || !vncbind_valid_addr($bind) || !vncbind_valid_addr($dest)) {
            continue;
        }
        $want[vncbind_listen_key($bind, $port)] = ['bind' => $bind, 'port' => $port, 'dest' => $dest];
    }
    foreach ($listeners as $key => $slot) {
        if (!isset($want[$key]) || !vncbind_same_addr($slot['dest'], $want[$key]['dest'])) {
            fclose($slot['socket']);
            unset($listeners[$key]);
        }
    }
    foreach ($backoff as $key => $_) {
        if (!isset($want[$key])) {
            unset($backoff[$key]);
        }
    }
    $now = time();
    foreach ($want as $key => $row) {
        if (isset($listeners[$key]) || ($backoff[$key]['next'] ?? 0) > $now) {
            continue;
        }
        $sock = vncbind_listen_open($row['bind'], $row['port']);
        if (!is_resource($sock)) {
            $n = ($backoff[$key]['n'] ?? 0) + 1;
            if ($n === 1) {
                vncbind_log('listen ' . $row['bind'] . ' port ' . $row['port'] . ' failed; retrying with backoff');
            }
            $backoff[$key] = ['n' => $n, 'next' => $now + min(60, 2 ** $n)];
            continue;
        }
        if (isset($backoff[$key])) {
            vncbind_log('listen ' . $row['bind'] . ' port ' . $row['port'] . ' open');
        }
        unset($backoff[$key]);
        $listeners[$key] = ['socket' => $sock, 'dest' => $row['dest'], 'port' => $row['port'], 'bind' => $row['bind']];
    }
}

function vncbind_listen_close_peer(array &$peers, int $id): void {
    if (!isset($peers[$id])) {
        return;
    }
    foreach (['left', 'right'] as $side) {
        if (is_resource($peers[$id][$side])) {
            fclose($peers[$id][$side]);
        }
    }
    unset($peers[$id]);
}

function vncbind_client_ip($sock): string {
    $name = (string)@stream_socket_get_name($sock, true);
    if (preg_match('/^\[?([0-9A-Fa-f:.]+?)\]?:\d+$/', $name, $m)) {
        return $m[1];
    }
    return $name;
}

/** Start `virsh event` for lifecycle events; returns [proc, stdout] or null. */
function vncbind_event_open(): ?array {
    $argv = [vncbind_virsh_bin(), 'event', '--event', 'lifecycle', '--loop'];
    if (is_executable('/usr/bin/stdbuf')) {
        array_unshift($argv, '/usr/bin/stdbuf', '-oL');
    }
    $proc = @proc_open($argv, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    if (!is_resource($proc)) {
        return null;
    }
    stream_set_blocking($pipes[1], false);
    $ev = ['proc' => $proc, 'out' => $pipes[1], 'buf' => '', 'start' => time()];
    $GLOBALS['vncbind_event_proc'] = $proc;
    return $ev;
}

function vncbind_event_close(?array &$ev): void {
    if ($ev === null) {
        return;
    }
    if (is_resource($ev['out'])) {
        fclose($ev['out']);
    }
    @proc_terminate($ev['proc']);
    @proc_close($ev['proc']);
    $GLOBALS['vncbind_event_proc'] = null;
    $ev = null;
}

/**
 * Parse "event 'lifecycle' for domain 'NAME': Defined Updated".
 * @return array{domain:string,event:string}|null
 */
function vncbind_event_parse(string $line): ?array {
    if (!preg_match("/^event 'lifecycle' for domain '(.*)': (\\S+)/", trim($line), $m)) {
        return null;
    }
    return ['domain' => $m[1], 'event' => $m[2]];
}

/**
 * @param array|null $fixed fixed plan (self-test); null = live plan
 * @param int|null $until stop time (self-test)
 */
function vncbind_listen_serve(?array $fixed, ?int $until): void {
    $listeners = [];
    $backoff = [];
    $peers = [];
    $plan = [];
    $ev = null;
    $evRetry = 0;
    $evDelay = 5;
    $nextSweep = 0;
    $dirty = true;
    $pokeSeen = (int)@filemtime(VNCBIND_POKE);
    $seq = 0;

    while ($until === null || time() < $until) {
        $now = time();
        if ($fixed !== null) {
            $plan = $fixed;
        } else {
            if ($ev === null && $now >= $evRetry) {
                $ev = vncbind_event_open();
                if ($ev === null) {
                    $evRetry = $now + $evDelay;
                    $evDelay = min(60, $evDelay * 2);
                }
            }
            clearstatcache(true, VNCBIND_POKE);
            $poke = (int)@filemtime(VNCBIND_POKE);
            if ($poke !== $pokeSeen) {
                $pokeSeen = $poke;
                $dirty = true;
            }
            if ($now >= $nextSweep) {
                $domains = vncbind_domain_list();
                if ($domains !== null) {
                    vncbind_enforce_all($domains);
                    $plan = vncbind_listen_plan($domains);
                }
                // While events are down, sweep more often.
                $nextSweep = $now + ($ev !== null ? VNCBIND_SWEEP_SECONDS : 10);
                $dirty = false;
            } elseif ($dirty) {
                $plan = vncbind_listen_plan();
                $dirty = false;
            }
        }
        vncbind_listen_sync($listeners, $backoff, $plan);

        // Peer housekeeping: connect / first byte / idle timeouts.
        foreach ($peers as $id => $p) {
            if (!$p['ready'] && $now - $p['since'] > VNCBIND_CONNECT_TIMEOUT
                || !$p['got'] && $now - $p['since'] > VNCBIND_FIRST_BYTE_TIMEOUT
                || $now - $p['active'] > VNCBIND_IDLE_TIMEOUT
                || !isset($listeners[$p['key']])) {
                vncbind_listen_close_peer($peers, $id);
            }
        }

        $read = [];
        $write = [];
        $map = [];
        if ($ev !== null && is_resource($ev['out'])) {
            $read[] = $ev['out'];
            $map[(int)$ev['out']] = ['kind' => 'event'];
        }
        foreach ($listeners as $key => $slot) {
            $read[] = $slot['socket'];
            $map[(int)$slot['socket']] = ['kind' => 'listen', 'key' => $key];
        }
        foreach ($peers as $id => $peer) {
            if (is_resource($peer['left']) && strlen($peer['to_right']) < VNCBIND_BUF_MAX) {
                $read[] = $peer['left'];
                $map[(int)$peer['left']] = ['kind' => 'peer', 'id' => $id, 'side' => 'left'];
            }
            if (is_resource($peer['right']) && $peer['ready'] && strlen($peer['to_left']) < VNCBIND_BUF_MAX) {
                $read[] = $peer['right'];
                $map[(int)$peer['right']] = ['kind' => 'peer', 'id' => $id, 'side' => 'right'];
            }
            if (is_resource($peer['right']) && (!$peer['ready'] || $peer['to_right'] !== '')) {
                $write[] = $peer['right'];
                $map[(int)$peer['right'] . 'w'] = ['kind' => 'write', 'id' => $id, 'side' => 'right'];
            }
            if (is_resource($peer['left']) && $peer['ready'] && $peer['to_left'] !== '') {
                $write[] = $peer['left'];
                $map[(int)$peer['left'] . 'w'] = ['kind' => 'write', 'id' => $id, 'side' => 'left'];
            }
        }
        if (!$read && !$write) {
            sleep(1);
            continue;
        }
        $except = null;
        $n = @stream_select($read, $write, $except, 1);
        if ($n === false || $n === 0) {
            continue;
        }
        foreach ($read as $sock) {
            $info = $map[(int)$sock] ?? null;
            if (!$info) {
                continue;
            }
            if ($info['kind'] === 'event') {
                $chunk = @fread($ev['out'], 8192);
                if ($chunk === false || ($chunk === '' && feof($ev['out']))) {
                    if (time() - $ev['start'] > 10) {
                        vncbind_log('libvirt event stream ended; reconnecting');
                    }
                    vncbind_event_close($ev);
                    $evRetry = time() + $evDelay;
                    $evDelay = min(60, $evDelay * 2);
                    $nextSweep = 0;
                    continue;
                }
                $evDelay = 5;
                $ev['buf'] .= $chunk;
                while (($nl = strpos($ev['buf'], "\n")) !== false) {
                    $line = substr($ev['buf'], 0, $nl);
                    $ev['buf'] = substr($ev['buf'], $nl + 1);
                    $e = vncbind_event_parse($line);
                    if ($e === null) {
                        continue;
                    }
                    if ($e['event'] === 'Defined') {
                        vncbind_enforce_domain($e['domain']);
                    }
                    if ($e['event'] === 'Undefined') {
                        $nextSweep = 0;
                    }
                    $dirty = true;
                }
                continue;
            }
            if ($info['kind'] === 'listen') {
                $client = @stream_socket_accept($sock, 0);
                $slot = $listeners[$info['key']] ?? null;
                if (!is_resource($client)) {
                    continue;
                }
                $ip = vncbind_client_ip($client);
                $same = 0;
                foreach ($peers as $p) {
                    if ($p['ip'] === $ip) {
                        $same++;
                    }
                }
                if (!$slot || count($peers) >= VNCBIND_MAX_PEERS || $same >= VNCBIND_MAX_PER_CLIENT) {
                    fclose($client);
                    continue;
                }
                stream_set_blocking($client, false);
                $errno = 0;
                $errstr = '';
                $remote = @stream_socket_client(
                    vncbind_tcp_uri($slot['dest'], $slot['port']),
                    $errno,
                    $errstr,
                    0,
                    STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT
                );
                if (!is_resource($remote)) {
                    fclose($client);
                    continue;
                }
                stream_set_blocking($remote, false);
                $seq++;
                $peers[$seq] = [
                    'left' => $client, 'right' => $remote, 'to_right' => '', 'to_left' => '',
                    'ready' => false, 'got' => false, 'ip' => $ip, 'key' => $info['key'],
                    'since' => time(), 'active' => time(),
                ];
                continue;
            }
            if ($info['kind'] !== 'peer' || !isset($peers[$info['id']])) {
                continue;
            }
            $id = $info['id'];
            $side = $info['side'];
            $chunk = @fread($peers[$id][$side], 8192);
            if ($chunk === false || $chunk === '') {
                if (!is_resource($peers[$id][$side]) || feof($peers[$id][$side])) {
                    vncbind_listen_close_peer($peers, $id);
                }
                continue;
            }
            $peers[$id]['active'] = time();
            if ($side === 'left') {
                $peers[$id]['to_right'] .= $chunk;
                $peers[$id]['got'] = true;
            } else {
                $peers[$id]['to_left'] .= $chunk;
            }
        }
        foreach ($write as $sock) {
            $info = $map[(int)$sock . 'w'] ?? null;
            if (!$info || !isset($peers[$info['id']])) {
                continue;
            }
            $id = $info['id'];
            $peer = &$peers[$id];
            if ($info['side'] === 'right' && !$peer['ready']) {
                $peername = @stream_socket_get_name($peer['right'], true);
                if (!is_string($peername) || $peername === '') {
                    unset($peer);
                    vncbind_listen_close_peer($peers, $id);
                    continue;
                }
                $peer['ready'] = true;
            }
            $buf = $info['side'] === 'right' ? 'to_right' : 'to_left';
            $dest = $info['side'] === 'right' ? 'right' : 'left';
            if ($peer[$buf] === '' || !is_resource($peer[$dest])) {
                unset($peer);
                continue;
            }
            $wrote = @fwrite($peer[$dest], $peer[$buf]);
            if ($wrote === false || $wrote === 0) {
                if (feof($peer[$dest])) {
                    unset($peer);
                    vncbind_listen_close_peer($peers, $id);
                    continue;
                }
                unset($peer);
                continue;
            }
            $peer[$buf] = substr($peer[$buf], $wrote);
            $peer['active'] = time();
            unset($peer);
        }
    }
    vncbind_event_close($ev);
    foreach (array_keys($peers) as $id) {
        vncbind_listen_close_peer($peers, $id);
    }
    foreach ($listeners as $slot) {
        if (is_resource($slot['socket'])) {
            fclose($slot['socket']);
        }
    }
}

function vncbind_listen_lock() {
    $fh = @fopen(VNCBIND_LISTEN_PID, 'ce');
    if (!is_resource($fh) || !@flock($fh, LOCK_EX | LOCK_NB)) {
        if (is_resource($fh)) {
            fclose($fh);
        }
        return null;
    }
    ftruncate($fh, 0);
    fwrite($fh, (string)getmypid());
    fflush($fh);
    return $fh;
}

/** Dev check: forward 127.0.0.2:port → 127.0.0.1:port and round-trip a line. */
function vncbind_listen_selftest(): int {
    $errno = 0;
    $errstr = '';
    $echo = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if (!is_resource($echo) || !function_exists('pcntl_fork')) {
        fwrite(STDERR, "selftest needs pcntl and a local socket\n");
        return 1;
    }
    $port = (int)substr((string)strrchr((string)stream_socket_get_name($echo, false), ':'), 1);
    $pid = pcntl_fork();
    if ($pid === 0) {
        fclose($echo);
        vncbind_listen_serve([['bind' => '127.0.0.2', 'port' => $port, 'dest' => '127.0.0.1']], time() + 4);
        exit(0);
    }
    usleep(300000);
    $client = @stream_socket_client('tcp://127.0.0.2:' . $port, $errno, $errstr, 2);
    $peer = is_resource($client) ? @stream_socket_accept($echo, 2) : false;
    $got = '';
    if (is_resource($peer)) {
        fwrite($client, "ping\n");
        $line = fgets($peer);
        if ($line === "ping\n") {
            fwrite($peer, "pong\n");
            $got = (string)fgets($client);
        }
    }
    @exec('kill ' . (int)$pid);
    pcntl_wait($status);
    if ($got !== "pong\n") {
        fwrite(STDERR, 'got ' . json_encode($got) . "\n");
        return 1;
    }
    echo "ok\n";
    return 0;
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath((string)$argv[0]) === realpath(__FILE__)) {
    if (($argv[1] ?? '') === 'selftest') {
        exit(vncbind_listen_selftest());
    }
    if (($argv[1] ?? '') === 'stop') {
        vncbind_listen_stop();
        exit(0);
    }
    $lock = vncbind_listen_lock();
    if (!is_resource($lock)) {
        exit(0);
    }
    register_shutdown_function(static function (): void {
        $p = $GLOBALS['vncbind_event_proc'] ?? null;
        if (is_resource($p)) {
            @proc_terminate($p);
        }
    });
    if (function_exists('pcntl_async_signals')) {
        pcntl_async_signals(true);
        pcntl_signal(15, static function (): void {
            exit(0);
        });
    }
    vncbind_listen_serve(null, null);
}
