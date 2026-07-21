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
  <title>Getway | System | Create Payment</title>
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
<?php $activeTopNav = 'pay'; require __DIR__ . '/wallet-top-nav.php'; ?>

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
              placeholder="Search form, inventory, payments…"
              autocomplete="off"
              spellcheck="false"
            />
          </div>
        </div>
      </header>

      <div class="w-page-content">
        <section class="tis-grid">
          <article class="tis-card w-searchable cp-form-card">
            <h2><i class="fa-solid fa-file-invoice-dollar"></i> Create Payment</h2>
            <form id="payment-form" class="tis-form">
              <label for="customerName"><i class="fa-solid fa-user"></i> Customer Name</label>
              <input type="text" id="customerName" value="Customer" required />

              <label for="customerEmail"><i class="fa-solid fa-envelope"></i> Customer Email</label>
              <input type="email" id="customerEmail" value="customer@example.com" required />

              <label for="customerPhone"><i class="fa-solid fa-phone"></i> Customer Phone</label>
              <input type="text" id="customerPhone" value="255765149991" required />

              <label for="description"><i class="fa-solid fa-pen-to-square"></i> Description</label>
              <input type="text" id="description" value="Inventory Payment" required />

              <div class="section-heading">
                <h3><i class="fa-solid fa-boxes-stacked"></i> Inventory Items</h3>
                <button class="btn-soft" id="refresh-inventory-btn" type="button">Refresh</button>
              </div>
              <div id="inventory-list" class="inventory-list"></div>

              <div class="custom-product-inline">
                <h3><i class="fa-solid fa-money-bill-wave"></i> Enter payment amount</h3>
                <p class="empty-state" style="margin-top: 0; margin-bottom: 0.5rem">
                  Enter amount (TZS) and customer phone, then tap Pay Now to continue to ClickPesa.
                </p>
                <label for="custom-product-name"><i class="fa-solid fa-signature"></i> Product name</label>
                <input type="text" id="custom-product-name" placeholder="Example: Sugar 1kg" autocomplete="off" />

                <label for="custom-product-amount"><i class="fa-solid fa-money-bill-wave"></i> Amount to pay (TZS)</label>
                <input type="number" id="custom-product-amount" min="0" step="1" value="0" placeholder="0" />

                <p class="form-message" id="custom-product-hint" style="margin-top: 0.5rem"></p>
              </div>

              <div class="total-row">
                <span>Order Total</span>
                <strong id="order-total">TZS 0</strong>
              </div>

              <button id="pay-now-btn" class="btn-primary" type="submit">
                <i class="fa-solid fa-credit-card"></i> PAY NOW (Create Checkout)
              </button>
            </form>
            <p id="form-message" class="form-message"></p>
          </article>

          <article class="tis-card w-searchable cp-dashboard-card">
            <h2><i class="fa-solid fa-chart-column"></i> Payments Dashboard</h2>
            <div class="summary-grid summary-grid--four">
              <div class="summary-item">
                <span>Total sales</span>
                <strong id="total-sales">TZS 0</strong>
              </div>
              <div class="summary-item summary-item--failed">
                <span>Failed amount</span>
                <strong id="total-failed">TZS 0</strong>
              </div>
              <div class="summary-item summary-item--pending">
                <span>Pending orders</span>
                <strong id="total-pending">0</strong>
              </div>
              <div class="summary-item">
                <span>Total transactions</span>
                <strong id="total-transactions">0</strong>
              </div>
            </div>

            <h3>Transactions</h3>
            <div class="table-wrap">
              <table class="cp-transactions-table">
                <thead>
                  <tr>
                    <th>Order Ref</th>
                    <th>Amount</th>
                    <th>Status</th>
                    <th>Phone</th>
                    <th>Date</th>
                  </tr>
                </thead>
                <tbody id="payments-body"></tbody>
              </table>
            </div>
          </article>
        </section>
      </div>
    </div>

<?php $activeNav = 'pay'; require __DIR__ . '/wallet-bottom-nav.php'; ?>
  </main>

  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
  <script src="tis-api-base.js"></script>
  <script src="script.js"></script>
  <script src="wallet-shell.js?v=<?= urlencode($shellVersion) ?>"></script>
  <script src="create-payment.js"></script>
</body>
</html>
