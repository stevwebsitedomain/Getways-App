const RENDER_API = "https://getways-app.onrender.com";
const API_BASE = window.BASE_API_URL || window.TIS_API_BASE || RENDER_API;
const CLICKPESA_API_BASE = window.CLICKPESA_API_BASE || `${window.location.origin}/api/clickpesa`;
const API_HEADERS = {
  "Content-Type": "application/json",
};

const successAmountEl = document.getElementById("success-amount");
const failedAmountEl = document.getElementById("failed-amount");
const pendingTransactionsEl = document.getElementById("pending-transactions");
const apiStatusEl = document.getElementById("api-status");

function formatNumber(value) {
  return new Intl.NumberFormat("en-US", {
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
  }).format(Number(value || 0));
}

function escapeHtml(value) {
  return String(value ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
}

function isSuccessStatus(value) {
  const s = String(value || "").trim().toUpperCase();
  return s === "SUCCESS" || s === "PAID" || s === "SUCCESSFUL" || s === "COMPLETED";
}

function isFailedStatus(value) {
  const s = String(value || "").trim().toUpperCase();
  return s === "FAILED" || s === "FAILURE";
}

function mergeRecentFeed(payments, pendingRows) {
  const out = [];
  for (const p of payments || []) {
    out.push({ ...p, _source: "payment" });
  }
  for (const o of pendingRows || []) {
    out.push({
      orderReference: o.orderReference,
      amount: o.amount,
      status: "PENDING",
      createdAt: o.createdAt,
      phone: o.phone,
      _source: "order",
    });
  }
  out.sort((a, b) => new Date(b.createdAt || 0) - new Date(a.createdAt || 0));
  return out;
}

function buildDayBuckets(payments, pendingRows, numDays) {
  const end = new Date();
  end.setHours(23, 59, 59, 999);
  const start = new Date(end);
  start.setDate(start.getDate() - (numDays - 1));
  start.setHours(0, 0, 0, 0);
  const dayMs = 86400000;
  const days = [];
  for (let i = 0; i < numDays; i++) {
    const d = new Date(start);
    d.setDate(start.getDate() + i);
    days.push({
      label: d.toLocaleDateString("en-GB", { day: "numeric", month: "short" }),
      count: 0,
    });
  }

  function bump(ts) {
    const t = new Date(ts);
    if (Number.isNaN(t.getTime())) return;
    t.setHours(0, 0, 0, 0);
    const diff = Math.round((t.getTime() - start.getTime()) / dayMs);
    if (diff >= 0 && diff < numDays) {
      days[diff].count += 1;
    }
  }

  (payments || []).forEach((p) => bump(p.createdAt));
  (pendingRows || []).forEach((o) => bump(o.createdAt));
  return days;
}

function polarToCartesian(cx, cy, r, angleDeg) {
  const rad = ((angleDeg - 90) * Math.PI) / 180;
  return {
    x: cx + r * Math.cos(rad),
    y: cy + r * Math.sin(rad),
  };
}

function describeArc(cx, cy, r, startAngle, endAngle) {
  const start = polarToCartesian(cx, cy, r, endAngle);
  const end = polarToCartesian(cx, cy, r, startAngle);
  const largeArc = endAngle - startAngle <= 180 ? 0 : 1;
  return `M ${cx} ${cy} L ${start.x} ${start.y} A ${r} ${r} 0 ${largeArc} 0 ${end.x} ${end.y} Z`;
}

function renderPieChart(payments) {
  const el = document.getElementById("wallet-pie-chart");
  if (!el) return;

  const list = Array.isArray(payments) ? payments : [];
  let success = 0;
  let failed = 0;
  let pending = 0;
  list.forEach((p) => {
    const st = String(p.status || "").toUpperCase();
    if (isSuccessStatus(st)) success += 1;
    else if (isFailedStatus(st)) failed += 1;
    else pending += 1;
  });

  const slices = [
    { key: "SUCCESS", label: "Success", count: success, color: "#16a34a" },
    { key: "FAILED", label: "Failed", count: failed, color: "#dc2626" },
    { key: "PENDING", label: "Pending", count: pending, color: "#f59e0b" },
  ].filter((s) => s.count > 0);

  const total = slices.reduce((sum, s) => sum + s.count, 0);
  if (!total) {
    el.innerHTML = '<p class="w-pie-empty">No payments yet for analysis.</p>';
    return;
  }

  const W = 360;
  const H = 240;
  const cx = W / 2;
  const cy = H / 2 + 4;
  const r = 68;
  const labelR = 98;

  let angle = 0;
  const paths = [];
  const leaders = [];

  slices.forEach((slice) => {
    const sweep = (slice.count / total) * 360;
    const start = angle;
    const end = angle + Math.min(sweep, 359.999);
    const mid = start + sweep / 2;
    if (slices.length === 1) {
      paths.push(
        `<circle cx="${cx}" cy="${cy}" r="${r}" fill="${slice.color}" stroke="#ffffff" stroke-width="2"/>`
      );
    } else {
      paths.push(
        `<path d="${describeArc(cx, cy, r, start, end)}" fill="${slice.color}" stroke="#ffffff" stroke-width="2"/>`
      );
    }

    const inner = polarToCartesian(cx, cy, r * 0.82, mid);
    const outer = polarToCartesian(cx, cy, labelR, mid);
    const side = mid > 180 ? -1 : 1;
    const labelX = outer.x + side * 18;
    const pct = Math.round((slice.count / total) * 100);
    const textAnchor = side < 0 ? "end" : "start";

    leaders.push(`
      <polyline
        points="${inner.x.toFixed(1)},${inner.y.toFixed(1)} ${outer.x.toFixed(1)},${outer.y.toFixed(1)} ${(labelX - side * 4).toFixed(1)},${outer.y.toFixed(1)}"
        fill="none" stroke="${slice.color}" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
      <circle cx="${inner.x.toFixed(1)}" cy="${inner.y.toFixed(1)}" r="2.4" fill="${slice.color}"/>
      <text x="${labelX.toFixed(1)}" y="${(outer.y - 4).toFixed(1)}" text-anchor="${textAnchor}"
        font-size="11" font-weight="800" fill="#0f172a" font-family="DM Sans, system-ui, sans-serif">${escapeHtml(slice.label)}</text>
      <text x="${labelX.toFixed(1)}" y="${(outer.y + 10).toFixed(1)}" text-anchor="${textAnchor}"
        font-size="10" font-weight="600" fill="#64748b" font-family="DM Sans, system-ui, sans-serif">${slice.count} · ${pct}%</text>
    `);

    angle = end;
  });

  el.innerHTML = `
    <svg class="w-pie-svg" viewBox="0 0 ${W} ${H}" role="img" aria-label="Payment analysis pie chart">
      ${paths.join("")}
      <circle cx="${cx}" cy="${cy}" r="34" fill="#ffffff"/>
      <text x="${cx}" y="${cy - 4}" text-anchor="middle" font-size="18" font-weight="800" fill="#0f172a" font-family="DM Sans, system-ui, sans-serif">${total}</text>
      <text x="${cx}" y="${cy + 14}" text-anchor="middle" font-size="10" font-weight="700" fill="#94a3b8" font-family="DM Sans, system-ui, sans-serif">TOTAL</text>
      ${leaders.join("")}
    </svg>
  `;
}

function renderTrendChart(payments, pendingRows) {
  const el = document.getElementById("wallet-trend-chart");
  if (!el) return;

  const days = buildDayBuckets(payments, pendingRows, 14);
  const totalHits = days.reduce((s, d) => s + d.count, 0);
  if (!totalHits) {
    el.innerHTML = '<p class="w-trend-empty">No activity in the last 14 days yet.</p>';
    return;
  }

  const max = Math.max(1, ...days.map((d) => d.count));
  const W = 400;
  const H = 90;
  const padL = 4;
  const padR = 4;
  const padT = 10;
  const padB = 22;
  const innerW = W - padL - padR;
  const innerH = H - padT - padB;
  const n = days.length;
  const pts = days.map((d, i) => {
    const x = padL + (n <= 1 ? innerW / 2 : (i / (n - 1)) * innerW);
    const y = padT + innerH - (d.count / max) * innerH;
    return { x, y, c: d.count, label: d.label };
  });

  const lineD = pts.map((p, i) => `${i === 0 ? "M" : "L"} ${p.x.toFixed(1)} ${p.y.toFixed(1)}`).join(" ");
  const areaD = `${lineD} L ${pts[pts.length - 1].x.toFixed(1)} ${(H - padB).toFixed(1)} L ${pts[0].x.toFixed(1)} ${(H - padB).toFixed(1)} Z`;

  el.innerHTML = `
    <svg class="w-trend-svg" viewBox="0 0 ${W} ${H}" preserveAspectRatio="none" aria-hidden="true">
      <defs>
        <linearGradient id="wTrendFill" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" stop-color="#2563eb" stop-opacity="0.35"/>
          <stop offset="100%" stop-color="#2563eb" stop-opacity="0"/>
        </linearGradient>
        <filter id="wTrendShadow" x="-20%" y="-20%" width="140%" height="140%">
          <feDropShadow dx="0" dy="2" stdDeviation="3" flood-color="#1d4ed8" flood-opacity="0.25"/>
        </filter>
      </defs>
      <path d="${areaD}" fill="url(#wTrendFill)" stroke="none"/>
      <path d="${lineD}" fill="none" stroke="#1d4ed8" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" filter="url(#wTrendShadow)"/>
      ${pts
        .filter((_, i) => i % 3 === 0 || i === n - 1)
        .map(
          (p) =>
            `<text x="${p.x.toFixed(1)}" y="${H - 4}" text-anchor="middle" font-size="9" fill="#64748b" font-family="DM Sans, system-ui, sans-serif">${escapeHtml(p.label)}</text>`
        )
        .join("")}
    </svg>`;
}

function renderRecentTransactions(merged) {
  const ul = document.getElementById("wallet-recent-list");
  if (!ul) return;
  const list = Array.isArray(merged) ? [...merged] : [];
  list.sort((a, b) => new Date(b.createdAt || 0) - new Date(a.createdAt || 0));
  const rows = list.slice(0, 5);
  if (!rows.length) {
    ul.innerHTML =
      '<li class="w-recent-empty">No activity yet. Create a checkout or wait for webhooks to see paid / failed / pending here.</li>';
    return;
  }
  let html = rows
    .map((p) => {
      const st = String(p.status || "").toUpperCase();
      const isOk = isSuccessStatus(st);
      const isFail = isFailedStatus(st);
      const amt = Number(p.amount || 0);
      let cls = "w-tx--pending";
      let icon = "fa-hourglass-half";
      let title = "Pending";
      let amtHtml = `TZS ${formatNumber(amt)}`;
      if (isOk) {
        cls = "w-tx--in";
        icon = "fa-check";
        title = "Received";
        amtHtml = `+TZS ${formatNumber(amt)}`;
      } else if (isFail) {
        cls = "w-tx--out";
        icon = "fa-xmark";
        title = "Failed";
        amtHtml = `−TZS ${formatNumber(amt)}`;
      }
      const ref = String(p.orderReference || "").trim();
      const shortRef = ref.length > 22 ? `${ref.slice(0, 22)}…` : ref;
      const phone = String(p.phone || "").trim();
      const phoneBit = phone ? ` · ${escapeHtml(phone)}` : "";
      const time = p.createdAt
        ? new Date(p.createdAt).toLocaleString("en-GB", {
            day: "numeric",
            month: "short",
            hour: "2-digit",
            minute: "2-digit",
          })
        : "—";
      const actions =
        window.GetwayReceiptActions && typeof window.GetwayReceiptActions.actionButtonsHtml === "function"
          ? window.GetwayReceiptActions.actionButtonsHtml(p)
          : "";
      return `
      <li class="w-tx ${cls}">
        <div class="w-tx-icon" aria-hidden="true"><i class="fa-solid ${icon}"></i></div>
        <div class="w-tx-body">
          <div class="w-tx-top">
            <span class="w-tx-title">${title}</span>
            <span class="w-tx-amt">${amtHtml}</span>
          </div>
          <div class="w-tx-sub">${escapeHtml(time)}${phoneBit}</div>
          <div class="w-tx-note">"${escapeHtml(shortRef || "—")}"</div>
          ${actions}
        </div>
      </li>`;
    })
    .join("");
  if (list.length > 5) {
    html += `
      <li class="w-recent-more">
        <a href="payment-details.php?type=success" class="w-recent-more-link">View more</a>
      </li>`;
  }
  ul.innerHTML = html;
}

function setSummaryPlaceholders() {
  if (successAmountEl) successAmountEl.textContent = "TZS 0";
  if (failedAmountEl) failedAmountEl.textContent = "TZS 0";
  if (pendingTransactionsEl) pendingTransactionsEl.textContent = "0";
  const mockStatusEl = document.getElementById("mock-status-sales");
  if (mockStatusEl) mockStatusEl.textContent = "TZS 0";
  renderRecentTransactions([]);
  renderTrendChart([], []);
  renderPieChart([]);
}

function setApiStatus(message, isError) {
  if (!apiStatusEl) return;
  if (isError) {
    apiStatusEl.innerHTML = "";
    apiStatusEl.style.display = "none";
    return;
  }
  apiStatusEl.style.display = "";
  const icon = isError ? "fa-plug-circle-xmark" : "fa-circle-check";
  const badgeClass = isError ? "is-offline" : "is-online";
  apiStatusEl.innerHTML = `
    <span class="api-status-pill ${badgeClass}">
      <i class="fa-solid ${icon}" aria-hidden="true"></i>
      <span>${escapeHtml(message || "")}</span>
    </span>
  `;
  apiStatusEl.classList.toggle("error", Boolean(isError));
}

function notifyWalletUpdated() {
  try {
    document.dispatchEvent(new CustomEvent("necta-wallet-updated"));
  } catch (_) {
    /* ignore */
  }
}

async function loadPayments() {
  try {
    let data;
    if (window.GetwayPaymentsMerge && typeof window.GetwayPaymentsMerge.loadMergedPayments === "function") {
      data = await window.GetwayPaymentsMerge.loadMergedPayments(
        API_BASE,
        CLICKPESA_API_BASE,
        API_HEADERS
      );
    } else {
      const payRes = await fetch(`${API_BASE}/payments`, { cache: "no-store", headers: API_HEADERS });
      if (!payRes.ok) {
        const errBody = await payRes.json().catch(() => ({}));
        throw new Error(errBody.message || `HTTP ${payRes.status}`);
      }
      data = await payRes.json();
    }

    let pendingRows = [];
    try {
      if (window.GetwayPaymentsMerge && typeof window.GetwayPaymentsMerge.loadMergedDetails === "function") {
        const pendData = await window.GetwayPaymentsMerge.loadMergedDetails(
          "pending",
          API_BASE,
          CLICKPESA_API_BASE,
          API_HEADERS
        );
        pendingRows = (pendData && pendData.rows) || [];
      } else {
        const pendRes = await fetch(`${API_BASE}/payments/details?type=pending`, {
          cache: "no-store",
          headers: API_HEADERS,
        });
        if (pendRes.ok) {
          const pendData = await pendRes.json();
          pendingRows = pendData.rows || [];
        }
      }
    } catch (_) {
      /* pending list optional */
    }

    const successAmount = Number(data.totalSales || 0);
    const failedAmount = Number(data.failedSales || 0);
    const pendingTransactions = Number(data.pendingTransactions || 0);

    if (successAmountEl) {
      successAmountEl.textContent = `TZS ${formatNumber(successAmount)}`;
    }
    if (failedAmountEl) {
      failedAmountEl.textContent = `TZS ${formatNumber(failedAmount)}`;
    }
    if (pendingTransactionsEl) {
      pendingTransactionsEl.textContent = formatNumber(pendingTransactions);
    }
    const mockStatusEl = document.getElementById("mock-status-sales");
    if (mockStatusEl) {
      mockStatusEl.textContent = `TZS ${formatNumber(successAmount)}`;
    }

    const merged = mergeRecentFeed(data.payments || [], pendingRows);
    renderRecentTransactions(merged);
    renderTrendChart(data.payments || [], pendingRows);
    renderPieChart(data.payments || []);
    setApiStatus("Server is online. Payments are syncing.", false);
    if (window.NectaServerAlerts && typeof window.NectaServerAlerts.setOnline === "function") {
      window.NectaServerAlerts.setOnline("Server is back online.");
    }
    notifyWalletUpdated();
  } catch (error) {
    setSummaryPlaceholders();
    setApiStatus(
      "",
      true
    );
    if (window.NectaServerAlerts && typeof window.NectaServerAlerts.setOffline === "function") {
      window.NectaServerAlerts.setOffline("Could not load totals. Check tis-clickpesa server.");
    }
  }
}

function setupLiveUpdates() {
  setApiStatus("Server is online. Polling for live updates...", false);
  const pollId = window.setInterval(() => {
    loadPayments().catch(() => {});
  }, 10000);
  window.addEventListener("beforeunload", () => window.clearInterval(pollId));
}

async function initDashboard() {
  setSummaryPlaceholders();
  await loadPayments();
  setupLiveUpdates();
}

initDashboard().catch(() => {
  setSummaryPlaceholders();
  setApiStatus("", true);
});

function bindHomeControlNumber() {
  const form = document.getElementById("home-cn-form");
  if (!form) return;
  const msg = document.getElementById("home-cn-msg");
  const result = document.getElementById("home-cn-result");
  const apiBase = `${window.location.origin}${window.location.pathname.replace(/[^/]*$/, "")}`.replace(/\/$/, "");
  // Prefer Yii pretty route under same origin
  const cnUrl = `${CLICKPESA_API_BASE}/control-number`;

  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (msg) {
      msg.textContent = "Creating…";
      msg.className = "w-cn-msg";
    }
    if (result) result.hidden = true;
    const fd = new FormData(form);
    const payload = Object.fromEntries(fd.entries());
    try {
      let res = await fetch(cnUrl, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        credentials: "same-origin",
        body: JSON.stringify(payload),
      });
      if (!res.ok && res.status === 404) {
        res = await fetch(`${CLICKPESA_API_BASE}/control-number`, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          credentials: "same-origin",
          body: JSON.stringify(payload),
        });
      }
      const data = await res.json().catch(() => ({}));
      if (!res.ok || data.success === false) {
        throw new Error(data.message || "Could not create control number.");
      }
      if (msg) {
        msg.textContent = "Control number created.";
        msg.className = "w-cn-msg is-ok";
      }
      if (result) {
        result.hidden = false;
        result.innerHTML = `
          <p><strong>Control number:</strong> <code id="home-cn-value">${escapeHtml(data.controlNumber || "")}</code>
          <button type="button" class="w-cn-copy" data-copy="${escapeHtml(data.controlNumber || "")}">Copy</button></p>
          <p><strong>Reference:</strong> ${escapeHtml(data.reference || data.orderReference || "")}</p>
          <p><strong>Amount:</strong> TZS ${formatNumber(data.amount)}</p>
          <p><strong>Status:</strong> ${escapeHtml(data.status || "PENDING")}</p>`;
        result.querySelector("[data-copy]")?.addEventListener("click", (e) => {
          const btn = e.currentTarget;
          const v = btn.getAttribute("data-copy") || "";
          navigator.clipboard?.writeText(v);
          btn.textContent = "Copied";
        });
      }
      form.reset();
    } catch (error) {
      if (msg) {
        msg.textContent = error.message || "Failed.";
        msg.className = "w-cn-msg is-err";
      }
    }
  });
}

window.addEventListener("offline", () => {
  setApiStatus("", true);
  if (window.NectaServerAlerts && typeof window.NectaServerAlerts.setOffline === "function") {
    window.NectaServerAlerts.setOffline("You are offline. Reconnect to continue.");
  }
});

window.addEventListener("online", () => {
  setApiStatus("Connection restored. Server is online.", false);
  if (window.NectaServerAlerts && typeof window.NectaServerAlerts.setOnline === "function") {
    window.NectaServerAlerts.setOnline("Connection restored. Server is online.");
  }
  loadPayments().catch(() => {});
});
