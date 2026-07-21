<?php

declare(strict_types=1);

require_once __DIR__ . '/auth-init.php';
gwAuthStartSession();
gwAuthRuntimeDir();

$loggedIn = isset($_SESSION['gw_auth_user']) && is_array($_SESSION['gw_auth_user']);
if (!$loggedIn) {
    $target = urlencode((string) ($_SERVER['REQUEST_URI'] ?? 'part-two.php'));
    header('Location: login.php?next=' . $target);
    exit;
}
