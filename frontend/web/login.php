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
$cssV = (string) (@filemtime(__DIR__ . '/acs-portal.css') ?: time());
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
  <title>ACS Portal | Login</title>
  <link rel="icon" type="image/png" href="images/favicon.png" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
  <link rel="stylesheet" href="acs-portal.css?v=<?= urlencode($cssV) ?>" />
  <script>
    window.GETWAY_GOOGLE_CLIENT_ID = <?= json_encode($googleClientId, JSON_UNESCAPED_SLASHES) ?>;
    window.GETWAY_NEXT = <?= json_encode($next, JSON_UNESCAPED_SLASHES) ?>;
  </script>
</head>
<body class="acs-body">
<?php
$acsBrand = 'ACS Portal';
$acsLine1 = 'NATIONAL AUTOMATIC COLLECTION AUTHORITY';
$acsLine2 = 'THE AUTOMATIC COLLECTION SYSTEM PORTAL';
$acsLine3 = 'EFFICIENT, SECURE AND TRANSPARENT SERVICES';
require __DIR__ . '/acs-gov-banner.php';
?>

  <div class="acs-auth-wrap">
    <div class="auth-card">
      <h1>Login</h1>
      <p class="auth-subtitle">Sign in to continue to ACS Portal</p>

      <div class="auth-tabs" role="tablist" aria-label="Login as">
        <button type="button" class="is-active" data-login-mode="admin" role="tab" aria-selected="true">Admin</button>
        <button type="button" data-login-mode="user" role="tab" aria-selected="false">User</button>
      </div>

      <form id="login-form" class="auth-form" autocomplete="on">
        <input type="hidden" name="role" id="login-role" value="admin" />

        <label class="form-group" for="username">
          Mobile number / username
          <div class="input-row" id="username-box">
            <span class="cc" aria-hidden="true">
              <img src="images/flag-tz.svg?v=4" width="18" height="12" alt="" />
              +255
            </span>
            <input id="username" name="username" type="text" value="admin" placeholder="admin" autocomplete="username" required />
          </div>
        </label>

        <label class="form-group" for="password">
          Password
          <div class="pass-wrap">
            <input id="password" name="password" type="password" placeholder="Password" autocomplete="current-password" required />
            <button type="button" class="eye-btn" data-password-toggle aria-label="Show password">
              <i class="fa-regular fa-eye"></i>
            </button>
          </div>
        </label>

        <div class="auth-forgot">
          <a href="forgot-password.php">Forgot password?</a>
        </div>

        <button class="auth-bio" type="button" id="pin-open-btn" aria-label="Login with PIN">
          <i class="fa-solid fa-fingerprint"></i>
        </button>

        <button class="primary-button mb-login-btn" type="submit">Login</button>
      </form>

      <section id="pin-panel" class="auth-pin" hidden>
        <p class="auth-pin-title">Enter PIN</p>
        <p class="auth-subtitle" style="margin-bottom:12px">Enter your admin PIN</p>
        <div class="auth-pin-row" id="pin-digits">
          <input type="password" inputmode="numeric" maxlength="1" aria-label="PIN digit 1" />
          <input type="password" inputmode="numeric" maxlength="1" aria-label="PIN digit 2" />
          <input type="password" inputmode="numeric" maxlength="1" aria-label="PIN digit 3" />
          <input type="password" inputmode="numeric" maxlength="1" aria-label="PIN digit 4" />
        </div>
        <button type="button" class="primary-button" id="pin-login-btn">Login with PIN</button>
        <button type="button" class="logout-button" id="pin-cancel-btn" style="display:block;width:100%;margin-top:10px;text-align:center">Cancel</button>
      </section>

      <p id="auth-message" class="acs-alert" role="status"></p>

      <p class="auth-switch">
        Don't have an account?
        <a href="register.php">Create Account</a>
      </p>
    </div>
  </div>

  <script src="mb-login-lang.js?v=3"></script>
  <script src="auth.js?v=10"></script>
</body>
</html>
