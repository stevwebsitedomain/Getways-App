<?php

declare(strict_types=1);

/**
 * POST /monitoring-api/check-device.php
 * Remote control response for localhost installations (status / limits only).
 */

require_once __DIR__ . '/_lib.php';

$auth = monAuthenticateDevice();
/** @var PDO $pdo */
$pdo = $auth['pdo'];
$device = $auth['device'];
$deviceId = (string) $auth['deviceId'];
$body = $auth['body'];
$deviceUuid = trim((string) ($body['device_uuid'] ?? ''));

monTouchDevice($pdo, $deviceId, $deviceUuid !== '' ? $deviceUuid : null);

// Refresh device row after touch
$fresh = monFindDevice($pdo, $deviceId) ?: $device;

$status = strtolower(trim((string) ($fresh['status'] ?? 'active')));
if (!in_array($status, ['active', 'suspended', 'expired'], true)) {
    $status = 'active';
}

$expires = $fresh['license_expires_at'] ?? null;
$expiresOut = $expires !== null && $expires !== '' ? (string) $expires : null;

$limit = $fresh['daily_search_limit'];
$limitOut = $limit === null || $limit === '' ? null : (int) $limit;

$maintenance = !empty($fresh['maintenance_mode']);
$message = $fresh['message'] ?? null;
$messageOut = $message !== null && trim((string) $message) !== '' ? (string) $message : null;

// Auto-expire if past license date
if ($expiresOut !== null) {
    $expTs = strtotime($expiresOut . ' UTC');
    if ($expTs !== false && $expTs < time() && $status === 'active') {
        $status = 'expired';
    }
}

monJson(200, [
    'success' => true,
    'device_status' => $status,
    'license_expires_at' => $expiresOut,
    'daily_search_limit' => $limitOut,
    'maintenance_mode' => $maintenance,
    'message' => $messageOut,
]);
