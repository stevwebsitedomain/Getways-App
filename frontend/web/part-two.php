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
$bkVersion = (string) (@filemtime(__DIR__ . '/wallet-banking-theme.css') ?: time());
$shellVersion = (string) (@filemtime(__DIR__ . '/wallet-shell.js') ?: time());
$pageJsVersion = (string) (@filemtime(__DIR__ . '/part-two.js') ?: time());
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
  <title>Getway | Home</title>
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
  <link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"
  />
</head>
<body class="tis-shell tis-wallet-dash layout-phone w-home-sample bk-theme bk-home">
<?php $activeTopNav = 'home'; require __DIR__ . '/wallet-top-nav.php'; ?>

  <main class="tis-wrap w-shell">
    <div class="w-app">

      <!-- User header (Screen 7) -->
      <section class="bk-user-header w-searchable" aria-label="User profile">
        <?php if ($authAvatar !== ''): ?>
          <img class="bk-user-avatar" src="<?= htmlspecialchars($authAvatar, ENT_QUOTES) ?>" alt="" />
        <?php else: ?>
          <span class="bk-user-avatar bk-user-avatar--fallback" aria-hidden="true"><?= htmlspecialchars($authInitial, ENT_QUOTES) ?></span>
        <?php endif; ?>
        <div class="bk-user-info">
          <h2><?= htmlspecialchars($authName, ENT_QUOTES) ?></h2>
          <p><i class="fa-solid fa-location-dot"></i> Tanzania</p>
        </div>
        <div class="bk-user-actions">
          <button type="button" class="bk-icon-btn" aria-label="Notifications" data-top-action="history">
            <i class="fa-regular fa-bell"></i>
          </button>
          <button type="button" class="bk-icon-btn" aria-label="Search" data-search-toggle>
            <i class="fa-solid fa-magnifying-glass"></i>
          </button>
          <a href="settings.php" class="bk-icon-btn" aria-label="Profile">
            <i class="fa-solid fa-gear"></i>
          </a>
        </div>
      </section>

      <!-- Balance (Screen 7) -->
      <section class="bk-balance-section w-searchable" aria-labelledby="balance-label">
        <p id="balance-label" class="bk-balance-label" data-i18n="total_balance">Total Balance</p>
        <div class="bk-balance-row">
          <p class="bk-balance-amt" id="success-amount">TZS 0</p>
          <div class="bk-balance-quick">
            <a href="create-payment.php" class="bk-icon-btn" aria-label="Add payment" title="Top-up">
              <i class="fa-solid fa-plus"></i>
            </a>
          </div>
        </div>
      </section>

      <!-- Virtual card (Screen 7) -->
      <section class="bk-virtual-card w-searchable" aria-label="Wallet card">
        <div class="bk-card-chip" aria-hidden="true"></div>
        <div class="bk-card-number">**** **** **** 4582</div>
        <div class="bk-card-footer">
          <div class="bk-card-holder">
            Card holder
            <strong><?= htmlspecialchars($authName, ENT_QUOTES) ?></strong>
          </div>
          <div class="bk-card-brand">VISA</div>
        </div>
      </section>

      <!-- Quick actions: Top-up, Payment, Send (Screen 5) -->
      <section class="bk-quick-actions w-searchable" aria-label="Quick actions">
        <a href="create-payment.php" class="bk-quick-action">
          <span class="bk-quick-action-ico"><i class="fa-solid fa-arrow-down"></i></span>
          <span data-i18n="top_up">Top-up</span>
        </a>
        <a href="create-payment.php" class="bk-quick-action">
          <span class="bk-quick-action-ico"><i class="fa-solid fa-credit-card"></i></span>
          <span data-i18n="payment">Payment</span>
        </a>
        <a href="payment-details.php?type=success" class="bk-quick-action">
          <span class="bk-quick-action-ico"><i class="fa-solid fa-paper-plane"></i></span>
          <span data-i18n="send">Send</span>
        </a>
      </section>

      <!-- Nav list: Pay, AutoPay, Transactions -->
      <nav class="bk-nav-list w-searchable" aria-label="Wallet navigation">
        <a href="create-payment.php" class="bk-nav-list-item">
          <i class="fa-solid fa-wallet"></i>
          <span data-i18n="pay">Pay</span>
          <i class="fa-solid fa-chevron-right"></i>
        </a>
        <a href="autopay.php" class="bk-nav-list-item">
          <i class="fa-solid fa-bolt"></i>
          <span data-i18n="autopay">AutoPay</span>
          <i class="fa-solid fa-chevron-right"></i>
        </a>
        <a href="payment-details.php?type=success" class="bk-nav-list-item">
          <i class="fa-solid fa-clock-rotate-left"></i>
          <span data-i18n="transactions">Transactions</span>
          <i class="fa-solid fa-chevron-right"></i>
        </a>
      </nav>

      <!-- Income / Expense stats (Screen 8) -->
      <section class="bk-stats-ring w-searchable" aria-label="Income and expenses">
        <div class="bk-stat-tile bk-stat-tile--income">
          <div class="bk-stat-tile-label"><span class="bk-stat-tile-dot"></span> <span data-i18n="income">Income</span></div>
          <p class="bk-stat-tile-value" id="failed-amount">TZS 0</p>
        </div>
        <div class="bk-stat-tile bk-stat-tile--expense">
          <div class="bk-stat-tile-label"><span class="bk-stat-tile-dot"></span> <span data-i18n="expense">Expense</span></div>
          <p class="bk-stat-tile-value" id="pending-transactions">0</p>
        </div>
      </section>

      <!-- Services menu (Screen 3) -->
      <section class="bk-services w-searchable" aria-labelledby="services-heading">
        <h2 id="services-heading" class="bk-section-title" data-i18n="app_services">Convenient services in the app</h2>
        <div class="bk-services-list">
          <a href="create-payment.php" class="bk-service-item">
            <span class="bk-service-ico bk-service-ico--blue"><i class="fa-solid fa-hand-holding-dollar"></i></span>
            <div class="bk-service-text">
              <h3 data-i18n="credits">Credits</h3>
              <p data-i18n="credits_desc">Apply for a loan online</p>
            </div>
          </a>
          <a href="control-number.php" class="bk-service-item">
            <span class="bk-service-ico bk-service-ico--green"><i class="fa-solid fa-piggy-bank"></i></span>
            <div class="bk-service-text">
              <h3 data-i18n="deposit">Deposit</h3>
              <p data-i18n="deposit_desc">Open a deposit account</p>
            </div>
          </a>
          <a href="autopay.php" class="bk-service-item">
            <span class="bk-service-ico bk-service-ico--purple"><i class="fa-solid fa-bolt"></i></span>
            <div class="bk-service-text">
              <h3 data-i18n="autopay">AutoPay</h3>
              <p data-i18n="autopay_desc">USSD push &amp; auto receipt</p>
            </div>
          </a>
          <a href="payment-details.php?type=success" class="bk-service-item">
            <span class="bk-service-ico bk-service-ico--orange"><i class="fa-solid fa-rotate-left"></i></span>
            <div class="bk-service-text">
              <h3 data-i18n="cashback">Cashback</h3>
              <p data-i18n="cashback_desc">Get rewards on purchases</p>
            </div>
          </a>
          <a href="create-payment.php" class="bk-service-item">
            <span class="bk-service-ico bk-service-ico--teal"><i class="fa-solid fa-right-left"></i></span>
            <div class="bk-service-text">
              <h3 data-i18n="exchange">Exchange</h3>
              <p data-i18n="exchange_desc">Currency exchange rates</p>
            </div>
          </a>
        </div>
      </section>

      <!-- Transaction trend chart (Screen 10) -->
      <section class="w-trend w-searchable" aria-labelledby="trend-heading">
        <div class="w-trend-head">
          <h2 id="trend-heading" data-i18n="transaction_trend">Transaction trend</h2>
          <p class="w-trend-sub" data-i18n="last_14_days">Last 14 days</p>
        </div>
        <div class="bk-chart-tabs" role="tablist" aria-label="Chart period">
          <button type="button" class="bk-chart-tab" data-period="1">1D</button>
          <button type="button" class="bk-chart-tab" data-period="7">1W</button>
          <button type="button" class="bk-chart-tab is-active" data-period="14">1M</button>
          <button type="button" class="bk-chart-tab" data-period="90">3M</button>
          <button type="button" class="bk-chart-tab" data-period="180">6M</button>
          <button type="button" class="bk-chart-tab" data-period="365">All</button>
        </div>
        <div id="wallet-trend-chart" class="w-trend-chart" role="img" aria-label="Daily activity"></div>
      </section>

      <!-- Payment analysis pie -->
      <section class="w-pie-section w-searchable" aria-labelledby="pie-heading">
        <div class="w-pie-head">
          <h2 id="pie-heading">Payment analysis</h2>
          <p class="w-pie-sub">Success · Failed · Pending</p>
        </div>
        <div id="wallet-pie-chart" class="w-pie-chart" role="img" aria-label="Payment status pie chart"></div>
      </section>

      <!-- Recent transactions (Screen 12) -->
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
