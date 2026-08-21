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
    syncPortalCards();
  }

  const chartStore = window.__gwAdminCharts || (window.__gwAdminCharts = {});

  function destroyChart(key) {
    if (chartStore[key]) {
      try {
        chartStore[key].destroy();
      } catch (_) {
        /* ignore */
      }
      delete chartStore[key];
    }
  }

  function waPolar(cx, cy, r, angleDeg) {
    const rad = ((angleDeg - 90) * Math.PI) / 180;
    return { x: cx + r * Math.cos(rad), y: cy + r * Math.sin(rad) };
  }

  function waArcPath(cx, cy, r, startAngle, endAngle) {
    const start = waPolar(cx, cy, r, endAngle);
    const end = waPolar(cx, cy, r, startAngle);
    const largeArc = endAngle - startAngle <= 180 ? 0 : 1;
    return `M ${cx} ${cy} L ${start.x} ${start.y} A ${r} ${r} 0 ${largeArc} 0 ${end.x} ${end.y} Z`;
  }

  function normalizePieCounts(pie) {
    let success = Number(pie.success || 0);
    let pending = Number(pie.pending || 0);
    let failed = Number(pie.failed || pie.failedSales || 0);
    if (success + pending + failed > 0) {
      return { success, pending, failed };
    }
    const rows = Array.isArray(pie.recentCollections) ? pie.recentCollections : [];
    rows.forEach((row) => {
      const st = String(row.status || "").toUpperCase();
      if (st === "SUCCESS" || st === "COMPLETED" || st === "PAID") success += 1;
      else if (st === "FAILED" || st === "CANCELLED" || st === "EXPIRED") failed += 1;
      else pending += 1;
    });
    return { success, pending, failed };
  }

  function drawPie(el, pie) {
    if (!el) return;
    const counts = normalizePieCounts(pie || {});
    const success = counts.success;
    const pending = counts.pending;
    const failed = counts.failed;
    const total = success + pending + failed;
    destroyChart("pie");
    el.classList.remove("is-empty");
    if (!total) {
      el.classList.add("is-empty");
      el.innerHTML = `<p class="ad-trend-empty">No payments for this period.</p>`;
      return;
    }

    const slices = [
      { label: "Success", count: success, color: "#0868AC" },
      { label: "Pending", count: pending, color: "#F3B61F" },
      { label: "Failed", count: failed, color: "#882828" },
    ].filter((s) => s.count > 0);

    const W = 320;
    const H = 220;
    const cx = 110;
    const cy = 110;
    const r = 78;
    const inner = 42;
    let angle = 0;
    const paths = [];

    slices.forEach((slice) => {
      const sweep = (slice.count / total) * 360;
      const start = angle;
      const end = angle + Math.min(sweep, 359.999);
      if (slices.length === 1) {
        paths.push(`<circle cx="${cx}" cy="${cy}" r="${r}" fill="${slice.color}" />`);
      } else {
        paths.push(`<path d="${waArcPath(cx, cy, r, start, end)}" fill="${slice.color}" stroke="#fff" stroke-width="2"/>`);
      }
      angle = end;
    });

    const legend = slices.map((slice) => {
      const pct = Math.round((slice.count / total) * 100);
      return `<li><span class="ad-pie-dot" style="background:${slice.color}"></span><strong>${esc(slice.label)}</strong><em>${slice.count} · ${pct}%</em></li>`;
    }).join("");

    el.innerHTML = `
      <div class="ad-pie-visual">
        <svg class="ad-pie-svg" viewBox="0 0 ${W} ${H}" role="img" aria-label="Payment status pie chart">
          ${paths.join("")}
          <circle cx="${cx}" cy="${cy}" r="${inner}" fill="#ffffff"/>
          <text x="${cx}" y="${cy - 4}" text-anchor="middle" font-size="11" font-weight="700" fill="#64748b">TOTAL</text>
          <text x="${cx}" y="${cy + 14}" text-anchor="middle" font-size="20" font-weight="800" fill="#002d58">${total}</text>
        </svg>
        <ul class="ad-pie-legend">${legend}</ul>
      </div>`;
  }

  function drawTrend(el, days) {
    if (!el) return;
    const list = Array.isArray(days) ? days : [];
    const totalHits = list.reduce((sum, d) => sum + Number(d.count || 0), 0);
    destroyChart("trend");
    if (!totalHits) {
      el.innerHTML = '<p class="ad-trend-empty">No activity in the last 14 days yet.</p>';
      return;
    }
    if (typeof ApexCharts === "undefined") {
      el.innerHTML = '<p class="ad-trend-empty">Chart library failed to load.</p>';
      return;
    }

    const labels = list.map((d) => String(d.label || ""));
    const values = list.map((d) => Number(d.count || 0));
    const seriesData = labels.map((label, index) => ({ x: label, y: values[index] ?? 0 }));
    let peakIdx = 0;
    values.forEach((v, i) => {
      if (v > values[peakIdx]) peakIdx = i;
    });
    const midIdx = Math.floor(values.length / 2);
    const points = [
      {
        x: labels[peakIdx],
        y: values[peakIdx],
        marker: { size: 6, fillColor: "#F3B61F", strokeColor: "#002d58", strokeWidth: 2 },
        label: {
          text: "Peak",
          borderColor: "#F3B61F",
          style: { color: "#002d58", background: "#F3B61F", fontWeight: 700 },
        },
      },
      {
        x: labels[midIdx],
        y: values[midIdx],
        marker: { size: 5, fillColor: "#0D8ACB", strokeColor: "#fff", strokeWidth: 2 },
        label: {
          text: "Typical",
          borderColor: "#0D8ACB",
          style: { color: "#fff", background: "#0D8ACB", fontWeight: 700 },
        },
      },
    ];

    el.innerHTML = "";
    const chart = new ApexCharts(el, {
      series: [{ name: "Transactions", data: seriesData }],
      chart: {
        height: 220,
        type: "line",
        id: "ad-annotation-trend",
        zoom: { enabled: false },
        selection: { enabled: false },
        toolbar: { show: false },
        background: "#ffffff",
        foreColor: "#334155",
        parentHeightOffset: 0,
        sparkline: { enabled: false },
      },
      theme: { mode: "light" },
      annotations: { points },
      dataLabels: { enabled: false },
      stroke: { curve: "smooth", width: 3 },
      grid: {
        padding: { top: 8, right: 16, bottom: 0, left: 8 },
        borderColor: "#e2e8f0",
        row: { colors: ["#ffffff", "#f8fafc"], opacity: 0.6 },
      },
      title: {
        text: "Transaction trend · last 14 days",
        align: "left",
        style: { fontSize: "14px", fontWeight: 700, color: "#002d58" },
      },
      colors: ["#008FFB"],
      markers: { size: 3, colors: ["#0868AC"], strokeColors: "#fff", strokeWidth: 2 },
      xaxis: {
        type: "category",
        labels: { style: { colors: "#64748b", fontSize: "11px" } },
        axisBorder: { color: "#e2e8f0" },
        axisTicks: { color: "#e2e8f0" },
      },
      yaxis: {
        min: 0,
        decimalsInFloat: 0,
        title: { text: "Count", style: { color: "#64748b" } },
        labels: { style: { colors: "#64748b" } },
      },
      tooltip: {
        theme: "light",
        y: {
          formatter: function (val) {
            return `${val} transaction${Number(val) === 1 ? "" : "s"}`;
          },
        },
      },
    });
    chartStore.trend = chart;
    chart.render();
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
      syncPortalCards();
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
      const testBadge = document.getElementById("ad-test-mode-badge");
      if (testBadge) {
        testBadge.hidden = !result.testMode;
      }
      syncPortalCards();
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
              <div class="ad-recent-meta">${statusBadge(row.status)} · ${fmtDate(row.createdAt)}</div>
            </div>
            <strong>${money(row.amount)}</strong>
          </li>`).join("")
      : '<li class="ad-recent-empty">No ClickPesa transactions were found for this period.</li>';
    renderPager("ad-recent-pager", recentPage, rows.length, (page) => {
      recentPage = page;
      renderRecentCollections();
    });
    syncPortalCards();
  }

  let latestAnalytics = null;
  let chartsNeedRedraw = false;

  function chartsContainerVisible() {
    const detail = document.getElementById("ad-detail-sections");
    const analytics = document.getElementById("ad-section-analytics");
    if (!detail || !analytics) return false;
    if (detail.hidden || detail.classList.contains("is-collapsed")) return false;
    if (!document.body.classList.contains("ad-view-detail")) return false;
    if (document.body.getAttribute("data-ad-section") !== "analytics") return false;
    return analytics.getClientRects().length > 0 || getComputedStyle(analytics).display !== "none";
  }

  function buildTrendFromPayments(payments, days = 14) {
    const map = {};
    const today = new Date();
    for (let i = days - 1; i >= 0; i -= 1) {
      const d = new Date(today);
      d.setHours(12, 0, 0, 0);
      d.setDate(d.getDate() - i);
      const key = d.toISOString().slice(0, 10);
      map[key] = {
        date: key,
        label: d.toLocaleDateString("en-GB", { day: "numeric", month: "short" }),
        count: 0,
      };
    }
    (payments || []).forEach((p) => {
      const raw = p.createdAt || p.updatedAt;
      if (!raw) return;
      const dt = new Date(raw);
      if (Number.isNaN(dt.getTime())) return;
      const key = dt.toISOString().slice(0, 10);
      if (map[key]) map[key].count += 1;
    });
    return Object.values(map);
  }

  function analyticsFromMergedPayments(summary) {
    const payments = summary?.payments || [];
    let success = 0;
    let pending = 0;
    let failed = 0;
    let moneyIn = 0;
    payments.forEach((p) => {
      const status = String(p.status || "").toUpperCase();
      const amount = Number(p.amount || 0);
      if (status === "SUCCESS") {
        success += 1;
        moneyIn += amount;
      } else if (status === "FAILED") {
        failed += 1;
      } else {
        pending += 1;
      }
    });
    return {
      moneyIn: Math.round(moneyIn * 100) / 100,
      success,
      pending,
      failed,
      recordCount: payments.length,
      periodLabel: "Live payments (same source as user dashboard)",
      trendDays: buildTrendFromPayments(payments, 14),
      recentCollections: payments.slice(0, 15).map((p) => ({
        orderReference: p.orderReference,
        controlNumber: "",
        amount: p.amount,
        status: p.status,
        createdAt: p.createdAt,
      })),
      source: "payments-merge",
    };
  }

  async function loadMergedAnalyticsFallback() {
    if (!window.GetwayPaymentsMerge?.loadMergedPayments) return null;
    const apiBase = window.TIS_API_BASE || window.BASE_API_URL || "https://getways-app.onrender.com";
    const clickpesaBase = window.CLICKPESA_API_BASE || `${window.location.origin}/api/clickpesa`;
    const summary = await window.GetwayPaymentsMerge.loadMergedPayments(apiBase, clickpesaBase, {
      "Content-Type": "application/json",
    });
    return analyticsFromMergedPayments(summary);
  }

  function applyAnalyticsToCharts(analytics) {
    const data = analytics || {};
    drawTrend(document.getElementById("ad-trend"), data.trendDays || []);
    drawPie(document.getElementById("ad-pie"), data);
  }

  function redrawChartsIfVisible() {
    if (!latestAnalytics) return;
    if (!chartsContainerVisible()) {
      chartsNeedRedraw = true;
      return;
    }
    chartsNeedRedraw = false;
    window.requestAnimationFrame(() => {
      applyAnalyticsToCharts(latestAnalytics);
      window.setTimeout(() => {
        try {
          chartStore.trend?.resize?.();
        } catch (_) {
          /* ignore */
        }
      }, 120);
    });
  }

  async function loadStatement() {
    try {
      let analytics = {};
      let warning = "";
      try {
        const result = await requestJson("analytics", {
          query: { period: analyticsPeriod },
        });
        analytics = result.analytics || {};
        warning = result.warning || "";
      } catch (dbError) {
        warning = dbError.message || String(dbError);
      }

      const emptyDb =
        !Number(analytics.recordCount || 0) &&
        !Number(analytics.success || 0) &&
        !Number(analytics.pending || 0) &&
        !Number(analytics.failed || 0);

      if (emptyDb) {
        try {
          const merged = await loadMergedAnalyticsFallback();
          if (merged && Number(merged.recordCount || 0) > 0) {
            analytics = merged;
            warning = "";
          }
        } catch (mergeError) {
          if (!warning) warning = mergeError.message || String(mergeError);
        }
      }

      latestAnalytics = analytics;
      document.getElementById("stat-incoming").textContent = money(analytics.moneyIn || 0);
      document.getElementById("stat-success").textContent = String(analytics.success || 0);
      document.getElementById("stat-pending").textContent = String(analytics.pending || 0);
      document.getElementById("stat-failed").textContent = String(analytics.failed || 0);
      updatePeriodLabels(analytics);
      if (chartsContainerVisible()) {
        applyAnalyticsToCharts(analytics);
        chartsNeedRedraw = false;
      } else {
        chartsNeedRedraw = true;
      }
      latestRecentRows = analytics.recentCollections || [];
      recentPage = 1;
      renderRecentCollections();
      if (warning) {
        setBanner("ad-statement-error", warning, "error");
      } else {
        setBanner("ad-statement-error", "");
      }
      setBanner("ad-recent-error", "");
      syncPortalCards();
    } catch (error) {
      latestAnalytics = { success: 0, pending: 0, failed: 0, trendDays: [], recentCollections: [] };
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
            <button type="button" class="ad-btn ad-btn--delete" data-delete-payment="${row.id}" data-delete-ref="${esc(row.reference || row.orderId || row.id)}" title="Delete transaction"><i class="fa-solid fa-trash"></i><span>Delete</span></button>
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
    body.querySelectorAll("[data-delete-payment]").forEach((btn) => {
      btn.addEventListener("click", async () => {
        const paymentId = Number(btn.getAttribute("data-delete-payment"));
        const ref = btn.getAttribute("data-delete-ref") || String(paymentId);
        if (!paymentId) return;
        if (!window.confirm(`Delete transaction ${ref}? This removes it from the dashboard only.`)) return;
        btn.disabled = true;
        try {
          const result = await requestJson("delete-payment", { method: "POST", body: { id: paymentId } });
          notify(result.message || "Transaction deleted.", "success");
          await Promise.all([loadControls(), loadStatement(), loadPayouts(), loadBalance()]);
        } catch (error) {
          notify(error.message || "Delete failed.", "error");
        } finally {
          btn.disabled = false;
        }
      });
    });
    renderPager("ad-controls-pager", controlsPage, rows.length, (page) => {
      controlsPage = page;
      renderControlsTable();
    });
    syncPortalCards();
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
            <div class="ad-payout-updated">${fmtDate(row.updatedAt)}</div>
            <div class="ad-actions ad-actions--payout">
              <button type="button" class="ad-btn ad-btn--status" data-refresh-payout="${esc(row.payoutReference)}" title="Refresh status"><i class="fa-solid fa-arrows-rotate"></i><span>Status</span></button>
              ${row.retryable ? `<button type="button" class="ad-btn ad-btn--retry" data-retry-payout="${row.id}" title="Retry payout"><i class="fa-solid fa-rotate-right"></i><span>Retry</span></button>` : ""}
              <button type="button" class="ad-btn ad-btn--view" data-view-payout="${row.id}" title="View details"><i class="fa-solid fa-eye"></i><span>View</span></button>
              <button type="button" class="ad-btn ad-btn--delete" data-delete-payout="${row.id}" data-delete-ref="${esc(row.payoutReference)}" title="Delete payout"><i class="fa-solid fa-trash"></i><span>Delete</span></button>
            </div>
          </td>
        </tr>`).join("") : `<tr><td colspan="8">No automatic payouts have been processed.</td></tr>`;
    body.querySelectorAll("[data-refresh-payout]").forEach((btn) => {
      btn.addEventListener("click", async () => {
        btn.disabled = true;
        try {
          await requestJson("refresh-payout-status", {
            method: "POST",
            body: { orderReference: btn.getAttribute("data-refresh-payout") },
          });
          await loadPayouts();
        } catch (error) {
          setBanner("ad-payouts-error", error.message);
        } finally {
          btn.disabled = false;
        }
      });
    });
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
    body.querySelectorAll("[data-delete-payout]").forEach((btn) => {
      btn.addEventListener("click", async () => {
        const id = Number(btn.getAttribute("data-delete-payout"));
        const ref = btn.getAttribute("data-delete-ref") || String(id);
        if (!id) return;
        if (!window.confirm(`Delete payout ${ref}? This removes it from the dashboard only.`)) return;
        btn.disabled = true;
        try {
          const result = await requestJson("delete-payout", { method: "POST", body: { id } });
          notify(result.message || "Payout deleted.", "success");
          await Promise.all([loadPayouts(), loadPayoutSummary()]);
        } catch (error) {
          setBanner("ad-payouts-error", error.message);
          notify(error.message || "Delete failed.", "error");
        } finally {
          btn.disabled = false;
        }
      });
    });
    renderPager("ad-payouts-pager", payoutsPage, rows.length, (page) => {
      payoutsPage = page;
      renderPayoutsTable();
    });
    syncPortalCards();
  }

  async function loadPayoutSummary() {
    try {
      const result = await requestJson("payout-summary");
      const counts = result.counts || {};
      const set = (id, val) => {
        const el = document.getElementById(id);
        if (el) el.textContent = String(val ?? "0");
      };
      set("ad-payout-success", counts.successful);
      set("ad-payout-pending", counts.pending);
      set("ad-payout-failed", counts.failed);
      set("ad-payout-refunded", counts.refunded);
      set("ad-payout-reversed", counts.reversed);
      const feesEl = document.getElementById("ad-payout-fees");
      if (feesEl) feesEl.textContent = `TZS ${new Intl.NumberFormat("en-US").format(Number(result.totalFees || 0))}`;
      const testBadge = document.getElementById("ad-test-mode-badge");
      if (testBadge) testBadge.hidden = !result.testMode;
    } catch (_) {
      // summary optional when DB/API unavailable
    }
  }

  async function openManualPayoutDialog() {
    const amountStr = window.prompt("Enter payout amount (TZS):", "10000");
    if (amountStr === null) return;
    const amount = Number(amountStr);
    if (!Number.isFinite(amount) || amount <= 0) {
      setBanner("ad-payouts-error", "Invalid amount.", "error");
      return;
    }
    const note = window.prompt("Optional internal note:", "") || "";
    try {
      const preview = await requestJson("preview-payout", { method: "POST", body: { amount, note } });
      const confirmMsg = [
        `Recipient: ${preview.recipientPhone || "+" + (latestSettings?.displayDestination || "255715296092")}`,
        preview.recipientName ? `Name: ${preview.recipientName}` : "",
        `Provider: ${preview.provider || "—"}`,
        `Amount: TZS ${Number(preview.amount || amount).toLocaleString()}`,
        `Fee: TZS ${Number(preview.fee || 0).toLocaleString()}`,
        `Total: TZS ${Number(preview.totalDeduction || amount).toLocaleString()}`,
        preview.testMode ? "TEST MODE — no real transfer" : "",
        "",
        "Confirm payout?",
      ].filter(Boolean).join("\n");
      if (!window.confirm(confirmMsg)) return;
      await requestJson("confirm-payout", {
        method: "POST",
        body: { orderReference: preview.orderReference, previewToken: preview.previewToken },
      });
      setBanner("ad-payouts-error", preview.testMode ? "TEST MODE payout recorded." : "Payout submitted.", "success", { toast: true });
      await loadPayouts();
      await loadPayoutSummary();
    } catch (error) {
      setBanner("ad-payouts-error", error.message, "error");
    }
  }

  async function loadPayouts() {
    const body = document.getElementById("ad-payouts-body");
    body.innerHTML = `<tr><td colspan="8">Loading...</td></tr>`;
    try {
      const [result] = await Promise.all([requestJson("payouts"), loadPayoutSummary()]);
      latestPayoutRows = result.items || [];
      if (result.summary?.counts) {
        const c = result.summary.counts;
        const set = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = String(val ?? "0"); };
        set("ad-payout-success", c.successful);
        set("ad-payout-pending", c.pending);
        set("ad-payout-failed", c.failed);
        set("ad-payout-refunded", c.refunded);
        set("ad-payout-reversed", c.reversed);
      }
      payoutsPage = 1;
      renderPayoutsTable();
      if (latestSettings?.warning) {
        setBanner("ad-payouts-error", latestSettings.warning, "warning");
      }
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
    syncPortalCards();
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

  function syncPortalCards() {
    const map = [
      ["stat-balance", "ad-portal-balance"],
      ["stat-balance-updated", "ad-portal-balance-updated"],
      ["stat-incoming", "ad-portal-incoming"],
      ["stat-incoming-period", "ad-portal-period"],
      ["stat-success", "ad-portal-success"],
      ["stat-pending", "ad-portal-pending"],
      ["stat-failed", "ad-portal-failed"],
      ["stat-dest", "ad-portal-dest"],
      ["stat-auto", "ad-portal-auto"],
      ["stat-auto-mode", "ad-portal-auto-mode"],
    ];
    map.forEach(([fromId, toId]) => {
      const from = document.getElementById(fromId);
      const to = document.getElementById(toId);
      if (from && to) {
        to.textContent = from.textContent || "";
        if (fromId === "stat-auto") {
          to.className = "ad-service-value " + (from.className || "");
        }
      }
    });
    const recentSub = document.getElementById("ad-portal-recent-sub");
    if (recentSub) {
      recentSub.textContent = `${latestRecentRows.length} record${latestRecentRows.length === 1 ? "" : "s"}`;
    }
    document.getElementById("ad-portal-recent").textContent = String(latestRecentRows.length);
    document.getElementById("ad-portal-controls").textContent = String(latestControlRows.length);
    document.getElementById("ad-portal-payouts").textContent = String(latestPayoutRows.length);
    document.getElementById("ad-portal-users").textContent = String(latestUserRows.length);
  }

  const PORTAL_SECTION_TITLES = {
    home: "Service catalogue",
    analytics: "Payment analysis",
    "control-number": "Create control number",
    transactions: "Transactions",
    "payout-dest": "Payout destination",
    payouts: "Automatic payouts",
    users: "Registered users",
    recent: "Recent collections",
    whatsapp: "WhatsApp",
  };

  function scrollToPortalSection(key) {
    const idMap = {
      analytics: "ad-section-analytics",
      "control-number": "ad-section-control-number",
      transactions: "ad-section-transactions",
      "payout-dest": "ad-section-payout-dest",
      payouts: "ad-section-payouts",
      users: "ad-section-users",
      recent: "ad-section-recent",
      whatsapp: "ad-section-whatsapp",
    };
    if (!idMap[key]) return;

    document.body.classList.add("ad-view-detail");
    document.body.classList.remove("ad-view-home");
    document.body.setAttribute("data-ad-section", key);

    const detailWrap = document.getElementById("ad-detail-sections");
    if (detailWrap) {
      detailWrap.classList.remove("is-collapsed");
      detailWrap.hidden = false;
    }

    try {
      const url = new URL(window.location.href);
      url.searchParams.set("section", key);
      window.history.replaceState({}, "", url.toString());
    } catch (_) {
      /* ignore */
    }

    const el = document.getElementById(idMap[key]);
    if (el) {
      window.setTimeout(() => {
        window.scrollTo({ top: 0, behavior: "smooth" });
        if (key === "analytics") {
          redrawChartsIfVisible();
        }
        if (key === "whatsapp") {
          loadWhatsappMessages(waCurrentStatus);
        }
      }, 80);
    }

    const titleEl = document.getElementById("ad-portal-title");
    if (titleEl && PORTAL_SECTION_TITLES[key]) {
      titleEl.textContent = PORTAL_SECTION_TITLES[key];
    }
    document.querySelectorAll(".ad-sidebar-link[data-ad-target]").forEach((btn) => {
      btn.classList.toggle("is-active", btn.dataset.adTarget === key);
    });
    document.querySelector(".ad-sidebar-catalogue")?.classList.toggle("is-active", false);
    closeSidebar();
  }

  function showPortalHome() {
    document.body.classList.remove("ad-view-detail");
    document.body.classList.add("ad-view-home");
    document.body.removeAttribute("data-ad-section");
    const detailWrap = document.getElementById("ad-detail-sections");
    if (detailWrap) {
      detailWrap.classList.add("is-collapsed");
      detailWrap.hidden = true;
    }
    try {
      const url = new URL(window.location.href);
      url.searchParams.delete("section");
      window.history.replaceState({}, "", url.toString());
    } catch (_) {
      /* ignore */
    }
    const home = document.getElementById("ad-view-home");
    if (home) home.scrollIntoView({ behavior: "smooth", block: "start" });
    const titleEl = document.getElementById("ad-portal-title");
    if (titleEl) titleEl.textContent = PORTAL_SECTION_TITLES.home;
    document.querySelector(".ad-sidebar-catalogue")?.classList.add("is-active");
    document.querySelectorAll(".ad-sidebar-link[data-ad-target]").forEach((btn) => btn.classList.remove("is-active"));
    closeSidebar();
  }

  function closeSidebar() {
    document.getElementById("ad-sidebar")?.classList.remove("is-open");
    const backdrop = document.getElementById("ad-sidebar-backdrop");
    if (backdrop) backdrop.hidden = true;
  }

  function openSidebar() {
    document.getElementById("ad-sidebar")?.classList.add("is-open");
    const backdrop = document.getElementById("ad-sidebar-backdrop");
    if (backdrop) backdrop.hidden = false;
  }

  const SIDEBAR_COLLAPSE_KEY = "gw_admin_sidebar_collapsed";

  function setSidebarCollapsed(collapsed) {
    document.body.classList.toggle("ad-sidebar-collapsed", collapsed);
    const btn = document.getElementById("ad-sidebar-minimize");
    if (btn) {
      btn.setAttribute("aria-label", collapsed ? "Expand sidebar" : "Minimize sidebar");
      btn.title = collapsed ? "Expand sidebar" : "Minimize sidebar";
    }
    try {
      localStorage.setItem(SIDEBAR_COLLAPSE_KEY, collapsed ? "1" : "0");
    } catch (_) {
      /* ignore */
    }
    window.setTimeout(() => {
      try {
        chartStore.trend?.resize?.();
      } catch (_) {
        /* ignore */
      }
    }, 220);
  }

  function bindSidebarMinimize() {
    const btn = document.getElementById("ad-sidebar-minimize");
    if (!btn) return;
    try {
      if (localStorage.getItem(SIDEBAR_COLLAPSE_KEY) === "1" && window.matchMedia("(min-width: 901px)").matches) {
        setSidebarCollapsed(true);
      }
    } catch (_) {
      /* ignore */
    }
    btn.addEventListener("click", () => {
      if (window.matchMedia("(max-width: 900px)").matches) {
        closeSidebar();
        return;
      }
      setSidebarCollapsed(!document.body.classList.contains("ad-sidebar-collapsed"));
    });
    window.matchMedia("(max-width: 900px)").addEventListener("change", (event) => {
      if (event.matches) {
        document.body.classList.remove("ad-sidebar-collapsed");
      } else if (localStorage.getItem(SIDEBAR_COLLAPSE_KEY) === "1") {
        setSidebarCollapsed(true);
      }
    });
  }

  function bindPortalNavigation() {
    document.querySelector(".ad-sidebar-catalogue")?.addEventListener("click", showPortalHome);
    document.querySelectorAll(".ad-sidebar-link[data-ad-target]").forEach((btn) => {
      btn.addEventListener("click", () => scrollToPortalSection(btn.dataset.adTarget || ""));
    });
    document.querySelectorAll(".ad-service-card[data-ad-target]").forEach((btn) => {
      btn.addEventListener("click", () => scrollToPortalSection(btn.dataset.adTarget || ""));
    });
    document.querySelectorAll(".ad-service-card[data-ad-action='sync']").forEach((btn) => {
      btn.addEventListener("click", () => syncTransactions());
    });
    document.getElementById("ad-portal-auto-card")?.addEventListener("click", () => toggleAutoPayout());
    document.getElementById("ad-menu-open")?.addEventListener("click", openSidebar);
    document.getElementById("ad-sidebar-close")?.addEventListener("click", closeSidebar);
    document.getElementById("ad-sidebar-backdrop")?.addEventListener("click", closeSidebar);
    bindSidebarMinimize();
  }

  let waCurrentStatus = "all";
  let waMode = "manual";
  let waAutoTimer = null;
  let waLastAutoSentTo = "";
  let waSending = false;
  let waPhoneList = [];
  let waHiddenIds = new Set();
  const WA_MODE_KEY = "gw_wa_send_mode";
  const WA_AUTO_BODY_KEY = "gw_wa_auto_body";
  const WA_HIDDEN_KEY = "gw_wa_hidden_ids";
  const WA_SCHEDULE_KEY = "gw_wa_schedule";

  function setWaMsg(text, isError) {
    const el = document.getElementById("ad-wa-msg");
    if (!el) return;
    el.textContent = text || "";
    el.classList.toggle("is-err", Boolean(isError));
    el.classList.toggle("is-ok", Boolean(text) && !isError);
  }

  function waSwalSent(title, text) {
    if (typeof Swal === "undefined") {
      setWaMsg(title || "Sent");
      return Promise.resolve();
    }
    return Swal.fire({
      title: title || "Sent",
      html: `<div style="display:grid;gap:10px;justify-items:center">
        <div style="width:72px;height:72px;border-radius:50%;background:#ecfdf5;display:grid;place-items:center;animation:wa-bounce 0.9s ease infinite alternate">
          <i class="fa-brands fa-whatsapp" style="font-size:2.2rem;color:#25d366"></i>
        </div>
        <p style="margin:0;color:#334155;font-weight:600">${esc(text || "Message updated successfully.")}</p>
      </div>
      <style>@keyframes wa-bounce{from{transform:scale(.92)}to{transform:scale(1.08)}}</style>`,
      confirmButtonText: "OK",
      confirmButtonColor: "#25d366",
      showClass: { popup: "swal2-show" },
      hideClass: { popup: "swal2-hide" },
    });
  }

  function loadWaHidden() {
    try {
      const raw = JSON.parse(localStorage.getItem(WA_HIDDEN_KEY) || "[]");
      waHiddenIds = new Set(Array.isArray(raw) ? raw.map(String) : []);
    } catch (_) {
      waHiddenIds = new Set();
    }
  }

  function saveWaHidden() {
    try {
      localStorage.setItem(WA_HIDDEN_KEY, JSON.stringify([...waHiddenIds]));
    } catch (_) {
      /* ignore */
    }
  }

  function delayMsFromUi() {
    const value = Math.max(0, Number(document.getElementById("ad-wa-delay-value")?.value || 0));
    const unit = document.getElementById("ad-wa-delay-unit")?.value || "minutes";
    if (unit === "days") return value * 24 * 60 * 60 * 1000;
    if (unit === "months") return value * 30 * 24 * 60 * 60 * 1000;
    return value * 60 * 1000;
  }

  function applyWaMode(mode) {
    waMode = mode === "auto" ? "auto" : "manual";
    document.querySelectorAll(".ad-wa-mode-btn").forEach((btn) => {
      btn.classList.toggle("is-active", btn.dataset.waMode === waMode);
    });
    const bodyWrap = document.getElementById("ad-wa-body-wrap");
    const autoWrap = document.getElementById("ad-wa-auto-wrap");
    const schedule = document.getElementById("ad-wa-schedule");
    const sendBtn = document.getElementById("ad-wa-send");
    const bodyInput = document.getElementById("ad-wa-body");
    if (bodyWrap) bodyWrap.hidden = waMode === "auto";
    if (autoWrap) autoWrap.hidden = waMode !== "auto";
    if (schedule) schedule.hidden = waMode !== "auto";
    if (bodyInput) bodyInput.required = waMode !== "auto";
    if (sendBtn) {
      sendBtn.innerHTML = waMode === "auto"
        ? '<i class="fa-brands fa-whatsapp"></i> Schedule / Send'
        : '<i class="fa-brands fa-whatsapp"></i> Send';
    }
    try {
      localStorage.setItem(WA_MODE_KEY, waMode);
    } catch (_) {
      /* ignore */
    }
  }

  function normalizeWaPhone(value) {
    return String(value || "").replace(/\D+/g, "");
  }

  function renderWaPhoneChips() {
    const wrap = document.getElementById("ad-wa-phone-chips");
    if (!wrap) return;
    if (!waPhoneList.length) {
      wrap.hidden = true;
      wrap.innerHTML = "";
      return;
    }
    wrap.hidden = false;
    wrap.innerHTML = waPhoneList.map((phone, index) => `
      <span class="ad-wa-chip">
        <i class="fa-brands fa-whatsapp"></i>${esc(phone)}
        <button type="button" data-wa-chip-remove="${index}" aria-label="Remove">&times;</button>
      </span>
    `).join("");
    wrap.querySelectorAll("[data-wa-chip-remove]").forEach((btn) => {
      btn.addEventListener("click", () => {
        const idx = Number(btn.getAttribute("data-wa-chip-remove"));
        waPhoneList.splice(idx, 1);
        renderWaPhoneChips();
      });
    });
  }

  function collectWaTargets() {
    const single = normalizeWaPhone(document.getElementById("ad-wa-to")?.value);
    const list = [...waPhoneList];
    if (single && !list.includes(single)) list.unshift(single);
    return [...new Set(list.filter((p) => p.length >= 9))];
  }

  function formatWaWhen(when) {
    if (when == null || when === "") return "";
    if (typeof when === "number" && when > 1000000000) {
      return new Date(when * (when < 1e12 ? 1000 : 1)).toLocaleString();
    }
    return String(when);
  }

  function renderWaMessages(messages) {
    const list = document.getElementById("ad-wa-list");
    if (!list) return;
    const visible = (messages || []).filter((m) => {
      const id = String(m.id || m.messageId || m.msgId || "");
      return !id || !waHiddenIds.has(id);
    });
    if (!visible.length) {
      list.innerHTML = '<li class="ad-wa-empty">No messages</li>';
      return;
    }
    list.innerHTML = visible.map((m) => {
      const rawTo = String(m.to || m.chatId || m.from || m.id || "—");
      const phone = normalizeWaPhone(rawTo) || rawTo;
      const body = m.body || m.message || m.text || m.caption || "";
      const st = String(m.status || m.ack || m.state || waCurrentStatus || "all").toLowerCase();
      const when = formatWaWhen(m.timestamp || m.time || m.created || m.date || m.sent_at || "");
      const id = String(m.id || m.messageId || m.msgId || "");
      const payload = encodeURIComponent(JSON.stringify({
        id,
        to: phone,
        body,
        status: st,
        when,
      }));
      return `<li class="ad-wa-item">
        <div class="ad-wa-item-top">
          <span class="ad-wa-phone"><i class="fa-brands fa-whatsapp"></i>${esc(phone)}</span>
          <span class="ad-wa-status is-${esc(st)}">${esc(st)}</span>
        </div>
        <p class="ad-wa-item-body">${esc(body)}</p>
        <p class="ad-wa-item-meta">${id ? "ID: " + esc(id) : ""}${when ? (id ? " · " : "") + esc(when) : ""}</p>
        <div class="ad-wa-item-actions">
          <button type="button" class="ad-btn ad-btn--view" data-wa-view="${payload}"><i class="fa-solid fa-eye"></i><span>View</span></button>
          <button type="button" class="ad-btn ad-btn--delete" data-wa-delete="${payload}"><i class="fa-solid fa-trash"></i><span>Delete</span></button>
        </div>
      </li>`;
    }).join("");

    list.querySelectorAll("[data-wa-view]").forEach((btn) => {
      btn.addEventListener("click", () => {
        let row = null;
        try {
          row = JSON.parse(decodeURIComponent(btn.getAttribute("data-wa-view") || ""));
        } catch (_) {
          row = null;
        }
        if (!row) return;
        if (typeof Swal === "undefined") {
          window.alert(`${row.to}\n\n${row.body}`);
          return;
        }
        Swal.fire({
          title: `<span style="display:inline-flex;align-items:center;gap:8px"><i class="fa-brands fa-whatsapp" style="color:#25d366"></i>${esc(row.to || "")}</span>`,
          html: `<p style="text-align:left;white-space:pre-wrap;color:#334155;font-weight:600">${esc(row.body || "")}</p>
                 <p style="margin:10px 0 0;color:#94a3b8;font-size:.8rem">${esc(row.status || "")}${row.when ? " · " + esc(row.when) : ""}</p>`,
          confirmButtonColor: "#25d366",
          confirmButtonText: "Close",
        });
      });
    });

    list.querySelectorAll("[data-wa-delete]").forEach((btn) => {
      btn.addEventListener("click", async () => {
        let row = null;
        try {
          row = JSON.parse(decodeURIComponent(btn.getAttribute("data-wa-delete") || ""));
        } catch (_) {
          row = null;
        }
        if (!row) return;
        const confirm = typeof Swal !== "undefined"
          ? await Swal.fire({
              title: "Delete message?",
              text: row.to || "",
              icon: "warning",
              showCancelButton: true,
              confirmButtonColor: "#dc2626",
              cancelButtonColor: "#64748b",
              confirmButtonText: "Delete",
            })
          : { isConfirmed: window.confirm("Delete this message?") };
        if (!confirm.isConfirmed) return;
        if (row.id) {
          waHiddenIds.add(String(row.id));
          saveWaHidden();
          try {
            await fetch("whatsapp-api.php?action=delete", {
              method: "POST",
              credentials: "same-origin",
              headers: { "Content-Type": "application/json" },
              body: JSON.stringify({ id: row.id }),
            });
          } catch (_) {
            /* local hide still applied */
          }
        }
        btn.closest("li")?.remove();
        await waSwalSent("Sent", "Message removed from recent list.");
        if (!document.querySelector("#ad-wa-list .ad-wa-item")) {
          list.innerHTML = '<li class="ad-wa-empty">No messages</li>';
        }
      });
    });
  }

  async function loadWhatsappMessages(status) {
    const list = document.getElementById("ad-wa-list");
    if (!list) return;
    waCurrentStatus = status || waCurrentStatus || "all";
    list.innerHTML = '<li class="ad-wa-empty">Loading…</li>';
    try {
      const res = await fetch(
        `whatsapp-api.php?action=messages&status=${encodeURIComponent(waCurrentStatus)}&limit=50&sort=desc`,
        { credentials: "same-origin", cache: "no-store" }
      );
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data.ok) {
        const detail = data.message || "Failed to load messages";
        const http = data.http ? ` (HTTP ${data.http})` : "";
        list.innerHTML = `<li class="ad-wa-empty">${esc(detail)}${esc(http)}</li>`;
        return;
      }
      renderWaMessages(data.messages || []);
    } catch (error) {
      list.innerHTML = `<li class="ad-wa-empty">${esc(error.message || error)}</li>`;
    }
  }

  async function sendWhatsappMessage({ to, body, priority }) {
    if (waSending) return null;
    waSending = true;
    setWaMsg("Sending…");
    try {
      const res = await fetch("whatsapp-api.php?action=send", {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ to, body, priority }),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data.ok) {
        throw new Error(data.message || "Send failed");
      }
      setWaMsg(`Sent to ${data.to || to}`);
      loadWhatsappMessages(waCurrentStatus);
      return data;
    } catch (error) {
      setWaMsg(error.message || String(error), true);
      throw error;
    } finally {
      waSending = false;
    }
  }

  function readWaSchedule() {
    try {
      const raw = JSON.parse(localStorage.getItem(WA_SCHEDULE_KEY) || "[]");
      return Array.isArray(raw) ? raw : [];
    } catch (_) {
      return [];
    }
  }

  function writeWaSchedule(items) {
    try {
      localStorage.setItem(WA_SCHEDULE_KEY, JSON.stringify(items));
    } catch (_) {
      /* ignore */
    }
  }

  function queueWaSchedule(entry) {
    const items = readWaSchedule();
    items.push(entry);
    writeWaSchedule(items);
  }

  async function processWaSchedule() {
    const now = Date.now();
    const items = readWaSchedule();
    if (!items.length) return;
    const keep = [];
    for (const item of items) {
      if (!item || !item.at || item.at > now) {
        keep.push(item);
        continue;
      }
      try {
        await sendWhatsappMessage({
          to: item.to,
          body: item.body,
          priority: item.priority || "10",
        });
        await waSwalSent("Sent", `Scheduled message sent to ${item.to}`);
      } catch (_) {
        keep.push({ ...item, at: now + 60000 });
      }
    }
    writeWaSchedule(keep);
  }

  function getAutoMessageBody() {
    return String(document.getElementById("ad-wa-auto-body")?.value || "").trim();
  }

  function saveAutoMessageBody() {
    const el = document.getElementById("ad-wa-auto-body");
    if (!el) return;
    try {
      localStorage.setItem(WA_AUTO_BODY_KEY, el.value || "");
    } catch (_) {
      /* ignore */
    }
  }

  function scheduleAutoSend() {
    if (waMode !== "auto") return;
    window.clearTimeout(waAutoTimer);
    const delay = delayMsFromUi();
    waAutoTimer = window.setTimeout(async () => {
      const targets = collectWaTargets();
      const body = getAutoMessageBody();
      const priority = document.getElementById("ad-wa-priority")?.value || "10";
      if (!targets.length) return;
      if (!body) {
        setWaMsg("Andika ujumbe wa automatic kwanza (unaweza kuandika ujumbe wowote).", true);
        return;
      }
      const key = targets.join(",");
      if (key === waLastAutoSentTo) return;
      try {
        saveAutoMessageBody();
        if (delay > 0) {
          const at = Date.now() + delay;
          targets.forEach((to) => queueWaSchedule({ to, body, priority, at }));
          waLastAutoSentTo = key;
          setWaMsg(`Scheduled ${targets.length} message(s).`);
          await waSwalSent("Sent", "Messages scheduled for later.");
          return;
        }
        for (const to of targets) {
          await sendWhatsappMessage({ to, body, priority });
        }
        waLastAutoSentTo = key;
        await waSwalSent("Sent", `Sent to ${targets.length} number(s).`);
      } catch (_) {
        /* message already shown */
      }
    }, delay > 0 ? 400 : 900);
  }

  function parsePhonesFromSheet(workbook) {
    const phones = [];
    const sheetName = workbook.SheetNames[0];
    const sheet = workbook.Sheets[sheetName];

    // Prefer objects with a phone/simu/mobile column header.
    const objects = window.XLSX.utils.sheet_to_json(sheet, { defval: "", raw: false });
    if (objects.length) {
      objects.forEach((row) => {
        if (!row || typeof row !== "object") return;
        const keys = Object.keys(row);
        const phoneKey = keys.find((k) => /^(phone|simu|mobile|msisdn|number|namba)$/i.test(String(k).trim()))
          || keys.find((k) => /phone|simu|mobile|msisdn|namba/i.test(String(k)));
        if (phoneKey) {
          const phone = normalizeWaPhone(row[phoneKey]);
          if (phone.length >= 9) phones.push(phone);
          return;
        }
        keys.forEach((k) => {
          const phone = normalizeWaPhone(row[k]);
          if (phone.length >= 9) phones.push(phone);
        });
      });
    }

    if (!phones.length) {
      const rows = window.XLSX.utils.sheet_to_json(sheet, { header: 1, raw: false });
      rows.forEach((row) => {
        (Array.isArray(row) ? row : []).forEach((cell) => {
          const phone = normalizeWaPhone(cell);
          if (phone.length >= 9) phones.push(phone);
        });
      });
    }

    return [...new Set(phones)];
  }

  function downloadWaExcelSample() {
    if (typeof window.XLSX === "undefined") {
      setWaMsg("Excel library failed to load.", true);
      return;
    }
    const rows = [
      { phone: "255715296092" },
      { phone: "255716260292" },
      { phone: "2557XXXXXXXX" },
    ];
    const sheet = window.XLSX.utils.json_to_sheet(rows);
    const book = window.XLSX.utils.book_new();
    window.XLSX.utils.book_append_sheet(book, sheet, "phones");
    window.XLSX.writeFile(book, "getway-whatsapp-phones-sample.xlsx");
    setWaMsg("Sample Excel downloaded. Fill column phone then upload.");
  }

  function bindWhatsappSection() {
    if (!document.getElementById("ad-section-whatsapp")) return;
    loadWaHidden();

    try {
      const savedMode = localStorage.getItem(WA_MODE_KEY);
      const autoEl = document.getElementById("ad-wa-auto-body");
      if (autoEl) {
        // Keep whatever the user saved — even empty. Do not force the sample text.
        const savedBody = localStorage.getItem(WA_AUTO_BODY_KEY);
        autoEl.value = savedBody !== null ? savedBody : "";
      }
      applyWaMode(savedMode === "auto" ? "auto" : "manual");
    } catch (_) {
      applyWaMode("manual");
    }

    document.querySelectorAll(".ad-wa-mode-btn").forEach((btn) => {
      btn.addEventListener("click", () => applyWaMode(btn.dataset.waMode || "manual"));
    });

    document.querySelectorAll(".ad-wa-tab").forEach((btn) => {
      btn.addEventListener("click", () => {
        document.querySelectorAll(".ad-wa-tab").forEach((b) => b.classList.remove("is-active"));
        btn.classList.add("is-active");
        loadWhatsappMessages(btn.dataset.waStatus || "all");
      });
    });

    document.getElementById("ad-wa-refresh")?.addEventListener("click", () => {
      loadWhatsappMessages(waCurrentStatus);
      processWaSchedule();
    });

    document.getElementById("ad-wa-to")?.addEventListener("input", () => {
      const to = normalizeWaPhone(document.getElementById("ad-wa-to")?.value);
      if (to !== waLastAutoSentTo) waLastAutoSentTo = "";
      if (waMode === "auto") scheduleAutoSend();
    });

    document.getElementById("ad-wa-delay-value")?.addEventListener("change", () => {
      if (waMode === "auto") {
        waLastAutoSentTo = "";
        scheduleAutoSend();
      }
    });
    document.getElementById("ad-wa-delay-unit")?.addEventListener("change", () => {
      if (waMode === "auto") {
        waLastAutoSentTo = "";
        scheduleAutoSend();
      }
    });

    document.getElementById("ad-wa-auto-body")?.addEventListener("input", () => {
      saveAutoMessageBody();
      waLastAutoSentTo = "";
    });
    document.getElementById("ad-wa-auto-body")?.addEventListener("change", saveAutoMessageBody);
    document.getElementById("ad-wa-auto-body")?.addEventListener("blur", saveAutoMessageBody);

    document.getElementById("ad-wa-excel-sample")?.addEventListener("click", downloadWaExcelSample);

    document.getElementById("ad-wa-excel")?.addEventListener("change", async (event) => {
      const file = event.target.files?.[0];
      if (!file) return;
      if (typeof window.XLSX === "undefined") {
        setWaMsg("Excel library failed to load.", true);
        return;
      }
      try {
        const buffer = await file.arrayBuffer();
        const workbook = window.XLSX.read(buffer, { type: "array" });
        const phones = parsePhonesFromSheet(workbook);
        if (!phones.length) {
          setWaMsg("No phone numbers found in the file.", true);
          return;
        }
        waPhoneList = phones;
        renderWaPhoneChips();
        const first = phones[0];
        const input = document.getElementById("ad-wa-to");
        if (input && first) input.value = first;
        setWaMsg(`Loaded ${phones.length} number(s) from Excel.`);
        if (waMode === "auto") {
          waLastAutoSentTo = "";
          scheduleAutoSend();
        }
      } catch (error) {
        setWaMsg(error.message || "Could not read Excel file.", true);
      } finally {
        event.target.value = "";
      }
    });

    document.getElementById("ad-wa-form")?.addEventListener("submit", async (event) => {
      event.preventDefault();
      const targets = collectWaTargets();
      const priority = document.getElementById("ad-wa-priority")?.value || "10";
      const body = waMode === "auto"
        ? getAutoMessageBody()
        : String(document.getElementById("ad-wa-body")?.value || "").trim();
      if (!targets.length) {
        setWaMsg("Enter a phone number or upload Excel.", true);
        return;
      }
      if (!body) {
        setWaMsg(waMode === "auto"
          ? "Andika ujumbe wa automatic kwanza (unaweza kuandika ujumbe wowote)."
          : "Message is required.", true);
        return;
      }
      try {
        if (waMode === "auto") saveAutoMessageBody();
        if (waMode === "auto") {
          const delay = delayMsFromUi();
          if (delay > 0) {
            const at = Date.now() + delay;
            targets.forEach((to) => queueWaSchedule({ to, body, priority, at }));
            setWaMsg(`Scheduled ${targets.length} message(s).`);
            await waSwalSent("Sent", "Messages scheduled.");
            return;
          }
        }
        for (const to of targets) {
          await sendWhatsappMessage({ to, body, priority });
        }
        if (waMode === "auto") waLastAutoSentTo = targets.join(",");
        await waSwalSent("Sent", `Sent to ${targets.length} number(s).`);
      } catch (_) {
        /* shown */
      }
    });

    document.getElementById("ad-wa-status")?.addEventListener("click", async () => {
      setWaMsg("Checking…");
      try {
        const res = await fetch("whatsapp-api.php?action=status", { credentials: "same-origin" });
        const data = await res.json().catch(() => ({}));
        setWaMsg(data.ok ? "Instance online." : (data.message || "Status failed"), !data.ok);
      } catch (error) {
        setWaMsg(error.message || String(error), true);
      }
    });

    document.getElementById("ad-wa-copy-hook")?.addEventListener("click", async () => {
      const url = document.getElementById("ad-wa-webhook")?.textContent?.trim() || "";
      try {
        await navigator.clipboard?.writeText(url);
        setWaMsg("Webhook copied.");
      } catch (_) {
        setWaMsg(url);
      }
    });

    processWaSchedule();
    window.setInterval(processWaSchedule, 30000);
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
      whatsapp: "ad-section-whatsapp",
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
      if (!sectionMap[key]) return;
      closeOverlay();
      if (key === "autopay") {
        toggleAutoPayout();
        return;
      }
      window.setTimeout(() => {
        scrollToPortalSection(key);
        const id = sectionMap[key];
        const el = document.getElementById(id);
        if (el) {
          el.classList.add("ad-ga-highlight");
          window.setTimeout(() => el.classList.remove("ad-ga-highlight"), 1400);
        }
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
  document.getElementById("ad-manual-payout-open")?.addEventListener("click", () => openManualPayoutDialog());
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
  document.getElementById("ad-payouts-export")?.addEventListener("click", exportPayoutCsv);
  bindGeneralAnalysis();
  bindPortalNavigation();
  bindWhatsappSection();
  document.body.classList.add("ad-view-home");
  const detailOnLoad = document.getElementById("ad-detail-sections");
  if (detailOnLoad) {
    detailOnLoad.classList.add("is-collapsed");
    detailOnLoad.hidden = true;
  }

  loadAll().catch((error) => {
    setBanner("ad-db-banner", error.message, "error", { toast: true });
  }).finally(() => {
    const section = new URLSearchParams(window.location.search).get("section")
      || (window.location.hash || "").replace(/^#/, "");
    if (section && PORTAL_SECTION_TITLES[section] && section !== "home") {
      scrollToPortalSection(section);
    }
  });
  window.setInterval(loadBalance, REFRESH_MS);
})();
