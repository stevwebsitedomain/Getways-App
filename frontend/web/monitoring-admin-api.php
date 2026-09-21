<?php

declare(strict_types=1);

/**
 * Admin Monitoring API — devices list/update + activity logs.
 * Requires admin session. Does not expose API keys.
 */

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/auth-init.php';
require_once __DIR__ . '/monitoring-api/_lib.php';
gwAuthStartSession();

$user = $_SESSION['gw_auth_user'] ?? null;
if (!is_array($user) || strtolower((string) ($user['role'] ?? '')) !== 'admin') {
    monJson(401, ['ok' => false, 'success' => false, 'message' => 'Admin login required.']);
}

$action = strtolower(trim((string) ($_GET['action'] ?? $_POST['action'] ?? '')));
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

try {
    $pdo = monPdo();
} catch (Throwable $e) {
    monJson(500, ['ok' => false, 'success' => false, 'message' => 'Database unavailable: ' . $e->getMessage()]);
}

if ($action === 'devices' && $method === 'GET') {
    $rows = $pdo->query(
        'SELECT id, device_id, device_uuid, status, license_expires_at, daily_search_limit,
                maintenance_mode, message, last_seen_at, created_at, updated_at
         FROM monitoring_devices
         ORDER BY last_seen_at IS NULL, last_seen_at DESC, device_id ASC'
    )->fetchAll();
    monJson(200, ['ok' => true, 'success' => true, 'devices' => $rows ?: []]);
}

if ($action === 'device' && $method === 'GET') {
    $deviceId = trim((string) ($_GET['device_id'] ?? ''));
    if ($deviceId === '') {
        monJson(422, ['ok' => false, 'success' => false, 'message' => 'device_id required.']);
    }
    $device = monFindDevice($pdo, $deviceId);
    if ($device === null) {
        monJson(404, ['ok' => false, 'success' => false, 'message' => 'Device not found.']);
    }
    unset($device['api_key_hash']);

    $logsStmt = $pdo->prepare(
        'SELECT id, device_id, local_id, username, action, description, endpoint, request_method,
                status, ip_address, metadata_json, created_at_client, received_at
         FROM monitoring_activity_logs
         WHERE device_id = ?
         ORDER BY received_at DESC, id DESC
         LIMIT 50'
    );
    $logsStmt->execute([$deviceId]);
    $logs = $logsStmt->fetchAll() ?: [];

    monJson(200, [
        'ok' => true,
        'success' => true,
        'device' => $device,
        'recentLogs' => $logs,
    ]);
}

if ($action === 'update-device' && $method === 'POST') {
    $input = monReadJsonBody();
    $deviceId = trim((string) ($input['device_id'] ?? ''));
    if ($deviceId === '') {
        monJson(422, ['ok' => false, 'success' => false, 'message' => 'device_id required.']);
    }
    $device = monFindDevice($pdo, $deviceId);
    if ($device === null) {
        monJson(404, ['ok' => false, 'success' => false, 'message' => 'Device not found.']);
    }

    $status = strtolower(trim((string) ($input['status'] ?? $device['status'])));
    if (!in_array($status, ['active', 'suspended', 'expired'], true)) {
        monJson(422, ['ok' => false, 'success' => false, 'message' => 'Invalid status.']);
    }

    $expires = $input['license_expires_at'] ?? $device['license_expires_at'];
    $expires = $expires === null || $expires === '' ? null : (string) $expires;

    $limit = array_key_exists('daily_search_limit', $input)
        ? $input['daily_search_limit']
        : $device['daily_search_limit'];
    if ($limit === '' || $limit === null) {
        $limit = null;
    } else {
        $limit = max(0, (int) $limit);
    }

    $maintenance = !empty($input['maintenance_mode']);
    $message = array_key_exists('message', $input) ? $input['message'] : $device['message'];
    $message = $message === null || trim((string) $message) === '' ? null : (string) $message;

    $now = gmdate('Y-m-d H:i:s');
    $upd = $pdo->prepare(
        'UPDATE monitoring_devices
         SET status = ?, license_expires_at = ?, daily_search_limit = ?, maintenance_mode = ?, message = ?, updated_at = ?
         WHERE device_id = ?'
    );
    $upd->execute([$status, $expires, $limit, $maintenance ? 1 : 0, $message, $now, $deviceId]);

    $fresh = monFindDevice($pdo, $deviceId);
    if (is_array($fresh)) {
        unset($fresh['api_key_hash']);
    }

    monJson(200, [
        'ok' => true,
        'success' => true,
        'message' => 'Device updated.',
        'device' => $fresh,
    ]);
}

if ($action === 'activity' && $method === 'GET') {
    $deviceId = trim((string) ($_GET['device_id'] ?? ''));
    $actionFilter = trim((string) ($_GET['log_action'] ?? $_GET['action_filter'] ?? ''));
    $from = trim((string) ($_GET['from'] ?? ''));
    $to = trim((string) ($_GET['to'] ?? ''));
    $limit = min(200, max(1, (int) ($_GET['limit'] ?? 100)));

    $sql = 'SELECT id, device_id, local_id, username, action, description, endpoint, request_method,
                   status, ip_address, metadata_json, created_at_client, received_at
            FROM monitoring_activity_logs WHERE 1=1';
    $params = [];
    if ($deviceId !== '') {
        $sql .= ' AND device_id = ?';
        $params[] = $deviceId;
    }
    if ($actionFilter !== '') {
        $sql .= ' AND action = ?';
        $params[] = $actionFilter;
    }
    if ($from !== '') {
        $sql .= ' AND received_at >= ?';
        $params[] = $from . (str_contains($from, ' ') ? '' : ' 00:00:00');
    }
    if ($to !== '') {
        $sql .= ' AND received_at <= ?';
        $params[] = $to . (str_contains($to, ' ') ? '' : ' 23:59:59');
    }
    $sql .= ' ORDER BY received_at DESC, id DESC LIMIT ' . $limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll() ?: [];

    monJson(200, [
        'ok' => true,
        'success' => true,
        'count' => count($rows),
        'logs' => $rows,
    ]);
}

monJson(400, ['ok' => false, 'success' => false, 'message' => 'Unknown action.']);
