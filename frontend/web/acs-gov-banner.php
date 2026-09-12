<?php
declare(strict_types=1);
$acsBrand = htmlspecialchars((string) ($acsBrand ?? 'ACS Portal'), ENT_QUOTES);
$acsLine1 = htmlspecialchars((string) ($acsLine1 ?? 'NATIONAL AUTOMATIC COLLECTION AUTHORITY'), ENT_QUOTES);
$acsLine2 = htmlspecialchars((string) ($acsLine2 ?? 'THE AUTOMATIC COLLECTION SYSTEM PORTAL'), ENT_QUOTES);
$acsLine3 = htmlspecialchars((string) ($acsLine3 ?? 'EFFICIENT, SECURE AND TRANSPARENT SERVICES'), ENT_QUOTES);
?>
<header class="government-banner">
  <div class="brand">
    <img
      class="crest crest-logo"
      src="images/acs-portal-logo.png"
      width="64"
      height="64"
      alt="ACS Portal"
    />
    <div class="brand-name"><?php echo $acsBrand; ?></div>
  </div>
  <div class="government-title">
    <span><?php echo $acsLine1; ?></span>
    <span><?php echo $acsLine2; ?></span>
    <span><?php echo $acsLine3; ?></span>
  </div>
  <div class="jobs-seal" aria-hidden="true">
    <span class="jobs-seal-ico"><i class="fa-solid fa-briefcase"></i></span>
    <span>ACS</span>
  </div>
</header>
