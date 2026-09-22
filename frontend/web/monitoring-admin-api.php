<?php

declare(strict_types=1);

/**
 * Admin Monitoring API — devices, activity, controls, audit.
 * Requires admin session. Never returns API keys.
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
    monSafeError('Database unavailable.', $e);
}

$adminUserId = isset($user['id']) && is_numeric($user['id']) ? (int) $user['id'] : null;

if ($action === 'csrf' && $method === 'GET') {
    monJson(200, ['ok' => true, 'success' => true, 'csrf' => monCsrfToken()]);
}

if ($action === 'devices' && $method === 'GET') {
    try {
        $rows = $pdo->query(
            'SELECT id, device_id, device_name, status, license_expires_at, daily_search_limit,
                    maintenance_mode, maintenance_message, last_seen_at, last_ip_address, created_at, updated_at
             FROM monitored_devices
             ORDER BY last_seen_at IS NULL, last_seen_at DESC, device_id ASC'
        )->fetchAll() ?: [];
    } catch (Throwable $e) {
        monSafeError('Failed to load devices.', $e);
    }

    $devices = array_map(static fn(array $row): array => monPublicDeviceRow($row), $rows);
    monJson(200, ['ok' => true, 'success' => true, 'devices' => $devices]);
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

    $logsStmt = $pdo->prepare(
        'SELECT id, device_id, local_record_id, user_id, username, action, description, endpoint,
                request_method, status, results_count, ip_address, metadata_json, occurred_at, received_at
         FROM remote_activity_logs
         WHERE device_id = ?
         ORDER BY occurred_at DESC, id DESC
         LIMIT 50'
    );
    $logsStmt->execute([$deviceId]);
    $logs = $logsStmt->fetchAll() ?: [];

    $auditStmt = $pdo->prepare(
        'SELECT id, device_id, admin_user_id, old_status, new_status, description, created_at
         FROM device_control_audit
         WHERE device_id = ?
         ORDER BY created_at DESC, id DESC
         LIMIT 30'
    );
    $auditStmt->execute([$deviceId]);
    $audit = $auditStmt->fetchAll() ?: [];

    monJson(200, [
        'ok' => true,
        'success' => true,
        'device' => monPublicDeviceRow($device),
        'recentLogs' => $logs,
        'audit' => $audit,
        'csrf' => monCsrfToken(),
    ]);
}

if ($action === 'update-device' && $method === 'POST') {
    $input = monReadJsonBody();
    monRequireCsrf(isset($input['csrf']) ? (string) $input['csrf'] : null);

    $deviceId = trim((string) ($input['device_id'] ?? ''));
    if ($deviceId === '') {
        monJson(422, ['ok' => false, 'success' => false, 'message' => 'device_id required.']);
    }
    $device = monFindDevice($pdo, $deviceId);
    if ($device === null) {
        monJson(404, ['ok' => false, 'success' => false, 'message' => 'Device not found.']);
    }

    $oldStatus = (string) ($device['status'] ?? '');
    $status = strtolower(trim((string) ($input['status'] ?? $device['status'])));
    if (!in_array($status, ['active', 'suspended', 'expired'], true)) {
        monJson(422, ['ok' => false, 'success' => false, 'message' => 'Invalid status.']);
    }

    $expires = $input['license_expires_at'] ?? $device['license_expires_at'];
    $expires = ($expires === null || $expires === '') ? null : (string) $expires;
    if ($expires !== null && strtotime($expires) === false) {
        monJson(422, ['ok' => false, 'success' => false, 'message' => 'Invalid license_expires_at.']);
    }

    $limit = array_key_exists('daily_search_limit', $input)
        ? $input['daily_search_limit']
        : $device['daily_search_limit'];
    if ($limit === '' || $limit === null) {
        $limit = null;
    } else {
        $limit = max(0, (int) $limit);
    }

    $maintenance = !empty($input['maintenance_mode']);
    $message = array_key_exists('maintenance_message', $input)
        ? $input['maintenance_message']
        : (array_key_exists('message', $input) ? $input['message'] : $device['maintenance_message']);
    $message = ($message === null || trim((string) $message) === '') ? null : (string) $message;

    $note = trim((string) ($input['description'] ?? ''));
    if ($note === '') {
        $note = 'Admin updated remote control settings.';
    }

    try {
        $pdo->beginTransaction();
        $upd = $pdo->prepare(
            'UPDATE monitored_devices
             SET status = ?, license_expires_at = ?, daily_search_limit = ?, maintenance_mode = ?,
                 maintenance_message = ?, updated_at = ?
             WHERE device_id = ?'
        );
        $upd->execute([
            $status,
            $expires,
            $limit,
            $maintenance ? 1 : 0,
            $message,
            gmdate('Y-m-d H:i:s'),
            $deviceId,
        ]);

        $audit = $pdo->prepare(
            'INSERT INTO device_control_audit (device_id, admin_user_id, old_status, new_status, description, created_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $audit->execute([
            $deviceId,
            $adminUserId,
            $oldStatus !== '' ? $oldStatus : null,
            $status,
            $note,
            gmdate('Y-m-d H:i:s'),
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        monSafeError('Failed to update device.', $e);
    }

    $fresh = monFindDevice($pdo, $deviceId);
    monJson(200, [
        'ok' => true,
        'success' => true,
        'message' => 'Device updated.',
        'device' => is_array($fresh) ? monPublicDeviceRow($fresh) : null,
        'csrf' => monCsrfToken(),
    ]);
}

if ($action === 'activity' && $method === 'GET') {
    $deviceId = trim((string) ($_GET['device_id'] ?? ''));
    $username = trim((string) ($_GET['username'] ?? ''));
    $actionFilter = trim((string) ($_GET['log_action'] ?? $_GET['action_filter'] ?? ''));
    $from = trim((string) ($_GET['from'] ?? ''));
    $to = trim((string) ($_GET['to'] ?? ''));
    $category = strtolower(trim((string) ($_GET['category'] ?? '')));
    $limit = min(200, max(1, (int) ($_GET['limit'] ?? 100)));

    $sql = 'SELECT id, device_id, local_record_id, user_id, username, action, description, endpoint,
                   request_method, status, results_count, ip_address, metadata_json, occurred_at, received_at
            FROM remote_activity_logs WHERE 1=1';
    $params = [];

    if ($deviceId !== '') {
        $sql .= ' AND device_id = ?';
        $params[] = $deviceId;
    }
    if ($username !== '') {
        $sql .= ' AND username LIKE ?';
        $params[] = '%' . $username . '%';
    }
    if ($actionFilter !== '') {
        $sql .= ' AND action = ?';
        $params[] = $actionFilter;
    }
    if ($from !== '') {
        $sql .= ' AND occurred_at >= ?';
        $params[] = $from . (str_contains($from, ' ') ? '' : ' 00:00:00');
    }
    if ($to !== '') {
        $sql .= ' AND occurred_at <= ?';
        $params[] = $to . (str_contains($to, ' ') ? '' : ' 23:59:59');
    }
    if ($category === 'login') {
        $sql .= " AND (action LIKE '%login%' OR action LIKE '%auth%' OR action LIKE '%sign_in%')";
    } elseif ($category === 'search') {
        $sql .= " AND (action LIKE '%search%' OR results_count IS NOT NULL)";
    } elseif ($category === 'api') {
        $sql .= " AND (endpoint IS NOT NULL OR request_method IS NOT NULL)";
    } elseif ($category === 'error') {
        $sql .= " AND (status LIKE '%error%' OR status LIKE '%fail%' OR action LIKE '%error%')";
    }

    $sql .= ' ORDER BY occurred_at DESC, id DESC LIMIT ' . $limit;

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        monSafeError('Failed to load activity.', $e);
    }

    monJson(200, [
        'ok' => true,
        'success' => true,
        'count' => count($rows),
        'logs' => $rows,
    ]);
}

monJson(400, ['ok' => false, 'success' => false, 'message' => 'Unknown action.']);
