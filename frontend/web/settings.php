<?php
require __DIR__ . '/auth-guard.php';
$authUser = $_SESSION['gw_auth_user'] ?? [];
$authName = trim((string) ($authUser['fullName'] ?? 'Customer'));
if ($authName === '') {
    $authName = 'Customer';
}
$authAvatar = trim((string) ($authUser['avatar'] ?? ''));
$authInitial = strtoupper(substr($authName, 0, 1));
if ($authInitial === '') {
    $authInitial = 'U';
}
$cssVersion = (string) (@filemtime(__DIR__ . '/part-two.css') ?: time());
$shellVersion = (string) (@filemtime(__DIR__ . '/wallet-shell.js') ?: time());
$settingsJsVersion = (string) (@filemtime(__DIR__ . '/settings.js') ?: time());
$phoneTopbarTitle = 'Profile & Settings';
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
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" />
  <style>
    body.settings-page { background: #f3f6fb; min-height: 100vh; }
    body.settings-page .settings-content { padding: 0 0 24px; }
    body.settings-page .tis-card { margin-bottom: 14px; }
  </style>
</head>
<body class="tis-shell tis-wallet-dash layout-phone w-home-sample settings-page">
  <main class="tis-wrap w-shell">
    <div class="w-app">
      <section class="w-phone-topbar w-searchable" aria-label="App quick header">
        <a href="part-two.php" class="w-phone-icon-btn w-phone-icon-link" aria-label="Back to home">
          <i class="fa-solid fa-arrow-left"></i>
        </a>
        <div class="w-phone-greet">
          <p data-i18n="welcome">Welcome</p>
          <h1><?= htmlspecialchars($phoneTopbarTitle, ENT_QUOTES) ?></h1>
        </div>
        <div class="w-phone-top-actions">
          <a href="settings.php" class="w-phone-profile-btn" aria-label="Profile" title="<?= htmlspecialchars($authName, ENT_QUOTES) ?>">
            <?php if ($authAvatar !== ''): ?>
              <img class="w-phone-profile-avatar" src="<?= htmlspecialchars($authAvatar, ENT_QUOTES) ?>" alt="" />
            <?php else: ?>
              <span class="w-phone-profile-avatar w-phone-profile-avatar--fallback" aria-hidden="true"><i class="fa-solid fa-user"></i></span>
            <?php endif; ?>
          </a>
          <a href="logout.php" class="w-phone-icon-btn w-phone-icon-link" aria-label="Logout">
            <i class="fa-solid fa-right-from-bracket"></i>
          </a>
        </div>
      </section>

      <div class="settings-content w-page-content">
        <section class="tis-card">
          <h2><i class="fa-solid fa-user"></i> Profile</h2>
          <div class="w-profile-block">
            <div class="w-profile-avatar-wrap">
              <img
                id="profile-avatar-preview"
                class="w-profile-avatar w-profile-avatar--photo<?= $authAvatar === '' ? ' is-hidden' : '' ?>"
                src="<?= $authAvatar !== '' ? htmlspecialchars($authAvatar, ENT_QUOTES) : '' ?>"
                alt="Profile picture"
              />
              <div id="profile-avatar-fallback" class="w-profile-avatar-fallback<?= $authAvatar !== '' ? ' is-hidden' : '' ?>" aria-hidden="<?= $authAvatar !== '' ? 'true' : 'false' ?>">
                <i class="fa-solid fa-user" aria-hidden="true"></i>
              </div>
            </div>
            <label class="w-profile-upload">
              <input type="file" id="profile-avatar-input" accept="image/*" hidden />
              <span><i class="fa-solid fa-camera"></i> Change profile picture</span>
            </label>
          </div>
          <form id="profile-form" class="tis-form" style="margin-top: 14px;">
            <label for="profile-name">Full name</label>
            <input type="text" id="profile-name" name="fullName" value="<?= htmlspecialchars($authName, ENT_QUOTES) ?>" required minlength="2" />
            <button class="btn-primary" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save profile</button>
          </form>
          <p id="profile-message" class="form-message"></p>
        </section>

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
    </div>

<?php $activeNav = 'more'; require __DIR__ . '/wallet-bottom-nav.php'; ?>
  </main>

  <script src="tis-api-base.js"></script>
  <script src="script.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
  <script src="wallet-shell.js?v=<?= urlencode($shellVersion) ?>"></script>
  <?php if (is_file(__DIR__ . '/settings.js')): ?>
  <script src="settings.js?v=<?= urlencode($settingsJsVersion) ?>"></script>
  <?php endif; ?>
</body>
</html>
