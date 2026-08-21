<?php

declare(strict_types=1);

require __DIR__ . '/admin-guard.php';
require_once __DIR__ . '/env-load.php';

$authUser = $_SESSION['gw_auth_user'] ?? [];
$authName = trim((string) ($authUser['fullName'] ?? 'Admin'));
$config = gwUltamsgConfig();
$configured = $config['instanceId'] !== '' && $config['token'] !== '' && $config['apiUrl'] !== '';
$cssVersion = (string) (@filemtime(__DIR__ . '/admin-dashboard.css') ?: time());
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Getway | Send WhatsApp</title>
  <link rel="icon" type="image/png" href="images/favicon.png" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
  <link rel="stylesheet" href="admin-dashboard.css?v=<?= urlencode($cssVersion) ?>" />
  <style>
    .wa-page { max-width: 560px; margin: 0 auto; padding: 24px 16px 48px; }
    .wa-card {
      background: #fff;
      border: 1px solid #e5e7eb;
      border-radius: 14px;
      padding: 22px 20px;
      box-shadow: 0 4px 18px rgba(0, 45, 88, 0.08);
    }
    .wa-card h1 {
      margin: 0 0 6px;
      font-size: 1.25rem;
      color: #002d58;
      font-weight: 800;
    }
    .wa-card .wa-sub {
      margin: 0 0 18px;
      color: #64748b;
      font-size: 0.86rem;
      font-weight: 600;
    }
    .wa-meta {
      display: grid;
      gap: 6px;
      margin-bottom: 18px;
      padding: 12px;
      background: #f0fdf4;
      border: 1px solid #bbf7d0;
      border-radius: 10px;
      font-size: 0.78rem;
      font-weight: 600;
      color: #166534;
    }
    .wa-meta code { font-size: 0.75rem; word-break: break-all; }
    .wa-warn {
      background: #fff7ed;
      border-color: #fed7aa;
      color: #9a3412;
    }
    .wa-field { margin-bottom: 14px; }
    .wa-field label {
      display: block;
      font-size: 0.78rem;
      font-weight: 700;
      color: #64748b;
      margin-bottom: 6px;
    }
    .wa-field input,
    .wa-field textarea {
      width: 100%;
      box-sizing: border-box;
      padding: 12px 14px;
      border: 1px solid #e2e8f0;
      border-radius: 10px;
      font: inherit;
      font-size: 0.92rem;
      font-weight: 600;
      color: #0f172a;
      background: #f8fafc;
    }
    .wa-field textarea { min-height: 120px; resize: vertical; }
    .wa-field input:focus,
    .wa-field textarea:focus {
      outline: none;
      border-color: #25d366;
      box-shadow: 0 0 0 3px rgba(37, 211, 102, 0.18);
      background: #fff;
    }
    .wa-actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 8px; }
    .wa-btn {
      border: none;
      border-radius: 10px;
      padding: 12px 18px;
      font: inherit;
      font-weight: 800;
      font-size: 0.9rem;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 8px;
    }
    .wa-btn-primary {
      background: #25d366;
      color: #fff;
      box-shadow: 0 6px 16px rgba(37, 211, 102, 0.35);
    }
    .wa-btn-primary:disabled { opacity: 0.6; cursor: not-allowed; }
    .wa-btn-secondary {
      background: #e2e8f0;
      color: #002d58;
    }
    .wa-result {
      margin-top: 16px;
      padding: 12px 14px;
      border-radius: 10px;
      font-size: 0.82rem;
      font-weight: 600;
      white-space: pre-wrap;
      word-break: break-word;
      display: none;
    }
    .wa-result.is-ok { display: block; background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
    .wa-result.is-err { display: block; background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
    .wa-top {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 12px;
      margin-bottom: 16px;
    }
    .wa-top a {
      color: #005691;
      font-weight: 700;
      text-decoration: none;
      font-size: 0.86rem;
    }
  </style>
</head>
<body class="ad-body" style="background:#eceff3;min-height:100vh;">
  <div class="wa-page">
    <div class="wa-top">
      <a href="admin-dashboard.php"><i class="fa-solid fa-arrow-left"></i> Admin dashboard</a>
      <span style="font-size:0.8rem;font-weight:700;color:#64748b;"><?= htmlspecialchars($authName, ENT_QUOTES) ?></span>
    </div>

    <article class="wa-card">
      <h1><i class="fa-brands fa-whatsapp" style="color:#25d366"></i> Send WhatsApp (manual)</h1>
      <p class="wa-sub">Jaribu ujumbe mmoja mmoja kuona kama Ultramsg inaenda.</p>

      <?php if ($configured): ?>
        <div class="wa-meta">
          <div>Instance: <code><?= htmlspecialchars($config['instanceId'], ENT_QUOTES) ?></code></div>
          <div>API: <code><?= htmlspecialchars($config['apiUrl'], ENT_QUOTES) ?></code></div>
          <div>Token: <code>••••••••<?= htmlspecialchars(substr($config['token'], -4), ENT_QUOTES) ?></code></div>
        </div>
      <?php else: ?>
        <div class="wa-meta wa-warn">
          Ultamsg bado haijawekwa kwenye <code>.env</code>. Ongeza
          <code>ULTAMSG_INSTANCE_ID</code>, <code>ULTAMSG_TOKEN</code>, na <code>ULTAMSG_API_URL</code>.
        </div>
      <?php endif; ?>

      <form id="wa-form" autocomplete="off">
        <div class="wa-field">
          <label for="wa-to">Phone (international)</label>
          <input id="wa-to" name="to" type="tel" placeholder="2557XXXXXXXX" required <?= $configured ? '' : 'disabled' ?> />
        </div>
        <div class="wa-field">
          <label for="wa-body">Message</label>
          <textarea id="wa-body" name="body" placeholder="Habari, hii ni test kutoka Getway…" required <?= $configured ? '' : 'disabled' ?>></textarea>
        </div>
        <div class="wa-actions">
          <button type="submit" class="wa-btn wa-btn-primary" id="wa-send" <?= $configured ? '' : 'disabled' ?>>
            <i class="fa-solid fa-paper-plane"></i> Send message
          </button>
          <button type="button" class="wa-btn wa-btn-secondary" id="wa-status" <?= $configured ? '' : 'disabled' ?>>
            <i class="fa-solid fa-signal"></i> Check status
          </button>
        </div>
      </form>

      <pre class="wa-result" id="wa-result" aria-live="polite"></pre>
    </article>
  </div>

  <script>
    (function () {
      var form = document.getElementById("wa-form");
      var result = document.getElementById("wa-result");
      var sendBtn = document.getElementById("wa-send");
      var statusBtn = document.getElementById("wa-status");

      function show(ok, text) {
        result.className = "wa-result " + (ok ? "is-ok" : "is-err");
        result.textContent = text;
      }

      form.addEventListener("submit", async function (e) {
        e.preventDefault();
        sendBtn.disabled = true;
        show(true, "Sending…");
        try {
          var res = await fetch("whatsapp-api.php?action=send", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
              to: document.getElementById("wa-to").value.trim(),
              body: document.getElementById("wa-body").value.trim()
            })
          });
          var data = await res.json();
          show(!!data.ok, JSON.stringify(data, null, 2));
        } catch (err) {
          show(false, String(err && err.message ? err.message : err));
        } finally {
          sendBtn.disabled = false;
        }
      });

      statusBtn.addEventListener("click", async function () {
        statusBtn.disabled = true;
        show(true, "Checking instance…");
        try {
          var res = await fetch("whatsapp-api.php?action=status");
          var data = await res.json();
          show(!!data.ok, JSON.stringify(data, null, 2));
        } catch (err) {
          show(false, String(err && err.message ? err.message : err));
        } finally {
          statusBtn.disabled = false;
        }
      });
    })();
  </script>
</body>
</html>
