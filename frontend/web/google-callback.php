<?php

declare(strict_types=1);

require_once __DIR__ . '/env-load.php';
require_once __DIR__ . '/auth-init.php';
require_once __DIR__ . '/google-oauth-lib.php';

gwLoadEnv();
header('Cross-Origin-Opener-Policy: same-origin-allow-popups');
gwAuthStartSession();

$postedCredential = trim((string) ($_POST['credential'] ?? ''));
$googleError = trim((string) ($_GET['error'] ?? $_GET['error_description'] ?? ''));
$origin = gwGoogleOrigin();
$callbackUrl = gwGoogleCallbackUrl();
$clientId = trim((string) (getenv('GOOGLE_CLIENT_ID') ?: ''));
$wantedRole = strtolower(trim((string) ($_SESSION['gw_google_role'] ?? 'user')));
if ($wantedRole !== 'admin') {
    $wantedRole = 'user';
}
$cssV = (string) (@filemtime(__DIR__ . '/acs-portal.css') ?: time());
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>ACS Portal | Google Sign-In</title>
  <link rel="stylesheet" href="acs-portal.css?v=<?= urlencode($cssV) ?>" />
</head>
<body class="acs-body">
  <div class="acs-auth-wrap">
    <div class="auth-card">
      <h1>Google Sign-In</h1>
      <p id="auth-message" class="acs-alert" role="status">Signing you in…</p>
      <p class="auth-switch"><a href="login.php">Back to Login</a></p>
    </div>
  </div>
  <script>
    window.GETWAY_GOOGLE_POSTED = <?= json_encode($postedCredential, JSON_UNESCAPED_SLASHES) ?>;
    window.GETWAY_GOOGLE_ERROR = <?= json_encode($googleError, JSON_UNESCAPED_SLASHES) ?>;
    window.GETWAY_GOOGLE_ORIGIN = <?= json_encode($origin, JSON_UNESCAPED_SLASHES) ?>;
    window.GETWAY_GOOGLE_CALLBACK = <?= json_encode($callbackUrl, JSON_UNESCAPED_SLASHES) ?>;
    window.GETWAY_GOOGLE_CLIENT_ID = <?= json_encode($clientId, JSON_UNESCAPED_SLASHES) ?>;
    window.GETWAY_GOOGLE_ROLE = <?= json_encode($wantedRole, JSON_UNESCAPED_SLASHES) ?>;
  </script>
  <script>
    (function () {
      const alertEl = document.getElementById("auth-message");
      function show(message) {
        if (alertEl) {
          alertEl.textContent = message;
          alertEl.classList.add("is-error");
        }
      }

      function readToken() {
        if (window.GETWAY_GOOGLE_POSTED) return String(window.GETWAY_GOOGLE_POSTED);
        const hash = new URLSearchParams(String(location.hash || "").replace(/^#/, ""));
        const query = new URLSearchParams(location.search);
        return String(hash.get("id_token") || query.get("id_token") || query.get("credential") || "").trim();
      }

      async function run() {
        if (window.GETWAY_GOOGLE_ERROR) {
          show(
            "Google blocked this domain. In Google Cloud add JavaScript origin " +
              window.GETWAY_GOOGLE_ORIGIN +
              " and redirect URI " +
              window.GETWAY_GOOGLE_CALLBACK +
              "."
          );
          return;
        }
        const credential = readToken();
        if (!credential) {
          show(
            "Google did not return a sign-in token. Add this origin in Google Cloud: " +
              window.GETWAY_GOOGLE_ORIGIN
          );
          return;
        }
        try {
          const roleWanted = String(window.GETWAY_GOOGLE_ROLE || "user").toLowerCase() === "admin" ? "admin" : "user";
          const res = await fetch("auth-api.php?action=google-login", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            credentials: "same-origin",
            body: JSON.stringify({ credential, role: roleWanted }),
          });
          const raw = await res.text();
          const data = raw ? JSON.parse(raw) : {};
          if (!res.ok || !data.ok) {
            throw new Error(data.message || "Google login failed.");
          }
          const role = String(data.role || roleWanted || "user").toLowerCase();
          window.location.href = role === "admin" ? "admin-dashboard.php" : (data.redirect || "part-two.php");
        } catch (error) {
          show(error.message || "Google login failed.");
        }
      }

      run();
    })();
  </script>
</body>
</html>
