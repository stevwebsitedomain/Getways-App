<?php
/** @var string $activeNav One of: home|history|pay|autopay|search|more */
$activeNav = $activeNav ?? 'home';
?>
    <nav class="w-bottom-nav" aria-label="Primary">
      <a href="part-two.php" class="w-nav-item<?= $activeNav === 'home' ? ' is-active' : '' ?>"<?= $activeNav === 'home' ? ' aria-current="page"' : '' ?>>
        <span class="w-nav-ico"><i class="fa-solid fa-house"></i></span>
        <span class="w-nav-txt" data-i18n="home">Home</span>
      </a>
      <a href="payment-details.php?type=success" class="w-nav-item<?= $activeNav === 'history' ? ' is-active' : '' ?>"<?= $activeNav === 'history' ? ' aria-current="page"' : '' ?>>
        <span class="w-nav-ico"><i class="fa-solid fa-clock-rotate-left"></i></span>
        <span class="w-nav-txt" data-i18n="history">History</span>
      </a>
      <a href="create-payment.php" class="w-nav-item w-nav-item--pay<?= $activeNav === 'pay' ? ' is-active' : '' ?>"<?= $activeNav === 'pay' ? ' aria-current="page"' : '' ?>>
        <span class="w-nav-ico"><i class="fa-solid fa-wallet"></i></span>
        <span class="w-nav-txt" data-i18n="pay">Pay</span>
      </a>
      <a href="autopay.php" class="w-nav-item w-nav-item--autopay<?= $activeNav === 'autopay' ? ' is-active' : '' ?>"<?= $activeNav === 'autopay' ? ' aria-current="page"' : '' ?>>
        <span class="w-nav-ico"><i class="fa-solid fa-bolt"></i></span>
        <span class="w-nav-txt" data-i18n="autopay">AutoPay</span>
      </a>
      <a href="#" class="w-nav-item<?= $activeNav === 'search' ? ' is-active' : '' ?>" data-search-toggle>
        <span class="w-nav-ico"><i class="fa-solid fa-magnifying-glass"></i></span>
        <span class="w-nav-txt" data-i18n="search">Search</span>
      </a>
      <a href="settings.php" class="w-nav-item<?= $activeNav === 'more' ? ' is-active' : '' ?>"<?= $activeNav === 'more' ? ' aria-current="page"' : '' ?>>
        <span class="w-nav-ico"><i class="fa-solid fa-user"></i></span>
        <span class="w-nav-txt" data-i18n="settings">Settings</span>
      </a>
    </nav>
