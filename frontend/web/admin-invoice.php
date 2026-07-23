<?php

declare(strict_types=1);

/**
 * Receipt / invoice page for control numbers.
 * Admin entry: admin-invoice.php
 * User entry: control-number-invoice.php
 */

$invoiceReceiptMode = defined('INVOICE_RECEIPT_MODE') ? (string) INVOICE_RECEIPT_MODE : 'admin';
$shareTokenEarly = trim((string) ($_GET['t'] ?? $_GET['share'] ?? ''));
$idEarly = (int) ($_GET['id'] ?? 0);
$invoicePublicShare = (defined('INVOICE_PUBLIC_SHARE_REQUEST') && INVOICE_PUBLIC_SHARE_REQUEST)
    || ($shareTokenEarly !== '' && $idEarly > 0);
if (!$invoicePublicShare) {
    if ($invoiceReceiptMode === 'user') {
        require_once __DIR__ . '/auth-guard.php';
    } else {
        require_once __DIR__ . '/admin-guard.php';
    }
}

require_once __DIR__ . '/invoice-share.php';

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

/**
 * @return \yii\web\Application
 */
function invoiceYiiApp(): \yii\web\Application
{
    static $app = null;
    if ($app instanceof \yii\web\Application) {
        return $app;
    }

    defined('YII_DEBUG') or define('YII_DEBUG', true);
    defined('YII_ENV') or define('YII_ENV', 'dev');

    require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
    require_once dirname(__DIR__, 2) . '/vendor/yiisoft/yii2/Yii.php';
    require_once dirname(__DIR__, 2) . '/common/config/bootstrap.php';
    require_once dirname(__DIR__) . '/config/bootstrap.php';

    $config = yii\helpers\ArrayHelper::merge(
        require dirname(__DIR__, 2) . '/common/config/main.php',
        require dirname(__DIR__, 2) . '/common/config/main-local.php',
        require dirname(__DIR__) . '/config/main.php',
        require dirname(__DIR__) . '/config/main-local.php'
    );

    if (isset($config['components']['session'])) {
        $config['components']['session']['autoStart'] = false;
    }
    if (isset($config['components']['user'])) {
        $config['components']['user']['enableSession'] = false;
        $config['components']['user']['enableAutoLogin'] = false;
    }

    $app = new yii\web\Application($config);

    return $app;
}

/**
 * @return array<string,mixed>|null
 */
function invoiceLoadLocal(int $id): ?array
{
    try {
        invoiceYiiApp();
        $invoice = Yii::$container->get(\common\services\ClickPesaService::class)->getInvoiceData($id);

        return ['success' => true, 'invoice' => $invoice];
    } catch (Throwable) {
        return null;
    }
}

/**
 * @return array<string,mixed>|null
 */
function invoiceLoadById(int $id): ?array
{
    $local = invoiceLoadLocal($id);
    if (is_array($local['invoice'] ?? null)) {
        return $local;
    }

    return adminInvoiceFetchRemote($id);
}

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function moneyLabel(float $amount, string $currency): string
{
    return h($currency) . ' ' . number_format($amount, 0, '.', ',');
}

function buildReceiptQrPayload(array $invoice, string $mode = 'admin'): string
{
    $id = (int) ($invoice['id'] ?? 0);
    if ($id > 0) {
        return invoiceFullShareUrl($id, $mode);
    }

    $ref = trim((string) ($invoice['qrReference'] ?? $invoice['billReference'] ?? ''));
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $scriptDir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/admin-invoice.php')));
    $base = rtrim($scheme . '://' . $host . $scriptDir, '/');
    $page = $mode === 'user' ? 'control-number-invoice.php' : 'admin-invoice.php';
    $verifyUrl = $base . '/' . $page . '?id=' . $id;
    if ($ref !== '') {
        $verifyUrl .= '&ref=' . rawurlencode($ref);
    }

    return $verifyUrl;
}

function buildReceiptQrUrl(array $invoice, string $mode = 'admin'): string
{
    return 'https://api.qrserver.com/v1/create-qr-code/?size=280x280&margin=10&ecc=H&data=' . rawurlencode(buildReceiptQrPayload($invoice, $mode));
}

/**
 * @param array<string,mixed> $invoice
 */
function buildAdminReceiptHtml(array $invoice, bool $forPdf = false, string $backUrl = 'admin-dashboard.php', string $backLabel = 'Dashboard', string $receiptMode = 'admin', bool $autoPrint = false): string
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
    $hasControlNumber = trim((string) ($invoice['controlNumber'] ?? '')) !== '' && trim((string) ($invoice['controlNumber'] ?? '')) !== '—';
    $description = h((string) ($invoice['description'] ?? 'Payment'));
    $channel = h((string) ($invoice['channel'] ?? 'clickpesa'));
    $paidAt = h((string) ($invoice['paidAtFormatted'] ?? '—'));
    $createdAt = h((string) ($invoice['createdAtFormatted'] ?? '—'));
    $amount = moneyLabel((float) ($invoice['amount'] ?? 0), (string) ($invoice['currency'] ?? 'TZS'));
    $qrUrl = buildReceiptQrUrl($invoice, $receiptMode);
    $qrCaption = 'Skani kuona risiti kwenye simu';
    $qrHtml = '<div class="qr-wrap"><img src="' . h($qrUrl) . '" alt="QR code" class="qr-img" /><p class="qr-caption">' . h($qrCaption) . '</p></div>';

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
      <a class="btn primary" href="?id=' . (int) ($invoice['id'] ?? 0) . '&amp;download=1"><i class="fa-solid fa-file-pdf"></i> Pakua PDF</a>
      <button type="button" class="btn" onclick="window.print()"><i class="fa-solid fa-print"></i> Chapisha</button>
      <a class="btn" href="' . h($backUrl) . '"><i class="fa-solid fa-arrow-left"></i> ' . h($backLabel) . '</a>
    </div>') . '
  </div>
' . ($autoPrint ? '<script>
window.addEventListener("load", function () {
  setTimeout(function () { window.print(); }, 500);
});
</script>' : '') . '
</body>
</html>';
}

$id = (int) ($_GET['id'] ?? 0);
$shareToken = trim((string) ($_GET['t'] ?? $_GET['share'] ?? ''));
if ($id <= 0) {
    http_response_code(400);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html><body><p>Invalid receipt id.</p></body></html>';
    exit;
}

$backUrl = $invoiceReceiptMode === 'user' ? 'control-number.php' : 'admin-dashboard.php';
$backLabel = $invoiceReceiptMode === 'user' ? 'Control Number' : 'Dashboard';

$payload = invoiceLoadById($id);
$invoice = is_array($payload['invoice'] ?? null) ? $payload['invoice'] : null;

if ($invoicePublicShare && !invoiceShareTokenValid($id, $shareToken)) {
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8" /><title>Receipt error</title></head><body style="font-family:sans-serif;padding:24px"><h1>Invalid receipt link</h1><p>This QR code or receipt link is not valid.</p></body></html>';
    exit;
}

if ($invoice === null) {
    http_response_code(404);
    header('Content-Type: text/html; charset=UTF-8');
    $message = h((string) ($payload['message'] ?? 'Receipt not found or remote service unavailable.'));
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8" /><title>Receipt error</title></head><body style="font-family:sans-serif;padding:24px"><h1>Could not load receipt</h1><p>' . $message . '</p><p><a href="' . h($backUrl) . '">Back</a></p></body></html>';
    exit;
}

$download = isset($_GET['download']) && (string) $_GET['download'] !== '0';
$autoPrint = (isset($_GET['print']) && (string) $_GET['print'] !== '0')
    || (isset($_GET['autoprint']) && (string) $_GET['autoprint'] !== '0');

if ($download) {
    require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

    $tempDir = dirname(__DIR__, 2) . '/frontend/runtime/mpdf';
    if (!is_dir($tempDir)) {
        @mkdir($tempDir, 0775, true);
    }

    $html = buildAdminReceiptHtml($invoice, true, $backUrl, $backLabel, $invoiceReceiptMode);
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
echo buildAdminReceiptHtml($invoice, false, $backUrl, $backLabel, $invoiceReceiptMode, $autoPrint);
