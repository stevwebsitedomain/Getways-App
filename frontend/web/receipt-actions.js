/**
 * View / Download / Delete receipt actions for dashboard + history.
 */
(function (global) {
  function escapeHtml(value) {
    return String(value || "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  function buildReceiptUrl(row, extra) {
    const ref = String(row.orderReference || row.order_reference || "")
      .trim()
      .toUpperCase()
      .replace(/[^A-Z0-9]/g, "");
    if (!ref) return "";
    const params = new URLSearchParams();
    params.set("r", ref);
    params.set("a", String(Math.floor(Number(row.amount || 0))));
    params.set("s", String(row.status || row.paymentStatus || "SUCCESS").toUpperCase().slice(0, 16));
    const phone = String(row.phone || "").replace(/\D/g, "");
    if (phone) params.set("p", phone);
    const ch = String(row.channel || row.mobileChannel || "").trim();
    if (ch) params.set("ch", ch.slice(0, 24));
    if (extra && typeof extra === "object") {
      Object.keys(extra).forEach((k) => {
        if (extra[k] != null && extra[k] !== "") params.set(k, String(extra[k]));
      });
    }
    return `${global.location.origin}/receipt.php?${params.toString()}`;
  }

  function actionButtonsHtml(row) {
    const payload = encodeURIComponent(
      JSON.stringify({
        orderReference: String(row.orderReference || "").trim(),
        amount: Number(row.amount || 0),
        status: row.status || "SUCCESS",
        phone: row.phone || "",
        channel: row.channel || row.mobileChannel || "",
      })
    );
    return `
      <div class="rx-actions">
        <button type="button" class="rx-link rx-link--view" data-rx-view="${payload}">
          <i class="fa-solid fa-eye" aria-hidden="true"></i> View
        </button>
        <button type="button" class="rx-link rx-link--dl" data-rx-download="${payload}">
          <i class="fa-solid fa-download" aria-hidden="true"></i> Download
        </button>
        <button type="button" class="rx-link rx-link--del" data-rx-delete="${payload}">
          <i class="fa-solid fa-trash" aria-hidden="true"></i> Delete
        </button>
      </div>
    `;
  }

  function parsePayload(raw) {
    try {
      return JSON.parse(decodeURIComponent(raw || ""));
    } catch (_) {
      return null;
    }
  }

  function viewReceipt(row) {
    const url = buildReceiptUrl(row, { embed: "1" });
    const fullUrl = buildReceiptUrl(row);
    if (!url) return;
    if (global.Swal && typeof global.Swal.fire === "function") {
      global.Swal.fire({
        title: "Payment Receipt",
        html: `
          <div class="rx-preview">
            <iframe class="rx-preview-frame" src="${escapeHtml(url)}" title="Receipt preview"></iframe>
          </div>
          <div class="rx-preview-bar">
            <a class="rx-text-btn" href="${escapeHtml(fullUrl)}" target="_blank" rel="noopener">
              <i class="fa-solid fa-up-right-from-square"></i> Full page
            </a>
            <button type="button" class="rx-text-btn" id="rx-swal-download">
              <i class="fa-solid fa-download"></i> Download
            </button>
          </div>
        `,
        width: Math.min(420, global.innerWidth - 16),
        padding: "1rem 0.75rem 1.1rem",
        showConfirmButton: true,
        confirmButtonText: "Close",
        confirmButtonColor: "#6357f1",
        customClass: { popup: "rx-swal-popup", htmlContainer: "rx-swal-html" },
        didOpen: () => {
          const btn = document.getElementById("rx-swal-download");
          if (btn) btn.addEventListener("click", () => chooseDownload(row));
        },
      });
      return;
    }
    global.open(fullUrl, "_blank", "noopener,noreferrer");
  }

  function chooseDownload(row) {
    if (!global.Swal || typeof global.Swal.fire !== "function") {
      global.open(buildReceiptUrl(row, { print: "1", embed: "1" }), "_blank", "noopener,noreferrer");
      return;
    }
    global.Swal.fire({
      title: "Save receipt",
      html: `
        <p class="rx-dl-ref">${escapeHtml(row.orderReference || "")}</p>
        <div class="rx-dl-row">
          <button type="button" class="rx-fmt" id="rx-dl-image">
            <span class="rx-fmt-ico rx-fmt-ico--img"><i class="fa-solid fa-image"></i></span>
            <span class="rx-fmt-copy">
              <strong>PNG Image</strong>
              <small>Photo of the receipt</small>
            </span>
            <i class="fa-solid fa-chevron-right rx-fmt-arrow"></i>
          </button>
          <button type="button" class="rx-fmt" id="rx-dl-pdf">
            <span class="rx-fmt-ico rx-fmt-ico--pdf"><i class="fa-solid fa-file-pdf"></i></span>
            <span class="rx-fmt-copy">
              <strong>PDF File</strong>
              <small>Print or save as PDF</small>
            </span>
            <i class="fa-solid fa-chevron-right rx-fmt-arrow"></i>
          </button>
        </div>
      `,
      showConfirmButton: false,
      showCancelButton: true,
      cancelButtonText: "Cancel",
      cancelButtonColor: "#94a3b8",
      width: 340,
      customClass: { popup: "rx-swal-popup" },
      didOpen: () => {
        const imgBtn = document.getElementById("rx-dl-image");
        const pdfBtn = document.getElementById("rx-dl-pdf");
        if (imgBtn) {
          imgBtn.addEventListener("click", async () => {
            imgBtn.disabled = true;
            try {
              await downloadAsImage(row);
              global.Swal.close();
            } catch (e) {
              global.Swal.fire({
                icon: "error",
                title: "Download failed",
                text: e.message || "Could not create image.",
                confirmButtonColor: "#6357f1",
              });
            }
          });
        }
        if (pdfBtn) {
          pdfBtn.addEventListener("click", () => {
            downloadAsPdf(row);
            global.Swal.close();
          });
        }
      },
    });
  }

  function downloadAsPdf(row) {
    global.open(buildReceiptUrl(row, { print: "1", embed: "1" }), "_blank", "noopener,noreferrer");
  }

  async function downloadAsImage(row) {
    const url = buildReceiptUrl(row, { embed: "1" });
    if (!url) throw new Error("Missing order reference.");
    if (typeof global.html2canvas !== "function") {
      downloadAsPdf(row);
      return;
    }

    const iframe = document.createElement("iframe");
    iframe.style.cssText =
      "position:fixed;left:-9999px;top:0;width:420px;height:900px;opacity:0;pointer-events:none;border:0";
    iframe.src = url;
    document.body.appendChild(iframe);

    await new Promise((resolve, reject) => {
      const t = global.setTimeout(() => reject(new Error("Receipt load timeout.")), 12000);
      iframe.onload = () => {
        global.clearTimeout(t);
        resolve();
      };
      iframe.onerror = () => {
        global.clearTimeout(t);
        reject(new Error("Could not load receipt."));
      };
    });

    await new Promise((r) => global.setTimeout(r, 900));
    const doc = iframe.contentDocument;
    const paper = doc && doc.querySelector(".receipt-paper");
    if (!paper) {
      iframe.remove();
      throw new Error("Receipt paper not found.");
    }

    // Wait for QR image so it appears in the PNG
    const qrImg = paper.querySelector(".qr-wrap img");
    if (qrImg && !qrImg.complete) {
      await new Promise((resolve) => {
        const done = () => resolve();
        qrImg.addEventListener("load", done, { once: true });
        qrImg.addEventListener("error", done, { once: true });
        global.setTimeout(done, 2500);
      });
    }

    const canvas = await global.html2canvas(paper, {
      backgroundColor: "#ffffff",
      scale: 2,
      useCORS: true,
      allowTaint: true,
    });
    iframe.remove();

    const link = document.createElement("a");
    const ref = String(row.orderReference || "receipt").replace(/[^\w-]+/g, "_");
    link.download = `Getway-Receipt-${ref}.png`;
    link.href = canvas.toDataURL("image/png");
    link.click();
  }

  async function deleteReceipt(row) {
    const ref = String(row.orderReference || "").trim().toUpperCase();
    if (!ref) return;

    const confirm = global.Swal
      ? await global.Swal.fire({
          icon: "warning",
          title: "Delete receipt?",
          html: `Remove <strong>${escapeHtml(ref)}</strong> from history?`,
          showCancelButton: true,
          confirmButtonText: "Delete",
          cancelButtonText: "Cancel",
          confirmButtonColor: "#dc2626",
          cancelButtonColor: "#94a3b8",
        })
      : { isConfirmed: global.confirm(`Delete ${ref}?`) };

    if (!confirm.isConfirmed) return;

    const headers = {
      "Content-Type": "application/json",
      "ngrok-skip-browser-warning": "true",
    };
    const apiTis = global.TIS_API_BASE || `${global.location.origin}/api/tis`;
    const apiYii = global.CLICKPESA_API_BASE || `${global.location.origin}/api/clickpesa`;

    let ok = false;
    try {
      const yiiRes = await fetch(`${apiYii}/delete`, {
        method: "POST",
        headers,
        body: JSON.stringify({ orderReference: ref }),
      });
      if (yiiRes.ok) ok = true;
    } catch (_) {
      /* try node */
    }
    try {
      const nodeRes = await fetch(`${apiTis}/payments/${encodeURIComponent(ref)}`, {
        method: "DELETE",
        headers,
      });
      if (nodeRes.ok) ok = true;
    } catch (_) {
      /* ignore */
    }

    if (!ok) {
      if (global.Swal) {
        global.Swal.fire({
          icon: "error",
          title: "Delete failed",
          text: "Could not delete this receipt.",
          confirmButtonColor: "#6357f1",
        });
      }
      return;
    }

    if (global.Swal) {
      await global.Swal.fire({
        icon: "success",
        title: "Deleted",
        timer: 1200,
        showConfirmButton: false,
      });
    }
    global.location.reload();
  }

  function injectStylesOnce() {
    if (document.getElementById("rx-actions-styles")) return;
    const style = document.createElement("style");
    style.id = "rx-actions-styles";
    style.textContent = `
      .rx-actions{display:flex;gap:12px;margin-top:10px;flex-wrap:wrap;align-items:center}
      .rx-link{
        display:inline-flex;align-items:center;gap:5px;
        border:0;background:transparent;padding:0;cursor:pointer;
        font:700 0.78rem/1.2 "DM Sans",system-ui,sans-serif;
      }
      .rx-link--view{color:#6357f1}
      .rx-link--dl{color:#0f766e}
      .rx-link--del{color:#dc2626}
      .rx-link:hover{text-decoration:underline;filter:brightness(0.92)}
      .rx-swal-popup{border-radius:18px!important}
      .rx-swal-html{margin:0!important;padding:0 .25rem!important}
      .rx-preview{
        width:100%;max-width:340px;margin:0 auto 10px;
        height:min(62vh,480px);border-radius:14px;overflow:hidden;
        border:1px solid #e5e9f2;background:#f3f6fb;
      }
      .rx-preview-frame{width:100%;height:100%;border:0;background:#f3f6fb}
      .rx-preview-bar{display:flex;justify-content:center;gap:18px;margin:2px 0 6px}
      .rx-text-btn{
        display:inline-flex;align-items:center;gap:6px;
        border:0;background:transparent;cursor:pointer;text-decoration:none;
        font:700 0.8rem/1 "DM Sans",system-ui,sans-serif;color:#6357f1;
      }
      .rx-dl-ref{
        margin:0 0 14px;font-size:.78rem;font-weight:700;color:#64748b;
        word-break:break-all;text-align:center;
      }
      .rx-dl-row{display:grid;gap:10px}
      .rx-fmt{
        display:grid;grid-template-columns:44px 1fr 16px;align-items:center;gap:10px;
        width:100%;border:1px solid #e5e9f2;border-radius:14px;padding:12px;
        background:#fff;cursor:pointer;font-family:"DM Sans",system-ui,sans-serif;
        text-align:left;
      }
      .rx-fmt:hover{border-color:#c7d2fe;background:#f8f7ff}
      .rx-fmt-ico{
        width:44px;height:44px;border-radius:12px;display:grid;place-items:center;font-size:1.1rem;
      }
      .rx-fmt-ico--img{background:#ecfdf5;color:#0f766e}
      .rx-fmt-ico--pdf{background:#eef2ff;color:#6357f1}
      .rx-fmt-copy{display:flex;flex-direction:column;gap:2px;min-width:0}
      .rx-fmt-copy strong{font-size:.9rem;color:#0f172a}
      .rx-fmt-copy small{font-size:.72rem;color:#94a3b8;font-weight:600}
      .rx-fmt-arrow{color:#cbd5e1;font-size:.75rem}
      .payments-detail-table td.col-actions{min-width:200px;vertical-align:middle}
    `;
    document.head.appendChild(style);
  }

  function bindDelegates(root) {
    injectStylesOnce();
    const el = root || document;
    el.addEventListener("click", (ev) => {
      const viewBtn = ev.target.closest("[data-rx-view]");
      const dlBtn = ev.target.closest("[data-rx-download]");
      const delBtn = ev.target.closest("[data-rx-delete]");
      if (viewBtn) {
        ev.preventDefault();
        const row = parsePayload(viewBtn.getAttribute("data-rx-view"));
        if (row) viewReceipt(row);
        return;
      }
      if (dlBtn) {
        ev.preventDefault();
        const row = parsePayload(dlBtn.getAttribute("data-rx-download"));
        if (row) chooseDownload(row);
        return;
      }
      if (delBtn) {
        ev.preventDefault();
        const row = parsePayload(delBtn.getAttribute("data-rx-delete"));
        if (row) void deleteReceipt(row);
      }
    });
  }

  global.GetwayReceiptActions = {
    buildReceiptUrl,
    actionButtonsHtml,
    viewReceipt,
    chooseDownload,
    deleteReceipt,
    bindDelegates,
    injectStylesOnce,
  };

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", () => bindDelegates(document));
  } else {
    bindDelegates(document);
  }
})(window);
