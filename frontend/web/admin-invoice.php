<?php

declare(strict_types=1);

/**
 * Admin receipt / invoice page.
 * Fetches transaction data from Render admin API (no local MySQL required).
 */

require_once __DIR__ . '/admin-guard.php';

function adminInvoiceUpstream(): string
{
    $fromEnv = getenv('ADMIN_DATA_UPSTREAM');
    if (is_string($fromEnv) && trim($fromEnv) !== '') {
        return rtrim(trim($fromEnv), '/');
    }

    return 'https://getways-app.onrender.com';
}

/**
 * @return array<string,mixed>|null
 */
function adminInvoiceFetchRemote(int $id): ?array
{
    $url = adminInvoiceUpstream() . '/admin/invoice/' . rawurlencode((string) $id);
    $headers = ['Accept: application/json'];

    $token = getenv('ADMIN_API_TOKEN');
    if (is_string($token) && trim($token) !== '') {
        $headers[] = 'X-Admin-Proxy-Token: ' . trim($token);
    }

    $ch = curl_init($url);
    if ($ch === false) {
        return null;
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => $headers,
    ]);

    $raw = curl_exec($ch);
    if ($raw === false) {
        curl_close($ch);
        return null;
    }

    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $decoded = json_decode((string) $raw, true);
    if (!is_array($decoded)) {
        return null;
    }

    if ($status >= 400 || ($decoded['success'] ?? true) === false) {
        return [
            'success' => false,
            'message' => (string) ($decoded['message'] ?? 'Could not load receipt.'),
        ];
    }

    return $decoded;
}

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function moneyLabel(float $amount, string $currency): string
{
    return h($currency) . ' ' . number_format($amount, 0, '.', ',');
}

function buildReceiptQrUrl(array $invoice): string
{
    $id = (int) ($invoice['id'] ?? 0);
    $ref = trim((string) ($invoice['qrReference'] ?? $invoice['billReference'] ?? ''));
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $scriptDir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/admin-invoice.php')));
    $base = rtrim($scheme . '://' . $host . $scriptDir, '/');
    $verifyUrl = $base . '/admin-invoice.php?id=' . $id;
    if ($ref !== '') {
        $verifyUrl .= '&ref=' . rawurlencode($ref);
    }
    $qrData = "GETWAY|REF:{$ref}|AMT:" . (string) ($invoice['amount'] ?? '') . '|URL:' . $verifyUrl;

    return 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&margin=8&ecc=M&data=' . rawurlencode($qrData);
}

/**
 * @param array<string,mixed> $invoice
 */
function buildAdminReceiptHtml(array $invoice, bool $forPdf = false): string
{
    $businessName = 'Getway';
    $businessAddress = 'Dar es Salaam, Tanzania';
    $status = strtoupper((string) ($invoice['status'] ?? 'PENDING'));
    $ok = in_array($status, ['SUCCESS', 'SUCCESSFUL', 'SETTLED', 'COMPLETED', 'PAID'], true);
    $statusClass = $ok ? 'ok' : ($status === 'PENDING' ? 'pending' : 'failed');
    $statusLabel = $ok ? 'PAID' : $status;

    $customerName = h((string) ($invoice['customerName'] ?? 'Mteja'));
    $customerPhone = h((string) ($invoice['customerPhone'] ?? ($invoice['customerPhoneFormatted'] ?? '—')));
    $invoiceNumber = h((string) ($invoice['invoiceNumber'] ?? ''));
    $orderId = h((string) ($invoice['orderId'] ?? '—'));
    $reference = h((string) ($invoice['billReference'] ?? '—'));
    $controlNumber = h((string) ($invoice['controlNumber'] ?? '—'));
    $hasControlNumber = (bool) ($invoice['hasControlNumber'] ?? false);
    $description = h((string) ($invoice['description'] ?? 'Payment'));
    $channel = h((string) ($invoice['channel'] ?? 'clickpesa'));
    $paidAt = h((string) ($invoice['paidAtFormatted'] ?? '—'));
    $createdAt = h((string) ($invoice['createdAtFormatted'] ?? '—'));
    $amount = moneyLabel((float) ($invoice['amount'] ?? 0), (string) ($invoice['currency'] ?? 'TZS'));
    $qrUrl = buildReceiptQrUrl($invoice);
    $qrHtml = '<div class="qr-wrap"><img src="' . h($qrUrl) . '" alt="QR code" class="qr-img" /><p class="qr-caption">Skani kuthibitisha muamala</p></div>';

    $controlBlock = $hasControlNumber
        ? '<div class="row"><span class="lbl">Control No.</span><span class="val">' . $controlNumber . '</span></div>'
        : '';

    $fontFamily = $forPdf ? 'DejaVu Sans, sans-serif' : '"Segoe UI", system-ui, sans-serif';

    return '<!DOCTYPE html>
<html lang="sw">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Receipt ' . $invoiceNumber . '</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
  <style>
    body { margin: 0; font-family: ' . $fontFamily . '; color: #111827; background: #eef2f7; }
    .wrap { max-width: 440px; margin: 0 auto; padding: 24px 16px 32px; }
    .paper {
      background: #fff;
      border-radius: 16px;
      box-shadow: 0 16px 40px rgba(15, 23, 42, 0.14);
      overflow: hidden;
      border: 1px solid #e5e7eb;
    }
    .head {
      background: linear-gradient(135deg, #0f172a, #1e3a5f);
      color: #fff;
      padding: 22px 18px 18px;
      text-align: center;
    }
    .brand { font-size: 20px; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; }
    .sub { margin-top: 4px; font-size: 12px; opacity: .85; }
    .stamp {
      display: inline-block;
      margin-top: 12px;
      padding: 6px 14px;
      border-radius: 999px;
      font-size: 11px;
      font-weight: 700;
      letter-spacing: .06em;
    }
    .stamp.ok { background: #dcfce7; color: #14532d; }
    .stamp.pending { background: #fef3c7; color: #92400e; }
    .stamp.failed { background: #fee2e2; color: #7f1d1d; }
    .body { padding: 18px 18px 8px; }
    .amount {
      text-align: center;
      font-size: 30px;
      font-weight: 800;
      margin: 4px 0 10px;
      color: #0f172a;
    }
    .qr-wrap { text-align: center; margin: 8px 0 14px; }
    .qr-img {
      width: 168px;
      height: 168px;
      border-radius: 12px;
      border: 1px solid #e5e7eb;
      padding: 8px;
      background: #fff;
    }
    .qr-caption { margin: 8px 0 0; font-size: 11px; color: #6b7280; font-weight: 600; }
    .divider { border: none; border-top: 1px dashed #d1d5db; margin: 12px 0; }
    .row {
      display: flex;
      justify-content: space-between;
      gap: 12px;
      margin: 7px 0;
      font-size: 13px;
      line-height: 1.45;
    }
    .lbl { color: #6b7280; font-weight: 600; min-width: 110px; }
    .val { color: #111827; font-weight: 600; text-align: right; word-break: break-word; }
    .print-bar {
      display: flex;
      gap: 10px;
      justify-content: center;
      padding: 14px 18px;
      border-top: 1px dashed #e5e7eb;
      background: #f8fafc;
    }
    .footer {
      padding: 14px 18px 18px;
      border-top: 1px solid #e5e7eb;
      font-size: 11px;
      color: #6b7280;
      text-align: center;
      line-height: 1.5;
    }
    .actions {
      display: flex;
      gap: 10px;
      justify-content: center;
      margin-top: 16px;
      flex-wrap: wrap;
    }
    .btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      padding: 11px 16px;
      border-radius: 10px;
      border: 1px solid #d1d5db;
      background: #fff;
      color: #111827;
      text-decoration: none;
      font-size: 13px;
      font-weight: 700;
      cursor: pointer;
      box-shadow: 0 2px 8px rgba(15, 23, 42, 0.06);
      transition: transform .15s ease, box-shadow .15s ease;
    }
    .btn:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(15, 23, 42, 0.1); }
    .btn.primary { background: linear-gradient(135deg, #0f172a, #1e3a5f); color: #fff; border-color: #0f172a; }
    .btn.print { background: #ecfdf5; color: #065f46; border-color: #a7f3d0; }
    @media print {
      body { background: #fff; }
      .wrap { max-width: none; padding: 0; }
      .paper { box-shadow: none; border: none; border-radius: 0; }
      .actions, .print-bar { display: none !important; }
    }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="paper">
      <div class="head">
        <div class="brand">' . h($businessName) . '</div>
        <div class="sub">' . h($businessAddress) . '</div>
        <div class="stamp ' . $statusClass . '">' . h($statusLabel) . '</div>
      </div>
      <div class="body">
        <div class="amount">' . $amount . '</div>
        <hr class="divider" />
        <div class="row"><span class="lbl">Mlipaji</span><span class="val">' . $customerName . '</span></div>
        <div class="row"><span class="lbl">Simu</span><span class="val">' . $customerPhone . '</span></div>
        <div class="row"><span class="lbl">Muda wa malipo</span><span class="val">' . $paidAt . '</span></div>
        <hr class="divider" />
        <div class="row"><span class="lbl">Risiti No.</span><span class="val">' . $invoiceNumber . '</span></div>
        <div class="row"><span class="lbl">Order</span><span class="val">' . $orderId . '</span></div>
        <div class="row"><span class="lbl">Reference</span><span class="val">' . $reference . '</span></div>
        ' . $controlBlock . '
        <div class="row"><span class="lbl">Channel</span><span class="val">' . $channel . '</span></div>
        <div class="row"><span class="lbl">Maelezo</span><span class="val">' . $description . '</span></div>
        <div class="row"><span class="lbl">Tarehe ya kuundwa</span><span class="val">' . $createdAt . '</span></div>
        <hr class="divider" />
        ' . $qrHtml . '
      </div>
      ' . ($forPdf ? '' : '<div class="print-bar">
        <button type="button" class="btn print" onclick="window.print()"><i class="fa-solid fa-print"></i> Chapisha Risiti</button>
      </div>') . '
      <div class="footer">
        Asante kwa malipo yako.<br />
        Hii ni risiti rasmi ya muamala wako.
      </div>
    </div>
    ' . ($forPdf ? '' : '<div class="actions">
      <a class="btn primary" href="?id=' . (int) ($invoice['id'] ?? 0) . '&amp;download=1"><i class="fa-solid fa-download"></i> Pakua PDF</a>
      <button type="button" class="btn" onclick="window.print()"><i class="fa-solid fa-print"></i> Chapisha</button>
      <a class="btn" href="admin-dashboard.php"><i class="fa-solid fa-arrow-left"></i> Dashboard</a>
    </div>') . '
  </div>
</body>
</html>';
}

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html><body><p>Invalid receipt id.</p></body></html>';
    exit;
}

$payload = adminInvoiceFetchRemote($id);
$invoice = is_array($payload['invoice'] ?? null) ? $payload['invoice'] : null;

if ($invoice === null) {
    http_response_code(404);
    header('Content-Type: text/html; charset=UTF-8');
    $message = h((string) ($payload['message'] ?? 'Receipt not found or remote service unavailable.'));
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8" /><title>Receipt error</title></head><body style="font-family:sans-serif;padding:24px"><h1>Could not load receipt</h1><p>' . $message . '</p><p><a href="admin-dashboard.php">Back to dashboard</a></p></body></html>';
    exit;
}

$download = isset($_GET['download']) && (string) $_GET['download'] !== '0';

if ($download) {
    require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

    $tempDir = dirname(__DIR__, 2) . '/frontend/runtime/mpdf';
    if (!is_dir($tempDir)) {
        @mkdir($tempDir, 0775, true);
    }

    $html = buildAdminReceiptHtml($invoice, true);
    $pdf = new \Mpdf\Mpdf([
        'tempDir' => $tempDir,
        'format' => 'A4',
        'margin_left' => 14,
        'margin_right' => 14,
        'margin_top' => 14,
        'margin_bottom' => 14,
        'allowRemoteImages' => true,
    ]);
    $pdf->WriteHTML($html);

    $filename = 'receipt-' . preg_replace('/[^A-Za-z0-9\-_]/', '', (string) ($invoice['invoiceNumber'] ?? (string) $id)) . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    echo $pdf->Output('', 'S');
    exit;
}

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
echo buildAdminReceiptHtml($invoice, false);
