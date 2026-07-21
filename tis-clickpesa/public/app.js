const inventoryListEl = document.getElementById("inventory-list");
const orderTotalEl = document.getElementById("order-total");
const formMessageEl = document.getElementById("form-message");
const paymentFormEl = document.getElementById("payment-form");
const paymentsBodyEl = document.getElementById("payments-body");
const totalSalesEl = document.getElementById("total-sales");
const totalTransactionsEl = document.getElementById("total-transactions");

let inventoryItems = [];

function formatNumber(value) {
  return new Intl.NumberFormat("en-US", {
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
  }).format(Number(value || 0));
}

function getSelectedItems() {
  return inventoryItems
    .map((item) => {
      const input = document.querySelector(`#qty-${item.sku}`);
      const quantity = Number(input?.value || 0);
      return {
        sku: item.sku,
        quantity,
      };
    })
    .filter((item) => item.quantity > 0);
}

function calculateCurrentOrderTotal() {
  const selected = getSelectedItems();
  const total = selected.reduce((sum, selectedItem) => {
    const inventory = inventoryItems.find((item) => item.sku === selectedItem.sku);
    if (!inventory) {
      return sum;
    }
    return sum + Number(inventory.unitPrice) * selectedItem.quantity;
  }, 0);
  orderTotalEl.textContent = `TZS ${formatNumber(total)}`;
  return total;
}

function renderInventory() {
  if (!inventoryItems.length) {
    inventoryListEl.innerHTML = "<p>No inventory found.</p>";
    return;
  }

  inventoryListEl.innerHTML = inventoryItems
    .map(
      (item) => `
      <div class="inventory-row">
        <div>
          <strong>${item.name}</strong>
          <div class="inventory-meta">SKU: ${item.sku} | Stock: ${item.stock} | TZS ${formatNumber(
        item.unitPrice
      )}</div>
        </div>
        <input type="number" min="0" max="${item.stock}" value="0" id="qty-${item.sku}" />
        <span>Qty</span>
      </div>
    `
    )
    .join("");

  inventoryItems.forEach((item) => {
    const input = document.querySelector(`#qty-${item.sku}`);
    if (input) {
      input.addEventListener("input", calculateCurrentOrderTotal);
    }
  });
}

async function loadInventory() {
  try {
    await fetch("/inventory/add-test-item", { method: "POST" });
  } catch (error) {
    // Ignore this step if API is temporarily unavailable.
  }
  const response = await fetch("/inventory");
  const data = await response.json();
  inventoryItems = data.items || [];
  if (!inventoryItems.length) {
    await fetch("/inventory/seed", { method: "POST" });
    const secondTry = await fetch("/inventory");
    const seededData = await secondTry.json();
    inventoryItems = seededData.items || [];
  }
  renderInventory();
  calculateCurrentOrderTotal();
}

function renderPayments(payments) {
  if (!payments.length) {
    paymentsBodyEl.innerHTML = `
      <tr>
        <td colspan="5">No payments yet.</td>
      </tr>
    `;
    return;
  }

  paymentsBodyEl.innerHTML = payments
    .map((payment) => {
      const createdAt = payment.createdAt ? new Date(payment.createdAt).toLocaleString() : "-";
      const statusClass = payment.status === "SUCCESS" ? "status-success" : "status-failed";
      return `
        <tr>
          <td>${payment.orderReference}</td>
          <td>TZS ${formatNumber(payment.amount)}</td>
          <td class="${statusClass}">${payment.status}</td>
          <td>${payment.phone || "-"}</td>
          <td>${createdAt}</td>
        </tr>
      `;
    })
    .join("");
}

async function loadPayments() {
  const response = await fetch("/payments");
  const data = await response.json();
  totalSalesEl.textContent = `TZS ${formatNumber(data.totalSales || 0)}`;
  totalTransactionsEl.textContent = formatNumber(data.count || 0);
  renderPayments(data.payments || []);
}

async function createPayment(event) {
  event.preventDefault();
  formMessageEl.textContent = "";
  const selectedItems = getSelectedItems();
  if (!selectedItems.length) {
    formMessageEl.textContent = "Select at least one inventory item.";
    return;
  }

  const orderTotal = calculateCurrentOrderTotal();
  if (orderTotal <= 0) {
    formMessageEl.textContent = "Order total must be greater than 0.";
    return;
  }

  const payload = {
    totalPrice: String(orderTotal),
    orderCurrency: "TZS",
    customerName: document.getElementById("customerName").value.trim(),
    customerEmail: document.getElementById("customerEmail").value.trim(),
    customerPhone: document.getElementById("customerPhone").value.trim(),
    description: document.getElementById("description").value.trim(),
    items: selectedItems,
  };

  const response = await fetch("/create-payment", {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
    },
    body: JSON.stringify(payload),
  });
  const data = await response.json();

  if (!response.ok) {
    formMessageEl.textContent = data.message || "Failed to create payment.";
    return;
  }

  formMessageEl.textContent = "Redirecting to ClickPesa checkout...";
  window.location.href = data.checkoutLink;
}

function setupLiveUpdates() {
  const events = new EventSource("/payments/stream");
  events.onmessage = async (event) => {
    const payload = JSON.parse(event.data);
    if (payload.type === "CONNECTED") {
      return;
    }
    await Promise.all([loadPayments(), loadInventory()]);
  };
}

async function initDashboard() {
  await Promise.all([loadInventory(), loadPayments()]);
  setupLiveUpdates();
}

paymentFormEl.addEventListener("submit", createPayment);
initDashboard().catch((error) => {
  formMessageEl.textContent = error.message || "Failed to load dashboard.";
});
