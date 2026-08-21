<?php
/** @var string $activeNav One of: home|history|pay|autopay|services|profile|settings */
$activeNav = $activeNav ?? 'home';
$isMore = in_array($activeNav, ['profile', 'settings'], true);
?>
    <nav class="w-bottom-nav ch-bottom-nav" aria-label="Primary">
      <a href="part-two.php" class="w-nav-item<?= $activeNav === 'home' ? ' is-active' : '' ?>"<?= $activeNav === 'home' ? ' aria-current="page"' : '' ?>>
        <span class="w-nav-ico"><i class="fa-solid fa-house"></i></span>
        <span class="w-nav-txt">Accounts</span>
      </a>
      <a href="create-payment.php" class="w-nav-item<?= $activeNav === 'pay' ? ' is-active' : '' ?>"<?= $activeNav === 'pay' ? ' aria-current="page"' : '' ?>>
        <span class="w-nav-ico"><i class="fa-solid fa-money-bill-transfer"></i></span>
        <span class="w-nav-txt">Pay &amp; Transfer</span>
      </a>
      <a href="autopay.php" class="w-nav-item<?= $activeNav === 'autopay' ? ' is-active' : '' ?>"<?= $activeNav === 'autopay' ? ' aria-current="page"' : '' ?>>
        <span class="w-nav-ico"><i class="fa-solid fa-award"></i></span>
        <span class="w-nav-txt">Rewards</span>
      </a>
      <a href="control-number.php" class="w-nav-item<?= $activeNav === 'services' ? ' is-active' : '' ?>"<?= $activeNav === 'services' ? ' aria-current="page"' : '' ?>>
        <span class="w-nav-ico"><i class="fa-solid fa-mobile-screen-button"></i></span>
        <span class="w-nav-txt">Deposit</span>
      </a>
      <a href="settings.php" class="w-nav-item<?= $isMore ? ' is-active' : '' ?>"<?= $isMore ? ' aria-current="page"' : '' ?>>
        <span class="w-nav-ico"><i class="fa-solid fa-bars"></i></span>
        <span class="w-nav-txt">More</span>
      </a>
    </nav>
