<?php

declare(strict_types=1);

/**
 * POST /monitoring-api/check-device.php
 * Remote control status for localhost installations.
 */

require_once __DIR__ . '/_lib.php';

$auth = monAuthenticateDevice(false);
/** @var PDO $pdo */
$pdo = $auth['pdo'];
$deviceId = (string) $auth['deviceId'];

try {
    monTouchDevice($pdo, $deviceId);
    $fresh = monFindDevice($pdo, $deviceId) ?: $auth['device'];
} catch (Throwable $e) {
    monSafeError('Database unavailable.', $e);
}

$status = strtolower(trim((string) ($fresh['status'] ?? 'active')));
if (!in_array($status, ['active', 'suspended', 'expired'], true)) {
    $status = 'active';
}

$expires = $fresh['license_expires_at'] ?? null;
$expiresOut = ($expires !== null && $expires !== '') ? (string) $expires : null;

$limit = $fresh['daily_search_limit'];
$limitOut = ($limit === null || $limit === '') ? null : (int) $limit;

$maintenance = !empty($fresh['maintenance_mode']);
$message = $fresh['maintenance_message'] ?? null;
$messageOut = ($message !== null && trim((string) $message) !== '') ? (string) $message : null;

if ($expiresOut !== null) {
    $expTs = strtotime($expiresOut);
    if ($expTs !== false && $expTs < time() && $status === 'active') {
        $status = 'expired';
        try {
            $upd = $pdo->prepare('UPDATE monitored_devices SET status = ?, updated_at = ? WHERE device_id = ?');
            $upd->execute(['expired', gmdate('Y-m-d H:i:s'), $deviceId]);
        } catch (Throwable $e) {
            error_log('Monitoring auto-expire failed: ' . $e->getMessage());
        }
    }
}

monJson(200, [
    'success' => true,
    'device_status' => $status,
    'license_expires_at' => $expiresOut,
    'daily_search_limit' => $limitOut,
    'maintenance_mode' => $maintenance,
    'message' => $messageOut,
    'server_time' => gmdate('Y-m-d H:i:s'),
]);
