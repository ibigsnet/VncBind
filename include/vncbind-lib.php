<?php
const VNCBIND_CFG = '/boot/config/plugins/VncBind';
const VNCBIND_FILE = '/boot/config/plugins/VncBind/vncbind.json';
const VNCBIND_SUGGEST = '/boot/config/plugins/VncBind/suggestions.json';
const VNCBIND_ALERTS = '/boot/config/plugins/VncBind/alerts.json';
const VNCBIND_BINDS = '/boot/config/plugins/VncBind/binds.json';
const VNCBIND_EXTRA = '/boot/config/network-extra.cfg';
const VNCBIND_CRON = '/etc/cron.d/vncbind';
const VNCBIND_WALK = '/usr/local/emhttp/plugins/VncBind/scripts/vncbind-walk';
const VNCBIND_LISTEN = '/usr/local/emhttp/plugins/VncBind/include/vncbind-listen.php';
const VNCBIND_LISTEN_PID = '/var/run/vncbind-listen.pid';

function vncbind_csrf_expected(): string {
    if (!is_readable('/var/local/emhttp/var.ini')) {
        return '';
    }
    $vi = @parse_ini_file('/var/local/emhttp/var.ini');
    return is_array($vi) ? (string)($vi['csrf_token'] ?? '') : '';
}

function vncbind_csrf_submitted(): string {
    if (isset($_POST['csrf_token']) && is_string($_POST['csrf_token'])) {
        return $_POST['csrf_token'];
    }
    if (isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
        return (string)$_SERVER['HTTP_X_CSRF_TOKEN'];
    }
    if (function_exists('getallheaders')) {
        foreach ((array)getallheaders() as $k => $v) {
            if (strtolower((string)$k) === 'x-csrf-token') {
                return (string)$v;
            }
        }
    }
    $type = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    if (strpos($type, 'application/x-www-form-urlencoded') === 0) {
        $raw = (string)@file_get_contents('php://input', false, null, 0, 1048576);
        $body = [];
        parse_str($raw, $body);
        if (isset($body['csrf_token']) && is_string($body['csrf_token'])) {
            return $body['csrf_token'];
        }
    }
    return '';
}

function vncbind_csrf_ok(): bool {
    $expected = vncbind_csrf_expected();
    $got = vncbind_csrf_submitted();
    return $expected !== '' && strlen($expected) === strlen($got) && hash_equals($expected, $got);
}

function vncbind_defaults(): array {
    return [
        'interjection' => 'yes',
        'use_interface_extra' => 'yes',
        'schedule' => 'off',
        'alerting' => 'yes',
        'interfaces' => [],
    ];
}

function vncbind_ensure_cfg(): void {
    if (!is_dir(VNCBIND_CFG)) {
        @mkdir(VNCBIND_CFG, 0700, true);
    }
    @chmod(VNCBIND_CFG, 0700);
}

function vncbind_load_cfg(): array {
    $cfg = vncbind_defaults();
    if (!is_file(VNCBIND_FILE)) {
        return $cfg;
    }
    $raw = @file_get_contents(VNCBIND_FILE);
    $j = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($j)) {
        return $cfg;
    }
    $yn = static function ($v, string $fallback): string {
        return ($v === 'yes' || $v === 'no') ? $v : $fallback;
    };
    $cfg['interjection'] = $yn($j['interjection'] ?? '', 'yes');
    if (isset($j['use_interface_extra'])) {
        $cfg['use_interface_extra'] = $yn($j['use_interface_extra'], 'yes');
    } elseif (($j['ignore_interface_extra'] ?? '') === 'yes') {
        $cfg['use_interface_extra'] = 'no';
    } else {
        $cfg['use_interface_extra'] = 'yes';
    }
    $sched = (string)($j['schedule'] ?? 'off');
    $cfg['schedule'] = in_array($sched, ['off', 'daily', 'weekly'], true) ? $sched : 'off';
    $cfg['alerting'] = $yn($j['alerting'] ?? '', 'yes');
    $names = [];
    foreach ((array)($j['interfaces'] ?? []) as $name) {
        $name = (string)$name;
        if (vncbind_valid_iface($name)) {
            $names[$name] = true;
        }
    }
    $cfg['interfaces'] = array_keys($names);
    return $cfg;
}

function vncbind_save_cfg(array $cfg): bool {
    vncbind_ensure_cfg();
    $json = json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (!is_string($json)) {
        return false;
    }
    $tmp = VNCBIND_FILE . '.tmp';
    if (@file_put_contents($tmp, $json . "\n") === false) {
        return false;
    }
    @chmod($tmp, 0600);
    return @rename($tmp, VNCBIND_FILE);
}

function vncbind_valid_iface(string $name): bool {
    return $name !== '' && strlen($name) <= 32 && (bool)preg_match('/^[A-Za-z0-9._:-]+$/', $name);
}

function vncbind_valid_domain(string $name): bool {
    if ($name === '' || strlen($name) > 128) {
        return false;
    }
    return !preg_match('/[\x00-\x1F\x7F]/', $name);
}

function vncbind_valid_addr(string $addr): bool {
    if ($addr === '' || vncbind_is_wildcard($addr)) {
        return false;
    }
    return filter_var($addr, FILTER_VALIDATE_IP) !== false;
}

function vncbind_is_wildcard(string $addr): bool {
    $addr = strtolower(trim($addr));
    return $addr === '' || $addr === '0.0.0.0' || $addr === '::' || $addr === '::0';
}

function vncbind_interface_extra(): array {
    $out = ['include' => [], 'exclude' => []];
    if (!is_file(VNCBIND_EXTRA)) {
        return $out;
    }
    $ini = @parse_ini_file(VNCBIND_EXTRA);
    if (!is_array($ini)) {
        return $out;
    }
    foreach (['include' => 'include_interfaces', 'exclude' => 'exclude_interfaces'] as $key => $field) {
        foreach (preg_split('/\s+/', trim((string)($ini[$field] ?? ''))) ?: [] as $name) {
            if (vncbind_valid_iface($name)) {
                $out[$key][$name] = true;
            }
        }
    }
    return $out;
}

function vncbind_host_addrs(): array {
    $rows = [];
    foreach ([4, 6] as $family) {
        $out = [];
        $rc = 0;
        @exec('ip -' . $family . ' -o addr show scope global 2>/dev/null', $out, $rc);
        if ($rc !== 0) {
            continue;
        }
        foreach ($out as $line) {
            if (!preg_match('/^\d+:\s+(\S+)\s+inet6?\s+(\S+)/', $line, $m)) {
                continue;
            }
            $name = rtrim($m[1], ':');
            $addr = explode('/', $m[2])[0];
            if (!vncbind_valid_iface($name) || $name === 'lo' || !vncbind_valid_addr($addr)) {
                continue;
            }
            $rows[] = ['name' => $name, 'addr' => $addr];
        }
    }
    return $rows;
}

function vncbind_allowed_names(array $cfg): array {
    $extra = vncbind_interface_extra();
    $saved = $cfg['interfaces'];
    if ($cfg['use_interface_extra'] !== 'yes') {
        return $saved;
    }
    $base = ['br0' => true, 'bond0' => true, 'eth0' => true, 'wlan0' => true];
    foreach ($extra['include'] as $name => $_) {
        $base[$name] = true;
    }
    foreach ($extra['exclude'] as $name => $_) {
        unset($base[$name]);
    }
    if (!$saved) {
        return array_keys($base);
    }
    $keep = [];
    foreach ($saved as $name) {
        if (!isset($extra['exclude'][$name])) {
            $keep[] = $name;
        }
    }
    return $keep;
}

function vncbind_choices(array $cfg): array {
    $allow = array_fill_keys(vncbind_allowed_names($cfg), true);
    $choices = [];
    foreach (vncbind_host_addrs() as $row) {
        if (!isset($allow[$row['name']])) {
            continue;
        }
        $choices[] = $row;
    }
    return $choices;
}

function vncbind_choice_ok(array $cfg, string $addr): bool {
    if (!vncbind_valid_addr($addr)) {
        return false;
    }
    foreach (vncbind_choices($cfg) as $row) {
        if (strlen($row['addr']) === strlen($addr) && hash_equals($row['addr'], $addr)) {
            return true;
        }
    }
    return false;
}

function vncbind_addrs_ok(array $cfg, array $addrs): bool {
    foreach ($addrs as $addr) {
        if (!is_string($addr) || !vncbind_choice_ok($cfg, $addr)) {
            return false;
        }
    }
    return true;
}

function vncbind_request_addrs(): ?array {
    if (!isset($_POST['addr'])) {
        return [];
    }
    $raw = $_POST['addr'];
    if (is_string($raw)) {
        $raw = trim($raw) === '' ? [] : [$raw];
    }
    if (!is_array($raw)) {
        return null;
    }
    $out = [];
    foreach ($raw as $addr) {
        if (!is_string($addr)) {
            return null;
        }
        $addr = trim($addr);
        if ($addr === '') {
            continue;
        }
        $out[$addr] = true;
    }
    return array_keys($out);
}

function vncbind_console_addr(): string {
    return '127.0.0.1';
}

function vncbind_same_addr(string $a, string $b): bool {
    $pa = @inet_pton($a);
    $pb = @inet_pton($b);
    return is_string($pa) && is_string($pb) && $pa === $pb;
}

function vncbind_tcp_uri(string $addr, int $port): string {
    if (filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        return 'tcp://[' . $addr . ']:' . $port;
    }
    return 'tcp://' . $addr . ':' . $port;
}

function vncbind_load_binds(): array {
    if (!is_file(VNCBIND_BINDS)) {
        return [];
    }
    $raw = @file_get_contents(VNCBIND_BINDS);
    $j = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($j)) {
        return [];
    }
    $out = [];
    foreach ($j as $domain => $addrs) {
        if (!is_string($domain) || !vncbind_valid_domain($domain) || !is_array($addrs)) {
            continue;
        }
        $keep = [];
        foreach ($addrs as $addr) {
            $addr = trim((string)$addr);
            if (vncbind_valid_addr($addr)) {
                $keep[$addr] = true;
            }
        }
        $out[$domain] = array_keys($keep);
    }
    ksort($out, SORT_NATURAL | SORT_FLAG_CASE);
    return $out;
}

function vncbind_save_binds(array $binds): bool {
    vncbind_ensure_cfg();
    $json = json_encode($binds, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (!is_string($json)) {
        return false;
    }
    $tmp = VNCBIND_BINDS . '.tmp';
    if (@file_put_contents($tmp, $json . "\n") === false) {
        return false;
    }
    @chmod($tmp, 0600);
    return @rename($tmp, VNCBIND_BINDS);
}

function vncbind_set_domain_binds(string $domain, array $addrs): bool {
    if (!vncbind_valid_domain($domain) || !vncbind_addrs_ok(vncbind_load_cfg(), $addrs)) {
        return false;
    }
    $binds = vncbind_load_binds();
    $binds[$domain] = array_values($addrs);
    ksort($binds, SORT_NATURAL | SORT_FLAG_CASE);
    return vncbind_save_binds($binds);
}

function vncbind_rewrite_xml(string $xml, string $addr): string {
    if (!vncbind_valid_addr($addr)) {
        return $xml;
    }
    $safe = htmlspecialchars($addr, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $out = preg_replace_callback(
        '/<graphics\b([^>]*\btype=[\'"]vnc[\'"][^>]*)>(.*?)<\/graphics>/si',
        static function (array $m) use ($safe): string {
            $open = preg_replace("/\\blisten=['\"][^'\"]*['\"]/", "listen='" . $safe . "'", $m[1], 1, $count);
            if ($count === 0) {
                $open .= " listen='" . $safe . "'";
            }
            $inner = preg_replace(
                '/<listen\b[^>]*\btype=[\'"]address[\'"][^>]*\/?\s*>/i',
                "<listen type='address' address='" . $safe . "'/>",
                $m[2],
                1,
                $n
            );
            if ($n === 0) {
                $inner = "<listen type='address' address='" . $safe . "'/>" . $inner;
            }
            return '<graphics' . $open . '>' . $inner . '</graphics>';
        },
        $xml
    );
    return is_string($out) ? $out : $xml;
}

function vncbind_graphics_blocks(string $xml): array {
    if (!preg_match_all('/<graphics\b[^>]*\btype=[\'"]vnc[\'"][^>]*>.*?<\/graphics>/si', $xml, $blocks)) {
        return [];
    }
    return $blocks[0];
}

function vncbind_graphics_effective(string $block): string {
    $listen = '';
    if (preg_match("/\\blisten=['\"]([^'\"]*)['\"]/", $block, $m)) {
        $listen = $m[1];
    }
    if (preg_match("/<listen\b[^>]*\\baddress=['\"]([^'\"]*)['\"]/i", $block, $m)) {
        return $m[1];
    }
    return $listen;
}

function vncbind_graphics_has_passwd(string $block): bool {
    if (!preg_match('/<graphics\b([^>]*)>/i', $block, $open)) {
        return false;
    }
    if (!preg_match("/\\bpasswd\\s*=\\s*(['\"])(.*?)\\1/s", $open[1], $m)) {
        return false;
    }
    return trim(html_entity_decode($m[2], ENT_QUOTES | ENT_XML1, 'UTF-8')) !== '';
}

function vncbind_xml_vnc_severity(string $xml): string {
    $worst = 'ok';
    foreach (vncbind_graphics_blocks($xml) as $block) {
        if (!vncbind_is_wildcard(vncbind_graphics_effective($block))) {
            continue;
        }
        if (!vncbind_graphics_has_passwd($block)) {
            return 'critical';
        }
        $worst = 'warning';
    }
    return $worst;
}

function vncbind_xml_open_vnc(string $xml): bool {
    foreach (vncbind_graphics_blocks($xml) as $block) {
        if (vncbind_is_wildcard(vncbind_graphics_effective($block))) {
            return true;
        }
    }
    return false;
}

function vncbind_xml_vnc_listen(string $xml): array {
    $found = [];
    $blocks = vncbind_graphics_blocks($xml);
    if (!$blocks) {
        return $found;
    }
    foreach ($blocks as $block) {
        if (preg_match("/<listen\b[^>]*\\baddress=['\"]([^'\"]*)['\"]/i", $block, $m)) {
            $found[] = $m[1];
        } elseif (preg_match("/\\blisten=['\"]([^'\"]*)['\"]/", $block, $m)) {
            $found[] = $m[1];
        }
    }
    return $found;
}

function vncbind_dumpxml(string $name, bool $inactive): string {
    if (!vncbind_valid_domain($name)) {
        return '';
    }
    $cmd = 'virsh dumpxml ' . ($inactive ? '--inactive ' : '') . escapeshellarg($name) . ' 2>/dev/null';
    $out = [];
    $rc = 0;
    @exec($cmd, $out, $rc);
    return $rc === 0 ? implode("\n", $out) : '';
}

function vncbind_define(string $xml): bool {
    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = @proc_open(['virsh', 'define', '/dev/stdin'], $desc, $pipes);
    if (!is_resource($proc)) {
        return false;
    }
    fwrite($pipes[0], $xml);
    fclose($pipes[0]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $rc = proc_close($proc);
    return $rc === 0 && !str_contains((string)$err, 'error:');
}

function vncbind_redefine(string $name, array $addrs): bool {
    if (!vncbind_valid_domain($name) || !vncbind_addrs_ok(vncbind_load_cfg(), $addrs)) {
        return false;
    }
    if (!vncbind_set_domain_binds($name, $addrs)) {
        return false;
    }
    $xml = vncbind_dumpxml($name, true);
    if ($xml === '') {
        return false;
    }
    if (!vncbind_define(vncbind_rewrite_xml($xml, vncbind_console_addr()))) {
        return false;
    }
    vncbind_listen_spawn();
    return true;
}

function vncbind_graphics_port(string $block): int {
    if (!preg_match('/<graphics\b([^>]*)>/i', $block, $open)) {
        return 0;
    }
    if (!preg_match("/\\bport=['\"](\\d+)['\"]/", $open[1], $m)) {
        return 0;
    }
    $port = (int)$m[1];
    return ($port >= 1 && $port <= 65535) ? $port : 0;
}

function vncbind_host_has_addr(string $addr): bool {
    if (vncbind_same_addr($addr, '127.0.0.1') || vncbind_same_addr($addr, '::1')) {
        return true;
    }
    foreach (vncbind_host_addrs() as $row) {
        if (vncbind_same_addr($row['addr'], $addr)) {
            return true;
        }
    }
    return false;
}

function vncbind_listen_key(string $addr, int $port): string {
    $bin = @inet_pton($addr);
    $id = is_string($bin) ? bin2hex($bin) : $addr;
    return $id . ':' . $port;
}

function vncbind_listen_plan(): array {
    $plan = [];
    $seen = [];
    foreach (vncbind_load_binds() as $domain => $addrs) {
        if (!vncbind_domain_live($domain)) {
            continue;
        }
        $xml = vncbind_dumpxml($domain, false);
        if ($xml === '') {
            continue;
        }
        foreach (vncbind_graphics_blocks($xml) as $block) {
            $dest = vncbind_graphics_effective($block);
            $port = vncbind_graphics_port($block);
            if ($port === 0 || !vncbind_valid_addr($dest)) {
                continue;
            }
            foreach ($addrs as $addr) {
                if (!vncbind_valid_addr($addr) || vncbind_same_addr($addr, $dest) || !vncbind_host_has_addr($addr)) {
                    continue;
                }
                $key = vncbind_listen_key($addr, $port);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $plan[] = ['bind' => $addr, 'port' => $port, 'dest' => $dest];
            }
        }
    }
    return $plan;
}

function vncbind_listen_alive(): bool {
    $pid = (int)trim((string)@file_get_contents(VNCBIND_LISTEN_PID));
    if ($pid <= 1 || !function_exists('posix_kill') || !@posix_kill($pid, 0)) {
        return false;
    }
    $cmd = @file_get_contents('/proc/' . $pid . '/cmdline');
    return is_string($cmd) && str_contains($cmd, 'vncbind-listen.php');
}

function vncbind_listen_spawn(): void {
    if (!is_file(VNCBIND_LISTEN) || vncbind_listen_alive()) {
        return;
    }
    $php = is_executable('/usr/bin/php') ? '/usr/bin/php' : PHP_BINARY;
    $cmd = 'setsid ' . escapeshellarg($php) . ' ' . escapeshellarg(VNCBIND_LISTEN)
        . ' </dev/null >/dev/null 2>&1 &';
    @exec($cmd);
}

function vncbind_listen_stop(): void {
    if (!vncbind_listen_alive()) {
        @unlink(VNCBIND_LISTEN_PID);
        return;
    }
    $pid = (int)trim((string)@file_get_contents(VNCBIND_LISTEN_PID));
    if ($pid > 1 && function_exists('posix_kill')) {
        @posix_kill($pid, SIGTERM);
    }
    @unlink(VNCBIND_LISTEN_PID);
}

function vncbind_domains(): array {
    $out = [];
    $rc = 0;
    @exec('virsh list --all --name 2>/dev/null', $out, $rc);
    if ($rc !== 0) {
        return [];
    }
    $names = [];
    foreach ($out as $line) {
        $name = trim($line);
        if (vncbind_valid_domain($name)) {
            $names[$name] = true;
        }
    }
    $list = array_keys($names);
    sort($list, SORT_NATURAL | SORT_FLAG_CASE);
    return $list;
}

function vncbind_walk(): array {
    $rows = [];
    foreach (vncbind_domains() as $name) {
        foreach (['saved' => true, 'running' => false] as $kind => $inactive) {
            $xml = vncbind_dumpxml($name, $inactive);
            if ($xml === '' || !vncbind_xml_open_vnc($xml)) {
                continue;
            }
            $rows[] = [
                'domain' => $name,
                'where' => $kind,
                'listen' => vncbind_xml_vnc_listen($xml),
            ];
        }
    }
    vncbind_ensure_cfg();
    $json = json_encode(['checked' => time(), 'rows' => $rows], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (is_string($json)) {
        $tmp = VNCBIND_SUGGEST . '.tmp';
        if (@file_put_contents($tmp, $json . "\n") !== false) {
            @chmod($tmp, 0600);
            @rename($tmp, VNCBIND_SUGGEST);
        }
    }
    return $rows;
}

function vncbind_suggestions(): array {
    if (!is_file(VNCBIND_SUGGEST)) {
        return [];
    }
    $raw = @file_get_contents(VNCBIND_SUGGEST);
    $j = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($j) ? $j : [];
}

function vncbind_apply_schedule(?array $cfg = null): void {
    if ($cfg === null) {
        $cfg = vncbind_load_cfg();
    }
    if (!is_file(VNCBIND_WALK)) {
        @unlink(VNCBIND_CRON);
        return;
    }
    $lines = [];
    $schedule = (string)($cfg['schedule'] ?? 'off');
    if ($schedule === 'daily' || $schedule === 'weekly') {
        $when = $schedule === 'weekly' ? '15 3 * * 0' : '15 3 * * *';
        $lines[] = $when . ' root ' . VNCBIND_WALK;
    }
    if (($cfg['alerting'] ?? 'no') === 'yes') {
        $lines[] = '*/5 * * * * root ' . VNCBIND_WALK . ' alert';
    }
    if (!$lines) {
        @unlink(VNCBIND_CRON);
        return;
    }
    @file_put_contents(VNCBIND_CRON, implode("\n", $lines) . "\n");
    @chmod(VNCBIND_CRON, 0644);
}

function vncbind_ipv4_in(string $addr, string $net, int $bits): bool {
    $ip = @inet_pton($addr);
    $nw = @inet_pton($net);
    if (!is_string($ip) || !is_string($nw) || strlen($ip) !== 4 || strlen($nw) !== 4 || $bits < 0 || $bits > 32) {
        return false;
    }
    $ipn = unpack('N', $ip)[1];
    $nwn = unpack('N', $nw)[1];
    if ($bits === 0) {
        return true;
    }
    $host = 32 - $bits;
    $mask = $host === 0 ? 0xFFFFFFFF : ((0xFFFFFFFF ^ ((1 << $host) - 1)) & 0xFFFFFFFF);
    return ($ipn & $mask) === ($nwn & $mask);
}

function vncbind_addr_is_public(string $addr): bool {
    if (!vncbind_valid_addr($addr)) {
        return false;
    }
    if (filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        foreach ([
            ['0.0.0.0', 8],
            ['10.0.0.0', 8],
            ['127.0.0.0', 8],
            ['100.64.0.0', 10],
            ['169.254.0.0', 16],
            ['172.16.0.0', 12],
            ['192.168.0.0', 16],
            ['224.0.0.0', 3],
        ] as [$net, $bits]) {
            if (vncbind_ipv4_in($addr, $net, $bits)) {
                return false;
            }
        }
        return true;
    }
    $bin = @inet_pton($addr);
    if (!is_string($bin) || strlen($bin) !== 16) {
        return false;
    }
    $b = array_values(unpack('C16', $bin));
    $mapped = true;
    for ($i = 0; $i < 10; $i++) {
        if ($b[$i] !== 0) {
            $mapped = false;
            break;
        }
    }
    if ($mapped && $b[10] === 0xff && $b[11] === 0xff) {
        return vncbind_addr_is_public(sprintf('%d.%d.%d.%d', $b[12], $b[13], $b[14], $b[15]));
    }
    if ($bin === inet_pton('::') || $bin === inet_pton('::1')) {
        return false;
    }
    if ($b[0] === 0xfe && ($b[1] & 0xc0) === 0x80) {
        return false;
    }
    if (($b[0] & 0xfe) === 0xfc) {
        return false;
    }
    if ($b[0] === 0xff) {
        return false;
    }
    return true;
}

function vncbind_public_addrs(): array {
    $rows = [];
    foreach (vncbind_host_addrs() as $row) {
        if (preg_match('/^tailscale/i', $row['name'])) {
            continue;
        }
        if (vncbind_addr_is_public($row['addr'])) {
            $rows[] = $row;
        }
    }
    return $rows;
}

function vncbind_has_public_addr(): bool {
    return vncbind_public_addrs() !== [];
}

function vncbind_internet_addrs(): array {
    $by = [];
    foreach (vncbind_public_addrs() as $row) {
        $addr = $row['addr'];
        $shim = (bool)preg_match('/^shim-/i', $row['name']);
        if (!isset($by[$addr]) || (!$shim && preg_match('/^shim-/i', $by[$addr]['name']))) {
            $by[$addr] = $row;
        }
    }
    return array_values($by);
}

function vncbind_domain_live(string $name): bool {
    if (!vncbind_valid_domain($name)) {
        return false;
    }
    $out = [];
    $rc = 0;
    @exec('virsh domstate ' . escapeshellarg($name) . ' 2>/dev/null', $out, $rc);
    if ($rc !== 0) {
        return false;
    }
    $state = strtolower(trim(implode(' ', $out)));
    return $state === 'running' || $state === 'paused' || $state === 'pmsuspended';
}

function vncbind_alert_state(): array {
    if (!is_file(VNCBIND_ALERTS)) {
        return [];
    }
    $raw = @file_get_contents(VNCBIND_ALERTS);
    $j = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($j) ? $j : [];
}

function vncbind_alert_save(array $state): void {
    vncbind_ensure_cfg();
    ksort($state, SORT_NATURAL | SORT_FLAG_CASE);
    $json = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (!is_string($json)) {
        return;
    }
    $tmp = VNCBIND_ALERTS . '.tmp';
    if (@file_put_contents($tmp, $json . "\n") === false) {
        return;
    }
    @chmod($tmp, 0600);
    @rename($tmp, VNCBIND_ALERTS);
}

function vncbind_notify(string $subject, string $desc, string $importance): void {
    $notify = '/usr/local/emhttp/webGui/scripts/notify';
    if (!is_file($notify)) {
        return;
    }
    $cmd = $notify
        . ' -e ' . escapeshellarg('VNC Bind')
        . ' -s ' . escapeshellarg($subject)
        . ' -d ' . escapeshellarg($desc)
        . ' -i ' . escapeshellarg($importance)
        . ' -l ' . escapeshellarg('/Settings/Utilities/VncBind');
    @exec($cmd);
}

function vncbind_alert(): void {
    $cfg = vncbind_load_cfg();
    if ($cfg['alerting'] !== 'yes') {
        return;
    }
    $want = [];
    if (vncbind_has_public_addr()) {
        foreach (vncbind_domains() as $name) {
            $hits = [];
            $saved = vncbind_dumpxml($name, true);
            $savedSev = $saved === '' ? 'ok' : vncbind_xml_vnc_severity($saved);
            if ($savedSev === 'critical' || $savedSev === 'warning') {
                $hits['saved'] = $savedSev;
            }
            if (vncbind_domain_live($name)) {
                $live = vncbind_dumpxml($name, false);
                $liveSev = $live === '' ? 'ok' : vncbind_xml_vnc_severity($live);
                if ($liveSev === 'critical' || $liveSev === 'warning') {
                    $hits['running'] = $liveSev;
                }
            }
            if (!$hits) {
                continue;
            }
            $sev = in_array('critical', $hits, true) ? 'critical' : 'warning';
            if (isset($hits['saved'], $hits['running'])) {
                $where = 'Saved and running';
            } elseif (isset($hits['running'])) {
                $where = 'Running';
            } else {
                $where = 'Saved';
            }
            $want[$name] = $sev . '|' . $where;
        }
    }
    $prev = vncbind_alert_state();
    foreach ($want as $name => $token) {
        if (($prev[$name] ?? '') === $token) {
            continue;
        }
        [$sev] = explode('|', $token, 2);
        $hits = [];
        $savedXml = vncbind_dumpxml($name, true);
        $savedSev = $savedXml === '' ? 'ok' : vncbind_xml_vnc_severity($savedXml);
        if ($savedSev === 'critical' || $savedSev === 'warning') {
            $hits[] = 'Saved ' . ($savedSev === 'critical' ? 'has no VNC password' : 'has a VNC password');
        }
        if (vncbind_domain_live($name)) {
            $liveXml = vncbind_dumpxml($name, false);
            $liveSev = $liveXml === '' ? 'ok' : vncbind_xml_vnc_severity($liveXml);
            if ($liveSev === 'critical' || $liveSev === 'warning') {
                $hits[] = 'Running ' . ($liveSev === 'critical' ? 'has no VNC password' : 'has a VNC password');
            }
        }
        $subject = $name . ' VNC listens on every interface';
        $desc = ($hits ? implode('. ', $hits) . '. ' : '') . 'The listen address is every interface, and this host has an internet address.';
        vncbind_notify($subject, $desc, $sev === 'critical' ? 'alert' : 'warning');
    }
    vncbind_alert_save($want);
}
