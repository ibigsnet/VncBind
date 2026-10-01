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
$addr = trim((string)($_POST['addr'] ?? ''));
$xml = (string)($_POST['xml'] ?? '');

if ($xml !== '') {
    if (!vncbind_choice_ok($cfg, $addr)) {
        http_response_code(400);
        echo json_encode(['ok' => false]);
        exit;
    }
    echo json_encode(['ok' => true, 'xml' => vncbind_rewrite_xml($xml, $addr)], JSON_UNESCAPED_SLASHES);
    exit;
}

if (!vncbind_redefine($domain, $addr)) {
    http_response_code(400);
    echo json_encode(['ok' => false]);
    exit;
}
echo json_encode(['ok' => true]);
