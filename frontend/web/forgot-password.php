<?php
declare(strict_types=1);
require_once __DIR__ . '/auth-init.php';
gwAuthStartSession();
if (isset($_SESSION['gw_auth_user']) && is_array($_SESSION['gw_auth_user'])) {
    $role = strtolower((string) ($_SESSION['gw_auth_user']['role'] ?? 'user'));
    header('Location: ' . ($role === 'admin' ? 'admin-dashboard.php' : 'part-two.php'));
    exit;
}
$cssV = (string) (@filemtime(__DIR__ . '/acs-portal.css') ?: time());
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
  <title>ACS Portal | Forgot Password</title>
  <link rel="icon" type="image/png" href="images/favicon.png" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
  <link rel="stylesheet" href="acs-portal.css?v=<?= urlencode($cssV) ?>" />
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
      <h1>Forgot Password</h1>
      <p class="auth-subtitle">Enter your phone number and a new password. We will send an OTP to verify.</p>

      <form id="forgot-form" class="auth-form" autocomplete="on">
        <label class="form-group" for="phone">
          Phone Number
          <input id="phone" name="phone" type="tel" placeholder="+2557XXXXXXXX" autocomplete="tel" required />
        </label>

        <label class="form-group" for="newPassword">
          New Password
          <div class="pass-wrap">
            <input id="newPassword" name="newPassword" type="password" placeholder="New password" autocomplete="new-password" required />
            <button type="button" class="eye-btn" data-password-toggle aria-label="Show password">
              <i class="fa-regular fa-eye"></i>
            </button>
          </div>
        </label>

        <button class="primary-button mb-login-btn" type="submit">Send OTP</button>
      </form>

      <p id="auth-message" class="acs-alert" role="status"></p>

      <p class="auth-switch">
        Remember your password?
        <a href="login.php">Back to Login</a>
      </p>
    </div>
  </div>

  <script src="mb-login-lang.js?v=2"></script>
  <script src="auth.js?v=7"></script>
</body>
</html>
