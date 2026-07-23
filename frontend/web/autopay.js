const RENDER_API = "https://getways-app.onrender.com";
const API_BASE = window.BASE_API_URL || window.TIS_API_BASE || RENDER_API;
const API_HEADERS = {
  "Content-Type": "application/json",
};

const formMessageEl = document.getElementById("form-message");
const formEl = document.getElementById("autopay-form");
const payBtn = document.getElementById("autopay-btn");
const orderTotalEl = document.getElementById("order-total");
const phoneEl = document.getElementById("customerPhone");
const amountEl = document.getElementById("amount");
const descriptionEl = document.getElementById("description");
const customerNameEl = document.getElementById("customerName");

const POLL_INTERVAL_MS = 2500;
const POLL_MAX_MS = 180000; // 3 minutes — customer may take time on PIN

function formatNumber(value) {
  return new Intl.NumberFormat("en-US", {
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
  }).format(Number(value || 0));
}

function normalizeAmount() {
  const parsed = Number(String(amountEl?.value || "").trim());
  if (!Number.isFinite(parsed) || parsed <= 0) return 0;
  return Math.floor(parsed);
}

function updateTotalView() {
  const total = normalizeAmount();
  if (orderTotalEl) orderTotalEl.textContent = `TZS ${formatNumber(total)}`;
  return total;
}

function showWaitSwal(title, html) {
  if (!window.Swal || typeof window.Swal.fire !== "function") return;
  window.Swal.fire({
    title: title || "Please wait",
    html:
      html ||
      '<p style="margin:0.35rem 0 0;font-size:0.95rem;font-weight:600;color:#475569">Sending USSD push…</p>',
    allowOutsideClick: false,
    allowEscapeKey: false,
    showConfirmButton: false,
    backdrop: true,
  });
}

function dismissWaitSwal() {
  if (!window.Swal || typeof window.Swal.close !== "function") return;
  try {
    window.Swal.close();
  } catch (_) {
    /* ignore */
  }
}

async function readResponsePayload(response) {
  const text = await response.text();
  if (!text) return {};
  try {
    return JSON.parse(text);
  } catch (_) {
    return { message: text.slice(0, 240) };
  }
}

function formatApiErrorMessage(data, responseStatus) {
  if (!data || typeof data !== "object") {
    return responseStatus ? `Server error (HTTP ${responseStatus}).` : "Unknown error.";
  }
  const details = data.details;
  const nested =
    (details && typeof details === "object" && (details.message || details.error || details.Message)) ||
    (typeof details === "string" ? details : null);
  const msg = data.message || data.error || nested || data.details;
  if (typeof msg === "string" && msg.trim()) return msg.trim();
  if (msg && typeof msg === "object") {
    return String(msg.message || msg.error || JSON.stringify(msg)).trim();
  }
  return `AutoPay failed (HTTP ${responseStatus}).`;
}

function showErrorSwal(message, title) {
  const text = String(message || "Something went wrong.").trim();
  if (formMessageEl) {
    formMessageEl.textContent = "";
    formMessageEl.classList.remove("is-ok", "is-error");
  }
  if (!window.Swal || typeof window.Swal.fire !== "function") {
    window.alert(text);
    return;
  }
  window.Swal.fire({
    icon: "error",
    title: title || "AutoPay Error",
    text,
    confirmButtonText: "OK",
    confirmButtonColor: "#b91c1c",
  });
}

function escapeHtml(value) {
  return String(value || "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
}

function showReceipt(kind, data) {
  if (window.GetwayReceipt && typeof window.GetwayReceipt.showPaymentReceipt === "function") {
    window.GetwayReceipt.showPaymentReceipt(kind, data, formMessageEl);
    return;
  }
  // Fallback if receipt-slip.js failed to load
  if (!window.Swal || typeof window.Swal.fire !== "function") return;
  window.Swal.fire({
    icon: kind === "SUCCESS" ? "success" : "error",
    title: kind === "SUCCESS" ? "Payment Successful" : "Payment Failed",
    text: `Ref: ${data.orderReference || ""} · TZS ${formatNumber(data.amount || 0)}`,
    confirmButtonText: "Close receipt",
  });
}

async function pollPaymentStatus(orderReference, fallbackAmount, fallbackPhone) {
  const started = Date.now();
  showWaitSwal(
    "Waiting for PIN…",
    '<p style="margin:0.35rem 0 0;font-size:0.95rem;font-weight:600;color:#475569">Customer should enter PIN on the phone.<br>We will show the receipt when done.</p>'
  );

  while (Date.now() - started < POLL_MAX_MS) {
    try {
      const response = await fetch(
        `${API_BASE}/autopay/payment-status/${encodeURIComponent(orderReference)}`,
        { cache: "no-store", headers: API_HEADERS }
      );
      const data = await readResponsePayload(response);
      if (response.ok) {
        const status = String(data.status || data.rawStatus || "")
          .trim()
          .toUpperCase()
          .replace(/\s+/g, "_");
        const receipt = {
          orderReference: data.orderReference || orderReference,
          amount: data.amount || fallbackAmount,
          currency: data.currency || "TZS",
          phone: data.phone || fallbackPhone,
          mobileChannel: data.mobileChannel || "",
          customerName: String(customerNameEl?.value || "Customer").trim() || "Customer",
          description: String(descriptionEl?.value || "AutoPay HaloPesa Payment").trim(),
        };
        const successSet = new Set([
          "SUCCESS",
          "SUCCESSFUL",
          "SETTLED",
          "COMPLETED",
          "PAID",
          "APPROVED",
        ]);
        const failedSet = new Set([
          "FAILED",
          "FAILURE",
          "DECLINED",
          "REJECTED",
          "CANCELLED",
          "CANCELED",
          "EXPIRED",
        ]);
        if (successSet.has(status)) {
          dismissWaitSwal();
          showReceipt("SUCCESS", receipt);
          return;
        }
        if (failedSet.has(status)) {
          dismissWaitSwal();
          showReceipt("FAILED", receipt);
          return;
        }
      }
    } catch (_) {
      /* keep polling */
    }
    await new Promise((r) => window.setTimeout(r, POLL_INTERVAL_MS));
  }

  dismissWaitSwal();
  if (window.Swal && typeof window.Swal.fire === "function") {
    window.Swal.fire({
      icon: "warning",
      title: "Still waiting",
      html: `<p style="margin:0;font-weight:600;color:#334155">No final status yet for <strong>${escapeHtml(
        orderReference
      )}</strong>.</p>
      <p style="margin:8px 0 0;font-size:0.85rem;color:#64748b">Customer may still be entering PIN, or the push timed out. Try again if needed.</p>`,
      confirmButtonText: "OK",
    });
  }
}

async function createAutoPay(event) {
  event.preventDefault();
  if (formMessageEl) {
    formMessageEl.textContent = "";
    formMessageEl.classList.remove("is-ok", "is-error");
  }

  const total = updateTotalView();
  const phone = String(phoneEl?.value || "").trim();
  if (!phone) {
    showErrorSwal("Enter customer phone number first.", "Missing phone");
    return;
  }
  if (total <= 0) {
    showErrorSwal("Enter a valid amount greater than 0.", "Invalid amount");
    return;
  }

  const payload = {
    totalPrice: String(total),
    customerPhone: phone,
    customerName: String(customerNameEl?.value || "Customer").trim() || "Customer",
    customerEmail: "customer@example.com",
    description: String(descriptionEl?.value || "AutoPay HaloPesa Payment").trim() || "AutoPay HaloPesa Payment",
    orderCurrency: "TZS",
  };

  if (payBtn) payBtn.disabled = true;
  showWaitSwal(
    "Please wait",
    '<p style="margin:0.35rem 0 0;font-size:0.95rem;font-weight:600;color:#475569">Sending USSD push to phone…</p>'
  );
  try {
    const response = await fetch(`${API_BASE}/autopay/create-payment`, {
      method: "POST",
      headers: API_HEADERS,
      body: JSON.stringify(payload),
    });
    const data = await readResponsePayload(response);
    if (!response.ok) {
      dismissWaitSwal();
      showErrorSwal(formatApiErrorMessage(data, response.status), "AutoPay Error");
      return;
    }

    const orderReference = String(data.orderReference || "").trim();
    if (!orderReference) {
      dismissWaitSwal();
      showErrorSwal("No order reference returned.", "AutoPay Error");
      return;
    }

    // Immediate success/fail (rare) — show receipt now
    const immediate = String(data.status || "").toUpperCase();
    if (immediate === "SUCCESS" || immediate === "SETTLED") {
      dismissWaitSwal();
      showReceipt("SUCCESS", {
        orderReference,
        amount: total,
        currency: "TZS",
        phone,
        mobileChannel: data.mobileChannel || "",
        customerName: payload.customerName,
        description: payload.description,
      });
      return;
    }
    if (immediate === "FAILED") {
      dismissWaitSwal();
      showReceipt("FAILED", {
        orderReference,
        amount: total,
        currency: "TZS",
        phone,
        mobileChannel: data.mobileChannel || "",
        customerName: payload.customerName,
        description: payload.description,
      });
      return;
    }

    // Wait for customer PIN → then success or failed receipt
    await pollPaymentStatus(orderReference, total, phone);
  } catch (error) {
    dismissWaitSwal();
    showErrorSwal(
      error?.message || "Connection failed. Ensure backend server is running.",
      "Connection Error"
    );
  } finally {
    if (payBtn) payBtn.disabled = false;
  }
}

updateTotalView();
if (amountEl) amountEl.addEventListener("input", updateTotalView);
if (formEl) formEl.addEventListener("submit", createAutoPay);

(function initAutoPrintFromUrl() {
  const params = new URLSearchParams(window.location.search);
  if (params.get("autoprint") === "1" || params.get("print") === "1") {
    window.GetwayReceipt?.setAutoPrintEnabled?.(true);
  }
  if (params.get("autoprint") === "0" || params.get("print") === "0") {
    window.GetwayReceipt?.setAutoPrintEnabled?.(false);
  }
})();
