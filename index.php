<?php

declare(strict_types=1);

/**
 * Entry file for the domain root.
 * On cPanel, public_html is often frontend/web (login.php sits next to this file).
 * Locally, this file sits in the project root (login.php is under frontend/web).
 */
$target = 'login.php';
if (!is_file(__DIR__ . '/login.php') && is_file(__DIR__ . '/frontend/web/login.php')) {
    $target = 'frontend/web/login.php';
}

$query = (string) ($_SERVER['QUERY_STRING'] ?? '');
if ($query !== '') {
    $target .= '?' . $query;
}

header('Location: ' . $target, true, 302);
header('Cache-Control: no-store');
exit;
