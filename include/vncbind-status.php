<?php
require_once __DIR__ . '/vncbind-lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$cfg = vncbind_load_cfg();
echo json_encode([
    'interjection' => $cfg['interjection'],
    'ignore_interface_extra' => $cfg['ignore_interface_extra'],
    'choices' => vncbind_choices($cfg),
], JSON_UNESCAPED_SLASHES);
