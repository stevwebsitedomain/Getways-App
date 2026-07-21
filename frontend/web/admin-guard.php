<?php

declare(strict_types=1);

require_once __DIR__ . '/auth-init.php';
gwAuthStartSession();
gwAuthRuntimeDir();

$loggedIn = isset($_SESSION['gw_auth_user']) && is_array($_SESSION['gw_auth_user']);
if (!$loggedIn) {
    header('Location: login.php?next=' . urlencode('admin-dashboard.php'));
    exit;
}

$role = strtolower((string) ($_SESSION['gw_auth_user']['role'] ?? 'user'));
if ($role !== 'admin') {
    header('Location: part-two.php');
    exit;
}
