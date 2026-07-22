<?php

declare(strict_types=1);

/**
 * Shared in-app phone top bar (yellow bar with profile avatar).
 * Optional: $phoneTopbarTitle — page heading text
 */
if (!isset($authUser)) {
    require_once __DIR__ . '/auth-init.php';
    gwAuthStartSession();
    $authUser = $_SESSION['gw_auth_user'] ?? [];
}

$phoneTopbarTitle = trim((string) ($phoneTopbarTitle ?? 'Home Dashboard'));
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
      <section class="w-phone-topbar w-searchable" aria-label="App quick header">
        <button type="button" class="w-phone-icon-btn" aria-label="Open menu" aria-expanded="false" data-phone-menu-toggle>
          <i class="fa-solid fa-bars"></i>
        </button>
        <div class="w-phone-greet">
          <p data-i18n="welcome">Welcome</p>
          <h1><?= htmlspecialchars($phoneTopbarTitle, ENT_QUOTES) ?></h1>
        </div>
        <div class="w-phone-top-actions">
          <button type="button" class="w-phone-icon-btn" aria-label="Notifications" data-top-action="history">
            <i class="fa-regular fa-bell"></i>
          </button>
          <button type="button" class="w-phone-icon-btn" aria-label="Search" data-top-action="search">
            <i class="fa-solid fa-magnifying-glass"></i>
          </button>
          <a href="settings.php" class="w-phone-profile-btn" aria-label="Open profile and settings" title="<?= htmlspecialchars($authName, ENT_QUOTES) ?>">
            <?php if ($authAvatar !== ''): ?>
              <img class="w-phone-profile-avatar" src="<?= htmlspecialchars($authAvatar, ENT_QUOTES) ?>" alt="" />
            <?php else: ?>
              <span class="w-phone-profile-avatar w-phone-profile-avatar--fallback" aria-hidden="true"><i class="fa-solid fa-user"></i></span>
            <?php endif; ?>
          </a>
          <button type="button" class="w-phone-icon-btn w-lang-btn" aria-label="Change language" aria-expanded="false" data-language-toggle>
            <img class="w-lang-flag" data-language-flag src="images/flag-gb.svg?v=1" width="22" height="11" alt="" />
          </button>
          <a href="logout.php" class="w-phone-icon-btn w-phone-icon-link" aria-label="Logout">
            <i class="fa-solid fa-right-from-bracket"></i>
          </a>
        </div>
      </section>

      <section class="w-phone-menu w-searchable" data-phone-menu>
        <a href="part-two.php"><i class="fa-solid fa-house"></i> Home</a>
        <a href="control-number.php"><i class="fa-solid fa-file-invoice-dollar"></i> Control Number</a>
        <a href="create-payment.php"><i class="fa-solid fa-wallet"></i> Pay</a>
        <a href="autopay.php"><i class="fa-solid fa-bolt"></i> AutoPay</a>
        <a href="settings.php"><i class="fa-solid fa-user"></i> Profile &amp; Settings</a>
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
