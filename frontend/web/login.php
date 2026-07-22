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
  <link rel="stylesheet" href="mb-login.css?v=8" />
  <script>
    window.GETWAY_GOOGLE_CLIENT_ID = <?= json_encode($googleClientId, JSON_UNESCAPED_SLASHES) ?>;
    window.GETWAY_NEXT = <?= json_encode($next, JSON_UNESCAPED_SLASHES) ?>;
  </script>
</head>
<body class="mb-login">
  <main class="mb-shell">
    <header class="mb-head">
      <h1 data-i18n="login_title">Login to Mobile Banking</h1>
      <?php require __DIR__ . '/mb-login-lang.php'; ?>
    </header>

    <div class="mb-mode" role="tablist" aria-label="Login as">
      <button type="button" class="mb-mode-btn is-active" data-login-mode="user" role="tab" aria-selected="true" data-i18n="user">User</button>
      <button type="button" class="mb-mode-btn" data-login-mode="admin" role="tab" aria-selected="false" data-i18n="admin">Admin</button>
    </div>

    <form id="login-form" class="mb-form" autocomplete="on">
      <input type="hidden" name="role" id="login-role" value="user" />

      <div class="mb-fields">
        <label class="mb-field">
          <span class="mb-sr" data-i18n="username">Username</span>
          <input id="username" name="username" type="text" placeholder="Username" data-i18n="username" autocomplete="username" required />
        </label>
        <div class="mb-field mb-field--pass">
          <input id="password" name="password" type="password" placeholder="Password" data-i18n="password" autocomplete="current-password" required />
          <button type="button" class="mb-eye" data-password-toggle aria-label="Show password">
            <i class="fa-regular fa-eye"></i>
          </button>
          <a class="mb-forgot" href="forgot-password.php" data-i18n="forgot_link">FORGOT?</a>
        </div>
      </div>

      <div class="mb-actions">
        <button class="mb-login-btn" type="submit" data-i18n="login_btn">LOGIN</button>
        <button class="mb-bio-btn" type="button" id="pin-open-btn" aria-label="Login with PIN / fingerprint">
          <i class="fa-solid fa-fingerprint"></i>
        </button>
      </div>
    </form>

    <section id="pin-panel" class="mb-pin" hidden>
      <p class="mb-pin-title" data-i18n="pin_title">Enter PIN</p>
      <p class="mb-pin-hint"><span data-i18n="pin_hint">Admin password:</span> <strong>0000</strong></p>
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
      <button type="button" class="mb-login-btn mb-pin-submit" id="pin-login-btn" data-i18n="pin_login">LOGIN WITH PIN</button>
      <button type="button" class="mb-pin-cancel" id="pin-cancel-btn" data-i18n="pin_cancel">Cancel</button>
    </section>

    <p class="mb-temp" data-i18n="temp_id">Have a temporary User ID &amp; Password?</p>
    <a class="mb-register" href="register.php" data-i18n="register_here">Register Here</a>

    <p id="auth-message" class="mb-alert" role="status"></p>

    <aside class="mb-notify" aria-label="Notification">
      <div class="mb-notify-icon"><i class="fa-solid fa-circle-info"></i></div>
      <div>
        <strong data-i18n="notify_title">Notification</strong>
        <p data-i18n="notify_body">Stay safe, access wallet, BillPay control numbers &amp; payouts with Getway.</p>
      </div>
    </aside>
  </main>
  <script src="mb-login-lang.js?v=2"></script>
  <script src="auth.js?v=7"></script>
</body>
</html>
