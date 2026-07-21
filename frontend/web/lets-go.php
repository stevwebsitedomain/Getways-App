<?php
declare(strict_types=1);
require __DIR__ . '/auth-guard.php';
$role = strtolower((string) ($_SESSION['gw_auth_user']['role'] ?? 'user'));
$goUrl = $role === 'admin' ? 'admin-dashboard.php' : 'part-two.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
  <title>Getway | System</title>
  <link rel="icon" type="image/png" href="images/favicon.png" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="auth.css?v=5" />
  <?php require __DIR__ . '/auth-bg.php'; ?>
</head>
<body class="auth-body auth-body--lets-go">
  <main class="auth-shell">
    <section class="auth-stage auth-stage--single">
      <article class="auth-phone auth-welcome-card">
        <div class="auth-welcome-hero" aria-hidden="true">
          <img
            src="images/get6.webp"
            alt=""
            onerror="this.onerror=null;this.src='images/get4.webp';"
          />
        </div>
        <div class="auth-welcome-body">
          <h1>Explore the app</h1>
          <p>
            Welcome to Getway. Manage your wallet, track payments, and complete checkouts quickly from one place.
          </p>
          <a href="<?= htmlspecialchars($goUrl, ENT_QUOTES) ?>" class="auth-btn auth-btn--primary auth-welcome-btn">lets go</a>
        </div>
      </article>
    </section>
  </main>
</body>
</html>
