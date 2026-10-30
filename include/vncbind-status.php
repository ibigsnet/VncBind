<?php
require_once __DIR__ . '/vncbind-lib.php';

// Read-only.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$cfg = vncbind_load_cfg();
$choices = [];
foreach (vncbind_choices($cfg) as $row) {
    $row['public'] = vncbind_addr_is_public($row['addr']);
    $choices[] = $row;
}
echo json_encode([
    'interjection' => $cfg['interjection'],
    'use_interface_extra' => $cfg['use_interface_extra'],
    'choices' => $choices,
    'binds' => vncbind_binds_by_name(),
], JSON_UNESCAPED_SLASHES);
