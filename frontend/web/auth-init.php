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

    // Always use site root so auth-api.php and admin-dashboard.php share the same cookie.
    $cookiePath = '/';

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

    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
}

/**
 * @param array<string, mixed> $user
 * @return array<string, string>
 */
function gwAuthSessionUser(array $user): array
{
    return [
        'id' => (string) ($user['id'] ?? ''),
        'fullName' => (string) ($user['fullName'] ?? 'Customer'),
        'phone' => (string) ($user['phone'] ?? ''),
        'email' => (string) ($user['email'] ?? ''),
        'username' => (string) ($user['username'] ?? ''),
        'role' => (string) ($user['role'] ?? 'user'),
        'provider' => (string) ($user['provider'] ?? 'password'),
        'avatar' => (string) ($user['avatar'] ?? ''),
    ];
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
