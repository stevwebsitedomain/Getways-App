<?php
declare(strict_types=1);

try {
    require __DIR__ . '/admin-guard.php';
} catch (Throwable $e) {
    error_log('Getway admin-dashboard guard failed: ' . $e->getMessage());
    http_response_code(500);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8" /><title>Admin error</title></head><body style="font-family:sans-serif;padding:24px">'
        . '<h1>Admin dashboard unavailable</h1>'
        . '<p>Please try again or <a href="logout.php">logout</a> and sign in again.</p>'
        . '</body></html>';
    exit;
}

$authUser = $_SESSION['gw_auth_user'] ?? [];
$authName = htmlspecialchars(trim((string) ($authUser['fullName'] ?? 'Admin')), ENT_QUOTES);
$gaBgUrl = 'https://images.unsplash.com/photo-1556742049-0cfed4f6a45d?auto=format&fit=crop&w=1600&q=80';
foreach (['images/payments-bg.jpg', 'login-bg.jpg', 'images/login.jpg', 'images/get2.jpg'] as $gaBgRel) {
    $gaBgPath = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $gaBgRel);
    if (is_file($gaBgPath)) {
        $gaBgUrl = $gaBgRel;
        break;
    }
}
$gaBgUrl = htmlspecialchars($gaBgUrl, ENT_QUOTES);
$cssV = (string) (@filemtime(__DIR__ . '/admin-dashboard.css') ?: time());
$jsV = (string) (@filemtime(__DIR__ . '/admin-dashboard.js') ?: time());
$robotCssV = (string) (@filemtime(__DIR__ . '/ai-robot.css') ?: time());
$robotJsV = (string) (@filemtime(__DIR__ . '/ai-robot.js') ?: time());
$cssV = htmlspecialchars($cssV, ENT_QUOTES);
$jsV = htmlspecialchars($jsV, ENT_QUOTES);
$robotCssV = htmlspecialchars($robotCssV, ENT_QUOTES);
$robotJsV = htmlspecialchars($robotJsV, ENT_QUOTES);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
  <title>Getway | Admin Dashboard</title>
  <link rel="icon" type="image/png" href="images/favicon.png" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
  <link rel="stylesheet" href="admin-dashboard.css?v=<?php echo $cssV; ?>" />
  <link rel="stylesheet" href="ai-robot.css?v=<?php echo htmlspecialchars($robotCssV, ENT_QUOTES); ?>" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" />
</head>
<body class="ad-body">
  <header class="ad-top">
    <div>
      <p class="ad-eyebrow">Getway Admin</p>
      <h1>Payout &amp; Collections</h1>
    </div>
    <div class="ad-top-actions">
      <button type="button" class="ad-ga-open" id="ad-ga-open">
        <i class="fa-solid fa-circle-nodes" aria-hidden="true"></i>
        <span>General Analysis</span>
      </button>
      <span class="ad-user"><?php echo $authName; ?></span>
      <a class="ad-link" href="part-two.php">User wallet</a>
      <a class="ad-link ad-link--danger" href="logout.php">Logout</a>
    </div>
  </header>

  <main class="ad-main">
    <p id="ad-db-banner" class="ad-db-banner" hidden></p>

    <section class="ad-stats" id="ad-stats">
      <article class="ad-stat ad-stat--money">
        <p>Available ClickPesa Balance</p>
        <strong id="stat-balance">Loading...</strong>
        <small id="stat-balance-updated">Last updated: --</small>
      </article>
      <article class="ad-stat ad-stat--money">
        <p>Money in (paid)</p>
        <strong id="stat-incoming">TZS 0</strong>
        <small id="stat-incoming-period">All time</small>
      </article>
      <article class="ad-stat ad-stat--compact">
        <p>Success</p>
        <strong id="stat-success">0</strong>
      </article>
      <article class="ad-stat ad-stat--compact">
        <p>Pending</p>
        <strong id="stat-pending">0</strong>
      </article>
      <article class="ad-stat ad-stat--compact">
        <p>Failed</p>
        <strong id="stat-failed">0</strong>
      </article>
      <article class="ad-stat ad-stat--toggle" id="stat-auto-card" role="button" tabindex="0" title="Bofya kubadilisha auto payout">
        <p>Auto payout <span class="ad-hint">(bofya)</span></p>
        <strong id="stat-auto" class="ad-auto-off">OFF</strong>
        <small id="stat-auto-mode">TEST</small>
      </article>
      <article class="ad-stat">
        <p>Destination</p>
        <strong id="stat-dest">2557******92</strong>
      </article>
    </section>

    <section class="ad-grid">
      <div class="ad-card" id="ad-section-analytics">
        <div class="ad-card-head ad-card-head--stack">
          <div>
            <h2>Payment analysis</h2>
            <p class="ad-period-sub" id="ad-period-label">All time · historical records</p>
          </div>
          <div class="ad-card-actions">
            <select id="ad-period-select" class="ad-period-select" aria-label="Analysis period">
              <option value="all" selected>All time</option>
              <option value="90d">Last 90 days</option>
              <option value="30d">Last 30 days</option>
              <option value="month">This month</option>
            </select>
            <button type="button" class="ad-refresh" id="ad-sync-transactions">Sync ClickPesa Transactions</button>
          </div>
        </div>
        <p id="ad-statement-error" class="ad-db-banner" hidden></p>
        <div class="ad-trend-wrap">
          <p class="ad-trend-title">Transaction trend · last 14 days</p>
          <div id="ad-trend" class="ad-trend" role="img" aria-label="Daily transaction trend"></div>
        </div>
        <div id="ad-pie" class="ad-pie" role="img" aria-label="Payment pie chart"></div>
      </div>

      <div class="ad-card" id="ad-section-control-number">
        <h2>Create control number</h2>
        <p class="ad-note">Weka kiasi na maelezo tu. <strong>Control number</strong> itatengenezwa na ClickPesa BillPay — hauandiki mwenyewe.</p>
        <form id="ad-cn-form" class="ad-form">
          <label>Order label <small>(si control number — hiari)</small>
            <input name="order_id" placeholder="Acha tupu au weka TIS01" maxlength="20" pattern="[A-Za-z0-9]*" title="Herufi na namba tu (hiari)" />
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
        <p id="ad-cn-msg" class="ad-msg"></p>
      </div>
    </section>

    <section class="ad-card" id="ad-section-transactions">
      <div class="ad-card-head">
        <h2>Transactions</h2>
        <div class="ad-top-actions">
          <button type="button" class="ad-refresh" id="ad-balance-refresh">Refresh Balance</button>
          <button type="button" class="ad-refresh" id="ad-refresh">Refresh</button>
        </div>
      </div>
      <p id="ad-controls-error" class="ad-db-banner" hidden></p>
      <div class="ad-table-wrap">
        <table class="ad-table ad-table--controls">
          <colgroup>
            <col class="ad-col-order" />
            <col class="ad-col-customer" />
            <col class="ad-col-control" />
            <col class="ad-col-ref" />
            <col class="ad-col-money" />
            <col class="ad-col-money" />
            <col class="ad-col-withdraw" />
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
              <th>Withdraw</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody id="ad-controls-body">
            <tr><td colspan="9">Loading…</td></tr>
          </tbody>
        </table>
      </div>
      <nav class="ad-pager" id="ad-controls-pager" hidden aria-label="Transactions pages"></nav>
    </section>

    <section class="ad-card" id="ad-section-payout-dest">
      <div class="ad-card-head">
        <h2>Payout destination</h2>
      </div>
      <form id="ad-payout-form" class="ad-form">
        <label>Payout phone number
          <input name="mobileMoneyNumber" type="tel" value="+255715296092" required placeholder="+255715296092" />
        </label>
        <label>Payout mode
          <select name="payoutMode" id="ad-payout-mode">
            <option value="MANUAL_APPROVAL">Manual — Withdraw button per payment</option>
            <option value="LIVE_AUTO">Automatic — send to destination when paid</option>
          </select>
        </label>
        <button type="submit">Save destination</button>
      </form>
      <p id="ad-payout-msg" class="ad-msg"></p>
    </section>

    <section class="ad-card" id="ad-section-payouts">
      <div class="ad-card-head">
        <h2>Automatic payouts</h2>
        <button type="button" class="ad-refresh" id="ad-payouts-refresh">Refresh</button>
      </div>
      <p class="ad-note">Configure the real destination in settings. Only the masked destination is shown here.</p>
      <p id="ad-payouts-error" class="ad-db-banner" hidden></p>
      <div class="ad-table-wrap">
        <table class="ad-table">
          <thead>
            <tr>
              <th>Payout ref</th>
              <th>Dest</th>
              <th>Amount</th>
              <th>Fee</th>
              <th>Status</th>
              <th>Provider</th>
              <th>Error</th>
              <th>Updated</th>
            </tr>
          </thead>
          <tbody id="ad-payouts-body">
            <tr><td colspan="8">Loading…</td></tr>
          </tbody>
        </table>
      </div>
      <nav class="ad-pager" id="ad-payouts-pager" hidden aria-label="Payout pages"></nav>
    </section>

    <section class="ad-card" id="ad-section-users">
      <div class="ad-card-head">
        <h2>Registered users</h2>
        <button type="button" class="ad-refresh" id="ad-users-refresh">Refresh</button>
      </div>
      <p id="ad-users-error" class="ad-db-banner" hidden></p>
      <div class="ad-table-wrap">
        <table class="ad-table ad-table--users">
          <thead>
            <tr>
              <th>Name</th>
              <th>Phone</th>
              <th>Username</th>
              <th>Role</th>
              <th>Joined</th>
            </tr>
          </thead>
          <tbody id="ad-users-body">
            <tr><td colspan="5">Loading…</td></tr>
          </tbody>
        </table>
      </div>
      <nav class="ad-pager" id="ad-users-pager" hidden aria-label="Users pages"></nav>
    </section>

    <section class="ad-card" id="ad-section-recent">
      <div class="ad-card-head">
        <h2>Recent collections</h2>
        <small id="ad-recent-period" class="ad-period-sub">All time</small>
      </div>
      <p id="ad-recent-error" class="ad-db-banner" hidden></p>
      <ul class="ad-recent" id="ad-recent"></ul>
      <nav class="ad-pager" id="ad-recent-pager" hidden aria-label="Recent collections pages"></nav>
    </section>
  </main>

  <div id="ad-ga-overlay" class="ad-ga" hidden aria-hidden="true" style="--ad-ga-bg: url('<?php echo $gaBgUrl; ?>');">
    <div class="ad-ga-bg" aria-hidden="true"></div>
    <header class="ad-ga-top">
      <button type="button" class="ad-ga-back" id="ad-ga-close" aria-label="Back to dashboard">
        <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
        Back
      </button>
      <h2>General Analysis</h2>
      <span class="ad-ga-top-spacer" aria-hidden="true"></span>
    </header>

    <div class="ad-ga-stage">
      <div class="ad-ga-glow ad-ga-glow--a" aria-hidden="true"></div>
      <div class="ad-ga-glow ad-ga-glow--b" aria-hidden="true"></div>

      <div class="ad-ga-orbit-system">
        <svg class="ad-ga-spokes" viewBox="0 0 400 400" aria-hidden="true">
          <circle cx="200" cy="200" r="148" class="ad-ga-orbit-line" />
          <g class="ad-ga-spoke-group">
            <line x1="200" y1="200" x2="200" y2="52" class="ad-ga-spoke" />
            <line x1="200" y1="200" x2="328" y2="126" class="ad-ga-spoke" />
            <line x1="200" y1="200" x2="328" y2="274" class="ad-ga-spoke" />
            <line x1="200" y1="200" x2="200" y2="348" class="ad-ga-spoke" />
            <line x1="200" y1="200" x2="72" y2="274" class="ad-ga-spoke" />
            <line x1="200" y1="200" x2="72" y2="126" class="ad-ga-spoke" />
          </g>
        </svg>

        <div class="ad-ga-orbit" id="ad-ga-orbit">
          <button type="button" class="ad-ga-satellite ad-ga-satellite--money" data-ga-target="transactions" data-ga-action="scroll" style="--angle: 0deg" aria-label="Transactions">
            <span class="ad-ga-satellite-inner">
              <span class="ad-ga-icon-ring">
                <span class="ad-ga-icon-ring-inner"><i class="fa-solid fa-dollar-sign"></i></span>
              </span>
              <span class="ad-ga-sat-label">Transactions</span>
            </span>
          </button>
          <button type="button" class="ad-ga-satellite ad-ga-satellite--lock" data-ga-target="payout-dest" data-ga-action="scroll" style="--angle: 60deg" aria-label="Payout security">
            <span class="ad-ga-satellite-inner">
              <span class="ad-ga-icon-ring">
                <span class="ad-ga-icon-ring-inner"><i class="fa-solid fa-lock"></i></span>
              </span>
              <span class="ad-ga-sat-label">Security</span>
            </span>
          </button>
          <button type="button" class="ad-ga-satellite ad-ga-satellite--wifi" data-ga-target="sync" data-ga-action="sync" style="--angle: 120deg" aria-label="Sync ClickPesa">
            <span class="ad-ga-satellite-inner">
              <span class="ad-ga-icon-ring">
                <span class="ad-ga-icon-ring-inner"><i class="fa-solid fa-wifi"></i></span>
              </span>
              <span class="ad-ga-sat-label">Sync</span>
            </span>
          </button>
          <button type="button" class="ad-ga-satellite ad-ga-satellite--chart" data-ga-target="analytics" data-ga-action="scroll" style="--angle: 180deg" aria-label="Payment analysis">
            <span class="ad-ga-satellite-inner">
              <span class="ad-ga-icon-ring">
                <span class="ad-ga-icon-ring-inner"><i class="fa-solid fa-chart-line"></i></span>
              </span>
              <span class="ad-ga-sat-label">Analysis</span>
            </span>
          </button>
          <button type="button" class="ad-ga-satellite ad-ga-satellite--cloud" data-ga-target="autopay" data-ga-action="scroll" style="--angle: 240deg" aria-label="Autopay">
            <span class="ad-ga-satellite-inner">
              <span class="ad-ga-icon-ring">
                <span class="ad-ga-icon-ring-inner"><i class="fa-solid fa-cloud"></i></span>
              </span>
              <span class="ad-ga-sat-label">Autopay</span>
            </span>
          </button>
          <button type="button" class="ad-ga-satellite ad-ga-satellite--bank" data-ga-target="control-number" data-ga-action="scroll" style="--angle: 300deg" aria-label="Control number">
            <span class="ad-ga-satellite-inner">
              <span class="ad-ga-icon-ring">
                <span class="ad-ga-icon-ring-inner"><i class="fa-solid fa-building-columns"></i></span>
              </span>
              <span class="ad-ga-sat-label">Control #</span>
            </span>
          </button>
        </div>

        <button type="button" class="ad-ga-hub" id="ad-ga-hub" aria-label="Admin hub overview">
          <span class="ad-ga-hub-glow" aria-hidden="true"></span>
          <span class="ad-ga-hub-ring ad-ga-hub-ring--outer" aria-hidden="true"></span>
          <span class="ad-ga-hub-ring ad-ga-hub-ring--inner" aria-hidden="true"></span>
          <span class="ad-ga-hub-core">
            <span class="ad-ga-hub-brand" aria-hidden="true">
              <i class="fa-solid fa-building-columns"></i>
              <i class="fa-solid fa-dollar-sign ad-ga-hub-dollar"></i>
            </span>
            <strong>Getway Admin</strong>
            <small id="ad-ga-hub-balance">Loading...</small>
            <span class="ad-ga-hub-pill" id="ad-ga-hub-auto">Auto payout OFF</span>
          </span>
        </button>
      </div>

      <p class="ad-ga-hint">Bofya ikoni ili kufungua sehemu husika · Ikoni zinazunguka kiotomatiki</p>

      <div class="ad-ga-extra">
        <button type="button" class="ad-ga-chip" data-ga-target="payouts" data-ga-action="scroll">
          <i class="fa-solid fa-money-bill-transfer"></i> Payouts
        </button>
        <button type="button" class="ad-ga-chip" data-ga-target="users" data-ga-action="scroll">
          <i class="fa-solid fa-users"></i> Users
        </button>
        <button type="button" class="ad-ga-chip" data-ga-target="recent" data-ga-action="scroll">
          <i class="fa-solid fa-receipt"></i> Collections
        </button>
        <a class="ad-ga-chip ad-ga-chip--link" href="autopay.php">
          <i class="fa-solid fa-bolt"></i> Autopay page
        </a>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
  <script src="admin-dashboard.js?v=<?php echo $jsV; ?>"></script>
  <script src="ai-robot.js?v=<?php echo htmlspecialchars($robotJsV, ENT_QUOTES); ?>"></script>
</body>
</html>
