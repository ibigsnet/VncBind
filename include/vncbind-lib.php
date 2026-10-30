<?php
if (!defined('VNCBIND_ROOT')) {
    $vncbind_root = getenv('VNCBIND_ROOT');
    // Optional path prefix for CLI tests. Empty on a real server.
    define('VNCBIND_ROOT', is_string($vncbind_root) ? rtrim($vncbind_root, '/') : '');
    define('VNCBIND_CFG', VNCBIND_ROOT . '/boot/config/plugins/VncBind');
    define('VNCBIND_FILE', VNCBIND_CFG . '/vncbind.json');
    define('VNCBIND_SUGGEST', VNCBIND_CFG . '/suggestions.json');
    define('VNCBIND_ALERTS', VNCBIND_CFG . '/alerts.json');
    define('VNCBIND_BINDS', VNCBIND_CFG . '/binds.json');
    // update_cron reads /boot/config/plugins/<plg name>/*.cron: lowercase vncbind/
    // (case matters on a ZFS boot device; same folder on FAT).
    define('VNCBIND_CRONFILE', VNCBIND_ROOT . '/boot/config/plugins/vncbind/vncbind.cron');
    define('VNCBIND_CRONFILE_LEGACY', VNCBIND_CFG . '/vncbind.cron');
    define('VNCBIND_EXTRA', VNCBIND_ROOT . '/boot/config/network-extra.cfg');
    define('VNCBIND_OLD_CRON', VNCBIND_ROOT . '/etc/cron.d/vncbind');
    define('VNCBIND_CRON_ROOT', VNCBIND_ROOT . '/etc/cron.d/root');
    define('VNCBIND_UPDATE_CRON', VNCBIND_ROOT . '/usr/local/sbin/update_cron');
    define('VNCBIND_WALK', '/usr/local/emhttp/plugins/VncBind/scripts/vncbind-walk');
    define('VNCBIND_LISTEN', __DIR__ . '/vncbind-listen.php');
    define('VNCBIND_LISTEN_PID', VNCBIND_ROOT . '/var/run/vncbind-listen.pid');
    define('VNCBIND_POKE', VNCBIND_ROOT . '/var/run/vncbind-listen.poke');
    define('VNCBIND_PENDING_TTL', 86400);
}

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
    return '';
}

function vncbind_csrf_ok(): bool {
    $expected = vncbind_csrf_expected();
    $got = vncbind_csrf_submitted();
    return $expected !== '' && strlen($expected) === strlen($got) && hash_equals($expected, $got);
}

function vncbind_log(string $msg): void {
    if (VNCBIND_ROOT !== '') {
        @file_put_contents(VNCBIND_ROOT . '/vncbind.log', $msg . "\n", FILE_APPEND);
        return;
    }
    openlog('vncbind', LOG_PID, LOG_DAEMON);
    syslog(LOG_NOTICE, $msg);
}

/**
 * Write a file only when its content changes (temp file + rename).
 * Returns null on failure, false when unchanged, true when written.
 */
function vncbind_write_if_changed(string $path, string $data): ?bool {
    if (is_file($path) && @file_get_contents($path) === $data) {
        return false;
    }
    if (!is_dir(dirname($path))) {
        @mkdir(dirname($path), 0755, true);
    }
    $tmp = $path . '.tmp';
    if (@file_put_contents($tmp, $data) === false) {
        @unlink($tmp);
        return null;
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return null;
    }
    return true;
}

function vncbind_defaults(): array {
    return [
        'interjection' => 'yes',
        'enforce' => 'yes',
        'use_interface_extra' => 'yes',
        'schedule' => 'off',
        'alerting' => 'yes',
        'interfaces' => [],
    ];
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
    $cfg['enforce'] = $yn($j['enforce'] ?? '', 'yes');
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
    $json = json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    return is_string($json) && vncbind_write_if_changed(VNCBIND_FILE, $json . "\n") !== null;
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

function vncbind_valid_uuid(string $uuid): bool {
    return (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid);
}

function vncbind_valid_addr(string $addr): bool {
    if ($addr === '' || vncbind_is_wildcard($addr)) {
        return false;
    }
    return filter_var($addr, FILTER_VALIDATE_IP) !== false;
}

function vncbind_is_wildcard(string $addr): bool {
    $addr = strtolower(trim($addr));
    if ($addr === '' || $addr === '0.0.0.0' || $addr === '::' || $addr === '::0') {
        return true;
    }
    $bin = @inet_pton($addr);
    return is_string($bin) && trim($bin, "\0") === '';
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

/** Global-scope interface addresses (name, addr). */
function vncbind_host_addrs(): array {
    static $cache = null;
    static $at = 0;
    if ($cache !== null && time() - $at < 2) {
        return $cache;
    }
    $rows = [];
    foreach ([4, 6] as $family) {
        $rc = vncbind_cmd(['ip', '-' . $family, '-o', 'addr', 'show', 'scope', 'global'], $text);
        if ($rc !== 0) {
            continue;
        }
        foreach (explode("\n", $text) as $line) {
            if (!preg_match('/^\d+:\s+(\S+)\s+inet6?\s+(\S+)/', $line, $m)) {
                continue;
            }
            $name = rtrim($m[1], ':');
            $name = explode('@', $name)[0];
            $addr = explode('/', $m[2])[0];
            if (!vncbind_valid_iface($name) || $name === 'lo' || !vncbind_valid_addr($addr)) {
                continue;
            }
            $rows[] = ['name' => $name, 'addr' => $addr];
        }
    }
    $cache = $rows;
    $at = time();
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

/** Addresses the question may offer, from the current settings and host addresses. */
function vncbind_choices(array $cfg, ?array $hostAddrs = null): array {
    $allow = array_fill_keys(vncbind_allowed_names($cfg), true);
    $choices = [];
    foreach ($hostAddrs ?? vncbind_host_addrs() as $row) {
        if (isset($allow[$row['name']])) {
            $choices[] = $row;
        }
    }
    return $choices;
}

function vncbind_same_addr(string $a, string $b): bool {
    $pa = @inet_pton($a);
    $pb = @inet_pton($b);
    return is_string($pa) && is_string($pb) && $pa === $pb;
}

function vncbind_choice_ok(array $cfg, string $addr, ?array $choices = null): bool {
    if (!vncbind_valid_addr($addr)) {
        return false;
    }
    foreach ($choices ?? vncbind_choices($cfg) as $row) {
        if (vncbind_same_addr($row['addr'], $addr)) {
            return true;
        }
    }
    return false;
}

function vncbind_addrs_ok(array $cfg, array $addrs): bool {
    $choices = vncbind_choices($cfg);
    foreach ($addrs as $addr) {
        if (!is_string($addr) || !vncbind_choice_ok($cfg, $addr, $choices)) {
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

function vncbind_tcp_uri(string $addr, int $port): string {
    if (filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        return 'tcp://[' . $addr . ']:' . $port;
    }
    return 'tcp://' . $addr . ':' . $port;
}

/**
 * Run a command without a shell. Returns the exit code; stdout in $out.
 */
function vncbind_cmd(array $argv, ?string &$out = null, ?string $stdin = null): int {
    $out = '';
    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = @proc_open($argv, $desc, $pipes);
    if (!is_resource($proc)) {
        return 127;
    }
    if ($stdin !== null) {
        fwrite($pipes[0], $stdin);
    }
    fclose($pipes[0]);
    $out = (string)stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return proc_close($proc);
}

function vncbind_virsh_bin(): string {
    $b = getenv('VNCBIND_VIRSH');
    return is_string($b) && $b !== '' ? $b : 'virsh';
}

function vncbind_virsh(array $args, ?string &$out = null, ?string $stdin = null): int {
    return vncbind_cmd(array_merge([vncbind_virsh_bin(), '-q'], $args), $out, $stdin);
}

/** Domain XML including the VNC password (needed so a redefine keeps it). */
function vncbind_dumpxml(string $id, bool $inactive): string {
    if (!vncbind_valid_domain($id)) {
        return '';
    }
    $args = ['dumpxml', '--security-info'];
    if ($inactive) {
        $args[] = '--inactive';
    }
    $args[] = '--domain';
    $args[] = $id;
    return vncbind_virsh($args, $out) === 0 ? (string)$out : '';
}

function vncbind_define(string $xml): bool {
    return vncbind_virsh(['define', '/dev/stdin'], $out, $xml) === 0;
}

/**
 * All domains as uuid => name, or null when libvirt does not answer.
 */
function vncbind_domain_list(): ?array {
    if (vncbind_virsh(['list', '--all', '--uuid'], $out) !== 0) {
        return null;
    }
    $list = [];
    foreach (preg_split('/\s+/', trim((string)$out)) ?: [] as $uuid) {
        if (!vncbind_valid_uuid($uuid)) {
            continue;
        }
        if (vncbind_virsh(['domname', '--domain', $uuid], $name) !== 0) {
            continue;
        }
        $name = trim((string)$name);
        if (vncbind_valid_domain($name)) {
            $list[$uuid] = $name;
        }
    }
    uasort($list, static fn($a, $b) => strnatcasecmp($a, $b));
    return $list;
}

function vncbind_domuuid(string $name): string {
    if (!vncbind_valid_domain($name) || vncbind_virsh(['domuuid', '--domain', $name], $out) !== 0) {
        return '';
    }
    $u = trim((string)$out);
    return vncbind_valid_uuid($u) ? $u : '';
}

function vncbind_domain_live(string $id): bool {
    if (!vncbind_valid_domain($id) || vncbind_virsh(['domstate', '--domain', $id], $out) !== 0) {
        return false;
    }
    $state = strtolower(trim((string)$out));
    return $state === 'running' || $state === 'paused' || $state === 'pmsuspended';
}

/* ---------- Domain XML (DOM) ---------- */

function vncbind_dom(string $xml): ?DOMDocument {
    if (trim($xml) === '') {
        return null;
    }
    if (!class_exists('DOMDocument')) {
        static $warned = false;
        if (!$warned) {
            $warned = true;
            vncbind_log('PHP DOM extension missing; VM definitions are not read or changed');
        }
        return null;
    }
    $prev = libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $ok = $doc->loadXML($xml, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$ok || !$doc->documentElement || $doc->documentElement->nodeName !== 'domain') {
        return null;
    }
    return $doc;
}

/** @return DOMElement[] */
function vncbind_vnc_nodes(DOMDocument $doc): array {
    $out = [];
    foreach ((new DOMXPath($doc))->query('/domain/devices/graphics[@type="vnc"]') as $g) {
        $out[] = $g;
    }
    return $out;
}

/**
 * Effective listen of one VNC graphics element.
 * kind: address | network | socket ; listen: address ('' = qemu default)
 */
function vncbind_vnc_listen(DOMElement $g): array {
    foreach ($g->childNodes as $c) {
        if (!($c instanceof DOMElement) || $c->nodeName !== 'listen') {
            continue;
        }
        $type = $c->getAttribute('type');
        if ($type === 'address') {
            $addr = $c->getAttribute('address');
            return ['kind' => 'address', 'listen' => $addr !== '' ? $addr : $g->getAttribute('listen')];
        }
        if ($type === 'network') {
            return ['kind' => 'network', 'listen' => $c->getAttribute('address')];
        }
        return ['kind' => 'socket', 'listen' => ''];
    }
    return ['kind' => 'address', 'listen' => $g->getAttribute('listen')];
}

/**
 * Per VNC device: kind, listen, port, passwd. Null when the XML does not parse.
 */
function vncbind_vnc_info(string $xml): ?array {
    $doc = vncbind_dom($xml);
    if (!$doc) {
        return null;
    }
    $out = [];
    foreach (vncbind_vnc_nodes($doc) as $g) {
        $l = vncbind_vnc_listen($g);
        $port = (int)$g->getAttribute('port');
        $out[] = [
            'kind' => $l['kind'],
            'listen' => $l['listen'],
            'port' => ($port >= 1 && $port <= 65535) ? $port : 0,
            'passwd' => trim($g->getAttribute('passwd')) !== '',
        ];
    }
    return $out;
}

function vncbind_info_open(array $row): bool {
    return $row['kind'] === 'address' && vncbind_is_wildcard($row['listen']);
}

function vncbind_xml_uuid(string $xml): string {
    $doc = vncbind_dom($xml);
    $u = $doc ? strtolower(trim((string)(new DOMXPath($doc))->evaluate('string(/domain/uuid)'))) : '';
    return vncbind_valid_uuid($u) ? $u : '';
}

function vncbind_xml_name(string $xml): string {
    $doc = vncbind_dom($xml);
    $n = $doc ? trim((string)(new DOMXPath($doc))->evaluate('string(/domain/name)')) : '';
    return vncbind_valid_domain($n) ? $n : '';
}

/**
 * Set every address-type VNC listen to $addr. Network and socket listens are
 * left alone. Other devices (SPICE, passwords, ports) are untouched.
 * Returns null when the XML does not parse.
 */
function vncbind_rewrite_xml(string $xml, string $addr): ?string {
    if (!vncbind_valid_addr($addr)) {
        return null;
    }
    $doc = vncbind_dom($xml);
    if (!$doc) {
        return null;
    }
    foreach (vncbind_vnc_nodes($doc) as $g) {
        if (vncbind_vnc_listen($g)['kind'] !== 'address') {
            continue;
        }
        $g->setAttribute('listen', $addr);
        $first = null;
        foreach (iterator_to_array($g->childNodes) as $c) {
            if ($c instanceof DOMElement && $c->nodeName === 'listen') {
                if ($first === null) {
                    $first = $c;
                } else {
                    $g->removeChild($c);
                }
            }
        }
        if ($first === null) {
            $first = $doc->createElement('listen');
            $g->insertBefore($first, $g->firstChild);
        }
        foreach (iterator_to_array($first->attributes) as $a) {
            $first->removeAttribute($a->nodeName);
        }
        $first->setAttribute('type', 'address');
        $first->setAttribute('address', $addr);
    }
    $out = $doc->saveXML($doc->documentElement);
    return is_string($out) ? $out . "\n" : null;
}

function vncbind_xml_open_vnc(string $xml): bool {
    foreach (vncbind_vnc_info($xml) ?? [] as $row) {
        if (vncbind_info_open($row)) {
            return true;
        }
    }
    return false;
}

function vncbind_xml_vnc_severity(string $xml): string {
    $worst = 'ok';
    foreach (vncbind_vnc_info($xml) ?? [] as $row) {
        if (!vncbind_info_open($row)) {
            continue;
        }
        if (!$row['passwd']) {
            return 'critical';
        }
        $worst = 'warning';
    }
    return $worst;
}

function vncbind_xml_vnc_listen(string $xml): array {
    $out = [];
    foreach (vncbind_vnc_info($xml) ?? [] as $row) {
        $out[] = $row['kind'] === 'address' ? ($row['listen'] !== '' ? $row['listen'] : '(default)') : $row['kind'];
    }
    return $out;
}

/* ---------- Binds (which host addresses forward to a VM console) ---------- */

/**
 * binds.json: { "<uuid>": {"name": "...", "addrs": [...]},
 *               "name:<name>": {"name": "...", "addrs": [...], "ts": 0} }  (pending until the VM exists)
 */
function vncbind_load_binds(): array {
    if (!is_file(VNCBIND_BINDS)) {
        return [];
    }
    $j = json_decode((string)@file_get_contents(VNCBIND_BINDS), true);
    if (!is_array($j)) {
        return [];
    }
    $out = [];
    foreach ($j as $key => $row) {
        $key = (string)$key;
        if (is_array($row) && array_is_list($row)) {
            // Format before 2026.10.30aa: name => [addrs]
            $row = ['name' => $key, 'addrs' => $row, 'ts' => time()];
            $key = 'name:' . $key;
        }
        if (!is_array($row)) {
            continue;
        }
        $name = (string)($row['name'] ?? '');
        if (!vncbind_valid_domain($name)) {
            continue;
        }
        if (!vncbind_valid_uuid($key) && $key !== 'name:' . $name) {
            continue;
        }
        $addrs = [];
        foreach ((array)($row['addrs'] ?? []) as $a) {
            $a = trim((string)$a);
            if (vncbind_valid_addr($a)) {
                $addrs[$a] = true;
            }
        }
        $entry = ['name' => $name, 'addrs' => array_keys($addrs)];
        if (!vncbind_valid_uuid($key)) {
            $entry['ts'] = (int)($row['ts'] ?? time());
        }
        $out[$key] = $entry;
    }
    ksort($out);
    return $out;
}

function vncbind_save_binds(array $binds): bool {
    ksort($binds);
    $json = json_encode($binds, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    return is_string($json) && vncbind_write_if_changed(VNCBIND_BINDS, $json . "\n") !== null;
}

/** Store the addresses for a VM; uuid '' = pending by name until the VM exists. */
function vncbind_set_binds(string $uuid, string $name, array $addrs): bool {
    if (!vncbind_valid_domain($name) || ($uuid !== '' && !vncbind_valid_uuid($uuid))) {
        return false;
    }
    $binds = vncbind_load_binds();
    unset($binds['name:' . $name]);
    foreach ($binds as $k => $row) {
        if ($uuid === '' && $row['name'] === $name) {
            unset($binds[$k]);
        }
    }
    if ($uuid !== '') {
        $binds[$uuid] = ['name' => $name, 'addrs' => array_values($addrs)];
    } else {
        $binds['name:' . $name] = ['name' => $name, 'addrs' => array_values($addrs), 'ts' => time()];
    }
    return vncbind_save_binds($binds);
}

/** name => addrs, for the question's pre-checked boxes. */
function vncbind_binds_by_name(): array {
    $out = [];
    foreach (vncbind_load_binds() as $row) {
        $out[$row['name']] = $row['addrs'];
    }
    return $out;
}

/**
 * Resolve pending entries to UUIDs, follow renames, drop entries for removed VMs.
 * $domains: uuid => name (from vncbind_domain_list()). Pure apart from time().
 */
function vncbind_binds_reconcile(array $binds, array $domains): array {
    $byName = array_flip($domains);
    $out = [];
    foreach ($binds as $key => $row) {
        if (vncbind_valid_uuid($key)) {
            if (isset($domains[$key])) {
                $out[$key] = ['name' => $domains[$key], 'addrs' => $row['addrs']];
            }
            continue;
        }
        $uuid = $byName[$row['name']] ?? '';
        if ($uuid !== '') {
            $out[$uuid] = ['name' => $row['name'], 'addrs' => $row['addrs']];
        } elseif (time() - (int)($row['ts'] ?? 0) < VNCBIND_PENDING_TTL) {
            $out[$key] = $row;
        }
    }
    ksort($out);
    return $out;
}

function vncbind_binds_sync(?array $domains = null): array {
    $domains = $domains ?? vncbind_domain_list();
    $binds = vncbind_load_binds();
    if ($domains === null) {
        return $binds;
    }
    $next = vncbind_binds_reconcile($binds, $domains);
    if ($next !== $binds) {
        vncbind_save_binds($next);
    }
    return $next;
}

/**
 * Point the saved definition at the local console address (password kept) and
 * remember the addresses the proxy opens. $id is a UUID or a name.
 */
function vncbind_redefine(string $id, array $addrs): bool {
    if (!vncbind_valid_domain($id) || !vncbind_addrs_ok(vncbind_load_cfg(), $addrs)) {
        return false;
    }
    $uuid = vncbind_valid_uuid($id) ? $id : vncbind_domuuid($id);
    if ($uuid === '') {
        return false;
    }
    $xml = vncbind_dumpxml($uuid, true);
    $name = vncbind_xml_name($xml);
    if ($xml === '' || $name === '' || !vncbind_set_binds($uuid, $name, $addrs)) {
        return false;
    }
    if (!vncbind_fix_definition($uuid, $xml)) {
        return false;
    }
    vncbind_listen_poke();
    return true;
}

/**
 * If any address-type VNC listen in the saved XML is not the console address,
 * redefine with the console address. Returns false only when a define failed.
 */
function vncbind_fix_definition(string $uuid, ?string $xml = null): bool {
    $xml = $xml ?? vncbind_dumpxml($uuid, true);
    $info = vncbind_vnc_info($xml);
    if ($info === null) {
        return false;
    }
    $need = false;
    foreach ($info as $row) {
        if ($row['kind'] === 'address' && !vncbind_same_addr($row['listen'], vncbind_console_addr())) {
            $need = true;
        }
    }
    if (!$need) {
        return true;
    }
    $new = vncbind_rewrite_xml($xml, vncbind_console_addr());
    return $new !== null && vncbind_define($new);
}

/**
 * Server-side correction: a saved VNC listen on every interface becomes the
 * local console address. Returns true when the definition was changed.
 */
function vncbind_enforce_domain(string $id, ?array $cfg = null): bool {
    $cfg = $cfg ?? vncbind_load_cfg();
    if ($cfg['enforce'] !== 'yes' || !vncbind_valid_domain($id)) {
        return false;
    }
    $xml = vncbind_dumpxml($id, true);
    if ($xml === '' || !vncbind_xml_open_vnc($xml)) {
        return false;
    }
    $new = vncbind_rewrite_xml($xml, vncbind_console_addr());
    if ($new === null || !vncbind_define($new)) {
        vncbind_log('could not correct the VNC listen address of ' . vncbind_xml_name($xml));
        return false;
    }
    vncbind_log('VNC listen of ' . vncbind_xml_name($xml) . ' set to ' . vncbind_console_addr() . ' (was every interface)');
    return true;
}

function vncbind_enforce_all(?array $domains = null): int {
    $cfg = vncbind_load_cfg();
    if ($cfg['enforce'] !== 'yes') {
        return 0;
    }
    $n = 0;
    foreach (array_keys($domains ?? vncbind_domain_list() ?? []) as $uuid) {
        if (vncbind_enforce_domain($uuid, $cfg)) {
            $n++;
        }
    }
    return $n;
}

/* ---------- Listener plan ---------- */

/**
 * Pure: which host addresses forward to which console.
 * $binds: uuid => {name, addrs}; $allowed: list of currently allowed addresses
 * (settings + Interface Extra + present on the host); $live: uuid => running XML.
 */
function vncbind_plan_from(array $binds, array $allowed, array $live): array {
    $plan = [];
    $seen = [];
    foreach ($binds as $uuid => $row) {
        if (!vncbind_valid_uuid((string)$uuid) || !isset($live[$uuid])) {
            continue;
        }
        foreach (vncbind_vnc_info($live[$uuid]) ?? [] as $vnc) {
            $dest = $vnc['listen'];
            if ($vnc['kind'] !== 'address' || $vnc['port'] === 0 || !vncbind_valid_addr($dest)) {
                continue;
            }
            foreach ($row['addrs'] as $addr) {
                $ok = false;
                foreach ($allowed as $a) {
                    if (vncbind_same_addr($a, $addr)) {
                        $ok = true;
                    }
                }
                if (!$ok || vncbind_same_addr($addr, $dest)) {
                    continue;
                }
                $key = vncbind_listen_key($addr, $vnc['port']);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $plan[] = ['uuid' => $uuid, 'bind' => $addr, 'port' => $vnc['port'], 'dest' => $dest];
            }
        }
    }
    return $plan;
}

function vncbind_listen_key(string $addr, int $port): string {
    $bin = @inet_pton($addr);
    $id = is_string($bin) ? bin2hex($bin) : $addr;
    return $id . ':' . $port;
}

function vncbind_listen_plan(?array $domains = null): array {
    $binds = vncbind_binds_sync($domains);
    $allowed = array_column(vncbind_choices(vncbind_load_cfg()), 'addr');
    $live = [];
    foreach ($binds as $uuid => $_) {
        if (vncbind_valid_uuid($uuid) && vncbind_domain_live($uuid)) {
            $xml = vncbind_dumpxml($uuid, false);
            if ($xml !== '') {
                $live[$uuid] = $xml;
            }
        }
    }
    return vncbind_plan_from($binds, $allowed, $live);
}

/* ---------- Listener daemon control ---------- */

function vncbind_listen_alive(): bool {
    $pid = (int)trim((string)@file_get_contents(VNCBIND_LISTEN_PID));
    if ($pid <= 1 || !is_dir('/proc/' . $pid)) {
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
    @exec('setsid ' . escapeshellarg($php) . ' ' . escapeshellarg(VNCBIND_LISTEN) . ' </dev/null >/dev/null 2>&1 &');
}

/** Ask the daemon to rebuild its plan now (or start it). */
function vncbind_listen_poke(): void {
    @touch(VNCBIND_POKE);
    vncbind_listen_spawn();
}

function vncbind_listen_stop(): void {
    $pid = (int)trim((string)@file_get_contents(VNCBIND_LISTEN_PID));
    if (vncbind_listen_alive() && $pid > 1) {
        if (function_exists('posix_kill')) {
            @posix_kill($pid, 15);
        } else {
            @exec('kill ' . (int)$pid);
        }
        for ($i = 0; $i < 30 && is_dir('/proc/' . $pid); $i++) {
            usleep(100000);
        }
    }
    @unlink(VNCBIND_LISTEN_PID);
    @unlink(VNCBIND_POKE);
}

/* ---------- Template check, cron ---------- */

function vncbind_walk(): ?array {
    $domains = vncbind_domain_list();
    if ($domains === null) {
        return null;
    }
    $rows = [];
    foreach ($domains as $uuid => $name) {
        foreach (['saved' => true, 'running' => false] as $kind => $inactive) {
            if (!$inactive && !vncbind_domain_live($uuid)) {
                continue;
            }
            $xml = vncbind_dumpxml($uuid, $inactive);
            if ($xml === '' || !vncbind_xml_open_vnc($xml)) {
                continue;
            }
            $rows[] = ['domain' => $name, 'where' => $kind, 'listen' => vncbind_xml_vnc_listen($xml)];
        }
    }
    $json = json_encode(['checked' => time(), 'rows' => $rows], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (is_string($json)) {
        vncbind_write_if_changed(VNCBIND_SUGGEST, $json . "\n");
    }
    return $rows;
}

function vncbind_suggestions(): array {
    if (!is_file(VNCBIND_SUGGEST)) {
        return [];
    }
    $j = json_decode((string)@file_get_contents(VNCBIND_SUGGEST), true);
    return is_array($j) ? $j : [];
}

/** Unraid cron fragment (no user column; merged into /etc/cron.d/root by update_cron). */
function vncbind_cron_text(array $cfg): string {
    $lines = [];
    $schedule = (string)($cfg['schedule'] ?? 'off');
    if ($schedule === 'daily' || $schedule === 'weekly') {
        $when = $schedule === 'weekly' ? '15 3 * * 0' : '15 3 * * *';
        $lines[] = $when . ' ' . VNCBIND_WALK . ' >/dev/null 2>&1';
    }
    if (($cfg['alerting'] ?? 'no') === 'yes') {
        $lines[] = '*/5 * * * * ' . VNCBIND_WALK . ' alert >/dev/null 2>&1';
    }
    return $lines ? "# Generated VNC Bind schedule:\n" . implode("\n", $lines) . "\n" : '';
}

function vncbind_apply_schedule(?array $cfg = null): void {
    $cfg = $cfg ?? vncbind_load_cfg();
    // Versions before 2026.10.30aa wrote a file dcron could not run.
    @unlink(VNCBIND_OLD_CRON);
    $text = vncbind_cron_text($cfg);
    $changed = false;
    if ($text === '') {
        if (is_file(VNCBIND_CRONFILE)) {
            @unlink(VNCBIND_CRONFILE);
            $changed = true;
        }
    } else {
        @mkdir(dirname(VNCBIND_CRONFILE), 0755, true);
        $changed = vncbind_write_if_changed(VNCBIND_CRONFILE, $text) === true;
    }
    $legacy = VNCBIND_CRONFILE_LEGACY;
    if (is_file($legacy) && !(is_file(VNCBIND_CRONFILE) && @fileinode($legacy) === @fileinode(VNCBIND_CRONFILE))) {
        @unlink($legacy);
        $changed = true;
    }
    $merged = (string)@file_get_contents(VNCBIND_CRON_ROOT);
    $present = str_contains($merged, VNCBIND_WALK);
    if ($changed || $present !== ($text !== '')) {
        vncbind_update_cron();
    }
}

function vncbind_update_cron(): void {
    if (is_executable(VNCBIND_UPDATE_CRON)) {
        vncbind_cmd([VNCBIND_UPDATE_CRON]);
    }
}

/* ---------- Internet addresses and alerts ---------- */

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
            ['0.0.0.0', 8], ['10.0.0.0', 8], ['127.0.0.0', 8], ['100.64.0.0', 10],
            ['169.254.0.0', 16], ['172.16.0.0', 12], ['192.168.0.0', 16], ['224.0.0.0', 3],
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
    if (substr($bin, 0, 10) === str_repeat("\0", 10) && $b[10] === 0xff && $b[11] === 0xff) {
        return vncbind_addr_is_public(sprintf('%d.%d.%d.%d', $b[12], $b[13], $b[14], $b[15]));
    }
    if ($bin === inet_pton('::1') || ($b[0] === 0xfe && ($b[1] & 0xc0) === 0x80) || ($b[0] & 0xfe) === 0xfc || $b[0] === 0xff) {
        return false;
    }
    return true;
}

function vncbind_public_addrs(): array {
    $rows = [];
    foreach (vncbind_host_addrs() as $row) {
        if (!preg_match('/^tailscale/i', $row['name']) && vncbind_addr_is_public($row['addr'])) {
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

function vncbind_alert_state(): array {
    $j = is_file(VNCBIND_ALERTS) ? json_decode((string)@file_get_contents(VNCBIND_ALERTS), true) : null;
    return is_array($j) ? $j : [];
}

function vncbind_alert_save(array $state): void {
    ksort($state);
    $json = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (is_string($json)) {
        vncbind_write_if_changed(VNCBIND_ALERTS, $json . "\n");
    }
}

function vncbind_notify(string $subject, string $desc, string $importance): void {
    $notify = '/usr/local/emhttp/webGui/scripts/notify';
    if (VNCBIND_ROOT !== '' || !is_file($notify)) {
        vncbind_log('notify ' . $importance . ': ' . $subject . ' — ' . $desc);
        return;
    }
    vncbind_cmd([$notify, '-e', 'VNC Bind', '-s', $subject, '-d', $desc, '-i', $importance, '-l', '/Settings/Utilities/VncBind']);
}

/**
 * Notify when this host has an internet address and a VM's saved or running
 * VNC listen is every interface. Skips the run (state untouched) when libvirt
 * does not answer.
 */
function vncbind_alert(): void {
    $cfg = vncbind_load_cfg();
    if ($cfg['alerting'] !== 'yes') {
        return;
    }
    $want = [];
    $detail = [];
    if (vncbind_has_public_addr()) {
        $domains = vncbind_domain_list();
        if ($domains === null) {
            return;
        }
        foreach ($domains as $uuid => $name) {
            $hits = [];
            $text = [];
            $sev = vncbind_xml_vnc_severity(vncbind_dumpxml($uuid, true));
            if ($sev !== 'ok') {
                $hits['saved'] = $sev;
                $text[] = 'Saved ' . ($sev === 'critical' ? 'has no VNC password' : 'has a VNC password');
            }
            if (vncbind_domain_live($uuid)) {
                $sev = vncbind_xml_vnc_severity(vncbind_dumpxml($uuid, false));
                if ($sev !== 'ok') {
                    $hits['running'] = $sev;
                    $text[] = 'Running ' . ($sev === 'critical' ? 'has no VNC password' : 'has a VNC password');
                }
            }
            if (!$hits) {
                continue;
            }
            $worst = in_array('critical', $hits, true) ? 'critical' : 'warning';
            $where = isset($hits['saved'], $hits['running']) ? 'Saved and running' : (isset($hits['running']) ? 'Running' : 'Saved');
            $want[$uuid] = $worst . '|' . $where;
            $detail[$uuid] = [$name, $text, $worst];
        }
    }
    $prev = vncbind_alert_state();
    foreach ($want as $uuid => $token) {
        if (($prev[$uuid] ?? '') === $token) {
            continue;
        }
        [$name, $text, $worst] = $detail[$uuid];
        vncbind_notify(
            $name . ' VNC listens on every interface',
            implode('. ', $text) . '. The listen address is every interface, and this host has an internet address.',
            $worst === 'critical' ? 'alert' : 'warning'
        );
    }
    vncbind_alert_save($want);
}

/* ---------- Uninstall ---------- */

/**
 * Before the proxy goes away: a VM whose saved VNC listen is the local console
 * address gets its first checked address that is still on this host, so a
 * remote VNC client keeps working without the proxy (one address only; libvirt
 * VNC has a single listen). VMs without a usable checked address stay local.
 * Never writes every interface. Returns lines for the uninstall log.
 */
function vncbind_restore_all(): array {
    $log = [];
    $domains = vncbind_domain_list();
    if ($domains === null) {
        return ['libvirt not running; VM definitions left as they are'];
    }
    $binds = vncbind_binds_reconcile(vncbind_load_binds(), $domains);
    $host = array_column(vncbind_host_addrs(), 'addr');
    foreach ($binds as $uuid => $row) {
        if (!vncbind_valid_uuid($uuid)) {
            continue;
        }
        $target = '';
        foreach ($row['addrs'] as $a) {
            foreach ($host as $h) {
                if ($target === '' && vncbind_same_addr($a, $h)) {
                    $target = $a;
                }
            }
        }
        if ($target === '') {
            $log[] = $row['name'] . ': stays on the local console address';
            continue;
        }
        $xml = vncbind_dumpxml($uuid, true);
        $info = vncbind_vnc_info($xml) ?? [];
        $local = $info !== [];
        foreach ($info as $vnc) {
            if ($vnc['kind'] !== 'address' || !vncbind_same_addr($vnc['listen'], vncbind_console_addr())) {
                $local = false;
            }
        }
        if (!$local) {
            continue;
        }
        $new = vncbind_rewrite_xml($xml, $target);
        $ok = $new !== null && vncbind_define($new);
        $log[] = $row['name'] . ': VNC listen ' . ($ok ? 'set to ' . $target : 'could not be changed') . ' (applies at next VM start)';
    }
    return $log;
}
