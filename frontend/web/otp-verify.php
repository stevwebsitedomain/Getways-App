<?php
declare(strict_types=1);
require_once __DIR__ . '/auth-init.php';
gwAuthStartSession();
$flow = strtolower(trim((string) ($_GET['flow'] ?? 'register')));
if ($flow !== 'register' && $flow !== 'reset' && $flow !== 'login') {
    $flow = 'register';
}
if ($flow === 'register' || $flow === 'login') {
    header('Location: ' . ($flow === 'login' ? 'login.php' : 'register.php'));
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
  <title>Getway | OTP Verification</title>
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
      <h1 data-i18n="otp_title">Mobile Banking Security Check</h1>
      <?php require __DIR__ . '/mb-login-lang.php'; ?>
    </header>

    <p id="otp-phone-hint" class="mb-sub" data-i18n="otp_sub">
      Enter the OTP sent to your phone to reset your password.
    </p>

    <form id="otp-form" class="mb-form">
      <div class="mb-pin-row mb-otp-row" id="otp-digits">
        <input type="text" inputmode="numeric" maxlength="1" aria-label="OTP digit 1" />
        <input type="text" inputmode="numeric" maxlength="1" aria-label="OTP digit 2" />
        <input type="text" inputmode="numeric" maxlength="1" aria-label="OTP digit 3" />
        <input type="text" inputmode="numeric" maxlength="1" aria-label="OTP digit 4" />
        <input type="text" inputmode="numeric" maxlength="1" aria-label="OTP digit 5" />
        <input type="text" inputmode="numeric" maxlength="1" aria-label="OTP digit 6" />
      </div>
      <input type="hidden" id="otp" name="otp" />
      <input type="hidden" id="flow" name="flow" value="<?= htmlspecialchars($flow, ENT_QUOTES) ?>" />

      <div class="mb-actions mb-actions--solo">
        <button class="mb-login-btn" type="submit" data-i18n="confirm_otp">CONFIRM OTP</button>
      </div>
    </form>

    <p class="mb-temp" data-i18n="otp_resend">Did not receive the code?</p>
    <a class="mb-register" href="forgot-password.php" data-i18n="otp_again">Send again</a>

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
