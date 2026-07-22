<?php
require __DIR__ . '/admin-guard.php';
$authUser = $_SESSION['gw_auth_user'] ?? [];
$authName = trim((string) ($authUser['fullName'] ?? 'Admin'));
$cssV = (string) (@filemtime(__DIR__ . '/admin-dashboard.css') ?: time());
$jsV = (string) (@filemtime(__DIR__ . '/admin-dashboard.js') ?: time());
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
  <link rel="stylesheet" href="admin-dashboard.css?v=<?= urlencode($cssV) ?>" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" />
</head>
<body class="ad-body">
  <header class="ad-top">
    <div>
      <p class="ad-eyebrow">Getway Admin</p>
      <h1>Payout &amp; Collections</h1>
    </div>
    <div class="ad-top-actions">
      <span class="ad-user"><?= htmlspecialchars($authName, ENT_QUOTES) ?></span>
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
      <article class="ad-stat">
        <p>Success</p>
        <strong id="stat-success">0</strong>
      </article>
      <article class="ad-stat">
        <p>Pending</p>
        <strong id="stat-pending">0</strong>
      </article>
      <article class="ad-stat">
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
      <div class="ad-card">
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

      <div class="ad-card">
        <h2>Create control number</h2>
        <form id="ad-cn-form" class="ad-form">
          <label>Order ID<input name="order_id" required placeholder="TIS01" maxlength="20" pattern="[A-Za-z0-9]+" title="Letters and numbers only, max 20 characters (e.g. TIS01, ORDER1001)" /></label>
          <label>Amount (TZS)<input name="amount" type="number" min="1" step="0.01" required /></label>
          <label>Description<input name="description" required placeholder="Payment for order…" /></label>
          <label>Mode
            <select name="payment_mode">
              <option value="EXACT">EXACT</option>
              <option value="ALLOW_PARTIAL_AND_OVER_PAYMENT">ALLOW_PARTIAL_AND_OVER_PAYMENT</option>
            </select>
          </label>
          <button type="submit">Create Control Number</button>
        </form>
        <p id="ad-cn-msg" class="ad-msg"></p>
      </div>
    </section>

    <section class="ad-card">
      <div class="ad-card-head">
        <h2>Control numbers</h2>
        <div class="ad-top-actions">
          <button type="button" class="ad-refresh" id="ad-balance-refresh">Refresh Balance</button>
          <button type="button" class="ad-refresh" id="ad-refresh">Refresh</button>
        </div>
      </div>
      <p id="ad-controls-error" class="ad-db-banner" hidden></p>
      <div class="ad-table-wrap">
        <table class="ad-table">
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
    </section>

    <section class="ad-card">
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

    <section class="ad-card">
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
    </section>

    <section class="ad-card">
      <div class="ad-card-head">
        <h2>Recent collections</h2>
        <small id="ad-recent-period" class="ad-period-sub">All time</small>
      </div>
      <p id="ad-recent-error" class="ad-db-banner" hidden></p>
      <ul class="ad-recent" id="ad-recent"></ul>
    </section>
  </main>

  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
  <script src="admin-dashboard.js?v=<?= urlencode($jsV) ?>"></script>
</body>
</html>
