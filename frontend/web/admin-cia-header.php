<?php

declare(strict_types=1);

/**
 * Shared admin header for CIA radar pages.
 * Optional: $ciaPageTitle, $ciaPageKicker
 */
$authUser = $_SESSION['gw_auth_user'] ?? [];
$authName = htmlspecialchars(trim((string) ($authUser['fullName'] ?? 'Admin')), ENT_QUOTES);
$ciaPageTitle = htmlspecialchars(trim((string) ($ciaPageTitle ?? 'AI Motion Radar')), ENT_QUOTES);
$ciaPageKicker = htmlspecialchars(trim((string) ($ciaPageKicker ?? 'CIA Perimeter Detection')), ENT_QUOTES);
?>
  <header class="ad-top">
    <div>
      <p class="ad-eyebrow"><?= $ciaPageKicker ?></p>
      <h1><?= $ciaPageTitle ?></h1>
    </div>
    <div class="ad-top-actions">
      <a class="ad-link" href="admin-dashboard.php"><i class="fa-solid fa-gauge-high"></i> Admin dashboard</a>
      <a class="ad-link ad-link--cia" href="cia-radar.php"><i class="fa-solid fa-satellite-dish"></i> Radar</a>
      <a class="ad-link" href="cia-radar-settings.php"><i class="fa-solid fa-sliders"></i> Settings</a>
      <span class="ad-user"><?= $authName ?></span>
      <a class="ad-link ad-link--danger" href="logout.php">Logout</a>
    </div>
  </header>
