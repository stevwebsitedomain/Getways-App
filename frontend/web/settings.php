<?php
require __DIR__ . '/auth-guard.php';
$cssVersion = (string) (@filemtime(__DIR__ . '/part-two.css') ?: time());
$shellVersion = (string) (@filemtime(__DIR__ . '/wallet-shell.js') ?: time());
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
  <title>Getway | System | Settings</title>
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
<?php $activeTopNav = 'settings'; require __DIR__ . '/wallet-top-nav.php'; ?>

  <main class="tis-wrap w-shell">
    <div class="w-app">
      <section class="tis-card">
        <h2><i class="fa-solid fa-gear"></i> Settings</h2>
        <p class="empty-state" style="margin-top: 4px;">Layout controls are moved here for mobile.</p>

        <div class="section-heading">
          <h3><i class="fa-solid fa-mobile-screen-button"></i> Layout</h3>
        </div>
        <div class="w-toggle w-toggle--layout" role="group" aria-label="Layout">
          <button type="button" class="w-toggle-btn is-active" data-layout="phone">Phone</button>
          <button type="button" class="w-toggle-btn" data-layout="desktop">Desktop</button>
        </div>

        <div class="section-heading">
          <h3><i class="fa-solid fa-table-list"></i> Records Shortcut</h3>
        </div>
        <div class="w-toggle" role="group" aria-label="Records">
          <button type="button" class="w-toggle-btn is-active" data-mode="tzs">TZS</button>
          <a href="payment-details.php?type=success" class="w-toggle-btn w-toggle-link">Records</a>
        </div>

        <div class="section-heading" style="margin-top:16px;">
          <h3><i class="fa-solid fa-right-from-bracket"></i> Session</h3>
        </div>
        <div class="w-toggle" role="group" aria-label="Session actions">
          <a href="logout.php" class="w-toggle-btn w-toggle-link">Logout</a>
        </div>
      </section>
    </div>

<?php $activeNav = 'more'; require __DIR__ . '/wallet-bottom-nav.php'; ?>
  </main>

  <script src="tis-api-base.js"></script>
  <script src="script.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
  <script src="wallet-shell.js?v=<?= urlencode($shellVersion) ?>"></script>
</body>
</html>
