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
  <title>Getway | Create Account</title>
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
      <h1 data-i18n="register_title">Create Mobile Banking Account</h1>
      <?php require __DIR__ . '/mb-login-lang.php'; ?>
    </header>

    <form id="register-form" class="mb-form" autocomplete="on">
      <div class="mb-fields">
        <label class="mb-field">
          <span class="mb-sr" data-i18n="full_name">Full name</span>
          <input id="fullName" name="fullName" type="text" placeholder="Full name" data-i18n="full_name" autocomplete="name" required />
        </label>
        <label class="mb-field">
          <span class="mb-sr" data-i18n="phone">Phone number</span>
          <input id="phone" name="phone" type="tel" placeholder="Phone number" data-i18n="phone" autocomplete="tel" required />
        </label>
        <label class="mb-field mb-field--pass">
          <span class="mb-sr" data-i18n="password">Password</span>
          <input id="password" name="password" type="password" placeholder="Password" data-i18n="password" autocomplete="new-password" required />
          <button type="button" class="mb-eye" data-password-toggle aria-label="Show password">
            <i class="fa-regular fa-eye"></i>
          </button>
        </label>
      </div>

      <div class="mb-actions mb-actions--solo">
        <button class="mb-login-btn" type="submit" data-i18n="register_btn">REGISTER</button>
      </div>
    </form>

    <p class="mb-temp" data-i18n="have_account">Already have an account?</p>
    <a class="mb-register" href="login.php" data-i18n="login_here">Login Here</a>

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
