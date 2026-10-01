<?php
require_once __DIR__ . '/vncbind-lib.php';

if (PHP_SAPI === 'cli') {
    $mode = $argv[1] ?? '';
    if ($mode === 'alert') {
        vncbind_alert();
    } else {
        vncbind_walk();
        vncbind_alert();
    }
    exit(0);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !vncbind_csrf_ok()) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Forbidden\n";
    exit;
}

vncbind_walk();
vncbind_alert();
header('Location: /Settings/Utilities/VncBind?checked=1', true, 303);
exit;
