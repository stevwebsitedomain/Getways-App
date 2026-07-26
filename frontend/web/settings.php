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
$bkVersion = (string) (@filemtime(__DIR__ . '/wallet-banking-theme.css') ?: time());
$shellVersion = (string) (@filemtime(__DIR__ . '/wallet-shell.js') ?: time());
$settingsJsVersion = (string) (@filemtime(__DIR__ . '/settings.js') ?: time());
$phoneTopbarTitle = 'Profile';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
  <title>Getway | Profile</title>
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
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" />
</head>
<body class="tis-shell tis-wallet-dash layout-phone w-home-sample bk-theme">
  <main class="tis-wrap w-shell">
    <div class="w-app">
<?php require __DIR__ . '/wallet-phone-topbar.php'; ?>

      <div class="settings-content w-page-content">
        <!-- Profile hero (Screen 2) -->
        <section class="bk-profile-hero w-searchable">
          <div class="bk-profile-avatar-wrap">
            <img
              id="profile-avatar-preview"
              class="bk-profile-avatar-lg<?= $authAvatar === '' ? ' is-hidden' : '' ?>"
              src="<?= $authAvatar !== '' ? htmlspecialchars($authAvatar, ENT_QUOTES) : '' ?>"
              alt="Profile picture"
            />
            <div id="profile-avatar-fallback" class="bk-profile-avatar-lg bk-profile-avatar-lg--fallback<?= $authAvatar !== '' ? ' is-hidden' : '' ?>" aria-hidden="<?= $authAvatar !== '' ? 'true' : 'false' ?>">
              <?= htmlspecialchars($authInitial, ENT_QUOTES) ?>
            </div>
            <label class="bk-profile-edit-btn" for="profile-avatar-input" aria-label="Change profile picture">
              <i class="fa-solid fa-pen"></i>
            </label>
            <input type="file" id="profile-avatar-input" accept="image/*" hidden />
          </div>
          <h2 class="bk-profile-name"><?= htmlspecialchars($authName, ENT_QUOTES) ?></h2>
        </section>

        <!-- Profile menu (Screen 2) -->
        <nav class="bk-profile-menu w-searchable" aria-label="Profile options">
          <a href="tel:+255000000000" class="bk-profile-menu-item">
            <i class="fa-solid fa-phone"></i>
            <span data-i18n="call_bank">Call to the bank</span>
          </a>
          <a href="create-payment.php" class="bk-profile-menu-item">
            <i class="fa-solid fa-calendar-check"></i>
            <span data-i18n="bank_appointment">Bank appointment consultation</span>
          </a>
          <a href="#profile-form" class="bk-profile-menu-item">
            <i class="fa-solid fa-id-card"></i>
            <span data-i18n="personal_info">Personal information</span>
          </a>
        </nav>

        <!-- Personal info form -->
        <section class="tis-card w-searchable" style="margin-top: 16px;">
          <h2><i class="fa-solid fa-user"></i> <span data-i18n="personal_info">Personal information</span></h2>
          <form id="profile-form" class="tis-form" style="margin-top: 14px;">
            <label for="profile-name">Full name</label>
            <input type="text" id="profile-name" name="fullName" value="<?= htmlspecialchars($authName, ENT_QUOTES) ?>" required minlength="2" />
            <button class="btn-primary bk-btn-primary" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save profile</button>
          </form>
          <p id="profile-message" class="form-message"></p>
        </section>

        <!-- Settings -->
        <section class="tis-card w-searchable" style="margin-top: 14px;">
          <h2><i class="fa-solid fa-gear"></i> <span data-i18n="settings">Settings</span></h2>

          <div class="section-heading">
            <h3><i class="fa-solid fa-mobile-screen-button"></i> Layout</h3>
          </div>
          <div class="w-toggle w-toggle--layout" role="group" aria-label="Layout">
            <button type="button" class="w-toggle-btn is-active" data-layout="phone">Phone</button>
            <button type="button" class="w-toggle-btn" data-layout="desktop">Desktop</button>
          </div>

          <div class="section-heading" style="margin-top:16px;">
            <h3><i class="fa-solid fa-language"></i> Language</h3>
          </div>
          <div class="w-toggle" role="group" aria-label="Language">
            <button type="button" class="w-toggle-btn is-active" data-language-option="en">English</button>
            <button type="button" class="w-toggle-btn" data-language-option="sw">Kiswahili</button>
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

<?php $activeNav = 'profile'; require __DIR__ . '/wallet-bottom-nav.php'; ?>
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
