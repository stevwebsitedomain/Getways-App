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
$authEmail = htmlspecialchars(trim((string) ($authUser['email'] ?? $authUser['username'] ?? '')), ENT_QUOTES);
$authAvatar = trim((string) ($authUser['avatar'] ?? ''));
$authAvatarSafe = $authAvatar !== '' ? htmlspecialchars($authAvatar, ENT_QUOTES) : '';
$authFirst = trim((string) (preg_split('/\s+/', trim((string) ($authUser['fullName'] ?? 'Admin')))[0] ?? 'Admin'));
$authFirst = htmlspecialchars($authFirst !== '' ? $authFirst : 'Admin', ENT_QUOTES);
$hour = (int) date('G');
$greet = $hour < 12 ? 'Good Morning' : ($hour < 17 ? 'Good Afternoon' : 'Good Evening');
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
$acsCssV = (string) (@filemtime(__DIR__ . '/acs-portal.css') ?: time());
$dtCssV = (string) (@filemtime(__DIR__ . '/acs-data-table.css') ?: time());
$jsV = (string) (@filemtime(__DIR__ . '/admin-dashboard.js') ?: time());
$cssV = htmlspecialchars($cssV, ENT_QUOTES);
$acsCssV = htmlspecialchars($acsCssV, ENT_QUOTES);
$dtCssV = htmlspecialchars($dtCssV, ENT_QUOTES);
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
  <title>ACS Portal | Admin Dashboard</title>
  <link rel="icon" type="image/png" href="images/favicon.png" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
  <link rel="stylesheet" href="admin-dashboard.css?v=<?php echo $cssV; ?>" />
  <link rel="stylesheet" href="acs-portal.css?v=<?php echo $acsCssV; ?>" />
  <link rel="stylesheet" href="acs-data-table.css?v=<?php echo $dtCssV; ?>" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" />
  <style id="ad-portal-critical">
    .ad-charts-row{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(280px,1fr);gap:16px}
    .ad-form--narrow{max-width:480px}
    .ad-tx-filters{display:flex;flex-wrap:wrap;gap:6px;margin-right:8px}
    .ad-tx-filters .ad-btn.is-active{background:#145493;color:#fff;border-color:#145493}
    .ad-stats--hidden{position:absolute!important;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0)}
    body.ad-acs #ad-trend.ad-trend{
      background:#fff;
      border:1px solid #e5e7eb;
      border-radius:8px;
      padding:12px 8px 4px;
      min-height:380px;
      box-shadow:0 1px 3px rgba(15,23,42,.06);
      overflow:visible;
      line-height:normal;
      max-width:100%;
      margin:0 auto;
      touch-action:pan-y;
    }
    body.ad-acs #ad-trend.ad-trend .apexcharts-canvas{
      margin:0 auto;
      background:transparent!important;
    }
    body.ad-acs #ad-trend.ad-trend .apexcharts-area-series path.apexcharts-area{
      opacity:1!important;
    }
    body.ad-acs #ad-trend.ad-trend .apexcharts-toolbar{
      z-index:5;
    }
    @media(max-width:900px){.ad-charts-row{grid-template-columns:1fr}}
  </style>
  <script src="https://cdn.jsdelivr.net/npm/apexcharts@3.54.1/dist/apexcharts.min.js"></script>
</head>
<body class="ad-body ad-portal ad-acs ad-view-home">
  <div class="ad-shell ad-gov-wrap">
<?php
$acsBrand = 'ACS Portal';
$acsLine1 = 'NATIONAL AUTOMATIC COLLECTION AUTHORITY';
$acsLine2 = 'THE AUTOMATIC COLLECTION SYSTEM PORTAL';
$acsLine3 = 'EFFICIENT, SECURE AND TRANSPARENT SERVICES';
require __DIR__ . '/acs-gov-banner.php';
?>

    <nav class="top-navigation" aria-label="Admin top navigation">
      <button class="mobile-menu-button" type="button" id="ad-menu-open" aria-label="Open menu">☰</button>
      <div class="nav-spacer" aria-hidden="true"></div>
      <div class="top-links app-links">
        <button type="button" class="ad-sidebar-catalogue is-active" data-ad-nav="home"><i class="fa-solid fa-house"></i> Dashboard</button>
        <button type="button" data-ad-target="transactions"><i class="fa-solid fa-receipt"></i> Transactions</button>
        <button type="button" data-ad-target="analytics"><i class="fa-solid fa-chart-line"></i> Analysis</button>
        <button type="button" data-ad-target="whatsapp"><i class="fa-brands fa-whatsapp"></i> WhatsApp</button>
        <button type="button" id="ad-refresh"><i class="fa-solid fa-rotate"></i> Refresh</button>
        <button type="button" id="ad-ga-open"><i class="fa-solid fa-circle-nodes"></i> General Analysis</button>
      </div>
      <div class="account">
        <div class="account-text">
          <strong><?php echo $authName; ?></strong>
          <small><?php echo $authEmail !== '' ? $authEmail : 'Administrator'; ?></small>
          <a class="logout-button" href="logout.php"><i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i> Logout</a>
        </div>
        <div class="account-avatar" id="headerAvatar">
          <?php if ($authAvatarSafe !== ''): ?>
            <img id="headerProfileImage" src="<?php echo $authAvatarSafe; ?>" alt="Profile photo" referrerpolicy="no-referrer" />
            <span id="headerProfileFallback" hidden><i class="fa-solid fa-user"></i></span>
          <?php else: ?>
            <img id="headerProfileImage" alt="Profile photo" hidden referrerpolicy="no-referrer" />
            <span id="headerProfileFallback"><i class="fa-solid fa-user"></i></span>
          <?php endif; ?>
        </div>
      </div>
    </nav>

    <div class="portal-body">
    <aside class="ad-sidebar" id="ad-sidebar">
      <button type="button" class="ad-sidebar-toggle" id="ad-sidebar-close" aria-label="Close menu">×</button>
      <div class="profile-uploader">
        <div class="profile-photo-box">
          <?php if ($authAvatarSafe !== ''): ?>
            <img id="sidebarProfileImage" src="<?php echo $authAvatarSafe; ?>" alt="Profile photo" referrerpolicy="no-referrer" />
            <div class="profile-fallback" id="sidebarProfileFallback" hidden><i class="fa-solid fa-user"></i></div>
          <?php else: ?>
            <img id="sidebarProfileImage" alt="Profile photo" hidden referrerpolicy="no-referrer" />
            <div class="profile-fallback" id="sidebarProfileFallback"><i class="fa-solid fa-user"></i></div>
          <?php endif; ?>
        </div>
        <label class="profile-upload-btn" for="profilePhotoInput">
          <i class="fa-solid fa-camera" aria-hidden="true"></i> Upload Photo
        </label>
        <input id="profilePhotoInput" type="file" accept="image/png,image/jpeg,image/jpg,image/webp" hidden />
        <p class="profile-upload-hint">JPG, PNG au WEBP — itabana otomatiki</p>
        <p class="ad-sidebar-user"><?php echo $authName; ?></p>
      </div>
      <div class="ad-sidebar-head" hidden>
        <p class="ad-sidebar-brand">ACS</p>
        <div class="ad-sidebar-head-actions">
          <button type="button" class="ad-sidebar-minimize" id="ad-sidebar-minimize" aria-label="Minimize sidebar" hidden></button>
        </div>
      </div>
      <button type="button" class="ad-sidebar-catalogue is-active" data-ad-nav="home">
        <i class="fa-solid fa-folder-open ad-nav-ico"></i>
        <span class="ad-sidebar-text">Dashboard</span>
        <i class="fa-solid fa-chevron-right ad-sidebar-chevron" aria-hidden="true"></i>
      </button>
      <nav class="ad-sidebar-nav sidebar-menu" aria-label="Admin modules">
        <p class="ad-sidebar-label">COLLECTIONS</p>
        <button type="button" class="ad-sidebar-link" data-ad-target="general-analysis">
          <i class="fa-solid fa-circle-nodes ad-nav-ico"></i>
          <span class="ad-sidebar-text">General Analysis</span>
          <i class="fa-solid fa-chevron-right ad-sidebar-chevron" aria-hidden="true"></i>
        </button>
        <button type="button" class="ad-sidebar-link" data-ad-target="analytics">
          <i class="fa-solid fa-chart-line ad-nav-ico"></i>
          <span class="ad-sidebar-text">Payment analysis</span>
          <i class="fa-solid fa-chevron-right ad-sidebar-chevron" aria-hidden="true"></i>
        </button>
        <button type="button" class="ad-sidebar-link" data-ad-target="control-number">
          <i class="fa-solid fa-file-invoice-dollar ad-nav-ico"></i>
          <span class="ad-sidebar-text">Control number</span>
          <i class="fa-solid fa-chevron-right ad-sidebar-chevron" aria-hidden="true"></i>
        </button>
        <button type="button" class="ad-sidebar-link" data-ad-target="transactions">
          <i class="fa-solid fa-receipt ad-nav-ico"></i>
          <span class="ad-sidebar-text">Transactions</span>
          <i class="fa-solid fa-chevron-right ad-sidebar-chevron" aria-hidden="true"></i>
        </button>
        <button type="button" class="ad-sidebar-link" data-ad-target="recent">
          <i class="fa-solid fa-clock-rotate-left ad-nav-ico"></i>
          <span class="ad-sidebar-text">Recent collections</span>
          <i class="fa-solid fa-chevron-right ad-sidebar-chevron" aria-hidden="true"></i>
        </button>
        <p class="ad-sidebar-label">PAYOUTS</p>
        <button type="button" class="ad-sidebar-link" data-ad-target="payout-dest">
          <i class="fa-solid fa-mobile-screen ad-nav-ico"></i>
          <span class="ad-sidebar-text">Payout destination</span>
          <i class="fa-solid fa-chevron-right ad-sidebar-chevron" aria-hidden="true"></i>
        </button>
        <button type="button" class="ad-sidebar-link" data-ad-target="users">
          <i class="fa-solid fa-users ad-nav-ico"></i>
          <span class="ad-sidebar-text">Registered users</span>
          <i class="fa-solid fa-chevron-right ad-sidebar-chevron" aria-hidden="true"></i>
        </button>
        <p class="ad-sidebar-label">MESSAGING</p>
        <button type="button" class="ad-sidebar-link" data-ad-target="whatsapp">
          <i class="fa-brands fa-whatsapp ad-nav-ico" style="color:#25d366"></i>
          <span class="ad-sidebar-text">Send WhatsApp</span>
          <i class="fa-solid fa-chevron-right ad-sidebar-chevron" aria-hidden="true"></i>
        </button>
      </nav>
      <div class="ad-sidebar-foot">
        <a class="ad-sidebar-link ad-sidebar-link--quiet" href="part-two.php">
          <i class="fa-solid fa-wallet ad-nav-ico"></i>
          <span class="ad-sidebar-text">User wallet</span>
          <i class="fa-solid fa-chevron-right ad-sidebar-chevron" aria-hidden="true"></i>
        </a>
        <a class="ad-sidebar-link ad-sidebar-link--danger" href="logout.php">
          <i class="fa-solid fa-right-from-bracket ad-nav-ico"></i>
          <span class="ad-sidebar-text">Logout</span>
          <i class="fa-solid fa-chevron-right ad-sidebar-chevron" aria-hidden="true"></i>
        </a>
      </div>
    </aside>
    <button class="ad-sidebar-backdrop" id="ad-sidebar-backdrop" type="button" hidden aria-label="Close menu"></button>

    <div class="ad-main-wrap">
      <header class="ad-portal-top" hidden>
        <div class="ad-portal-top-text">
          <p class="ad-eyebrow">ACS Admin</p>
          <h1 id="ad-portal-title">Dashboard</h1>
        </div>
      </header>

      <main class="ad-main content">
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
          <article class="ad-stat ad-stat--toggle" id="stat-auto-card" hidden aria-hidden="true">
            <p>Auto payout</p>
            <strong id="stat-auto" class="ad-auto-off">OFF</strong>
            <small id="stat-auto-mode">TEST</small>
          </article>
          <article class="ad-stat">
            <p>Destination</p>
            <strong id="stat-dest">2557******92</strong>
          </article>
        </section>

        <!-- Portal home — ACS dashboard -->
        <section class="ad-portal-home" id="ad-view-home">
          <h1 class="welcome-title">
            <?php echo $greet; ?> <?php echo $authFirst; ?>
            <span class="sun" aria-hidden="true">☀</span>
          </h1>

          <div class="statistics" aria-label="Collection statistics">
            <div class="statistic-card">
              <span>ClickPesa Balance</span>
              <strong id="ad-portal-balance">Loading...</strong>
            </div>
            <div class="statistic-card">
              <span>Money in (paid)</span>
              <strong id="ad-portal-incoming">TZS 0</strong>
            </div>
            <div class="statistic-card">
              <span>Successful</span>
              <strong id="ad-portal-success">0</strong>
            </div>
            <div class="statistic-card">
              <span>Pending</span>
              <strong id="ad-portal-pending">0</strong>
            </div>
          </div>
          <p class="ad-period-sub" id="ad-portal-period" style="margin:-12px 0 18px;color:#667085;font-size:12px">All time</p>
          <span id="ad-portal-failed" hidden>0</span>
          <span id="ad-portal-recent" hidden>0</span>
          <span id="ad-portal-recent-sub" hidden></span>
          <span id="ad-portal-controls" hidden>—</span>
          <span id="ad-portal-users" hidden>0</span>
          <span id="ad-portal-dest" hidden>—</span>
          <span id="ad-portal-balance-updated" hidden></span>
          <span id="ad-portal-trend" hidden>14 days</span>

          <div class="ad-portal-block">
            <div class="ad-portal-block-head">
              <h2>COLLECTION SERVICES</h2>
              <div class="ad-portal-illus" aria-hidden="true">
                <i class="fa-solid fa-lightbulb"></i>
              </div>
            </div>
            <div class="ad-service-grid">
              <button type="button" class="ad-service-card" data-ad-target="analytics">
                <span class="ad-service-ico"><i class="fa-solid fa-chart-pie"></i></span>
                <span class="ad-service-body">
                  <span class="ad-service-title">Payment analysis</span>
                  <small>Charts &amp; paid totals</small>
                </span>
              </button>
              <button type="button" class="ad-service-card" data-ad-target="control-number">
                <span class="ad-service-ico"><i class="fa-solid fa-hashtag"></i></span>
                <span class="ad-service-body">
                  <span class="ad-service-title">Create control number</span>
                  <small>BillPay collections</small>
                </span>
              </button>
              <button type="button" class="ad-service-card" data-ad-target="transactions" data-tx-filter="SUCCESS">
                <span class="ad-service-ico"><i class="fa-solid fa-circle-check"></i></span>
                <span class="ad-service-body">
                  <span class="ad-service-title">Successful payments</span>
                  <small>Paid transactions</small>
                </span>
              </button>
              <button type="button" class="ad-service-card" data-ad-target="transactions" data-tx-filter="PENDING">
                <span class="ad-service-ico"><i class="fa-solid fa-hourglass-half"></i></span>
                <span class="ad-service-body">
                  <span class="ad-service-title">Pending payments</span>
                  <small>Awaiting payment</small>
                </span>
              </button>
              <button type="button" class="ad-service-card" data-ad-target="transactions" data-tx-filter="FAILED">
                <span class="ad-service-ico"><i class="fa-solid fa-circle-xmark"></i></span>
                <span class="ad-service-body">
                  <span class="ad-service-title">Failed payments</span>
                  <small>Unsuccessful attempts</small>
                </span>
              </button>
              <button type="button" class="ad-service-card" data-ad-target="recent">
                <span class="ad-service-ico"><i class="fa-solid fa-receipt"></i></span>
                <span class="ad-service-body">
                  <span class="ad-service-title">Recent collections</span>
                  <small>Latest records</small>
                </span>
              </button>
              <button type="button" class="ad-service-card" data-ad-action="sync">
                <span class="ad-service-ico"><i class="fa-solid fa-rotate"></i></span>
                <span class="ad-service-body">
                  <span class="ad-service-title">Sync ClickPesa</span>
                  <small>Update transaction records</small>
                </span>
              </button>
              <button type="button" class="ad-service-card" data-ad-target="transactions">
                <span class="ad-service-ico"><i class="fa-solid fa-building-columns"></i></span>
                <span class="ad-service-body">
                  <span class="ad-service-title">All transactions</span>
                  <small>Full payment history</small>
                </span>
              </button>
            </div>
          </div>

          <div class="ad-portal-block">
            <div class="ad-portal-block-head">
              <h2>PAYOUT &amp; USERS</h2>
              <div class="ad-portal-illus ad-portal-illus--biz" aria-hidden="true">
                <i class="fa-solid fa-briefcase"></i>
              </div>
            </div>
            <div class="ad-service-grid">
              <button type="button" class="ad-service-card" data-ad-target="payout-dest">
                <span class="ad-service-ico"><i class="fa-solid fa-mobile-screen-button"></i></span>
                <span class="ad-service-body">
                  <span class="ad-service-title">Payout destination</span>
                  <small>Number that receives every successful payment</small>
                </span>
              </button>
              <button type="button" class="ad-service-card" data-ad-target="users">
                <span class="ad-service-ico"><i class="fa-solid fa-users"></i></span>
                <span class="ad-service-body">
                  <span class="ad-service-title">Registered users</span>
                  <small>Wallet accounts</small>
                </span>
              </button>
              <button type="button" class="ad-service-card" data-ad-target="analytics">
                <span class="ad-service-ico"><i class="fa-solid fa-chart-column"></i></span>
                <span class="ad-service-body">
                  <span class="ad-service-title">Transaction trend</span>
                  <small>Charts &amp; breakdown</small>
                </span>
              </button>
              <a class="ad-service-card ad-service-card--link" href="autopay.php">
                <span class="ad-service-ico"><i class="fa-solid fa-wifi"></i></span>
                <span class="ad-service-body">
                  <span class="ad-service-title">AutoPay USSD</span>
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
            <p class="ad-period-sub" id="ad-period-label">All time</p>
          </div>
          <div class="ad-card-actions">
            <select id="ad-period-select" class="ad-period-select" aria-label="Analysis period">
              <option value="all" selected>All time</option>
              <option value="90d">Last 90 days</option>
              <option value="30d">Last 30 days</option>
              <option value="month">This month</option>
            </select>
            <button type="button" class="ad-btn ad-btn--ghost" id="ad-sync-transactions"><i class="fa-solid fa-rotate"></i><span>Sync ClickPesa</span></button>
          </div>
        </div>
        <p id="ad-statement-error" class="ad-db-banner" hidden></p>
        <div class="ad-charts-row ad-charts-row--flat">
          <div id="ad-trend" class="ad-trend" role="img" aria-label="Daily transaction trend"></div>
          <div id="ad-pie" class="ad-pie" role="img" aria-label="Payment pie chart"></div>
        </div>
    </section>

    <section class="ad-card ad-page-section ad-cn-page" id="ad-section-control-number" data-ad-page="control-number">
      <div class="ad-cn-stage">
        <div class="ad-cn-card">
          <div class="ad-cn-badge" aria-hidden="true"><i class="fa-solid fa-file-invoice-dollar"></i></div>
          <h2>CREATE CONTROL NUMBER</h2>
          <p class="ad-note">Weka kiasi na maelezo. Control number itatengenezwa na ClickPesa BillPay.</p>
          <form id="ad-cn-form" class="ad-form ad-cn-form" autocomplete="off">
            <label>Order label <small>(hiari)</small>
              <input name="order_id" placeholder="Acha tupu au weka TIS01" maxlength="20" pattern="[A-Za-z0-9]*" title="Herufi na namba tu (hiari)" />
            </label>
            <label>Amount (TZS)
              <input name="amount" type="number" min="1" step="0.01" required placeholder="1000" />
            </label>
            <label>Description
              <input name="description" required placeholder="Malipo ya bidhaa / huduma" />
            </label>
            <label>Mode
              <select name="payment_mode">
                <option value="EXACT">EXACT</option>
                <option value="ALLOW_PARTIAL_AND_OVER_PAYMENT">ALLOW_PARTIAL_AND_OVER_PAYMENT</option>
              </select>
            </label>
            <button type="submit" class="ad-cn-submit"><i class="fa-solid fa-circle-check"></i> GENERATE</button>
          </form>
          <p id="ad-cn-msg" class="ad-msg"></p>
        </div>
      </div>
    </section>

    <section class="ad-card ad-page-section" id="ad-section-transactions" data-ad-page="transactions">
      <div class="ad-card-head">
        <h2 id="ad-transactions-title">Transactions</h2>
        <div class="ad-top-actions">
          <div class="ad-tx-filters" role="group" aria-label="Transaction status filter">
            <button type="button" class="ad-btn ad-btn--ghost is-active" data-set-tx-filter="ALL">All</button>
            <button type="button" class="ad-btn ad-btn--ghost" data-set-tx-filter="SUCCESS">Successful</button>
            <button type="button" class="ad-btn ad-btn--ghost" data-set-tx-filter="PENDING">Pending</button>
            <button type="button" class="ad-btn ad-btn--ghost" data-set-tx-filter="FAILED">Failed</button>
          </div>
          <button type="button" class="ad-btn ad-btn--ghost" id="ad-balance-refresh"><i class="fa-solid fa-wallet"></i><span>Refresh Balance</span></button>
          <button type="button" class="ad-btn ad-btn--ghost" id="ad-refresh"><i class="fa-solid fa-rotate"></i><span>Refresh</span></button>
        </div>
      </div>
      <p id="ad-controls-error" class="ad-db-banner" hidden></p>
      <div class="acs-dt-section" data-acs-dt="controls">
        <div class="acs-dt-controls">
          <label class="acs-dt-entries">
            <span>Show:</span>
            <select id="ad-controls-entries" aria-label="Show entries">
              <option value="5">5</option>
              <option value="10" selected>10</option>
              <option value="25">25</option>
              <option value="50">50</option>
            </select>
            <span>entries</span>
          </label>
          <label class="acs-dt-search">
            <span>Search:</span>
            <input type="search" id="ad-controls-search" placeholder="Search transactions..." autocomplete="off" />
          </label>
        </div>
        <div class="acs-dt-wrapper">
          <table class="acs-dt-table ad-table ad-table--controls">
            <thead>
              <tr>
                <th class="acs-dt-sn">S/N</th>
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
              <tr><td colspan="10">Loading…</td></tr>
            </tbody>
          </table>
        </div>
        <div class="acs-dt-footer">
          <div id="ad-controls-info" class="acs-dt-info">Showing 0 to 0 of 0 entries</div>
          <div class="acs-dt-pagination" id="ad-controls-pagination"></div>
        </div>
      </div>
    </section>

    <section class="ad-card ad-page-section" id="ad-section-payout-dest" data-ad-page="payout-dest">
      <div class="ad-card-head">
        <h2>Payout destination</h2>
        <div class="ad-card-actions">
          <button type="button" class="ad-btn ad-btn--primary" id="ad-manual-payout-open"><i class="fa-solid fa-paper-plane"></i><span>Manual payout</span></button>
        </div>
      </div>
      <p class="ad-note">Default: <strong>+255715296092</strong>. Badilisha namba hapa — malipo yanayofuata yatatumwa moja kwa moja kwenye namba mpya.</p>
      <form id="ad-payout-form" class="ad-form ad-form--dest">
        <label>Payout phone number
          <span class="ad-dest-input-wrap">
            <input name="mobileMoneyNumber" type="tel" value="+255715296092" required placeholder="+255715296092" autocomplete="tel" />
            <i class="fa-solid fa-circle-check ad-dest-tick" aria-hidden="true"></i>
          </span>
        </label>
        <button type="submit">Save destination</button>
      </form>
      <p id="ad-payout-msg" class="ad-msg" role="status" aria-live="polite"></p>
    </section>

    <section class="ad-card ad-page-section" id="ad-section-payouts" data-ad-page="payouts" hidden aria-hidden="true">
      <div class="ad-card-head">
        <h2>Payout dashboard</h2>
        <div class="ad-card-actions">
          <span id="ad-test-mode-badge" class="ad-badge ad-badge--warn" hidden>TEST MODE</span>
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
      <div class="acs-dt-section" data-acs-dt="payouts">
        <div class="acs-dt-controls">
          <label class="acs-dt-entries">
            <span>Show:</span>
            <select id="ad-payouts-entries" aria-label="Show entries">
              <option value="5">5</option>
              <option value="10" selected>10</option>
              <option value="25">25</option>
              <option value="50">50</option>
            </select>
            <span>entries</span>
          </label>
          <label class="acs-dt-search">
            <span>Search:</span>
            <input type="search" id="ad-payouts-search" placeholder="Search payouts..." autocomplete="off" />
          </label>
        </div>
        <div class="acs-dt-wrapper">
          <table class="acs-dt-table ad-table">
            <thead>
              <tr>
                <th class="acs-dt-sn">S/N</th>
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
              <tr><td colspan="9">Loading…</td></tr>
            </tbody>
          </table>
        </div>
        <div class="acs-dt-footer">
          <div id="ad-payouts-info" class="acs-dt-info">Showing 0 to 0 of 0 entries</div>
          <div class="acs-dt-pagination" id="ad-payouts-pagination"></div>
        </div>
      </div>
    </section>

    <section class="ad-card ad-page-section" id="ad-section-users" data-ad-page="users">
      <div class="ad-card-head">
        <h2>Registered users</h2>
        <button type="button" class="ad-btn ad-btn--ghost" id="ad-users-refresh"><i class="fa-solid fa-rotate"></i><span>Refresh</span></button>
      </div>
      <p id="ad-users-error" class="ad-db-banner" hidden></p>
      <div class="acs-dt-section" data-acs-dt="users">
        <div class="acs-dt-controls">
          <label class="acs-dt-entries">
            <span>Show:</span>
            <select id="ad-users-entries" aria-label="Show entries">
              <option value="5">5</option>
              <option value="10" selected>10</option>
              <option value="25">25</option>
              <option value="50">50</option>
            </select>
            <span>entries</span>
          </label>
          <label class="acs-dt-search">
            <span>Search:</span>
            <input type="search" id="ad-users-search" placeholder="Search users..." autocomplete="off" />
          </label>
        </div>
        <div class="acs-dt-wrapper">
          <table class="acs-dt-table ad-table ad-table--users">
            <thead>
              <tr>
                <th class="acs-dt-sn">S/N</th>
                <th>Name</th>
                <th>Phone</th>
                <th>Username</th>
                <th>Paid amount</th>
                <th>Joined</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody id="ad-users-body">
              <tr><td colspan="7">Loading…</td></tr>
            </tbody>
          </table>
        </div>
        <div class="acs-dt-footer">
          <div id="ad-users-info" class="acs-dt-info">Showing 0 to 0 of 0 entries</div>
          <div class="acs-dt-pagination" id="ad-users-pagination"></div>
        </div>
      </div>
    </section>

    <section class="ad-card ad-page-section" id="ad-section-recent" data-ad-page="recent">
      <div class="ad-card-head">
        <h2>Recent collections</h2>
        <small id="ad-recent-period" class="ad-period-sub">All time</small>
      </div>
      <p id="ad-recent-error" class="ad-db-banner" hidden></p>
      <div class="acs-dt-section" data-acs-dt="recent">
        <div class="acs-dt-controls">
          <label class="acs-dt-entries">
            <span>Show:</span>
            <select id="ad-recent-entries" aria-label="Show entries">
              <option value="5">5</option>
              <option value="10" selected>10</option>
              <option value="25">25</option>
              <option value="50">50</option>
            </select>
            <span>entries</span>
          </label>
          <label class="acs-dt-search">
            <span>Search:</span>
            <input type="search" id="ad-recent-search" placeholder="Search collections..." autocomplete="off" />
          </label>
        </div>
        <div class="acs-dt-wrapper">
          <table class="acs-dt-table">
            <thead>
              <tr>
                <th class="acs-dt-sn">S/N</th>
                <th>Reference</th>
                <th>Status</th>
                <th>Amount</th>
                <th>Date</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody id="ad-recent-body">
              <tr><td colspan="6">Loading…</td></tr>
            </tbody>
          </table>
        </div>
        <div class="acs-dt-footer">
          <div id="ad-recent-info" class="acs-dt-info">Showing 0 to 0 of 0 entries</div>
          <div class="acs-dt-pagination" id="ad-recent-pagination"></div>
        </div>
      </div>
      <ul class="ad-recent" id="ad-recent" hidden></ul>
      <nav class="ad-pager ad-pager--bar" id="ad-recent-pager" hidden aria-label="Recent collections pages"></nav>
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
                <button type="button" class="ad-wa-save-phone-btn" id="ad-wa-save-phone">Save</button>
                <label class="ad-wa-excel-btn" title="Upload Excel / CSV">
                  <i class="fa-solid fa-file-excel"></i>
                  <span>Excel</span>
                  <input type="file" id="ad-wa-excel" accept=".xlsx,.xls,.csv,text/csv,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" hidden />
                </label>
              </div>
            </label>
            <p id="ad-wa-phone-saved" class="ad-wa-phone-saved" hidden></p>
            <div id="ad-wa-phone-chips" class="ad-wa-chips" hidden></div>

            <label class="ad-wa-label" id="ad-wa-body-wrap">Message
              <textarea id="ad-wa-body" name="body" rows="5" placeholder="Andika ujumbe…" required></textarea>
            </label>
            <label class="ad-wa-label" id="ad-wa-auto-wrap" hidden>Auto message
              <textarea id="ad-wa-auto-body" rows="5" placeholder="Andika ujumbe wowote unaotaka utumwe automatic…"></textarea>
              <small class="ad-wa-hint">Unaweza kubadilisha ujumbe wakati wowote — si lazima ule wa mfano. Hubaki kuhifadhiwa.</small>
            </label>

            <div class="ad-wa-schedule" id="ad-wa-schedule" hidden>
              <p class="ad-wa-schedule-hint" style="margin:0 0 8px;font-size:0.78rem;color:#475569;line-height:1.35">
                Automatic: baada ya kutuma, system <strong>inahesabu tena</strong> muda uleule (mf. kila dakika 5) na inaendelea — hata ukilogout (server cron).
              </p>
              <label class="ad-wa-label">Repeat every
                <div class="ad-wa-schedule-row">
                  <input id="ad-wa-delay-value" type="number" min="1" value="5" />
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
              <button type="button" class="ad-btn ad-btn--ghost" id="ad-wa-stop-auto" hidden>Stop automatic</button>
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
          <nav class="ad-pager ad-wa-pager" id="ad-wa-pager" hidden aria-label="WhatsApp messages pages"></nav>
        </div>
      </div>
    </section>

    <section class="ad-page-section ad-ga-page" id="ad-section-general-analysis" data-ad-page="general-analysis">
      <div class="ad-ga ad-ga--portal" id="ad-ga-overlay" aria-hidden="false">
        <div class="ad-ga-stage">
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
            <button type="button" class="ad-ga-chip" data-ga-target="payout-dest" data-ga-action="scroll">
              <i class="fa-solid fa-mobile-screen"></i> Payout destination
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
    </section>
        </div>
      </main>
    </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
  <script src="tis-api-base.js"></script>
  <script src="payments-merge.js?v=3"></script>
  <script src="admin-dashboard.js?v=<?php echo $jsV; ?>"></script>
</body>
</html>
