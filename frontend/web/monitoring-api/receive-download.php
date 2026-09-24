<?php

declare(strict_types=1);

/**
 * POST /monitoring-api/receive-download.php
 * Ingest download manifest + record list (no binary file upload).
 */

require_once __DIR__ . '/_lib.php';

$auth = monAuthenticateDevice(true);
/** @var PDO $pdo */
$pdo = $auth['pdo'];
$deviceId = (string) $auth['deviceId'];
$body = $auth['body'];

$localDownloadIdRaw = $body['local_download_id'] ?? $body['download_id'] ?? null;
if ($localDownloadIdRaw === null || $localDownloadIdRaw === '' || !is_numeric($localDownloadIdRaw)) {
    monJson(422, ['success' => false, 'message' => 'local_download_id is required.']);
}
$localDownloadId = (int) $localDownloadIdRaw;

$fileName = trim((string) ($body['file_name'] ?? $body['filename'] ?? ''));
if ($fileName === '') {
    monJson(422, ['success' => false, 'message' => 'file_name is required.']);
}
$fileName = substr($fileName, 0, 255);

// Reject accidental binary payloads
if (!empty($body['file_base64']) || !empty($body['file_binary']) || !empty($body['content_base64'])) {
    monJson(422, [
        'success' => false,
        'message' => 'Binary file upload is not allowed. Send download manifest and records only.',
    ]);
}

$now = gmdate('Y-m-d H:i:s');
$userId = $body['user_id'] ?? null;
$userId = ($userId === null || $userId === '') ? null : (int) $userId;
$username = isset($body['username']) ? substr(trim((string) $body['username']), 0, 100) : null;
$searchId = $body['search_id'] ?? $body['local_search_id'] ?? null;
$searchId = ($searchId === null || $searchId === '') ? null : (int) $searchId;
$fileType = isset($body['file_type']) ? substr(trim((string) $body['file_type']), 0, 50) : null;
$fileSize = isset($body['file_size']) && $body['file_size'] !== '' ? (int) $body['file_size'] : null;
$rowsCount = isset($body['rows_count']) && $body['rows_count'] !== '' ? (int) $body['rows_count'] : null;
$downloadType = isset($body['download_type']) ? substr(trim((string) $body['download_type']), 0, 100) : null;
$sourceDescription = isset($body['source_description']) ? (string) $body['source_description'] : null;
$downloadedAt = monParseDateTime(
    isset($body['downloaded_at']) ? (string) $body['downloaded_at'] : null,
    $now
);

$records = $body['records'] ?? $body['downloaded_records'] ?? $body['downloaded_records_json'] ?? null;
$recordsJson = null;
if (is_array($records)) {
    if (count($records) > 5000) {
        monJson(422, ['success' => false, 'message' => 'Send at most 5000 downloaded records.']);
    }
    if ($rowsCount === null) {
        $rowsCount = count($records);
    }
    $recordsJson = monSanitizeMetadata(['records' => $records]);
} elseif (is_string($records) && $records !== '') {
    $decoded = json_decode($records, true);
    $recordsJson = is_array($decoded) ? monSanitizeMetadata($decoded) : null;
}

$duplicate = false;
$remoteId = 0;

try {
    $pdo->beginTransaction();
    $exists = $pdo->prepare(
        'SELECT id FROM remote_downloads WHERE device_id = ? AND local_download_id = ? LIMIT 1'
    );
    $exists->execute([$deviceId, $localDownloadId]);
    $existing = $exists->fetch();

    if ($existing) {
        $duplicate = true;
        $remoteId = (int) $existing['id'];
        $upd = $pdo->prepare(
            'UPDATE remote_downloads
             SET user_id = COALESCE(?, user_id),
                 username = COALESCE(?, username),
                 search_id = COALESCE(?, search_id),
                 file_name = ?,
                 file_type = COALESCE(?, file_type),
                 file_size = COALESCE(?, file_size),
                 rows_count = COALESCE(?, rows_count),
                 download_type = COALESCE(?, download_type),
                 source_description = COALESCE(?, source_description),
                 downloaded_records_json = COALESCE(?, downloaded_records_json),
                 downloaded_at = ?,
                 received_at = ?
             WHERE id = ?'
        );
        $upd->execute([
            $userId,
            ($username !== null && $username !== '') ? $username : null,
            $searchId,
            $fileName,
            $fileType,
            $fileSize,
            $rowsCount,
            $downloadType,
            $sourceDescription,
            $recordsJson,
            $downloadedAt,
            $now,
            $remoteId,
        ]);
    } else {
        $ins = $pdo->prepare(
            'INSERT INTO remote_downloads
            (device_id, local_download_id, user_id, username, search_id, file_name, file_type, file_size,
             rows_count, download_type, source_description, downloaded_records_json, downloaded_at, received_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $ins->execute([
            $deviceId,
            $localDownloadId,
            $userId,
            ($username !== null && $username !== '') ? $username : null,
            $searchId,
            $fileName,
            $fileType,
            $fileSize,
            $rowsCount,
            $downloadType,
            $sourceDescription,
            $recordsJson,
            $downloadedAt,
            $now,
        ]);
        $remoteId = (int) $pdo->lastInsertId();
    }

    monTouchDevice($pdo, $deviceId, true);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    monSafeError('Failed to store download.', $e);
}

monJson(200, [
    'success' => true,
    'duplicate' => $duplicate,
    'remote_download_id' => $remoteId,
    'local_download_id' => $localDownloadId,
    'message' => $duplicate ? 'Download already known.' : 'Download received successfully',
]);
