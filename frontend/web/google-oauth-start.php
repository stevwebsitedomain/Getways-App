<?php

declare(strict_types=1);

require_once __DIR__ . '/env-load.php';
require_once __DIR__ . '/auth-init.php';
require_once __DIR__ . '/google-oauth-lib.php';

gwLoadEnv();
header('Cross-Origin-Opener-Policy: same-origin-allow-popups');
gwAuthStartSession();

$clientId = trim((string) (getenv('GOOGLE_CLIENT_ID') ?: ''));
if ($clientId === '') {
    header('Location: login.php?google=missing');
    exit;
}

$nonce = bin2hex(random_bytes(16));
$wantedRole = strtolower(trim((string) ($_GET['role'] ?? 'user')));
if ($wantedRole !== 'admin') {
    $wantedRole = 'user';
}
$_SESSION['gw_google_nonce'] = $nonce;
$_SESSION['gw_google_role'] = $wantedRole;
$_SESSION['gw_google_next'] = trim((string) ($_GET['next'] ?? ($_SESSION['gw_google_next'] ?? '')));

$auth = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
    'client_id' => $clientId,
    'redirect_uri' => gwGoogleCallbackUrl(),
    'response_type' => 'id_token',
    'scope' => 'openid email profile',
    'nonce' => $nonce,
    'prompt' => 'select_account',
], '', '&', PHP_QUERY_RFC3986);

header('Location: ' . $auth, true, 302);
exit;
