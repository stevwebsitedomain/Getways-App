(function () {
  const API = "admin-api.php";
  const REFRESH_MS = 60000;
  let latestPayoutRows = [];
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

  function setBanner(id, message, type = "error") {
    const el = document.getElementById(id);
    if (el) {
      el.hidden = true;
      el.textContent = "";
    }
    notify(message, type);
  }

  function notify(message, type = "info") {
    const text = String(message || "").trim();
    if (!text) return;
    if (!window.Swal || typeof window.Swal.fire !== "function") {
      if (type === "error") console.error(text);
      return;
    }
    const icon = type === "success" ? "success" : type === "warning" ? "warning" : type === "error" ? "error" : "info";
    window.Swal.fire({
      toast: true,
      position: "top-end",
      icon,
      title: text,
      showConfirmButton: false,
      timer: type === "error" ? 5000 : 3500,
      timerProgressBar: true,
    });
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

  async function showSuccessDialog(data) {
    const cn = esc(data.controlNumber || "");
    const ref = esc(data.reference || "");
    const amt = money(data.amount || 0);
    const invoiceUrl = data.invoiceUrl || "";
    if (!window.Swal || typeof window.Swal.fire !== "function") return;
    await window.Swal.fire({
      icon: "success",
      title: "Control Number Created",
      html: `
        <div style="text-align:left;font-size:0.95rem;line-height:1.6">
          <p><strong>Control Number:</strong> <code>${cn}</code></p>
          <p><strong>Reference:</strong> ${ref}</p>
          <p><strong>Amount:</strong> ${amt}</p>
          <p style="font-size:0.85rem;color:#64748b">Tumia <strong>Control Number</strong> hii kulipa kwenye BillPay. Kwa ClickPesa inaweza kuwa sawa na Reference.</p>
          <div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:center;margin-top:14px">
            <button type="button" class="ad-refresh" id="swal-copy-cn">Copy Control Number</button>
            ${invoiceUrl ? `<button type="button" class="ad-refresh" id="swal-view-invoice">View Invoice</button>` : ""}
            ${invoiceUrl ? `<button type="button" class="ad-refresh" id="swal-download-invoice">Download PDF</button>` : ""}
          </div>
        </div>`,
      confirmButtonText: "Close",
      confirmButtonColor: "#16a34a",
      didOpen: () => {
        document.getElementById("swal-copy-cn")?.addEventListener("click", async () => {
          await navigator.clipboard?.writeText(data.controlNumber || "");
          notify("Control number copied.", "success");
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

  async function loadBalance() {
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
      setBanner("ad-db-banner", error.message);
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
        setBanner("ad-payouts-error", result.warning, "warning");
      }
    } catch (error) {
      setAutoPayoutUi(false, "ERROR");
      setBanner("ad-payouts-error", error.message);
    }
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
      const recentRows = analytics.recentCollections || [];
      recent.innerHTML = recentRows.length
        ? recentRows.map((row) => `
            <li>
              <div>
                <strong>${esc(row.orderReference || row.controlNumber || "—")}</strong>
                <div style="color:#8b9aab;font-size:0.8rem">${statusBadge(row.status)} · ${fmtDate(row.createdAt)}</div>
              </div>
              <strong>${money(row.amount)}</strong>
            </li>`).join("")
        : "<li>No ClickPesa transactions were found for this period.</li>";
      setBanner("ad-statement-error", "");
      setBanner("ad-recent-error", "");
    } catch (error) {
      drawTrend(document.getElementById("ad-trend"), []);
      drawPie(document.getElementById("ad-pie"), {});
      document.getElementById("ad-recent").innerHTML = "<li>No ClickPesa transactions were found for this period.</li>";
      setBanner("ad-statement-error", error.message);
      setBanner("ad-recent-error", error.message);
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

  async function loadControls() {
    const body = document.getElementById("ad-controls-body");
    body.innerHTML = `<tr><td colspan="9">Loading...</td></tr>`;
    try {
      const result = await requestJson("control-numbers");
      const rows = result.items || [];
      body.innerHTML = rows.length ? rows.map((row) => `
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
            ${row.hasControlNumber ? `<button type="button" class="ad-btn ad-btn--copy" data-copy="${esc(row.controlNumber)}"><i class="fa-regular fa-copy"></i> Copy</button>` : ""}
            ${row.canWithdraw ? `<button type="button" class="ad-btn ad-btn--withdraw" data-withdraw="${row.id}"><i class="fa-solid fa-money-bill-transfer"></i> Withdraw</button>` : ""}
            ${row.invoiceUrl ? `<button type="button" class="ad-btn ad-btn--view" data-invoice="${esc(row.invoiceUrl)}"><i class="fa-solid fa-eye"></i> View</button>` : ""}
            ${row.invoiceUrl ? `<button type="button" class="ad-btn ad-btn--download" data-invoice-download="${esc(row.invoiceUrl)}"><i class="fa-solid fa-file-pdf"></i> PDF</button>` : ""}
            </div>
          </td>
        </tr>`).join("") : `<tr><td colspan="9">No control numbers have been created yet.</td></tr>`;
      bindCopyButtons();
      body.querySelectorAll("[data-invoice]").forEach((btn) => {
        btn.addEventListener("click", () => openInvoice(btn.getAttribute("data-invoice") || "", false));
      });
      body.querySelectorAll("[data-invoice-download]").forEach((btn) => {
        btn.addEventListener("click", () => openInvoice(btn.getAttribute("data-invoice-download") || "", true));
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
      setBanner("ad-controls-error", "");
    } catch (error) {
      body.innerHTML = `<tr><td colspan="9">No control numbers have been created yet.</td></tr>`;
      setBanner("ad-controls-error", error.message);
    }
  }

  async function loadPayouts() {
    const body = document.getElementById("ad-payouts-body");
    body.innerHTML = `<tr><td colspan="8">Loading...</td></tr>`;
    try {
      const result = await requestJson("payouts");
      latestPayoutRows = result.items || [];
      body.innerHTML = latestPayoutRows.length ? latestPayoutRows.map((row) => `
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
      setBanner("ad-payouts-error", latestSettings?.warning || "", latestSettings?.warning ? "warning" : "info");
    } catch (error) {
      body.innerHTML = `<tr><td colspan="8">No automatic payouts have been processed.</td></tr>`;
      setBanner("ad-payouts-error", error.message);
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
        msg.className = "ad-msg is-err";
        msg.textContent = error.message;
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
      msg.className = "ad-msg";
      msg.textContent = "Creating...";
      const payload = Object.fromEntries(new FormData(event.target).entries());
      const data = await requestJson("create-control-number", { method: "POST", body: payload });
      msg.className = "ad-msg is-ok";
      msg.textContent = `Created: ${data.controlNumber}`;
      event.target.reset();
      showSuccessDialog(data);
      await loadControls();
    } catch (error) {
      msg.className = "ad-msg is-err";
      msg.textContent = error.message;
      notify(error.message, "error");
    } finally {
      if (submit) submit.disabled = false;
    }
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
    await Promise.all([loadBalance(), loadSettings(), loadStatement(), loadControls(), loadPayouts()]);
  }

  document.getElementById("ad-refresh")?.addEventListener("click", () => loadAll());
  document.getElementById("ad-balance-refresh")?.addEventListener("click", () => loadBalance());
  document.getElementById("ad-payouts-refresh")?.addEventListener("click", () => loadPayouts());
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

  loadAll().catch((error) => {
    setBanner("ad-db-banner", error.message);
  });
  window.setInterval(loadBalance, REFRESH_MS);
})();
