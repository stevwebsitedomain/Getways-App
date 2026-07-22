<?php
require __DIR__ . '/auth-guard.php';
$authUser = $_SESSION['gw_auth_user'] ?? [];
$cssVersion = (string) (@filemtime(__DIR__ . '/part-two.css') ?: time());
$shellVersion = (string) (@filemtime(__DIR__ . '/wallet-shell.js') ?: time());
$pageJsVersion = (string) (@filemtime(__DIR__ . '/control-number.js') ?: time());
$adminCssVersion = (string) (@filemtime(__DIR__ . '/admin-dashboard.css') ?: time());
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
  <link rel="stylesheet" href="admin-dashboard.css?v=<?= urlencode($adminCssVersion) ?>" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" />
</head>
<body class="tis-shell tis-wallet-dash layout-phone create-pay-page w-home-sample">
<?php $activeTopNav = 'autopay'; require __DIR__ . '/wallet-top-nav.php'; ?>

  <main class="tis-wrap w-shell">
    <div class="w-app w-cn-page">
      <section class="ad-card" style="margin-top: 12px;">
        <h2><i class="fa-solid fa-file-invoice-dollar"></i> Generate Control Number</h2>
        <p class="ad-note">Weka kiasi na maelezo tu. Control number itatengenezwa na ClickPesa BillPay.</p>
        <form id="control-number-form" class="ad-form">
          <label>Order label <small>(hiari)</small>
            <input name="order_id" placeholder="Acha tupu au weka TIS01" maxlength="20" />
          </label>
          <label>Amount (TZS)<input name="amount" type="number" min="1" step="0.01" required placeholder="1000" /></label>
          <label>Description<input name="description" required placeholder="Malipo ya bidhaa / huduma" /></label>
          <label>Mode
            <select name="payment_mode">
              <option value="EXACT">EXACT</option>
              <option value="ALLOW_PARTIAL_AND_OVER_PAYMENT">ALLOW_PARTIAL_AND_OVER_PAYMENT</option>
            </select>
          </label>
          <button type="submit">Generate Control Number</button>
        </form>
        <p id="control-number-message" class="ad-msg"></p>
      </section>

      <section class="ad-card">
        <div class="ad-card-head">
          <h2>Transactions</h2>
          <button type="button" class="ad-refresh" id="cn-refresh">Refresh</button>
        </div>
        <p id="cn-tx-error" class="ad-db-banner" hidden></p>
        <div class="ad-table-wrap">
          <table class="ad-table ad-table--controls">
            <colgroup>
              <col class="ad-col-order" />
              <col class="ad-col-customer" />
              <col class="ad-col-control" />
              <col class="ad-col-ref" />
              <col class="ad-col-money" />
              <col class="ad-col-money" />
              <col class="ad-col-status" />
              <col class="ad-col-actions" />
            </colgroup>
            <thead>
              <tr>
                <th>Order</th>
                <th>Customer</th>
                <th>Control #</th>
                <th>Reference</th>
                <th>Expected</th>
                <th>Paid</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody id="cn-tx-body">
              <tr><td colspan="8">Loading…</td></tr>
            </tbody>
          </table>
        </div>
        <nav class="ad-pager" id="cn-tx-pager" hidden aria-label="Transactions pages"></nav>
      </section>
    </div>

<?php $activeNav = 'autopay'; require __DIR__ . '/wallet-bottom-nav.php'; ?>
  </main>

  <script src="tis-api-base.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
  <script src="wallet-shell.js?v=<?= urlencode($shellVersion) ?>"></script>
  <script src="control-number.js?v=<?= urlencode($pageJsVersion) ?>"></script>
</body>
</html>
