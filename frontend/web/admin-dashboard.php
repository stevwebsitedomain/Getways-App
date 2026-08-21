<?php
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

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
$cssV = htmlspecialchars($cssV, ENT_QUOTES);
$jsV = htmlspecialchars($jsV, ENT_QUOTES);

require_once __DIR__ . '/env-load.php';
$waConfig = function_exists('gwUltamsgConfig') ? gwUltamsgConfig() : [
    'senderName' => 'Digital Matrix Technology',
    'webhookUrl' => 'https://getway.legitconsult.co.tz/whatsapp-webhook.php',
];
$waSender = htmlspecialchars((string) ($waConfig['senderName'] ?? 'Digital Matrix Technology'), ENT_QUOTES);
$waWebhook = htmlspecialchars((string) ($waConfig['webhookUrl'] ?? 'https://getway.legitconsult.co.tz/whatsapp-webhook.php'), ENT_QUOTES);
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
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" />
  <!-- Portal layout v2 — inline so production cannot show stale dark dashboard -->
  <style id="ad-portal-critical">
    body.ad-body.ad-portal{background:#f0f4f8!important;color:#1a1a2e!important;background-image:none!important;overflow:hidden!important}
    body.ad-body.ad-portal .ad-top{display:none!important}
    body.ad-body.ad-portal .ad-stats:not(.ad-stats--hidden){display:none!important}
    body.ad-body.ad-portal .ad-detail-sections.is-collapsed{display:none!important}
    body.ad-body.ad-portal.ad-view-detail .ad-portal-home{display:none!important}
    body.ad-body.ad-portal.ad-view-detail .ad-detail-sections{display:grid!important}
    body.ad-body.ad-portal.ad-view-detail .ad-page-section{display:none!important}
    body.ad-body.ad-portal.ad-view-detail[data-ad-section="analytics"] #ad-section-analytics,
    body.ad-body.ad-portal.ad-view-detail[data-ad-section="control-number"] #ad-section-control-number,
    body.ad-body.ad-portal.ad-view-detail[data-ad-section="transactions"] #ad-section-transactions,
    body.ad-body.ad-portal.ad-view-detail[data-ad-section="payout-dest"] #ad-section-payout-dest,
    body.ad-body.ad-portal.ad-view-detail[data-ad-section="payouts"] #ad-section-payouts,
    body.ad-body.ad-portal.ad-view-detail[data-ad-section="users"] #ad-section-users,
    body.ad-body.ad-portal.ad-view-detail[data-ad-section="recent"] #ad-section-recent,
    body.ad-body.ad-portal.ad-view-detail[data-ad-section="whatsapp"] #ad-section-whatsapp{display:block!important}
    .ad-charts-row{display:grid;grid-template-columns:minmax(0,1.7fr) minmax(260px,.75fr);gap:16px}
    .ad-form--narrow{max-width:480px}
    @media(max-width:900px){.ad-charts-row{grid-template-columns:1fr}}
    .ad-shell{display:flex;min-height:100svh;height:100svh;overflow:hidden}
    .ad-sidebar{width:var(--ad-sidebar-w,210px);flex-shrink:0;background:#002d58;color:#fff;display:flex;flex-direction:column;padding:16px 0 12px;position:fixed;top:0;left:0;bottom:0;z-index:300;overflow-y:auto;overflow-x:hidden;scrollbar-width:none;-ms-overflow-style:none;transition:width .2s ease}
    .ad-sidebar::-webkit-scrollbar{display:none;width:0;height:0}
    body.ad-sidebar-collapsed{--ad-sidebar-w:72px}
    .ad-main-wrap{flex:1;margin-left:var(--ad-sidebar-w,210px);min-width:0;min-height:0;height:100svh;display:flex;flex-direction:column;overflow:hidden;transition:margin-left .2s ease}
    .ad-portal-top{position:sticky;top:0;z-index:200;flex-shrink:0;display:flex;align-items:center;gap:14px;padding:16px 24px;background:#f0f4f8;border-bottom:1px solid #d8dee8}
    .ad-portal .ad-main{max-width:none;margin:0;padding:20px 24px 48px;background:#f0f4f8;flex:1 1 auto;min-height:0;overflow-x:hidden;overflow-y:auto;-webkit-overflow-scrolling:touch}
    .ad-portal-home{display:grid!important;gap:28px}
    .ad-service-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
    .ad-service-card{display:flex;align-items:center;gap:14px;padding:16px 18px;background:#fff;border:1px solid #e2e8f0;border-radius:4px;box-shadow:0 1px 4px rgba(15,23,42,.06);cursor:pointer;text-align:left;font:inherit;color:inherit;text-decoration:none;min-height:72px}
    .ad-service-title{font-size:.88rem;font-weight:700;color:#005691}
    .ad-service-value{font-size:1.05rem;font-weight:800;color:#1a1a2e}
    .ad-service-value--money{color:#15803d!important}
    @media(max-width:900px){.ad-sidebar{transform:translateX(-100%)}.ad-sidebar.is-open{transform:translateX(0)}.ad-main-wrap{margin-left:0}.ad-menu-btn{display:grid!important;place-items:center;width:40px;height:40px;border:1px solid #c5cdd8;border-radius:8px;background:#fff;color:#005691;cursor:pointer}.ad-service-grid{grid-template-columns:1fr}}
  </style>
  <script src="https://cdn.jsdelivr.net/npm/apexcharts@3.54.1/dist/apexcharts.min.js"></script>
</head>
<body class="ad-body ad-portal ad-view-home">
  <div class="ad-shell">
    <aside class="ad-sidebar" id="ad-sidebar">
      <div class="ad-sidebar-head">
        <p class="ad-sidebar-brand">Getway</p>
        <div class="ad-sidebar-head-actions">
          <button type="button" class="ad-sidebar-minimize" id="ad-sidebar-minimize" aria-label="Minimize sidebar" title="Minimize sidebar">
            <i class="fa-solid fa-angles-left"></i>
          </button>
          <button type="button" class="ad-sidebar-toggle" id="ad-sidebar-close" aria-label="Close menu">
            <i class="fa-solid fa-xmark"></i>
          </button>
        </div>
      </div>
      <button type="button" class="ad-sidebar-catalogue is-active" data-ad-nav="home">
        <i class="fa-solid fa-folder-open ad-nav-ico ad-nav-ico--catalogue"></i>
        <span class="ad-sidebar-text">Service catalogue</span>
      </button>
      <nav class="ad-sidebar-nav" aria-label="Admin modules">
        <p class="ad-sidebar-label">COLLECTIONS</p>
        <button type="button" class="ad-sidebar-link" data-ad-target="analytics"><span class="ad-sidebar-link-text"><i class="fa-solid fa-chart-line ad-nav-ico ad-nav-ico--chart"></i> <span class="ad-sidebar-text">Payment analysis</span></span><i class="fa-solid fa-chevron-right ad-sidebar-chevron" aria-hidden="true"></i></button>
        <button type="button" class="ad-sidebar-link" data-ad-target="control-number"><span class="ad-sidebar-link-text"><i class="fa-solid fa-file-invoice-dollar ad-nav-ico ad-nav-ico--invoice"></i> <span class="ad-sidebar-text">Control number</span></span><i class="fa-solid fa-chevron-right ad-sidebar-chevron" aria-hidden="true"></i></button>
        <button type="button" class="ad-sidebar-link" data-ad-target="transactions"><span class="ad-sidebar-link-text"><i class="fa-solid fa-receipt ad-nav-ico ad-nav-ico--receipt"></i> <span class="ad-sidebar-text">Transactions</span></span><i class="fa-solid fa-chevron-right ad-sidebar-chevron" aria-hidden="true"></i></button>
        <button type="button" class="ad-sidebar-link" data-ad-target="recent"><span class="ad-sidebar-link-text"><i class="fa-solid fa-clock-rotate-left ad-nav-ico ad-nav-ico--recent"></i> <span class="ad-sidebar-text">Recent collections</span></span><i class="fa-solid fa-chevron-right ad-sidebar-chevron" aria-hidden="true"></i></button>
        <p class="ad-sidebar-label">PAYOUTS</p>
        <button type="button" class="ad-sidebar-link" data-ad-target="payout-dest"><span class="ad-sidebar-link-text"><i class="fa-solid fa-mobile-screen ad-nav-ico ad-nav-ico--mobile"></i> <span class="ad-sidebar-text">Payout destination</span></span><i class="fa-solid fa-chevron-right ad-sidebar-chevron" aria-hidden="true"></i></button>
        <button type="button" class="ad-sidebar-link" data-ad-target="payouts"><span class="ad-sidebar-link-text"><i class="fa-solid fa-money-bill-transfer ad-nav-ico ad-nav-ico--money"></i> <span class="ad-sidebar-text">Automatic payouts</span></span><i class="fa-solid fa-chevron-right ad-sidebar-chevron" aria-hidden="true"></i></button>
        <button type="button" class="ad-sidebar-link" data-ad-target="users"><span class="ad-sidebar-link-text"><i class="fa-solid fa-users ad-nav-ico ad-nav-ico--users"></i> <span class="ad-sidebar-text">Registered users</span></span><i class="fa-solid fa-chevron-right ad-sidebar-chevron" aria-hidden="true"></i></button>
        <p class="ad-sidebar-label">MESSAGING</p>
        <button type="button" class="ad-sidebar-link" data-ad-target="whatsapp"><span class="ad-sidebar-link-text"><i class="fa-brands fa-whatsapp ad-nav-ico" style="color:#25d366"></i> <span class="ad-sidebar-text">Send WhatsApp</span></span><i class="fa-solid fa-chevron-right ad-sidebar-chevron" aria-hidden="true"></i></button>
      </nav>
      <div class="ad-sidebar-foot">
        <span class="ad-sidebar-user ad-sidebar-text"><?php echo $authName; ?></span>
        <a class="ad-sidebar-link ad-sidebar-link--quiet" href="part-two.php"><i class="fa-solid fa-wallet ad-nav-ico ad-nav-ico--wallet"></i> <span class="ad-sidebar-text">User wallet</span></a>
        <a class="ad-sidebar-link ad-sidebar-link--danger" href="logout.php"><i class="fa-solid fa-right-from-bracket ad-nav-ico ad-nav-ico--logout"></i> <span class="ad-sidebar-text">Logout</span></a>
      </div>
    </aside>
    <div class="ad-sidebar-backdrop" id="ad-sidebar-backdrop" hidden></div>

    <div class="ad-main-wrap">
      <header class="ad-portal-top">
        <button type="button" class="ad-menu-btn" id="ad-menu-open" aria-label="Open menu">
          <i class="fa-solid fa-bars"></i>
        </button>
        <div class="ad-portal-top-text">
          <p class="ad-eyebrow">Getway Admin</p>
          <h1 id="ad-portal-title">Service catalogue</h1>
        </div>
        <div class="ad-top-actions">
          <button type="button" class="ad-ga-open" id="ad-ga-open">
            <i class="fa-solid fa-circle-nodes" aria-hidden="true"></i>
            <span>General Analysis</span>
          </button>
          <button type="button" class="ad-refresh" id="ad-refresh">Refresh all</button>
          <a class="ad-logout-top" href="logout.php"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
        </div>
      </header>

      <main class="ad-main">
        <p id="ad-db-banner" class="ad-db-banner" hidden></p>

        <!-- Hidden stats — JS updates these; portal cards mirror values -->
        <section class="ad-stats ad-stats--hidden" id="ad-stats" aria-hidden="true">
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
            <p>Auto payout</p>
            <strong id="stat-auto" class="ad-auto-off">OFF</strong>
            <small id="stat-auto-mode">TEST</small>
          </article>
          <article class="ad-stat">
            <p>Destination</p>
            <strong id="stat-dest">2557******92</strong>
          </article>
        </section>

        <!-- Portal home — e-services style cards with live data -->
        <section class="ad-portal-home" id="ad-view-home">
          <div class="ad-portal-block">
            <div class="ad-portal-block-head">
              <h2>E-services for collections</h2>
              <div class="ad-portal-illus" aria-hidden="true">
                <i class="fa-solid fa-wallet"></i>
              </div>
            </div>
            <div class="ad-service-grid">
              <button type="button" class="ad-service-card" data-ad-target="analytics">
                <span class="ad-service-ico"><i class="fa-solid fa-chart-pie"></i></span>
                <span class="ad-service-body">
                  <span class="ad-service-title">Payment analysis</span>
                  <strong class="ad-service-value ad-service-value--money" id="ad-portal-incoming">TZS 0</strong>
                  <small id="ad-portal-period">All time</small>
                </span>
              </button>
              <button type="button" class="ad-service-card" data-ad-target="control-number">
                <span class="ad-service-ico"><i class="fa-solid fa-hashtag"></i></span>
                <span class="ad-service-body">
                  <span class="ad-service-title">Create control number</span>
                  <strong class="ad-service-value" id="ad-portal-controls">—</strong>
                  <small>BillPay collections</small>
                </span>
              </button>
              <button type="button" class="ad-service-card" data-ad-target="transactions">
                <span class="ad-service-ico"><i class="fa-solid fa-circle-check"></i></span>
                <span class="ad-service-body">
                  <span class="ad-service-title">Successful payments</span>
                  <strong class="ad-service-value" id="ad-portal-success">0</strong>
                  <small>Paid transactions</small>
                </span>
              </button>
              <button type="button" class="ad-service-card" data-ad-target="transactions">
                <span class="ad-service-ico"><i class="fa-solid fa-hourglass-half"></i></span>
                <span class="ad-service-body">
                  <span class="ad-service-title">Pending payments</span>
                  <strong class="ad-service-value" id="ad-portal-pending">0</strong>
                  <small>Awaiting payment</small>
                </span>
              </button>
              <button type="button" class="ad-service-card" data-ad-target="transactions">
                <span class="ad-service-ico"><i class="fa-solid fa-circle-xmark"></i></span>
                <span class="ad-service-body">
                  <span class="ad-service-title">Failed payments</span>
                  <strong class="ad-service-value" id="ad-portal-failed">0</strong>
                  <small>Unsuccessful attempts</small>
                </span>
              </button>
              <button type="button" class="ad-service-card" data-ad-target="recent">
                <span class="ad-service-ico"><i class="fa-solid fa-receipt"></i></span>
                <span class="ad-service-body">
                  <span class="ad-service-title">Recent collections</span>
                  <strong class="ad-service-value" id="ad-portal-recent">0</strong>
                  <small id="ad-portal-recent-sub">Latest records</small>
                </span>
              </button>
              <button type="button" class="ad-service-card" data-ad-action="sync">
                <span class="ad-service-ico"><i class="fa-solid fa-rotate"></i></span>
                <span class="ad-service-body">
                  <span class="ad-service-title">Sync ClickPesa</span>
                  <strong class="ad-service-value">Sync</strong>
                  <small>Update transaction records</small>
                </span>
              </button>
              <button type="button" class="ad-service-card" data-ad-target="transactions">
                <span class="ad-service-ico"><i class="fa-solid fa-building-columns"></i></span>
                <span class="ad-service-body">
                  <span class="ad-service-title">ClickPesa balance</span>
                  <strong class="ad-service-value ad-service-value--money" id="ad-portal-balance">Loading...</strong>
                  <small id="ad-portal-balance-updated">Last updated: --</small>
                </span>
              </button>
            </div>
          </div>

          <div class="ad-portal-block">
            <div class="ad-portal-block-head">
              <h2>E-services for payouts</h2>
              <div class="ad-portal-illus ad-portal-illus--biz" aria-hidden="true">
                <i class="fa-solid fa-briefcase"></i>
              </div>
            </div>
            <div class="ad-service-grid">
              <button type="button" class="ad-service-card" data-ad-target="payout-dest">
                <span class="ad-service-ico"><i class="fa-solid fa-mobile-screen-button"></i></span>
                <span class="ad-service-body">
                  <span class="ad-service-title">Payout destination</span>
                  <strong class="ad-service-value" id="ad-portal-dest">—</strong>
                  <small>Mobile money number</small>
                </span>
              </button>
              <button type="button" class="ad-service-card" id="ad-portal-auto-card">
                <span class="ad-service-ico"><i class="fa-solid fa-bolt"></i></span>
                <span class="ad-service-body">
                  <span class="ad-service-title">Auto payout</span>
                  <strong class="ad-service-value" id="ad-portal-auto">OFF</strong>
                  <small id="ad-portal-auto-mode">TEST</small>
                </span>
              </button>
              <button type="button" class="ad-service-card" data-ad-target="payouts">
                <span class="ad-service-ico"><i class="fa-solid fa-money-bill-transfer"></i></span>
                <span class="ad-service-body">
                  <span class="ad-service-title">Automatic payouts</span>
                  <strong class="ad-service-value" id="ad-portal-payouts">0</strong>
                  <small>Payout history</small>
                </span>
              </button>
              <button type="button" class="ad-service-card" data-ad-target="users">
                <span class="ad-service-ico"><i class="fa-solid fa-users"></i></span>
                <span class="ad-service-body">
                  <span class="ad-service-title">Registered users</span>
                  <strong class="ad-service-value" id="ad-portal-users">0</strong>
                  <small>Wallet accounts</small>
                </span>
              </button>
              <button type="button" class="ad-service-card" data-ad-target="analytics">
                <span class="ad-service-ico"><i class="fa-solid fa-chart-column"></i></span>
                <span class="ad-service-body">
                  <span class="ad-service-title">Transaction trend</span>
                  <strong class="ad-service-value" id="ad-portal-trend">14 days</strong>
                  <small>Charts &amp; breakdown</small>
                </span>
              </button>
              <a class="ad-service-card ad-service-card--link" href="autopay.php">
                <span class="ad-service-ico"><i class="fa-solid fa-wifi"></i></span>
                <span class="ad-service-body">
                  <span class="ad-service-title">AutoPay USSD</span>
                  <strong class="ad-service-value">Open</strong>
                  <small>POS &amp; mobile push</small>
                </span>
              </a>
            </div>
          </div>
        </section>

        <div class="ad-detail-sections is-collapsed" id="ad-detail-sections" hidden>
    <section class="ad-card ad-page-section" id="ad-section-analytics" data-ad-page="analytics">
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
        <div class="ad-charts-row">
          <div class="ad-trend-wrap">
            <p class="ad-trend-title">Transaction trend</p>
            <div id="ad-trend" class="ad-trend" role="img" aria-label="Daily transaction trend"></div>
          </div>
          <div class="ad-pie-wrap">
            <p class="ad-trend-title">Payment status</p>
            <div id="ad-pie" class="ad-pie" role="img" aria-label="Payment pie chart"></div>
          </div>
        </div>
    </section>

    <section class="ad-card ad-page-section" id="ad-section-control-number" data-ad-page="control-number">
        <h2>Create control number</h2>
        <p class="ad-note">Weka kiasi na maelezo tu. <strong>Control number</strong> itatengenezwa na ClickPesa BillPay — hauandiki mwenyewe.</p>
        <form id="ad-cn-form" class="ad-form ad-form--narrow">
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
    </section>

    <section class="ad-card ad-page-section" id="ad-section-transactions" data-ad-page="transactions">
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

    <section class="ad-card ad-page-section" id="ad-section-payout-dest" data-ad-page="payout-dest">
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

    <section class="ad-card ad-page-section" id="ad-section-payouts" data-ad-page="payouts">
      <div class="ad-card-head">
        <h2>Payout dashboard</h2>
        <div class="ad-card-actions">
          <span id="ad-test-mode-badge" class="ad-badge ad-badge--warn" hidden>TEST MODE</span>
          <button type="button" class="ad-btn ad-btn--primary" id="ad-manual-payout-open"><i class="fa-solid fa-paper-plane"></i><span>Manual payout</span></button>
          <button type="button" class="ad-btn ad-btn--ghost" id="ad-payouts-refresh"><i class="fa-solid fa-rotate"></i><span>Refresh</span></button>
          <button type="button" class="ad-btn ad-btn--ghost" id="ad-payouts-export"><i class="fa-solid fa-file-csv"></i><span>Export CSV</span></button>
        </div>
      </div>
      <div class="ad-stats ad-stats--payout" id="ad-payout-stats">
        <article class="ad-stat ad-stat--compact"><p>Successful</p><strong id="ad-payout-success">0</strong></article>
        <article class="ad-stat ad-stat--compact"><p>Pending</p><strong id="ad-payout-pending">0</strong></article>
        <article class="ad-stat ad-stat--compact"><p>Failed</p><strong id="ad-payout-failed">0</strong></article>
        <article class="ad-stat ad-stat--compact"><p>Refunded</p><strong id="ad-payout-refunded">0</strong></article>
        <article class="ad-stat ad-stat--compact"><p>Reversed</p><strong id="ad-payout-reversed">0</strong></article>
        <article class="ad-stat ad-stat--money"><p>Total fees</p><strong id="ad-payout-fees">TZS 0</strong></article>
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

    <section class="ad-card ad-page-section" id="ad-section-users" data-ad-page="users">
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

    <section class="ad-card ad-page-section" id="ad-section-recent" data-ad-page="recent">
      <div class="ad-card-head">
        <h2>Recent collections</h2>
        <small id="ad-recent-period" class="ad-period-sub">All time</small>
      </div>
      <p id="ad-recent-error" class="ad-db-banner" hidden></p>
      <ul class="ad-recent" id="ad-recent"></ul>
      <nav class="ad-pager" id="ad-recent-pager" hidden aria-label="Recent collections pages"></nav>
    </section>

    <section class="ad-card ad-page-section" id="ad-section-whatsapp" data-ad-page="whatsapp">
      <div class="ad-card-head">
        <div>
          <h2><i class="fa-brands fa-whatsapp" style="color:#25d366"></i> WhatsApp</h2>
          <p class="ad-period-sub" id="ad-wa-sender-label"><?php echo $waSender; ?></p>
        </div>
        <div class="ad-card-actions">
          <button type="button" class="ad-btn ad-btn--ghost" id="ad-wa-refresh"><i class="fa-solid fa-rotate"></i><span>Refresh</span></button>
        </div>
      </div>

      <div class="ad-wa-layout">
        <div class="ad-wa-send-col">
          <div class="ad-wa-mode" role="group" aria-label="Mode">
            <button type="button" class="ad-wa-mode-btn is-active" data-wa-mode="manual">Manual</button>
            <button type="button" class="ad-wa-mode-btn" data-wa-mode="auto">Automatic</button>
          </div>

          <form id="ad-wa-form" class="ad-form ad-wa-form" autocomplete="off">
            <label class="ad-wa-label">Phone
              <div class="ad-wa-phone-row">
                <input id="ad-wa-to" name="to" type="tel" placeholder="2557XXXXXXXX" required />
                <label class="ad-wa-excel-btn" title="Upload Excel / CSV">
                  <i class="fa-solid fa-file-excel"></i>
                  <span>Excel</span>
                  <input type="file" id="ad-wa-excel" accept=".xlsx,.xls,.csv,text/csv,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" hidden />
                </label>
              </div>
            </label>
            <div id="ad-wa-phone-chips" class="ad-wa-chips" hidden></div>

            <label class="ad-wa-label" id="ad-wa-body-wrap">Message
              <textarea id="ad-wa-body" name="body" rows="5" placeholder="Andika ujumbe…" required></textarea>
            </label>
            <label class="ad-wa-label" id="ad-wa-auto-wrap" hidden>Auto message
              <textarea id="ad-wa-auto-body" rows="5" placeholder="Andika ujumbe wowote unaotaka utumwe automatic…"></textarea>
              <small class="ad-wa-hint">Unaweza kubadilisha ujumbe wakati wowote — si lazima ule wa mfano. Hubaki kuhifadhiwa.</small>
            </label>

            <div class="ad-wa-excel-hint" id="ad-wa-excel-hint">
              <strong>Excel format:</strong> column <code>phone</code> (au namba moja kwa safu).
              Mfano: <code>2557XXXXXXXX</code>
              <button type="button" class="ad-btn ad-btn--ghost" id="ad-wa-excel-sample">Download sample Excel</button>
            </div>

            <div class="ad-wa-schedule" id="ad-wa-schedule" hidden>
              <label class="ad-wa-label">Send after
                <div class="ad-wa-schedule-row">
                  <input id="ad-wa-delay-value" type="number" min="0" value="5" />
                  <select id="ad-wa-delay-unit">
                    <option value="minutes">Minutes</option>
                    <option value="days">Days</option>
                    <option value="months">Months</option>
                  </select>
                </div>
              </label>
            </div>

            <label class="ad-wa-label">Priority
              <select id="ad-wa-priority">
                <option value="0">High</option>
                <option value="5">Normal</option>
                <option value="10" selected>Low</option>
              </select>
            </label>

            <div class="ad-wa-actions">
              <button type="submit" class="ad-wa-send-btn" id="ad-wa-send"><i class="fa-brands fa-whatsapp"></i> Send</button>
              <button type="button" class="ad-btn ad-btn--ghost" id="ad-wa-status">Status</button>
            </div>
          </form>
          <p id="ad-wa-msg" class="ad-msg"></p>
          <details class="ad-wa-hook">
            <summary>Webhook</summary>
            <code id="ad-wa-webhook"><?php echo $waWebhook; ?></code>
            <button type="button" class="ad-btn ad-btn--ghost" id="ad-wa-copy-hook">Copy</button>
          </details>
        </div>

        <div class="ad-wa-list-col">
          <div class="ad-wa-tabs" role="tablist">
            <button type="button" class="ad-wa-tab is-active" data-wa-status="all">All</button>
            <button type="button" class="ad-wa-tab" data-wa-status="sent">Sent</button>
            <button type="button" class="ad-wa-tab" data-wa-status="queue">Queue</button>
            <button type="button" class="ad-wa-tab" data-wa-status="unsent">Unsent</button>
            <button type="button" class="ad-wa-tab" data-wa-status="invalid">Invalid</button>
            <button type="button" class="ad-wa-tab" data-wa-status="expired">Expired</button>
          </div>
          <ul class="ad-wa-list" id="ad-wa-list">
            <li class="ad-wa-empty">Loading…</li>
          </ul>
        </div>
      </div>
    </section>
        </div>
      </main>
    </div>
  </div>

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
  <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
  <script src="tis-api-base.js"></script>
  <script src="payments-merge.js?v=2"></script>
  <script src="admin-dashboard.js?v=<?php echo $jsV; ?>"></script>
</body>
</html>
