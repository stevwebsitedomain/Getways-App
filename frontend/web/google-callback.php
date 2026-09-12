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
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
  <title>ACS Portal | Google Sign-In</title>
  <link rel="icon" type="image/png" href="images/favicon.png" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
  <link rel="stylesheet" href="acs-portal.css?v=<?= urlencode($cssV) ?>" />
</head>
<body class="acs-body gw-google-wait-body">
<?php
$acsBrand = 'ACS Portal';
$acsLine1 = 'NATIONAL AUTOMATIC COLLECTION AUTHORITY';
$acsLine2 = 'THE AUTOMATIC COLLECTION SYSTEM PORTAL';
$acsLine3 = 'EFFICIENT, SECURE AND TRANSPARENT SERVICES';
require __DIR__ . '/acs-gov-banner.php';
?>

  <div class="acs-auth-wrap gw-google-wait-wrap">
    <div class="gw-google-wait-card" id="google-wait-card" data-state="loading">
      <div class="gw-google-wait-orbit" aria-hidden="true">
        <span class="gw-google-wait-ring"></span>
        <span class="gw-google-wait-logo">
          <svg viewBox="0 0 48 48" width="34" height="34" aria-hidden="true">
            <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
            <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
            <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>
            <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
          </svg>
        </span>
      </div>

      <div class="gw-google-wait-avatar" id="google-wait-avatar" hidden>
        <img id="google-wait-photo" alt="" referrerpolicy="no-referrer" />
      </div>

      <h1 class="gw-google-wait-title">Signing in with Google</h1>
      <p id="auth-message" class="gw-google-wait-msg" role="status">Connecting your account securely…</p>

      <div class="gw-google-wait-dots" aria-hidden="true">
        <span></span><span></span><span></span>
      </div>

      <div class="gw-google-wait-meta">
        <span class="gw-google-wait-chip">
          <i class="fa-solid fa-shield-halved"></i>
          Secure Google OAuth
        </span>
        <span class="gw-google-wait-chip">
          <i class="fa-solid fa-<?= $wantedRole === 'admin' ? 'user-shield' : 'user' ?>"></i>
          <?= $wantedRole === 'admin' ? 'Admin access' : 'User access' ?>
        </span>
      </div>

      <p class="gw-google-wait-back">
        <a href="login.php"><i class="fa-solid fa-arrow-left"></i> Back to Login</a>
      </p>
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
      const card = document.getElementById("google-wait-card");
      const alertEl = document.getElementById("auth-message");
      const avatarWrap = document.getElementById("google-wait-avatar");
      const photoEl = document.getElementById("google-wait-photo");

      function setState(state) {
        if (card) card.setAttribute("data-state", state);
      }

      function show(message, isError) {
        if (!alertEl) return;
        alertEl.textContent = message;
        alertEl.classList.toggle("is-error", !!isError);
        if (isError) setState("error");
      }

      function showProfile(user) {
        const avatar = String(user?.avatar || "").trim();
        const name = String(user?.fullName || "").trim();
        if (avatar && photoEl && avatarWrap) {
          photoEl.src = avatar;
          photoEl.alt = name || "Profile";
          avatarWrap.hidden = false;
        }
        if (name) {
          show("Welcome, " + name + ". Opening your dashboard…", false);
        } else {
          show("Signed in successfully. Opening your dashboard…", false);
        }
        setState("success");
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
            "Google blocked this domain. Add JavaScript origin " +
              window.GETWAY_GOOGLE_ORIGIN +
              " and redirect URI " +
              window.GETWAY_GOOGLE_CALLBACK +
              " in Google Cloud.",
            true
          );
          return;
        }
        const credential = readToken();
        if (!credential) {
          show(
            "Google did not return a sign-in token. Register this origin: " +
              window.GETWAY_GOOGLE_ORIGIN,
            true
          );
          return;
        }
        try {
          const roleWanted =
            String(window.GETWAY_GOOGLE_ROLE || "user").toLowerCase() === "admin" ? "admin" : "user";
          show("Verifying your Google account…", false);
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
          showProfile(data.user || {});
          const role = String(data.role || roleWanted || "user").toLowerCase();
          const target = role === "admin" ? "admin-dashboard.php" : data.redirect || "part-two.php";
          window.setTimeout(function () {
            window.location.href = target;
          }, 700);
        } catch (error) {
          show(error.message || "Google login failed.", true);
        }
      }

      run();
    })();
  </script>
</body>
</html>
