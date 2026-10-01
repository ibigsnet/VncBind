<?php
const VNCBIND_CFG = '/boot/config/plugins/VncBind';
const VNCBIND_FILE = '/boot/config/plugins/VncBind/vncbind.json';
const VNCBIND_SUGGEST = '/boot/config/plugins/VncBind/suggestions.json';
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

function vncbind_xml_open_vnc(string $xml): bool {
    if (!preg_match_all('/<graphics\b[^>]*\btype=[\'"]vnc[\'"][^>]*>.*?<\/graphics>/si', $xml, $blocks)) {
        return false;
    }
    foreach ($blocks[0] as $block) {
        $listen = '';
        if (preg_match("/\\blisten=['\"]([^'\"]*)['\"]/", $block, $m)) {
            $listen = $m[1];
        }
        $child = '';
        if (preg_match("/<listen\b[^>]*\\baddress=['\"]([^'\"]*)['\"]/i", $block, $m)) {
            $child = $m[1];
        }
        $effective = $child !== '' ? $child : $listen;
        if (vncbind_is_wildcard($effective)) {
            return true;
        }
    }
    return false;
}

function vncbind_xml_vnc_listen(string $xml): array {
    $found = [];
    if (!preg_match_all('/<graphics\b[^>]*\btype=[\'"]vnc[\'"][^>]*>.*?<\/graphics>/si', $xml, $blocks)) {
        return $found;
    }
    foreach ($blocks[0] as $block) {
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

function vncbind_apply_schedule(string $schedule): void {
    if ($schedule === 'off' || !is_file(VNCBIND_WALK)) {
        @unlink(VNCBIND_CRON);
        return;
    }
    $when = $schedule === 'weekly' ? '15 3 * * 0' : '15 3 * * *';
    $line = $when . ' root ' . VNCBIND_WALK . "\n";
    @file_put_contents(VNCBIND_CRON, $line);
    @chmod(VNCBIND_CRON, 0644);
}
