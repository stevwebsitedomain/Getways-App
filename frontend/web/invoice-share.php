<?php

declare(strict_types=1);

function invoiceShareToken(int $id): string
{
    $secret = getenv('RECEIPT_SHARE_SECRET');
    if (!is_string($secret) || trim($secret) === '') {
        $secret = 'getway-receipt-share-v1';
    }

    return substr(hash_hmac('sha256', 'receipt:' . $id, $secret), 0, 20);
}

function invoiceShareUrl(int $id, string $mode = 'user'): string
{
    $page = $mode === 'user' ? 'control-number-invoice.php' : 'admin-invoice.php';

    return $page . '?id=' . $id . '&t=' . rawurlencode(invoiceShareToken($id));
}

function invoiceFullShareUrl(int $id, string $mode = 'user'): string
{
    $relative = invoiceShareUrl($id, $mode);
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $scriptDir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/admin-invoice.php')));

    return rtrim($scheme . '://' . $host . $scriptDir, '/') . '/' . ltrim($relative, '/');
}

function invoiceSharePrintUrl(int $id, string $mode = 'user'): string
{
    return invoiceShareUrl($id, $mode) . '&print=1';
}

function invoiceFullSharePrintUrl(int $id, string $mode = 'user'): string
{
    return invoiceFullShareUrl($id, $mode) . '&print=1';
}

function invoiceShareTokenValid(int $id, string $token): bool
{
    $token = trim($token);
    if ($id <= 0 || $token === '') {
        return false;
    }

    return hash_equals(invoiceShareToken($id), $token);
}
