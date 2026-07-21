<?php
declare(strict_types=1);
require_once __DIR__ . '/auth-init.php';
gwAuthStartSession();
if (isset($_SESSION['gw_auth_user']) && is_array($_SESSION['gw_auth_user'])) {
    $role = strtolower((string) ($_SESSION['gw_auth_user']['role'] ?? 'user'));
    header('Location: ' . ($role === 'admin' ? 'admin-dashboard.php' : 'part-two.php'));
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
  <title>Getway | Forgot Password</title>
  <link rel="icon" type="image/png" href="images/favicon.png" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
  <link rel="stylesheet" href="mb-login.css?v=8" />
</head>
<body class="mb-login">
  <main class="mb-shell">
    <header class="mb-head">
      <h1 data-i18n="forgot_title">Forgot Mobile Banking Password</h1>
      <?php require __DIR__ . '/mb-login-lang.php'; ?>
    </header>

    <p class="mb-sub" data-i18n="forgot_sub">Enter your phone number and a new password. We will send an OTP to verify.</p>

    <form id="forgot-form" class="mb-form" autocomplete="on">
      <div class="mb-fields">
        <label class="mb-field">
          <span class="mb-sr" data-i18n="phone">Phone number</span>
          <input id="phone" name="phone" type="tel" placeholder="Phone number" data-i18n="phone" autocomplete="tel" required />
        </label>
        <label class="mb-field mb-field--pass">
          <span class="mb-sr" data-i18n="new_password">New password</span>
          <input id="newPassword" name="newPassword" type="password" placeholder="New password" data-i18n="new_password" autocomplete="new-password" required />
          <button type="button" class="mb-eye" data-password-toggle aria-label="Show password">
            <i class="fa-regular fa-eye"></i>
          </button>
        </label>
      </div>

      <div class="mb-actions mb-actions--solo">
        <button class="mb-login-btn" type="submit" data-i18n="send_otp">SEND OTP</button>
      </div>
    </form>

    <p class="mb-temp" data-i18n="remember_pw">Remember your password?</p>
    <a class="mb-register" href="login.php" data-i18n="back_login">Back to Login</a>

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
