<?php
require __DIR__ . '/auth-guard.php';
$cssVersion = (string) (@filemtime(__DIR__ . '/part-two.css') ?: time());
$shellVersion = (string) (@filemtime(__DIR__ . '/wallet-shell.js') ?: time());
$pageJsVersion = (string) (@filemtime(__DIR__ . '/control-number.js') ?: time());
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
  <title>Getway | System | Control Number</title>
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
              placeholder="Search control number form…"
              autocomplete="off"
              spellcheck="false"
            />
          </div>
        </div>
      </header>

      <div class="w-page-content">
        <section class="tis-grid">
          <article class="tis-card w-searchable cp-form-card">
            <h2><i class="fa-solid fa-file-invoice-dollar"></i> BillPay Control Number</h2>
            <p class="empty-state" style="margin-top: 0; margin-bottom: 0.85rem">
              Generate a ClickPesa control number for an order and share it with the customer.
            </p>
            <form id="control-number-form" class="tis-form">
              <label for="cn-order-id"><i class="fa-solid fa-receipt"></i> Order ID</label>
              <input type="text" id="cn-order-id" name="order_id" placeholder="Order ID" required />

              <label for="cn-amount"><i class="fa-solid fa-money-bill-wave"></i> Amount TZS</label>
              <input type="number" id="cn-amount" name="amount" min="1" step="0.01" placeholder="Amount TZS" required />

              <label for="cn-description"><i class="fa-solid fa-pen-to-square"></i> Description</label>
              <input type="text" id="cn-description" name="description" placeholder="Description" required />

              <label for="cn-payment-mode"><i class="fa-solid fa-list-check"></i> Payment Mode</label>
              <select id="cn-payment-mode" name="payment_mode" class="w-cn-select">
                <option value="EXACT">EXACT</option>
                <option value="ALLOW_PARTIAL_AND_OVER_PAYMENT">Partial/Over Payment</option>
              </select>

              <button class="btn-primary" type="submit">
                <i class="fa-solid fa-plus"></i> Create Control Number
              </button>
            </form>
            <p id="control-number-message" class="form-message"></p>
            <div id="control-number-result" class="w-cn-result" hidden></div>
          </article>
        </section>
      </div>
    </div>

<?php $activeNav = 'autopay'; require __DIR__ . '/wallet-bottom-nav.php'; ?>
  </main>

  <script src="tis-api-base.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
  <script src="wallet-shell.js?v=<?= urlencode($shellVersion) ?>"></script>
  <script src="control-number.js?v=<?= urlencode($pageJsVersion) ?>"></script>
</body>
</html>
