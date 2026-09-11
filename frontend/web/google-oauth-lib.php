<?php

declare(strict_types=1);

function gwRequestIsHttps(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

function gwGoogleOrigin(): string
{
    $scheme = gwRequestIsHttps() ? 'https' : 'http';
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));

    return $scheme . '://' . $host;
}

function gwGoogleWebDir(): string
{
    $dir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/')));
    $dir = rtrim($dir, '/');
    if ($dir === '/' || $dir === '\\' || $dir === '.' || $dir === '') {
        return '';
    }

    return $dir;
}

function gwGoogleCallbackUrl(): string
{
    return gwGoogleOrigin() . gwGoogleWebDir() . '/google-callback.php';
}
