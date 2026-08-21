<?php
require __DIR__ . '/auth-guard.php';
$authUser = $_SESSION['gw_auth_user'] ?? [];
$authName = trim((string) ($authUser['fullName'] ?? 'Customer'));
if ($authName === '') {
    $authName = 'Customer';
}
$authFirst = trim((string) (preg_split('/\s+/', $authName)[0] ?? $authName));
if ($authFirst === '') {
    $authFirst = 'Customer';
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
<body class="tis-shell tis-wallet-dash layout-phone w-home-sample bk-theme bk-home chase-home">
<?php $activeTopNav = 'home'; require __DIR__ . '/wallet-top-nav.php'; ?>

  <main class="tis-wrap w-shell">
    <div class="w-app">

      <!-- Chase-style blue hero -->
      <header class="ch-hero w-searchable" aria-label="Welcome">
        <div class="ch-hero-top">
          <a href="settings.php" class="ch-hero-icon" aria-label="Profile">
            <i class="fa-solid fa-user-gear"></i>
          </a>
          <div class="ch-hero-top-right">
            <a href="create-payment.php" class="ch-hero-icon" aria-label="Pay">
              <i class="fa-solid fa-cart-shopping"></i>
            </a>
            <a href="payment-details.php?type=success" class="ch-hero-icon" aria-label="Help &amp; history">
              <i class="fa-regular fa-circle-question"></i>
            </a>
          </div>
        </div>
        <h1 class="ch-hero-greet">Hello, <?= htmlspecialchars($authFirst, ENT_QUOTES) ?></h1>

        <article class="ch-promo-card" aria-label="Security notice">
          <div class="ch-promo-ico" aria-hidden="true">
            <i class="fa-solid fa-shield-halved"></i>
          </div>
          <div class="ch-promo-body">
            <h2>We're Enhancing Your Security</h2>
            <p>
              Use one-time passcodes and keep your Getway account safe.
              <a href="settings.php">Learn More</a>
            </p>
          </div>
        </article>
        <div class="ch-promo-dots" aria-hidden="true">
          <span class="is-active"></span>
          <span></span>
          <span></span>
        </div>
      </header>

      <div class="ch-body">

        <!-- Merchant / wallet sales card -->
        <section class="ch-acct-card w-searchable" aria-label="Merchant accounts">
          <div class="ch-acct-head">
            <span class="ch-acct-head-left">
              <i class="fa-solid fa-thumbtack"></i>
              Merchant Accounts
            </span>
            <button type="button" class="ch-acct-toggle" aria-label="Collapse" hidden>
              <i class="fa-solid fa-chevron-up"></i>
            </button>
          </div>
          <div class="ch-acct-body">
            <a href="create-payment.php" class="ch-acct-name">Getway Wallet · Mobile</a>
            <div class="ch-acct-row">
              <div class="ch-acct-main">
                <div class="ch-acct-balance-line">
                  <p class="ch-acct-balance" id="success-amount" data-amount-visible="1">TZS 0</p>
                  <button
                    type="button"
                    class="ch-amt-toggle"
                    id="merchant-amount-toggle"
                    aria-pressed="false"
                    aria-controls="success-amount"
                    aria-label="Hide amount"
                    title="Hide amount"
                  >
                    <i class="fa-solid fa-eye-slash" aria-hidden="true"></i>
                  </button>
                </div>
                <p class="ch-acct-label">Daily Net Sales</p>
                <p class="ch-acct-meta">
                  Gross <span id="failed-amount">TZS 0</span>
                  · Pending <span id="pending-transactions">0</span>
                </p>
              </div>
              <button type="button" class="ch-acct-more" aria-label="More options" data-top-action="history">
                <i class="fa-solid fa-ellipsis-vertical"></i>
              </button>
            </div>
            <p class="ch-acct-deposit">Depositing to Business Wallet</p>
            <div class="ch-acct-actions">
              <a href="payment-details.php?type=success" class="ch-fab-orange" aria-label="View history">
                <i class="fa-solid fa-chevron-right"></i>
              </a>
              <a href="create-payment.php" class="ch-btn-accept">Accept Payment</a>
            </div>
          </div>
        </section>

        <!-- Deposit / available balance -->
        <section class="ch-acct-card w-searchable" aria-label="Deposit accounts">
          <div class="ch-acct-head">
            <span class="ch-acct-head-left">Deposit Accounts</span>
            <button type="button" class="ch-acct-toggle" aria-label="Collapse" hidden>
              <i class="fa-solid fa-chevron-up"></i>
            </button>
          </div>
          <div class="ch-acct-body">
            <a href="control-number.php" class="ch-acct-name">Business Checking · Getway</a>
            <div class="ch-acct-row">
              <div>
                <p class="ch-acct-balance" id="deposit-available">—</p>
                <p class="ch-acct-label">Available Balance</p>
              </div>
              <a href="settings.php" class="ch-acct-more" aria-label="Account options">
                <i class="fa-solid fa-ellipsis-vertical"></i>
              </a>
            </div>
          </div>
        </section>

        <!-- Open an account / services -->
        <section class="ch-open w-searchable" aria-labelledby="open-heading">
          <div class="ch-open-head">
            <h2 id="open-heading">Open an Account</h2>
            <a href="control-number.php" class="ch-view-all">View All</a>
          </div>
          <div class="ch-open-grid">
            <a href="create-payment.php" class="ch-open-item">
              <span class="ch-open-ico"><i class="fa-solid fa-money-check"></i></span>
              <span>Business Checking</span>
            </a>
            <a href="autopay.php" class="ch-open-item">
              <span class="ch-open-ico"><i class="fa-solid fa-hand-holding-dollar"></i></span>
              <span>Business Loan</span>
            </a>
            <a href="create-payment.php" class="ch-open-item">
              <span class="ch-open-ico"><i class="fa-regular fa-credit-card"></i></span>
              <span>Business Credit Card</span>
            </a>
            <a href="control-number.php" class="ch-open-item">
              <span class="ch-open-ico"><i class="fa-solid fa-store"></i></span>
              <span>Merchant Services</span>
            </a>
          </div>
        </section>

        <!-- Keep analytics / recent for data continuity -->
        <section class="w-trend w-searchable ch-panel" aria-labelledby="trend-heading">
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

        <section class="w-pie-section w-searchable ch-panel" aria-labelledby="pie-heading">
          <div class="w-pie-head">
            <h2 id="pie-heading">Payment analysis</h2>
            <p class="w-pie-sub">Success · Failed · Pending</p>
          </div>
          <div id="wallet-pie-chart" class="w-pie-chart" role="img" aria-label="Payment status pie chart"></div>
        </section>

        <section class="w-recent w-searchable ch-panel" aria-labelledby="recent-heading">
          <div class="w-recent-head">
            <h2 id="recent-heading" data-i18n="recent_transactions">Recent transactions</h2>
            <a href="payment-details.php?type=success" class="w-recent-link" data-i18n="see_all">See all</a>
          </div>
          <ul class="w-recent-list" id="wallet-recent-list"></ul>
        </section>

        <p id="api-status" class="api-status-line w-api-line w-searchable"></p>
      </div>
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
  <script>
    (function () {
      var src = document.getElementById("success-amount");
      var dest = document.getElementById("deposit-available");
      if (!src || !dest) return;
      var sync = function () {
        dest.textContent = src.getAttribute("data-amount-raw") || src.textContent;
      };
      sync();
      new MutationObserver(sync).observe(src, { childList: true, characterData: true, subtree: true, attributes: true, attributeFilter: ["data-amount-raw"] });
    })();
  </script>
</body>
</html>
