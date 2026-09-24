<?php

declare(strict_types=1);

/**
 * Admin Monitoring API — devices, searches, downloads, SMS, controls, audit.
 * Requires admin session. Never returns API keys.
 */

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/auth-init.php';
require_once __DIR__ . '/monitoring-api/_lib.php';
require_once __DIR__ . '/monitoring-api/mon-sms.php';
gwAuthStartSession();

$user = $_SESSION['gw_auth_user'] ?? null;
if (!is_array($user) || strtolower((string) ($user['role'] ?? '')) !== 'admin') {
    monJson(401, ['ok' => false, 'success' => false, 'message' => 'Admin login required.']);
}

$action = strtolower(trim((string) ($_GET['action'] ?? $_POST['action'] ?? '')));
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($action === 'logins') {
    $action = 'activity';
    $_GET['category'] = 'login';
}

if ($action === 'csrf' && $method === 'GET') {
    monJson(200, ['ok' => true, 'success' => true, 'csrf' => monCsrfToken()]);
}

if ($action === 'save-db' && $method === 'POST') {
    $input = monReadJsonBody();
    monRequireCsrf(isset($input['csrf']) ? (string) $input['csrf'] : null);
    $host = trim((string) ($input['host'] ?? 'localhost'));
    $port = trim((string) ($input['port'] ?? '3306'));
    $name = trim((string) ($input['name'] ?? ''));
    $user = trim((string) ($input['user'] ?? ''));
    $pass = (string) ($input['password'] ?? '');
    if (!preg_match('/^[A-Za-z0-9._-]+$/', $host) || !preg_match('/^[A-Za-z0-9_-]+$/', $name) || !preg_match('/^[A-Za-z0-9_-]+$/', $user)) {
        monJson(422, ['ok' => false, 'success' => false, 'message' => 'Invalid database settings.']);
    }
    if ($port === '' || !ctype_digit($port)) {
        $port = '3306';
    }
    $cfg = ['host' => $host, 'port' => $port, 'name' => $name, 'user' => $user, 'pass' => $pass];
    $tables = [];
    try {
        $test = monOpenPdo($cfg);
        $test->query('SELECT 1');
        $tables = $test->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        monDbFailure($e);
    }
    if (!monStoreRuntimeDbConfig($cfg)) {
        monJson(500, ['ok' => false, 'success' => false, 'message' => 'Could not store database settings. Make frontend/web/runtime writable.']);
    }
    monJson(200, [
        'ok' => true,
        'success' => true,
        'message' => 'Monitoring database connected.',
        'tables' => array_values($tables),
        'csrf' => monCsrfToken(),
    ]);
}

try {
    $pdo = monPdo();
} catch (Throwable $e) {
    monDbFailure($e);
}

$adminUserId = isset($user['id']) && is_numeric($user['id']) ? (int) $user['id'] : null;

function monDateBound(string $value, bool $endOfDay): string
{
    $value = trim($value);
    if ($value === '') {
        return $value;
    }
    if (str_contains($value, ' ')) {
        return $value;
    }
    return $value . ($endOfDay ? ' 23:59:59' : ' 00:00:00');
}

function monScalar(PDO $pdo, string $sql, array $params = []): int
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

function monInsertAudit(
    PDO $pdo,
    string $deviceId,
    ?int $adminUserId,
    string $field,
    mixed $oldVal,
    mixed $newVal,
    string $oldStatus,
    string $newStatus,
    string $note
): void {
    $oldS = is_scalar($oldVal) || $oldVal === null ? (string) ($oldVal ?? '') : json_encode($oldVal);
    $newS = is_scalar($newVal) || $newVal === null ? (string) ($newVal ?? '') : json_encode($newVal);
    $stmt = $pdo->prepare(
        'INSERT INTO device_control_audit
        (device_id, admin_user_id, field_name, old_status, new_status, old_value, new_value, description, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $deviceId,
        $adminUserId,
        $field,
        $oldStatus !== '' ? $oldStatus : null,
        $newStatus !== '' ? $newStatus : null,
        $oldS !== '' ? $oldS : null,
        $newS !== '' ? $newS : null,
        $note,
        gmdate('Y-m-d H:i:s'),
    ]);
}

if ($action === 'csrf' && $method === 'GET') {
    monJson(200, ['ok' => true, 'success' => true, 'csrf' => monCsrfToken()]);
}

if ($action === 'devices' && $method === 'GET') {
    try {
        $rows = $pdo->query(
            'SELECT id, device_id, device_name, status, control_mode, control_version,
                    license_expires_at, daily_search_limit, daily_download_limit,
                    maintenance_mode, maintenance_message, blocked_message, sms_alerts_enabled,
                    last_seen_at, last_ip_address, last_sync_at, last_command_received_at,
                    last_command_applied_at, created_at, updated_at
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
        'SELECT id, device_id, admin_user_id, field_name, old_status, new_status, old_value, new_value, description, created_at
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

    $controlMode = strtolower(trim((string) ($input['control_mode'] ?? $device['control_mode'] ?? 'active')));
    if (!in_array($controlMode, ['active', 'read_only', 'api_blocked', 'fully_blocked'], true)) {
        monJson(422, ['ok' => false, 'success' => false, 'message' => 'Invalid control_mode.']);
    }

    $expires = $input['license_expires_at'] ?? $device['license_expires_at'];
    $expires = ($expires === null || $expires === '') ? null : (string) $expires;
    if ($expires !== null && strtotime($expires) === false) {
        monJson(422, ['ok' => false, 'success' => false, 'message' => 'Invalid license_expires_at.']);
    }

    $searchLimit = array_key_exists('daily_search_limit', $input)
        ? $input['daily_search_limit']
        : ($device['daily_search_limit'] ?? null);
    $searchLimit = ($searchLimit === '' || $searchLimit === null) ? null : max(0, (int) $searchLimit);

    $downloadLimit = array_key_exists('daily_download_limit', $input)
        ? $input['daily_download_limit']
        : ($device['daily_download_limit'] ?? null);
    $downloadLimit = ($downloadLimit === '' || $downloadLimit === null) ? null : max(0, (int) $downloadLimit);

    $maintenance = array_key_exists('maintenance_mode', $input)
        ? !empty($input['maintenance_mode'])
        : !empty($device['maintenance_mode']);

    $maintMsg = array_key_exists('maintenance_message', $input)
        ? $input['maintenance_message']
        : (array_key_exists('message', $input) ? $input['message'] : ($device['maintenance_message'] ?? null));
    $maintMsg = ($maintMsg === null || trim((string) $maintMsg) === '') ? null : (string) $maintMsg;

    $blockedMsg = array_key_exists('blocked_message', $input)
        ? $input['blocked_message']
        : ($device['blocked_message'] ?? null);
    $blockedMsg = ($blockedMsg === null || trim((string) $blockedMsg) === '') ? null : (string) $blockedMsg;

    $smsAlerts = array_key_exists('sms_alerts_enabled', $input)
        ? !empty($input['sms_alerts_enabled'])
        : (!isset($device['sms_alerts_enabled']) || !empty($device['sms_alerts_enabled']));

    $note = trim((string) ($input['description'] ?? ''));
    if ($note === '') {
        $note = 'Admin updated remote control settings.';
    }

    $changes = [
        'status' => [$oldStatus, $status],
        'control_mode' => [(string) ($device['control_mode'] ?? 'active'), $controlMode],
        'license_expires_at' => [(string) ($device['license_expires_at'] ?? ''), (string) ($expires ?? '')],
        'daily_search_limit' => [(string) ($device['daily_search_limit'] ?? ''), (string) ($searchLimit ?? '')],
        'daily_download_limit' => [(string) ($device['daily_download_limit'] ?? ''), (string) ($downloadLimit ?? '')],
        'maintenance_mode' => [!empty($device['maintenance_mode']) ? '1' : '0', $maintenance ? '1' : '0'],
        'maintenance_message' => [(string) ($device['maintenance_message'] ?? ''), (string) ($maintMsg ?? '')],
        'blocked_message' => [(string) ($device['blocked_message'] ?? ''), (string) ($blockedMsg ?? '')],
        'sms_alerts_enabled' => [(!isset($device['sms_alerts_enabled']) || !empty($device['sms_alerts_enabled'])) ? '1' : '0', $smsAlerts ? '1' : '0'],
    ];
    $bumped = false;
    foreach (['status', 'control_mode', 'blocked_message', 'daily_search_limit', 'daily_download_limit', 'maintenance_mode', 'maintenance_message'] as $k) {
        if (($changes[$k][0] ?? '') !== ($changes[$k][1] ?? '')) {
            $bumped = true;
            break;
        }
    }
    $newVersion = (int) ($device['control_version'] ?? 1);
    if ($bumped) {
        $newVersion++;
    }
    $now = gmdate('Y-m-d H:i:s');

    try {
        $pdo->beginTransaction();
        $upd = $pdo->prepare(
            'UPDATE monitored_devices
             SET status = ?, control_mode = ?, blocked_message = ?, license_expires_at = ?,
                 daily_search_limit = ?, daily_download_limit = ?, maintenance_mode = ?,
                 maintenance_message = ?, sms_alerts_enabled = ?, control_version = ?,
                 last_command_received_at = ?, updated_at = ?
             WHERE device_id = ?'
        );
        $upd->execute([
            $status,
            $controlMode,
            $blockedMsg,
            $expires,
            $searchLimit,
            $downloadLimit,
            $maintenance ? 1 : 0,
            $maintMsg,
            $smsAlerts ? 1 : 0,
            $newVersion,
            $now,
            $now,
            $deviceId,
        ]);

        foreach ($changes as $field => [$oldV, $newV]) {
            if ((string) $oldV === (string) $newV) {
                continue;
            }
            monInsertAudit($pdo, $deviceId, $adminUserId, $field, $oldV, $newV, $oldStatus, $status, $note);
        }
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
    $pg = monPagination(50, 200);

    $where = ' WHERE 1=1';
    $params = [];
    if ($deviceId !== '') {
        $where .= ' AND device_id = ?';
        $params[] = $deviceId;
    }
    if ($username !== '') {
        $where .= ' AND username LIKE ?';
        $params[] = '%' . $username . '%';
    }
    if ($actionFilter !== '') {
        $where .= ' AND action = ?';
        $params[] = $actionFilter;
    }
    if ($from !== '') {
        $where .= ' AND occurred_at >= ?';
        $params[] = monDateBound($from, false);
    }
    if ($to !== '') {
        $where .= ' AND occurred_at <= ?';
        $params[] = monDateBound($to, true);
    }
    if ($category === 'login') {
        $where .= " AND (action LIKE '%login%' OR action LIKE '%logout%' OR action LIKE '%auth%' OR action LIKE '%sign_in%')";
    } elseif ($category === 'search') {
        $where .= " AND (action LIKE '%search%' OR results_count IS NOT NULL)";
    } elseif ($category === 'api') {
        $where .= ' AND (endpoint IS NOT NULL OR request_method IS NOT NULL)';
    } elseif ($category === 'error') {
        $where .= " AND (status LIKE '%error%' OR status LIKE '%fail%' OR action LIKE '%error%')";
    }

    try {
        $total = monScalar($pdo, 'SELECT COUNT(*) FROM remote_activity_logs' . $where, $params);
        $sql = 'SELECT id, device_id, local_record_id, user_id, username, action, description, endpoint,
                       request_method, status, results_count, ip_address, metadata_json, occurred_at, received_at
                FROM remote_activity_logs' . $where . ' ORDER BY occurred_at DESC, id DESC LIMIT ' . $pg['per_page'] . ' OFFSET ' . $pg['offset'];
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
        'total' => $total,
        'page' => $pg['page'],
        'per_page' => $pg['per_page'],
        'logs' => $rows,
    ]);
}

if ($action === 'summary' && $method === 'GET') {
    $deviceId = trim((string) ($_GET['device_id'] ?? 'BOSS-PC-001'));
    $device = monFindDevice($pdo, $deviceId);
    if ($device === null) {
        $first = $pdo->query('SELECT * FROM monitored_devices ORDER BY id ASC LIMIT 1')->fetch();
        $device = is_array($first) ? $first : null;
        $deviceId = $device ? (string) $device['device_id'] : $deviceId;
    }
    $todayStart = gmdate('Y-m-d') . ' 00:00:00';
    try {
        $searchesToday = monScalar($pdo, 'SELECT COUNT(*) FROM remote_searches WHERE device_id = ? AND searched_at >= ?', [$deviceId, $todayStart]);
        $resultsToday = monScalar($pdo, 'SELECT COALESCE(SUM(results_count),0) FROM remote_searches WHERE device_id = ? AND searched_at >= ?', [$deviceId, $todayStart]);
        $downloadsToday = monScalar($pdo, 'SELECT COUNT(*) FROM remote_downloads WHERE device_id = ? AND downloaded_at >= ?', [$deviceId, $todayStart]);
        $apiToday = monScalar($pdo, 'SELECT COUNT(*) FROM remote_activity_logs WHERE device_id = ? AND occurred_at >= ?', [$deviceId, $todayStart]);
        $failedToday = monScalar($pdo, "SELECT COUNT(*) FROM remote_searches WHERE device_id = ? AND searched_at >= ? AND status = 'failed'", [$deviceId, $todayStart]);
        $smsToday = monScalar($pdo, "SELECT COUNT(*) FROM sms_alert_logs WHERE device_id = ? AND created_at >= ? AND delivery_status = 'sent'", [$deviceId, $todayStart]);
        $lastAct = $pdo->prepare(
            'SELECT MAX(occurred_at) FROM remote_activity_logs WHERE device_id = ?'
        );
        $lastAct->execute([$deviceId]);
        $lastActivityLog = $lastAct->fetchColumn() ?: null;
    } catch (Throwable $e) {
        monSafeError('Failed to load summary.', $e);
    }

    $pub = $device ? monPublicDeviceRow($device) : null;
    monJson(200, [
        'ok' => true,
        'success' => true,
        'device_id' => $deviceId,
        'device' => $pub,
        'system_status' => $pub['control_mode'] ?? 'active',
        'license_status' => $pub['status'] ?? null,
        'presence' => $pub['presence'] ?? 'offline',
        'is_online' => $pub['is_online'] ?? false,
        'searches_today' => $searchesToday,
        'results_today' => $resultsToday,
        'downloads_today' => $downloadsToday,
        'api_usage_today' => $apiToday,
        'failed_searches_today' => $failedToday,
        'sms_sent_today' => $smsToday,
        'last_activity' => $lastActivityLog ?: ($pub['last_seen_at'] ?? null),
        'license_expires_at' => $pub['license_expires_at'] ?? null,
        'last_sync_at' => $pub['last_sync_at'] ?? null,
        'last_command_received_at' => $pub['last_command_received_at'] ?? null,
        'last_command_applied_at' => $pub['last_command_applied_at'] ?? null,
        'pending_sync' => empty($pub['last_sync_at']),
        'csrf' => monCsrfToken(),
    ]);
}

if ($action === 'searches' && $method === 'GET') {
    $deviceId = trim((string) ($_GET['device_id'] ?? ''));
    $username = trim((string) ($_GET['username'] ?? ''));
    $term = trim((string) ($_GET['search_term'] ?? ''));
    $status = trim((string) ($_GET['status'] ?? ''));
    $from = trim((string) ($_GET['from'] ?? ''));
    $to = trim((string) ($_GET['to'] ?? ''));
    $pg = monPagination(25, 100);

    $where = ' WHERE 1=1';
    $params = [];
    if ($deviceId !== '') {
        $where .= ' AND device_id = ?';
        $params[] = $deviceId;
    }
    if ($username !== '') {
        $where .= ' AND username LIKE ?';
        $params[] = '%' . $username . '%';
    }
    if ($term !== '') {
        $where .= ' AND search_term LIKE ?';
        $params[] = '%' . $term . '%';
    }
    if ($status !== '' && in_array($status, ['started', 'completed', 'failed'], true)) {
        $where .= ' AND status = ?';
        $params[] = $status;
    }
    if ($from !== '') {
        $where .= ' AND searched_at >= ?';
        $params[] = monDateBound($from, false);
    }
    if ($to !== '') {
        $where .= ' AND searched_at <= ?';
        $params[] = monDateBound($to, true);
    }

    try {
        $total = monScalar($pdo, 'SELECT COUNT(*) FROM remote_searches' . $where, $params);
        $sql = 'SELECT id, device_id, local_search_id, user_id, username, search_term, search_type,
                       filters_json, results_count, status, error_message, searched_at, received_at
                FROM remote_searches' . $where . ' ORDER BY searched_at DESC, id DESC LIMIT '
            . $pg['per_page'] . ' OFFSET ' . $pg['offset'];
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        monSafeError('Failed to load searches.', $e);
    }

    monJson(200, [
        'ok' => true,
        'success' => true,
        'total' => $total,
        'page' => $pg['page'],
        'per_page' => $pg['per_page'],
        'searches' => $rows,
    ]);
}

if ($action === 'search-results' && $method === 'GET') {
    $deviceId = trim((string) ($_GET['device_id'] ?? ''));
    $localSearchId = trim((string) ($_GET['local_search_id'] ?? ''));
    $remoteId = (int) ($_GET['id'] ?? 0);
    if ($deviceId === '' && $remoteId < 1) {
        monJson(422, ['ok' => false, 'success' => false, 'message' => 'device_id and local_search_id required.']);
    }
    try {
        if ($remoteId > 0 && ($deviceId === '' || $localSearchId === '')) {
            $s = $pdo->prepare('SELECT device_id, local_search_id FROM remote_searches WHERE id = ? LIMIT 1');
            $s->execute([$remoteId]);
            $found = $s->fetch();
            if (!$found) {
                monJson(404, ['ok' => false, 'success' => false, 'message' => 'Search not found.']);
            }
            $deviceId = (string) $found['device_id'];
            $localSearchId = (string) $found['local_search_id'];
        }
        $stmt = $pdo->prepare(
            'SELECT id, device_id, local_search_id, local_result_id, result_type, title, username, full_name,
                    phone, email, location, profile_url, website_url, description, result_data_json, created_at, received_at
             FROM remote_search_results
             WHERE device_id = ? AND local_search_id = ?
             ORDER BY local_result_id ASC, id ASC
             LIMIT 2000'
        );
        $stmt->execute([$deviceId, (int) $localSearchId]);
        $rows = $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        monSafeError('Failed to load search results.', $e);
    }
    monJson(200, [
        'ok' => true,
        'success' => true,
        'device_id' => $deviceId,
        'local_search_id' => (int) $localSearchId,
        'count' => count($rows),
        'results' => $rows,
    ]);
}

if ($action === 'downloads' && $method === 'GET') {
    $deviceId = trim((string) ($_GET['device_id'] ?? ''));
    $username = trim((string) ($_GET['username'] ?? ''));
    $fileType = trim((string) ($_GET['file_type'] ?? ''));
    $from = trim((string) ($_GET['from'] ?? ''));
    $to = trim((string) ($_GET['to'] ?? ''));
    $pg = monPagination(25, 100);
    $where = ' WHERE 1=1';
    $params = [];
    if ($deviceId !== '') {
        $where .= ' AND device_id = ?';
        $params[] = $deviceId;
    }
    if ($username !== '') {
        $where .= ' AND username LIKE ?';
        $params[] = '%' . $username . '%';
    }
    if ($fileType !== '') {
        $where .= ' AND file_type = ?';
        $params[] = $fileType;
    }
    if ($from !== '') {
        $where .= ' AND downloaded_at >= ?';
        $params[] = monDateBound($from, false);
    }
    if ($to !== '') {
        $where .= ' AND downloaded_at <= ?';
        $params[] = monDateBound($to, true);
    }
    try {
        $total = monScalar($pdo, 'SELECT COUNT(*) FROM remote_downloads' . $where, $params);
        $sql = 'SELECT id, device_id, local_download_id, user_id, username, search_id, file_name, file_type,
                       file_size, rows_count, download_type, source_description, downloaded_at, received_at
                FROM remote_downloads' . $where . ' ORDER BY downloaded_at DESC, id DESC LIMIT '
            . $pg['per_page'] . ' OFFSET ' . $pg['offset'];
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        monSafeError('Failed to load downloads.', $e);
    }
    monJson(200, [
        'ok' => true,
        'success' => true,
        'total' => $total,
        'page' => $pg['page'],
        'per_page' => $pg['per_page'],
        'downloads' => $rows,
    ]);
}

if ($action === 'download' && $method === 'GET') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id < 1) {
        monJson(422, ['ok' => false, 'success' => false, 'message' => 'id required.']);
    }
    try {
        $stmt = $pdo->prepare('SELECT * FROM remote_downloads WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        monSafeError('Failed to load download.', $e);
    }
    if (!$row) {
        monJson(404, ['ok' => false, 'success' => false, 'message' => 'Download not found.']);
    }
    $records = null;
    if (!empty($row['downloaded_records_json'])) {
        $decoded = json_decode((string) $row['downloaded_records_json'], true);
        $records = is_array($decoded) ? $decoded : null;
    }
    unset($row['downloaded_records_json']);
    $row['records'] = $records['records'] ?? $records;
    monJson(200, ['ok' => true, 'success' => true, 'download' => $row]);
}

if ($action === 'sms-logs' && $method === 'GET') {
    $deviceId = trim((string) ($_GET['device_id'] ?? ''));
    $status = trim((string) ($_GET['delivery_status'] ?? ''));
    $from = trim((string) ($_GET['from'] ?? ''));
    $to = trim((string) ($_GET['to'] ?? ''));
    $pg = monPagination(25, 100);
    $where = ' WHERE 1=1';
    $params = [];
    if ($deviceId !== '') {
        $where .= ' AND device_id = ?';
        $params[] = $deviceId;
    }
    if ($status !== '') {
        $where .= ' AND delivery_status = ?';
        $params[] = $status;
    }
    if ($from !== '') {
        $where .= ' AND created_at >= ?';
        $params[] = monDateBound($from, false);
    }
    if ($to !== '') {
        $where .= ' AND created_at <= ?';
        $params[] = monDateBound($to, true);
    }
    try {
        $total = monScalar($pdo, 'SELECT COUNT(*) FROM sms_alert_logs' . $where, $params);
        $sql = 'SELECT id, device_id, remote_search_id, recipient, message, provider_message_id,
                       delivery_status, error_message, created_at, sent_at
                FROM sms_alert_logs' . $where . ' ORDER BY created_at DESC, id DESC LIMIT '
            . $pg['per_page'] . ' OFFSET ' . $pg['offset'];
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        monSafeError('Failed to load SMS logs.', $e);
    }
    monJson(200, [
        'ok' => true,
        'success' => true,
        'total' => $total,
        'page' => $pg['page'],
        'per_page' => $pg['per_page'],
        'logs' => $rows,
    ]);
}

if ($action === 'sms-settings' && $method === 'GET') {
    $cfg = monSmsConfig();
    $row = monSmsSettings($pdo);
    monJson(200, [
        'ok' => true,
        'success' => true,
        'settings' => [
            'enabled' => !empty($row['enabled']),
            'recipient' => (string) ($row['recipient'] ?? '+255622045972'),
            'mode' => (string) ($row['mode'] ?? 'per_search'),
            'last_summary_sent_at' => $row['last_summary_sent_at'] ?? null,
            'provider' => $cfg['provider'],
            'api_configured' => $cfg['apiKey'] !== '',
        ],
        'csrf' => monCsrfToken(),
    ]);
}

if ($action === 'sms-settings' && $method === 'POST') {
    $input = monReadJsonBody();
    monRequireCsrf(isset($input['csrf']) ? (string) $input['csrf'] : null);
    monEnsureSmsSettings($pdo);
    $enabled = !empty($input['enabled']);
    $recipient = monNormalizePhone(trim((string) ($input['recipient'] ?? '+255622045972')));
    $mode = strtolower(trim((string) ($input['mode'] ?? 'per_search')));
    if (!in_array($mode, ['per_search', 'summary_5', 'summary_10'], true)) {
        $mode = 'per_search';
    }
    try {
        $pdo->prepare(
            'UPDATE monitoring_sms_settings SET enabled = ?, recipient = ?, mode = ?, updated_at = ? WHERE id = 1'
        )->execute([$enabled ? 1 : 0, $recipient, $mode, gmdate('Y-m-d H:i:s')]);
    } catch (Throwable $e) {
        monSafeError('Failed to save SMS settings.', $e);
    }
    monJson(200, [
        'ok' => true,
        'success' => true,
        'message' => 'SMS settings saved.',
        'csrf' => monCsrfToken(),
    ]);
}

if ($action === 'audit' && $method === 'GET') {
    $deviceId = trim((string) ($_GET['device_id'] ?? ''));
    $pg = monPagination(25, 100);
    $where = ' WHERE 1=1';
    $params = [];
    if ($deviceId !== '') {
        $where .= ' AND device_id = ?';
        $params[] = $deviceId;
    }
    try {
        $total = monScalar($pdo, 'SELECT COUNT(*) FROM device_control_audit' . $where, $params);
        $sql = 'SELECT id, device_id, admin_user_id, field_name, old_status, new_status, old_value, new_value, description, created_at
                FROM device_control_audit' . $where . ' ORDER BY created_at DESC, id DESC LIMIT '
            . $pg['per_page'] . ' OFFSET ' . $pg['offset'];
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        monSafeError('Failed to load audit.', $e);
    }
    monJson(200, [
        'ok' => true,
        'success' => true,
        'total' => $total,
        'page' => $pg['page'],
        'per_page' => $pg['per_page'],
        'audit' => $rows,
    ]);
}

if ($action === 'export-searches' && $method === 'GET') {
    $deviceId = trim((string) ($_GET['device_id'] ?? ''));
    $username = trim((string) ($_GET['username'] ?? ''));
    $term = trim((string) ($_GET['search_term'] ?? ''));
    $status = trim((string) ($_GET['status'] ?? ''));
    $from = trim((string) ($_GET['from'] ?? ''));
    $to = trim((string) ($_GET['to'] ?? ''));
    $where = ' WHERE 1=1';
    $params = [];
    if ($deviceId !== '') {
        $where .= ' AND device_id = ?';
        $params[] = $deviceId;
    }
    if ($username !== '') {
        $where .= ' AND username LIKE ?';
        $params[] = '%' . $username . '%';
    }
    if ($term !== '') {
        $where .= ' AND search_term LIKE ?';
        $params[] = '%' . $term . '%';
    }
    if ($status !== '' && in_array($status, ['started', 'completed', 'failed'], true)) {
        $where .= ' AND status = ?';
        $params[] = $status;
    }
    if ($from !== '') {
        $where .= ' AND searched_at >= ?';
        $params[] = monDateBound($from, false);
    }
    if ($to !== '') {
        $where .= ' AND searched_at <= ?';
        $params[] = monDateBound($to, true);
    }
    try {
        $stmt = $pdo->prepare(
            'SELECT device_id, local_search_id, username, search_term, search_type, results_count, status, searched_at
             FROM remote_searches' . $where . ' ORDER BY searched_at DESC, id DESC LIMIT 5000'
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        monSafeError('Failed to export searches.', $e);
    }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="monitoring-searches.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['device_id', 'local_search_id', 'username', 'search_term', 'search_type', 'results_count', 'status', 'searched_at']);
    foreach ($rows as $row) {
        fputcsv($out, [
            $row['device_id'],
            $row['local_search_id'],
            $row['username'],
            $row['search_term'],
            $row['search_type'],
            $row['results_count'],
            $row['status'],
            $row['searched_at'],
        ]);
    }
    fclose($out);
    exit;
}

if ($action === 'errors' && $method === 'GET') {
    $deviceId = trim((string) ($_GET['device_id'] ?? ''));
    $pg = monPagination(25, 100);
    $where = " WHERE (status = 'failed' OR error_message IS NOT NULL)";
    $params = [];
    if ($deviceId !== '') {
        $where .= ' AND device_id = ?';
        $params[] = $deviceId;
    }
    try {
        $total = monScalar($pdo, 'SELECT COUNT(*) FROM remote_searches' . $where, $params);
        $sql = 'SELECT id, device_id, local_search_id, username, search_term, status, error_message, searched_at
                FROM remote_searches' . $where . ' ORDER BY searched_at DESC, id DESC LIMIT '
            . $pg['per_page'] . ' OFFSET ' . $pg['offset'];
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        monSafeError('Failed to load errors.', $e);
    }
    monJson(200, [
        'ok' => true,
        'success' => true,
        'total' => $total,
        'page' => $pg['page'],
        'per_page' => $pg['per_page'],
        'errors' => $rows,
    ]);
}

monJson(400, ['ok' => false, 'success' => false, 'message' => 'Unknown action.']);
