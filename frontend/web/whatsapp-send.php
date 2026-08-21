<?php

declare(strict_types=1);

require __DIR__ . '/admin-guard.php';
require_once __DIR__ . '/env-load.php';

$authUser = $_SESSION['gw_auth_user'] ?? [];
$authName = trim((string) ($authUser['fullName'] ?? 'Admin'));
$config = gwUltamsgConfig();
$configured = $config['instanceId'] !== '' && $config['token'] !== '' && $config['apiUrl'] !== '';
$senderName = $config['senderName'];
$webhookUrl = $config['webhookUrl'];
$cssVersion = (string) (@filemtime(__DIR__ . '/admin-dashboard.css') ?: time());
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Getway | WhatsApp</title>
  <link rel="icon" type="image/png" href="images/favicon.png" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
  <link rel="stylesheet" href="admin-dashboard.css?v=<?= urlencode($cssVersion) ?>" />
  <style>
    .wa-page { max-width: 920px; margin: 0 auto; padding: 24px 16px 56px; }
    .wa-grid { display: grid; gap: 16px; }
    @media (min-width: 860px) {
      .wa-grid-main { grid-template-columns: 1fr 1fr; align-items: start; }
    }
    .wa-card {
      background: #fff;
      border: 1px solid #e5e7eb;
      border-radius: 14px;
      padding: 20px;
      box-shadow: 0 4px 18px rgba(0, 45, 88, 0.08);
    }
    .wa-card h1, .wa-card h2 {
      margin: 0 0 6px;
      font-size: 1.15rem;
      color: #002d58;
      font-weight: 800;
    }
    .wa-card h2 { font-size: 1.02rem; }
    .wa-sub { margin: 0 0 14px; color: #64748b; font-size: 0.84rem; font-weight: 600; line-height: 1.4; }
    .wa-meta {
      display: grid; gap: 6px; margin-bottom: 14px; padding: 12px;
      background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 10px;
      font-size: 0.78rem; font-weight: 600; color: #166534;
    }
    .wa-meta code, .wa-hook code { font-size: 0.74rem; word-break: break-all; }
    .wa-warn { background: #fff7ed; border-color: #fed7aa; color: #9a3412; }
    .wa-hook {
      display: grid; gap: 8px; margin-bottom: 14px; padding: 12px;
      background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 10px;
      font-size: 0.78rem; font-weight: 600; color: #1e3a8a;
    }
    .wa-hook-row { display: flex; gap: 8px; align-items: flex-start; flex-wrap: wrap; }
    .wa-field { margin-bottom: 12px; }
    .wa-field label {
      display: block; font-size: 0.76rem; font-weight: 700; color: #64748b; margin-bottom: 6px;
    }
    .wa-field input, .wa-field textarea, .wa-field select {
      width: 100%; box-sizing: border-box; padding: 11px 13px; border: 1px solid #e2e8f0;
      border-radius: 10px; font: inherit; font-size: 0.9rem; font-weight: 600; color: #0f172a; background: #f8fafc;
    }
    .wa-field textarea { min-height: 110px; resize: vertical; }
    .wa-field input:focus, .wa-field textarea:focus, .wa-field select:focus {
      outline: none; border-color: #25d366; box-shadow: 0 0 0 3px rgba(37, 211, 102, 0.18); background: #fff;
    }
    .wa-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 6px; }
    .wa-btn {
      border: none; border-radius: 10px; padding: 11px 16px; font: inherit; font-weight: 800;
      font-size: 0.86rem; cursor: pointer; display: inline-flex; align-items: center; gap: 8px;
    }
    .wa-btn-primary { background: #25d366; color: #fff; box-shadow: 0 6px 16px rgba(37, 211, 102, 0.35); }
    .wa-btn-primary:disabled, .wa-btn:disabled { opacity: 0.55; cursor: not-allowed; }
    .wa-btn-secondary { background: #e2e8f0; color: #002d58; }
    .wa-btn-ghost { background: #fff; border: 1px solid #cbd5e1; color: #002d58; }
    .wa-result {
      margin-top: 14px; padding: 12px 14px; border-radius: 10px; font-size: 0.78rem; font-weight: 600;
      white-space: pre-wrap; word-break: break-word; display: none; max-height: 220px; overflow: auto;
    }
    .wa-result.is-ok { display: block; background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
    .wa-result.is-err { display: block; background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
    .wa-top {
      display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 16px;
    }
    .wa-top a { color: #005691; font-weight: 700; text-decoration: none; font-size: 0.86rem; }
    .wa-tabs { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 12px; }
    .wa-tab {
      border: 1px solid #dbe3ee; background: #f8fafc; color: #475569; border-radius: 999px;
      padding: 6px 12px; font: inherit; font-size: 0.72rem; font-weight: 800; cursor: pointer;
    }
    .wa-tab.is-active { background: #002d58; border-color: #002d58; color: #fff; }
    .wa-list { list-style: none; margin: 0; padding: 0; display: grid; gap: 8px; max-height: 520px; overflow: auto; }
    .wa-item {
      border: 1px solid #e8ecf2; border-radius: 12px; padding: 12px; background: #fbfdff;
    }
    .wa-item-top { display: flex; justify-content: space-between; gap: 8px; align-items: center; margin-bottom: 6px; }
    .wa-item-to { font-size: 0.84rem; font-weight: 800; color: #0f172a; }
    .wa-badge {
      font-size: 0.65rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.04em;
      padding: 3px 8px; border-radius: 999px; background: #e2e8f0; color: #334155;
    }
    .wa-badge--sent { background: #dcfce7; color: #166534; }
    .wa-badge--queue { background: #ffedd5; color: #9a3412; }
    .wa-badge--unsent, .wa-badge--invalid, .wa-badge--expired { background: #fee2e2; color: #991b1b; }
    .wa-item-body { margin: 0; font-size: 0.8rem; color: #334155; font-weight: 600; white-space: pre-wrap; word-break: break-word; }
    .wa-item-meta { margin: 8px 0 0; font-size: 0.7rem; color: #94a3b8; font-weight: 600; }
    .wa-empty { padding: 18px; text-align: center; color: #94a3b8; font-weight: 700; font-size: 0.84rem; }
    .wa-sender-pill {
      display: inline-flex; align-items: center; gap: 8px; padding: 8px 12px; border-radius: 999px;
      background: #ecfdf5; color: #166534; font-size: 0.8rem; font-weight: 800; margin-bottom: 12px;
    }
  </style>
</head>
<body class="ad-body" style="background:#eceff3;min-height:100vh;">
  <div class="wa-page">
    <div class="wa-top">
      <a href="admin-dashboard.php"><i class="fa-solid fa-arrow-left"></i> Admin dashboard</a>
      <span style="font-size:0.8rem;font-weight:700;color:#64748b;"><?= htmlspecialchars($authName, ENT_QUOTES) ?></span>
    </div>

    <div class="wa-grid wa-grid-main">
      <article class="wa-card">
        <h1><i class="fa-brands fa-whatsapp" style="color:#25d366"></i> Send WhatsApp</h1>
        <p class="wa-sub">Ujumbe hutumwa kwa jina la biashara: <strong><?= htmlspecialchars($senderName, ENT_QUOTES) ?></strong></p>

        <div class="wa-sender-pill">
          <i class="fa-solid fa-building"></i>
          Sender: <?= htmlspecialchars($senderName, ENT_QUOTES) ?>
        </div>

        <?php if ($configured): ?>
          <div class="wa-meta">
            <div>Instance: <code><?= htmlspecialchars($config['instanceId'], ENT_QUOTES) ?></code></div>
            <div>API: <code><?= htmlspecialchars($config['apiUrl'], ENT_QUOTES) ?></code></div>
          </div>
        <?php else: ?>
          <div class="wa-meta wa-warn">Weka ULTAMSG_* kwenye <code>.env</code> kwanza.</div>
        <?php endif; ?>

        <div class="wa-hook">
          <strong>Webhook URL (weka kwenye Ultramsg → Settings)</strong>
          <div class="wa-hook-row">
            <code id="wa-webhook-url"><?= htmlspecialchars($webhookUrl, ENT_QUOTES) ?></code>
            <button type="button" class="wa-btn wa-btn-ghost" id="wa-copy-hook">Copy</button>
          </div>
          <span>Washa events: Received, Create, Ack, Download media.</span>
        </div>

        <form id="wa-form" autocomplete="off">
          <div class="wa-field">
            <label for="wa-to">Phone (international)</label>
            <input id="wa-to" type="tel" placeholder="2557XXXXXXXX" required <?= $configured ? '' : 'disabled' ?> />
          </div>
          <div class="wa-field">
            <label for="wa-priority">Priority</label>
            <select id="wa-priority" <?= $configured ? '' : 'disabled' ?>>
              <option value="0">High (0)</option>
              <option value="5">Normal (5)</option>
              <option value="10" selected>Low (10)</option>
            </select>
          </div>
          <div class="wa-field">
            <label for="wa-body">Message</label>
            <textarea id="wa-body" placeholder="Habari, hii ni test…" required <?= $configured ? '' : 'disabled' ?>></textarea>
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
        <p class="wa-sub" style="margin-top:12px;margin-bottom:0">
          Jina la juu kwenye WhatsApp (profile) linatokana na namba iliyoskaniwa.
          Badilisha WhatsApp profile name kuwa <strong><?= htmlspecialchars($senderName, ENT_QUOTES) ?></strong>
          ikiwa bado si hivyo. Mwili wa ujumbe pia unaanza na jina hilo.
        </p>
      </article>

      <article class="wa-card">
        <h2><i class="fa-solid fa-clock-rotate-left"></i> Recent messages</h2>
        <p class="wa-sub">Inasoma moja kwa moja kutoka Ultramsg: All / Queue / Sent / Unsent / Invalid / Expired.</p>

        <div class="wa-tabs" role="tablist" aria-label="Message status">
          <button type="button" class="wa-tab is-active" data-status="all">All</button>
          <button type="button" class="wa-tab" data-status="sent">Sent</button>
          <button type="button" class="wa-tab" data-status="queue">Queue</button>
          <button type="button" class="wa-tab" data-status="unsent">Unsent</button>
          <button type="button" class="wa-tab" data-status="invalid">Invalid</button>
          <button type="button" class="wa-tab" data-status="expired">Expired</button>
        </div>

        <div class="wa-actions" style="margin-bottom:12px">
          <button type="button" class="wa-btn wa-btn-secondary" id="wa-refresh" <?= $configured ? '' : 'disabled' ?>>
            <i class="fa-solid fa-rotate"></i> Refresh
          </button>
          <button type="button" class="wa-btn wa-btn-ghost" id="wa-hooks" <?= $configured ? '' : 'disabled' ?>>
            <i class="fa-solid fa-bolt"></i> Webhook events
          </button>
        </div>

        <ul class="wa-list" id="wa-list">
          <li class="wa-empty">Loading…</li>
        </ul>
      </article>
    </div>
  </div>

  <script>
    (function () {
      var form = document.getElementById("wa-form");
      var result = document.getElementById("wa-result");
      var sendBtn = document.getElementById("wa-send");
      var statusBtn = document.getElementById("wa-status");
      var refreshBtn = document.getElementById("wa-refresh");
      var hooksBtn = document.getElementById("wa-hooks");
      var listEl = document.getElementById("wa-list");
      var currentStatus = "all";
      var configured = <?= $configured ? 'true' : 'false' ?>;

      function show(ok, text) {
        result.className = "wa-result " + (ok ? "is-ok" : "is-err");
        result.textContent = text;
      }

      function esc(s) {
        return String(s == null ? "" : s)
          .replace(/&/g, "&amp;")
          .replace(/</g, "&lt;")
          .replace(/>/g, "&gt;")
          .replace(/"/g, "&quot;");
      }

      function pick(msg, keys, fallback) {
        for (var i = 0; i < keys.length; i++) {
          if (msg[keys[i]] != null && String(msg[keys[i]]) !== "") return msg[keys[i]];
        }
        return fallback;
      }

      function renderMessages(messages) {
        if (!messages || !messages.length) {
          listEl.innerHTML = '<li class="wa-empty">Hakuna messages kwa status hii.</li>';
          return;
        }
        listEl.innerHTML = messages.map(function (m) {
          var to = pick(m, ["to", "chatId", "from", "id"], "—");
          var body = pick(m, ["body", "message", "text", "caption"], "");
          var st = String(pick(m, ["status", "ack", "state"], currentStatus || "all")).toLowerCase();
          var when = pick(m, ["timestamp", "time", "created", "date", "sent_at"], "");
          if (typeof when === "number" && when > 1000000000) {
            when = new Date(when * (when < 1e12 ? 1000 : 1)).toLocaleString();
          }
          var id = pick(m, ["id", "messageId", "msgId"], "");
          return (
            '<li class="wa-item">' +
              '<div class="wa-item-top">' +
                '<span class="wa-item-to">' + esc(to) + '</span>' +
                '<span class="wa-badge wa-badge--' + esc(st) + '">' + esc(st) + '</span>' +
              '</div>' +
              '<p class="wa-item-body">' + esc(body) + '</p>' +
              '<p class="wa-item-meta">ID: ' + esc(id) + (when ? ' · ' + esc(when) : '') + '</p>' +
            '</li>'
          );
        }).join("");
      }

      async function loadMessages(status) {
        if (!configured) {
          listEl.innerHTML = '<li class="wa-empty">Configure Ultamsg first.</li>';
          return;
        }
        currentStatus = status || currentStatus;
        listEl.innerHTML = '<li class="wa-empty">Loading…</li>';
        try {
          var res = await fetch("whatsapp-api.php?action=messages&status=" + encodeURIComponent(currentStatus) + "&limit=50&sort=desc");
          var data = await res.json();
          if (!data.ok) {
            listEl.innerHTML = '<li class="wa-empty">' + esc(data.message || "Failed to load") + '</li>';
            return;
          }
          renderMessages(data.messages || []);
        } catch (err) {
          listEl.innerHTML = '<li class="wa-empty">' + esc(err && err.message ? err.message : err) + '</li>';
        }
      }

      document.querySelectorAll(".wa-tab").forEach(function (btn) {
        btn.addEventListener("click", function () {
          document.querySelectorAll(".wa-tab").forEach(function (b) { b.classList.remove("is-active"); });
          btn.classList.add("is-active");
          loadMessages(btn.getAttribute("data-status"));
        });
      });

      refreshBtn.addEventListener("click", function () { loadMessages(currentStatus); });

      hooksBtn.addEventListener("click", async function () {
        hooksBtn.disabled = true;
        show(true, "Loading webhook events…");
        try {
          var res = await fetch("whatsapp-api.php?action=webhook-events");
          var data = await res.json();
          show(!!data.ok, JSON.stringify(data, null, 2));
        } catch (err) {
          show(false, String(err && err.message ? err.message : err));
        } finally {
          hooksBtn.disabled = false;
        }
      });

      document.getElementById("wa-copy-hook").addEventListener("click", async function () {
        var url = document.getElementById("wa-webhook-url").textContent.trim();
        try {
          await navigator.clipboard.writeText(url);
          show(true, "Webhook URL copied:\n" + url);
        } catch (e) {
          show(true, url);
        }
      });

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
              body: document.getElementById("wa-body").value.trim(),
              priority: document.getElementById("wa-priority").value
            })
          });
          var data = await res.json();
          show(!!data.ok, JSON.stringify(data, null, 2));
          if (data.ok) loadMessages(currentStatus);
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

      loadMessages("all");
    })();
  </script>
</body>
</html>
