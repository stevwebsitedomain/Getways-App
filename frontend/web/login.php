<?php
declare(strict_types=1);
require_once __DIR__ . '/auth-init.php';
gwAuthStartSession();
if (isset($_SESSION['gw_auth_user']) && is_array($_SESSION['gw_auth_user'])) {
    $role = strtolower((string) ($_SESSION['gw_auth_user']['role'] ?? 'user'));
    header('Location: ' . ($role === 'admin' ? 'admin-dashboard.php' : 'part-two.php'));
    exit;
}
$googleClientId = getenv('GOOGLE_CLIENT_ID') ?: '';
$next = trim((string) ($_GET['next'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
  <title>Getway | Login</title>
  <link rel="icon" type="image/png" href="images/favicon.png" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
  <link rel="stylesheet" href="mb-login.css?v=11" />
  <script>
    window.GETWAY_GOOGLE_CLIENT_ID = <?= json_encode($googleClientId, JSON_UNESCAPED_SLASHES) ?>;
    window.GETWAY_NEXT = <?= json_encode($next, JSON_UNESCAPED_SLASHES) ?>;
  </script>
</head>
<body class="mb-login mb-login--efaw">
  <main class="mb-shell">
    <header class="mb-efaw-top">
      <?php require __DIR__ . '/mb-login-lang.php'; ?>
    </header>

    <div class="mb-brand">
      <img
        class="mb-logo-img"
        src="images/digital-matrix-technology.png?v=1"
        alt="Digital Matrix Technology"
        width="320"
        height="80"
      />
    </div>

    <div class="mb-tabs" role="tablist" aria-label="Login as">
      <button type="button" class="mb-tab is-active" data-login-mode="admin" role="tab" aria-selected="true" data-i18n="admin_tab">Login as Admin</button>
      <button type="button" class="mb-tab" data-login-mode="user" role="tab" aria-selected="false" data-i18n="user_tab">Login as User</button>
    </div>

    <form id="login-form" class="mb-form mb-efaw-form" autocomplete="on">
      <input type="hidden" name="role" id="login-role" value="admin" />

      <label class="mb-label" for="username">
        <span data-i18n="username_label">Mobile number / username</span>
      </label>
      <div class="mb-input-box" id="username-box">
        <div class="mb-cc" aria-hidden="true">
          <img class="mb-cc-flag" src="images/flag-tz.svg?v=4" width="22" height="15" alt="" />
          <span>+255</span>
        </div>
        <input id="username" name="username" type="text" value="admin" placeholder="admin" data-i18n-placeholder="username_ph_admin" autocomplete="username" required />
      </div>

      <label class="mb-label" for="password">
        <span data-i18n="password">Password</span>
      </label>
      <div class="mb-input-box mb-input-box--pass">
        <input id="password" name="password" type="password" placeholder="Password (admin: 0000)" data-i18n-placeholder="password_ph_admin" autocomplete="current-password" required />
        <button type="button" class="mb-eye" data-password-toggle aria-label="Show password">
          <i class="fa-regular fa-eye"></i>
        </button>
      </div>

      <div class="mb-forgot-row">
        <a class="mb-forgot-link" href="forgot-password.php" data-i18n="forgot_link">Forgot password?</a>
      </div>

      <button class="mb-bio-center" type="button" id="pin-open-btn" aria-label="Login with PIN / fingerprint">
        <i class="fa-solid fa-fingerprint"></i>
      </button>

      <button class="mb-login-btn" type="submit" data-i18n="login_btn">Login</button>
    </form>

    <section id="pin-panel" class="mb-pin" hidden>
      <p class="mb-pin-title" data-i18n="pin_title">Enter PIN</p>
      <p class="mb-pin-hint"><span data-i18n="pin_hint">Admin PIN:</span> <strong>0000</strong></p>
      <div class="mb-pin-row-wrap">
        <div class="mb-pin-row" id="pin-digits">
          <input type="password" inputmode="numeric" maxlength="1" aria-label="PIN digit 1" />
          <input type="password" inputmode="numeric" maxlength="1" aria-label="PIN digit 2" />
          <input type="password" inputmode="numeric" maxlength="1" aria-label="PIN digit 3" />
          <input type="password" inputmode="numeric" maxlength="1" aria-label="PIN digit 4" />
        </div>
        <button type="button" class="mb-eye mb-eye--pin" data-pin-toggle aria-label="Show PIN">
          <i class="fa-regular fa-eye"></i>
        </button>
      </div>
      <button type="button" class="mb-login-btn mb-pin-submit" id="pin-login-btn" data-i18n="pin_login">Login with PIN</button>
      <button type="button" class="mb-pin-cancel" id="pin-cancel-btn" data-i18n="pin_cancel">Cancel</button>
    </section>

    <p id="auth-message" class="mb-alert" role="status"></p>

    <div class="mb-signup-block">
      <p class="mb-signup-hint" data-i18n="new_user">New user?</p>
      <a class="mb-signup-btn" href="register.php" data-i18n="sign_up_now">Sign up now!</a>
    </div>
  </main>

  <div class="mb-skyline" aria-hidden="true"></div>

  <script src="mb-login-lang.js?v=3"></script>
  <script src="auth.js?v=10"></script>
</body>
</html>
