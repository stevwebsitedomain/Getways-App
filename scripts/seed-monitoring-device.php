<?php

declare(strict_types=1);

/**
 * Ensure monitoring schema + seed BOSS-PC-001.
 * Prints the plaintext API key ONCE to stdout (not stored in DB).
 *
 * Usage:
 *   php scripts/seed-monitoring-device.php
 *   php scripts/seed-monitoring-device.php --rotate
 */

$root = dirname(__DIR__);
require_once $root . '/frontend/web/env-load.php';
require_once $root . '/frontend/web/monitoring-api/_lib.php';

gwLoadEnv();

$rotate = in_array('--rotate', $argv, true);

try {
    $pdo = monPdo();
} catch (Throwable $e) {
    fwrite(STDERR, "Database connection failed.\n");
    exit(1);
}

$deviceId = 'BOSS-PC-001';
$deviceName = 'Boss Local Computer';
$existing = monFindDevice($pdo, $deviceId);

if ($existing !== null && !$rotate) {
    fwrite(STDOUT, "Device {$deviceId} already exists. Pass --rotate to issue a new API key.\n");
    fwrite(STDOUT, "Plain API key is not stored and cannot be recovered from the database.\n");
    exit(0);
}

$plainKey = bin2hex(random_bytes(32)); // 64 hex chars
if (isset($argv[1]) && $argv[1] !== '--rotate' && preg_match('/^[a-f0-9]{64,}$/i', $argv[1])) {
    $plainKey = $argv[1];
}

$hash = password_hash($plainKey, PASSWORD_DEFAULT);
$now = gmdate('Y-m-d H:i:s');

if ($existing === null) {
    $stmt = $pdo->prepare(
        'INSERT INTO monitored_devices
        (device_id, device_name, api_key_hash, status, license_expires_at, daily_search_limit,
         maintenance_mode, maintenance_message, last_seen_at, last_ip_address, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, 0, NULL, NULL, NULL, ?, ?)'
    );
    $stmt->execute([
        $deviceId,
        $deviceName,
        $hash,
        'active',
        '2026-12-31 23:59:59',
        100,
        $now,
        $now,
    ]);
    fwrite(STDOUT, "Created device {$deviceId} ({$deviceName}).\n");
} else {
    $stmt = $pdo->prepare(
        'UPDATE monitored_devices
         SET device_name = ?, api_key_hash = ?, status = ?, updated_at = ?
         WHERE device_id = ?'
    );
    $stmt->execute([$deviceName, $hash, 'active', $now, $deviceId]);
    fwrite(STDOUT, "Rotated API key for {$deviceId}.\n");
}

fwrite(STDOUT, "\n=== SAVE THIS API KEY NOW (shown once) ===\n");
fwrite(STDOUT, $plainKey . "\n");
fwrite(STDOUT, "==========================================\n");
fwrite(STDOUT, "Only the password_hash() is stored in monitored_devices.api_key_hash.\n");
