<?php

declare(strict_types=1);

/**
 * POST /monitoring-api/receive-logs.php
 * Ingest activity logs from localhost installations (HMAC + Bearer auth).
 */

require_once __DIR__ . '/_lib.php';

$auth = monAuthenticateDevice();
/** @var PDO $pdo */
$pdo = $auth['pdo'];
$deviceId = (string) $auth['deviceId'];
$body = $auth['body'];
$deviceUuid = trim((string) ($body['device_uuid'] ?? ''));

$logs = $body['logs'] ?? null;
if (!is_array($logs)) {
    monJson(422, ['success' => false, 'message' => 'logs array is required.']);
}
if (count($logs) > 500) {
    monJson(422, ['success' => false, 'message' => 'Send at most 500 logs per request.']);
}

$accepted = 0;
$now = gmdate('Y-m-d H:i:s');

$insert = $pdo->prepare(
    'INSERT INTO monitoring_activity_logs
    (device_id, local_id, user_id, username, action, description, endpoint, request_method, status, ip_address, metadata_json, created_at_client, received_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
      username = VALUES(username),
      action = VALUES(action),
      description = VALUES(description),
      endpoint = VALUES(endpoint),
      request_method = VALUES(request_method),
      status = VALUES(status),
      ip_address = VALUES(ip_address),
      metadata_json = VALUES(metadata_json),
      created_at_client = VALUES(created_at_client),
      received_at = VALUES(received_at)'
);

foreach ($logs as $row) {
    if (!is_array($row)) {
        continue;
    }
    $localId = $row['local_id'] ?? null;
    $localId = $localId === null || $localId === '' ? null : (int) $localId;

    $userId = $row['user_id'] ?? null;
    $userId = $userId === null || $userId === '' ? null : substr((string) $userId, 0, 64);

    $username = isset($row['username']) ? substr(trim((string) $row['username']), 0, 128) : null;
    $action = isset($row['action']) ? substr(trim((string) $row['action']), 0, 64) : null;
    $description = isset($row['description']) ? (string) $row['description'] : null;
    $endpoint = isset($row['endpoint']) ? substr(trim((string) $row['endpoint']), 0, 255) : null;
    $method = isset($row['request_method']) ? substr(strtoupper(trim((string) $row['request_method'])), 0, 16) : null;
    $status = isset($row['status']) ? substr(trim((string) $row['status']), 0, 32) : null;
    $ip = isset($row['ip_address']) ? substr(trim((string) $row['ip_address']), 0, 64) : null;
    $createdClient = isset($row['created_at']) ? substr(trim((string) $row['created_at']), 0, 64) : null;
    $metaJson = monSanitizeMetadata($row['metadata'] ?? null);

    try {
        $insert->execute([
            $deviceId,
            $localId,
            $userId,
            $username !== '' ? $username : null,
            $action !== '' ? $action : null,
            $description,
            $endpoint !== '' ? $endpoint : null,
            $method !== '' ? $method : null,
            $status !== '' ? $status : null,
            $ip !== '' ? $ip : null,
            $metaJson,
            $createdClient !== '' ? $createdClient : null,
            $now,
        ]);
        $accepted++;
    } catch (Throwable $e) {
        // Skip bad rows; continue batch
        continue;
    }
}

monTouchDevice($pdo, $deviceId, $deviceUuid !== '' ? $deviceUuid : null);

monJson(200, [
    'success' => true,
    'accepted' => $accepted,
    'message' => 'Logs stored',
]);
