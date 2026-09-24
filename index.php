<?php

declare(strict_types=1);

/**
 * Project root entry. Sends visitors to the public start page (login).
 */
$target = 'frontend/web/login.php';
$query = (string) ($_SERVER['QUERY_STRING'] ?? '');
if ($query !== '') {
    $target .= '?' . $query;
}

header('Location: ' . $target, true, 302);
header('Cache-Control: no-store');
exit;
