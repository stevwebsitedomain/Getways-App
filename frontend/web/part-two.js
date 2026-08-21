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

function prefersReducedMotion() {
  return window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
}

function animateCount(el, target, options = {}) {
  if (!el) return;
  const end = Number(target || 0);
  const prefix = options.prefix || "";
  const duration = options.duration || 900;
  const masked = "••••••";
  const applyVisibleText = (valueText) => {
    el.setAttribute("data-amount-raw", valueText);
    if (el.getAttribute("data-amount-visible") === "0") {
      el.textContent = masked;
      el.classList.add("is-amount-hidden");
    } else {
      el.textContent = valueText;
      el.classList.remove("is-amount-hidden");
    }
  };
  if (prefersReducedMotion() || !Number.isFinite(end)) {
    applyVisibleText(`${prefix}${formatNumber(end)}`);
    return;
  }
  const start = 0;
  const t0 = performance.now();
  function frame(now) {
    const p = Math.min(1, (now - t0) / duration);
    const eased = 1 - Math.pow(1 - p, 3);
    const current = Math.round(start + (end - start) * eased);
    applyVisibleText(`${prefix}${formatNumber(current)}`);
    if (p < 1) {
      requestAnimationFrame(frame);
    } else {
      applyVisibleText(`${prefix}${formatNumber(end)}`);
    }
  }
  requestAnimationFrame(frame);
}

function initMerchantAmountToggle() {
  const amountEl = document.getElementById("success-amount");
  const toggleBtn = document.getElementById("merchant-amount-toggle");
  if (!amountEl || !toggleBtn) return;

  const storageKey = "gw_merchant_amount_visible";
  const labelEl = toggleBtn.querySelector("span");
  const iconEl = toggleBtn.querySelector("i");

  const setVisible = (visible) => {
    const show = Boolean(visible);
    amountEl.setAttribute("data-amount-visible", show ? "1" : "0");
    const raw = amountEl.getAttribute("data-amount-raw") || amountEl.textContent || "TZS 0";
    if (!amountEl.getAttribute("data-amount-raw")) {
      amountEl.setAttribute("data-amount-raw", raw);
    }
    if (show) {
      amountEl.textContent = amountEl.getAttribute("data-amount-raw") || raw;
      amountEl.classList.remove("is-amount-hidden");
      toggleBtn.setAttribute("aria-pressed", "false");
      toggleBtn.setAttribute("title", "Hide amount");
      if (labelEl) labelEl.textContent = "Hide amount";
      if (iconEl) iconEl.className = "fa-solid fa-eye-slash";
    } else {
      amountEl.textContent = "••••••";
      amountEl.classList.add("is-amount-hidden");
      toggleBtn.setAttribute("aria-pressed", "true");
      toggleBtn.setAttribute("title", "View amount");
      if (labelEl) labelEl.textContent = "View amount";
      if (iconEl) iconEl.className = "fa-solid fa-eye";
    }
    try {
      localStorage.setItem(storageKey, show ? "1" : "0");
    } catch (_) {
      /* ignore */
    }
  };

  let initial = true;
  try {
    const saved = localStorage.getItem(storageKey);
    if (saved === "0") initial = false;
    if (saved === "1") initial = true;
  } catch (_) {
    /* ignore */
  }
  setVisible(initial);

  toggleBtn.addEventListener("click", () => {
    const currentlyVisible = amountEl.getAttribute("data-amount-visible") !== "0";
    setVisible(!currentlyVisible);
  });
}

initMerchantAmountToggle();

function animateCountElements(root) {
  const scope = root || document;
  scope.querySelectorAll("[data-count-to]").forEach((el) => {
    const target = Number(el.getAttribute("data-count-to") || 0);
    const prefix = el.getAttribute("data-count-prefix") || "";
    animateCount(el, target, { prefix, duration: 850 + Math.random() * 250 });
  });
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
      let prefix = "TZS ";
      if (isOk) {
        cls = "w-tx--in";
        icon = "fa-check";
        title = "Received";
        prefix = "+TZS ";
      } else if (isFail) {
        cls = "w-tx--out";
        icon = "fa-xmark";
        title = "Failed";
        prefix = "−TZS ";
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
            hour12: false,
          }).replace(",", ",")
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
            <span class="w-tx-amt w-tx-amt--money" data-count-to="${amt}" data-count-prefix="${prefix}">${prefix}0</span>
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
        <a href="payment-details.php?type=success" class="w-recent-more-link">
          <span>View more</span>
          <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
        </a>
      </li>`;
  }
  ul.innerHTML = html;
  animateCountElements(ul);
}

function setSummaryPlaceholders() {
  if (successAmountEl) {
    successAmountEl.setAttribute("data-amount-raw", "TZS 0");
    if (successAmountEl.getAttribute("data-amount-visible") === "0") {
      successAmountEl.textContent = "••••••";
      successAmountEl.classList.add("is-amount-hidden");
    } else {
      successAmountEl.textContent = "TZS 0";
      successAmountEl.classList.remove("is-amount-hidden");
    }
  }
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

async function loadPayments(retryCount = 0) {
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
      animateCount(successAmountEl, successAmount, { prefix: "TZS ", duration: 1100 });
    }
    if (failedAmountEl) {
      animateCount(failedAmountEl, failedAmount, { prefix: "TZS ", duration: 900 });
    }
    if (pendingTransactionsEl) {
      animateCount(pendingTransactionsEl, pendingTransactions, { prefix: "", duration: 800 });
    }
    const mockStatusEl = document.getElementById("mock-status-sales");
    if (mockStatusEl) {
      animateCount(mockStatusEl, successAmount, { prefix: "TZS ", duration: 1100 });
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
    if (retryCount < 2) {
      await new Promise((resolve) => window.setTimeout(resolve, 1500 * (retryCount + 1)));
      return loadPayments(retryCount + 1);
    }
    setSummaryPlaceholders();
    setApiStatus(
      "",
      true
    );
    if (window.NectaServerAlerts && typeof window.NectaServerAlerts.setOffline === "function") {
      window.NectaServerAlerts.setOffline("Could not load totals. Check your connection and try again.");
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
