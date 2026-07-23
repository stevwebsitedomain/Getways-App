(function () {
  const API = "user-api.php";
  const PAGE_SIZE = 5;
  let txRows = [];
  let txPage = 1;
  let waitSwalOpen = false;

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

  function statusBadge(st) {
    const s = String(st || "").toUpperCase();
    let cls = "bk-badge--pending";
    if (["SUCCESS", "PAID", "COMPLETED"].includes(s)) cls = "bk-badge--ok";
    if (["FAILED", "FAILURE", "REFUNDED", "REVERSED"].includes(s)) cls = "bk-badge--fail";
    return `<span class="bk-badge ${cls}">${esc(s || "—")}</span>`;
  }

  async function requestJson(action, options = {}) {
    const url = new URL(API, window.location.href);
    url.searchParams.set("action", action);
    const res = await fetch(url.toString(), {
      method: options.method || "GET",
      headers: { "Content-Type": "application/json", Accept: "application/json" },
      credentials: "same-origin",
      body: options.body ? JSON.stringify(options.body) : undefined,
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || data.success === false || data.ok === false) {
      throw new Error(data.message || "Request failed.");
    }
    return data;
  }

  function showWaitSwal(title, html) {
    if (!window.Swal) return;
    waitSwalOpen = true;
    window.Swal.fire({
      title: title || "Tafadhali subiri",
      html: html || '<p style="margin:0.35rem 0 0;font-size:0.95rem;font-weight:600;color:#475569">Tunatengeneza control number…</p>',
      allowOutsideClick: false,
      allowEscapeKey: false,
      showConfirmButton: false,
      didOpen: () => window.Swal.showLoading?.(),
    });
  }

  function dismissWaitSwal() {
    if (!window.Swal || !waitSwalOpen) return;
    try { window.Swal.close(); } catch (_) { /* ignore */ }
    waitSwalOpen = false;
  }

  function showError(message) {
    dismissWaitSwal();
    if (!window.Swal) {
      window.alert(message);
      return;
    }
    window.Swal.fire({ icon: "error", title: "Haijafanikiwa", text: message, confirmButtonColor: "#b91c1c" });
  }

  function qrImageUrl(payload) {
    const data = encodeURIComponent(String(payload || "").trim());
    if (!data) return "";
    return `https://api.qrserver.com/v1/create-qr-code/?size=140x140&margin=8&ecc=H&data=${data}`;
  }

  function buildPaperHtml(data) {
    const cn = esc(data.controlNumber || "—");
    const ref = esc(data.reference || "—");
    const amt = esc(money(data.amount || 0));
    const desc = esc(data.description || "BillPay payment");
    const status = String(data.status || "PENDING").toUpperCase();
    const isPaid = ["SUCCESS", "PAID", "COMPLETED", "SETTLED"].includes(status);
    const statusLabel = isPaid ? "IMELIPWA" : "BADO — INASUBIRI MALIPO";
    const statusColor = isPaid ? "#15803d" : "#b45309";
    const shareUrl = data.receiptShareUrl || "";
    const qrUrl = shareUrl ? qrImageUrl(shareUrl) : "";
    const qrBlock = qrUrl
      ? `<div class="ad-cn-qr-wrap"><img src="${qrUrl}" alt="Receipt QR" class="ad-cn-qr" /><p class="ad-cn-qr-caption">Skani kuona risiti kwenye simu</p></div>`
      : "";
    return `
      <div class="ad-cn-paper">
        <div class="ad-cn-brand">Getway | BillPay</div>
        <div class="ad-cn-sub">CONTROL NUMBER IMETENGENEZWA</div>
        <hr class="ad-cn-dash" />
        <div class="ad-cn-number">${cn}</div>
        <p class="ad-cn-hint">Shiriki namba hii na mteja ili alipe.</p>
        ${qrBlock}
        <hr class="ad-cn-dash" />
        <div class="ad-cn-row"><span>REFERENCE</span><span>${ref}</span></div>
        <div class="ad-cn-row"><span>AMOUNT</span><span>${amt}</span></div>
        <div class="ad-cn-row"><span>DESCRIPTION</span><span>${desc}</span></div>
        <div class="ad-cn-row"><span>MALIPO</span><span style="color:${statusColor};font-weight:800">${statusLabel}</span></div>
      </div>`;
  }

  async function showResult(data) {
    dismissWaitSwal();
    const cn = data.controlNumber || "";
    const invoiceUrl = data.invoiceUrl || "";
    const msg = document.getElementById("control-number-message");
    if (msg) {
      msg.className = "form-message is-ok";
      msg.textContent = `Control number: ${cn}`;
    }
    if (!window.Swal) return;
    await window.Swal.fire({
      icon: "success",
      title: "Imefanikiwa!",
      html: `${buildPaperHtml(data)}<div class="ad-cn-actions">
        <button type="button" class="ad-refresh" id="swal-copy-cn">Nakili Control Number</button>
        ${invoiceUrl ? '<button type="button" class="ad-refresh" id="swal-view-invoice">Angalia Risiti</button>' : ""}
      </div>`,
      confirmButtonText: "Funga",
      confirmButtonColor: "#16a34a",
      width: 400,
      didOpen: () => {
        document.getElementById("swal-copy-cn")?.addEventListener("click", async () => {
          await navigator.clipboard?.writeText(cn);
        });
        document.getElementById("swal-view-invoice")?.addEventListener("click", () => openInvoice(invoiceUrl, false));
      },
    });
  }

  function renderPager() {
    const pager = document.getElementById("cn-tx-pager");
    if (!pager) return;
    const totalPages = Math.max(1, Math.ceil(txRows.length / PAGE_SIZE));
    txPage = Math.min(Math.max(1, txPage), totalPages);
    if (txRows.length <= PAGE_SIZE) {
      pager.hidden = true;
      return;
    }
    pager.hidden = false;
    pager.innerHTML = `
      <button type="button" class="bk-pager-btn" data-page="prev" ${txPage <= 1 ? "disabled" : ""}>Prev</button>
      <span class="bk-pager-info">${txPage} / ${totalPages}</span>
      <button type="button" class="bk-pager-btn" data-page="next" ${txPage >= totalPages ? "disabled" : ""}>Next</button>`;
    pager.querySelector('[data-page="prev"]')?.addEventListener("click", () => { txPage -= 1; renderTable(); });
    pager.querySelector('[data-page="next"]')?.addEventListener("click", () => { txPage += 1; renderTable(); });
  }

  function resolveInvoiceUrl(url) {
    const raw = String(url || "").trim();
    if (!raw) return "";
    if (/^https?:\/\//i.test(raw)) {
      return raw.replace(/admin-invoice\.php/i, "control-number-invoice.php");
    }
    const normalized = raw.replace(/^admin-invoice\.php/i, "control-number-invoice.php");
    if (normalized.startsWith("/")) {
      return `${window.location.origin}${normalized}`;
    }
    const base = window.location.pathname.replace(/[^/]*$/, "");
    return `${window.location.origin}${base}${normalized}`;
  }

  function openInvoice(url, download) {
    const resolved = resolveInvoiceUrl(url);
    if (!resolved) return;
    const target = download ? `${resolved}${resolved.includes("?") ? "&" : "?"}download=1` : resolved;
    window.open(target, "_blank", "noopener");
  }

  function renderTable() {
    const body = document.getElementById("cn-tx-body");
    if (!body) return;
    const slice = txRows.slice((txPage - 1) * PAGE_SIZE, txPage * PAGE_SIZE);
    body.innerHTML = slice.length ? slice.map((row) => `
      <tr>
        <td>${esc(row.orderId || "—")}</td>
        <td>${esc(row.controlNumber || "—")}</td>
        <td>${money(row.amount)}</td>
        <td>${statusBadge(row.status)}</td>
        <td>
          <div class="bk-row-actions">
            ${row.controlNumber ? `<button type="button" class="bk-row-btn" data-copy="${esc(row.controlNumber)}" title="Copy"><i class="fa-regular fa-copy"></i></button>` : ""}
            ${row.invoiceUrl ? `<button type="button" class="bk-row-btn" data-invoice="${esc(row.invoiceUrl)}" title="View"><i class="fa-solid fa-receipt"></i></button>` : ""}
          </div>
        </td>
      </tr>`).join("") : `<tr><td colspan="5" class="bk-empty">No transactions yet.</td></tr>`;
    body.querySelectorAll("[data-copy]").forEach((btn) => {
      btn.addEventListener("click", async () => {
        await navigator.clipboard?.writeText(btn.getAttribute("data-copy") || "");
      });
    });
    body.querySelectorAll("[data-invoice]").forEach((btn) => {
      btn.addEventListener("click", () => openInvoice(btn.getAttribute("data-invoice") || "", false));
    });
    renderPager();
  }

  async function loadTransactions() {
    const body = document.getElementById("cn-tx-body");
    if (body) body.innerHTML = `<tr><td colspan="5" class="bk-empty">Loading…</td></tr>`;
    try {
      const data = await requestJson("transactions");
      txRows = data.items || [];
      txPage = 1;
      renderTable();
    } catch (error) {
      if (body) body.innerHTML = `<tr><td colspan="5" class="bk-empty">No transactions yet.</td></tr>`;
      const err = document.getElementById("cn-tx-error");
      if (err) {
        err.hidden = false;
        err.textContent = error.message;
      }
    }
  }

  function bindForm() {
    const form = document.getElementById("control-number-form");
    if (!form) return;
    const submitBtn = form.querySelector('button[type="submit"]');
    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      try {
        if (submitBtn) submitBtn.disabled = true;
        showWaitSwal("Subiri", "<p style='margin:0.35rem 0 0;font-weight:600;color:#475569'>Tunatengeneza control number…</p>");
        const payload = Object.fromEntries(new FormData(form).entries());
        if (!String(payload.order_id || "").trim()) delete payload.order_id;
        if (!String(payload.description || "").trim()) payload.description = "BillPay payment";
        const data = await requestJson("create-control-number", { method: "POST", body: payload });
        form.reset();
        await showResult({ ...data, description: payload.description });
        await loadTransactions();
      } catch (error) {
        showError(error.message || "Failed to create control number.");
        const msg = document.getElementById("control-number-message");
        if (msg) {
          msg.className = "form-message is-error";
          msg.textContent = error.message;
        }
      } finally {
        if (submitBtn) submitBtn.disabled = false;
      }
    });
  }

  document.getElementById("cn-refresh")?.addEventListener("click", () => loadTransactions());
  bindForm();
  loadTransactions();
})();
