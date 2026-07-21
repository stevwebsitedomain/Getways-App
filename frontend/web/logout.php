<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

unset($_SESSION['gw_auth_user'], $_SESSION['gw_pending']);
session_regenerate_id(true);

header('Location: login.php');
exit;
