(function () {
  const API = "admin-api.php";
  const REFRESH_MS = 60000;
  const tableUi = {
    controls: { page: 1, perPage: 10, search: "" },
    payouts: { page: 1, perPage: 10, search: "" },
    users: { page: 1, perPage: 10, search: "" },
    recent: { page: 1, perPage: 10, search: "" },
  };
  let latestPayoutRows = [];
  let latestControlRows = [];
  let latestAllControlRows = [];
  let latestUserRows = [];
  let latestRecentRows = [];
  let latestSettings = null;
  let analyticsPeriod = "all";
  let txStatusFilter = "ALL";
  let latestPaymentRows = [];

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

  function filterTableRows(rows, searchText, fieldsFn) {
    const list = Array.isArray(rows) ? rows : [];
    const q = String(searchText || "").trim().toLowerCase();
    if (!q) return list;
    return list.filter((row) => {
      try {
        return String(fieldsFn(row) || "").toLowerCase().includes(q);
      } catch (_) {
        return false;
      }
    });
  }

  function paginateTableRows(rows, state) {
    const list = Array.isArray(rows) ? rows : [];
    const perPage = Math.max(1, Number(state.perPage) || 10);
    const total = list.length;
    const totalPages = Math.max(1, Math.ceil(total / perPage) || 1);
    state.page = Math.min(Math.max(1, Number(state.page) || 1), totalPages);
    const start = (state.page - 1) * perPage;
    const end = Math.min(start + perPage, total);
    return {
      slice: list.slice(start, end),
      start,
      end,
      total,
      totalPages,
      perPage,
    };
  }

  function updateTableInfo(infoId, start, end, total) {
    const el = document.getElementById(infoId);
    if (!el) return;
    if (!total) {
      el.textContent = "Showing 0 to 0 of 0 entries";
      return;
    }
    el.textContent = `Showing ${start + 1} to ${end} of ${total} entries`;
  }

  function renderTablePagination(containerId, page, totalPages, onPage) {
    const el = document.getElementById(containerId);
    if (!el) return;
    el.innerHTML = "";
    const pages = Math.max(1, Number(totalPages) || 1);
    const current = Math.min(Math.max(1, Number(page) || 1), pages);

    const prev = document.createElement("button");
    prev.type = "button";
    prev.textContent = "Previous";
    prev.disabled = current <= 1;
    prev.addEventListener("click", () => {
      if (current > 1) onPage(current - 1);
    });
    el.appendChild(prev);

    const maxButtons = 7;
    let from = Math.max(1, current - Math.floor(maxButtons / 2));
    let to = Math.min(pages, from + maxButtons - 1);
    from = Math.max(1, to - maxButtons + 1);
    for (let n = from; n <= to; n += 1) {
      const btn = document.createElement("button");
      btn.type = "button";
      btn.textContent = String(n);
      if (n === current) btn.classList.add("active");
      btn.addEventListener("click", () => onPage(n));
      el.appendChild(btn);
    }

    const next = document.createElement("button");
    next.type = "button";
    next.textContent = "Next";
    next.disabled = current >= pages;
    next.addEventListener("click", () => {
      if (current < pages) onPage(current + 1);
    });
    el.appendChild(next);
  }

  function bindDataTableControls(key, renderFn) {
    const entries = document.getElementById(`ad-${key}-entries`);
    const search = document.getElementById(`ad-${key}-search`);
    entries?.addEventListener("change", () => {
      tableUi[key].perPage = Number(entries.value) || 10;
      tableUi[key].page = 1;
      renderFn();
    });
    search?.addEventListener("input", () => {
      tableUi[key].search = search.value || "";
      tableUi[key].page = 1;
      renderFn();
    });
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
        width: "340px",
        customClass: {
          popup: "acs-swal-toast",
          title: "acs-swal-toast-title",
        },
      });
      return;
    }

    window.Swal.fire({
      icon,
      title: type === "error" ? "Application Failed" : type === "warning" ? "Warning" : "Notice",
      text,
      confirmButtonText: "OK",
      confirmButtonColor: "#1a3352",
      buttonsStyling: true,
      width: 360,
    });
  }

  async function confirmAction(options = {}) {
    const title = String(options.title || "Are you sure?");
    const text = String(options.text || "");
    const html = options.html ? String(options.html) : "";
    const confirmButtonText = String(options.confirmButtonText || "OK");
    const cancelButtonText = String(options.cancelButtonText || "Cancel");
    const icon = options.icon || "warning";
    const confirmButtonColor = options.confirmButtonColor || "#dc2626";

    if (!window.Swal || typeof window.Swal.fire !== "function") {
      return window.confirm(text || title);
    }

    const result = await window.Swal.fire({
      icon,
      title,
      text: html ? undefined : text,
      html: html || undefined,
      showCancelButton: true,
      focusCancel: true,
      confirmButtonText,
      cancelButtonText,
      confirmButtonColor,
      cancelButtonColor: "#64748b",
      buttonsStyling: true,
      reverseButtons: true,
      width: 420,
    });
    return Boolean(result.isConfirmed);
  }

  function renderPager(pagerId, page, totalItems, onPage, pageSize = 10) {
    const pager = document.getElementById(pagerId);
    if (!pager) return;
    const size = Math.max(1, Number(pageSize) || 10);
    const totalPages = Math.max(1, Math.ceil(totalItems / size));
    const current = Math.min(Math.max(1, page), totalPages);
    if (totalItems <= size) {
      pager.hidden = true;
      pager.innerHTML = "";
      return;
    }
    pager.hidden = false;
    pager.innerHTML = `
      <button type="button" class="ad-btn ad-btn--ghost ad-pager-btn" data-page="prev" ${current <= 1 ? "disabled" : ""}><i class="fa-solid fa-chevron-left"></i><span>Previous</span></button>
      <span class="ad-pager-info">Page ${current} of ${totalPages}</span>
      <button type="button" class="ad-btn ad-btn--ghost ad-pager-btn" data-page="next" ${current >= totalPages ? "disabled" : ""}><span>Next</span><i class="fa-solid fa-chevron-right"></i></button>`;
    pager.querySelector('[data-page="prev"]')?.addEventListener("click", () => onPage(current - 1));
    pager.querySelector('[data-page="next"]')?.addEventListener("click", () => onPage(current + 1));
  }

  function payoutBadge(status) {
    const s = String(status || "—").trim();
    const upper = s.toUpperCase();
    if (upper === "WITHDRAWN" || upper === "SUCCESS" || upper === "COMPLETED" || upper === "SETTLED") {
      return `<span class="ad-payout-pill ad-payout-pill--ok"><i class="fa-solid fa-circle-check"></i> Paid out</span>`;
    }
    if (upper.includes("PROCESS") || upper.includes("QUEUE") || upper.includes("PENDING")) {
      return `<span class="ad-payout-pill ad-payout-pill--wait"><i class="fa-solid fa-clock"></i> ${esc(s)}</span>`;
    }
    if (upper.includes("FAIL")) {
      return `<span class="ad-payout-pill ad-payout-pill--fail"><i class="fa-solid fa-circle-xmark"></i> ${esc(s)}</span>`;
    }
    if (upper === "—" || upper === "NOT WITHDRAWN" || upper === "") {
      return `<span class="ad-payout-pill ad-payout-pill--none">Not paid out</span>`;
    }
    return `<span class="ad-payout-pill">${esc(s)}</span>`;
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
      confirmButtonText: "OK",
      confirmButtonColor: "#1a3352",
      cancelButtonText: "Cancel",
      buttonsStyling: true,
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
      confirmButtonText: "OK",
      confirmButtonColor: "#1a3352",
      buttonsStyling: true,
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
      timer: undefined,
      customClass: { popup: "ad-crm-swal-square" },
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
      title: title || "Application Failed",
      text,
      confirmButtonText: "OK",
      confirmButtonColor: "#1a3352",
      buttonsStyling: true,
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
      confirmButtonText: "OK",
      confirmButtonColor: "#1a3352",
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
      const raw = String(error?.message || error || "");
      if (/Failed to fetch|NetworkError|Connection lost|Load failed|aborted|network/i.test(raw)) {
        throw new Error(
          "Muunganisho umekatika wakati wa ku-save (database/internet). Jaribu tena baada ya sekunde chache."
        );
      }
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

  function waDonutSlicePath(cx, cy, outerR, innerR, startAngle, endAngle) {
    const outerStart = waPolar(cx, cy, outerR, startAngle);
    const outerEnd = waPolar(cx, cy, outerR, endAngle);
    const innerEnd = waPolar(cx, cy, innerR, endAngle);
    const innerStart = waPolar(cx, cy, innerR, startAngle);
    const largeArc = endAngle - startAngle <= 180 ? 0 : 1;
    return [
      `M ${outerStart.x} ${outerStart.y}`,
      `A ${outerR} ${outerR} 0 ${largeArc} 1 ${outerEnd.x} ${outerEnd.y}`,
      `L ${innerEnd.x} ${innerEnd.y}`,
      `A ${innerR} ${innerR} 0 ${largeArc} 0 ${innerStart.x} ${innerStart.y}`,
      "Z",
    ].join(" ");
  }

  function localDateKey(value) {
    const dt = value instanceof Date ? value : new Date(value);
    if (Number.isNaN(dt.getTime())) return "";
    const y = dt.getFullYear();
    const m = String(dt.getMonth() + 1).padStart(2, "0");
    const d = String(dt.getDate()).padStart(2, "0");
    return `${y}-${m}-${d}`;
  }

  function normalizePieCounts(pie) {
    const data = pie || {};
    let success = Number(data.success || 0);
    let pending = Number(data.pending || 0);
    // Count only — never treat monetary failedSales as a slice.
    let failed = Number(data.failed || 0);
    if (success + pending + failed > 0) {
      return { success, pending, failed };
    }

    const rows = [
      ...(Array.isArray(data.payments) ? data.payments : []),
      ...(Array.isArray(data.recentCollections) ? data.recentCollections : []),
    ];
    success = 0;
    pending = 0;
    failed = 0;
    const seen = new Set();
    rows.forEach((row) => {
      if (!row || typeof row !== "object") return;
      const key = String(row.orderReference || row.id || `${row.status}-${row.createdAt}-${row.amount}`);
      if (seen.has(key)) return;
      seen.add(key);
      const st = String(row.status || "").toUpperCase();
      if (["SUCCESS", "SUCCESSFUL", "COMPLETED", "PAID", "SETTLED"].includes(st)) success += 1;
      else if (["FAILED", "FAILURE", "DECLINED", "CANCELLED", "EXPIRED"].includes(st)) failed += 1;
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
      { label: "Success", count: success, color: "#2D9CDB" },
      { label: "Pending", count: pending, color: "#F2C94C" },
      { label: "Failed", count: failed, color: "#EB5757" },
    ].filter((s) => s.count > 0);

    const W = 560;
    const H = 420;
    const cx = 280;
    const cy = 210;
    const outerR = 148;
    const innerR = 78;
    const labelR = 178;
    const gapDeg = slices.length > 1 ? 2.2 : 0;
    let angle = 0;
    const parts = [];
    const midLabelR = (outerR + innerR) / 2;

    slices.forEach((slice) => {
      const sweep = (slice.count / total) * 360;
      const usable = Math.max(sweep - gapDeg, 0.01);
      const start = angle + gapDeg / 2;
      const end = start + Math.min(usable, 359.999);
      const mid = (start + end) / 2;
      const pct = Math.round((slice.count / total) * 100);

      if (slices.length === 1) {
        parts.push(`<circle cx="${cx}" cy="${cy}" r="${outerR}" fill="${slice.color}"/>`);
        parts.push(`<circle cx="${cx}" cy="${cy}" r="${innerR}" fill="#ffffff"/>`);
      } else {
        parts.push(
          `<path d="${waDonutSlicePath(cx, cy, outerR, innerR, start, end)}" fill="${slice.color}" stroke="#ffffff" stroke-width="3"/>`
        );
      }

      if (pct >= 6) {
        const pctPt = waPolar(cx, cy, midLabelR, mid);
        parts.push(
          `<text class="ad-pie-pct" x="${pctPt.x}" y="${pctPt.y}" text-anchor="middle" dominant-baseline="middle" font-size="15" font-weight="800" fill="#ffffff">${pct}%</text>`
        );
      }

      const rim = waPolar(cx, cy, outerR + 2, mid);
      const elbow = waPolar(cx, cy, labelR, mid);
      const right = elbow.x >= cx;
      const labelX = right ? elbow.x + 18 : elbow.x - 18;
      const labelY = elbow.y;
      parts.push(
        `<polyline class="ad-pie-leader" points="${rim.x},${rim.y} ${elbow.x},${elbow.y} ${labelX},${labelY}" fill="none" stroke="${slice.color}" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>`
      );
      parts.push(
        `<circle cx="${rim.x}" cy="${rim.y}" r="2.4" fill="${slice.color}"/>`
      );
      parts.push(
        `<text class="ad-pie-label" x="${labelX + (right ? 4 : -4)}" y="${labelY - 7}" text-anchor="${right ? "start" : "end"}" font-size="14" font-weight="700" fill="#0f172a">${esc(slice.label)}</text>`
      );
      parts.push(
        `<text class="ad-pie-sub" x="${labelX + (right ? 4 : -4)}" y="${labelY + 12}" text-anchor="${right ? "start" : "end"}" font-size="12" font-weight="600" fill="#64748b">${slice.count} · ${pct}%</text>`
      );

      angle += sweep;
    });

    parts.push(`<text x="${cx}" y="${cy - 10}" text-anchor="middle" font-size="12" font-weight="700" fill="#94a3b8">TOTAL</text>`);
    parts.push(`<text x="${cx}" y="${cy + 16}" text-anchor="middle" font-size="30" font-weight="800" fill="#002d58">${total}</text>`);

    el.innerHTML = `
      <div class="ad-pie-visual ad-pie-visual--labeled">
        <svg class="ad-pie-svg" viewBox="0 0 ${W} ${H}" role="img" aria-label="Payment status pie chart">
          ${parts.join("")}
        </svg>
      </div>`;
  }

  function drawTrend(el, days) {
    if (!el) return;
    const list = Array.isArray(days) ? days : [];
    const totalHits = list.reduce((sum, d) => sum + Number(d.count || 0), 0);
    destroyChart("trend");
    if (!totalHits) {
      el.innerHTML = '<p class="ad-trend-empty">No payment activity in this date range yet.</p>';
      return;
    }
    if (typeof ApexCharts === "undefined") {
      el.innerHTML = '<p class="ad-trend-empty">Chart library failed to load.</p>';
      return;
    }

    const useAmount = list.some((d) => Number(d.amount || 0) > 0);

    // Build continuous datetime series like the ApexCharts zoomable timeseries demo.
    // Cumulative totals avoid "needle spikes" to zero that hide the area gradient.
    let running = 0;
    const dates = [];
    const dayValues = [];
    list.forEach((d) => {
      const key = String(d.date || "");
      const ts = key ? new Date(`${key}T12:00:00`).getTime() : NaN;
      if (!Number.isFinite(ts)) return;
      const dayVal = useAmount ? Number(d.amount || 0) : Number(d.count || 0);
      running += dayVal;
      dates.push([ts, running]);
      dayValues.push({ ts, dayVal, total: running, label: d.label || key });
    });

    if (!dates.length) {
      el.innerHTML = '<p class="ad-trend-empty">No payment activity in this date range yet.</p>';
      return;
    }

    el.innerHTML = "";
    el.classList.add("ad-trend--apex");

    // Exact structure from ApexCharts "Zoomable Timeseries" demo (adapted for payments)
    const options = {
      series: [
        {
          name: useAmount ? "Total collections" : "Total transactions",
          data: dates,
        },
      ],
      chart: {
        type: "area",
        stacked: false,
        height: 350,
        id: "ad-payment-movement",
        fontFamily: "Helvetica, Arial, sans-serif",
        zoom: {
          type: "x",
          enabled: true,
          autoScaleYaxis: true,
          allowMouseWheelZoom: false,
        },
        toolbar: {
          show: true,
          autoSelected: "zoom",
          tools: {
            download: true,
            selection: true,
            zoom: true,
            zoomin: true,
            zoomout: true,
            pan: true,
            reset: true,
          },
        },
        events: {
          mounted(chartContext) {
            const node = chartContext?.el || el;
            const blockWheelZoom = (event) => {
              // Keep page scroll; never let wheel/pinch stretch the graph
              if (event.ctrlKey || event.metaKey) {
                event.preventDefault();
              }
              event.stopPropagation();
            };
            node.addEventListener("wheel", blockWheelZoom, { capture: true, passive: false });
            node.addEventListener("mousewheel", blockWheelZoom, { capture: true, passive: false });
          },
        },
      },
      colors: ["#008FFB"],
      dataLabels: {
        enabled: false,
      },
      markers: {
        size: 0,
      },
      stroke: {
        curve: "straight",
        width: 2,
        colors: ["#008FFB"],
      },
      title: {
        text: "Payment Movement",
        align: "left",
        style: {
          fontSize: "16px",
          fontWeight: 700,
          color: "#373d3f",
        },
      },
      fill: {
        type: "gradient",
        gradient: {
          shadeIntensity: 1,
          inverseColors: false,
          opacityFrom: 0.5,
          opacityTo: 0,
          stops: [0, 90, 100],
        },
      },
      grid: {
        borderColor: "#e7e7e7",
        strokeDashArray: 0,
        xaxis: { lines: { show: false } },
        yaxis: { lines: { show: true } },
      },
      yaxis: {
        labels: {
          formatter(val) {
            const n = Number(val || 0);
            if (!useAmount) return String(Math.round(n));
            if (Math.abs(n) >= 1000000) return `${(n / 1000000).toFixed(0)}M`;
            if (Math.abs(n) >= 1000) return `${(n / 1000).toFixed(n % 1000 === 0 ? 0 : 1)}k`;
            return String(Math.round(n));
          },
        },
        title: {
          text: useAmount ? "Amount" : "Transactions",
        },
      },
      xaxis: {
        type: "datetime",
        labels: {
          datetimeUTC: false,
          format: "dd MMM",
          style: { colors: "#64748b", fontSize: "12px" },
        },
        tooltip: { enabled: false },
      },
      tooltip: {
        shared: false,
        x: {
          format: "dd MMM yyyy",
        },
        y: {
          formatter(val, opts) {
            const idx = opts?.dataPointIndex ?? -1;
            const day = dayValues[idx];
            const total = Number(val || 0);
            if (!useAmount) {
              const dayPart = day ? ` · +${Math.round(day.dayVal)} today` : "";
              return `${Math.round(total)} tx${dayPart}`;
            }
            const dayPart = day && day.dayVal > 0 ? ` · +${money(day.dayVal)} today` : "";
            return `${money(total)}${dayPart}`;
          },
        },
      },
      legend: { show: false },
    };

    const chart = new ApexCharts(el, options);
    chartStore.trend = chart;
    chart.render();
  }

  function updatePeriodLabels(analytics) {
    const label = analytics.periodLabel || "All time";
    const periodEl = document.getElementById("ad-period-label");
    const incomingPeriodEl = document.getElementById("stat-incoming-period");
    const recentPeriodEl = document.getElementById("ad-recent-period");
    const count = Number(analytics.recordCount || 0);

    if (periodEl) {
      periodEl.textContent = count > 0 ? `${label} · ${count} records` : label;
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

  function normalizeDestinationPhone(raw) {
    const digits = String(raw || "").replace(/[^\d+]/g, "").trim();
    if (!digits) return "";
    if (digits.startsWith("+")) return digits;
    if (digits.startsWith("255")) return `+${digits}`;
    if (digits.startsWith("0") && digits.length >= 10) return `+255${digits.slice(1)}`;
    return digits.startsWith("+") ? digits : `+${digits}`;
  }

  function maskDestinationPhone(raw) {
    const phone = normalizeDestinationPhone(raw).replace(/^\+/, "");
    if (!phone) return "—";
    if (phone.length <= 6) return phone;
    return `${phone.slice(0, 4)}${"*".repeat(Math.max(4, phone.length - 6))}${phone.slice(-2)}`;
  }

  function syncDestinationDisplays(phone, options = {}) {
    const display = normalizeDestinationPhone(phone) || "—";
    const masked = options.masked || maskDestinationPhone(display);
    const phoneInput = document.querySelector('#ad-payout-form input[name="mobileMoneyNumber"]');
    if (phoneInput && display !== "—") {
      phoneInput.value = display;
      phoneInput.classList.add("is-saved-ok");
    }
    const destEls = [
      document.getElementById("stat-dest"),
      document.getElementById("ad-portal-dest"),
      ...document.querySelectorAll("[data-dest-phone]"),
    ];
    destEls.forEach((el) => {
      if (!el) return;
      el.textContent = options.showFull ? display : masked;
      el.classList.add("is-dest-updated");
    });
    const hubAuto = document.getElementById("ad-ga-hub-auto");
    if (hubAuto) {
      const autoOn = document.getElementById("stat-auto")?.textContent === "ON";
      hubAuto.textContent = autoOn ? `Auto → ${masked}` : `Set → ${masked}`;
    }
    if (latestSettings) {
      latestSettings.displayDestination = display;
      latestSettings.maskedDestination = masked;
    }
  }

  async function loadSettings() {
    try {
      const result = await requestJson("payout-settings");
      latestSettings = result;
      if (result.displayDestination || result.maskedDestination) {
        syncDestinationDisplays(result.displayDestination || "", {
          masked: result.maskedDestination,
          showFull: true,
        });
      }
      setAutoPayoutUi(!!result.enabled, result.mode || "TEST");
      if (result.warning) {
        setBanner("ad-payouts-error", result.warning, "warning", { toast: true });
      }
      const testBadge = document.getElementById("ad-test-mode-badge");
      if (testBadge) {
        testBadge.hidden = !result.testMode;
      }
      syncPortalCards();
      if (result.displayDestination || result.maskedDestination) {
        syncDestinationDisplays(result.displayDestination || "", {
          masked: result.maskedDestination,
          showFull: true,
        });
      }
    } catch (error) {
      setAutoPayoutUi(false, "ERROR");
      syncPortalCards();
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
    const body = document.getElementById("ad-recent-body");
    const legacy = document.getElementById("ad-recent");
    if (!body && !legacy) return;

    const filtered = filterTableRows(latestRecentRows, tableUi.recent.search, (row) =>
      [row.orderReference, row.controlNumber, row.status, row.amount, row.createdAt].join(" ")
    );
    const page = paginateTableRows(filtered, tableUi.recent);

    if (body) {
      body.innerHTML = page.slice.length
        ? page.slice.map((row, index) => `
          <tr>
            <td class="acs-dt-sn">${page.start + index + 1}</td>
            <td><strong>${esc(row.orderReference || row.controlNumber || "—")}</strong></td>
            <td>${statusBadge(row.status)}</td>
            <td>${money(row.amount)}</td>
            <td>${fmtDate(row.createdAt)}</td>
            <td>
              <div class="acs-dt-actions">
                <button type="button" class="acs-dt-action acs-dt-action--danger" data-recent-delete="${esc(String(row.id || ""))}" data-recent-ref="${esc(row.orderReference || row.controlNumber || "")}" title="Delete"><i class="fa-solid fa-trash"></i><span>Delete</span></button>
              </div>
            </td>
          </tr>`).join("")
        : `<tr class="acs-dt-empty"><td colspan="6"><strong>No matching collections</strong>Try another reference or clear the search.</td></tr>`;

      body.querySelectorAll("[data-recent-delete]").forEach((btn) => {
        btn.addEventListener("click", async () => {
          const id = Number(btn.getAttribute("data-recent-delete"));
          const ref = btn.getAttribute("data-recent-ref") || String(id || "");
          if (!id) {
            notify("Cannot delete this item (missing id).", "error");
            return;
          }
          if (!(await confirmAction({
            title: "Delete collection?",
            text: `Delete collection ${ref}?`,
            confirmButtonText: "Delete",
          }))) return;
          btn.disabled = true;
          try {
            const result = await requestJson("delete-payment", { method: "POST", body: { id } });
            notify(result.message || "Deleted.", "success");
            await Promise.all([loadStatement(), loadControls()]);
          } catch (error) {
            notify(error.message || "Delete failed.", "error");
          } finally {
            btn.disabled = false;
          }
        });
      });
    }

    updateTableInfo("ad-recent-info", page.start, page.end, page.total);
    renderTablePagination("ad-recent-pagination", tableUi.recent.page, page.totalPages, (p) => {
      tableUi.recent.page = p;
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
    const list = Array.isArray(payments) ? payments : [];
    const today = new Date();
    today.setHours(12, 0, 0, 0);

    let span = Math.max(1, Number(days) || 14);
    const times = list
      .map((p) => {
        const dt = new Date(p.createdAt || p.updatedAt || 0);
        return Number.isNaN(dt.getTime()) ? null : dt.getTime();
      })
      .filter((t) => t != null);

    if (times.length) {
      const oldest = Math.min(...times);
      const daySpan = Math.ceil((today.getTime() - oldest) / 86400000) + 1;
      if (daySpan > span) span = Math.min(90, Math.max(span, daySpan));
    }

    const map = {};
    for (let i = span - 1; i >= 0; i -= 1) {
      const d = new Date(today);
      d.setDate(d.getDate() - i);
      const key = localDateKey(d);
      map[key] = {
        date: key,
        label: d.toLocaleDateString("en-GB", { day: "numeric", month: "short" }),
        count: 0,
        amount: 0,
      };
    }
    list.forEach((p) => {
      const raw = p.createdAt || p.updatedAt;
      if (!raw) return;
      const key = localDateKey(raw);
      if (key && map[key]) {
        map[key].count += 1;
        const status = String(p.status || "").toUpperCase();
        if (["SUCCESS", "SUCCESSFUL", "COMPLETED", "PAID", "SETTLED"].includes(status)) {
          map[key].amount += Number(p.amount || 0);
        }
      }
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
      if (status === "SUCCESS" || status === "SUCCESSFUL" || status === "COMPLETED" || status === "PAID" || status === "SETTLED") {
        success += 1;
        moneyIn += amount;
      } else if (status === "FAILED" || status === "FAILURE" || status === "DECLINED" || status === "CANCELLED" || status === "EXPIRED") {
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
      recentCollections: payments.slice(0, 40).map((p) => ({
        id: p.id,
        orderReference: p.orderReference,
        controlNumber: "",
        amount: p.amount,
        status: p.status,
        createdAt: p.createdAt,
      })),
      payments,
      source: "payments-merge",
    };
  }

  async function loadMergedAnalyticsFallback() {
    // Prefer same-origin admin proxy (avoids browser CORS / user-login issues).
    try {
      const proxied = await requestJson("live-payments");
      if (proxied && Array.isArray(proxied.payments) && proxied.payments.length) {
        return analyticsFromMergedPayments(proxied);
      }
    } catch (_) {
      /* fall through to client merge */
    }

    if (!window.GetwayPaymentsMerge?.loadMergedPayments) return null;
    const apiBase = window.TIS_API_BASE || window.BASE_API_URL || "https://getways-app.onrender.com";
    const clickpesaBase = window.CLICKPESA_API_BASE || `${window.location.origin}/api/clickpesa`;
    const summary = await window.GetwayPaymentsMerge.loadMergedPayments(apiBase, clickpesaBase, {
      "Content-Type": "application/json",
    });
    return analyticsFromMergedPayments(summary);
  }

  function enrichAnalyticsForCharts(analytics) {
    const data = { ...(analytics || {}) };
    const counts = normalizePieCounts(data);
    data.success = counts.success;
    data.pending = counts.pending;
    data.failed = counts.failed;
    data.recordCount = Number(data.recordCount || 0) || counts.success + counts.pending + counts.failed;

    const paymentRows = Array.isArray(data.payments) && data.payments.length
      ? data.payments
      : Array.isArray(data.recentCollections)
        ? data.recentCollections
        : [];
    const trendHits = (Array.isArray(data.trendDays) ? data.trendDays : []).reduce(
      (sum, d) => sum + Number(d.count || 0),
      0
    );
    if (trendHits <= 0 && paymentRows.length) {
      data.trendDays = buildTrendFromPayments(paymentRows, 14);
    }
    return data;
  }

  function analyticsChartTotals(analytics) {
    const data = enrichAnalyticsForCharts(analytics || {});
    const pieTotal = Number(data.success || 0) + Number(data.pending || 0) + Number(data.failed || 0);
    const trendHits = (Array.isArray(data.trendDays) ? data.trendDays : []).reduce(
      (sum, d) => sum + Number(d.count || 0),
      0
    );
    return { pieTotal, trendHits };
  }

  function applyAnalyticsToCharts(analytics) {
    const data = enrichAnalyticsForCharts(analytics || {});
    try {
      drawTrend(document.getElementById("ad-trend"), data.trendDays || []);
    } catch (error) {
      console.error("Trend chart failed", error);
      const el = document.getElementById("ad-trend");
      if (el) el.innerHTML = '<p class="ad-trend-empty">Trend chart failed to render.</p>';
    }
    try {
      drawPie(document.getElementById("ad-pie"), data);
    } catch (error) {
      console.error("Pie chart failed", error);
      const el = document.getElementById("ad-pie");
      if (el) el.innerHTML = '<p class="ad-trend-empty">Pie chart failed to render.</p>';
    }
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

  let statementLoadSeq = 0;

  async function loadStatement() {
    const loadId = ++statementLoadSeq;
    try {
      let analytics = {};
      let warning = "";

      // Live merge is the source of truth for admin charts (same as user dashboard).
      try {
        const merged = await loadMergedAnalyticsFallback();
        if (loadId !== statementLoadSeq) return;
        if (merged && Number(merged.recordCount || 0) > 0) {
          analytics = merged;
        }
      } catch (mergeError) {
        warning = mergeError.message || String(mergeError);
      }

      // Fall back to DB analytics only when merge is empty.
      if (analyticsChartTotals(analytics).pieTotal <= 0) {
        try {
          const result = await requestJson("analytics", {
            query: { period: analyticsPeriod },
          });
          if (loadId !== statementLoadSeq) return;
          analytics = enrichAnalyticsForCharts(result.analytics || {});
          if (result.warning) warning = result.warning;
        } catch (dbError) {
          if (!warning) warning = dbError.message || String(dbError);
        }
      }

      if (loadId !== statementLoadSeq) return;

      analytics = enrichAnalyticsForCharts(analytics);
      latestAnalytics = analytics;
      const incomingEl = document.getElementById("stat-incoming");
      const successEl = document.getElementById("stat-success");
      const pendingEl = document.getElementById("stat-pending");
      const failedEl = document.getElementById("stat-failed");
      if (incomingEl) incomingEl.textContent = money(analytics.moneyIn || 0);
      if (successEl) successEl.textContent = String(analytics.success || 0);
      if (pendingEl) pendingEl.textContent = String(analytics.pending || 0);
      if (failedEl) failedEl.textContent = String(analytics.failed || 0);
      updatePeriodLabels(analytics);

      applyAnalyticsToCharts(analytics);
      chartsNeedRedraw = !chartsContainerVisible();
      if (chartsContainerVisible()) {
        window.setTimeout(() => {
          try {
            chartStore.trend?.resize?.();
          } catch (_) {
            /* ignore */
          }
        }, 150);
      }

      latestRecentRows = analytics.recentCollections || [];
      latestPaymentRows = Array.isArray(analytics.payments) && analytics.payments.length
        ? analytics.payments
        : Array.isArray(analytics.recentCollections)
          ? analytics.recentCollections
          : latestPaymentRows;
      tableUi.recent.page = 1;
      renderRecentCollections();
      applyUserPaidAmounts();
      if (!latestAllControlRows.length && latestPaymentRows.length) {
        latestAllControlRows = mapPaymentsToControlRows(latestPaymentRows);
        applyTxFilter();
      }
      if (warning && analyticsChartTotals(analytics).pieTotal <= 0) {
        setBanner("ad-statement-error", warning, "error");
      } else {
        setBanner("ad-statement-error", "");
      }
      setBanner("ad-recent-error", "");
      syncPortalCards();
    } catch (error) {
      if (loadId !== statementLoadSeq) return;
      console.error("loadStatement failed", error);
      if (!latestAnalytics || analyticsChartTotals(latestAnalytics).pieTotal <= 0) {
        latestAnalytics = { success: 0, pending: 0, failed: 0, trendDays: [], recentCollections: [] };
        applyAnalyticsToCharts(latestAnalytics);
        latestRecentRows = [];
        tableUi.recent.page = 1;
        renderRecentCollections();
      }
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

  function normalizeTxStatus(status) {
    const s = String(status || "").trim().toUpperCase();
    if (["SUCCESS", "SUCCESSFUL", "SETTLED", "COMPLETED", "PAID"].includes(s)) return "SUCCESS";
    if (["FAILED", "FAILURE", "DECLINED", "CANCELLED", "EXPIRED"].includes(s)) return "FAILED";
    if (s === "PENDING") return "PENDING";
    return s || "PENDING";
  }

  function mapPaymentsToControlRows(payments) {
    return (Array.isArray(payments) ? payments : []).map((p, index) => {
      const status = normalizeTxStatus(p.status);
      return {
        id: p.id || p.orderReference || `pay-${index}`,
        orderId: p.orderReference || p.orderId || "—",
        customerName: p.customerName || p.phone || "—",
        controlNumber: p.controlNumber || "—",
        hasControlNumber: Boolean(p.controlNumber),
        reference: p.orderReference || "—",
        amount: Number(p.amount || 0),
        receivedAmount: status === "SUCCESS" ? Number(p.amount || 0) : null,
        status,
        description: p.description || "",
        channel: p.channel || null,
        createdAt: p.createdAt || p.updatedAt || null,
        withdrawStatus: "—",
        canWithdraw: false,
        canResend: status === "PENDING",
        invoiceUrl: "",
        collectorUserId: p.collectorUserId || "",
        phone: p.phone || "",
      };
    });
  }

  function applyTxFilter() {
    const filter = String(txStatusFilter || "ALL").toUpperCase();
    const source = latestAllControlRows.length ? latestAllControlRows : latestControlRows;
    latestControlRows = filter === "ALL"
      ? source.slice()
      : source.filter((row) => normalizeTxStatus(row.status) === filter);
    tableUi.controls.page = 1;
    const titleEl = document.getElementById("ad-transactions-title");
    if (titleEl) {
      const labels = {
        ALL: "Transactions",
        SUCCESS: "Successful payments",
        PENDING: "Pending payments",
        FAILED: "Failed payments",
      };
      titleEl.textContent = labels[filter] || "Transactions";
    }
    document.querySelectorAll("[data-set-tx-filter]").forEach((btn) => {
      btn.classList.toggle("is-active", String(btn.getAttribute("data-set-tx-filter") || "").toUpperCase() === filter);
    });
    renderControlsTable();
  }

  function normalizePhoneDigits(raw) {
    return String(raw || "").replace(/\D/g, "");
  }

  function paymentBelongsToUser(payment, user) {
    if (!payment || !user) return false;
    const uid = String(user.id || "").trim();
    const tag = uid ? `[gw:${uid}]` : "";
    const collector = String(payment.collectorUserId || "").trim();
    const desc = String(payment.description || "");
    if (uid && (collector === uid || (tag && desc.includes(tag)))) return true;
    const userPhone = normalizePhoneDigits(user.phone);
    const payPhone = normalizePhoneDigits(payment.phone);
    if (userPhone && payPhone && userPhone === payPhone) return true;
    const userName = String(user.fullName || "").trim().toLowerCase();
    const payName = String(payment.customerName || "").trim().toLowerCase();
    return Boolean(userName && payName && userName === payName);
  }

  function applyUserPaidAmounts() {
    if (!latestUserRows.length) return;
    const payments = latestPaymentRows.length
      ? latestPaymentRows
      : (latestAnalytics?.payments || latestAnalytics?.recentCollections || []);
    latestUserRows = latestUserRows.map((user) => {
      const paidAmount = (Array.isArray(payments) ? payments : []).reduce((sum, payment) => {
        const status = normalizeTxStatus(payment.status);
        if (status !== "SUCCESS") return sum;
        if (!paymentBelongsToUser(payment, user)) return sum;
        return sum + Number(payment.amount || 0);
      }, 0);
      return { ...user, paidAmount };
    });
    renderUsersTable();
  }

  function renderControlsTable() {
    const body = document.getElementById("ad-controls-body");
    if (!body) return;
    const filtered = filterTableRows(latestControlRows, tableUi.controls.search, (row) =>
      [
        row.orderId,
        row.customerName,
        row.controlNumber,
        row.reference,
        row.amount,
        row.receivedAmount,
        row.withdrawStatus,
        row.status,
      ].join(" ")
    );
    const page = paginateTableRows(filtered, tableUi.controls);
    body.innerHTML = page.slice.length ? page.slice.map((row, index) => {
      const status = String(row.status || "").toUpperCase();
      const isPending = status === "PENDING";
      const showResend = isPending && row.canResend !== false;
      const showWithdraw = row.canWithdraw && isManualPayoutActive();
      return `
        <tr>
          <td class="acs-dt-sn">${page.start + index + 1}</td>
          <td>${esc(row.orderId || "—")}</td>
          <td>${esc(row.customerName || "—")}</td>
          <td>${esc(row.controlNumber || "—")}</td>
          <td>${esc(row.reference || "—")}</td>
          <td>${money(row.amount)}</td>
          <td>${row.receivedAmount != null ? money(row.receivedAmount) : "—"}</td>
          <td>${payoutBadge(row.withdrawStatus)}</td>
          <td>${statusBadge(row.status)}</td>
          <td>
            <div class="acs-dt-actions ad-actions">
            ${row.hasControlNumber ? `<button type="button" class="acs-dt-action acs-dt-action--ghost" data-copy="${esc(row.controlNumber)}" title="Copy control number"><i class="fa-regular fa-copy"></i><span>Copy</span></button>` : ""}
            ${showResend ? `<button type="button" class="acs-dt-action" data-resend="${row.id}" title="Resend"><i class="fa-solid fa-paper-plane"></i><span>Resend</span></button>` : ""}
            ${showWithdraw ? `<button type="button" class="acs-dt-action" data-withdraw="${row.id}" title="Withdraw"><i class="fa-solid fa-money-bill-wave"></i><span>Withdraw</span></button>` : ""}
            ${row.invoiceUrl ? `<button type="button" class="acs-dt-action" data-invoice="${esc(row.invoiceUrl)}" title="View receipt"><i class="fa-solid fa-receipt"></i><span>View</span></button>` : ""}
            ${row.invoiceUrl ? `<button type="button" class="acs-dt-action acs-dt-action--ghost" data-invoice-download="${esc(row.invoiceUrl)}" title="Download PDF"><i class="fa-solid fa-file-pdf"></i><span>PDF</span></button>` : ""}
            <button type="button" class="acs-dt-action acs-dt-action--danger" data-delete-payment="${row.id}" data-delete-ref="${esc(row.reference || row.orderId || row.id)}" title="Delete transaction"><i class="fa-solid fa-trash"></i><span>Delete</span></button>
            </div>
          </td>
        </tr>`;
    }).join("") : `<tr class="acs-dt-empty"><td colspan="10"><strong>No matching transactions</strong>Try another search or clear filters.</td></tr>`;
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
        if (!(await confirmAction({
          title: "Delete transaction?",
          text: `Delete transaction ${ref}? This removes it from the dashboard only.`,
          confirmButtonText: "Delete",
        }))) return;
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
    updateTableInfo("ad-controls-info", page.start, page.end, page.total);
    renderTablePagination("ad-controls-pagination", tableUi.controls.page, page.totalPages, (p) => {
      tableUi.controls.page = p;
      renderControlsTable();
    });
    syncPortalCards();
  }

  async function loadControls() {
    const body = document.getElementById("ad-controls-body");
    body.innerHTML = `<tr><td colspan="9">Loading...</td></tr>`;
    try {
      let items = [];
      try {
        const result = await requestJson("control-numbers");
        items = result.items || [];
        if (result.payoutSettings) {
          latestSettings = { ...(latestSettings || {}), ...result.payoutSettings };
        }
      } catch (_) {
        items = [];
      }

      if (!items.length) {
        try {
          const proxied = await requestJson("live-payments");
          const payments = Array.isArray(proxied?.payments) ? proxied.payments : [];
          latestPaymentRows = payments;
          items = mapPaymentsToControlRows(payments);
        } catch (_) {
          if (latestPaymentRows.length) {
            items = mapPaymentsToControlRows(latestPaymentRows);
          }
        }
      }

      latestAllControlRows = items;
      applyTxFilter();
      applyUserPaidAmounts();
      setBanner("ad-controls-error", "");
    } catch (error) {
      body.innerHTML = `<tr><td colspan="9">No transactions yet.</td></tr>`;
      setBanner("ad-controls-error", error.message);
    }
  }

  function renderPayoutsTable() {
    const body = document.getElementById("ad-payouts-body");
    if (!body) return;
    const filtered = filterTableRows(latestPayoutRows, tableUi.payouts.search, (row) =>
      [
        row.payoutReference,
        row.destinationMasked,
        row.amount,
        row.fee,
        row.status,
        row.provider,
        row.lastError,
        row.updatedAt,
      ].join(" ")
    );
    const page = paginateTableRows(filtered, tableUi.payouts);
    body.innerHTML = page.slice.length ? page.slice.map((row, index) => `
        <tr>
          <td class="acs-dt-sn">${page.start + index + 1}</td>
          <td>${esc(row.payoutReference)}</td>
          <td>${esc(row.destinationMasked || "—")}</td>
          <td>${money(row.amount)}</td>
          <td>${row.fee != null ? money(row.fee) : "—"}</td>
          <td>${statusBadge(row.status)}</td>
          <td>${esc(row.provider || "—")}</td>
          <td>${esc(row.lastError || "—")}</td>
          <td>
            <div class="ad-payout-updated">${fmtDate(row.updatedAt)}</div>
            <div class="acs-dt-actions ad-actions ad-actions--payout">
              <button type="button" class="acs-dt-action acs-dt-action--ghost" data-refresh-payout="${esc(row.payoutReference)}" title="Refresh status"><i class="fa-solid fa-arrows-rotate"></i><span>Status</span></button>
              ${row.retryable ? `<button type="button" class="acs-dt-action" data-retry-payout="${row.id}" title="Retry payout"><i class="fa-solid fa-rotate-right"></i><span>Retry</span></button>` : ""}
              <button type="button" class="acs-dt-action" data-view-payout="${row.id}" title="View details"><i class="fa-solid fa-eye"></i><span>View</span></button>
              <button type="button" class="acs-dt-action acs-dt-action--danger" data-delete-payout="${row.id}" data-delete-ref="${esc(row.payoutReference)}" title="Delete payout"><i class="fa-solid fa-trash"></i><span>Delete</span></button>
            </div>
          </td>
        </tr>`).join("") : `<tr class="acs-dt-empty"><td colspan="9"><strong>No matching payouts</strong>Try another search term.</td></tr>`;
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
        if (!(await confirmAction({
          title: "Delete payout?",
          text: `Delete payout ${ref}? This removes it from the dashboard only.`,
          confirmButtonText: "Delete",
        }))) return;
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
    updateTableInfo("ad-payouts-info", page.start, page.end, page.total);
    renderTablePagination("ad-payouts-pagination", tableUi.payouts.page, page.totalPages, (p) => {
      tableUi.payouts.page = p;
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
      if (!(await confirmAction({
        title: "Confirm payout?",
        html: confirmMsg.replace(/\n/g, "<br>"),
        confirmButtonText: "Confirm payout",
        confirmButtonColor: "#145493",
        icon: "question",
      }))) return;
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
      tableUi.payouts.page = 1;
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
    const filtered = filterTableRows(latestUserRows, tableUi.users.search, (row) =>
      [row.fullName, row.phone, row.email, row.username, row.paidAmount, row.createdAt].join(" ")
    );
    const page = paginateTableRows(filtered, tableUi.users);
    body.innerHTML = page.slice.length ? page.slice.map((row, index) => `
        <tr>
          <td class="acs-dt-sn">${page.start + index + 1}</td>
          <td>${esc(row.fullName || "—")}</td>
          <td>${esc(row.phone || row.email || "—")}</td>
          <td>${esc(row.username || "—")}</td>
          <td>${money(row.paidAmount || 0)}</td>
          <td>${fmtDate(row.createdAt)}</td>
          <td>
            <div class="acs-dt-actions ad-actions ad-actions--users">
              <button type="button" class="acs-dt-action" data-view-user="${esc(row.id || "")}" title="View user"><i class="fa-solid fa-eye"></i></button>
              <button type="button" class="acs-dt-action acs-dt-action--danger" data-delete-user="${esc(row.id || "")}" data-delete-name="${esc(row.fullName || row.username || row.phone || "")}" title="Delete user"><i class="fa-solid fa-trash"></i></button>
            </div>
          </td>
        </tr>`).join("") : `<tr class="acs-dt-empty"><td colspan="7"><strong>No matching users</strong>Registered users from the signup page will appear here.</td></tr>`;

    body.querySelectorAll("[data-view-user]").forEach((btn) => {
      btn.addEventListener("click", () => {
        const id = btn.getAttribute("data-view-user");
        const user = latestUserRows.find((u) => String(u.id) === String(id));
        if (!user) return;
        const html = `
          <div style="text-align:left;font-size:0.9rem;line-height:1.5">
            <p><strong>Name:</strong> ${esc(user.fullName || "—")}</p>
            <p><strong>Phone:</strong> ${esc(user.phone || "—")}</p>
            <p><strong>Email:</strong> ${esc(user.email || "—")}</p>
            <p><strong>Username:</strong> ${esc(user.username || "—")}</p>
            <p><strong>Paid amount:</strong> ${esc(money(user.paidAmount || 0))}</p>
            <p><strong>Joined:</strong> ${esc(fmtDate(user.createdAt))}</p>
          </div>`;
        if (window.Swal) {
          window.Swal.fire({
            title: "Registered user",
            html,
            confirmButtonText: "OK",
            confirmButtonColor: "#1a3352",
            buttonsStyling: true,
          });
        } else {
          window.alert(`${user.fullName || ""}\n${user.phone || ""}\n${user.username || ""}`);
        }
      });
    });

    body.querySelectorAll("[data-delete-user]").forEach((btn) => {
      btn.addEventListener("click", async () => {
        const id = btn.getAttribute("data-delete-user") || "";
        const name = btn.getAttribute("data-delete-name") || id;
        if (!id) return;
        if (!(await confirmAction({
          title: "Delete user?",
          text: `Delete registered user ${name}?`,
          confirmButtonText: "Delete",
        }))) return;
        btn.disabled = true;
        try {
          const res = await fetch("auth-api.php?action=delete-user", {
            method: "POST",
            credentials: "same-origin",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ id }),
          });
          const data = await res.json().catch(() => ({}));
          if (!res.ok || !data.ok) throw new Error(data.message || "Delete failed.");
          notify(data.message || "User deleted.", "success");
          await loadUsers();
        } catch (error) {
          notify(error.message || "Delete failed.", "error");
        } finally {
          btn.disabled = false;
        }
      });
    });

    updateTableInfo("ad-users-info", page.start, page.end, page.total);
    renderTablePagination("ad-users-pagination", tableUi.users.page, page.totalPages, (p) => {
      tableUi.users.page = p;
      renderUsersTable();
    });
    syncPortalCards();
  }

  async function loadUsers() {
    const body = document.getElementById("ad-users-body");
    if (!body) return;
    body.innerHTML = `<tr><td colspan="7">Loading...</td></tr>`;
    try {
      const res = await fetch("auth-api.php?action=list-users", {
        credentials: "same-origin",
        cache: "no-store",
        headers: { Accept: "application/json" },
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data.ok) throw new Error(data.message || "Could not load users.");
      latestUserRows = Array.isArray(data.items) ? data.items : [];
      tableUi.users.page = 1;
      applyUserPaidAmounts();
      renderUsersTable();
      syncPortalCards();
      if (!latestUserRows.length) {
        setBanner(
          "ad-users-error",
          "No registered users found yet. New signups from register/login will appear here.",
          "warning"
        );
      } else {
        setBanner("ad-users-error", "");
      }
    } catch (error) {
      latestUserRows = [];
      tableUi.users.page = 1;
      renderUsersTable();
      syncPortalCards();
      setBanner("ad-users-error", error.message || "Could not load registered users.", "error");
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
    const submitBtn = event.target.querySelector('button[type="submit"]');
    const adminPassword = await promptAdminPassword();
    if (!adminPassword) return;
    try {
      if (msg) {
        msg.className = "ad-msg";
        msg.innerHTML = "Saving...";
      }
      if (submitBtn) submitBtn.disabled = true;
      const payload = Object.fromEntries(new FormData(event.target).entries());
      const savedPhone = normalizeDestinationPhone(payload.mobileMoneyNumber);
      // Saving a destination always enables LIVE automatic payout to that number.
      const result = await requestJson("payout-settings", {
        method: "POST",
        body: {
          mobileMoneyNumber: savedPhone || payload.mobileMoneyNumber,
          enabled: true,
          mode: "LIVE_AUTO",
          manualApprovalRequired: false,
          currentAdminPassword: adminPassword,
        },
      });

      const display =
        result.displayDestination ||
        savedPhone ||
        normalizeDestinationPhone(payload.mobileMoneyNumber);
      const masked = result.maskedDestination || maskDestinationPhone(display);

      // Update every destination display immediately from the number just saved.
      syncDestinationDisplays(display, { masked, showFull: true });
      latestSettings = { ...(latestSettings || {}), ...result, enabled: true, mode: "LIVE_AUTO" };
      setAutoPayoutUi(true, "LIVE_AUTO");
      syncPortalCards();
      syncDestinationDisplays(display, { masked, showFull: true });

      if (msg) {
        msg.className = "ad-msg is-ok ad-msg--saved";
        msg.innerHTML = `<i class="fa-solid fa-circle-check" aria-hidden="true"></i><span>Saved — payouts go to <strong>${esc(display)}</strong></span>`;
      }
      event.target.classList.add("is-just-saved");
      if (submitBtn) {
        submitBtn.classList.add("is-saved-ok");
        submitBtn.innerHTML = `<i class="fa-solid fa-check" aria-hidden="true"></i><span>Saved</span>`;
        window.setTimeout(() => {
          submitBtn.classList.remove("is-saved-ok");
          submitBtn.innerHTML = "Save destination";
          event.target.classList.remove("is-just-saved");
        }, 2500);
      }
      notify("Payout number saved — automatic payout is ON.", "success");
      // Refresh from server so masked/display stay authoritative.
      await loadSettings();
      syncDestinationDisplays(
        latestSettings?.displayDestination || display,
        { masked: latestSettings?.maskedDestination || masked, showFull: true }
      );
    } catch (error) {
      if (msg) {
        msg.className = "ad-msg is-err";
        msg.innerHTML = `<i class="fa-solid fa-circle-xmark" aria-hidden="true"></i><span>${esc(error.message)}</span>`;
      }
      notify(error.message, "error");
    } finally {
      if (submitBtn) submitBtn.disabled = false;
    }
  }

  async function toggleAutoPayout() {
    // Manual/auto toggle removed — open destination settings instead.
    document.querySelector('[data-ad-target="payout-dest"]')?.click();
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

  let portalCardsSyncing = false;

  function syncPortalCards() {
    if (portalCardsSyncing) return;
    portalCardsSyncing = true;
    try {
    const map = [
      ["stat-balance", "ad-portal-balance"],
      ["stat-balance-updated", "ad-portal-balance-updated"],
      ["stat-incoming", "ad-portal-incoming"],
      ["stat-incoming-period", "ad-portal-period"],
      ["stat-success", "ad-portal-success"],
      ["stat-pending", "ad-portal-pending"],
      ["stat-failed", "ad-portal-failed"],
      ["stat-dest", "ad-portal-dest"],
    ];
    map.forEach(([fromId, toId]) => {
      const from = document.getElementById(fromId);
      const to = document.getElementById(toId);
      if (from && to) {
        to.textContent = from.textContent || "";
      }
    });
    // Prefer live settings destination when available.
    const destDisplay =
      latestSettings?.displayDestination ||
      latestSettings?.maskedDestination ||
      document.getElementById("stat-dest")?.textContent ||
      "";
    if (destDisplay) {
      const statDest = document.getElementById("stat-dest");
      const portalDest = document.getElementById("ad-portal-dest");
      if (statDest) statDest.textContent = destDisplay;
      if (portalDest) portalDest.textContent = destDisplay;
    }
    // Keep hidden auto stats in sync for GA hub / legacy helpers.
    setAutoPayoutUi(
      Boolean(latestSettings?.enabled) && String(latestSettings?.mode || "").toUpperCase() === "LIVE_AUTO",
      latestSettings?.mode || "LIVE_AUTO"
    );
    const recentSub = document.getElementById("ad-portal-recent-sub");
    if (recentSub) {
      recentSub.textContent = `${latestRecentRows.length} record${latestRecentRows.length === 1 ? "" : "s"}`;
    }
    document.getElementById("ad-portal-recent").textContent = String(latestRecentRows.length);
    document.getElementById("ad-portal-controls").textContent = String(latestAllControlRows.length || latestControlRows.length);
    const portalUsers = document.getElementById("ad-portal-users");
    if (portalUsers) portalUsers.textContent = String(latestUserRows.length);
    } finally {
      portalCardsSyncing = false;
    }
  }

  const PORTAL_SECTION_TITLES = {
    home: "Dashboard",
    "general-analysis": "General Analysis",
    analytics: "Payment analysis",
    "control-number": "Create control number",
    transactions: "Transactions",
    "payout-dest": "Payout destination",
    users: "Registered users",
    recent: "Recent collections",
    whatsapp: "WhatsApp",
    crm: "CRM",
    "crm-database": "Database",
  };

  function scrollToPortalSection(key, options = {}) {
    const idMap = {
      "general-analysis": "ad-section-general-analysis",
      analytics: "ad-section-analytics",
      "control-number": "ad-section-control-number",
      transactions: "ad-section-transactions",
      "payout-dest": "ad-section-payout-dest",
      users: "ad-section-users",
      recent: "ad-section-recent",
      whatsapp: "ad-section-whatsapp",
      crm: "ad-section-crm",
      "crm-database": "ad-section-crm-database",
    };
    if (key === "payouts") key = "payout-dest";
    if (!idMap[key]) return;

    if (key === "transactions") {
      const filter = String(options.txFilter || "ALL").toUpperCase();
      txStatusFilter = ["SUCCESS", "PENDING", "FAILED", "ALL"].includes(filter) ? filter : "ALL";
      applyTxFilter();
    }

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
          loadStatement().catch(() => {});
        }
        if (key === "users") {
          loadUsers().catch(() => {});
        }
        if (key === "whatsapp") {
          loadWhatsappMessages(waCurrentStatus);
        }
        if (key === "crm-database") {
          document.dispatchEvent(new CustomEvent("crm:load-database"));
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
    document.querySelectorAll("[data-ad-nav='home']").forEach((btn) => {
      btn.classList.remove("is-active");
    });
    document.querySelectorAll(".top-links [data-ad-target]").forEach((btn) => {
      btn.classList.toggle("is-active", btn.dataset.adTarget === key);
    });
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
    if (titleEl) titleEl.textContent = PORTAL_SECTION_TITLES.home || "Dashboard";
    document.querySelectorAll("[data-ad-nav='home']").forEach((btn) => btn.classList.add("is-active"));
    document.querySelectorAll(".ad-sidebar-link[data-ad-target], .top-links [data-ad-target]").forEach((btn) => {
      btn.classList.remove("is-active");
    });
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
    document.querySelectorAll("[data-ad-nav='home']").forEach((btn) => {
      btn.addEventListener("click", showPortalHome);
    });
    document.querySelectorAll(".ad-sidebar-link[data-ad-target], .top-links [data-ad-target]").forEach((btn) => {
      btn.addEventListener("click", () => scrollToPortalSection(btn.dataset.adTarget || ""));
    });
    document.querySelectorAll(".ad-service-card[data-ad-target]").forEach((btn) => {
      btn.addEventListener("click", () => {
        scrollToPortalSection(btn.dataset.adTarget || "", {
          txFilter: btn.getAttribute("data-tx-filter") || "ALL",
        });
      });
    });
    document.querySelectorAll("[data-set-tx-filter]").forEach((btn) => {
      btn.addEventListener("click", () => {
        txStatusFilter = String(btn.getAttribute("data-set-tx-filter") || "ALL").toUpperCase();
        applyTxFilter();
      });
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
  let waScheduleTimer = null;
  let waLastAutoSentTo = "";
  let waSending = false;
  let waPhoneList = [];
  let waHiddenIds = new Set();
  let waMessagesCache = [];
  let waPage = 1;
  const WA_PAGE_SIZE = 4;
  const WA_MODE_KEY = "gw_wa_send_mode";
  const WA_AUTO_BODY_KEY = "gw_wa_auto_body";
  const WA_BODY_KEY = "gw_wa_manual_body";
  const WA_PHONE_KEY = "gw_wa_phone";
  const WA_PHONE_LIST_KEY = "gw_wa_phone_list";
  const WA_PRIORITY_KEY = "gw_wa_priority";
  const WA_HIDDEN_KEY = "gw_wa_hidden_ids";
  const WA_SCHEDULE_KEY = "gw_wa_schedule";
  const WA_DELAY_VALUE_KEY = "gw_wa_delay_value";
  const WA_DELAY_UNIT_KEY = "gw_wa_delay_unit";
  const WA_AUTO_SCHEDULED_PHONES_KEY = "gw_wa_auto_scheduled_phones";

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

  function formatWaIntervalLabel(ms) {
    const n = Number(ms) || 0;
    if (n <= 0) return "";
    const minute = 60 * 1000;
    const day = 24 * 60 * minute;
    const month = 30 * day;
    if (n % month === 0) {
      const v = n / month;
      return `every ${v} month${v === 1 ? "" : "s"}`;
    }
    if (n % day === 0) {
      const v = n / day;
      return `every ${v} day${v === 1 ? "" : "s"}`;
    }
    const v = Math.max(1, Math.round(n / minute));
    return `every ${v} minute${v === 1 ? "" : "s"}`;
  }

  function formatWaScheduleTime(at) {
    try {
      return new Date(at).toLocaleString();
    } catch (_) {
      return String(at);
    }
  }

  function saveWaDelaySettings() {
    const valueEl = document.getElementById("ad-wa-delay-value");
    const unitEl = document.getElementById("ad-wa-delay-unit");
    if (!valueEl || !unitEl) return;
    try {
      const raw = valueEl.value;
      const num = Number(raw);
      const value = Number.isFinite(num) && num >= 0 ? String(Math.floor(num)) : "0";
      if (valueEl.value !== value) valueEl.value = value;
      const unit = ["minutes", "days", "months"].includes(unitEl.value) ? unitEl.value : "minutes";
      unitEl.value = unit;
      localStorage.setItem(WA_DELAY_VALUE_KEY, value);
      localStorage.setItem(WA_DELAY_UNIT_KEY, unit);
    } catch (_) {
      /* ignore */
    }
  }

  function loadWaDelaySettings() {
    const valueEl = document.getElementById("ad-wa-delay-value");
    const unitEl = document.getElementById("ad-wa-delay-unit");
    if (!valueEl || !unitEl) return;
    try {
      const savedValue = localStorage.getItem(WA_DELAY_VALUE_KEY);
      const savedUnit = localStorage.getItem(WA_DELAY_UNIT_KEY);
      if (savedValue !== null && savedValue !== "") {
        const num = Number(savedValue);
        valueEl.value = Number.isFinite(num) && num >= 0 ? String(Math.floor(num)) : "0";
      }
      if (savedUnit && ["minutes", "days", "months"].includes(savedUnit)) {
        unitEl.value = savedUnit;
      }
    } catch (_) {
      /* keep HTML defaults */
    }
  }

  function saveWaPriority() {
    const el = document.getElementById("ad-wa-priority");
    if (!el) return;
    try {
      localStorage.setItem(WA_PRIORITY_KEY, el.value || "10");
    } catch (_) {
      /* ignore */
    }
  }

  function loadWaPriority() {
    const el = document.getElementById("ad-wa-priority");
    if (!el) return;
    try {
      const saved = localStorage.getItem(WA_PRIORITY_KEY);
      if (saved !== null && ["0", "5", "10"].includes(saved)) {
        el.value = saved;
      }
    } catch (_) {
      /* ignore */
    }
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
    const stopBtn = document.getElementById("ad-wa-stop-auto");
    const bodyInput = document.getElementById("ad-wa-body");
    if (bodyWrap) bodyWrap.hidden = waMode === "auto";
    if (autoWrap) autoWrap.hidden = waMode !== "auto";
    if (schedule) schedule.hidden = waMode !== "auto";
    if (stopBtn) stopBtn.hidden = waMode !== "auto";
    if (bodyInput) bodyInput.required = waMode !== "auto";
    if (sendBtn) {
      sendBtn.innerHTML = waMode === "auto"
        ? '<i class="fa-brands fa-whatsapp"></i> Start automatic'
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

  function getWaSavedPhone() {
    try {
      const phone = normalizeWaPhone(localStorage.getItem(WA_PHONE_KEY) || "");
      return phone.length >= 9 ? phone : "";
    } catch (_) {
      return "";
    }
  }

  function refreshWaSavedPhoneHint() {
    const el = document.getElementById("ad-wa-phone-saved");
    const saved = getWaSavedPhone();
    if (!el) return;
    if (saved) {
      el.hidden = false;
      el.textContent = `Saved number: ${saved}`;
    } else {
      el.hidden = true;
      el.textContent = "";
    }
  }

  function saveWaPhoneSettings() {
    try {
      localStorage.setItem(WA_PHONE_LIST_KEY, JSON.stringify(waPhoneList));
    } catch (_) {
      /* ignore */
    }
  }

  async function fetchWaServerSchedule() {
    try {
      const res = await fetch("whatsapp-api.php?action=schedule", { credentials: "same-origin" });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data.ok) return [];
      return Array.isArray(data.items) ? data.items : [];
    } catch (_) {
      return [];
    }
  }

  async function saveWaPhoneNumber({ rescheduleAuto = false } = {}) {
    const input = document.getElementById("ad-wa-to");
    const phone = normalizeWaPhone(input?.value);
    if (phone.length < 9) {
      setWaMsg("Weka namba sahihi kwanza (mf. 2557XXXXXXXX).", true);
      return false;
    }
    if (input) input.value = phone;

    const serverItems = await fetchWaServerSchedule();
    const serverPhones = [...new Set(
      serverItems.map((item) => normalizeWaPhone(item.to)).filter((p) => p.length >= 9),
    )];
    const prevSaved = getWaSavedPhone();
    const toCancel = [...new Set(
      [...serverPhones, ...readWaAutoScheduledPhones(), prevSaved].filter((p) => p && p !== phone),
    )];
    if (toCancel.length) {
      await cancelWaSchedules(toCancel);
    }

    if (waMode === "auto") {
      waPhoneList = [phone];
      renderWaPhoneChips();
    }

    localStorage.setItem(WA_PHONE_KEY, phone);
    localStorage.setItem(WA_PHONE_LIST_KEY, JSON.stringify(waPhoneList));
    writeWaAutoScheduledPhones([phone]);
    waLastAutoSentTo = "";
    refreshWaSavedPhoneHint();
    setWaMsg(`Namba imehifadhiwa: ${phone}`);

    if (rescheduleAuto && waMode === "auto") {
      scheduleAutoSend();
    }
    return true;
  }

  function loadWaPhoneSettings() {
    const input = document.getElementById("ad-wa-to");
    try {
      const savedPhone = localStorage.getItem(WA_PHONE_KEY);
      if (input && savedPhone !== null) {
        input.value = savedPhone;
      }
      const rawList = JSON.parse(localStorage.getItem(WA_PHONE_LIST_KEY) || "[]");
      waPhoneList = Array.isArray(rawList)
        ? [...new Set(rawList.map((p) => normalizeWaPhone(p)).filter((p) => p.length >= 9))]
        : [];
      renderWaPhoneChips();
    } catch (_) {
      waPhoneList = [];
    }
  }

  function saveWaManualBody() {
    const el = document.getElementById("ad-wa-body");
    if (!el) return;
    try {
      localStorage.setItem(WA_BODY_KEY, el.value || "");
    } catch (_) {
      /* ignore */
    }
  }

  function loadWaManualBody() {
    const el = document.getElementById("ad-wa-body");
    if (!el) return;
    try {
      const saved = localStorage.getItem(WA_BODY_KEY);
      if (saved !== null) el.value = saved;
    } catch (_) {
      /* ignore */
    }
  }

  function renderWaPhoneChips() {
    const wrap = document.getElementById("ad-wa-phone-chips");
    if (!wrap) return;
    if (!waPhoneList.length) {
      wrap.hidden = true;
      wrap.innerHTML = "";
      saveWaPhoneSettings();
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
    saveWaPhoneSettings();
  }

  function collectWaTargets() {
    const single = normalizeWaPhone(document.getElementById("ad-wa-to")?.value);
    // Automatic uses the saved phone (after Save) — not draft typing in the field.
    if (waMode === "auto") {
      const saved = getWaSavedPhone();
      if (saved) return [saved];
      if (single.length >= 9) return [single];
      return [...new Set(waPhoneList.filter((p) => p.length >= 9))];
    }
    const list = [...waPhoneList];
    if (single && !list.includes(single)) list.unshift(single);
    return [...new Set(list.filter((p) => p.length >= 9))];
  }

  function readWaAutoScheduledPhones() {
    try {
      const raw = JSON.parse(localStorage.getItem(WA_AUTO_SCHEDULED_PHONES_KEY) || "[]");
      return Array.isArray(raw)
        ? [...new Set(raw.map((p) => normalizeWaPhone(p)).filter((p) => p.length >= 9))]
        : [];
    } catch (_) {
      return [];
    }
  }

  function writeWaAutoScheduledPhones(phones) {
    try {
      localStorage.setItem(
        WA_AUTO_SCHEDULED_PHONES_KEY,
        JSON.stringify([...new Set((phones || []).map((p) => normalizeWaPhone(p)).filter((p) => p.length >= 9))]),
      );
    } catch (_) {
      /* ignore */
    }
  }

  async function cancelWaSchedules(phones) {
    const list = [...new Set((phones || []).map((p) => normalizeWaPhone(p)).filter((p) => p.length >= 9))];
    if (!list.length) return null;
    const res = await fetch("whatsapp-api.php?action=schedule-cancel", {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ phones: list }),
    });
    return res.json().catch(() => ({}));
  }

  async function syncAutoWaSchedule({ targets, body, priority, at, everyMs }) {
    const next = [...new Set(targets.map((p) => normalizeWaPhone(p)).filter((p) => p.length >= 9))];
    const serverItems = await fetchWaServerSchedule();
    const serverPhones = serverItems.map((item) => normalizeWaPhone(item.to)).filter((p) => p.length >= 9);
    const prev = readWaAutoScheduledPhones();
    const toCancel = [...new Set([...prev, ...serverPhones].filter((p) => !next.includes(p)))];
    if (toCancel.length) {
      await cancelWaSchedules(toCancel);
    }
    for (const to of next) {
      await queueWaSchedule({ to, body, priority, at, everyMs });
    }
    writeWaAutoScheduledPhones(next);
  }

  function formatWaWhen(when) {
    if (when == null || when === "") return "";
    if (typeof when === "number" && when > 1000000000) {
      return new Date(when * (when < 1e12 ? 1000 : 1)).toLocaleString();
    }
    return String(when);
  }

  function getVisibleWaMessages(messages) {
    return (messages || []).filter((m) => {
      const id = String(m.id || m.messageId || m.msgId || "");
      return !id || !waHiddenIds.has(id);
    });
  }

  function bindWaMessageActions(list) {
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
        await waSwalSent("Sent", "Message removed from recent list.");
        renderWaMessages(waMessagesCache, waPage);
      });
    });
  }

  function renderWaMessages(messages, page) {
    const list = document.getElementById("ad-wa-list");
    if (!list) return;
    if (Array.isArray(messages)) {
      waMessagesCache = messages;
    }
    const visible = getVisibleWaMessages(waMessagesCache);
    const totalPages = Math.max(1, Math.ceil(visible.length / WA_PAGE_SIZE));
    waPage = Math.min(Math.max(1, page || waPage || 1), totalPages);

    if (!visible.length) {
      list.innerHTML = '<li class="ad-wa-empty">No messages</li>';
      const emptyPager = document.getElementById("ad-wa-pager");
      if (emptyPager) {
        emptyPager.hidden = true;
        emptyPager.innerHTML = "";
      }
      return;
    }

    const slice = visible.slice((waPage - 1) * WA_PAGE_SIZE, waPage * WA_PAGE_SIZE);
    list.innerHTML = slice.map((m) => {
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

    bindWaMessageActions(list);

    const pager = document.getElementById("ad-wa-pager");
    if (pager) {
      if (visible.length <= WA_PAGE_SIZE) {
        pager.hidden = true;
        pager.innerHTML = "";
      } else {
        const total = Math.max(1, Math.ceil(visible.length / WA_PAGE_SIZE));
        const current = Math.min(Math.max(1, waPage), total);
        pager.hidden = false;
        pager.innerHTML = `
          <button type="button" class="ad-btn ad-btn--ghost ad-pager-btn" data-page="prev" ${current <= 1 ? "disabled" : ""}><i class="fa-solid fa-chevron-left"></i><span>Previous</span></button>
          <span class="ad-pager-info">Page ${current} of ${total}</span>
          <button type="button" class="ad-btn ad-btn--ghost ad-pager-btn" data-page="next" ${current >= total ? "disabled" : ""}><span>Next</span><i class="fa-solid fa-chevron-right"></i></button>`;
        pager.querySelector('[data-page="prev"]')?.addEventListener("click", () => {
          renderWaMessages(waMessagesCache, current - 1);
          document.getElementById("ad-wa-list")?.scrollIntoView({ behavior: "smooth", block: "start" });
        });
        pager.querySelector('[data-page="next"]')?.addEventListener("click", () => {
          renderWaMessages(waMessagesCache, current + 1);
          document.getElementById("ad-wa-list")?.scrollIntoView({ behavior: "smooth", block: "start" });
        });
      }
    }
  }

  async function loadWhatsappMessages(status) {
    const list = document.getElementById("ad-wa-list");
    if (!list) return;
    waCurrentStatus = status || waCurrentStatus || "all";
    waPage = 1;
    list.innerHTML = '<li class="ad-wa-empty">Loading…</li>';
    const pager = document.getElementById("ad-wa-pager");
    if (pager) {
      pager.hidden = true;
      pager.innerHTML = "";
    }
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
      renderWaMessages(data.messages || [], 1);
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

  function clearWaLocalSchedule() {
    writeWaSchedule([]);
  }

  function armWaScheduleTimer() {
    window.clearTimeout(waScheduleTimer);
    // Keep a gentle server tick while the dashboard tab is open (cron covers offline).
    waScheduleTimer = window.setTimeout(() => {
      processWaSchedule().finally(armWaScheduleTimer);
    }, 20000);
  }

  async function queueWaSchedule(entry) {
    const phone = normalizeWaPhone(entry.to);
    const everyMs = Math.max(0, Number(entry.everyMs) || 0);
    const payload = {
      items: [{
        to: phone || entry.to,
        body: String(entry.body || ""),
        priority: String(entry.priority ?? "10"),
        at: Number(entry.at),
        everyMs,
      }],
    };
    const res = await fetch("whatsapp-api.php?action=schedule", {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || !data.ok) {
      throw new Error(data.message || "Could not save schedule on server.");
    }
    // Prefer server queue; drop browser-only leftovers for this phone.
    const local = readWaSchedule().filter((item) => normalizeWaPhone(item.to) !== phone);
    writeWaSchedule(local);
    return data;
  }

  async function processWaSchedule() {
    try {
      const res = await fetch("whatsapp-api.php?action=process-schedule&limit=25", {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/json" },
        body: "{}",
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data.ok) return data;
      if (Number(data.sent) > 0) {
        loadWhatsappMessages(waCurrentStatus);
        const first = Array.isArray(data.results)
          ? data.results.find((r) => r && r.ok)
          : null;
        if (first) {
          const next = first.nextAt
            ? ` · next ${formatWaScheduleTime(first.nextAt)}`
            : "";
          await waSwalSent("Sent", `Automatic message sent to ${first.to}${next}`);
          if (first.nextAt) {
            setWaMsg(`Sent to ${first.to}${next}`);
          }
        }
      }
      return data;
    } catch (_) {
      return null;
    }
  }

  async function migrateLocalWaScheduleToServer() {
    const local = readWaSchedule().filter((item) => item && Number(item.at) > 0 && item.to && item.body);
    if (!local.length) return;
    try {
      const res = await fetch("whatsapp-api.php?action=schedule", {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          items: local.map((item) => ({
            to: normalizeWaPhone(item.to) || item.to,
            body: String(item.body || ""),
            priority: String(item.priority ?? "10"),
            at: Number(item.at),
            everyMs: Math.max(0, Number(item.everyMs) || 0),
          })),
        }),
      });
      const data = await res.json().catch(() => ({}));
      if (res.ok && data.ok) {
        clearWaLocalSchedule();
      }
    } catch (_) {
      /* keep local until next visit */
    }
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
    // Short debounce while typing the phone — the real send delay starts AFTER this.
    waAutoTimer = window.setTimeout(async () => {
      const delay = delayMsFromUi();
      const targets = collectWaTargets();
      const body = getAutoMessageBody();
      const priority = document.getElementById("ad-wa-priority")?.value || "10";
      if (!targets.length) {
        setWaMsg("Bonyeza Save kwanza kuweka namba ya automatic.", true);
        return;
      }
      if (!body) {
        setWaMsg("Andika ujumbe wa automatic kwanza (unaweza kuandika ujumbe wowote).", true);
        return;
      }
      const key = `${targets.join(",")}|${delay}|${priority}|${body}`;
      if (key === waLastAutoSentTo) return;
      try {
        saveAutoMessageBody();
        saveWaDelaySettings();
        saveWaPriority();
        if (delay > 0) {
          const at = Date.now() + delay;
          await syncAutoWaSchedule({
            targets,
            body,
            priority,
            at,
            everyMs: delay,
          });
          waLastAutoSentTo = key;
          setWaMsg(`Automatic ON · ${formatWaIntervalLabel(delay)} · ${targets.join(", ")} · next ${formatWaScheduleTime(at)}`);
          return;
        }
        for (const to of targets) {
          await sendWhatsappMessage({ to, body, priority });
        }
        waLastAutoSentTo = key;
        setWaMsg(`Sent to ${targets.length} number(s).`);
        await waSwalSent("Sent", `Sent to ${targets.length} number(s).`);
      } catch (error) {
        setWaMsg(error.message || String(error), true);
      }
    }, 800);
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

  function bindCrmSection() {
    const form = document.getElementById("ad-crm-form");
    const resultsEl = document.getElementById("ad-crm-results");
    const savedEl = document.getElementById("ad-crm-saved");
    const msgEl = document.getElementById("ad-crm-msg");
    const dbResultsEl = document.getElementById("ad-crm-db-results");
    const dbMsgEl = document.getElementById("ad-crm-db-msg");
    const saveAllBtn = document.getElementById("ad-crm-save-all");
    if (!form || !resultsEl) return;

    let crmItems = [];
    let crmSavedItems = [];
    let crmDbItems = [];
    let crmTab = "results";
    let crmDbPlatform = "all";
    const savedIds = new Set();
    let crmChart = null;

    if (!window.crmAvatarFallback) {
      window.crmAvatarFallback = function crmAvatarFallback(img) {
        if (!img) return;
        let list = [];
        try {
          list = JSON.parse(img.getAttribute("data-fallbacks") || "[]");
        } catch (_) {
          list = [];
        }
        if (!Array.isArray(list)) list = [];
        while (list.length) {
          const next = String(list.shift() || "").trim();
          if (!next || next === img.getAttribute("src")) continue;
          img.setAttribute("data-fallbacks", JSON.stringify(list));
          img.onerror = function () {
            window.crmAvatarFallback(img);
          };
          img.src = next;
          return;
        }
        const ph = document.createElement("div");
        ph.className = `${img.className} ad-crm-avatar--ph`.replace(/\s+/g, " ").trim();
        ph.setAttribute("aria-hidden", "true");
        ph.innerHTML = '<i class="fa-solid fa-user"></i>';
        img.replaceWith(ph);
      };
    }

    function setCrmMsg(text, type = "", el = msgEl) {
      if (!el) return;
      const t = String(text || "").trim();
      if (!t) {
        el.hidden = true;
        el.textContent = "";
        el.className = "ad-msg";
        return;
      }
      el.hidden = false;
      el.textContent = t;
      el.className = "ad-msg" + (type ? ` ad-msg--${type}` : "");
    }

    function formatList(list) {
      const arr = Array.isArray(list) ? list.filter(Boolean) : [];
      return arr.length ? arr.map((x) => esc(x)).join(", ") : "—";
    }

    function platformIcon(platform) {
      if (platform === "instagram") return "fa-brands fa-instagram";
      if (platform === "linkedin") return "fa-brands fa-linkedin";
      return "fa-brands fa-facebook";
    }

    function platformLabel(platform) {
      if (platform === "instagram") return "Instagram";
      if (platform === "linkedin") return "LinkedIn";
      if (platform === "all") return "All";
      return "Facebook";
    }

    function getSelectedPlatform() {
      return String(document.getElementById("ad-crm-platform-value")?.value || "facebook").trim() || "facebook";
    }

    function setSelectedPlatform(platform) {
      const value = ["facebook", "instagram", "linkedin"].includes(platform) ? platform : "facebook";
      const hidden = document.getElementById("ad-crm-platform-value");
      if (hidden) hidden.value = value;
      document.querySelectorAll(".ad-crm-platform").forEach((btn) => {
        const active = btn.dataset.platform === value;
        btn.classList.toggle("is-active", active);
        btn.setAttribute("aria-pressed", active ? "true" : "false");
      });
      const queryEl = document.getElementById("ad-crm-query");
      if (queryEl) {
        queryEl.placeholder =
          value === "linkedin"
            ? "e.g. software engineer, accountant, hotel manager…"
            : "e.g. hotel, restaurant, salon…";
      }
    }

    document.querySelectorAll(".ad-crm-platform").forEach((btn) => {
      btn.addEventListener("click", () => setSelectedPlatform(btn.dataset.platform || "facebook"));
    });
    setSelectedPlatform(getSelectedPlatform());

    function crmImgSrc(url) {
      const src = String(url || "").trim();
      if (!src) return "";
      if (src.startsWith("crm-api.php?")) return src;
      if (/^https?:\/\//i.test(src)) {
        return `crm-api.php?action=image&u=${encodeURIComponent(src)}`;
      }
      return src;
    }

    function initialsAvatar(item) {
      const label = String(item.name || item.username || item.title || "Lead").trim() || "Lead";
      return `https://ui-avatars.com/api/?name=${encodeURIComponent(label)}&background=1a3352&color=fff&size=128&bold=true`;
    }

    function imageCandidateList(item) {
      const out = [];
      const push = (u) => {
        const src = crmImgSrc(u);
        if (src && !out.includes(src)) out.push(src);
      };
      if (Array.isArray(item.imageCandidates)) item.imageCandidates.forEach(push);
      push(item.profilePicture);
      push(item.profilePictureRaw);
      if (Array.isArray(item.thumbnails)) item.thumbnails.forEach(push);
      push(initialsAvatar(item));
      return out;
    }

    function avatarHtml(item, sizeClass = "ad-crm-avatar") {
      const candidates = imageCandidateList(item);
      if (!candidates.length) {
        return `<div class="${sizeClass} ad-crm-avatar--ph" aria-hidden="true"><i class="fa-solid fa-user"></i></div>`;
      }
      const first = candidates[0];
      const rest = candidates.slice(1);
      return `<img class="${sizeClass}" src="${esc(first)}" alt="" loading="lazy" referrerpolicy="no-referrer" data-fallbacks="${esc(JSON.stringify(rest))}" onerror="window.crmAvatarFallback && window.crmAvatarFallback(this)" />`;
    }

    function thumbsHtml(item, limit = 4, cls = "ad-crm-thumbs") {
      const thumbs = Array.isArray(item.thumbnails) ? item.thumbnails.filter(Boolean).slice(0, limit) : [];
      if (!thumbs.length) return "";
      return `<div class="${cls}">${thumbs
        .map((u) => {
          const src = crmImgSrc(u);
          return src
            ? `<img src="${esc(src)}" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="this.remove()" />`
            : "";
        })
        .join("")}</div>`;
    }

    function openUrl(item) {
      return item.pageUrl || item.linkedinUrl || item.instagramUrl || item.facebookUrl || item.companyUrl || "";
    }

    function updateSaveAllState() {
      if (!saveAllBtn) return;
      saveAllBtn.disabled = !crmItems.length;
    }

    function cardHtml(item, idx, mode) {
      const phone = item.phone || (Array.isArray(item.phones) && item.phones[0]) || "";
      const email = item.email || (Array.isArray(item.emails) && item.emails[0]) || "";
      const cats = Array.isArray(item.categories) ? item.categories.slice(0, 2).join(" · ") : "";
      const desc = String(item.description || "").trim();
      const platform = String(item.platform || "facebook");
      const alreadySaved = savedIds.has(String(item.id || ""));
      const link = openUrl(item);
      const viewAttr =
        mode === "saved"
          ? `data-crm-saved-view="${idx}"`
          : mode === "database"
            ? `data-crm-db-view="${idx}"`
            : `data-crm-view="${idx}"`;
      const actionBtn =
        mode === "saved" || mode === "database"
          ? `<button type="button" class="ad-btn ad-btn--ghost ad-btn--delete" data-crm-delete="${esc(item.id || "")}"><i class="fa-solid fa-trash"></i> Delete</button>`
          : `<button type="button" class="ad-btn ad-btn--ghost" data-crm-save="${idx}" ${alreadySaved ? "disabled" : ""}><i class="fa-solid fa-bookmark"></i> ${alreadySaved ? "Saved" : "Save"}</button>`;

      return `<article class="ad-crm-card" data-crm-idx="${idx}">
        <div class="ad-crm-card-top">
          ${avatarHtml(item)}
          <div class="ad-crm-card-meta">
            <h3>${esc(item.name || item.title || "Lead")}</h3>
            <p><i class="${platformIcon(platform)}"></i> ${esc(cats || item.username || item.address || platformLabel(platform))}</p>
          </div>
        </div>
        ${thumbsHtml(item)}
        <div class="ad-crm-chips">
          ${phone ? `<span class="ad-crm-chip"><i class="fa-solid fa-phone"></i> ${esc(phone)}</span>` : ""}
          ${email ? `<span class="ad-crm-chip"><i class="fa-solid fa-envelope"></i> ${esc(email)}</span>` : ""}
          ${item.likes != null ? `<span class="ad-crm-chip"><i class="fa-solid fa-thumbs-up"></i> ${esc(item.likes)}</span>` : ""}
          ${item.followers != null ? `<span class="ad-crm-chip"><i class="fa-solid fa-users"></i> ${esc(item.followers)}</span>` : ""}
        </div>
        ${desc ? `<p class="ad-crm-desc">${esc(desc)}</p>` : ""}
        <div class="ad-crm-card-actions">
          <button type="button" class="ad-btn ad-btn--ghost ad-btn--view" ${viewAttr}>
            <i class="fa-solid fa-eye"></i> View
          </button>
          ${actionBtn}
          ${link
            ? `<a class="ad-btn ad-btn--ghost" href="${esc(link)}" target="_blank" rel="noopener noreferrer"><i class="${platformIcon(platform)}"></i> Open</a>`
            : ""}
        </div>
      </article>`;
    }

    function renderCrmResults(items) {
      crmItems = Array.isArray(items) ? items : [];
      updateSaveAllState();
      if (!crmItems.length) {
        resultsEl.innerHTML = `<div class="ad-crm-empty">No results found. Try another search.</div>`;
        return;
      }
      resultsEl.innerHTML = crmItems.map((item, idx) => cardHtml(item, idx, "results")).join("");
    }

    function renderDatabaseResults(items) {
      crmDbItems = Array.isArray(items) ? items : [];
      if (!dbResultsEl) return;
      const filtered =
        crmDbPlatform === "all"
          ? crmDbItems
          : crmDbItems.filter((item) => String(item.platform || "") === crmDbPlatform);
      if (!filtered.length) {
        dbResultsEl.innerHTML = `<div class="ad-crm-empty">No saved ${crmDbPlatform === "all" ? "" : platformLabel(crmDbPlatform) + " "}leads in the database yet.</div>`;
        setCrmMsg(crmDbItems.length ? `${filtered.length} shown · ${crmDbItems.length} total saved` : "Database is empty.", "", dbMsgEl);
        return;
      }
      dbResultsEl.innerHTML = filtered.map((item, idx) => cardHtml(item, idx, "database")).join("");
      if (crmDbPlatform === "all") {
        setCrmMsg(`${filtered.length} saved lead${filtered.length === 1 ? "" : "s"}`, "success", dbMsgEl);
      } else {
        setCrmMsg(`${filtered.length} ${platformLabel(crmDbPlatform)} lead${filtered.length === 1 ? "" : "s"}`, "success", dbMsgEl);
      }
    }

    function renderSavedResults(items) {
      crmSavedItems = Array.isArray(items) ? items : [];
      savedIds.clear();
      crmSavedItems.forEach((item) => {
        if (item && item.id) savedIds.add(String(item.id));
      });
      if (savedEl) {
        if (!crmSavedItems.length) {
          savedEl.innerHTML = `<div class="ad-crm-empty">No saved leads yet.</div>`;
        } else {
          savedEl.innerHTML = crmSavedItems.map((item, idx) => cardHtml(item, idx, "saved")).join("");
        }
      }
      if (crmTab === "results") {
        renderCrmResults(crmItems);
      }
      renderDatabaseResults(crmSavedItems);
    }

    async function openCrmPopup(item) {
      if (!item) return;
      const phones = Array.isArray(item.phones) && item.phones.length ? item.phones : item.phone ? [item.phone] : [];
      const emails = Array.isArray(item.emails) && item.emails.length ? item.emails : item.email ? [item.email] : [];
      const pageLink = openUrl(item);
      const platform = String(item.platform || "facebook");
      const html = `<div class="ad-crm-popup">
        <div class="ad-crm-popup-hero">
          ${avatarHtml(item, "ad-crm-avatar ad-crm-avatar--lg")}
          <div>
            <h3>${esc(item.name || item.title || "Lead")}</h3>
            <p><i class="${platformIcon(platform)}"></i> ${esc(
              (Array.isArray(item.categories) ? item.categories.join(" · ") : "") || item.username || platformLabel(platform)
            )}</p>
          </div>
        </div>
        <div class="ad-crm-popup-grid">
          <div class="ad-crm-popup-row"><strong>Platform</strong><span>${esc(platformLabel(platform))}</span></div>
          <div class="ad-crm-popup-row"><strong>Username</strong><span>${esc(item.username || "—")}</span></div>
          ${platform === "linkedin" ? `<div class="ad-crm-popup-row"><strong>Job title</strong><span>${esc(item.title || "—")}</span></div>` : ""}
          ${platform === "linkedin" ? `<div class="ad-crm-popup-row"><strong>Employment</strong><span>${esc(item.employmentType || "—")}</span></div>` : ""}
          ${platform === "linkedin" ? `<div class="ad-crm-popup-row"><strong>Seniority</strong><span>${esc(item.seniorityLevel || "—")}</span></div>` : ""}
          ${platform === "linkedin" ? `<div class="ad-crm-popup-row"><strong>Applicants</strong><span>${esc(item.applicantsCount || "—")}</span></div>` : ""}
          ${platform === "linkedin" ? `<div class="ad-crm-popup-row"><strong>Salary</strong><span>${esc(item.priceRange || "—")}</span></div>` : ""}
          ${platform === "linkedin" && item.jobPosterName ? `<div class="ad-crm-popup-row"><strong>Posted by</strong><span>${esc(item.jobPosterName)}${item.jobPosterTitle ? ` · ${esc(item.jobPosterTitle)}` : ""}</span></div>` : ""}
          <div class="ad-crm-popup-row"><strong>Phone</strong><span>${formatList(phones)}</span></div>
          <div class="ad-crm-popup-row"><strong>Email</strong><span>${formatList(emails)}</span></div>
          <div class="ad-crm-popup-row"><strong>Website</strong><span>${
            item.website
              ? `<a href="${esc(/^https?:\/\//i.test(item.website) ? item.website : `https://${item.website}`)}" target="_blank" rel="noopener noreferrer">${esc(item.website)}</a>`
              : "—"
          }</span></div>
          <div class="ad-crm-popup-row"><strong>Address</strong><span>${esc(item.address || "—")}</span></div>
          <div class="ad-crm-popup-row"><strong>Likes</strong><span>${item.likes != null ? esc(item.likes) : "—"}</span></div>
          <div class="ad-crm-popup-row"><strong>Followers</strong><span>${item.followers != null ? esc(item.followers) : "—"}</span></div>
          <div class="ad-crm-popup-row"><strong>Posts</strong><span>${item.postsCount != null ? esc(item.postsCount) : "—"}</span></div>
          <div class="ad-crm-popup-row"><strong>Rating</strong><span>${esc(
            item.rating || (item.ratingOverall != null ? String(item.ratingOverall) : "") || "—"
          )}${item.ratingCount != null ? ` (${esc(item.ratingCount)} reviews)` : ""}</span></div>
          <div class="ad-crm-popup-row"><strong>Messenger</strong><span>${esc(item.messenger || "—")}</span></div>
          <div class="ad-crm-popup-row"><strong>Price range</strong><span>${esc(item.priceRange || "—")}</span></div>
          <div class="ad-crm-popup-row"><strong>Created</strong><span>${esc(item.creationDate || "—")}</span></div>
          <div class="ad-crm-popup-row"><strong>Ad status</strong><span>${esc(item.adStatus || "—")}</span></div>
          <div class="ad-crm-popup-row"><strong>Page ID</strong><span>${esc(item.pageId || "—")}</span></div>
          <div class="ad-crm-popup-row"><strong>Profile</strong><span>${
            pageLink
              ? `<a href="${esc(pageLink)}" target="_blank" rel="noopener noreferrer">${esc(pageLink)}</a>`
              : "—"
          }</span></div>
          <div class="ad-crm-popup-row"><strong>Description</strong><span>${esc(item.description || "—")}</span></div>
        </div>
        ${thumbsHtml(item, 8, "ad-crm-popup-thumbs")}
      </div>`;

      if (!window.Swal || typeof window.Swal.fire !== "function") {
        window.alert(item.name || "CRM lead");
        return;
      }
      await window.Swal.fire({
        title: "Lead details",
        html,
        width: "520px",
        confirmButtonText: "Close",
        confirmButtonColor: "#1a3352",
        buttonsStyling: true,
        customClass: { popup: "ad-crm-swal-popup" },
      });
    }

    async function showSearchChart(items, platform, query, location) {
      if (!window.Swal || typeof window.Swal.fire !== "function") return;
      const list = Array.isArray(items) ? items : [];
      const labels = list.map((item, i) => {
        const name = String(item.name || item.title || `Lead ${i + 1}`);
        return name.length > 16 ? `${name.slice(0, 14)}…` : name;
      });
      const followers = list.map((item) => Number(item.followers || 0));
      const likes = list.map((item) => Number(item.likes || 0));
      const withContact = list.map((item) => (item.phone || item.email ? 1 : 0));

      await window.Swal.fire({
        title: "Search insights",
        html: `<div class="ad-crm-chart-wrap">
          <p class="ad-crm-chart-sub">${esc(platformLabel(platform))} · ${esc(String(list.length))} results${location ? ` · ${esc(location)}` : ""}${query ? ` · “${esc(query)}”` : ""}</p>
          <div id="ad-crm-search-chart"></div>
        </div>`,
        width: 860,
        confirmButtonText: "Close",
        confirmButtonColor: "#1a3352",
        buttonsStyling: true,
        showConfirmButton: true,
        showCloseButton: false,
        timer: undefined,
        timerProgressBar: false,
        allowOutsideClick: false,
        allowEscapeKey: false,
        allowEnterKey: true,
        customClass: { popup: "ad-crm-swal-popup ad-crm-swal-square ad-crm-swal-chart" },
        didOpen: () => {
          // Keep this modal open until Close — clear any leftover toast timers.
          try {
            if (typeof window.Swal.stopTimer === "function") window.Swal.stopTimer();
          } catch (_) {
            /* ignore */
          }
          const el = document.getElementById("ad-crm-search-chart");
          if (!el || typeof ApexCharts === "undefined") return;
          if (crmChart) {
            try { crmChart.destroy(); } catch (_) { /* ignore */ }
            crmChart = null;
          }
          crmChart = new ApexCharts(el, {
            chart: {
              type: "line",
              height: 280,
              toolbar: { show: false },
              zoom: { enabled: false },
              fontFamily: "inherit",
            },
            stroke: { curve: "smooth", width: 3 },
            colors: ["#1a3352", "#0a66c2", "#16a34a"],
            series: [
              { name: "Followers", data: followers },
              { name: "Likes", data: likes },
              { name: "Has contact", data: withContact },
            ],
            xaxis: {
              categories: labels,
              labels: { rotate: -35, style: { fontSize: "11px" } },
            },
            yaxis: {
              labels: { formatter: (v) => String(Math.round(Number(v) || 0)) },
            },
            legend: { position: "top" },
            grid: { borderColor: "#e2e8f0" },
            markers: { size: 4 },
            tooltip: { shared: true },
          });
          crmChart.render();
        },
        willClose: () => {
          if (crmChart) {
            try { crmChart.destroy(); } catch (_) { /* ignore */ }
            crmChart = null;
          }
        },
      });
    }

    async function loadSavedLeads() {
      try {
        const res = await fetch("crm-api.php?action=saved", { credentials: "same-origin" });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data.ok) throw new Error(data.message || "Could not load saved leads.");
        const items = Array.isArray(data.items) ? data.items : [];
        renderSavedResults(items);
        return items;
      } catch (error) {
        if (savedEl) {
          savedEl.innerHTML = `<div class="ad-crm-empty">${esc(error.message || "Could not load saved leads.")}</div>`;
        }
        if (dbResultsEl) {
          dbResultsEl.innerHTML = `<div class="ad-crm-empty">${esc(error.message || "Could not load database.")}</div>`;
        }
        return [];
      }
    }

    function setCrmTab(tab) {
      crmTab = tab === "saved" ? "saved" : "results";
      document.querySelectorAll("#ad-section-crm .ad-crm-tab").forEach((btn) => {
        btn.classList.toggle("is-active", btn.dataset.crmTab === crmTab);
      });
      resultsEl.hidden = crmTab !== "results";
      if (savedEl) savedEl.hidden = crmTab !== "saved";
      if (crmTab === "saved") loadSavedLeads();
    }

    document.querySelectorAll("#ad-section-crm .ad-crm-tab").forEach((btn) => {
      btn.addEventListener("click", () => setCrmTab(btn.dataset.crmTab || "results"));
    });

    document.querySelectorAll("[data-crm-db-platform]").forEach((btn) => {
      btn.addEventListener("click", () => {
        crmDbPlatform = btn.dataset.crmDbPlatform || "all";
        document.querySelectorAll("[data-crm-db-platform]").forEach((b) => {
          b.classList.toggle("is-active", b.dataset.crmDbPlatform === crmDbPlatform);
        });
        renderDatabaseResults(crmDbItems);
      });
    });

    document.getElementById("ad-crm-db-refresh")?.addEventListener("click", () => {
      loadSavedLeads();
    });
    document.addEventListener("crm:load-database", () => {
      loadSavedLeads();
      void refreshExtraEmailHint();
    });

    async function fetchEmailTemplate() {
      const res = await fetch("crm-api.php?action=email-template", { credentials: "same-origin" });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data.ok) throw new Error(data.message || "Could not load email message.");
      return data;
    }

    async function openEmailMessageEditor() {
      if (!window.Swal || typeof window.Swal.fire !== "function") {
        notify("SweetAlert is required to edit the message.", "error", { force: true });
        return;
      }
      let data;
      try {
        data = await fetchEmailTemplate();
      } catch (error) {
        notify(error.message || "Could not load email message.", "error", { force: true });
        return;
      }
      const t = data.template || {};
      const result = await window.Swal.fire({
        title: "Set email message",
        width: 920,
        focusConfirm: false,
        showCancelButton: true,
        confirmButtonText: "Save message",
        cancelButtonText: "Cancel",
        confirmButtonColor: "#1a3352",
        buttonsStyling: true,
        customClass: {
          popup: "ad-crm-swal-popup ad-crm-swal-square ad-crm-swal-email",
        },
        html: `<div class="ad-crm-email-editor">
          <label><span>Subject</span>
            <input id="ad-crm-email-subject" type="text" value="${esc(t.subject || "")}" />
          </label>
          <div class="ad-crm-email-grid">
            <label><span>Header title</span>
              <input id="ad-crm-email-header-title" type="text" value="${esc(t.headerTitle || "")}" />
            </label>
            <label><span>Header subtitle</span>
              <input id="ad-crm-email-header-sub" type="text" value="${esc(t.headerSubtitle || "")}" />
            </label>
          </div>
          <label><span>Body title</span>
            <input id="ad-crm-email-body-title" type="text" value="${esc(t.bodyTitle || "")}" />
          </label>
          <label><span>Greeting</span>
            <input id="ad-crm-email-greeting" type="text" value="${esc(t.greeting || "")}" />
          </label>
          <label><span>Message</span>
            <textarea id="ad-crm-email-body">${esc(t.body || "")}</textarea>
          </label>
          <div class="ad-crm-email-grid">
            <label><span>Sign-off</span>
              <input id="ad-crm-email-signoff" type="text" value="${esc(t.signOff || "")}" />
            </label>
            <label><span>Your name</span>
              <input id="ad-crm-email-signname" type="text" value="${esc(t.signName || "")}" />
            </label>
          </div>
          <label><span>Your role</span>
            <input id="ad-crm-email-signrole" type="text" value="${esc(t.signRole || "")}" />
          </label>
          <label><span>Footer line</span>
            <input id="ad-crm-email-footer" type="text" value="${esc(t.footerLine || "")}" />
          </label>
          <div class="ad-crm-email-grid">
            <label><span>Button text</span>
              <input id="ad-crm-email-cta-text" type="text" value="${esc(t.ctaText || "Get in touch")}" />
            </label>
            <label><span>Button link</span>
              <input id="ad-crm-email-cta-url" type="text" value="${esc(t.ctaUrl || "https://makarious.legitconsult.co.tz/")}" />
            </label>
          </div>
          <label><span>Contact phones (use | between numbers)</span>
            <input id="ad-crm-email-phones" type="text" value="${esc(t.contactPhones || "+255 715 296 092 | +255 622 045 972")}" />
          </label>
          <div class="ad-crm-email-grid">
            <label><span>Contact email</span>
              <input id="ad-crm-email-contact-email" type="text" value="${esc(t.contactEmail || "stevenabalwambo@gmail.com")}" />
            </label>
            <label><span>Contact website</span>
              <input id="ad-crm-email-contact-web" type="text" value="${esc(t.contactWebsite || "https://makarious.legitconsult.co.tz/")}" />
            </label>
          </div>
          <label><span>Organization line</span>
            <input id="ad-crm-email-contact-org" type="text" value="${esc(t.contactOrg || "Digital Matrix Technology · Tanzania")}" />
          </label>
        </div>`,
        preConfirm: () => {
          const subject = String(document.getElementById("ad-crm-email-subject")?.value || "").trim();
          const body = String(document.getElementById("ad-crm-email-body")?.value || "").trim();
          if (subject.length < 3) {
            window.Swal.showValidationMessage("Enter a subject.");
            return false;
          }
          if (body.length < 10) {
            window.Swal.showValidationMessage("Enter the message body.");
            return false;
          }
          return {
            subject,
            body,
            headerTitle: String(document.getElementById("ad-crm-email-header-title")?.value || "").trim(),
            headerSubtitle: String(document.getElementById("ad-crm-email-header-sub")?.value || "").trim(),
            bodyTitle: String(document.getElementById("ad-crm-email-body-title")?.value || "").trim(),
            greeting: String(document.getElementById("ad-crm-email-greeting")?.value || "").trim(),
            signOff: String(document.getElementById("ad-crm-email-signoff")?.value || "").trim(),
            signName: String(document.getElementById("ad-crm-email-signname")?.value || "").trim(),
            signRole: String(document.getElementById("ad-crm-email-signrole")?.value || "").trim(),
            footerLine: String(document.getElementById("ad-crm-email-footer")?.value || "").trim(),
            ctaText: String(document.getElementById("ad-crm-email-cta-text")?.value || "").trim(),
            ctaUrl: String(document.getElementById("ad-crm-email-cta-url")?.value || "").trim(),
            contactPhones: String(document.getElementById("ad-crm-email-phones")?.value || "").trim(),
            contactEmail: String(document.getElementById("ad-crm-email-contact-email")?.value || "").trim(),
            contactWebsite: String(document.getElementById("ad-crm-email-contact-web")?.value || "").trim(),
            contactOrg: String(document.getElementById("ad-crm-email-contact-org")?.value || "").trim(),
          };
        },
      });
      if (!result.isConfirmed || !result.value) return;
      try {
        const res = await fetch("crm-api.php?action=email-template", {
          method: "POST",
          credentials: "same-origin",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(result.value),
        });
        const saved = await res.json().catch(() => ({}));
        if (!res.ok || !saved.ok) throw new Error(saved.message || "Could not save message.");
        notify("Email message saved.", "success", { toast: true, force: true });
      } catch (error) {
        notify(error.message || "Could not save message.", "error", { force: true });
      }
    }

    async function loadSendSelection() {
      const res = await fetch("crm-api.php?action=send-selection", { credentials: "same-origin" });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data.ok) return [];
      return Array.isArray(data.emails) ? data.emails : [];
    }

    async function saveSendSelection(emails) {
      const list = Array.isArray(emails) ? emails : [];
      const res = await fetch("crm-api.php?action=send-selection", {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ emails: list, clear: list.length === 0 }),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data.ok) throw new Error(data.message || "Could not save selection.");
      return data;
    }

    function collectSendCandidates(extraEmails) {
      const filtered =
        crmDbPlatform === "all"
          ? crmDbItems
          : crmDbItems.filter((item) => String(item.platform || "") === crmDbPlatform);
      const rows = [];
      const seen = new Set();
      filtered.forEach((item) => {
        const name = String(item.name || item.title || item.username || "Lead");
        const platform = platformLabel(item.platform || "");
        const list = [];
        if (item.email) list.push(String(item.email));
        if (Array.isArray(item.emails)) {
          item.emails.forEach((e) => {
            if (e) list.push(String(e));
          });
        }
        list.forEach((raw) => {
          const email = String(raw || "").trim().toLowerCase();
          if (!email || seen.has(email)) return;
          seen.add(email);
          rows.push({
            email,
            label: name,
            meta: platform || "Lead",
            source: "lead",
            id: item.id || "",
          });
        });
      });
      (Array.isArray(extraEmails) ? extraEmails : []).forEach((raw) => {
        const email = String(raw || "").trim().toLowerCase();
        if (!email || seen.has(email)) return;
        seen.add(email);
        rows.push({
          email,
          label: "Extra email",
          meta: "Edit email list",
          source: "extra",
          id: "",
        });
      });
      return rows;
    }

    function readSelectedEmailsFromPopup() {
      return Array.from(document.querySelectorAll(".ad-crm-send-pick:checked"))
        .map((el) => String(el.value || "").trim().toLowerCase())
        .filter(Boolean);
    }

    async function sendDatabaseEmails() {
      if (!window.Swal || typeof window.Swal.fire !== "function") {
        notify("SweetAlert is required to select emails.", "error", { force: true });
        return;
      }

      let extraEmails = [];
      let savedSelection = [];
      try {
        extraEmails = await loadExtraEmails();
      } catch (_) {
        extraEmails = [];
      }
      try {
        savedSelection = await loadSendSelection();
      } catch (_) {
        savedSelection = [];
      }

      const candidates = collectSendCandidates(extraEmails);
      if (!candidates.length) {
        notify("No emails to send. Add lead emails or use Edit email.", "warning", { force: true });
        return;
      }

      const savedSet = new Set(savedSelection.map((e) => String(e).toLowerCase()));
      const hasSaved = savedSet.size > 0;
      const rowsHtml = candidates
        .map((row, idx) => {
          const checked = hasSaved ? savedSet.has(row.email) : true;
          return `<label class="ad-crm-send-row">
            <input type="checkbox" class="ad-crm-send-pick" value="${esc(row.email)}" data-idx="${idx}" ${checked ? "checked" : ""} />
            <span class="ad-crm-send-row-main">
              <strong>${esc(row.email)}</strong>
              <small>${esc(row.label)} · ${esc(row.meta)}</small>
            </span>
          </label>`;
        })
        .join("");

      const result = await window.Swal.fire({
        title: "Select emails to send",
        width: 640,
        focusConfirm: false,
        showCancelButton: true,
        showDenyButton: true,
        confirmButtonText: "Send selected",
        denyButtonText: "Save selection",
        cancelButtonText: "Cancel",
        confirmButtonColor: "#1a3352",
        denyButtonColor: "#0f766e",
        buttonsStyling: true,
        allowOutsideClick: false,
        customClass: {
          popup: "ad-crm-swal-popup ad-crm-swal-square ad-crm-swal-email",
        },
        html: `<div class="ad-crm-send-picker">
          <p class="ad-crm-email-hint">Tick the emails you want, then <strong>Save selection</strong> or <strong>Send selected</strong>.</p>
          <div class="ad-crm-send-picker-tools">
            <button type="button" class="ad-btn ad-btn--ghost" id="ad-crm-send-select-all">Select all</button>
            <button type="button" class="ad-btn ad-btn--ghost" id="ad-crm-send-clear-all">Clear</button>
            <span class="ad-crm-send-picker-count" id="ad-crm-send-pick-count"></span>
          </div>
          <div class="ad-crm-send-picker-list">${rowsHtml}</div>
        </div>`,
        didOpen: () => {
          const updateCount = () => {
            const n = readSelectedEmailsFromPopup().length;
            const el = document.getElementById("ad-crm-send-pick-count");
            if (el) el.textContent = `${n} selected`;
          };
          document.getElementById("ad-crm-send-select-all")?.addEventListener("click", () => {
            document.querySelectorAll(".ad-crm-send-pick").forEach((el) => {
              el.checked = true;
            });
            updateCount();
          });
          document.getElementById("ad-crm-send-clear-all")?.addEventListener("click", () => {
            document.querySelectorAll(".ad-crm-send-pick").forEach((el) => {
              el.checked = false;
            });
            updateCount();
          });
          document.querySelectorAll(".ad-crm-send-pick").forEach((el) => {
            el.addEventListener("change", updateCount);
          });
          updateCount();
        },
        preConfirm: () => {
          const emails = readSelectedEmailsFromPopup();
          if (!emails.length) {
            window.Swal.showValidationMessage("Select at least one email to send.");
            return false;
          }
          return { emails, action: "send" };
        },
        preDeny: () => {
          const emails = readSelectedEmailsFromPopup();
          if (!emails.length) {
            window.Swal.showValidationMessage("Select at least one email to save.");
            return false;
          }
          return { emails, action: "save" };
        },
      });

      if (result.isDenied && result.value?.emails) {
        try {
          const saved = await saveSendSelection(result.value.emails);
          notify(saved.message || "Selection saved.", "success", { toast: true, force: true });
          await refreshExtraEmailHint();
        } catch (error) {
          notify(error.message || "Could not save selection.", "error", { force: true });
        }
        return;
      }

      if (!result.isConfirmed || !result.value?.emails?.length) return;

      const selectedEmails = result.value.emails;
      try {
        await saveSendSelection(selectedEmails);
      } catch (_) {
        /* sending can continue even if save fails */
      }

      const selectedLeadIds = candidates
        .filter((row) => row.source === "lead" && selectedEmails.includes(row.email) && row.id)
        .map((row) => row.id);

      showWaitSwal(
        "Sending emails…",
        '<p style="margin:0.35rem 0 0;font-size:0.95rem;font-weight:600;color:#475569">Sending job application emails. Please wait…</p>'
      );
      try {
        const payload = {
          platform: crmDbPlatform,
          emails: selectedEmails,
        };
        if (selectedLeadIds.length) {
          payload.ids = [...new Set(selectedLeadIds)];
        }
        const res = await fetch("crm-api.php?action=send-emails", {
          method: "POST",
          credentials: "same-origin",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(payload),
        });
        const data = await res.json().catch(() => ({}));
        dismissWaitSwal();
        if (!res.ok || !data.ok) {
          const detail = Array.isArray(data.errors) && data.errors.length ? ` ${data.errors[0]}` : "";
          throw new Error((data.message || "Could not send emails.") + detail);
        }
        notify(data.message || "Emails sent.", "success", { force: true });
        setCrmMsg(data.message || "Emails sent.", "success", dbMsgEl);
        await showEmailSendReport(data);
        await refreshExtraEmailHint();
      } catch (error) {
        dismissWaitSwal();
        notify(error.message || "Could not send emails.", "error", { force: true });
      }
    }

    function formatEmailLogTime(iso) {
      const d = new Date(iso || "");
      if (Number.isNaN(d.getTime())) return "—";
      return d.toLocaleString();
    }

    async function showEmailSendReport(data) {
      if (!window.Swal || typeof window.Swal.fire !== "function") return;
      const results = Array.isArray(data.results) ? data.results : [];
      const rows = results
        .map((row) => {
          const ok = String(row.status || "") === "sent";
          return `<li><strong class="${ok ? "ad-crm-status ad-crm-status--sent" : "ad-crm-status ad-crm-status--failed"}">${ok ? "SENT" : "FAILED"}</strong> ${esc(row.to || "")}${row.error ? ` — ${esc(row.error)}` : ""}</li>`;
        })
        .join("");
      await window.Swal.fire({
        title: data.ok ? "Email delivery status" : "Email send failed",
        icon: data.ok ? "success" : "error",
        width: 560,
        html: `<div class="ad-crm-send-results">
          <p><strong>${esc(String(data.sent || 0))}</strong> sent · <strong>${esc(String(data.failed || 0))}</strong> failed<br/>
          From: ${esc(data.fromEmail || "stevenabalwambo@gmail.com")}</p>
          <p style="margin:8px 0 4px;color:#64748b;font-size:0.82rem">Check inbox and spam. Status also appears in Delivery log.</p>
          <ul style="padding-left:1.1rem;margin:0">${rows || "<li>No detail rows.</li>"}</ul>
        </div>`,
        confirmButtonText: "OK",
        confirmButtonColor: "#1a3352",
        customClass: { popup: "ad-crm-swal-popup" },
      });
    }

    async function loadExtraEmails() {
      const res = await fetch("crm-api.php?action=test-emails", { credentials: "same-origin" });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data.ok) throw new Error(data.message || "Could not load emails.");
      return Array.isArray(data.emails) ? data.emails : [];
    }

    async function refreshExtraEmailHint() {
      const hint = document.getElementById("ad-crm-extra-email-hint");
      if (!hint) return;
      try {
        const emails = await loadExtraEmails();
        if (!emails.length) {
          hint.hidden = true;
          hint.textContent = "";
          return;
        }
        hint.hidden = false;
        hint.textContent = `${emails.length} extra email${emails.length === 1 ? "" : "s"} available · use Send emails to select & save.`;
      } catch (_) {
        hint.hidden = true;
      }
    }

    async function openEditEmailsPopup() {
      if (!window.Swal || typeof window.Swal.fire !== "function") {
        notify("SweetAlert is required.", "error", { force: true });
        return;
      }
      let emails = [];
      try {
        emails = await loadExtraEmails();
      } catch (error) {
        notify(error.message || "Could not load emails.", "error", { force: true });
        return;
      }

      const result = await window.Swal.fire({
        title: "Edit email",
        width: 720,
        focusConfirm: false,
        showCancelButton: true,
        confirmButtonText: "Save emails",
        cancelButtonText: "Cancel",
        confirmButtonColor: "#1a3352",
        buttonsStyling: true,
        customClass: {
          popup: "ad-crm-swal-popup ad-crm-swal-square ad-crm-swal-email",
        },
        html: `<div class="ad-crm-email-editor">
          <p class="ad-crm-email-hint" style="margin-bottom:10px">These emails join online lead emails when you click <strong>Send emails</strong>. One per line or comma-separated.</p>
          <label><span>Extra emails</span>
            <textarea id="ad-crm-edit-emails-ta" rows="8" placeholder="you@gmail.com&#10;friend@yahoo.com">${esc(emails.join("\n"))}</textarea>
          </label>
          <div class="ad-crm-mail-import-actions" style="margin-top:10px">
            <label class="ad-btn ad-btn--ghost ad-crm-file-btn">
              <i class="fa-solid fa-upload"></i><span>Upload .txt / .csv</span>
              <input type="file" id="ad-crm-edit-emails-file" accept=".txt,.csv,text/plain,text/csv" hidden />
            </label>
          </div>
        </div>`,
        didOpen: () => {
          const fileInput = document.getElementById("ad-crm-edit-emails-file");
          const ta = document.getElementById("ad-crm-edit-emails-ta");
          fileInput?.addEventListener("change", async (event) => {
            const file = event.target?.files?.[0];
            if (!file || !ta) return;
            try {
              const text = await file.text();
              const existing = String(ta.value || "").trim();
              ta.value = existing ? `${existing}\n${text}` : text;
            } catch (_) {
              /* ignore */
            } finally {
              event.target.value = "";
            }
          });
        },
        preConfirm: () => {
          const text = String(document.getElementById("ad-crm-edit-emails-ta")?.value || "").trim();
          return { emails: text };
        },
      });

      if (!result.isConfirmed) return;
      try {
        const text = String(result.value?.emails || "").trim();
        const res = await fetch("crm-api.php?action=test-emails", {
          method: "POST",
          credentials: "same-origin",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ emails: text, clear: text === "" }),
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data.ok) throw new Error(data.message || "Could not save emails.");
        notify(data.message || "Extra emails saved.", "success", { toast: true, force: true });
        await refreshExtraEmailHint();
      } catch (error) {
        notify(error.message || "Could not save emails.", "error", { force: true });
      }
    }

    async function fetchEmailLogItems() {
      const res = await fetch("crm-api.php?action=email-log", { credentials: "same-origin" });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data.ok) throw new Error(data.message || "Could not load email log.");
      return Array.isArray(data.items) ? data.items : [];
    }

    function emailLogRowsHtml(items) {
      if (!items.length) {
        return `<tr><td colspan="5" class="ad-crm-empty">No sends yet.</td></tr>`;
      }
      return items
        .map((row) => {
          const ok = String(row.status || "") === "sent";
          const sourceLabel =
            row.source === "extra" || row.source === "test"
              ? "Extra"
              : row.leadName || "Lead";
          return `<tr>
            <td>${esc(formatEmailLogTime(row.at))}</td>
            <td>${esc(row.to || "—")}</td>
            <td>${esc(sourceLabel)}</td>
            <td><span class="ad-crm-status ${ok ? "ad-crm-status--sent" : "ad-crm-status--failed"}">${ok ? "Sent" : "Failed"}</span></td>
            <td>${esc(row.error || row.subject || "—")}</td>
          </tr>`;
        })
        .join("");
    }

    async function openDeliveryLogPopup() {
      if (!window.Swal || typeof window.Swal.fire !== "function") {
        notify("SweetAlert is required.", "error", { force: true });
        return;
      }
      let items = [];
      try {
        items = await fetchEmailLogItems();
      } catch (error) {
        notify(error.message || "Could not load email log.", "error", { force: true });
        return;
      }

      await window.Swal.fire({
        title: "Delivery log",
        width: 720,
        confirmButtonText: "Close",
        confirmButtonColor: "#1a3352",
        showDenyButton: true,
        denyButtonText: "Refresh",
        denyButtonColor: "#64748b",
        buttonsStyling: true,
        customClass: {
          popup: "ad-crm-swal-popup ad-crm-swal-square ad-crm-swal-email",
        },
        html: `<div class="ad-crm-email-editor">
          <p class="ad-crm-email-hint" style="margin-bottom:10px">Sent / Failed status for CRM emails. Check inbox and spam too.</p>
          <div class="ad-crm-mail-log-table-wrap ad-crm-mail-log-table-wrap--popup">
            <table class="ad-crm-mail-log-table">
              <thead>
                <tr>
                  <th>Time</th>
                  <th>To</th>
                  <th>Source</th>
                  <th>Status</th>
                  <th>Note</th>
                </tr>
              </thead>
              <tbody id="ad-crm-email-log-body">${emailLogRowsHtml(items)}</tbody>
            </table>
          </div>
        </div>`,
        preDeny: async () => {
          try {
            const fresh = await fetchEmailLogItems();
            const body = document.getElementById("ad-crm-email-log-body");
            if (body) body.innerHTML = emailLogRowsHtml(fresh);
          } catch (error) {
            window.Swal.showValidationMessage(error.message || "Could not refresh.");
            return false;
          }
          return false;
        },
      });
    }

    document.getElementById("ad-crm-email-set")?.addEventListener("click", () => {
      void openEmailMessageEditor();
    });
    document.getElementById("ad-crm-edit-emails")?.addEventListener("click", () => {
      void openEditEmailsPopup();
    });
    document.getElementById("ad-crm-email-send")?.addEventListener("click", () => {
      void sendDatabaseEmails();
    });
    document.getElementById("ad-crm-email-log-open")?.addEventListener("click", () => {
      void openDeliveryLogPopup();
    });

    void refreshExtraEmailHint();

    async function saveLead(idx) {
      const item = crmItems[idx];
      if (!item) return;
      try {
        const res = await fetch("crm-api.php?action=save", {
          method: "POST",
          credentials: "same-origin",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ item }),
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data.ok) throw new Error(data.message || "Could not save lead.");
        if (item.id) savedIds.add(String(item.id));
        notify("Lead saved.", "success", { toast: true, force: true });
        renderCrmResults(crmItems);
        await loadSavedLeads();
      } catch (error) {
        notify(error.message || "Could not save lead.", "error", { force: true });
      }
    }

    async function saveAllLeads() {
      if (!crmItems.length) {
        notify("No search results to save.", "warning", { force: true });
        return;
      }
      if (saveAllBtn) saveAllBtn.disabled = true;
      try {
        const res = await fetch("crm-api.php?action=save-many", {
          method: "POST",
          credentials: "same-origin",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ items: crmItems }),
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data.ok) throw new Error(data.message || "Could not save leads.");
        crmItems.forEach((item) => {
          if (item && item.id) savedIds.add(String(item.id));
        });
        notify(data.message || "All leads saved.", "success", { toast: true, force: true });
        renderCrmResults(crmItems);
        await loadSavedLeads();
      } catch (error) {
        notify(error.message || "Could not save leads.", "error", { force: true });
      } finally {
        updateSaveAllState();
      }
    }

    async function deleteLead(id) {
      const ok = await confirmAction({
        title: "Delete lead?",
        text: "This lead will be removed from the database.",
        confirmButtonText: "Delete",
      });
      if (!ok) return;
      try {
        const res = await fetch("crm-api.php?action=delete", {
          method: "POST",
          credentials: "same-origin",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ id }),
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data.ok) throw new Error(data.message || "Could not delete lead.");
        savedIds.delete(String(id));
        notify("Lead deleted.", "success", { toast: true, force: true });
        await loadSavedLeads();
        renderCrmResults(crmItems);
      } catch (error) {
        notify(error.message || "Could not delete lead.", "error", { force: true });
      }
    }

    saveAllBtn?.addEventListener("click", () => {
      void saveAllLeads();
    });

    resultsEl.addEventListener("click", (event) => {
      const viewBtn = event.target.closest("[data-crm-view]");
      if (viewBtn) {
        const idx = Number(viewBtn.getAttribute("data-crm-view"));
        if (Number.isFinite(idx)) openCrmPopup(crmItems[idx]);
        return;
      }
      const oneSaveBtn = event.target.closest("[data-crm-save]");
      if (oneSaveBtn) {
        const idx = Number(oneSaveBtn.getAttribute("data-crm-save"));
        if (Number.isFinite(idx)) saveLead(idx);
      }
    });

    savedEl?.addEventListener("click", (event) => {
      const viewBtn = event.target.closest("[data-crm-saved-view]");
      if (viewBtn) {
        const idx = Number(viewBtn.getAttribute("data-crm-saved-view"));
        if (Number.isFinite(idx)) openCrmPopup(crmSavedItems[idx]);
        return;
      }
      const delBtn = event.target.closest("[data-crm-delete]");
      if (delBtn) {
        const id = delBtn.getAttribute("data-crm-delete");
        if (id) deleteLead(id);
      }
    });

    dbResultsEl?.addEventListener("click", (event) => {
      const viewBtn = event.target.closest("[data-crm-db-view]");
      if (viewBtn) {
        const idx = Number(viewBtn.getAttribute("data-crm-db-view"));
        const filtered =
          crmDbPlatform === "all"
            ? crmDbItems
            : crmDbItems.filter((item) => String(item.platform || "") === crmDbPlatform);
        if (Number.isFinite(idx)) openCrmPopup(filtered[idx]);
        return;
      }
      const delBtn = event.target.closest("[data-crm-delete]");
      if (delBtn) {
        const id = delBtn.getAttribute("data-crm-delete");
        if (id) deleteLead(id);
      }
    });

    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      const query = String(document.getElementById("ad-crm-query")?.value || "").trim();
      const location = String(document.getElementById("ad-crm-location")?.value || "").trim();
      const platform = getSelectedPlatform();
      const limit = Number(document.getElementById("ad-crm-limit")?.value || 12);
      if (query.length < 2) {
        setCrmMsg("Enter a search term (at least 2 characters).", "error");
        return;
      }

      setCrmTab("results");
      const btn = document.getElementById("ad-crm-search-btn");
      if (btn) btn.disabled = true;
      const label = platformLabel(platform);
      setCrmMsg(`Searching ${label}…`);
      resultsEl.innerHTML = `<div class="ad-crm-loading"><i class="fa-solid fa-spinner fa-spin"></i> Searching ${esc(label)}…</div>`;
      showWaitSwal(
        "Searching…",
        `<p style="margin:0.35rem 0 0;font-size:0.95rem;font-weight:600;color:#475569">Fetching ${esc(label)} results. Please wait…</p>`
      );

      try {
        const res = await fetch("crm-api.php?action=search", {
          method: "POST",
          credentials: "same-origin",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ query, location, limit, platform }),
        });
        const data = await res.json().catch(() => ({}));
        dismissWaitSwal();
        if (!res.ok || !data.ok) {
          throw new Error(data.message || "CRM search failed.");
        }
        const items = Array.isArray(data.items) ? data.items : [];
        setCrmMsg(`${items.length} result${items.length === 1 ? "" : "s"} · ${label}${location ? ` · ${location}` : ""}`, "success");
        renderCrmResults(items);
        if (items.length) {
          // Show chart first (no timed toast) so SweetAlert toast timer cannot auto-close it.
          await showSearchChart(items, platform, query, location);
          notify(`Found ${items.length} ${label} results.`, "success", { toast: true, force: true });
        }
      } catch (error) {
        dismissWaitSwal();
        resultsEl.innerHTML = `<div class="ad-crm-empty">${esc(error.message || "CRM search failed.")}</div>`;
        setCrmMsg(error.message || "CRM search failed.", "error");
        notify(error.message || "CRM search failed.", "error", { force: true });
        updateSaveAllState();
      } finally {
        if (btn) btn.disabled = false;
      }
    });

    loadSavedLeads();
  }

  function bindWhatsappSection() {
    if (!document.getElementById("ad-section-whatsapp")) return;
    loadWaHidden();
    loadWaDelaySettings();
    loadWaPriority();
    loadWaPhoneSettings();
    refreshWaSavedPhoneHint();
    loadWaManualBody();

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

    document.getElementById("ad-wa-save-phone")?.addEventListener("click", async () => {
      await saveWaPhoneNumber({ rescheduleAuto: waMode === "auto" });
    });

    document.getElementById("ad-wa-to")?.addEventListener("keydown", (event) => {
      if (event.key === "Enter") {
        event.preventDefault();
        void saveWaPhoneNumber({ rescheduleAuto: waMode === "auto" });
      }
    });

    document.getElementById("ad-wa-to")?.addEventListener("change", () => {
      waLastAutoSentTo = "";
    });

    document.getElementById("ad-wa-body")?.addEventListener("input", saveWaManualBody);
    document.getElementById("ad-wa-body")?.addEventListener("change", saveWaManualBody);
    document.getElementById("ad-wa-body")?.addEventListener("blur", saveWaManualBody);

    document.getElementById("ad-wa-delay-value")?.addEventListener("input", saveWaDelaySettings);
    document.getElementById("ad-wa-delay-value")?.addEventListener("change", () => {
      saveWaDelaySettings();
      if (waMode === "auto") {
        waLastAutoSentTo = "";
        scheduleAutoSend();
      }
    });
    document.getElementById("ad-wa-delay-unit")?.addEventListener("change", () => {
      saveWaDelaySettings();
      if (waMode === "auto") {
        waLastAutoSentTo = "";
        scheduleAutoSend();
      }
    });

    document.getElementById("ad-wa-priority")?.addEventListener("change", () => {
      saveWaPriority();
      if (waMode === "auto") waLastAutoSentTo = "";
    });

    document.getElementById("ad-wa-auto-body")?.addEventListener("input", () => {
      saveAutoMessageBody();
      waLastAutoSentTo = "";
    });
    document.getElementById("ad-wa-auto-body")?.addEventListener("change", saveAutoMessageBody);
    document.getElementById("ad-wa-auto-body")?.addEventListener("blur", saveAutoMessageBody);

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
        if (waMode === "auto" && first) {
          waLastAutoSentTo = "";
          await saveWaPhoneNumber({ rescheduleAuto: true });
        } else {
          saveWaPhoneSettings();
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
      if (waMode === "auto") {
        const saved = getWaSavedPhone();
        const typed = normalizeWaPhone(document.getElementById("ad-wa-to")?.value);
        if (typed && typed !== saved) {
          setWaMsg("Bonyeza Save kwanza kuweka namba mpya.", true);
          return;
        }
        if (!saved) {
          setWaMsg("Bonyeza Save kwanza kuweka namba.", true);
          return;
        }
      }
      if (!body) {
        setWaMsg(waMode === "auto"
          ? "Andika ujumbe wa automatic kwanza (unaweza kuandika ujumbe wowote)."
          : "Message is required.", true);
        return;
      }
      try {
        if (waMode === "auto") {
          const typed = normalizeWaPhone(document.getElementById("ad-wa-to")?.value);
          const saved = getWaSavedPhone();
          if (!saved || (typed && typed !== saved)) {
            const ok = await saveWaPhoneNumber({ rescheduleAuto: false });
            if (!ok) return;
          }
        } else {
          const phone = normalizeWaPhone(document.getElementById("ad-wa-to")?.value);
          if (phone.length >= 9) {
            localStorage.setItem(WA_PHONE_KEY, phone);
            refreshWaSavedPhoneHint();
          }
          saveWaPhoneSettings();
        }
        if (waMode === "auto") saveAutoMessageBody();
        saveWaPriority();
        saveWaDelaySettings();
        if (waMode === "auto") {
          const delay = delayMsFromUi();
          if (delay > 0) {
            const at = Date.now() + delay;
            await syncAutoWaSchedule({
              targets,
              body,
              priority,
              at,
              everyMs: delay,
            });
            waLastAutoSentTo = `${targets.join(",")}|${delay}|${priority}|${body}`;
            setWaMsg(`Automatic ON · ${formatWaIntervalLabel(delay)} · ${targets.join(", ")} · next ${formatWaScheduleTime(at)}`);
            return;
          }
        }
        for (const to of targets) {
          await sendWhatsappMessage({ to, body, priority });
        }
        if (waMode === "auto") {
          waLastAutoSentTo = `${targets.join(",")}|0|${priority}|${body}`;
        }
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

    document.getElementById("ad-wa-stop-auto")?.addEventListener("click", async () => {
      const targets = collectWaTargets();
      setWaMsg("Stopping automatic…");
      try {
        const payload = targets.length
          ? { phones: targets }
          : { all: true };
        const res = await fetch("whatsapp-api.php?action=schedule-cancel", {
          method: "POST",
          credentials: "same-origin",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(payload),
        });
        const data = await res.json().catch(() => ({}));
        waLastAutoSentTo = "";
        clearWaLocalSchedule();
        writeWaAutoScheduledPhones([]);
        setWaMsg(data.ok ? (data.message || "Automatic stopped.") : (data.message || "Stop failed"), !data.ok);
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

    migrateLocalWaScheduleToServer()
      .finally(() => processWaSchedule())
      .finally(armWaScheduleTimer);
    window.setInterval(() => {
      processWaSchedule().finally(armWaScheduleTimer);
    }, 30000);
  }

  function bindGeneralAnalysis() {
    const panel = document.getElementById("ad-ga-overlay");
    const openBtn = document.getElementById("ad-ga-open");
    if (!panel) return;

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
        const dest = document.getElementById("stat-dest")?.textContent || "—";
        autoEl.textContent = autoOn ? `Auto → ${dest}` : "Set payout number";
        autoEl.classList.toggle("is-on", autoOn);
      }
    }

    function openPanel() {
      updateHubSummary();
      scrollToPortalSection("general-analysis");
    }

    function scrollToSection(key) {
      if (!sectionMap[key]) return;
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
      }, 40);
    }

    openBtn?.addEventListener("click", openPanel);
    document.getElementById("ad-ga-hub")?.addEventListener("click", () => {
      scrollToPortalSection("payout-dest");
    });

    panel.querySelectorAll("[data-ga-target]").forEach((node) => {
      node.addEventListener("click", (event) => {
        if (node.classList.contains("ad-ga-chip--link")) return;
        event.preventDefault();
        const target = node.getAttribute("data-ga-target") || "";
        const action = node.getAttribute("data-ga-action") || "scroll";
        if (action === "sync") {
          syncTransactions().catch(() => {});
          return;
        }
        scrollToSection(target);
      });
    });

    // Keep hub summary fresh whenever section opens.
    const mo = new MutationObserver(() => {
      if (document.body.getAttribute("data-ad-section") === "general-analysis") {
        updateHubSummary();
      }
    });
    mo.observe(document.body, { attributes: true, attributeFilter: ["data-ad-section"] });
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
  function bindAdminProfilePhoto() {
    const input = document.getElementById("profilePhotoInput");
    if (!input) return;

    const sidebarImg = document.getElementById("sidebarProfileImage");
    const sidebarFallback = document.getElementById("sidebarProfileFallback");
    const headerImg = document.getElementById("headerProfileImage");
    const headerFallback = document.getElementById("headerProfileFallback");

    function applyPhoto(url) {
      const hasPhoto = Boolean(url);
      if (sidebarImg) {
        sidebarImg.hidden = !hasPhoto;
        if (hasPhoto) {
          sidebarImg.removeAttribute("hidden");
          sidebarImg.src = url;
        }
      }
      if (headerImg) {
        headerImg.hidden = !hasPhoto;
        if (hasPhoto) {
          headerImg.removeAttribute("hidden");
          headerImg.src = url;
        }
      }
      if (sidebarFallback) sidebarFallback.hidden = hasPhoto;
      if (headerFallback) headerFallback.hidden = hasPhoto;
    }

    function compressImage(file, maxSize = 512, quality = 0.82) {
      return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onerror = () => reject(new Error("Could not read image."));
        reader.onload = () => {
          const img = new Image();
          img.onerror = () => reject(new Error("Could not load image."));
          img.onload = () => {
            const scale = Math.min(1, maxSize / Math.max(img.width || 1, img.height || 1));
            const width = Math.max(1, Math.round((img.width || maxSize) * scale));
            const height = Math.max(1, Math.round((img.height || maxSize) * scale));
            const canvas = document.createElement("canvas");
            canvas.width = width;
            canvas.height = height;
            const ctx = canvas.getContext("2d");
            if (!ctx) {
              resolve(String(reader.result || ""));
              return;
            }
            ctx.fillStyle = "#ffffff";
            ctx.fillRect(0, 0, width, height);
            ctx.drawImage(img, 0, 0, width, height);
            resolve(canvas.toDataURL("image/jpeg", quality));
          };
          img.src = String(reader.result || "");
        };
        reader.readAsDataURL(file);
      });
    }

    input.addEventListener("change", async () => {
      const file = input.files && input.files[0];
      if (!file) return;

      if (!String(file.type || "").startsWith("image/")) {
        notify("Tafadhali chagua picha tu (JPG/PNG/WEBP).", "error");
        input.value = "";
        return;
      }
      if (file.size > 8 * 1024 * 1024) {
        notify("Picha ni kubwa mno. Tumia picha chini ya 8MB.", "error");
        input.value = "";
        return;
      }

      try {
        notify("Inahifadhi picha…", "info", { force: true, modal: true });
        const dataUrl = await compressImage(file);
        if (!dataUrl) throw new Error("Could not process image.");
        applyPhoto(dataUrl);

        const res = await fetch("auth-api.php?action=update-profile", {
          method: "POST",
          credentials: "same-origin",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ avatar: dataUrl }),
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data.ok) {
          throw new Error(data.message || "Could not save profile photo.");
        }
        const savedUrl = String(data?.user?.avatar || "").trim();
        if (savedUrl) applyPhoto(savedUrl);
        notify("Profile photo saved.", "success", { force: true });
      } catch (error) {
        notify(error.message || "Could not save profile photo.", "error", { force: true });
      } finally {
        input.value = "";
      }
    });
  }

  bindDataTableControls("controls", renderControlsTable);
  bindDataTableControls("payouts", renderPayoutsTable);
  bindDataTableControls("users", renderUsersTable);
  bindDataTableControls("recent", renderRecentCollections);
  bindGeneralAnalysis();
  bindPortalNavigation();
  bindWhatsappSection();
  bindCrmSection();
  bindAdminProfilePhoto();
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
