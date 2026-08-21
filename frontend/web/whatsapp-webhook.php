<?php

declare(strict_types=1);

/**
 * Public Ultramsg webhook receiver.
 * Paste this URL into Ultramsg → Instance settings → Webhook URL.
 *
 * Example: https://getway.legitconsult.co.tz/whatsapp-webhook.php
 */

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/env-load.php';

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET' || $method === 'HEAD') {
    $config = gwUltamsgConfig();
    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'service' => 'Getway Ultramsg webhook',
        'message' => 'Webhook endpoint is live. Use POST from Ultramsg.',
        'webhookUrl' => $config['webhookUrl'],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$payload = [];
if (is_string($raw) && $raw !== '') {
    $decoded = json_decode($raw, true);
    $payload = is_array($decoded) ? $decoded : ['raw' => $raw];
} elseif (!empty($_POST)) {
    $payload = $_POST;
}

$event = [
    'receivedAt' => gmdate('c'),
    'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
    'payload' => $payload,
];

$path = gwWhatsappWebhookLogPath();
@file_put_contents($path, json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);

// Opportunistic tick: send any due scheduled WhatsApp while Ultramsg is talking to us.
$scheduleTick = null;
try {
    require_once __DIR__ . '/whatsapp-schedule-lib.php';
    $scheduleTick = gwWhatsappProcessDueSchedules(10);
} catch (Throwable $e) {
    $scheduleTick = ['error' => $e->getMessage()];
}

http_response_code(200);
echo json_encode([
    'ok' => true,
    'received' => true,
    'schedule' => $scheduleTick,
], JSON_UNESCAPED_SLASHES);
