(function () {
  const API = "admin-api.php";
  const REFRESH_MS = 60000;
  const PAGE_SIZE = 5;
  let latestPayoutRows = [];
  let latestControlRows = [];
  let latestUserRows = [];
  let controlsPage = 1;
  let payoutsPage = 1;
  let usersPage = 1;
  let recentPage = 1;
  let latestRecentRows = [];
  let latestSettings = null;
  let analyticsPeriod = "all";

  function money(n) {
    return "TZS " + new Intl.NumberFormat("en-US", { maximumFractionDigits: 0 }).format(Number(n || 0));
  }

  function esc(v) {
    return String(v ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  function fmtDate(value) {
    if (!value) return "—";
    const d = new Date(value);
    return Number.isNaN(d.getTime()) ? String(value) : d.toLocaleString();
  }

  function clearBanner(id) {
    const el = document.getElementById(id);
    if (el) {
      el.hidden = true;
      el.textContent = "";
    }
  }

  function setBanner(id, message, type = "error", options = {}) {
    clearBanner(id);
    const text = String(message || "").trim();
    if (!text) return;
    notify(text, type, options);
  }

  const recentAlerts = new Map();
  const ALERT_DEDUPE_MS = 45000;

  function notify(message, type = "info", options = {}) {
    const text = String(message || "").trim();
    if (!text) return;
    if (!window.Swal || typeof window.Swal.fire !== "function") {
      if (type === "error") console.error(text);
      return;
    }

    const key = `${type}:${text}`;
    if (!options.force) {
      const last = recentAlerts.get(key) || 0;
      if (Date.now() - last < ALERT_DEDUPE_MS) return;
    }
    recentAlerts.set(key, Date.now());

    const icon = type === "success" ? "success" : type === "warning" ? "warning" : type === "error" ? "error" : "info";
    const useToast = options.toast === true || (type === "success" && options.modal !== true);

    if (useToast) {
      window.Swal.fire({
        toast: true,
        position: "top-end",
        icon,
        title: text,
        showConfirmButton: false,
        timer: type === "error" ? 6000 : 3500,
        timerProgressBar: true,
      });
      return;
    }

    window.Swal.fire({
      icon,
      title: type === "error" ? "Error" : type === "warning" ? "Onyo" : "Taarifa",
      text,
      confirmButtonText: "Sawa",
      confirmButtonColor: "#16a34a",
    });
  }

  function renderPager(pagerId, page, totalItems, onPage) {
    const pager = document.getElementById(pagerId);
    if (!pager) return;
    const totalPages = Math.max(1, Math.ceil(totalItems / PAGE_SIZE));
    const current = Math.min(Math.max(1, page), totalPages);
    if (totalItems <= PAGE_SIZE) {
      pager.hidden = true;
      pager.innerHTML = "";
      return;
    }
    pager.hidden = false;
    pager.innerHTML = `
      <button type="button" class="ad-pager-btn" data-page="prev" ${current <= 1 ? "disabled" : ""}>Previous</button>
      <span class="ad-pager-info">Page ${current} of ${totalPages}</span>
      <button type="button" class="ad-pager-btn" data-page="next" ${current >= totalPages ? "disabled" : ""}>Next</button>`;
    pager.querySelector('[data-page="prev"]')?.addEventListener("click", () => onPage(current - 1));
    pager.querySelector('[data-page="next"]')?.addEventListener("click", () => onPage(current + 1));
  }

  async function promptAdminPassword() {
    if (!window.Swal || typeof window.Swal.fire !== "function") {
      return window.prompt("Enter current admin password to change automatic payout settings:", "") || "";
    }
    const result = await window.Swal.fire({
      title: "Admin password required",
      text: "Enter your password to change automatic payout settings.",
      input: "password",
      inputPlaceholder: "Admin password",
      showCancelButton: true,
      confirmButtonText: "Continue",
      confirmButtonColor: "#16a34a",
      cancelButtonText: "Cancel",
    });
    return result.isConfirmed ? String(result.value || "") : "";
  }

  async function showPayoutDetails(row) {
    if (!row) return;
    const html = `
      <div style="text-align:left;font-size:0.95rem;line-height:1.6">
        <p><strong>Reference:</strong> ${esc(row.payoutReference)}</p>
        <p><strong>Status:</strong> ${esc(row.status)}</p>
        <p><strong>Provider:</strong> ${esc(row.provider || "—")}</p>
        <p><strong>Error:</strong> ${esc(row.lastError || "—")}</p>
      </div>`;
    if (!window.Swal || typeof window.Swal.fire !== "function") {
      window.alert(`Reference: ${row.payoutReference}\nStatus: ${row.status}`);
      return;
    }
    await window.Swal.fire({
      title: "Payout details",
      html,
      confirmButtonText: "Close",
      confirmButtonColor: "#16a34a",
    });
  }

  let waitSwalOpen = false;

  function showWaitSwal(title, html) {
    if (!window.Swal || typeof window.Swal.fire !== "function") return;
    waitSwalOpen = true;
    window.Swal.fire({
      title: title || "Tafadhali subiri",
      html:
        html ||
        '<p style="margin:0.35rem 0 0;font-size:0.95rem;font-weight:600;color:#475569">Tunatengeneza control number kutoka ClickPesa…</p>',
      allowOutsideClick: false,
      allowEscapeKey: false,
      showConfirmButton: false,
      backdrop: true,
      didOpen: () => {
        if (typeof window.Swal.showLoading === "function") {
          window.Swal.showLoading();
        }
      },
    });
  }

  function dismissWaitSwal() {
    if (!window.Swal || typeof window.Swal.close !== "function") return;
    try {
      if (waitSwalOpen) window.Swal.close();
    } catch (_) {
      /* ignore */
    }
    waitSwalOpen = false;
  }

  function showControlNumberError(message, title) {
    const text = String(message || "Hitilafu imetokea.").trim();
    dismissWaitSwal();
    if (!window.Swal || typeof window.Swal.fire !== "function") {
      window.alert(text);
      return;
    }
    window.Swal.fire({
      icon: "error",
      title: title || "Haijafanikiwa",
      text,
      confirmButtonText: "Sawa",
      confirmButtonColor: "#b91c1c",
    });
  }

  function buildControlNumberPaperHtml(data) {
    const cn = esc(data.controlNumber || "—");
    const ref = esc(data.reference || "—");
    const amt = esc(money(data.amount || 0));
    const desc = esc(data.description || "BillPay payment");
    const status = String(data.status || "PENDING").toUpperCase();
    const isPaid = ["SUCCESS", "PAID", "COMPLETED", "SETTLED"].includes(status);
    const statusLabel = isPaid ? "IMELIPWA" : "BADO — INASUBIRI MALIPO";
    const statusColor = isPaid ? "#15803d" : "#b45309";
    const when = esc(new Date().toLocaleString());
    const existing = !!data.existing;

    return `
      <div class="ad-cn-paper">
        <div class="ad-cn-brand">Getway | BillPay</div>
        <div class="ad-cn-sub">${existing ? "CONTROL NUMBER TAYARI IPO" : "CONTROL NUMBER IMETENGENEZWA"}</div>
        <hr class="ad-cn-dash" />
        <div class="ad-cn-number">${cn}</div>
        <p class="ad-cn-hint">Mteja analipa kwa kutumia namba hii kwenye M-Pesa, HaloPesa, n.k.</p>
        <hr class="ad-cn-dash" />
        <div class="ad-cn-row"><span>REFERENCE</span><span>${ref}</span></div>
        <div class="ad-cn-row"><span>AMOUNT</span><span>${amt}</span></div>
        <div class="ad-cn-row"><span>DESCRIPTION</span><span>${desc}</span></div>
        <div class="ad-cn-row"><span>MALIPO</span><span class="ad-cn-status" style="color:${statusColor}">${statusLabel}</span></div>
        <div class="ad-cn-row"><span>DATE</span><span>${when}</span></div>
      </div>`;
  }

  async function showControlNumberResult(data) {
    dismissWaitSwal();
    const cn = data.controlNumber || "";
    const invoiceUrl = data.invoiceUrl || "";
    const existing = !!data.existing;
    const msg = document.getElementById("ad-cn-msg");
    if (msg) {
      msg.className = "ad-msg is-ok";
      msg.textContent = existing
        ? `Control number tayari ipo: ${cn}`
        : `Control number imetengenezwa: ${cn}`;
    }
    if (!window.Swal || typeof window.Swal.fire !== "function") return;
    await window.Swal.fire({
      icon: existing ? "info" : "success",
      title: existing ? "Control Number Tayari Ipo" : "Imefanikiwa!",
      html: `
        ${buildControlNumberPaperHtml(data)}
        <div class="ad-cn-actions">
          <button type="button" class="ad-refresh" id="swal-copy-cn">Nakili Control Number</button>
          ${invoiceUrl ? '<button type="button" class="ad-refresh" id="swal-view-invoice">Angalia Risiti</button>' : ""}
          ${invoiceUrl ? '<button type="button" class="ad-refresh" id="swal-download-invoice">Pakua PDF</button>' : ""}
        </div>
        <p class="ad-cn-footnote">
          ${
            existing
              ? "Malipo bado yanaweza kusubiriwa kwa control number hii."
              : "Control number imetoka ClickPesa. Malipo yataonekana hapa mteja akilipa."
          }
        </p>`,
      confirmButtonText: "Funga",
      confirmButtonColor: "#16a34a",
      width: 400,
      didOpen: () => {
        document.getElementById("swal-copy-cn")?.addEventListener("click", async () => {
          await navigator.clipboard?.writeText(cn);
          notify("Control number imenakiliwa.", "success");
        });
        document.getElementById("swal-view-invoice")?.addEventListener("click", () => openInvoice(invoiceUrl, false));
        document.getElementById("swal-download-invoice")?.addEventListener("click", () => openInvoice(invoiceUrl, true));
      },
    });
  }

  function statusBadge(st) {
    const s = String(st || "").toUpperCase();
    let cls = "ad-badge--pending";
    if (["SUCCESS", "PAID", "COMPLETED"].includes(s)) cls = "ad-badge--ok";
    if (["FAILED", "FAILURE", "REFUNDED", "REVERSED"].includes(s)) cls = "ad-badge--fail";
    return `<span class="ad-badge ${cls}">${esc(s || "—")}</span>`;
  }

  function logDevError(route, response, payload) {
    console.warn("ClickPesa dashboard request failed", {
      httpStatus: response?.status || payload?.httpStatus || 0,
      apiRoute: route,
      message: payload?.message || "Request failed",
      clickpesaResponseCode: payload?.clickpesaCode || payload?.responseCode || null,
    });
  }

  async function requestJson(action, options = {}) {
    const {
      method = "GET",
      body,
      query = {},
      onLoading,
      onFinally,
    } = options;

    const url = new URL(API, window.location.href);
    url.searchParams.set("action", action);
    Object.entries(query).forEach(([key, value]) => {
      if (value !== undefined && value !== null && value !== "") {
        url.searchParams.set(key, value);
      }
    });

    try {
      if (onLoading) onLoading(true);
      const response = await fetch(url.toString(), {
        method,
        credentials: "same-origin",
        cache: "no-store",
        headers: { "Content-Type": "application/json" },
        body: body ? JSON.stringify(body) : undefined,
      });
      const result = await response.json().catch(() => ({}));
      if (!response.ok || result.success === false || result.ok === false) {
        logDevError(result.apiRoute || action, response, result);
        const detail = result.db?.hint ? ` ${result.db.hint}` : "";
        throw new Error((result.message || "Request failed") + detail);
      }
      return result;
    } catch (error) {
      throw error;
    } finally {
      if (onLoading) onLoading(false);
      if (onFinally) onFinally();
    }
  }

  function setAutoPayoutUi(enabled, mode) {
    const el = document.getElementById("stat-auto");
    if (!el) return;
    el.textContent = enabled ? "ON" : "OFF";
    el.classList.toggle("ad-auto-on", enabled);
    el.classList.toggle("ad-auto-off", !enabled);
    const modeEl = document.getElementById("stat-auto-mode");
    if (modeEl) modeEl.textContent = mode || "TEST";
  }

  function drawPie(el, pie) {
    if (!el) return;
    const success = Number(pie.success || 0);
    const pending = Number(pie.pending || 0);
    const failed = Number(pie.failed || 0);
    const total = success + pending + failed;
    if (!total) {
      el.innerHTML = `<p>No ClickPesa transactions were found for this period.</p>`;
      return;
    }

    const W = 360;
    const H = 240;
    const cx = W / 2;
    const cy = H / 2 + 4;
    const r = 68;
    const labelR = 98;
    const colors = { success: "#22c55e", pending: "#f59e0b", failed: "#ef4444" };
    const slices = [
      { key: "success", label: "Success", count: success },
      { key: "pending", label: "Pending", count: pending },
      { key: "failed", label: "Failed", count: failed },
    ].filter((s) => s.count > 0);

    let angle = 0;
    const paths = [];
    const leaders = [];

    slices.forEach((slice) => {
      const sweep = (slice.count / total) * 360;
      const start = angle;
      const end = angle + Math.min(sweep, 359.999);
      const mid = start + sweep / 2;
      if (slices.length === 1) {
        paths.push(`<circle cx="${cx}" cy="${cy}" r="${r}" fill="${colors[slice.key]}" stroke="#1a222c" stroke-width="2"/>`);
      } else {
        const startRad = ((start - 90) * Math.PI) / 180;
        const endRad = ((end - 90) * Math.PI) / 180;
        const x1 = cx + r * Math.cos(startRad);
        const y1 = cy + r * Math.sin(startRad);
        const x2 = cx + r * Math.cos(endRad);
        const y2 = cy + r * Math.sin(endRad);
        const large = end - start > 180 ? 1 : 0;
        paths.push(`<path d="M ${cx} ${cy} L ${x1} ${y1} A ${r} ${r} 0 ${large} 1 ${x2} ${y2} Z" fill="${colors[slice.key]}" stroke="#1a222c" stroke-width="2"/>`);
      }

      const innerRad = ((mid - 90) * Math.PI) / 180;
      const outerRad = ((mid - 90) * Math.PI) / 180;
      const innerX = cx + r * 0.82 * Math.cos(innerRad);
      const innerY = cy + r * 0.82 * Math.sin(innerRad);
      const outerX = cx + labelR * Math.cos(outerRad);
      const outerY = cy + labelR * Math.sin(outerRad);
      const side = mid > 180 ? -1 : 1;
      const labelX = outerX + side * 18;
      const pct = Math.round((slice.count / total) * 100);
      const textAnchor = side < 0 ? "end" : "start";
      leaders.push(`
        <line x1="${innerX.toFixed(1)}" y1="${innerY.toFixed(1)}" x2="${outerX.toFixed(1)}" y2="${outerY.toFixed(1)}" stroke="${colors[slice.key]}" stroke-width="1.5"/>
        <text x="${labelX.toFixed(1)}" y="${(outerY - 4).toFixed(1)}" text-anchor="${textAnchor}" fill="#fff" font-size="11" font-weight="700">${slice.label}</text>
        <text x="${labelX.toFixed(1)}" y="${(outerY + 10).toFixed(1)}" text-anchor="${textAnchor}" fill="#8b9aab" font-size="10">${slice.count} · ${pct}%</text>
      `);
      angle = end;
    });

    el.innerHTML = `
      <svg viewBox="0 0 ${W} ${H}" width="100%" height="240" role="img" aria-label="Payment analysis pie chart">
        ${paths.join("")}
        <circle cx="${cx}" cy="${cy}" r="34" fill="#1a222c"/>
        <text x="${cx}" y="${cy - 4}" text-anchor="middle" fill="#fff" font-size="18" font-weight="700">${total}</text>
        <text x="${cx}" y="${cy + 14}" text-anchor="middle" fill="#8b9aab" font-size="10">TOTAL</text>
        ${leaders.join("")}
      </svg>
      <div style="display:flex;gap:14px;justify-content:center;flex-wrap:wrap;font-size:0.82rem;color:#8b9aab;margin-top:8px">
        <span><i style="color:#22c55e">●</i> Success ${success}</span>
        <span><i style="color:#f59e0b">●</i> Pending ${pending}</span>
        <span><i style="color:#ef4444">●</i> Failed ${failed}</span>
      </div>`;
  }

  function drawTrend(el, days) {
    if (!el) return;
    const list = Array.isArray(days) ? days : [];
    const totalHits = list.reduce((sum, d) => sum + Number(d.count || 0), 0);
    if (!totalHits) {
      el.innerHTML = '<p class="ad-trend-empty">No activity in the last 14 days yet.</p>';
      return;
    }

    const max = Math.max(1, ...list.map((d) => Number(d.count || 0)));
    const W = 400;
    const H = 90;
    const padL = 4;
    const padR = 4;
    const padT = 10;
    const padB = 22;
    const innerW = W - padL - padR;
    const innerH = H - padT - padB;
    const n = list.length;
    const pts = list.map((d, i) => {
      const x = padL + (n <= 1 ? innerW / 2 : (i / (n - 1)) * innerW);
      const y = padT + innerH - (Number(d.count || 0) / max) * innerH;
      return { x, y, label: d.label || "" };
    });
    const lineD = pts.map((p, i) => `${i === 0 ? "M" : "L"} ${p.x.toFixed(1)} ${p.y.toFixed(1)}`).join(" ");
    const areaD = `${lineD} L ${pts[pts.length - 1].x.toFixed(1)} ${(H - padB).toFixed(1)} L ${pts[0].x.toFixed(1)} ${(H - padB).toFixed(1)} Z`;

    el.innerHTML = `
      <svg viewBox="0 0 ${W} ${H}" width="100%" height="${H}" preserveAspectRatio="none" aria-hidden="true">
        <defs>
          <linearGradient id="adTrendFill" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stop-color="#22c55e" stop-opacity="0.35"/>
            <stop offset="100%" stop-color="#22c55e" stop-opacity="0"/>
          </linearGradient>
        </defs>
        <path d="${areaD}" fill="url(#adTrendFill)" stroke="none"/>
        <path d="${lineD}" fill="none" stroke="#22c55e" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
        ${pts
          .filter((_, i) => i % 3 === 0 || i === n - 1)
          .map((p) => `<text x="${p.x.toFixed(1)}" y="${H - 4}" text-anchor="middle" font-size="9" fill="#8b9aab">${esc(p.label)}</text>`)
          .join("")}
      </svg>`;
  }

  function updatePeriodLabels(analytics) {
    const label = analytics.periodLabel || "All time";
    const first = analytics.firstTransactionAt ? fmtDate(analytics.firstTransactionAt) : null;
    const last = analytics.lastTransactionAt ? fmtDate(analytics.lastTransactionAt) : null;
    const rangeText = first && last ? `${first} → ${last}` : label;
    const periodEl = document.getElementById("ad-period-label");
    const incomingPeriodEl = document.getElementById("stat-incoming-period");
    const recentPeriodEl = document.getElementById("ad-recent-period");
    const count = Number(analytics.recordCount || 0);

    if (periodEl) {
      periodEl.textContent = count > 0
        ? `${label} · ${count} record${count === 1 ? "" : "s"} · ${rangeText}`
        : `${label} · no records yet`;
    }
    if (incomingPeriodEl) incomingPeriodEl.textContent = label;
    if (recentPeriodEl) recentPeriodEl.textContent = label;
  }

  async function loadBalance(options = {}) {
    const valueEl = document.getElementById("stat-balance");
    const updatedEl = document.getElementById("stat-balance-updated");
    try {
      if (valueEl) valueEl.textContent = "Loading...";
      const result = await requestJson("balance");
      if (valueEl) valueEl.textContent = `${esc(result.currency || "TZS")} ${new Intl.NumberFormat("en-US", { maximumFractionDigits: 2 }).format(Number(result.balance || 0))}`;
      if (updatedEl) updatedEl.textContent = `Last updated: ${fmtDate(result.lastUpdated)}`;
      setBanner("ad-db-banner", "");
    } catch (error) {
      if (valueEl) valueEl.textContent = "Balance unavailable";
      if (updatedEl) updatedEl.textContent = "Last updated: --";
      setBanner("ad-db-banner", error.message, "error", { toast: !options.manual });
    }
  }

  async function loadSettings() {
    try {
      const result = await requestJson("payout-settings");
      latestSettings = result;
      document.getElementById("stat-dest").textContent = result.maskedDestination || "—";
      setAutoPayoutUi(!!result.enabled, result.mode || "TEST");
      const modeSelect = document.getElementById("ad-payout-mode");
      if (modeSelect && result.mode) {
        modeSelect.value = result.mode === "LIVE_AUTO" ? "LIVE_AUTO" : "MANUAL_APPROVAL";
      }
      if (result.warning) {
        setBanner("ad-payouts-error", result.warning, "warning", { toast: true });
      }
    } catch (error) {
      setAutoPayoutUi(false, "ERROR");
      setBanner("ad-payouts-error", error.message, "error", { toast: true });
    }
  }

  function isAutoPayoutActive() {
    return Boolean(latestSettings?.enabled) && String(latestSettings?.mode || "").toUpperCase() === "LIVE_AUTO";
  }

  function isManualPayoutActive() {
    return Boolean(latestSettings?.enabled) && !isAutoPayoutActive();
  }

  function renderRecentCollections() {
    const recent = document.getElementById("ad-recent");
    if (!recent) return;
    const rows = latestRecentRows;
    const totalPages = Math.max(1, Math.ceil(rows.length / PAGE_SIZE));
    recentPage = Math.min(Math.max(1, recentPage), totalPages);
    const slice = rows.slice((recentPage - 1) * PAGE_SIZE, recentPage * PAGE_SIZE);
    recent.innerHTML = slice.length
      ? slice.map((row) => `
          <li>
            <div>
              <strong>${esc(row.orderReference || row.controlNumber || "—")}</strong>
              <div style="color:#8b9aab;font-size:0.8rem">${statusBadge(row.status)} · ${fmtDate(row.createdAt)}</div>
            </div>
            <strong>${money(row.amount)}</strong>
          </li>`).join("")
      : "<li>No ClickPesa transactions were found for this period.</li>";
    renderPager("ad-recent-pager", recentPage, rows.length, (page) => {
      recentPage = page;
      renderRecentCollections();
    });
  }

  async function loadStatement() {
    try {
      const result = await requestJson("analytics", {
        query: { period: analyticsPeriod },
      });
      const analytics = result.analytics || {};
      document.getElementById("stat-incoming").textContent = money(analytics.moneyIn || 0);
      document.getElementById("stat-success").textContent = String(analytics.success || 0);
      document.getElementById("stat-pending").textContent = String(analytics.pending || 0);
      document.getElementById("stat-failed").textContent = String(analytics.failed || 0);
      updatePeriodLabels(analytics);
      drawTrend(document.getElementById("ad-trend"), analytics.trendDays || []);
      drawPie(document.getElementById("ad-pie"), analytics);
      const recent = document.getElementById("ad-recent");
      latestRecentRows = analytics.recentCollections || [];
      recentPage = 1;
      renderRecentCollections();
      setBanner("ad-statement-error", "");
      setBanner("ad-recent-error", "");
    } catch (error) {
      drawTrend(document.getElementById("ad-trend"), []);
      drawPie(document.getElementById("ad-pie"), {});
      latestRecentRows = [];
      recentPage = 1;
      renderRecentCollections();
      setBanner("ad-statement-error", error.message, "error", { toast: true });
      clearBanner("ad-recent-error");
    }
  }

  function bindCopyButtons() {
    document.querySelectorAll("[data-copy]").forEach((btn) => {
      btn.addEventListener("click", async () => {
        const value = btn.getAttribute("data-copy") || "";
        await navigator.clipboard?.writeText(value);
        const oldText = btn.textContent;
        btn.textContent = "Copied";
        setTimeout(() => { btn.textContent = oldText; }, 1000);
      });
    });
  }

  function appEntryBase() {
    const path = window.location.pathname.replace(/[^/]*$/, "");
    return `${window.location.origin}${path}index.php`;
  }

  function resolveInvoiceUrl(url) {
    const raw = String(url || "").trim();
    if (!raw) return "";
    if (/^https?:\/\//i.test(raw)) return raw;
    if (raw.startsWith("/api/") && /\/frontend\/web\//i.test(window.location.pathname)) {
      return `${appEntryBase()}${raw}`;
    }
    if (raw.startsWith("/")) return `${window.location.origin}${raw}`;
    const base = window.location.pathname.replace(/[^/]*$/, "");
    return `${window.location.origin}${base}${raw}`;
  }

  async function openInvoice(url, download) {
    const resolved = resolveInvoiceUrl(url);
    if (!resolved) return;
    const target = download
      ? `${resolved}${resolved.includes("?") ? "&" : "?"}download=1`
      : resolved;
    if (!download) {
      window.open(target, "_blank", "noopener");
      return;
    }
    try {
      const res = await fetch(target, { credentials: "same-origin" });
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      const blob = await res.blob();
      const objectUrl = URL.createObjectURL(blob);
      const link = document.createElement("a");
      link.href = objectUrl;
      link.download = `receipt-${Date.now()}.pdf`;
      document.body.appendChild(link);
      link.click();
      link.remove();
      URL.revokeObjectURL(objectUrl);
    } catch (error) {
      notify(error.message || "Could not download invoice.", "error");
      window.open(target, "_blank", "noopener");
    }
  }

  function renderControlsTable() {
    const body = document.getElementById("ad-controls-body");
    if (!body) return;
    const rows = latestControlRows;
    const totalPages = Math.max(1, Math.ceil(rows.length / PAGE_SIZE));
    controlsPage = Math.min(Math.max(1, controlsPage), totalPages);
    const slice = rows.slice((controlsPage - 1) * PAGE_SIZE, controlsPage * PAGE_SIZE);
    body.innerHTML = slice.length ? slice.map((row) => {
      const status = String(row.status || "").toUpperCase();
      const isPending = status === "PENDING";
      const showResend = isPending && row.canResend !== false;
      const showWithdraw = row.canWithdraw && isManualPayoutActive();
      return `
        <tr>
          <td>${esc(row.orderId || "—")}</td>
          <td>${esc(row.customerName || "—")}</td>
          <td>${esc(row.controlNumber || "—")}</td>
          <td>${esc(row.reference || "—")}</td>
          <td>${money(row.amount)}</td>
          <td>${row.receivedAmount != null ? money(row.receivedAmount) : "—"}</td>
          <td>${esc(row.withdrawStatus || "—")}</td>
          <td>${statusBadge(row.status)}</td>
          <td>
            <div class="ad-actions">
            ${row.hasControlNumber ? `<button type="button" class="ad-btn ad-btn--copy" data-copy="${esc(row.controlNumber)}"><i class="fa-regular fa-copy"></i><span>Copy</span></button>` : ""}
            ${showResend ? `<button type="button" class="ad-btn ad-btn--resend" data-resend="${row.id}"><i class="fa-solid fa-paper-plane"></i><span>Resend</span></button>` : ""}
            ${showWithdraw ? `<button type="button" class="ad-btn ad-btn--withdraw" data-withdraw="${row.id}"><i class="fa-solid fa-money-bill-wave"></i><span>Withdraw</span></button>` : ""}
            ${row.invoiceUrl ? `<button type="button" class="ad-btn ad-btn--view" data-invoice="${esc(row.invoiceUrl)}"><i class="fa-solid fa-receipt"></i><span>View</span></button>` : ""}
            ${row.invoiceUrl ? `<button type="button" class="ad-btn ad-btn--download" data-invoice-download="${esc(row.invoiceUrl)}"><i class="fa-solid fa-file-pdf"></i><span>PDF</span></button>` : ""}
            </div>
          </td>
        </tr>`;
    }).join("") : `<tr><td colspan="9">No transactions yet.</td></tr>`;
    bindCopyButtons();
    body.querySelectorAll("[data-invoice]").forEach((btn) => {
      btn.addEventListener("click", () => openInvoice(btn.getAttribute("data-invoice") || "", false));
    });
    body.querySelectorAll("[data-invoice-download]").forEach((btn) => {
      btn.addEventListener("click", () => openInvoice(btn.getAttribute("data-invoice-download") || "", true));
    });
    body.querySelectorAll("[data-resend]").forEach((btn) => {
      btn.addEventListener("click", async () => {
        const paymentId = Number(btn.getAttribute("data-resend"));
        if (!paymentId) return;
        btn.disabled = true;
        try {
          const result = await requestJson("resend-payment", { method: "POST", body: { id: paymentId } });
          notify(result.message || "Payment status refreshed.", "success");
          await Promise.all([loadControls(), loadStatement(), loadPayouts(), loadBalance()]);
        } catch (error) {
          notify(error.message || "Resend failed.", "error");
        } finally {
          btn.disabled = false;
        }
      });
    });
    body.querySelectorAll("[data-withdraw]").forEach((btn) => {
      btn.addEventListener("click", async () => {
        const paymentId = Number(btn.getAttribute("data-withdraw"));
        if (!paymentId) return;
        btn.disabled = true;
        try {
          const result = await requestJson("withdraw", { method: "POST", body: { id: paymentId } });
          notify(result.message || "Withdraw initiated.", "success");
          await Promise.all([loadControls(), loadPayouts(), loadBalance()]);
        } catch (error) {
          notify(error.message || "Withdraw failed.", "error");
        } finally {
          btn.disabled = false;
        }
      });
    });
    renderPager("ad-controls-pager", controlsPage, rows.length, (page) => {
      controlsPage = page;
      renderControlsTable();
    });
  }

  async function loadControls() {
    const body = document.getElementById("ad-controls-body");
    body.innerHTML = `<tr><td colspan="9">Loading...</td></tr>`;
    try {
      const result = await requestJson("control-numbers");
      latestControlRows = result.items || [];
      if (result.payoutSettings) {
        latestSettings = { ...(latestSettings || {}), ...result.payoutSettings };
      }
      controlsPage = 1;
      renderControlsTable();
      setBanner("ad-controls-error", "");
    } catch (error) {
      body.innerHTML = `<tr><td colspan="9">No transactions yet.</td></tr>`;
      setBanner("ad-controls-error", error.message);
    }
  }

  function renderPayoutsTable() {
    const body = document.getElementById("ad-payouts-body");
    if (!body) return;
    const rows = latestPayoutRows;
    const totalPages = Math.max(1, Math.ceil(rows.length / PAGE_SIZE));
    payoutsPage = Math.min(Math.max(1, payoutsPage), totalPages);
    const slice = rows.slice((payoutsPage - 1) * PAGE_SIZE, payoutsPage * PAGE_SIZE);
    body.innerHTML = slice.length ? slice.map((row) => `
        <tr>
          <td>${esc(row.payoutReference)}</td>
          <td>${esc(row.destinationMasked || "—")}</td>
          <td>${money(row.amount)}</td>
          <td>${row.fee != null ? money(row.fee) : "—"}</td>
          <td>${statusBadge(row.status)}</td>
          <td>${esc(row.provider || "—")}</td>
          <td>${esc(row.lastError || "—")}</td>
          <td>
            ${fmtDate(row.updatedAt)}
            <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:6px">
              ${row.retryable ? `<button type="button" data-retry-payout="${row.id}">Retry</button>` : ""}
              <button type="button" data-view-payout="${row.id}">View</button>
            </div>
          </td>
        </tr>`).join("") : `<tr><td colspan="8">No automatic payouts have been processed.</td></tr>`;
    body.querySelectorAll("[data-retry-payout]").forEach((btn) => {
      btn.addEventListener("click", async () => {
        btn.disabled = true;
        try {
          await requestJson("retry-payout", { method: "POST", body: { id: Number(btn.getAttribute("data-retry-payout")) } });
          await loadPayouts();
        } catch (error) {
          setBanner("ad-payouts-error", error.message);
        } finally {
          btn.disabled = false;
        }
      });
    });
    body.querySelectorAll("[data-view-payout]").forEach((btn) => {
      btn.addEventListener("click", () => {
        const row = latestPayoutRows.find((item) => Number(item.id) === Number(btn.getAttribute("data-view-payout")));
        showPayoutDetails(row).catch(() => {});
      });
    });
    renderPager("ad-payouts-pager", payoutsPage, rows.length, (page) => {
      payoutsPage = page;
      renderPayoutsTable();
    });
  }

  async function loadPayouts() {
    const body = document.getElementById("ad-payouts-body");
    body.innerHTML = `<tr><td colspan="8">Loading...</td></tr>`;
    try {
      const result = await requestJson("payouts");
      latestPayoutRows = result.items || [];
      payoutsPage = 1;
      renderPayoutsTable();
      setBanner("ad-payouts-error", latestSettings?.warning || "", latestSettings?.warning ? "warning" : "info", { toast: true });
    } catch (error) {
      body.innerHTML = `<tr><td colspan="8">No automatic payouts have been processed.</td></tr>`;
      setBanner("ad-payouts-error", error.message);
    }
  }

  function renderUsersTable() {
    const body = document.getElementById("ad-users-body");
    if (!body) return;
    const rows = latestUserRows;
    const totalPages = Math.max(1, Math.ceil(rows.length / PAGE_SIZE));
    usersPage = Math.min(Math.max(1, usersPage), totalPages);
    const slice = rows.slice((usersPage - 1) * PAGE_SIZE, usersPage * PAGE_SIZE);
    body.innerHTML = slice.length ? slice.map((row) => `
        <tr>
          <td>${esc(row.fullName || "—")}</td>
          <td>${esc(row.phone || "—")}</td>
          <td>${esc(row.username || "—")}</td>
          <td>${statusBadge(row.role || "user")}</td>
          <td>${fmtDate(row.createdAt)}</td>
        </tr>`).join("") : `<tr><td colspan="5">No registered users yet.</td></tr>`;
    renderPager("ad-users-pager", usersPage, rows.length, (page) => {
      usersPage = page;
      renderUsersTable();
    });
  }

  async function loadUsers() {
    const body = document.getElementById("ad-users-body");
    if (!body) return;
    body.innerHTML = `<tr><td colspan="5">Loading...</td></tr>`;
    try {
      const res = await fetch("auth-api.php?action=list-users", { credentials: "same-origin" });
      const data = await res.json();
      if (!res.ok || !data.ok) throw new Error(data.message || "Could not load users.");
      latestUserRows = data.items || [];
      usersPage = 1;
      renderUsersTable();
      setBanner("ad-users-error", "");
    } catch (error) {
      body.innerHTML = `<tr><td colspan="5">No registered users yet.</td></tr>`;
      setBanner("ad-users-error", error.message);
    }
  }

  async function syncTransactions() {
    const btn = document.getElementById("ad-sync-transactions");
    try {
      if (btn) btn.disabled = true;
      await requestJson("sync-transactions", {
        method: "POST",
        body: {
          startDate: new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().slice(0, 10),
          endDate: new Date().toISOString().slice(0, 10),
          currency: "TZS",
        },
      });
      setBanner("ad-statement-error", "Transactions synced from ClickPesa account statement.", "success");
    } catch (error) {
      setBanner("ad-statement-error", `Sync from ClickPesa failed: ${error.message}. Showing database records.`, "warning");
    } finally {
      if (btn) btn.disabled = false;
      await Promise.all([loadStatement(), loadControls(), loadPayouts(), loadBalance()]);
    }
  }

  async function savePayoutDestination(event) {
    event.preventDefault();
    const msg = document.getElementById("ad-payout-msg");
    const adminPassword = await promptAdminPassword();
    if (!adminPassword) return;
    try {
      if (msg) {
        msg.className = "ad-msg";
        msg.textContent = "Saving...";
      }
      const payload = Object.fromEntries(new FormData(event.target).entries());
      await requestJson("payout-settings", {
        method: "POST",
        body: {
          ...payload,
          mode: payload.payoutMode || latestSettings?.mode || "MANUAL_APPROVAL",
          enabled: latestSettings?.enabled ?? false,
          currentAdminPassword: adminPassword,
        },
      });
      await loadSettings();
      if (msg) {
        msg.className = "ad-msg is-ok";
        msg.textContent = "Payout destination saved.";
      }
      notify("Payout destination saved.", "success");
    } catch (error) {
      if (msg) {
        msg.className = "ad-msg";
        msg.textContent = "";
      }
      notify(error.message, "error");
    }
  }

  async function toggleAutoPayout() {
    const toggleCard = document.getElementById("stat-auto-card");
    const enabling = document.getElementById("stat-auto")?.textContent !== "ON";
    const adminPassword = await promptAdminPassword();
    if (!adminPassword) return;
    const payoutPhone = document.querySelector('#ad-payout-form input[name="mobileMoneyNumber"]')?.value || "+255715296092";
    const payoutMode = document.getElementById("ad-payout-mode")?.value || "MANUAL_APPROVAL";
    try {
      if (toggleCard) toggleCard.style.pointerEvents = "none";
      await requestJson("payout-settings", {
        method: "POST",
        body: {
          enabled: enabling,
          mode: enabling ? payoutMode : "TEST",
          mobileMoneyNumber: payoutPhone,
          currentAdminPassword: adminPassword,
          manualApprovalRequired: payoutMode === "MANUAL_APPROVAL",
        },
      });
      await loadSettings();
      setBanner(
        "ad-payouts-error",
        enabling
          ? payoutMode === "LIVE_AUTO"
            ? "Automatic payout enabled — funds go to destination when paid."
            : "Manual payout enabled — use Withdraw button on each payment."
          : "Automatic payout disabled.",
        "success"
      );
    } catch (error) {
      setBanner("ad-payouts-error", error.message, "error");
    } finally {
      if (toggleCard) toggleCard.style.pointerEvents = "";
    }
  }

  async function createControlNumber(event) {
    event.preventDefault();
    const msg = document.getElementById("ad-cn-msg");
    const submit = event.target.querySelector('button[type="submit"]');
    try {
      if (submit) submit.disabled = true;
      if (msg) {
        msg.className = "ad-msg";
        msg.textContent = "";
      }
      showWaitSwal(
        "Tafadhali subiri",
        '<p style="margin:0.35rem 0 0;font-size:0.95rem;font-weight:600;color:#475569">Tunatengeneza control number kutoka ClickPesa…</p><p style="margin:0.5rem 0 0;font-size:0.85rem;font-weight:500;color:#94a3b8">Usifunge ukurasa huu.</p>'
      );
      const payload = Object.fromEntries(new FormData(event.target).entries());
      if (!String(payload.order_id || "").trim()) {
        delete payload.order_id;
      }
      if (!String(payload.description || "").trim()) {
        payload.description = "BillPay payment";
      }
      const data = await requestJson("create-control-number", { method: "POST", body: payload });
      event.target.reset();
      await showControlNumberResult({ ...data, description: payload.description });
      await loadControls();
    } catch (error) {
      showControlNumberError(error.message, "Control Number Haijafanikiwa");
    } finally {
      if (submit) submit.disabled = false;
    }
  }

  function bindGeneralAnalysis() {
    const overlay = document.getElementById("ad-ga-overlay");
    const openBtn = document.getElementById("ad-ga-open");
    const closeBtn = document.getElementById("ad-ga-close");
    if (!overlay) return;

    const sectionMap = {
      analytics: "ad-section-analytics",
      "control-number": "ad-section-control-number",
      transactions: "ad-section-transactions",
      "payout-dest": "ad-section-payout-dest",
      payouts: "ad-section-payouts",
      users: "ad-section-users",
      recent: "ad-section-recent",
      autopay: "stat-auto-card",
    };

    function updateHubSummary() {
      const balanceEl = document.getElementById("ad-ga-hub-balance");
      const autoEl = document.getElementById("ad-ga-hub-auto");
      const balance = document.getElementById("stat-balance")?.textContent || "—";
      const autoOn = document.getElementById("stat-auto")?.textContent === "ON";
      if (balanceEl) balanceEl.textContent = balance;
      if (autoEl) {
        autoEl.textContent = autoOn ? "Auto payout ON" : "Auto payout OFF";
        autoEl.classList.toggle("is-on", autoOn);
      }
    }

    function openOverlay() {
      updateHubSummary();
      overlay.hidden = false;
      overlay.setAttribute("aria-hidden", "false");
      document.body.classList.add("ad-ga-active");
    }

    function closeOverlay() {
      overlay.hidden = true;
      overlay.setAttribute("aria-hidden", "true");
      document.body.classList.remove("ad-ga-active");
    }

    function scrollToSection(key) {
      const id = sectionMap[key];
      if (!id) return;
      closeOverlay();
      window.setTimeout(() => {
        const el = document.getElementById(id);
        if (!el) return;
        el.scrollIntoView({ behavior: "smooth", block: "start" });
        el.classList.add("ad-ga-highlight");
        window.setTimeout(() => el.classList.remove("ad-ga-highlight"), 1400);
      }, 120);
    }

    openBtn?.addEventListener("click", openOverlay);
    closeBtn?.addEventListener("click", closeOverlay);
    document.getElementById("ad-ga-hub")?.addEventListener("click", () => {
      closeOverlay();
      document.getElementById("ad-stats")?.scrollIntoView({ behavior: "smooth", block: "start" });
    });

    overlay.querySelectorAll("[data-ga-target]").forEach((node) => {
      node.addEventListener("click", (event) => {
        if (node.classList.contains("ad-ga-chip--link")) return;
        event.preventDefault();
        const target = node.getAttribute("data-ga-target") || "";
        const action = node.getAttribute("data-ga-action") || "scroll";
        if (action === "sync") {
          closeOverlay();
          syncTransactions().catch(() => {});
          return;
        }
        scrollToSection(target);
      });
    });

    document.addEventListener("keydown", (event) => {
      if (event.key === "Escape" && !overlay.hidden) closeOverlay();
    });
  }

  function exportPayoutCsv() {
    if (!latestPayoutRows.length) return;
    const header = ["Payout Reference", "Destination", "Amount", "Fee", "Status", "Provider", "Error", "Updated"];
    const lines = [header.join(",")].concat(latestPayoutRows.map((row) => [
      row.payoutReference,
      row.destinationMasked,
      row.amount,
      row.fee ?? "",
      row.status,
      row.provider ?? "",
      (row.lastError || "").replace(/,/g, " "),
      row.updatedAt || "",
    ].map((v) => `"${String(v ?? "").replace(/"/g, '""')}"`).join(",")));
    const blob = new Blob([lines.join("\n")], { type: "text/csv;charset=utf-8" });
    const url = URL.createObjectURL(blob);
    const link = document.createElement("a");
    link.href = url;
    link.download = "clickpesa-payouts.csv";
    link.click();
    URL.revokeObjectURL(url);
  }

  async function loadAll() {
    await Promise.all([loadBalance(), loadSettings(), loadStatement(), loadControls(), loadPayouts(), loadUsers()]);
  }

  document.getElementById("ad-refresh")?.addEventListener("click", () => loadAll());
  document.getElementById("ad-balance-refresh")?.addEventListener("click", () => loadBalance({ manual: true }));
  document.getElementById("ad-payouts-refresh")?.addEventListener("click", () => loadPayouts());
  document.getElementById("ad-users-refresh")?.addEventListener("click", () => loadUsers());
  document.getElementById("ad-sync-transactions")?.addEventListener("click", () => syncTransactions());
  document.getElementById("ad-period-select")?.addEventListener("change", (event) => {
    analyticsPeriod = event.target.value || "all";
    loadStatement().catch(() => {});
  });
  document.getElementById("stat-auto-card")?.addEventListener("click", () => toggleAutoPayout());
  document.getElementById("stat-auto-card")?.addEventListener("keydown", (e) => {
    if (e.key === "Enter" || e.key === " ") {
      e.preventDefault();
      toggleAutoPayout();
    }
  });
  document.getElementById("ad-cn-form")?.addEventListener("submit", createControlNumber);
  document.getElementById("ad-payout-form")?.addEventListener("submit", savePayoutDestination);
  document.getElementById("ad-payouts-refresh")?.insertAdjacentHTML("afterend", ' <button type="button" class="ad-refresh" id="ad-payouts-export">Export CSV</button>');
  document.getElementById("ad-payouts-export")?.addEventListener("click", exportPayoutCsv);
  bindGeneralAnalysis();

  loadAll().catch((error) => {
    setBanner("ad-db-banner", error.message, "error", { toast: true });
  });
  window.setInterval(loadBalance, REFRESH_MS);
})();
