const API_BASE = window.TIS_API_BASE || `${window.location.origin}/api/tis`;
const API_HEADERS = {
  "Content-Type": "application/json",
  "ngrok-skip-browser-warning": "true",
};

const formMessageEl = document.getElementById("form-message");
const paymentFormEl = document.getElementById("payment-form");
const payNowBtn = document.getElementById("pay-now-btn");
const orderTotalEl = document.getElementById("order-total");
const phoneEl = document.getElementById("customerPhone");
const amountEl = document.getElementById("custom-product-amount");
const descriptionEl = document.getElementById("description");
const customerNameEl = document.getElementById("customerName");
const customerEmailEl = document.getElementById("customerEmail");

function formatNumber(value) {
  return new Intl.NumberFormat("en-US", {
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
  }).format(Number(value || 0));
}

function normalizeAmount() {
  const raw = amountEl ? String(amountEl.value || "").trim() : "";
  const parsed = Number(raw);
  if (!Number.isFinite(parsed) || parsed <= 0) {
    return 0;
  }
  return Math.floor(parsed);
}

function updateTotalView() {
  const total = normalizeAmount();
  if (orderTotalEl) {
    orderTotalEl.textContent = `TZS ${formatNumber(total)}`;
  }
  return total;
}

function hideInventorySections() {
  const ids = [
    "inventory-list",
    "refresh-inventory-btn",
    "custom-product-name",
    "custom-product-hint",
    "payments-body",
    "total-sales",
    "total-failed",
    "total-pending",
    "total-transactions",
  ];
  ids.forEach((id) => {
    const el = document.getElementById(id);
    if (!el) return;
    const card = el.closest(".cp-dashboard-card");
    if (card) {
      card.style.display = "none";
      return;
    }
    const section = el.closest(".section-heading");
    if (section) {
      section.style.display = "none";
      return;
    }
    el.style.display = "none";
  });
}

function hideOptionalCustomerFields() {
  const optionalIds = ["customerName", "customerEmail", "description", "custom-product-name"];
  optionalIds.forEach((id) => {
    const input = document.getElementById(id);
    if (!input) return;
    const label = document.querySelector(`label[for="${id}"]`);
    if (label) label.style.display = "none";
    input.style.display = "none";
  });
}

function showWaitSwal() {
  if (!window.Swal || typeof window.Swal.fire !== "function") {
    return;
  }
  window.Swal.fire({
    title: "Please wait",
    html: '<p style="margin:0.35rem 0 0;font-size:0.95rem;font-weight:600;color:#475569">Creating checkout…</p>',
    allowOutsideClick: false,
    allowEscapeKey: false,
    showConfirmButton: false,
    backdrop: true,
  });
}

function dismissWaitSwal() {
  if (!window.Swal || typeof window.Swal.close !== "function") {
    return;
  }
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
  return String(data.message || data.error || data.details || `Checkout creation failed (HTTP ${responseStatus}).`);
}

async function createPayment(event) {
  event.preventDefault();
  if (formMessageEl) formMessageEl.textContent = "";

  const total = updateTotalView();
  const phone = String(phoneEl?.value || "").trim();
  if (!phone) {
    if (formMessageEl) formMessageEl.textContent = "Enter customer phone number first.";
    return;
  }
  if (total <= 0) {
    if (formMessageEl) formMessageEl.textContent = "Enter a valid amount greater than 0.";
    return;
  }

  const payload = {
    totalPrice: String(total),
    customerPhone: phone,
    customerName: String(customerNameEl?.value || "Customer").trim() || "Customer",
    customerEmail: String(customerEmailEl?.value || "customer@example.com").trim() || "customer@example.com",
    description: String(descriptionEl?.value || "ClickPesa Payment").trim() || "ClickPesa Payment",
    orderCurrency: "TZS",
  };

  if (payNowBtn) payNowBtn.disabled = true;
  showWaitSwal();
  try {
    const response = await fetch(`${API_BASE}/create-payment`, {
      method: "POST",
      headers: API_HEADERS,
      body: JSON.stringify(payload),
    });
    const data = await readResponsePayload(response);
    if (!response.ok) {
      if (formMessageEl) formMessageEl.textContent = formatApiErrorMessage(data, response.status);
      return;
    }
    if (!data.checkoutLink) {
      if (formMessageEl) formMessageEl.textContent = "API did not return a checkout link.";
      return;
    }
    if (formMessageEl) formMessageEl.textContent = "Redirecting to ClickPesa checkout...";
    window.location.assign(data.checkoutLink);
  } catch (error) {
    if (formMessageEl) {
      formMessageEl.textContent =
        error?.message || "Connection failed. Ensure backend server is running.";
    }
  } finally {
    dismissWaitSwal();
    if (payNowBtn) payNowBtn.disabled = false;
  }
}

hideInventorySections();
hideOptionalCustomerFields();
updateTotalView();
if (amountEl) {
  amountEl.addEventListener("input", updateTotalView);
}
if (paymentFormEl) {
  paymentFormEl.addEventListener("submit", createPayment);
}
