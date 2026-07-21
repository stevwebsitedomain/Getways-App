<?php
require __DIR__ . '/auth-guard.php';
$cssVersion = (string) (@filemtime(__DIR__ . '/part-two.css') ?: time());
$shellVersion = (string) (@filemtime(__DIR__ . '/wallet-shell.js') ?: time());
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
  <title>Getway | System | AutoPay</title>
  <link rel="icon" type="image/png" href="images/favicon.png" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link
    href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;0,9..40,800&display=swap"
    rel="stylesheet"
  />
  <link rel="stylesheet" href="style.css" />
  <link rel="stylesheet" href="part-two.css?v=<?= urlencode($cssVersion) ?>" />
  <link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"
  />
</head>
<body class="tis-shell tis-wallet-dash layout-phone create-pay-page w-home-sample">
<?php $activeTopNav = 'autopay'; require __DIR__ . '/wallet-top-nav.php'; ?>

  <main class="tis-wrap w-shell">
    <div class="w-app">
      <header class="w-header">
        <div class="w-header-center">
          <label class="w-visually-hidden" for="wallet-global-search">Search this page</label>
          <div class="w-header-search">
            <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
            <input
              type="search"
              id="wallet-global-search"
              placeholder="Search AutoPay…"
              autocomplete="off"
              spellcheck="false"
            />
          </div>
        </div>
      </header>

      <div class="w-page-content">
        <section class="tis-grid">
          <article class="tis-card w-searchable cp-form-card">
            <h2><i class="fa-solid fa-bolt"></i> AutoPay</h2>
            <p class="empty-state" style="margin-top: 0; margin-bottom: 0.85rem">
              AutoPay sends a USSD push. After the customer enters PIN, a Success or Failed receipt is shown automatically.
            </p>
            <form id="autopay-form" class="tis-form">
              <label for="customerPhone"><i class="fa-solid fa-phone"></i> Customer Phone</label>
              <input type="text" id="customerPhone" value="255765149991" required />

              <label for="customerName"><i class="fa-solid fa-user"></i> Customer Name</label>
              <input type="text" id="customerName" value="Customer" required />

              <label for="description"><i class="fa-solid fa-pen-to-square"></i> Description</label>
              <input type="text" id="description" value="AutoPay HaloPesa Payment" required />

              <label for="amount"><i class="fa-solid fa-money-bill-wave"></i> Amount to pay (TZS)</label>
              <input type="number" id="amount" min="1" step="1" value="0" placeholder="0" required />

              <div class="total-row">
                <span>Order Total</span>
                <strong id="order-total">TZS 0</strong>
              </div>

              <button id="autopay-btn" class="btn-primary" type="submit">
                <i class="fa-solid fa-bolt"></i> SEND USSD PUSH
              </button>
            </form>
            <p id="form-message" class="form-message"></p>
          </article>
        </section>
      </div>
    </div>

<?php $activeNav = 'autopay'; require __DIR__ . '/wallet-bottom-nav.php'; ?>
  </main>

  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
  <script src="tis-api-base.js"></script>
  <script src="script.js"></script>
  <script src="wallet-shell.js?v=<?= urlencode($shellVersion) ?>"></script>
  <script src="receipt-actions.js?v=2"></script>
  <script src="receipt-slip.js?v=12"></script>
  <script src="autopay.js?v=3"></script>
</body>
</html>
