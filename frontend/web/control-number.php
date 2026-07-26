<?php
require __DIR__ . '/auth-guard.php';
$authUser = $_SESSION['gw_auth_user'] ?? [];
$cssVersion = (string) (@filemtime(__DIR__ . '/part-two.css') ?: time());
$bkVersion = (string) (@filemtime(__DIR__ . '/wallet-banking-theme.css') ?: time());
$shellVersion = (string) (@filemtime(__DIR__ . '/wallet-shell.js') ?: time());
$pageJsVersion = (string) (@filemtime(__DIR__ . '/control-number.js') ?: time());
$phoneTopbarTitle = 'Control Number';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
  <title>Getway | Control Number</title>
  <link rel="icon" type="image/png" href="images/favicon.png" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link
    href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;0,9..40,800&display=swap"
    rel="stylesheet"
  />
  <link rel="stylesheet" href="style.css" />
  <link rel="stylesheet" href="part-two.css?v=<?= urlencode($cssVersion) ?>" />
  <link rel="stylesheet" href="wallet-banking-theme.css?v=<?= urlencode($bkVersion) ?>" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" />
</head>
<body class="tis-shell tis-wallet-dash layout-phone create-pay-page w-home-sample bk-theme">
<?php $activeTopNav = 'autopay'; require __DIR__ . '/wallet-top-nav.php'; ?>

  <main class="tis-wrap w-shell">
    <div class="w-app w-cn-page">
<?php require __DIR__ . '/wallet-phone-topbar.php'; ?>

      <div class="w-page-content">
        <section class="bk-transfer-card w-searchable" style="margin-bottom: 14px;">
          <h2><i class="fa-solid fa-file-invoice-dollar"></i> Create control number</h2>
          <p class="bk-form-note">Weka kiasi na maelezo — control number itatengenezwa otomatiki.</p>
          <form id="control-number-form" class="tis-form">
            <div class="bk-form-field">
              <label for="cn-order-id">Order label <small class="bk-label-hint">(hiari)</small></label>
              <input id="cn-order-id" name="order_id" placeholder="TIS01" maxlength="20" />
            </div>
            <div class="bk-form-field">
              <label for="cn-amount">Amount (TZS)</label>
              <input id="cn-amount" name="amount" type="number" min="1" step="0.01" required placeholder="1000" />
            </div>
            <div class="bk-form-field">
              <label for="cn-description">Description</label>
              <input id="cn-description" name="description" required placeholder="Malipo ya bidhaa" />
            </div>
            <div class="bk-form-field">
              <label for="cn-mode">Mode</label>
              <select id="cn-mode" name="payment_mode">
                <option value="EXACT">EXACT</option>
                <option value="ALLOW_PARTIAL_AND_OVER_PAYMENT">Partial / Over</option>
              </select>
            </div>
            <button type="submit" class="bk-btn-primary">
              <i class="fa-solid fa-file-invoice-dollar"></i> Create
            </button>
          </form>
          <p id="control-number-message" class="form-message"></p>
        </section>

        <section class="bk-tx-card w-searchable">
          <div class="bk-tx-head">
            <h2><i class="fa-solid fa-clock-rotate-left"></i> Transactions</h2>
            <button type="button" class="bk-tx-refresh" id="cn-refresh" title="Refresh">
              <i class="fa-solid fa-rotate"></i>
            </button>
          </div>
          <p id="cn-tx-error" class="bk-tx-error" hidden></p>
          <div class="bk-table-wrap">
            <table class="bk-table">
              <thead>
                <tr>
                  <th>Order</th>
                  <th>Control #</th>
                  <th>Amount</th>
                  <th>Status</th>
                  <th></th>
                </tr>
              </thead>
              <tbody id="cn-tx-body">
                <tr><td colspan="5" class="bk-empty">Loading…</td></tr>
              </tbody>
            </table>
          </div>
          <nav class="bk-pager" id="cn-tx-pager" hidden aria-label="Transactions pages"></nav>
        </section>
      </div>
    </div>

<?php $activeNav = ''; require __DIR__ . '/wallet-bottom-nav.php'; ?>
  </main>

  <script src="tis-api-base.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
  <script src="wallet-shell.js?v=<?= urlencode($shellVersion) ?>"></script>
  <script src="control-number.js?v=<?= urlencode($pageJsVersion) ?>"></script>
</body>
</html>
