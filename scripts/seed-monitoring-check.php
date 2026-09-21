<?php
require dirname(__DIR__) . '/frontend/web/monitoring-api/_lib.php';
$pdo = monPdo();
$row = monFindDevice($pdo, 'BOSS-PC-001');
if (!$row) {
    fwrite(STDERR, "MISSING\n");
    exit(1);
}
echo 'OK device=' . $row['device_id'] . ' status=' . $row['status'] . ' limit=' . $row['daily_search_limit'] . PHP_EOL;
echo 'hash_ok=' . (password_verify('qbp385A4STrK6hRattuLqI7NM2peQNVHLACwJ3go', $row['api_key_hash']) ? 'yes' : 'no') . PHP_EOL;
