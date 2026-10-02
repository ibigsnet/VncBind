<?php
require_once __DIR__ . '/vncbind-lib.php';

function vncbind_listen_open(string $addr, int $port) {
    $opts = [
        'so_reuseaddr' => true,
        'backlog' => 32,
    ];
    if (filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $opts['ipv6_v6only'] = true;
    }
    $ctx = stream_context_create(['socket' => $opts]);
    $errno = 0;
    $errstr = '';
    $sock = @stream_socket_server(
        vncbind_tcp_uri($addr, $port),
        $errno,
        $errstr,
        STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
        $ctx
    );
    if (!is_resource($sock)) {
        syslog(LOG_WARNING, 'listen ' . $addr . ' ' . $port . ' failed');
        return null;
    }
    stream_set_blocking($sock, false);
    return $sock;
}

function vncbind_listen_sync(array &$listeners, array $plan): void {
    $want = [];
    foreach ($plan as $row) {
        if (!isset($row['bind'], $row['port'], $row['dest'])) {
            continue;
        }
        $port = (int)$row['port'];
        $bind = (string)$row['bind'];
        $dest = (string)$row['dest'];
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
    foreach ($want as $key => $row) {
        if (isset($listeners[$key])) {
            continue;
        }
        $sock = vncbind_listen_open($row['bind'], $row['port']);
        if (!is_resource($sock)) {
            continue;
        }
        $listeners[$key] = ['socket' => $sock, 'dest' => $row['dest'], 'port' => $row['port']];
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

function vncbind_listen_serve(?array $fixed, ?int $until): void {
    openlog('vncbind', LOG_PID, LOG_DAEMON);
    $listeners = [];
    $peers = [];
    $plan = [];
    $next = 0;
    $seq = 0;
    while ($until === null || time() < $until) {
        if ($fixed !== null) {
            $plan = $fixed;
        } elseif (time() >= $next) {
            $plan = vncbind_listen_plan();
            $next = time() + 2;
        }
        vncbind_listen_sync($listeners, $plan);
        $read = [];
        $write = [];
        $map = [];
        foreach ($listeners as $key => $slot) {
            $read[] = $slot['socket'];
            $map[(int)$slot['socket']] = ['kind' => 'listen', 'key' => $key];
        }
        foreach ($peers as $id => $peer) {
            if (is_resource($peer['left']) && strlen($peer['to_right']) < 262144) {
                $read[] = $peer['left'];
                $map[(int)$peer['left']] = ['kind' => 'peer', 'id' => $id, 'side' => 'left'];
            }
            if (is_resource($peer['right']) && $peer['ready'] && strlen($peer['to_left']) < 262144) {
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
            if (!$info || $info['kind'] === 'write') {
                continue;
            }
            if ($info['kind'] === 'listen') {
                if (count($peers) >= 64) {
                    $extra = @stream_socket_accept($sock, 0);
                    if (is_resource($extra)) {
                        fclose($extra);
                    }
                    continue;
                }
                $client = @stream_socket_accept($sock, 0);
                $slot = $listeners[$info['key']] ?? null;
                if (!is_resource($client) || !$slot) {
                    if (is_resource($client)) {
                        fclose($client);
                    }
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
                    'left' => $client,
                    'right' => $remote,
                    'to_right' => '',
                    'to_left' => '',
                    'ready' => false,
                ];
                continue;
            }
            $id = $info['id'];
            if (!isset($peers[$id])) {
                continue;
            }
            $side = $info['side'];
            $chunk = @fread($peers[$id][$side], 8192);
            if ($chunk === false || $chunk === '') {
                if (!is_resource($peers[$id][$side]) || feof($peers[$id][$side])) {
                    vncbind_listen_close_peer($peers, $id);
                }
                continue;
            }
            if ($side === 'left') {
                $peers[$id]['to_right'] .= $chunk;
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
                    if (feof($peer['right'])) {
                        vncbind_listen_close_peer($peers, $id);
                    }
                    unset($peer);
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
                    vncbind_listen_close_peer($peers, $id);
                }
                unset($peer);
                continue;
            }
            $peer[$buf] = substr($peer[$buf], $wrote);
            unset($peer);
        }
    }
    foreach ($peers as $id => $_) {
        vncbind_listen_close_peer($peers, $id);
    }
    foreach ($listeners as $slot) {
        if (is_resource($slot['socket'])) {
            fclose($slot['socket']);
        }
    }
}

function vncbind_listen_lock() {
    $fh = @fopen(VNCBIND_LISTEN_PID, 'c');
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

function vncbind_listen_selftest(): int {
    $errno = 0;
    $errstr = '';
    $echo = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if (!is_resource($echo)) {
        fwrite(STDERR, "echo bind failed\n");
        return 1;
    }
    $name = stream_socket_get_name($echo, false);
    $port = (int)substr((string)strrchr((string)$name, ':'), 1);
    if ($port < 1) {
        return 1;
    }
    $pid = pcntl_fork();
    if ($pid === 0) {
        fclose($echo);
        vncbind_listen_serve([
            ['bind' => '127.0.0.2', 'port' => $port, 'dest' => '127.0.0.1'],
        ], time() + 4);
        exit(0);
    }
    if ($pid < 0) {
        return 1;
    }
    stream_set_blocking($echo, false);
    usleep(200000);
    $client = @stream_socket_client('tcp://127.0.0.2:' . $port, $errno, $errstr, 2);
    if (!is_resource($client)) {
        posix_kill($pid, SIGTERM);
        fwrite(STDERR, "client connect failed\n");
        return 1;
    }
    stream_set_blocking($client, false);
    $pending = "ping\n";
    $got = '';
    $reply = '';
    $peer = null;
    $end = microtime(true) + 3;
    while (microtime(true) < $end && $got !== "pong\n") {
        $read = [$echo, $client];
        $write = [];
        if ($pending !== '') {
            $write[] = $client;
        }
        if (is_resource($peer)) {
            $read[] = $peer;
            if ($reply !== '') {
                $write[] = $peer;
            }
        }
        $except = null;
        if (@stream_select($read, $write, $except, 0, 200000) === false) {
            continue;
        }
        if (in_array($echo, $read, true)) {
            $accepted = @stream_socket_accept($echo, 0);
            if (is_resource($accepted)) {
                stream_set_blocking($accepted, false);
                $peer = $accepted;
            }
        }
        if (is_resource($peer) && in_array($peer, $read, true)) {
            $chunk = @fread($peer, 64);
            if (is_string($chunk) && str_contains($chunk, 'ping')) {
                $reply = "pong\n";
            }
        }
        if (is_resource($peer) && $reply !== '' && in_array($peer, $write, true)) {
            $wrote = @fwrite($peer, $reply);
            if ($wrote !== false) {
                $reply = substr($reply, $wrote);
            }
        }
        if ($pending !== '' && in_array($client, $write, true)) {
            $wrote = @fwrite($client, $pending);
            if ($wrote !== false) {
                $pending = substr($pending, $wrote);
            }
        }
        if (in_array($client, $read, true)) {
            $chunk = @fread($client, 64);
            if (is_string($chunk)) {
                $got .= $chunk;
            }
        }
    }
    posix_kill($pid, SIGTERM);
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
    if (function_exists('pcntl_async_signals')) {
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, static function (): void {
            exit(0);
        });
    }
    vncbind_listen_serve(null, null);
}
