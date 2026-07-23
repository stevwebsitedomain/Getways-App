<?php

declare(strict_types=1);

/**
 * Public receipt page opened when a QR code is scanned / printed.
 * Shows a visual paper receipt (not raw JSON).
 */

require_once __DIR__ . '/auth-init.php';
gwAuthStartSession();

$authUser = $_SESSION['gw_auth_user'] ?? null;
$loggedIn = is_array($authUser);
$authName = $loggedIn ? trim((string) ($authUser['fullName'] ?? 'Customer')) : '';
$authEmail = $loggedIn ? trim((string) ($authUser['email'] ?? '')) : '';
$authAvatar = $loggedIn ? trim((string) ($authUser['avatar'] ?? '')) : '';
$authInitial = $authName !== '' ? strtoupper(substr($authName, 0, 1)) : 'U';

$ref = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) ($_GET['r'] ?? $_GET['ref'] ?? '')) ?: '');

$tx = [
    'order_reference' => $ref,
    'amount' => isset($_GET['a']) ? (float) $_GET['a'] : 0,
    'currency' => preg_replace('/[^A-Z]/', '', strtoupper((string) ($_GET['c'] ?? 'TZS'))) ?: 'TZS',
    'phone' => preg_replace('/\D+/', '', (string) ($_GET['p'] ?? '')) ?: '',
    'payment_status' => strtoupper((string) ($_GET['s'] ?? 'SUCCESS')),
    'channel' => substr((string) ($_GET['ch'] ?? 'HALOPESA'), 0, 64),
    'customer_name' => substr((string) ($_GET['n'] ?? 'Customer'), 0, 120),
    'description' => substr((string) ($_GET['d'] ?? 'AutoPay HaloPesa Payment'), 0, 200),
    'created_at' => null,
];

if ($ref !== '') {
    try {
        require __DIR__ . '/../../vendor/autoload.php';
        require __DIR__ . '/../../vendor/yiisoft/yii2/Yii.php';
        require __DIR__ . '/../../common/config/bootstrap.php';
        require __DIR__ . '/../config/bootstrap.php';

        $config = yii\helpers\ArrayHelper::merge(
            require __DIR__ . '/../../common/config/main.php',
            require __DIR__ . '/../../common/config/main-local.php',
            require __DIR__ . '/../config/main.php',
            require __DIR__ . '/../config/main-local.php'
        );

        new yii\web\Application($config);

        /** @var common\models\ClickPesaTransaction|null $model */
        $model = common\models\ClickPesaTransaction::find()
            ->where(['order_reference' => $ref])
            ->one();

        if ($model !== null) {
            $tx = [
                'order_reference' => (string) $model->order_reference,
                'amount' => (float) $model->amount,
                'currency' => (string) ($model->currency ?: 'TZS'),
                'phone' => (string) ($model->phone ?: ''),
                'payment_status' => (string) ($model->payment_status ?: 'SUCCESS'),
                'channel' => (string) ($model->channel ?: 'HALOPESA'),
                'customer_name' => (string) ($model->customer_name ?: 'Customer'),
                'description' => (string) ($model->description ?: 'AutoPay HaloPesa Payment'),
                'created_at' => $model->created_at ? (int) $model->created_at : null,
            ];
        }
    } catch (Throwable $e) {
        // Keep query-string fallback values when DB/Yii is unavailable.
    }
}

$ok = in_array(strtoupper((string) $tx['payment_status']), ['SUCCESS', 'SUCCESSFUL', 'SETTLED', 'COMPLETED', 'PAID'], true);
$status = $ok ? 'SUCCESS' : strtoupper((string) $tx['payment_status']);
$amountFmt = number_format((float) $tx['amount'], 0, '.', ',');
$when = $tx['created_at']
    ? date('d/m/Y, H:i:s', (int) $tx['created_at'])
    : date('d/m/Y, H:i:s');
$autoPrint = (isset($_GET['print']) && (string) $_GET['print'] === '1')
    || (isset($_GET['autoprint']) && (string) $_GET['autoprint'] === '1');
$embed = isset($_GET['embed']) && (string) $_GET['embed'] === '1';

function h(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

$qrUrl = '';
if ($ref !== '') {
    $qrTarget = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
        . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
        . '/receipt.php?r=' . rawurlencode($ref)
        . '&a=' . rawurlencode((string) (int) $tx['amount'])
        . '&s=' . rawurlencode($status);
    if (!empty($tx['phone'])) {
        $qrTarget .= '&p=' . rawurlencode((string) $tx['phone']);
    }
    $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&margin=6&ecc=H&data=' . rawurlencode($qrTarget);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Receipt <?= h($tx['order_reference'] ?: 'Getway') ?></title>
  <link rel="icon" type="image/png" href="images/favicon.png" />
  <style>
    /* Self-contained — do NOT load wallet CSS (it breaks print width) */
    *, *::before, *::after { box-sizing: border-box; }
    html, body {
      margin: 0;
      padding: 0;
      background: #eef2f7;
      color: #000;
      font-family: "Courier New", Courier, monospace;
    }
    .receipt-page {
      min-height: 100vh;
      display: flex;
      justify-content: center;
      align-items: flex-start;
      padding: <?= $embed ? '12px 10px 20px' : '24px 16px 40px' ?>;
    }
    .receipt-paper {
      width: 100%;
      max-width: 340px;
      margin: 0 auto;
      background: #fff;
      color: #000;
      border: 1px dashed #94a3b8;
      padding: 18px 14px 16px;
      text-align: center;
      box-shadow: 0 10px 28px rgba(15, 23, 42, 0.1);
    }
    .brand {
      font-weight: 700;
      font-size: 15px;
      letter-spacing: .05em;
      text-transform: uppercase;
      color: #000;
    }
    .sub {
      margin-top: 4px;
      font-size: 11px;
      color: #000;
      font-weight: 700;
    }
    .stamp {
      display: inline-block;
      margin-top: 10px;
      padding: 5px 12px;
      border-radius: 999px;
      font-size: 11px;
      font-weight: 700;
      background: <?= $ok ? '#dcfce7' : '#fee2e2' ?>;
      color: <?= $ok ? '#14532d' : '#7f1d1d' ?>;
    }
    .dash {
      border: none;
      border-top: 1px dashed #000;
      margin: 10px 0;
    }
    .row {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 10px;
      text-align: left;
      margin: 5px 0;
      font-size: 12px;
      line-height: 1.4;
      color: #000;
    }
    .row .lbl {
      flex: 0 0 auto;
      color: #000;
      font-weight: 700;
      min-width: 82px;
    }
    .row .val {
      flex: 1 1 auto;
      font-weight: 700;
      text-align: right;
      word-break: break-word;
      overflow-wrap: anywhere;
      min-width: 0;
      color: #000;
    }
    .total {
      display: flex;
      justify-content: space-between;
      align-items: baseline;
      gap: 10px;
      margin-top: 10px;
      font-size: 16px;
      font-weight: 800;
      text-align: left;
      color: #000;
    }
    .total .val { text-align: right; word-break: break-word; font-weight: 800; color: #000; }
    .qr-label {
      font-size: 10px;
      font-weight: 700;
      color: #000;
      letter-spacing: .06em;
      margin-bottom: 6px;
    }
    .qr-wrap {
      display: flex;
      justify-content: center;
      margin: 0 auto 6px;
      padding: 0;
      border: none;
      border-radius: 0;
      width: fit-content;
      background: #fff;
    }
    .qr-wrap img {
      width: 130px;
      height: 130px;
      display: block;
      image-rendering: pixelated;
      image-rendering: crisp-edges;
      background: #fff;
    }
    .code {
      margin-top: 6px;
      font-size: 11px;
      letter-spacing: .04em;
      word-break: break-all;
      font-weight: 700;
      color: #000;
    }
    .thanks {
      margin-top: 10px;
      font-size: 12px;
      font-weight: 700;
      color: #000;
    }
    .receipt-missing {
      max-width: 360px;
      margin: 24px auto;
      padding: 20px;
      background: #fff;
      border-radius: 12px;
      text-align: center;
      color: #334155;
      font-family: system-ui, sans-serif;
    }

    /* Thermal-style sheet — avoid full A4 height (long empty white space) */
    @page {
      size: 80mm 220mm;
      margin: 4mm;
    }

    @media print {
      html, body {
        width: auto !important;
        height: auto !important;
        min-height: 0 !important;
        max-height: none !important;
        margin: 0 !important;
        padding: 0 !important;
        background: #fff !important;
        overflow: hidden !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
      }
      .receipt-page {
        display: block !important;
        width: auto !important;
        height: auto !important;
        min-height: 0 !important;
        padding: 0 !important;
        margin: 0 !important;
      }
      .receipt-paper {
        width: 72mm !important;
        max-width: 72mm !important;
        height: auto !important;
        min-height: 0 !important;
        margin: 0 auto !important;
        padding: 4mm 3mm !important;
        border: 1px dashed #94a3b8 !important;
        box-shadow: none !important;
        page-break-after: avoid;
        page-break-inside: avoid;
        break-after: avoid;
        break-inside: avoid;
      }
      .receipt-missing { display: none !important; }
      .receipt-paper {
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
        color: #000 !important;
      }
      .receipt-paper .brand,
      .receipt-paper .sub,
      .receipt-paper .row,
      .receipt-paper .row .lbl,
      .receipt-paper .row .val,
      .receipt-paper .total,
      .receipt-paper .total .val,
      .receipt-paper .qr-label,
      .receipt-paper .code,
      .receipt-paper .thanks {
        color: #000 !important;
      }
      .row, .sub, .code, .thanks, .qr-label { font-size: 10pt !important; }
      .row .val, .row .lbl, .code, .thanks, .sub, .qr-label { font-weight: 700 !important; }
      .total { font-size: 13pt !important; font-weight: 800 !important; }
      .brand { font-size: 12pt !important; font-weight: 700 !important; }
      .dash { border-top: 1px dashed #000 !important; }
      .qr-wrap {
        width: fit-content !important;
        margin: 2mm auto !important;
        padding: 0 !important;
        border: none !important;
        background: #fff !important;
      }
      .qr-wrap img {
        width: 34mm !important;
        height: 34mm !important;
        image-rendering: pixelated !important;
        image-rendering: crisp-edges !important;
        background: #fff !important;
      }
      .qr-label {
        color: #000 !important;
        font-weight: 700 !important;
      }
      .stamp {
        background: <?= $ok ? '#dcfce7' : '#fee2e2' ?> !important;
        color: <?= $ok ? '#166534' : '#991b1b' ?> !important;
      }
    }
  </style>
</head>
<body class="<?= $embed ? 'receipt-embed' : '' ?>">
  <main class="receipt-page">
    <?php if ($ref === ''): ?>
      <div class="receipt-missing">
        <strong>Receipt not found</strong>
        <p>Scan a valid Getway receipt QR code.</p>
      </div>
    <?php else: ?>
      <article class="receipt-paper" aria-label="Payment receipt">
        <div class="brand">Getway | System</div>
        <div class="sub">Payment Receipt</div>
        <div class="sub"><?= $ok ? 'TRANSACTION COMPLETE' : 'TRANSACTION FAILED' ?></div>
        <div class="stamp"><?= h($status) ?></div>
        <hr class="dash" />
        <div class="row"><span class="lbl">CASHIER</span><span class="val">AutoPay</span></div>
        <div class="row"><span class="lbl">CUSTOMER</span><span class="val"><?= h($tx['customer_name'] ?: 'Customer') ?></span></div>
        <div class="row"><span class="lbl">PHONE</span><span class="val"><?= h($tx['phone'] ?: '—') ?></span></div>
        <div class="row"><span class="lbl">CHANNEL</span><span class="val"><?= h($tx['channel'] ?: 'Mobile Money') ?></span></div>
        <div class="row"><span class="lbl">DESC</span><span class="val"><?= h($tx['description'] ?: 'Payment') ?></span></div>
        <hr class="dash" />
        <div class="row"><span class="lbl">ORDER REF</span><span class="val"><?= h($tx['order_reference']) ?></span></div>
        <div class="row"><span class="lbl">STATUS</span><span class="val"><?= h($status) ?></span></div>
        <div class="row"><span class="lbl">DATE</span><span class="val"><?= h($when) ?></span></div>
        <div class="total"><span class="lbl">TOTAL</span><span class="val"><?= h($tx['currency']) ?> <?= h($amountFmt) ?></span></div>
        <hr class="dash" />
        <?php if ($qrUrl !== ''): ?>
          <div class="qr-label">SCAN QR CODE</div>
          <div class="qr-wrap">
            <img src="<?= h($qrUrl) ?>" width="130" height="130" alt="Receipt QR code" />
          </div>
        <?php endif; ?>
        <div class="code"><?= h($tx['order_reference']) ?></div>
        <div class="thanks">Thank you for your payment</div>
      </article>
    <?php endif; ?>
  </main>
  <?php if ($autoPrint && $ref !== ''): ?>
  <script>
    window.addEventListener("load", function () {
      var img = document.querySelector(".qr-wrap img");
      function goPrint() {
        setTimeout(function () { window.focus(); window.print(); }, 300);
      }
      if (img && !img.complete) {
        img.addEventListener("load", goPrint, { once: true });
        img.addEventListener("error", goPrint, { once: true });
        setTimeout(goPrint, 2500);
      } else {
        goPrint();
      }
    });
  </script>
  <?php endif; ?>
</body>
</html>
