<?php
require_once __DIR__ . '/vncbind-lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !vncbind_csrf_ok()) {
    http_response_code(403);
    echo json_encode(['ok' => false]);
    exit;
}

$cfg = vncbind_load_cfg();
if ($cfg['interjection'] !== 'yes') {
    http_response_code(409);
    echo json_encode(['ok' => false]);
    exit;
}

$domain = trim((string)($_POST['domain'] ?? ''));
$addrs = vncbind_request_addrs();
$xml = (string)($_POST['xml'] ?? '');

if ($addrs === null || !vncbind_addrs_ok($cfg, $addrs)) {
    http_response_code(400);
    echo json_encode(['ok' => false]);
    exit;
}

if ($xml !== '') {
    // XML view: rewrite before VM Manager saves it, and remember the addresses.
    $out = vncbind_rewrite_xml($xml, vncbind_console_addr());
    $name = vncbind_xml_name($xml);
    $uuid = vncbind_xml_uuid($xml);
    if ($out === null || $name === '' || !vncbind_set_binds($uuid, $name, $addrs)) {
        http_response_code(400);
        echo json_encode(['ok' => false]);
        exit;
    }
    vncbind_listen_poke();
    echo json_encode(['ok' => true, 'xml' => $out], JSON_UNESCAPED_SLASHES);
    exit;
}

if (!vncbind_redefine($domain, $addrs)) {
    http_response_code(400);
    echo json_encode(['ok' => false]);
    exit;
}
echo json_encode(['ok' => true]);
