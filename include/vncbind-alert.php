<?php
require_once __DIR__ . '/vncbind-lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !vncbind_csrf_ok()) {
    http_response_code(403);
    echo json_encode(['ok' => false]);
    exit;
}

vncbind_alert();
echo json_encode(['ok' => true]);
