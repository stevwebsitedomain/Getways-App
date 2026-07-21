<?php

declare(strict_types=1);

/**
 * Shared session + writable runtime for auth (localhost + production).
 */
function gwAuthStartSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');

    $base = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/')));
    if ($base === '/' || $base === '.' || $base === '') {
        $cookiePath = '/';
    } else {
        $cookiePath = rtrim($base, '/') . '/';
    }

    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $cookiePath,
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } else {
        session_set_cookie_params(0, $cookiePath, '', $isHttps, true);
    }

    $savePath = __DIR__ . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'sessions';
    if (!is_dir($savePath)) {
        @mkdir($savePath, 0775, true);
    }
    if (is_dir($savePath) && is_writable($savePath)) {
        session_save_path($savePath);
    }

    session_start();
}

function gwAuthRuntimeDir(): string
{
    $dir = __DIR__ . DIRECTORY_SEPARATOR . 'runtime';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

function gwAuthBgImageUrl(): string
{
    foreach (['login-bg.jpg', 'images/login.jpg', 'images/get2.jpg'] as $rel) {
        if (is_file(__DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel))) {
            return $rel;
        }
    }
    return 'images/login.jpg';
}
