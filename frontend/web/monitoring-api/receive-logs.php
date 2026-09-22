<?php

declare(strict_types=1);

/**
 * POST /monitoring-api/receive-logs.php
 * Ingest activity logs from localhost installations.
 */

require_once __DIR__ . '/_lib.php';

$auth = monAuthenticateDevice(true);
/** @var PDO $pdo */
$pdo = $auth['pdo'];
$deviceId = (string) $auth['deviceId'];
$body = $auth['body'];

$logs = $body['logs'] ?? null;
if (!is_array($logs)) {
    monJson(422, ['success' => false, 'message' => 'logs array is required.']);
}
if (count($logs) > 500) {
    monJson(422, ['success' => false, 'message' => 'Send at most 500 logs per request.']);
}

$acceptedIds = [];
$duplicateIds = [];
$now = gmdate('Y-m-d H:i:s');

$existsStmt = $pdo->prepare(
    'SELECT id FROM remote_activity_logs WHERE device_id = ? AND local_record_id = ? LIMIT 1'
);
$insertStmt = $pdo->prepare(
    'INSERT INTO remote_activity_logs
    (device_id, local_record_id, user_id, username, action, description, endpoint, request_method,
     status, results_count, ip_address, metadata_json, occurred_at, received_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);

try {
    $pdo->beginTransaction();

    foreach ($logs as $row) {
        if (!is_array($row)) {
            continue;
        }

        $localIdRaw = $row['local_record_id'] ?? $row['local_id'] ?? null;
        if ($localIdRaw === null || $localIdRaw === '' || !is_numeric($localIdRaw)) {
            continue;
        }
        $localId = (int) $localIdRaw;
        if ($localId < 0) {
            continue;
        }

        $action = trim((string) ($row['action'] ?? ''));
        if ($action === '') {
            continue;
        }
        $action = substr($action, 0, 100);

        $userId = $row['user_id'] ?? null;
        $userId = ($userId === null || $userId === '') ? null : (int) $userId;

        $username = isset($row['username']) ? substr(trim((string) $row['username']), 0, 100) : null;
        $description = isset($row['description']) ? (string) $row['description'] : null;
        $endpoint = isset($row['endpoint']) ? substr(trim((string) $row['endpoint']), 0, 255) : null;
        $method = isset($row['request_method']) ? substr(strtoupper(trim((string) $row['request_method'])), 0, 10) : null;
        $status = isset($row['status']) ? substr(trim((string) $row['status']), 0, 50) : null;
        $ip = isset($row['ip_address']) ? substr(trim((string) $row['ip_address']), 0, 45) : null;

        $resultsCount = $row['results_count'] ?? null;
        if ($resultsCount === '' || $resultsCount === null) {
            $resultsCount = null;
        } else {
            $resultsCount = (int) $resultsCount;
        }

        $occurredAt = monParseDateTime(
            isset($row['occurred_at']) ? (string) $row['occurred_at'] : (isset($row['created_at']) ? (string) $row['created_at'] : null),
            $now
        );
        $metaJson = monSanitizeMetadata($row['metadata'] ?? $row['metadata_json'] ?? null);

        $existsStmt->execute([$deviceId, $localId]);
        if ($existsStmt->fetch()) {
            $duplicateIds[] = $localId;
            continue;
        }

        $insertStmt->execute([
            $deviceId,
            $localId,
            $userId,
            ($username !== null && $username !== '') ? $username : null,
            $action,
            $description,
            ($endpoint !== null && $endpoint !== '') ? $endpoint : null,
            ($method !== null && $method !== '') ? $method : null,
            ($status !== null && $status !== '') ? $status : null,
            $resultsCount,
            ($ip !== null && $ip !== '') ? $ip : null,
            $metaJson,
            $occurredAt,
            $now,
        ]);
        $acceptedIds[] = $localId;
    }

    monTouchDevice($pdo, $deviceId);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    monSafeError('Failed to store logs.', $e);
}

monJson(200, [
    'success' => true,
    'accepted_ids' => $acceptedIds,
    'duplicate_ids' => $duplicateIds,
    'message' => 'Logs received successfully',
]);
