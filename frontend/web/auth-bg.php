<?php
declare(strict_types=1);
require_once __DIR__ . '/auth-init.php';
$authBgUrl = gwAuthBgImageUrl();
?>
<style id="auth-bg-style">
body.auth-body.auth-body--login,
body.auth-body.auth-body--register,
body.auth-body.auth-body--lets-go {
  background-color: #1a2332 !important;
  background-image:
    linear-gradient(rgba(15, 23, 42, 0.58), rgba(15, 23, 42, 0.58)),
    url("<?= htmlspecialchars($authBgUrl, ENT_QUOTES, 'UTF-8') ?>") !important;
  background-size: cover !important;
  background-position: center !important;
  background-repeat: no-repeat !important;
  background-attachment: fixed !important;
}
</style>
