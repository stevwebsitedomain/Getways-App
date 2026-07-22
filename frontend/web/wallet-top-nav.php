<?php

declare(strict_types=1);

/**
 * Shared sticky top navigation for wallet pages.
 * Optional: $activeTopNav = 'home'|'pay'|'autopay'|'history'|'settings'
 */
$activeTopNav = $activeTopNav ?? '';

if (!isset($authName)) {
    require_once __DIR__ . '/auth-init.php';
    gwAuthStartSession();
    $authUser = $_SESSION['gw_auth_user'] ?? [];
    $authName = trim((string) ($authUser['fullName'] ?? 'Customer'));
    if ($authName === '') {
        $authName = 'Customer';
    }
    $authEmail = trim((string) ($authUser['email'] ?? ''));
    $authAvatar = trim((string) ($authUser['avatar'] ?? ''));
    $authInitial = strtoupper(substr($authName, 0, 1));
    if ($authInitial === '') {
        $authInitial = 'U';
    }
}

$isActive = static function (string $key) use ($activeTopNav): string {
    return $activeTopNav === $key ? ' active' : '';
};
?>
  <div class="navbar gw-sticky-nav" id="gw-top-nav">
    <div class="navbar-inner">
      <button type="button" class="nav-menu-toggle" aria-label="Open navigation menu" aria-expanded="false" data-nav-toggle>☰</button>
      <div class="nav-title">Getway | System</div>
      <div class="nav-links">
        <a class="<?= trim($isActive('home')) ?>" href="part-two.php">Dashboard</a>
        <a class="<?= trim($isActive('pay')) ?>" href="create-payment.php">Pay</a>
        <a class="<?= trim($isActive('autopay')) ?>" href="autopay.php">AutoPay</a>
        <a class="<?= trim($isActive('history')) ?>" href="payment-details.php?type=success">History</a>
        <a class="<?= trim($isActive('settings')) ?>" href="settings.php">Settings</a>
        <a href="logout.php">Logout</a>
      </div>
      <a class="nav-account" href="settings.php" title="<?= htmlspecialchars($authEmail !== '' ? $authEmail : $authName, ENT_QUOTES) ?>">
        <?php if (!empty($authAvatar)): ?>
          <img class="nav-account-avatar" src="<?= htmlspecialchars($authAvatar, ENT_QUOTES) ?>" alt="Profile" referrerpolicy="no-referrer" />
        <?php else: ?>
          <span class="nav-account-avatar nav-account-avatar--fallback"><?= htmlspecialchars($authInitial, ENT_QUOTES) ?></span>
        <?php endif; ?>
        <div class="nav-account-meta">
          <strong><?= htmlspecialchars($authName, ENT_QUOTES) ?></strong>
          <span><?= htmlspecialchars($authEmail !== '' ? $authEmail : 'Signed in', ENT_QUOTES) ?></span>
        </div>
      </a>
    </div>
    <div class="nav-mobile-menu" data-nav-mobile-menu>
      <a class="<?= trim($isActive('home')) ?>" href="part-two.php">Dashboard</a>
      <a class="<?= trim($isActive('pay')) ?>" href="create-payment.php">Pay</a>
      <a class="<?= trim($isActive('autopay')) ?>" href="autopay.php">AutoPay</a>
      <a class="<?= trim($isActive('history')) ?>" href="payment-details.php?type=success">History</a>
      <a class="<?= trim($isActive('settings')) ?>" href="settings.php">Settings</a>
      <a href="logout.php">Logout</a>
    </div>
  </div>
