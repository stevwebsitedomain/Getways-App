<?php
require __DIR__ . '/auth-guard.php';
$cssVersion = (string) (@filemtime(__DIR__ . '/part-two.css') ?: time());
$bkVersion = (string) (@filemtime(__DIR__ . '/wallet-banking-theme.css') ?: time());
$shellVersion = (string) (@filemtime(__DIR__ . '/wallet-shell.js') ?: time());
$phoneTopbarTitle = 'Transactions';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
  <title>Getway | Transactions</title>
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
<body class="tis-shell tis-wallet-dash layout-phone w-home-sample bk-theme">
<?php $activeTopNav = 'history'; require __DIR__ . '/wallet-top-nav.php'; ?>

  <main class="tis-wrap w-shell">
    <div class="w-app">
<?php require __DIR__ . '/wallet-phone-topbar.php'; ?>

      <div class="w-page-content">
        <!-- Search bar (Screen 12) -->
        <div class="bk-search-bar w-searchable">
          <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
          <input
            type="search"
            id="wallet-global-search"
            placeholder="Search transactions…"
            autocomplete="off"
            spellcheck="false"
          />
        </div>

        <div class="records-pick w-searchable" role="navigation" aria-label="Payment record types">
          <a
            href="payment-details.php?type=success"
            class="records-pick-card records-pick-card--success"
            data-type="success"
          >
            <span class="rpc-ico" aria-hidden="true"><i class="fa-solid fa-circle-check"></i></span>
            <span class="rpc-label">Successful</span>
            <strong class="rpc-amt" id="pick-success-amt">TZS 0</strong>
            <span class="rpc-meta"><span id="pick-success-n">0</span> paid</span>
          </a>
          <a
            href="payment-details.php?type=failed"
            class="records-pick-card records-pick-card--failed"
            data-type="failed"
          >
            <span class="rpc-ico" aria-hidden="true"><i class="fa-solid fa-circle-xmark"></i></span>
            <span class="rpc-label">Failed</span>
            <strong class="rpc-amt" id="pick-failed-amt">TZS 0</strong>
            <span class="rpc-meta"><span id="pick-failed-n">0</span> failed</span>
          </a>
          <a
            href="payment-details.php?type=pending"
            class="records-pick-card records-pick-card--pending"
            data-type="pending"
          >
            <span class="rpc-ico" aria-hidden="true"><i class="fa-solid fa-hourglass-half"></i></span>
            <span class="rpc-label">Pending</span>
            <strong class="rpc-amt rpc-amt--plain" id="pick-pending-n">0</strong>
            <span class="rpc-meta">unpaid orders</span>
          </a>
        </div>

        <section class="tis-grid w-searchable">
          <article class="tis-card dashboard-card-only">
            <div class="details-header">
              <h2 id="details-title"><i class="fa-solid fa-clock-rotate-left"></i> Transaction History</h2>
              <span id="details-badge" class="details-badge">Loading...</span>
            </div>

            <div class="details-toolbar">
              <span id="details-count" class="details-count"></span>
              <button type="button" id="refresh-details" class="btn-refresh" title="Refresh list">
                <i class="fa-solid fa-rotate"></i> Refresh
              </button>
            </div>

            <div class="table-wrap payments-table-wrap">
              <table class="payments-detail-table">
                <thead>
                  <tr>
                    <th>Order ref</th>
                    <th>Amount</th>
                    <th>Status</th>
                    <th>Phone</th>
                    <th>Date</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody id="details-body">
                  <tr>
                    <td colspan="6" class="empty-state">Loading data...</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </article>
        </section>
      </div>
    </div>

<?php $activeNav = 'history'; require __DIR__ . '/wallet-bottom-nav.php'; ?>
  </main>

  <script src="tis-api-base.js"></script>
  <script src="script.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
  <script src="wallet-shell.js?v=<?= urlencode($shellVersion) ?>"></script>
  <script src="payments-merge.js?v=1"></script>
  <script src="receipt-actions.js?v=2"></script>
  <script src="payment-details.js?v=4"></script>
</body>
</html>
