<?php
require __DIR__ . '/auth-guard.php';
$authUser = $_SESSION['gw_auth_user'] ?? [];
$authName = trim((string) ($authUser['fullName'] ?? 'Customer'));
if ($authName === '') {
    $authName = 'Customer';
}
$authEmail = trim((string) ($authUser['email'] ?? ''));
$authAvatar = trim((string) ($authUser['avatar'] ?? ''));
$authInitial = strtoupper(substr($authName, 0, 1));
if ($authInitial === '') {
    $authInitial = 'U';
}
$cssVersion = (string) (@filemtime(__DIR__ . '/part-two.css') ?: time());
$shellVersion = (string) (@filemtime(__DIR__ . '/wallet-shell.js') ?: time());
$pageJsVersion = (string) (@filemtime(__DIR__ . '/part-two.js') ?: time());
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
  <title>Getway | System</title>
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
<body class="tis-shell tis-wallet-dash layout-phone w-home-sample">
<?php $activeTopNav = 'home'; require __DIR__ . '/wallet-top-nav.php'; ?>

  <main class="tis-wrap w-shell">
    <div class="w-app">
<?php $phoneTopbarTitle = 'Home Dashboard';
if (is_file(__DIR__ . '/wallet-phone-topbar.php')) {
    require __DIR__ . '/wallet-phone-topbar.php';
}
?>

      <div class="w-hero-row w-searchable">
      <section class="w-balance-card" aria-labelledby="balance-label">
        <p id="balance-label" class="w-balance-label" data-i18n="total_balance">Total Balance</p>
        <p class="w-balance-amt" id="success-amount">TZS 0</p>
        <div class="w-balance-meta">
          <span><span data-i18n="income">Income</span> <strong id="failed-amount">TZS 0</strong></span>
          <span><span data-i18n="expense">Expense</span> <strong id="pending-transactions">0</strong></span>
        </div>
        <div class="w-balance-actions">
          <a href="payment-details.php?type=success" class="w-balance-btn">
            <i class="fa-solid fa-arrow-up-right"></i> <span data-i18n="send">Send</span>
          </a>
          <a href="create-payment.php" class="w-balance-btn">
            <i class="fa-solid fa-arrow-down"></i> <span data-i18n="request">Request</span>
          </a>
        </div>
      </section>

      <section class="w-phone-hero w-searchable" aria-label="Promotional banner">
        <div class="w-phone-hero-slider" aria-hidden="true">
          <img class="w-phone-hero-slide" src="images/get6.webp" alt="Promo 1" />
          <img class="w-phone-hero-slide" src="images/get4.webp" alt="Promo 2" />
          <img class="w-phone-hero-slide" src="images/get3.webp" alt="Promo 3" />
          <img class="w-phone-hero-slide" src="images/get2.jpg" alt="Promo 4" />
          <img class="w-phone-hero-slide" src="images/get5.webp" alt="Promo 5" />
        </div>
        <div class="w-phone-hero-overlay">
          <p data-i18n="your_wallet">Your wallet</p>
          <strong data-i18n="connected_ready">Connected and ready</strong>
        </div>
      </section>

      </div>

      <section class="w-phone-shortcuts w-searchable" aria-label="Quick actions">
        <a href="create-payment.php" class="w-phone-shortcut-card">
          <span class="w-phone-shortcut-ico"><i class="fa-solid fa-mobile-screen-button"></i></span>
          <span data-i18n="add_float">Add Float</span>
        </a>
        <a href="autopay.php" class="w-phone-shortcut-card">
          <span class="w-phone-shortcut-ico"><i class="fa-solid fa-bolt"></i></span>
          <span data-i18n="autopay">AutoPay</span>
        </a>
        <a href="payment-details.php?type=success" class="w-phone-shortcut-card">
          <span class="w-phone-shortcut-ico"><i class="fa-regular fa-user"></i></span>
          <span data-i18n="send_friend">Send to Friend</span>
        </a>
      </section>

      <div class="w-quick-row w-searchable">
        <a href="control-number.php" class="w-quick-card">
          <span class="w-quick-ico w-quick-ico--orange" aria-hidden="true"><i class="fa-solid fa-file-invoice-dollar"></i></span>
          <span class="w-quick-title">Control Number</span>
        </a>
        <a href="payment-details.php?type=success" class="w-quick-card">
          <span class="w-quick-ico w-quick-ico--violet" aria-hidden="true"><i class="fa-solid fa-building-columns"></i></span>
          <span class="w-quick-title" data-i18n="payments">Payments</span>
        </a>
        <a href="autopay.php" class="w-quick-card">
          <span class="w-quick-ico w-quick-ico--amber" aria-hidden="true"><i class="fa-solid fa-bolt"></i></span>
          <span class="w-quick-title" data-i18n="autopay">AutoPay</span>
        </a>
      </div>

      <section class="w-trend w-searchable" aria-labelledby="trend-heading">
        <div class="w-trend-head">
          <h2 id="trend-heading" data-i18n="transaction_trend">Transaction trend</h2>
          <p class="w-trend-sub" data-i18n="last_14_days">Last 14 days · count of payments &amp; new checkouts</p>
        </div>
        <div id="wallet-trend-chart" class="w-trend-chart" role="img" aria-label="Daily activity"></div>
      </section>

      <section class="w-pie-section w-searchable" aria-labelledby="pie-heading">
        <div class="w-pie-head">
          <h2 id="pie-heading">Payment analysis</h2>
          <p class="w-pie-sub">Success · Failed · Pending breakdown</p>
        </div>
        <div id="wallet-pie-chart" class="w-pie-chart" role="img" aria-label="Payment status pie chart"></div>
      </section>

      <section class="w-recent w-searchable" aria-labelledby="recent-heading">
        <div class="w-recent-head">
          <h2 id="recent-heading" data-i18n="recent_transactions">Recent transactions</h2>
          <a href="payment-details.php?type=success" class="w-recent-link" data-i18n="see_all">See all</a>
        </div>
        <ul class="w-recent-list" id="wallet-recent-list"></ul>
      </section>

      <p id="api-status" class="api-status-line w-api-line w-searchable"></p>
    </div>

<?php $activeNav = 'home'; require __DIR__ . '/wallet-bottom-nav.php'; ?>
  </main>

  <script src="tis-api-base.js"></script>
  <script src="script.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
  <script src="wallet-shell.js?v=<?= urlencode($shellVersion) ?>"></script>
  <script src="payments-merge.js?v=1"></script>
  <script src="receipt-actions.js?v=2"></script>
  <script src="part-two.js?v=<?= urlencode($pageJsVersion) ?>"></script>
</body>
</html>
