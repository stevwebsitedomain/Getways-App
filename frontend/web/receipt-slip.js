/**
 * Paper-style payment receipt with scannable QR (URL → visual receipt page).
 */
(function (global) {
  // Compact but scannable QR (no frame)
  var QR_SIZE = 130;
  var PRINT_QR_MM = 34;
  var AUTO_PRINT_KEY = "getwayAutoPrintReceipt";

  function escapeHtml(value) {
    return String(value || "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  function formatNumber(value) {
    return new Intl.NumberFormat("en-US", {
      minimumFractionDigits: 0,
      maximumFractionDigits: 0,
    }).format(Number(value || 0));
  }

  /** Short public URL — phone cameras open this as a receipt PAGE (not JSON text). */
  function qrPayload(data) {
    var ref = String(data.orderReference || "")
      .trim()
      .toUpperCase()
      .replace(/[^A-Z0-9]/g, "");
    var origin = String(global.location.origin || "").replace(/\/$/, "");
    var base = origin + "/receipt.php";
    var params = new URLSearchParams();
    params.set("r", ref);
    // Compact fallbacks if DB is slow/missing on first scan
    params.set("a", String(Math.floor(Number(data.amount || 0))));
    params.set("s", String(data.status || "SUCCESS").toUpperCase().slice(0, 16));
    var phone = String(data.phone || "").replace(/\D/g, "");
    if (phone) params.set("p", phone);
    var ch = String(data.mobileChannel || data.channel || "").trim();
    if (ch) params.set("ch", ch.slice(0, 24));
    return base + "?" + params.toString();
  }

  function paperStyles() {
    return `
      .gw-receipt-paper{
        width:100%;
        max-width:340px;
        margin:0 auto;
        padding:18px 14px 16px;
        background:#fff;
        color:#000;
        font-family:"Courier New",Courier,monospace;
        font-size:12px;
        line-height:1.4;
        border:1px dashed #94a3b8;
        box-shadow:0 8px 24px rgba(15,23,42,.08);
        text-align:center;
        box-sizing:border-box;
      }
      .gw-receipt-brand{font-weight:700;color:#000;font-size:15px;letter-spacing:.04em;text-transform:uppercase}
      .gw-receipt-sub{margin-top:3px;font-size:11px;color:#000;font-weight:700}
      .gw-receipt-dash{border:none;border-top:1px dashed #000;margin:10px 0}
      .gw-receipt-row{
        display:flex;justify-content:space-between;align-items:flex-start;gap:10px;
        text-align:left;margin:5px 0;font-size:12px;color:#000;
      }
      .gw-receipt-row span:first-child{flex:0 0 auto;min-width:82px;color:#000;font-weight:700}
      .gw-receipt-row span:last-child{
        flex:1 1 auto;font-weight:700;text-align:right;overflow-wrap:anywhere;word-break:break-word;min-width:0;color:#000;
      }
      .gw-receipt-total{
        display:flex;justify-content:space-between;align-items:baseline;gap:10px;
        margin-top:8px;font-size:16px;font-weight:800;text-align:left;color:#000;
      }
      .gw-receipt-total span:last-child{text-align:right;overflow-wrap:anywhere;font-weight:800;color:#000}
      .gw-receipt-qr-wrap{
        margin:8px auto 4px;
        width:${QR_SIZE}px;height:${QR_SIZE}px;
        display:flex;align-items:center;justify-content:center;
        background:#fff;padding:0;border:none;border-radius:0;
        box-sizing:border-box;
      }
      .gw-receipt-qr-wrap canvas,
      .gw-receipt-qr-wrap img,
      .gw-receipt-qr-wrap table{
        max-width:${QR_SIZE}px!important;max-height:${QR_SIZE}px!important;
        width:${QR_SIZE}px!important;height:${QR_SIZE}px!important;
        display:block;margin:0 auto;background:#fff;
        image-rendering:pixelated;image-rendering:crisp-edges;
      }
      .gw-receipt-qr-label{font-size:10px;margin:0 0 4px;letter-spacing:.06em;font-weight:700;color:#000}
      .gw-receipt-code{font-size:11px;letter-spacing:.05em;word-break:break-all;font-weight:700;color:#000}
      .gw-receipt-thanks{margin-top:8px;font-size:12px;font-weight:700;color:#000}
      .gw-receipt-actions{display:flex;gap:16px;justify-content:center;flex-wrap:wrap;margin:14px 0 4px}
      .gw-receipt-btn{
        display:inline-flex;align-items:center;gap:6px;border:0;background:transparent;padding:0;
        font:700 0.82rem/1 "DM Sans",system-ui,sans-serif;cursor:pointer;
      }
      .gw-receipt-btn--download{color:#0f766e}
      .gw-receipt-btn--print{color:#6357f1}
      .gw-receipt-btn:hover{text-decoration:underline}
      .gw-receipt-btn:disabled{opacity:.55;cursor:wait}
    `;
  }

  function buildPaperHtml(data, options) {
    var opts = options || {};
    var ok = opts.ok !== false;
    var amount = formatNumber(data.amount || 0);
    var currency = escapeHtml(data.currency || "TZS");
    var ref = escapeHtml(data.orderReference || "—");
    var phone = escapeHtml(data.phone || "—");
    var channel = escapeHtml(data.mobileChannel || data.channel || "Mobile Money");
    var status = escapeHtml(data.status || (ok ? "SUCCESS" : "FAILED"));
    var when = escapeHtml(data.when || new Date().toLocaleString());
    var desc = escapeHtml(data.description || "Payment");
    var customer = escapeHtml(data.customerName || "Customer");

    return `
      <div class="gw-receipt-paper" id="gw-receipt-paper" data-receipt="1">
        <div class="gw-receipt-brand">Getway | System</div>
        <div class="gw-receipt-sub">Payment Receipt</div>
        <div class="gw-receipt-sub">${ok ? "TRANSACTION COMPLETE" : "TRANSACTION FAILED"}</div>
        <hr class="gw-receipt-dash" />
        <div class="gw-receipt-row"><span>CASHIER</span><span>AutoPay</span></div>
        <div class="gw-receipt-row"><span>CUSTOMER</span><span>${customer}</span></div>
        <div class="gw-receipt-row"><span>PHONE</span><span>${phone}</span></div>
        <div class="gw-receipt-row"><span>CHANNEL</span><span>${channel}</span></div>
        <div class="gw-receipt-row"><span>DESC</span><span>${desc}</span></div>
        <hr class="gw-receipt-dash" />
        <div class="gw-receipt-row"><span>ORDER REF</span><span>${ref}</span></div>
        <div class="gw-receipt-row"><span>STATUS</span><span>${status}</span></div>
        <div class="gw-receipt-row"><span>DATE</span><span>${when}</span></div>
        <div class="gw-receipt-total"><span>TOTAL</span><span>${currency} ${amount}</span></div>
        <hr class="gw-receipt-dash" />
        <div class="gw-receipt-qr-label">SCAN FOR RECEIPT</div>
        <div class="gw-receipt-qr-wrap" id="gw-receipt-qr" aria-label="Receipt QR code"></div>
        <div class="gw-receipt-code">${ref}</div>
        <div class="gw-receipt-thanks">Thank you for your payment</div>
      </div>
    `;
  }

  function injectStylesOnce() {
    var existing = document.getElementById("gw-receipt-slip-styles");
    if (existing) existing.remove();
    var style = document.createElement("style");
    style.id = "gw-receipt-slip-styles";
    style.textContent = paperStyles();
    document.head.appendChild(style);
  }

  function qrImageUrl(payload, size) {
    var px = size || QR_SIZE;
    return (
      "https://api.qrserver.com/v1/create-qr-code/?size=" +
      px +
      "x" +
      px +
      "&margin=10&ecc=H&data=" +
      encodeURIComponent(payload)
    );
  }

  function isAutoPrintEnabled() {
    return getAutoPrintPreference();
  }

  function getAutoPrintPreference() {
    try {
      var params = new URLSearchParams(String(global.location.search || ""));
      if (params.get("autoprint") === "0" || params.get("print") === "0") return false;
      if (params.get("autoprint") === "1" || params.get("print") === "1") return true;
      var v = global.localStorage.getItem(AUTO_PRINT_KEY);
      // Default ON for POS — print when payment receipt appears
      return v === null || v === "1" || v === "true";
    } catch (_) {
      return true;
    }
  }

  function setAutoPrintEnabled(on) {
    try {
      global.localStorage.setItem(AUTO_PRINT_KEY, on ? "1" : "0");
    } catch (_) {
      /* ignore */
    }
  }

  function renderQrFallbackImage(container, payload) {
    var img = document.createElement("img");
    img.width = QR_SIZE;
    img.height = QR_SIZE;
    img.alt = "Receipt QR code";
    img.decoding = "async";
    img.src = qrImageUrl(payload);
    container.appendChild(img);
    container.setAttribute("data-payload", payload);
  }

  function renderQrCode(container, data) {
    if (!container) return Promise.resolve();
    container.innerHTML = "";
    var payload = qrPayload(data);
    var QR = global.QRCode;

    if (typeof QR === "function") {
      try {
        // Low/Medium ECC + larger modules = faster phone scan for short URLs
        // eslint-disable-next-line no-new
        new QR(container, {
          text: payload,
          width: QR_SIZE,
          height: QR_SIZE,
          colorDark: "#000000",
          colorLight: "#ffffff",
          correctLevel: QR.CorrectLevel ? QR.CorrectLevel.H : 2,
        });
        container.setAttribute("data-payload", payload);
        if (!container.querySelector("canvas, img, table")) {
          renderQrFallbackImage(container, payload);
        }
        return Promise.resolve();
      } catch (_) {
        /* fall through */
      }
    }

    if (QR && typeof QR.toCanvas === "function") {
      return new Promise(function (resolve) {
        var canvas = document.createElement("canvas");
        QR.toCanvas(
          canvas,
          payload,
          {
            width: QR_SIZE,
            margin: 2,
            color: { dark: "#000000", light: "#ffffff" },
            errorCorrectionLevel: "M",
          },
          function (err) {
            if (err) renderQrFallbackImage(container, payload);
            else {
              container.appendChild(canvas);
              container.setAttribute("data-payload", payload);
            }
            resolve();
          }
        );
      });
    }

    renderQrFallbackImage(container, payload);
    return Promise.resolve();
  }

  function printDocumentCss() {
    return (
      paperStyles() +
      "html,body{margin:0;padding:0;background:#fff}" +
      "body{padding:8px;display:flex;justify-content:center;align-items:flex-start}" +
      ".gw-receipt-paper{box-shadow:none;border:1px dashed #94a3b8}" +
      ".gw-receipt-actions{display:none!important}" +
      ".gw-receipt-qr-wrap{background:#fff!important;border:none!important;border-radius:0!important;padding:0!important}" +
      "@page{size:80mm 220mm;margin:3mm}" +
      "@media print{" +
      "html,body{width:auto!important;height:auto!important;min-height:0!important;margin:0!important;padding:0!important;background:#fff!important;overflow:hidden!important;-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}" +
      "body{display:block}" +
      ".gw-receipt-paper{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}" +
      ".gw-receipt-paper{width:74mm!important;max-width:74mm!important;height:auto!important;min-height:0!important;margin:0 auto!important;padding:3mm!important;box-shadow:none!important;border:1px dashed #000!important;page-break-inside:avoid;break-inside:avoid;font-size:10.5pt!important;color:#000!important}" +
      ".gw-receipt-brand,.gw-receipt-sub,.gw-receipt-row,.gw-receipt-row span,.gw-receipt-total,.gw-receipt-total span,.gw-receipt-qr-label,.gw-receipt-code,.gw-receipt-thanks{color:#000!important}" +
      ".gw-receipt-brand{font-size:12pt!important;font-weight:700!important}" +
      ".gw-receipt-row span:last-child,.gw-receipt-code,.gw-receipt-thanks,.gw-receipt-row span:first-child{font-weight:700!important}" +
      ".gw-receipt-sub,.gw-receipt-qr-label{font-weight:700!important}" +
      ".gw-receipt-row,.gw-receipt-sub,.gw-receipt-code,.gw-receipt-thanks,.gw-receipt-qr-label{font-size:10pt!important}" +
      ".gw-receipt-total{font-size:13pt!important;font-weight:800!important}" +
      ".gw-receipt-dash{border-top:1px dashed #000!important}" +
      ".gw-receipt-qr-label{margin:2mm 0 1.5mm!important}" +
      ".gw-receipt-qr-wrap{width:" +
      PRINT_QR_MM +
      "mm!important;height:" +
      PRINT_QR_MM +
      "mm!important;margin:1mm auto 2mm!important;padding:0!important;background:#fff!important;border:none!important}" +
      ".gw-receipt-qr-wrap canvas,.gw-receipt-qr-wrap img,.gw-receipt-qr-wrap table{width:" +
      PRINT_QR_MM +
      "mm!important;height:" +
      PRINT_QR_MM +
      "mm!important;max-width:" +
      PRINT_QR_MM +
      "mm!important;max-height:" +
      PRINT_QR_MM +
      "mm!important;image-rendering:pixelated!important;image-rendering:crisp-edges!important;background:#fff!important}" +
      "}"
    );
  }

  function waitForImages(doc, timeoutMs) {
    var imgs = Array.prototype.slice.call((doc && doc.images) || []);
    if (!imgs.length) return Promise.resolve();
    return new Promise(function (resolve) {
      var left = imgs.length;
      var done = function () {
        left -= 1;
        if (left <= 0) resolve();
      };
      global.setTimeout(resolve, timeoutMs || 4000);
      imgs.forEach(function (img) {
        if (img.complete) done();
        else {
          img.addEventListener("load", done, { once: true });
          img.addEventListener("error", done, { once: true });
        }
      });
    });
  }

  /** Bake QR into a data: PNG so phone print does not drop external/canvas QR */
  function bakeQrForPrint(paperEl, data) {
    if (!paperEl) return Promise.resolve();
    var wrap =
      paperEl.querySelector("#gw-receipt-qr") || paperEl.querySelector(".gw-receipt-qr-wrap");
    if (!wrap) return Promise.resolve();

    function putImg(dataUrl) {
      return new Promise(function (resolve) {
        wrap.innerHTML = "";
        var img = document.createElement("img");
        img.alt = "Receipt QR code";
        img.width = QR_SIZE;
        img.height = QR_SIZE;
        img.style.cssText =
          "display:block;width:" +
          QR_SIZE +
          "px;height:" +
          QR_SIZE +
          "px;background:#fff;image-rendering:pixelated";
        img.onload = function () {
          resolve();
        };
        img.onerror = function () {
          resolve();
        };
        img.src = dataUrl;
        wrap.appendChild(img);
        global.setTimeout(resolve, 1200);
      });
    }

    var canvas = wrap.querySelector("canvas");
    if (canvas && canvas.width) {
      try {
        return putImg(canvas.toDataURL("image/png"));
      } catch (_) {
        /* fall through */
      }
    }

    var existing = wrap.querySelector("img");
    if (existing && existing.src && String(existing.src).indexOf("data:") === 0) {
      return Promise.resolve();
    }

    // Rebuild QR with library → canvas → data URL (works offline / on phone print)
    var payload = qrPayload(data || {});
    var QR = global.QRCode;
    if (typeof QR === "function") {
      return new Promise(function (resolve) {
        var tmp = document.createElement("div");
        tmp.style.cssText = "position:fixed;left:-9999px;top:0;width:1px;height:1px;overflow:hidden";
        document.body.appendChild(tmp);
        try {
          // eslint-disable-next-line no-new
          new QR(tmp, {
            text: payload,
            width: QR_SIZE,
            height: QR_SIZE,
            colorDark: "#000000",
            colorLight: "#ffffff",
            correctLevel: QR.CorrectLevel ? QR.CorrectLevel.H : 2,
          });
        } catch (_) {
          tmp.remove();
          resolve();
          return;
        }
        global.setTimeout(function () {
          var c = tmp.querySelector("canvas");
          var im = tmp.querySelector("img");
          var url = "";
          try {
            if (c && c.width) url = c.toDataURL("image/png");
            else if (im && im.src) url = im.src;
          } catch (_) {
            url = "";
          }
          tmp.remove();
          if (url) {
            putImg(url).then(resolve);
          } else {
            // Last resort: remote PNG, wait until loaded
            var remote = qrImageUrl(payload, 200);
            putImg(remote).then(resolve);
          }
        }, 350);
      });
    }

    return putImg(qrImageUrl(payload, 200));
  }

  function waitUntilQrReady(paperEl, data, timeoutMs) {
    var limit = timeoutMs || 10000;
    var start = Date.now();
    return new Promise(function (resolve) {
      function tick() {
        var wrap =
          paperEl &&
          (paperEl.querySelector("#gw-receipt-qr") || paperEl.querySelector(".gw-receipt-qr-wrap"));
        var node = wrap && wrap.querySelector("canvas, img, table");
        var img = wrap && wrap.querySelector("img");
        var canvas = wrap && wrap.querySelector("canvas");
        var ready =
          (canvas && canvas.width > 0) ||
          (img && img.complete && img.naturalWidth > 0) ||
          (node && node.tagName === "TABLE");
        if (ready || Date.now() - start > limit) {
          resolve();
          return;
        }
        global.setTimeout(tick, 120);
      }
      if (!paperEl) {
        resolve();
        return;
      }
      var wrap0 =
        paperEl.querySelector("#gw-receipt-qr") || paperEl.querySelector(".gw-receipt-qr-wrap");
      if (wrap0 && !wrap0.querySelector("canvas, img, table")) {
        void renderQrCode(wrap0, data).then(tick);
      } else {
        tick();
      }
    });
  }

  /**
   * Print without opening a blank about:blank tab.
   * Clones the on-screen receipt (QR baked as data URL) into a hidden iframe.
   */
  function printFromPaperElement(paperEl) {
    if (!paperEl) return Promise.reject(new Error("Receipt paper not found"));

    var prev = document.getElementById("gw-auto-print-frame");
    if (prev) prev.remove();

    var iframe = document.createElement("iframe");
    iframe.id = "gw-auto-print-frame";
    iframe.setAttribute("title", "Print receipt");
    iframe.style.cssText =
      "position:fixed;left:-10000px;top:0;width:420px;height:900px;border:0;opacity:0;pointer-events:none";
    document.body.appendChild(iframe);

    var win = iframe.contentWindow;
    var idoc = win && win.document;
    if (!idoc) {
      iframe.remove();
      return Promise.reject(new Error("Print frame unavailable"));
    }

    var clone = paperEl.cloneNode(true);
    clone.id = "gw-receipt-paper-print";
    idoc.open();
    idoc.write(
      "<!DOCTYPE html><html><head><meta charset=\"utf-8\"/><title>Receipt</title><style>" +
        printDocumentCss() +
        "</style></head><body></body></html>"
    );
    idoc.close();
    idoc.body.appendChild(clone);

    return waitForImages(idoc, 8000).then(function () {
      return new Promise(function (resolve) {
        global.setTimeout(function () {
          try {
            win.focus();
            win.print();
          } catch (e) {
            /* ignore */
          }
          global.setTimeout(function () {
            try {
              iframe.remove();
            } catch (_) {
              /* ignore */
            }
            resolve();
          }, 1500);
        }, 300);
      });
    });
  }

  function ensureQrInPaper(paperEl, data) {
    if (!paperEl) return Promise.resolve();
    var qrEl = paperEl.querySelector("#gw-receipt-qr") || paperEl.querySelector(".gw-receipt-qr-wrap");
    if (!qrEl) return Promise.resolve();
    if (qrEl.querySelector("canvas, img, table")) return Promise.resolve();
    return renderQrCode(qrEl, data);
  }

  /** Manual Print button + auto-print — never opens a white popup tab */
  function openPrintWindow(data, options) {
    var paper = document.getElementById("gw-receipt-paper");
    if (paper) {
      void ensureQrInPaper(paper, data)
        .then(function () {
          return waitUntilQrReady(paper, data, 10000);
        })
        .then(function () {
          return bakeQrForPrint(paper, data);
        })
        .then(function () {
          return printFromPaperElement(paper);
        });
      return;
    }
    injectStylesOnce();
    var wrap = document.createElement("div");
    wrap.style.cssText = "position:fixed;left:-10000px;top:0;width:340px;";
    wrap.innerHTML = buildPaperHtml(data, options || {});
    document.body.appendChild(wrap);
    var built = wrap.querySelector("#gw-receipt-paper");
    void ensureQrInPaper(built, data)
      .then(function () {
        return waitUntilQrReady(built, data, 10000);
      })
      .then(function () {
        return bakeQrForPrint(built, data);
      })
      .then(function () {
        return printFromPaperElement(built);
      })
      .finally(function () {
        wrap.remove();
      });
  }

  function autoPrintReceipt(data, options) {
    if (!getAutoPrintPreference()) return;
    openPrintWindow(data, options);
  }

  /** Hidden receipt page — works well with POS / thermal printers (receipt.php?print=1). */
  function printViaReceiptPage(data) {
    var url = qrPayload(data);
    if (!/[?&]print=1(?:&|$)/.test(url)) {
      url += (url.indexOf("?") >= 0 ? "&" : "?") + "print=1";
    }
    var prev = document.getElementById("gw-receipt-page-print-frame");
    if (prev) prev.remove();
    var iframe = document.createElement("iframe");
    iframe.id = "gw-receipt-page-print-frame";
    iframe.setAttribute("title", "Auto print receipt");
    iframe.style.cssText =
      "position:fixed;left:-10000px;top:0;width:420px;height:900px;border:0;opacity:0;pointer-events:none";
    iframe.src = url;
    document.body.appendChild(iframe);
    global.setTimeout(function () {
      try {
        iframe.remove();
      } catch (_) {
        /* ignore */
      }
    }, 20000);
  }

  async function downloadPaperPng(data, options) {
    var paper = document.getElementById("gw-receipt-paper");
    if (!paper) {
      downloadPaperHtml(data, options);
      return;
    }
    if (typeof global.html2canvas !== "function") {
      downloadPaperHtml(data, options);
      return;
    }
    var canvas = await global.html2canvas(paper, {
      backgroundColor: "#ffffff",
      scale: 2,
      useCORS: true,
    });
    var link = document.createElement("a");
    var ref = String(data.orderReference || "receipt").replace(/[^\w-]+/g, "_");
    link.download = "Getway-Receipt-" + ref + ".png";
    link.href = canvas.toDataURL("image/png");
    link.click();
  }

  function downloadPaperHtml(data, options) {
    var payload = qrPayload(data);
    var html =
      "<!DOCTYPE html><html><head><meta charset=\"utf-8\"/><title>Receipt " +
      escapeHtml(data.orderReference || "") +
      "</title><style>" +
      paperStyles() +
      "body{margin:24px;background:#fff;display:flex;justify-content:center}</style>" +
      '<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"><\\/script></head><body>' +
      buildPaperHtml(data, options) +
      "<script>document.addEventListener('DOMContentLoaded',function(){var el=document.getElementById('gw-receipt-qr');var payload=" +
      JSON.stringify(payload) +
      ";if(!el)return;if(typeof QRCode==='function'){try{new QRCode(el,{text:payload,width:" +
      QR_SIZE +
      ",height:" +
      QR_SIZE +
      "});return;}catch(e){}}" +
      "el.innerHTML='<img width=\"" +
      QR_SIZE +
      '" height="' +
      QR_SIZE +
      '" alt="QR" src="https://api.qrserver.com/v1/create-qr-code/?size=' +
      QR_SIZE +
      "x" +
      QR_SIZE +
      '&data=\'+encodeURIComponent(payload)+\'"/>\';});<\\/script></body></html>';
    var blob = new Blob([html], { type: "text/html;charset=utf-8" });
    var url = URL.createObjectURL(blob);
    var link = document.createElement("a");
    var ref = String(data.orderReference || "receipt").replace(/[^\w-]+/g, "_");
    link.href = url;
    link.download = "Getway-Receipt-" + ref + ".html";
    link.click();
    setTimeout(function () {
      URL.revokeObjectURL(url);
    }, 2000);
  }

  function actionButtonsHtml() {
    return `
      <div class="gw-receipt-actions">
        <button type="button" class="gw-receipt-btn gw-receipt-btn--download" id="gw-receipt-download">
          <i class="fa-solid fa-download" aria-hidden="true"></i> Download
        </button>
        <button type="button" class="gw-receipt-btn gw-receipt-btn--print" id="gw-receipt-print">
          <i class="fa-solid fa-print" aria-hidden="true"></i> Print
        </button>
      </div>
    `;
  }

  function showPaymentReceipt(kind, data, formMessageEl) {
    var ok = String(kind || "").toUpperCase() === "SUCCESS";
    var when = new Date().toLocaleString();
    var receiptData = {
      orderReference: data.orderReference || "",
      amount: data.amount || 0,
      currency: data.currency || "TZS",
      phone: data.phone || "",
      mobileChannel: data.mobileChannel || data.channel || "",
      status: ok ? "SUCCESS" : String(kind || "FAILED").toUpperCase(),
      description: data.description || "AutoPay HaloPesa Payment",
      customerName: data.customerName || "Customer",
      when: when,
    };

    if (formMessageEl) {
      formMessageEl.textContent = "";
      formMessageEl.classList.remove("is-ok", "is-error");
    }

    if (!global.Swal || typeof global.Swal.fire !== "function") return;

    injectStylesOnce();

    global.Swal.fire({
      icon: ok ? "success" : "error",
      title: ok ? "Payment Successful" : "Payment Failed",
      html:
        buildPaperHtml(receiptData, { ok: ok }) +
        actionButtonsHtml() +
        (ok
          ? '<p id="gw-auto-print-hint" style="margin:10px 0 0;font:600 0.75rem/1.35 DM Sans,system-ui,sans-serif;color:#64748b;text-align:center">Sending to printer…</p>'
          : ""),
      showConfirmButton: true,
      confirmButtonText: "Close receipt",
      confirmButtonColor: ok ? "#0f766e" : "#b91c1c",
      width: 380,
      didOpen: function () {
        var qrEl = document.getElementById("gw-receipt-qr");
        var dlBtn = document.getElementById("gw-receipt-download");
        var printBtn = document.getElementById("gw-receipt-print");
        var hint = document.getElementById("gw-auto-print-hint");
        var sawPrint = false;

        function onBeforePrint() {
          sawPrint = true;
          if (hint) hint.textContent = "Print dialog open — choose your printer.";
        }
        global.addEventListener("beforeprint", onBeforePrint);

        void renderQrCode(qrEl, receiptData).then(function () {
          if (!ok) return;
          var paper = document.getElementById("gw-receipt-paper");
          // Phone networks are slower — wait until QR is ready, bake it, then print
          void waitUntilQrReady(paper, receiptData, 12000)
            .then(function () {
              return bakeQrForPrint(paper, receiptData);
            })
            .then(function () {
              return new Promise(function (r) {
                global.setTimeout(r, 400);
              });
            })
            .then(function () {
              if (!getAutoPrintPreference()) return;
              openPrintWindow(receiptData, { ok: ok });
              global.setTimeout(function () {
                if (!sawPrint) {
                  printViaReceiptPage(receiptData);
                }
              }, 3000);
              global.setTimeout(function () {
                if (!sawPrint && hint) {
                  hint.style.color = "#b45309";
                  hint.textContent = "Bofya Print ili risiti itoke kwenye printer.";
                }
                if (!sawPrint && printBtn) {
                  printBtn.style.color = "#0f766e";
                  printBtn.style.textDecoration = "underline";
                  printBtn.focus();
                }
              }, 5500);
            });
        });

        if (printBtn) {
          printBtn.addEventListener("click", function () {
            if (hint) {
              hint.style.color = "#64748b";
              hint.textContent = "Sending to printer…";
            }
            openPrintWindow(receiptData, { ok: ok });
          });
        }

        if (dlBtn) {
          dlBtn.addEventListener("click", function () {
            if (global.GetwayReceiptActions && typeof global.GetwayReceiptActions.chooseDownload === "function") {
              global.GetwayReceiptActions.chooseDownload({
                orderReference: receiptData.orderReference,
                amount: receiptData.amount,
                status: receiptData.status,
                phone: receiptData.phone,
                channel: receiptData.mobileChannel || receiptData.channel,
              });
              return;
            }
            void (async function () {
              dlBtn.disabled = true;
              try {
                await downloadPaperPng(receiptData, { ok: ok });
              } finally {
                dlBtn.disabled = false;
              }
            })();
          });
        }

        global.Swal.getPopup() &&
          global.Swal.getPopup().addEventListener(
            "remove",
            function () {
              global.removeEventListener("beforeprint", onBeforePrint);
            },
            { once: true }
          );
      },
    });
  }

  global.GetwayReceipt = {
    showPaymentReceipt: showPaymentReceipt,
    buildPaperHtml: buildPaperHtml,
    qrPayload: qrPayload,
    openPrintWindow: openPrintWindow,
    autoPrintReceipt: autoPrintReceipt,
    printViaReceiptPage: printViaReceiptPage,
    isAutoPrintEnabled: isAutoPrintEnabled,
    getAutoPrintPreference: getAutoPrintPreference,
    setAutoPrintEnabled: setAutoPrintEnabled,
  };
})(window);
