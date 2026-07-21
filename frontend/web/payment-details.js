const API_BASE = window.TIS_API_BASE || `${window.location.origin}/api/tis`;
const CLICKPESA_API_BASE = window.CLICKPESA_API_BASE || `${window.location.origin}/api/clickpesa`;
const API_HEADERS = {
  "ngrok-skip-browser-warning": "true",
};

async function fetchPaymentsSummary() {
  // Merge Yii DB + Node so empty Yii no longer hides live AutoPay history.
  if (window.GetwayPaymentsMerge && typeof window.GetwayPaymentsMerge.loadMergedPayments === "function") {
    return window.GetwayPaymentsMerge.loadMergedPayments(API_BASE, CLICKPESA_API_BASE, API_HEADERS);
  }
  const response = await fetch(`${API_BASE}/payments`, { cache: "no-store", headers: API_HEADERS });
  const data = await response.json();
  if (!response.ok) {
    throw new Error(data.message || "Failed to load payments.");
  }
  return data;
}

async function fetchPaymentDetails(type) {
  const safeType = type === "failed" || type === "pending" ? type : "success";
  if (window.GetwayPaymentsMerge && typeof window.GetwayPaymentsMerge.loadMergedDetails === "function") {
    return window.GetwayPaymentsMerge.loadMergedDetails(
      safeType,
      API_BASE,
      CLICKPESA_API_BASE,
      API_HEADERS
    );
  }
  const response = await fetch(`${API_BASE}/payments/details?type=${encodeURIComponent(safeType)}`, {
    cache: "no-store",
    headers: API_HEADERS,
  });
  const data = await response.json();
  if (!response.ok) {
    throw new Error(data.message || "Failed to load details.");
  }
  return data;
}

function notifyWalletUpdated() {
  try {
    document.dispatchEvent(new CustomEvent("necta-wallet-updated"));
  } catch (_) {
    /* ignore */
  }
}

const detailsTitleEl = document.getElementById("details-title");
const detailsBadgeEl = document.getElementById("details-badge");
const detailsBodyEl = document.getElementById("details-body");
const detailsCountEl = document.getElementById("details-count");
const refreshBtn = document.getElementById("refresh-details");

const pickSuccessAmt = document.getElementById("pick-success-amt");
const pickSuccessN = document.getElementById("pick-success-n");
const pickFailedAmt = document.getElementById("pick-failed-amt");
const pickFailedN = document.getElementById("pick-failed-n");
const pickPendingN = document.getElementById("pick-pending-n");

function formatNumber(value) {
  return new Intl.NumberFormat("en-US", {
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
  }).format(Number(value || 0));
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
  return s === "SUCCESS" || s === "PAID" || s === "SUCCESSFUL" || s === "COMPLETED" || s === "SETTLED";
}

function isFailedStatus(value) {
  const s = String(value || "").trim().toUpperCase();
  return s === "FAILED" || s === "FAILURE";
}

function getTypeConfig(type) {
  if (type === "success") {
    return {
      title: "Successful Payments",
      badgeClass: "success",
      badgeIcon: "fa-circle-check",
    };
  }
  if (type === "failed") {
    return {
      title: "Failed Payments",
      badgeClass: "failed",
      badgeIcon: "fa-circle-xmark",
    };
  }
  return {
    title: "Unpaid Transactions",
    badgeClass: "pending",
    badgeIcon: "fa-hourglass-half",
  };
}

function badgeForStatus(status) {
  const s = String(status || "").toUpperCase();
  if (s === "SUCCESS" || s === "PAID") {
    return '<span class="badge badge--success"><i class="fa-solid fa-circle-check"></i>Success</span>';
  }
  if (s === "FAILED") {
    return '<span class="badge badge--failed"><i class="fa-solid fa-circle-xmark"></i>Failed</span>';
  }
  if (s === "PENDING") {
    return '<span class="badge badge--pending"><i class="fa-solid fa-hourglass-half"></i>Pending</span>';
  }
  return `<span class="badge badge--muted">${escapeHtml(status || "—")}</span>`;
}

function renderRows(rows) {
  if (!rows.length) {
    detailsBodyEl.innerHTML = `
      <tr>
        <td colspan="6" class="empty-state">No records found.</td>
      </tr>
    `;
    return;
  }

  detailsBodyEl.innerHTML = rows
    .map((row) => {
      const createdAt = row.createdAt ? new Date(row.createdAt).toLocaleString() : "—";
      const ref = escapeHtml(row.orderReference || "—");
      const phone = escapeHtml(row.phone || "—");
      const actions =
        window.GetwayReceiptActions && typeof window.GetwayReceiptActions.actionButtonsHtml === "function"
          ? window.GetwayReceiptActions.actionButtonsHtml(row)
          : "";
      return `
        <tr>
          <td class="col-ref">${ref}</td>
          <td class="col-amount">TZS ${formatNumber(row.amount)}</td>
          <td>${badgeForStatus(row.status)}</td>
          <td>${phone}</td>
          <td>${escapeHtml(createdAt)}</td>
          <td class="col-actions">${actions}</td>
        </tr>
      `;
    })
    .join("");
}

function syncPickHighlight(type) {
  document.querySelectorAll(".records-pick-card").forEach((el) => {
    el.classList.toggle("is-active", el.dataset.type === type);
  });
}

function syncHistoryNavHref(type) {
  const link = document.querySelector('.w-bottom-nav a[href*="payment-details"]');
  if (link) {
    link.href = `payment-details.php?type=${encodeURIComponent(type)}`;
  }
}

async function loadSummary() {
  const data = await fetchPaymentsSummary();
  const payments = data.payments || [];
  const successN = payments.filter((p) => isSuccessStatus(p.status)).length;
  const failedN = payments.filter((p) => isFailedStatus(p.status)).length;
  const pendingOrders = Number(data.pendingTransactions || 0);

  if (pickSuccessAmt) pickSuccessAmt.textContent = `TZS ${formatNumber(data.totalSales || 0)}`;
  if (pickSuccessN) pickSuccessN.textContent = formatNumber(successN);
  if (pickFailedAmt) pickFailedAmt.textContent = `TZS ${formatNumber(data.failedSales || 0)}`;
  if (pickFailedN) pickFailedN.textContent = formatNumber(failedN);
  if (pickPendingN) pickPendingN.textContent = formatNumber(pendingOrders);
  notifyWalletUpdated();
}

let currentType = "success";

async function loadDetails() {
  const params = new URLSearchParams(window.location.search);
  const type = String(params.get("type") || "success").toLowerCase();
  const safeType = type === "failed" || type === "pending" ? type : "success";
  currentType = safeType;
  const config = getTypeConfig(safeType);

  syncPickHighlight(safeType);
  syncHistoryNavHref(safeType);

  detailsTitleEl.innerHTML = `<i class="fa-solid fa-table-list"></i> ${config.title}`;
  detailsBadgeEl.className = `details-badge ${config.badgeClass}`;
  detailsBadgeEl.innerHTML = `<i class="fa-solid ${config.badgeIcon}"></i> ${config.title}`;

  const data = await fetchPaymentDetails(safeType);
  const rows = data.rows || [];
  renderRows(rows);
  if (detailsCountEl) {
    detailsCountEl.textContent = `${rows.length} record${rows.length === 1 ? "" : "s"}`;
  }
  notifyWalletUpdated();
}

function setupLiveUpdates() {
  // Polling-only mode keeps this page stable across shared hosts and tunnels.
  return;
}

function startPolling() {
  const intervalMs = 20000;
  return window.setInterval(() => {
    Promise.all([loadSummary(), loadDetails()]).catch(() => {});
  }, intervalMs);
}

Promise.all([loadSummary(), loadDetails()])
  .then(() => {
    setupLiveUpdates();
    const pollId = startPolling();
    window.addEventListener("beforeunload", () => window.clearInterval(pollId));
    notifyWalletUpdated();
  })
  .catch((error) => {
    detailsBodyEl.innerHTML = `
    <tr>
      <td colspan="6" class="empty-state">${escapeHtml(error.message || "Failed to load data.")}</td>
    </tr>
  `;
  });

if (refreshBtn) {
  refreshBtn.addEventListener("click", () => {
    refreshBtn.disabled = true;
    Promise.all([loadSummary(), loadDetails()])
      .catch((error) => {
        detailsBodyEl.innerHTML = `
        <tr>
          <td colspan="6" class="empty-state">${escapeHtml(error.message || "Failed to load data.")}</td>
        </tr>
      `;
      })
      .finally(() => {
        refreshBtn.disabled = false;
        notifyWalletUpdated();
      });
  });
}
