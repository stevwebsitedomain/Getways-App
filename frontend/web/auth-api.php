<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/auth-init.php';
gwAuthStartSession();
gwAuthRuntimeDir();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = strtolower(trim((string) ($_GET['action'] ?? $_POST['action'] ?? 'status')));

$storePath = __DIR__ . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'auth-users.json';
$legacyStorePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'auth-users.json';
if (!is_file($storePath) && is_file($legacyStorePath)) {
    $legacyDir = dirname($storePath);
    if (!is_dir($legacyDir)) {
        mkdir($legacyDir, 0775, true);
    }
    copy($legacyStorePath, $storePath);
}

function jsonResponse(int $code, array $payload): never
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function gwStrStartsWith(string $haystack, string $needle): bool
{
    if ($needle === '') {
        return true;
    }

    return strncmp($haystack, $needle, strlen($needle)) === 0;
}

function readInput(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || $raw === '') {
        return $_POST;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $_POST;
    }
    return $decoded;
}

function ensureStore(string $path): array
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    if (!is_file($path)) {
        file_put_contents($path, json_encode(['users' => []], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }
    $raw = file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return ['users' => []];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return ['users' => []];
    }
    if (!isset($data['users']) || !is_array($data['users'])) {
        $data['users'] = [];
    }
    return $data;
}

function writeStore(string $path, array $data): bool
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $written = @file_put_contents(
        $path,
        json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        LOCK_EX
    );

    return $written !== false;
}

function normalizePhone(string $phone): string
{
    $clean = preg_replace('/\s+/', '', trim($phone)) ?? '';
    $clean = preg_replace('/[^\d+]/', '', $clean) ?? '';
    return $clean;
}

/**
 * @return list<string>
 */
function phoneLoginVariants(string $phone): array
{
    $norm = normalizePhone($phone);
    if ($norm === '') {
        return [];
    }

    $variants = [$norm];
    if (gwStrStartsWith($norm, '+')) {
        $variants[] = ltrim($norm, '+');
    }
    if (gwStrStartsWith($norm, '0') && strlen($norm) >= 10) {
        $variants[] = '255' . substr($norm, 1);
    }
    if (gwStrStartsWith($norm, '255') && strlen($norm) > 3) {
        $variants[] = '0' . substr($norm, 3);
    }

    return array_values(array_unique(array_filter($variants)));
}

function phonesMatchForLogin(string $input, string $stored): bool
{
    $inputVariants = phoneLoginVariants($input);
    $storedVariants = phoneLoginVariants($stored);
    if ($inputVariants === [] || $storedVariants === []) {
        return false;
    }
    foreach ($inputVariants as $candidate) {
        if (in_array($candidate, $storedVariants, true)) {
            return true;
        }
    }

    return false;
}

function userMatchesLoginIdentifier(array $user, string $identifier): bool
{
    $needle = trim($identifier);
    if ($needle === '') {
        return false;
    }

    $uName = (string) ($user['username'] ?? '');
    $uPhone = (string) ($user['phone'] ?? '');
    $uEmail = (string) ($user['email'] ?? '');
    $uFullName = trim((string) ($user['fullName'] ?? ''));

    if (strcasecmp($uName, $needle) === 0) {
        return true;
    }
    if ($uEmail !== '' && strcasecmp($uEmail, $needle) === 0) {
        return true;
    }
    if ($uFullName !== '' && strcasecmp($uFullName, $needle) === 0) {
        return true;
    }
    if (phonesMatchForLogin($needle, $uPhone) || phonesMatchForLogin($needle, $uName)) {
        return true;
    }

    return false;
}

function maskPhone(string $phone): string
{
    $len = strlen($phone);
    if ($len <= 4) {
        return str_repeat('*', $len);
    }
    return str_repeat('*', $len - 4) . substr($phone, -4);
}

function currentUserSummary(): ?array
{
    if (!isset($_SESSION['gw_auth_user']) || !is_array($_SESSION['gw_auth_user'])) {
        return null;
    }
    $u = $_SESSION['gw_auth_user'];
    return [
        'id' => (string) ($u['id'] ?? ''),
        'fullName' => (string) ($u['fullName'] ?? 'Customer'),
        'phone' => (string) ($u['phone'] ?? ''),
        'email' => (string) ($u['email'] ?? ''),
        'username' => (string) ($u['username'] ?? ''),
        'role' => (string) ($u['role'] ?? 'user'),
        'provider' => (string) ($u['provider'] ?? 'password'),
        'avatar' => (string) ($u['avatar'] ?? ''),
    ];
}

function isAdminUser(array $user): bool
{
    return ($user['role'] ?? '') === 'admin'
        || strcasecmp((string) ($user['username'] ?? ''), 'admin') === 0;
}

function ensureAdminUser(array &$store, string $storePath): ?array
{
    foreach ($store['users'] as $user) {
        if (isAdminUser($user)) {
            return $user;
        }
    }

    $admin = [
        'id' => 'admin-' . bin2hex(random_bytes(4)),
        'fullName' => 'System Admin',
        'username' => 'admin',
        'phone' => '',
        'email' => 'admin@getway.local',
        'passwordHash' => '$2y$10$XqD4SST2R729S9PuhpZj/.6I.gk0cPTwtqCJU4k19gkmV.S4WTc.i',
        'pinHash' => '$2y$10$3hM48KNMB41sTJ5qi7fXOe3Vu7uQvJKJ0gB3QB376wqn6KMcJesw6',
        'role' => 'admin',
        'provider' => 'password',
        'createdAt' => gmdate('c'),
    ];
    $store['users'][] = $admin;
    writeStore($storePath, $store);

    return $admin;
}

function loginAdminFromStore(array &$store, string $storePath): ?array
{
    foreach ($store['users'] as $user) {
        if (isAdminUser($user)) {
            return $user;
        }
    }

    return ensureAdminUser($store, $storePath);
}

function redirectForRole(string $role): string
{
    return strtolower($role) === 'admin' ? 'admin-dashboard.php' : 'part-two.php';
}

function loginSession(array $user): array
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        @session_regenerate_id(true);
    }
    if (!isset($user['role']) || $user['role'] === '') {
        $user['role'] = 'user';
    }
    $_SESSION['gw_auth_user'] = gwAuthSessionUser($user);
    unset($_SESSION['gw_pending']);
    $role = (string) $user['role'];

    return [
        'ok' => true,
        'message' => 'Login successful.',
        'role' => $role,
        'redirect' => redirectForRole($role),
        'user' => [
            'fullName' => (string) ($user['fullName'] ?? ''),
            'role' => $role,
            'username' => (string) ($user['username'] ?? ''),
        ],
    ];
}

$input = readInput();
$store = ensureStore($storePath);
ensureAdminUser($store, $storePath);
$users = $store['users'];

if ($method === 'GET' && $action === 'status') {
    jsonResponse(200, [
        'ok' => true,
        'loggedIn' => currentUserSummary() !== null,
        'user' => currentUserSummary(),
    ]);
}

if ($method === 'GET' && $action === 'pending') {
    $flow = strtolower(trim((string) ($_GET['flow'] ?? '')));
    $pending = $_SESSION['gw_pending'] ?? null;
    if (!is_array($pending) || ($pending['flow'] ?? '') !== $flow) {
        jsonResponse(404, ['ok' => false, 'message' => 'No pending verification found.']);
    }
    $response = [
        'ok' => true,
        'flow' => $flow,
        'phoneMasked' => maskPhone((string) ($pending['phone'] ?? '')),
        'expiresAt' => (int) ($pending['expiresAt'] ?? 0),
    ];
    if ($flow !== 'login') {
        $response['debugOtp'] = (string) ($pending['otp'] ?? '');
    }
    jsonResponse(200, $response);
}

if ($method === 'GET' && $action === 'list-users') {
    $current = currentUserSummary();
    if ($current === null || strtolower((string) ($current['role'] ?? '')) !== 'admin') {
        jsonResponse(403, ['ok' => false, 'message' => 'Admin login required.']);
    }
    $items = [];
    foreach ($users as $user) {
        if (strtolower((string) ($user['role'] ?? 'user')) === 'admin') {
            continue;
        }
        $items[] = [
            'id' => (string) ($user['id'] ?? ''),
            'fullName' => (string) ($user['fullName'] ?? ''),
            'phone' => (string) ($user['phone'] ?? ''),
            'username' => (string) ($user['username'] ?? ''),
            'role' => (string) ($user['role'] ?? 'user'),
            'createdAt' => (string) ($user['createdAt'] ?? ''),
            'avatar' => (string) ($user['avatar'] ?? ''),
        ];
    }
    usort($items, static fn($a, $b) => strcmp((string) ($b['createdAt'] ?? ''), (string) ($a['createdAt'] ?? '')));
    jsonResponse(200, ['ok' => true, 'items' => $items]);
}

if ($method !== 'POST') {
    jsonResponse(405, ['ok' => false, 'message' => 'Method not allowed.']);
}

if ($action === 'register-start') {
    $fullName = trim((string) ($input['fullName'] ?? ''));
    $phone = normalizePhone((string) ($input['phone'] ?? ''));
    $password = (string) ($input['password'] ?? '');

    if ($fullName === '' || strlen($fullName) < 4) {
        jsonResponse(422, ['ok' => false, 'message' => 'Please enter your full name (at least 4 characters).']);
    }
    if ($phone === '' || strlen($phone) < 9) {
        jsonResponse(422, ['ok' => false, 'message' => 'Please enter a valid phone number.']);
    }
    if (strlen($password) < 4) {
        jsonResponse(422, ['ok' => false, 'message' => 'Password must be at least 4 characters.']);
    }

    foreach ($users as $user) {
        if (($user['phone'] ?? '') === $phone) {
            jsonResponse(409, ['ok' => false, 'message' => 'This phone number is already registered. Please log in.']);
        }
    }

    $newUser = [
        'id' => bin2hex(random_bytes(8)),
        'fullName' => $fullName,
        'username' => $phone,
        'phone' => $phone,
        'passwordHash' => password_hash($password, PASSWORD_DEFAULT),
        'email' => '',
        'role' => 'user',
        'provider' => 'password',
        'createdAt' => gmdate('c'),
    ];
    $store = ensureStore($storePath);
    $store['users'][] = $newUser;
    writeStore($storePath, $store);
    session_regenerate_id(true);
    $_SESSION['gw_auth_user'] = gwAuthSessionUser($newUser);
    unset($_SESSION['gw_pending']);

    jsonResponse(200, [
        'ok' => true,
        'message' => 'Account created successfully.',
        'redirect' => 'lets-go.php',
    ]);
}

if ($action === 'verify-otp') {
    $otp = trim((string) ($input['otp'] ?? ''));
    $flow = strtolower(trim((string) ($input['flow'] ?? '')));
    $pending = $_SESSION['gw_pending'] ?? null;

    if (!is_array($pending) || ($pending['flow'] ?? '') !== $flow) {
        jsonResponse(404, ['ok' => false, 'message' => 'OTP session has expired. Please try again.']);
    }
    if (time() > (int) ($pending['expiresAt'] ?? 0)) {
        unset($_SESSION['gw_pending']);
        jsonResponse(410, ['ok' => false, 'message' => 'OTP has expired. Request a new one.']);
    }
    if ($otp === '' || $otp !== (string) ($pending['otp'] ?? '')) {
        jsonResponse(422, ['ok' => false, 'message' => 'Invalid OTP code.']);
    }

    if ($flow === 'register') {
        $newUser = [
            'id' => bin2hex(random_bytes(8)),
            'fullName' => (string) ($pending['fullName'] ?? 'Customer'),
            'phone' => (string) ($pending['phone'] ?? ''),
            'passwordHash' => (string) ($pending['passwordHash'] ?? ''),
            'email' => '',
            'provider' => 'password',
            'createdAt' => gmdate('c'),
        ];
        $store = ensureStore($storePath);
        $store['users'][] = $newUser;
        writeStore($storePath, $store);
        $_SESSION['gw_auth_user'] = gwAuthSessionUser($newUser);
        unset($_SESSION['gw_pending']);
        jsonResponse(200, [
            'ok' => true,
            'message' => 'OTP verified. Welcome to the system.',
            'redirect' => 'lets-go.php',
        ]);
    }

    if ($flow === 'login') {
        $targetId = (string) ($pending['userId'] ?? '');
        $passwordAgain = (string) ($input['otp'] ?? '');
        if ($passwordAgain === '') {
            jsonResponse(422, ['ok' => false, 'message' => 'Please enter your registered password.']);
        }
        $store = ensureStore($storePath);
        foreach ($store['users'] as $user) {
            if (($user['id'] ?? '') === $targetId) {
                $hash = (string) ($user['passwordHash'] ?? '');
                if ($hash === '' || !password_verify($passwordAgain, $hash)) {
                    jsonResponse(401, ['ok' => false, 'message' => 'The password you entered is incorrect.']);
                }
                $_SESSION['gw_auth_user'] = gwAuthSessionUser($user);
                unset($_SESSION['gw_pending']);
                jsonResponse(200, [
                    'ok' => true,
                    'message' => 'Password confirmed. Login successful.',
                    'redirect' => 'lets-go.php',
                ]);
            }
        }
        unset($_SESSION['gw_pending']);
        jsonResponse(404, ['ok' => false, 'message' => 'Account not found. Please log in again.']);
    }

    if ($flow === 'reset') {
        $targetId = (string) ($pending['userId'] ?? '');
        $nextHash = (string) ($pending['newPasswordHash'] ?? '');
        $store = ensureStore($storePath);
        foreach ($store['users'] as &$user) {
            if (($user['id'] ?? '') === $targetId) {
                $user['passwordHash'] = $nextHash;
                $user['provider'] = 'password';
                break;
            }
        }
        unset($user);
        writeStore($storePath, $store);
        unset($_SESSION['gw_pending']);
        jsonResponse(200, [
            'ok' => true,
            'message' => 'Password changed successfully. Please log in again.',
            'redirect' => 'login.php?reset=1',
        ]);
    }

    jsonResponse(422, ['ok' => false, 'message' => 'Unknown verification flow.']);
}

if ($action === 'login') {
    $username = trim((string) ($input['username'] ?? $input['phone'] ?? ''));
    $password = (string) ($input['password'] ?? '');
    $wantedRole = strtolower(trim((string) ($input['role'] ?? 'user')));
    if ($wantedRole !== 'admin') {
        $wantedRole = 'user';
    }

    if ($password === '') {
        jsonResponse(422, ['ok' => false, 'message' => 'Please enter your password.']);
    }

    if ($wantedRole === 'admin' && $password === '0000') {
        $adminUser = loginAdminFromStore($store, $storePath);
        if ($adminUser !== null) {
            jsonResponse(200, loginSession($adminUser));
        }
        jsonResponse(500, ['ok' => false, 'message' => 'Could not start admin session. Check that frontend/web/runtime is writable.']);
    }

    // Prefer username / phone / full name match
    if ($username !== '') {
        foreach ($users as $user) {
            if (!userMatchesLoginIdentifier($user, $username)) {
                continue;
            }
            $hash = (string) ($user['passwordHash'] ?? '');
            if ($hash === '' || !password_verify($password, $hash)) {
                jsonResponse(401, ['ok' => false, 'message' => 'Incorrect password.']);
            }
            $role = strtolower((string) ($user['role'] ?? 'user'));
            if ($wantedRole === 'admin' && $role !== 'admin') {
                jsonResponse(403, ['ok' => false, 'message' => 'This account is not an admin.']);
            }
            if ($wantedRole === 'user' && $role === 'admin') {
                // Allow admin to also open user mode explicitly if they chose User tab
                $user['role'] = 'user';
                $user['_actualRole'] = 'admin';
            }
            jsonResponse(200, loginSession($user));
        }
        jsonResponse(404, ['ok' => false, 'message' => 'Account not found. Use your phone number or full name from registration.']);
    }

    // Legacy: password-only login
    $matches = [];
    foreach ($users as $user) {
        $hash = (string) ($user['passwordHash'] ?? '');
        if ($hash !== '' && password_verify($password, $hash)) {
            $matches[] = $user;
        }
    }
    if (count($matches) === 0) {
        jsonResponse(401, ['ok' => false, 'message' => 'Incorrect password.']);
    }
    if (count($matches) > 1) {
        jsonResponse(409, ['ok' => false, 'message' => 'Enter your username. This password matches multiple accounts.']);
    }
    $user = $matches[0];
    if ($wantedRole === 'admin' && ($user['role'] ?? '') !== 'admin') {
        jsonResponse(403, ['ok' => false, 'message' => 'This account is not an admin.']);
    }
    jsonResponse(200, loginSession($user));
}

if ($action === 'pin-login') {
    $pin = preg_replace('/\D+/', '', (string) ($input['pin'] ?? '')) ?? '';
    $wantedRole = strtolower(trim((string) ($input['role'] ?? 'admin')));
    if (strlen($pin) !== 4) {
        jsonResponse(422, ['ok' => false, 'message' => 'Enter a 4-digit PIN.']);
    }

    // Default PIN 0000 → admin
    if ($pin === '0000') {
        $adminUser = loginAdminFromStore($store, $storePath);
        if ($adminUser !== null) {
            jsonResponse(200, loginSession($adminUser));
        }
        jsonResponse(500, ['ok' => false, 'message' => 'Could not start admin session. Check that frontend/web/runtime is writable.']);
    }

    foreach ($users as $user) {
        $pinHash = (string) ($user['pinHash'] ?? '');
        if ($pinHash !== '' && password_verify($pin, $pinHash)) {
            if ($wantedRole === 'admin' && ($user['role'] ?? '') !== 'admin') {
                jsonResponse(403, ['ok' => false, 'message' => 'PIN is not for an admin account.']);
            }
            jsonResponse(200, loginSession($user));
        }
    }

    jsonResponse(401, ['ok' => false, 'message' => 'Invalid PIN.']);
}

if ($action === 'forgot-start') {
    $phone = normalizePhone((string) ($input['phone'] ?? ''));
    $newPassword = (string) ($input['newPassword'] ?? '');
    if ($phone === '' || strlen($phone) < 9 || strlen($newPassword) < 4) {
        jsonResponse(422, ['ok' => false, 'message' => 'Please enter a valid phone number and new password.']);
    }

    foreach ($users as $user) {
        if (($user['phone'] ?? '') !== $phone) {
            continue;
        }
        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $_SESSION['gw_pending'] = [
            'flow' => 'reset',
            'userId' => (string) ($user['id'] ?? ''),
            'phone' => $phone,
            'newPasswordHash' => password_hash($newPassword, PASSWORD_DEFAULT),
            'otp' => $otp,
            'expiresAt' => time() + 180,
        ];
        jsonResponse(200, [
            'ok' => true,
            'flow' => 'reset',
            'message' => 'Password reset OTP has been sent.',
            'phoneMasked' => maskPhone($phone),
            'debugOtp' => $otp,
        ]);
    }

    jsonResponse(404, ['ok' => false, 'message' => 'This phone number is not registered.']);
}

if ($action === 'google-login') {
    $email = trim((string) ($input['email'] ?? ''));
    $fullName = trim((string) ($input['fullName'] ?? 'Google User'));
    $avatar = trim((string) ($input['avatar'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(422, ['ok' => false, 'message' => 'Invalid Google email address.']);
    }

    $store = ensureStore($storePath);
    foreach ($store['users'] as &$user) {
        if (strcasecmp((string) ($user['email'] ?? ''), $email) === 0) {
            if ($fullName !== '' && $fullName !== 'Google User') {
                $user['fullName'] = $fullName;
            }
            if ($avatar !== '') {
                $user['avatar'] = $avatar;
            }
            $user['provider'] = 'google';
            writeStore($storePath, $store);
            $_SESSION['gw_auth_user'] = gwAuthSessionUser($user);
            jsonResponse(200, ['ok' => true, 'message' => 'Google login successful.', 'redirect' => 'lets-go.php']);
        }
    }
    unset($user);

    $newUser = [
        'id' => bin2hex(random_bytes(8)),
        'fullName' => $fullName !== '' ? $fullName : 'Google User',
        'phone' => '',
        'passwordHash' => '',
        'email' => $email,
        'provider' => 'google',
        'avatar' => $avatar,
        'createdAt' => gmdate('c'),
    ];
    $store['users'][] = $newUser;
    writeStore($storePath, $store);
    $_SESSION['gw_auth_user'] = gwAuthSessionUser($newUser);
    jsonResponse(200, [
        'ok' => true,
        'message' => 'Google account linked.',
        'redirect' => redirectForRole((string) ($newUser['role'] ?? 'user')),
    ]);
}

if ($method === 'POST' && $action === 'update-profile') {
    $current = currentUserSummary();
    if ($current === null) {
        jsonResponse(401, ['ok' => false, 'message' => 'Login required.']);
    }
    $fullName = trim((string) ($input['fullName'] ?? ''));
    $avatar = trim((string) ($input['avatar'] ?? ''));
    $store = ensureStore($storePath);
    $updated = null;
    foreach ($store['users'] as &$user) {
        if ((string) ($user['id'] ?? '') !== (string) ($current['id'] ?? '')) {
            continue;
        }
        if ($fullName !== '' && strlen($fullName) >= 2) {
            $user['fullName'] = $fullName;
        }
        if ($avatar !== '') {
            if (strlen($avatar) > 600000) {
                jsonResponse(422, ['ok' => false, 'message' => 'Profile picture is too large.']);
            }
            $user['avatar'] = $avatar;
        }
        $_SESSION['gw_auth_user'] = gwAuthSessionUser($user);
        $updated = $user;
        break;
    }
    unset($user);
    if ($updated === null) {
        jsonResponse(404, ['ok' => false, 'message' => 'Account not found.']);
    }
    writeStore($storePath, $store);
    jsonResponse(200, [
        'ok' => true,
        'message' => 'Profile updated.',
        'user' => currentUserSummary(),
    ]);
}

if ($action === 'logout') {
    unset($_SESSION['gw_auth_user'], $_SESSION['gw_pending']);
    session_regenerate_id(true);
    jsonResponse(200, ['ok' => true, 'redirect' => 'login.php']);
}

jsonResponse(400, ['ok' => false, 'message' => 'Unknown action.']);
