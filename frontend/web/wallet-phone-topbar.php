<?php

declare(strict_types=1);

/**
 * Shared in-app phone top bar — banking style (back, title, menu).
 * Optional: $phoneTopbarTitle — page heading text
 * Optional: $phoneTopbarBack — back link URL (default: part-two.php)
 */
if (!isset($authUser)) {
    require_once __DIR__ . '/auth-init.php';
    gwAuthStartSession();
    $authUser = $_SESSION['gw_auth_user'] ?? [];
}

$phoneTopbarTitle = trim((string) ($phoneTopbarTitle ?? 'Home Dashboard'));
$phoneTopbarBack = trim((string) ($phoneTopbarBack ?? 'part-two.php'));
$authName = trim((string) ($authUser['fullName'] ?? ($authName ?? 'Customer')));
if ($authName === '') {
    $authName = 'Customer';
}
$authAvatar = trim((string) ($authUser['avatar'] ?? ($authAvatar ?? '')));
$authInitial = strtoupper(substr($authName, 0, 1));
if ($authInitial === '') {
    $authInitial = 'U';
}
?>
      <section class="w-phone-topbar w-searchable" aria-label="Page header">
        <a href="<?= htmlspecialchars($phoneTopbarBack, ENT_QUOTES) ?>" class="w-phone-icon-btn w-phone-icon-link" aria-label="Go back">
          <i class="fa-solid fa-arrow-left"></i>
        </a>
        <div class="w-phone-greet">
          <h1><?= htmlspecialchars($phoneTopbarTitle, ENT_QUOTES) ?></h1>
        </div>
        <div class="w-phone-top-actions">
          <button type="button" class="w-phone-icon-btn" aria-label="Open menu" aria-expanded="false" data-phone-menu-toggle>
            <i class="fa-solid fa-bars"></i>
          </button>
        </div>
      </section>

      <section class="w-phone-menu w-searchable" data-phone-menu>
        <a href="part-two.php"><i class="fa-solid fa-chart-column"></i> Dashboard</a>
        <a href="create-payment.php"><i class="fa-solid fa-dollar-sign"></i> Pay</a>
        <a href="control-number.php"><i class="fa-solid fa-map-location-dot"></i> Services</a>
        <a href="payment-details.php?type=success"><i class="fa-solid fa-clock-rotate-left"></i> History</a>
        <a href="autopay.php"><i class="fa-solid fa-bolt"></i> AutoPay</a>
        <a href="settings.php"><i class="fa-solid fa-user"></i> Profile</a>
      </section>

      <section class="w-lang-menu" data-lang-menu aria-label="Language options" hidden>
        <button type="button" data-language-option="en">
          <img class="w-lang-flag w-lang-flag--menu" src="images/flag-gb.svg?v=1" width="24" height="12" alt="" />
          English
        </button>
        <button type="button" data-language-option="sw">
          <img class="w-lang-flag w-lang-flag--menu" src="images/flag-tz.svg?v=4" width="24" height="16" alt="" />
          Kiswahili
        </button>
      </section>
