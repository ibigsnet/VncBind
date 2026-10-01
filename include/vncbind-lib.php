<?php
const VNCBIND_CFG = '/boot/config/plugins/VncBind';
const VNCBIND_FILE = '/boot/config/plugins/VncBind/vncbind.json';
const VNCBIND_SUGGEST = '/boot/config/plugins/VncBind/suggestions.json';
const VNCBIND_ALERTS = '/boot/config/plugins/VncBind/alerts.json';
const VNCBIND_EXTRA = '/boot/config/network-extra.cfg';
const VNCBIND_CRON = '/etc/cron.d/vncbind';
const VNCBIND_WALK = '/usr/local/emhttp/plugins/VncBind/scripts/vncbind-walk';

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
        'ignore_interface_extra' => 'no',
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
    $cfg['ignore_interface_extra'] = $yn($j['ignore_interface_extra'] ?? '', 'no');
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
    if ($cfg['ignore_interface_extra'] === 'yes') {
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
        if (isset($base[$name])) {
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

function vncbind_redefine(string $name, string $addr): bool {
    if (!vncbind_valid_domain($name) || !vncbind_choice_ok(vncbind_load_cfg(), $addr)) {
        return false;
    }
    $xml = vncbind_dumpxml($name, true);
    if ($xml === '') {
        return false;
    }
    return vncbind_define(vncbind_rewrite_xml($xml, $addr));
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

function vncbind_has_public_addr(): bool {
    foreach (vncbind_host_addrs() as $row) {
        if (preg_match('/^tailscale/i', $row['name'])) {
            continue;
        }
        if (vncbind_addr_is_public($row['addr'])) {
            return true;
        }
    }
    return false;
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
        $desc = ($hits ? implode('. ', $hits) . '. ' : '') . 'The listen address is every interface, and this host has a public address.';
        vncbind_notify($subject, $desc, $sev === 'critical' ? 'alert' : 'warning');
    }
    vncbind_alert_save($want);
}
