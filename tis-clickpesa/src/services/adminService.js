const { getPool } = require("../config/db");
const { getAccountBalance } = require("./clickpesaService");

function mapPaymentStatus(status) {
  const value = String(status || "").trim().toUpperCase();
  if (["SUCCESS", "SUCCESSFUL", "COMPLETED", "PAID", "SETTLED"].includes(value)) {
    return "SUCCESS";
  }
  if (["FAILED", "FAILURE", "DECLINED", "CANCELLED", "CANCELED"].includes(value)) {
    return "FAILED";
  }
  return "PENDING";
}

function isSuccessfulStatus(status) {
  return mapPaymentStatus(status) === "SUCCESS";
}

function toIso(value) {
  if (!value) return null;
  const date = new Date(Number(value) * 1000);
  return Number.isNaN(date.getTime()) ? null : date.toISOString();
}

async function getDashboardAnalytics(filters = {}) {
  const db = getPool();
  const period = String(filters.period || "all").toLowerCase();
  let startDate = filters.startDate || null;
  let endDate = filters.endDate || null;

  if (!startDate && !endDate) {
    const now = new Date();
    if (period === "month") {
      startDate = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, "0")}-01`;
      endDate = now.toISOString().slice(0, 10);
    } else if (period === "30d") {
      const start = new Date(now);
      start.setDate(start.getDate() - 29);
      startDate = start.toISOString().slice(0, 10);
      endDate = now.toISOString().slice(0, 10);
    } else if (period === "90d") {
      const start = new Date(now);
      start.setDate(start.getDate() - 89);
      startDate = start.toISOString().slice(0, 10);
      endDate = now.toISOString().slice(0, 10);
    }
  }

  const clauses = ["transaction_type = 'collection'"];
  const params = [];

  if (startDate) {
    clauses.push("created_at >= ?");
    params.push(Math.floor(new Date(`${startDate}T00:00:00`).getTime() / 1000));
  }
  if (endDate) {
    clauses.push("created_at <= ?");
    params.push(Math.floor(new Date(`${endDate}T23:59:59`).getTime() / 1000));
  }

  const sql = `SELECT * FROM clickpesa_transactions WHERE ${clauses.join(" AND ")} ORDER BY updated_at DESC LIMIT 1000`;
  const [rows] = await db.query(sql, params);

  let moneyIn = 0;
  let failedSales = 0;
  let success = 0;
  let pending = 0;
  let failed = 0;
  const payments = [];
  const recentCollections = [];

  for (const tx of rows) {
    const amount = Number(tx.received_amount || tx.expected_amount || tx.amount || 0);
    const status = mapPaymentStatus(tx.payment_status);
    const createdAt = toIso(tx.created_at);

    if (isSuccessfulStatus(status)) {
      moneyIn += amount;
      success += 1;
    } else if (status === "FAILED") {
      failedSales += amount;
      failed += 1;
    } else {
      pending += 1;
    }

    payments.push({
      orderReference: tx.order_reference,
      amount,
      status: isSuccessfulStatus(status) ? "SUCCESS" : tx.payment_status,
      phone: tx.phone || "",
      controlNumber: tx.control_number,
      createdAt,
    });

    recentCollections.push({
      id: tx.id,
      orderId: tx.order_id,
      orderReference: tx.order_reference,
      controlNumber: tx.control_number,
      amount,
      status: tx.payment_status,
      createdAt,
    });
  }

  return {
    success: true,
    source: "database",
    period,
    filters: {
      period,
      ...(startDate ? { startDate } : {}),
      ...(endDate ? { endDate } : {}),
    },
    analytics: {
      moneyIn: Math.round(moneyIn * 100) / 100,
      failedSales: Math.round(failedSales * 100) / 100,
      success,
      pending,
      failed,
      recordCount: rows.length,
      periodLabel: period,
      firstTransactionAt: payments.length ? payments[payments.length - 1].createdAt : null,
      lastTransactionAt: payments.length ? payments[0].createdAt : null,
      trendDays: [],
      recentCollections: recentCollections.slice(0, 15),
    },
    payments,
  };
}

async function listControlNumbers(limit = 100) {
  const db = getPool();
  const [rows] = await db.query(
    `SELECT * FROM clickpesa_transactions
     WHERE channel = 'billpay' OR control_number IS NOT NULL
     ORDER BY id DESC LIMIT ?`,
    [limit]
  );

  return {
    success: true,
    items: rows.map((tx) => ({
      id: tx.id,
      orderId: tx.order_id,
      customerName: tx.customer_name,
      controlNumber: tx.control_number,
      reference: tx.order_reference,
      amount: Number(tx.expected_amount || tx.amount || 0),
      receivedAmount: tx.received_amount != null ? Number(tx.received_amount) : null,
      status: tx.payment_status,
      description: tx.description,
      createdAt: toIso(tx.created_at),
      invoiceUrl: `/api/clickpesa/control-number/${tx.id}/invoice`,
    })),
  };
}

async function listPayouts(limit = 100) {
  const db = getPool();
  const [rows] = await db.query("SELECT * FROM clickpesa_payout ORDER BY id DESC LIMIT ?", [limit]);

  return {
    success: true,
    items: rows.map((payout) => ({
      id: payout.id,
      payoutReference: payout.payout_reference,
      destinationMasked: payout.destination_masked,
      amount: Number(payout.amount || 0),
      fee: payout.fee != null ? Number(payout.fee) : null,
      status: payout.payout_status,
      provider: payout.provider,
      lastError: payout.last_error,
      createdAt: toIso(payout.created_at),
      updatedAt: toIso(payout.updated_at),
      retryable: ["FAILED", "PENDING"].includes(String(payout.payout_status || "").toUpperCase()),
    })),
  };
}

async function getAutoPayoutSettings() {
  const db = getPool();
  const [rows] = await db.query("SELECT * FROM clickpesa_setting ORDER BY id ASC LIMIT 1");
  const settings = rows[0] || {};

  return {
    success: true,
    enabled: Boolean(settings.auto_payout_enabled),
    mode: settings.mode || "TEST",
    destinationType: settings.destination_type || "MOBILE_MONEY",
    maskedDestination: settings.destination_masked || null,
    mobileProvider: settings.mobile_provider || null,
    minimumAmount: Number(settings.minimum_amount || 0),
    dailyLimit: Number(settings.daily_limit || 0),
    payoutPercentage: Number(settings.payout_percentage || 0),
    delaySeconds: Number(settings.delay_seconds || 0),
    manualApprovalRequired: Boolean(settings.require_manual_approval),
    lastSyncedAt: settings.last_synced_at ? toIso(settings.last_synced_at) : null,
    warning:
      (settings.mode || "TEST") === "TEST"
        ? "TEST MODE — auto payout is off. Turn Auto payout ON and enter admin password to activate."
        : null,
  };
}

module.exports = {
  getAccountBalance,
  getDashboardAnalytics,
  listControlNumbers,
  listPayouts,
  getAutoPayoutSettings,
};
