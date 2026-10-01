<?php
require_once __DIR__ . '/vncbind-lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !vncbind_csrf_ok()) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Forbidden\n";
    exit;
}

$cfg = vncbind_load_cfg();
$err = '';

if (isset($_POST['interjection'])) {
    $v = (string)$_POST['interjection'];
    if ($v !== 'yes' && $v !== 'no') {
        $err = 'check';
    } else {
        $cfg['interjection'] = $v;
    }
}
if ($err === '' && isset($_POST['ignore_interface_extra'])) {
    $v = (string)$_POST['ignore_interface_extra'];
    if ($v !== 'yes' && $v !== 'no') {
        $err = 'check';
    } else {
        $cfg['ignore_interface_extra'] = $v;
    }
}
if ($err === '' && isset($_POST['schedule'])) {
    $v = (string)$_POST['schedule'];
    if (!in_array($v, ['off', 'daily', 'weekly'], true)) {
        $err = 'check';
    } else {
        $cfg['schedule'] = $v;
    }
}
if ($err === '' && isset($_POST['alerting'])) {
    $v = (string)$_POST['alerting'];
    if ($v !== 'yes' && $v !== 'no') {
        $err = 'check';
    } else {
        $cfg['alerting'] = $v;
    }
}
if ($err === '' && isset($_POST['ifaces_present'])) {
    $names = [];
    $posted = isset($_POST['iface']) && is_array($_POST['iface']) ? $_POST['iface'] : [];
    foreach ($posted as $name) {
        $name = (string)$name;
        if (!vncbind_valid_iface($name)) {
            $err = 'check';
            break;
        }
        $names[$name] = true;
    }
    if ($err === '') {
        $cfg['interfaces'] = array_keys($names);
    }
}

if ($err === '' && !vncbind_save_cfg($cfg)) {
    $err = 'save';
}
if ($err === '') {
    if ($cfg['alerting'] !== 'yes') {
        @unlink(VNCBIND_ALERTS);
    }
    vncbind_apply_schedule($cfg);
    if (isset($_POST['alerting']) && $cfg['alerting'] === 'yes') {
        vncbind_alert();
    }
}

$back = '/Settings/Utilities/VncBind';
if (!empty($_POST['from']) && (string)$_POST['from'] === 'vm') {
    $back = '/Settings/VMSettings';
}
header('Location: ' . $back . ($err !== '' ? '?err=' . rawurlencode($err) : ''), true, 303);
exit;
