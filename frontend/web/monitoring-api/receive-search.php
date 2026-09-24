<?php

declare(strict_types=1);

/**
 * POST /monitoring-api/receive-search.php
 * Ingest a search + result rows from a boss installation.
 */

require_once __DIR__ . '/_lib.php';
require_once __DIR__ . '/mon-sms.php';

$auth = monAuthenticateDevice(true);
/** @var PDO $pdo */
$pdo = $auth['pdo'];
$deviceId = (string) $auth['deviceId'];
$body = $auth['body'];

$localSearchIdRaw = $body['local_search_id'] ?? $body['search_id'] ?? null;
if ($localSearchIdRaw === null || $localSearchIdRaw === '' || !is_numeric($localSearchIdRaw)) {
    monJson(422, ['success' => false, 'message' => 'local_search_id is required.']);
}
$localSearchId = (int) $localSearchIdRaw;
if ($localSearchId < 0) {
    monJson(422, ['success' => false, 'message' => 'Invalid local_search_id.']);
}

$searchTerm = trim((string) ($body['search_term'] ?? $body['query'] ?? ''));
if ($searchTerm === '') {
    monJson(422, ['success' => false, 'message' => 'search_term is required.']);
}

$status = strtolower(trim((string) ($body['status'] ?? 'completed')));
if (!in_array($status, ['started', 'completed', 'failed'], true)) {
    $status = 'completed';
}

$now = gmdate('Y-m-d H:i:s');
$userId = $body['user_id'] ?? null;
$userId = ($userId === null || $userId === '') ? null : (int) $userId;
$username = isset($body['username']) ? substr(trim((string) $body['username']), 0, 100) : null;
$searchType = isset($body['search_type']) ? substr(trim((string) $body['search_type']), 0, 100) : null;
$errorMessage = isset($body['error_message']) ? (string) $body['error_message'] : null;
$filtersJson = monSanitizeMetadata($body['filters'] ?? $body['filters_json'] ?? null);
$searchedAt = monParseDateTime(
    isset($body['searched_at']) ? (string) $body['searched_at'] : (isset($body['created_at']) ? (string) $body['created_at'] : null),
    $now
);

$results = $body['results'] ?? $body['result_list'] ?? [];
if (!is_array($results)) {
    $results = [];
}
if (count($results) > 2000) {
    monJson(422, ['success' => false, 'message' => 'Send at most 2000 results per request.']);
}

$resultsCount = array_key_exists('results_count', $body)
    ? max(0, (int) $body['results_count'])
    : count($results);

$duplicate = false;
$remoteSearchId = 0;
$acceptedResults = 0;
$duplicateResults = 0;

try {
    $pdo->beginTransaction();

    $exists = $pdo->prepare(
        'SELECT id FROM remote_searches WHERE device_id = ? AND local_search_id = ? LIMIT 1'
    );
    $exists->execute([$deviceId, $localSearchId]);
    $existing = $exists->fetch();

    if ($existing) {
        $duplicate = true;
        $remoteSearchId = (int) $existing['id'];
        $upd = $pdo->prepare(
            'UPDATE remote_searches
             SET user_id = COALESCE(?, user_id),
                 username = COALESCE(?, username),
                 search_term = ?,
                 search_type = COALESCE(?, search_type),
                 filters_json = COALESCE(?, filters_json),
                 results_count = ?,
                 status = ?,
                 error_message = ?,
                 searched_at = ?,
                 received_at = ?
             WHERE id = ?'
        );
        $upd->execute([
            $userId,
            ($username !== null && $username !== '') ? $username : null,
            $searchTerm,
            ($searchType !== null && $searchType !== '') ? $searchType : null,
            $filtersJson,
            $resultsCount,
            $status,
            $errorMessage,
            $searchedAt,
            $now,
            $remoteSearchId,
        ]);
    } else {
        $ins = $pdo->prepare(
            'INSERT INTO remote_searches
            (device_id, local_search_id, user_id, username, search_term, search_type, filters_json,
             results_count, status, error_message, searched_at, received_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $ins->execute([
            $deviceId,
            $localSearchId,
            $userId,
            ($username !== null && $username !== '') ? $username : null,
            $searchTerm,
            ($searchType !== null && $searchType !== '') ? $searchType : null,
            $filtersJson,
            $resultsCount,
            $status,
            $errorMessage,
            $searchedAt,
            $now,
        ]);
        $remoteSearchId = (int) $pdo->lastInsertId();
    }

    $resExists = $pdo->prepare(
        'SELECT id FROM remote_search_results
         WHERE device_id = ? AND local_search_id = ? AND local_result_id = ? LIMIT 1'
    );
    $resIns = $pdo->prepare(
        'INSERT INTO remote_search_results
        (device_id, local_search_id, local_result_id, result_type, title, username, full_name,
         phone, email, location, profile_url, website_url, description, result_data_json, created_at, received_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    foreach ($results as $idx => $row) {
        if (!is_array($row)) {
            continue;
        }
        $localResultIdRaw = $row['local_result_id'] ?? $row['id'] ?? ($idx + 1);
        if (!is_numeric($localResultIdRaw)) {
            continue;
        }
        $localResultId = (int) $localResultIdRaw;
        $resExists->execute([$deviceId, $localSearchId, $localResultId]);
        if ($resExists->fetch()) {
            $duplicateResults++;
            continue;
        }
        $dataJson = monSanitizeMetadata($row['data'] ?? $row['result_data'] ?? $row['result_data_json'] ?? $row);
        $createdAt = monParseDateTime(
            isset($row['created_at']) ? (string) $row['created_at'] : null,
            $searchedAt
        );
        $resIns->execute([
            $deviceId,
            $localSearchId,
            $localResultId,
            isset($row['result_type']) ? substr(trim((string) $row['result_type']), 0, 100) : null,
            isset($row['title']) ? substr(trim((string) $row['title']), 0, 255) : null,
            isset($row['username']) ? substr(trim((string) $row['username']), 0, 255) : null,
            isset($row['full_name']) ? substr(trim((string) $row['full_name']), 0, 255) : null,
            isset($row['phone']) ? substr(trim((string) $row['phone']), 0, 100) : null,
            isset($row['email']) ? substr(trim((string) $row['email']), 0, 255) : null,
            isset($row['location']) ? substr(trim((string) $row['location']), 0, 255) : null,
            isset($row['profile_url']) ? (string) $row['profile_url'] : null,
            isset($row['website_url']) ? (string) $row['website_url'] : null,
            isset($row['description']) ? (string) $row['description'] : null,
            $dataJson,
            $createdAt,
            $now,
        ]);
        $acceptedResults++;
    }

    monTouchDevice($pdo, $deviceId, true);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    monSafeError('Failed to store search.', $e);
}

// SMS only for brand-new successful searches (not duplicates)
if (!$duplicate && $status === 'completed' && $remoteSearchId > 0) {
    monSmsAlertForSearch($pdo, $deviceId, $remoteSearchId);
}

monJson(200, [
    'success' => true,
    'duplicate' => $duplicate,
    'remote_search_id' => $remoteSearchId,
    'local_search_id' => $localSearchId,
    'accepted_results' => $acceptedResults,
    'duplicate_results' => $duplicateResults,
    'message' => $duplicate ? 'Search already known; results merged where new.' : 'Search received successfully',
]);
