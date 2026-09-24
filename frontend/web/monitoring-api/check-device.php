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
$body = $auth['body'];

try {
    monTouchDevice($pdo, $deviceId, true);
    // Client may acknowledge applied control_version
    $appliedVersion = $body['control_version_applied'] ?? $body['applied_control_version'] ?? null;
    if ($appliedVersion !== null && $appliedVersion !== '' && is_numeric($appliedVersion)) {
        monMarkCommandApplied($pdo, $deviceId);
    }
    $fresh = monFindDevice($pdo, $deviceId) ?: $auth['device'];
} catch (Throwable $e) {
    monSafeError('Database unavailable.', $e);
}

$status = strtolower(trim((string) ($fresh['status'] ?? 'active')));
if (!in_array($status, ['active', 'suspended', 'expired'], true)) {
    $status = 'active';
}

$controlMode = strtolower(trim((string) ($fresh['control_mode'] ?? 'active')));
if (!in_array($controlMode, ['active', 'read_only', 'api_blocked', 'fully_blocked'], true)) {
    $controlMode = 'active';
}

$expires = $fresh['license_expires_at'] ?? null;
$expiresOut = ($expires !== null && $expires !== '') ? (string) $expires : null;

$searchLimit = $fresh['daily_search_limit'];
$searchLimitOut = ($searchLimit === null || $searchLimit === '') ? null : (int) $searchLimit;
$downloadLimit = $fresh['daily_download_limit'] ?? null;
$downloadLimitOut = ($downloadLimit === null || $downloadLimit === '') ? null : (int) $downloadLimit;

$maintenance = !empty($fresh['maintenance_mode']);
$blocked = $fresh['blocked_message'] ?? $fresh['maintenance_message'] ?? null;
$blockedOut = ($blocked !== null && trim((string) $blocked) !== '') ? (string) $blocked : null;

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
    'device_id' => $deviceId,
    'device_status' => $status,
    'control_mode' => $controlMode,
    'control_version' => (int) ($fresh['control_version'] ?? 1),
    'license_expires_at' => $expiresOut,
    'daily_search_limit' => $searchLimitOut,
    'daily_download_limit' => $downloadLimitOut,
    'maintenance_mode' => $maintenance,
    'blocked_message' => $blockedOut,
    'message' => $blockedOut,
    'server_time' => gmdate('Y-m-d H:i:s'),
    'last_seen_at' => $fresh['last_seen_at'] ?? null,
    'last_sync_at' => $fresh['last_sync_at'] ?? null,
    'last_command_received_at' => $fresh['last_command_received_at'] ?? null,
    'last_command_applied_at' => $fresh['last_command_applied_at'] ?? null,
]);
