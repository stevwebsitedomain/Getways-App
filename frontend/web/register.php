<?php
declare(strict_types=1);
require_once __DIR__ . '/env-load.php';
require_once __DIR__ . '/auth-init.php';
gwLoadEnv();
header('Cross-Origin-Opener-Policy: same-origin-allow-popups');
gwAuthStartSession();
if (isset($_SESSION['gw_auth_user']) && is_array($_SESSION['gw_auth_user'])) {
    $role = strtolower((string) ($_SESSION['gw_auth_user']['role'] ?? 'user'));
    header('Location: ' . ($role === 'admin' ? 'admin-dashboard.php' : 'part-two.php'));
    exit;
}
$googleClientId = trim((string) (getenv('GOOGLE_CLIENT_ID') ?: ''));
$cssV = (string) (@filemtime(__DIR__ . '/acs-portal.css') ?: time());
$jsV = (string) (@filemtime(__DIR__ . '/auth.js') ?: time());
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
  <title>ACS Portal | Create Account</title>
  <link rel="icon" type="image/png" href="images/favicon.png" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
  <link rel="stylesheet" href="acs-portal.css?v=<?= urlencode($cssV) ?>" />
  <script>
    window.GETWAY_GOOGLE_CLIENT_ID = <?= json_encode($googleClientId, JSON_UNESCAPED_SLASHES) ?>;
  </script>
  <script src="https://accounts.google.com/gsi/client" async defer></script>
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
      <h1>Create Account</h1>
      <p class="auth-subtitle">Register to use ACS Portal collection services</p>

      <form id="register-form" class="auth-form" autocomplete="on">
        <label class="form-group" for="fullName">
          Full Name
          <input id="fullName" name="fullName" type="text" placeholder="Enter your name" autocomplete="name" required />
        </label>

        <label class="form-group" for="phone">
          Phone Number
          <input id="phone" name="phone" type="tel" placeholder="+2557XXXXXXXX" autocomplete="tel" required />
        </label>

        <label class="form-group" for="password">
          Password
          <div class="pass-wrap">
            <input id="password" name="password" type="password" placeholder="Create a password" autocomplete="new-password" minlength="4" required />
            <button type="button" class="eye-btn" data-password-toggle aria-label="Show password">
              <i class="fa-regular fa-eye"></i>
            </button>
          </div>
        </label>

        <button class="primary-button mb-login-btn" type="submit">Create Account</button>
      </form>

      <?php require __DIR__ . '/acs-google-signin.php'; ?>

      <p id="auth-message" class="acs-alert" role="status"></p>

      <p class="auth-switch">
        Already have an account?
        <a href="login.php">Login</a>
      </p>
    </div>
  </div>

  <script src="mb-login-lang.js?v=2"></script>
  <script src="auth.js?v=<?= urlencode($jsV) ?>"></script>
</body>
</html>
