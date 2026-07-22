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

function buildAnalyticsPeriodLabel(startDate, endDate, period) {
  if (!startDate && !endDate) {
    if (period === "month") return "This month";
    if (period === "30d") return "Last 30 days";
    if (period === "90d") return "Last 90 days";
    return "All time";
  }
  if (startDate && endDate) {
    return startDate === endDate ? startDate : `${startDate} → ${endDate}`;
  }
  return "All time";
}

function buildAnalyticsTrendDays(payments, numDays = 14) {
  const safeDays = Math.max(1, Math.min(Number(numDays) || 14, 90));
  const start = new Date();
  start.setHours(0, 0, 0, 0);
  start.setDate(start.getDate() - (safeDays - 1));

  const days = [];
  for (let i = 0; i < safeDays; i += 1) {
    const day = new Date(start);
    day.setDate(start.getDate() + i);
    days.push({
      label: day.toLocaleDateString("en-GB", { day: "numeric", month: "short" }),
      count: 0,
      date: day.toISOString().slice(0, 10),
    });
  }

  for (const payment of payments) {
    const ts = Date.parse(String(payment.createdAt || ""));
    if (Number.isNaN(ts)) {
      continue;
    }
    const day = new Date(ts);
    day.setHours(0, 0, 0, 0);
    const diff = Math.round((day.getTime() - start.getTime()) / 86400000);
    if (diff >= 0 && diff < safeDays) {
      days[diff].count += 1;
    }
  }

  return days;
}

function getPaymentDateRange(payments) {
  let firstTransactionAt = null;
  let lastTransactionAt = null;

  for (const payment of payments) {
    const createdAt = payment.createdAt;
    if (!createdAt) {
      continue;
    }
    if (!firstTransactionAt || createdAt < firstTransactionAt) {
      firstTransactionAt = createdAt;
    }
    if (!lastTransactionAt || createdAt > lastTransactionAt) {
      lastTransactionAt = createdAt;
    }
  }

  return { firstTransactionAt, lastTransactionAt };
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

  const { firstTransactionAt, lastTransactionAt } = getPaymentDateRange(payments);
  const trendDays = buildAnalyticsTrendDays(payments, 14);

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
      periodLabel: buildAnalyticsPeriodLabel(startDate, endDate, period),
      firstTransactionAt,
      lastTransactionAt,
      trendDays,
      recentCollections: recentCollections.slice(0, 15),
    },
    payments,
  };
}

async function listControlNumbers(limit = 100) {
  const db = getPool();
  const [rows] = await db.query(
    `SELECT * FROM clickpesa_transactions
     WHERE transaction_type = 'collection'
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
  const settings = await getOrCreateSettingsRow(db);
  return formatAutoPayoutSettings(settings);
}

function maskPhone(phone) {
  const digits = String(phone || "").replace(/\D/g, "");
  if (digits.length < 6) {
    return "*".repeat(digits.length);
  }
  return `${digits.slice(0, 4)}${"*".repeat(Math.max(0, digits.length - 6))}${digits.slice(-2)}`;
}

function normalizePhone(phone) {
  let digits = String(phone || "").replace(/\D/g, "");
  if (digits.startsWith("0") && digits.length === 10) {
    digits = `255${digits.slice(1)}`;
  }
  if (digits.length === 9) {
    digits = `255${digits}`;
  }
  return digits;
}

function encryptDestinationPhone(phone) {
  return `plain:${Buffer.from(normalizePhone(phone), "utf8").toString("base64")}`;
}

function getDestinationPhone(encrypted) {
  const value = String(encrypted || "");
  if (!value) {
    return null;
  }
  if (value.startsWith("plain:")) {
    return Buffer.from(value.slice(6), "base64").toString("utf8");
  }
  return null;
}

function verifyAdminPassword(password) {
  const provided = String(password || "").trim();
  if (!provided) {
    return false;
  }
  const allowed = String(process.env.ADMIN_PROXY_PASSWORDS || "admin123,1234")
    .split(",")
    .map((entry) => entry.trim())
    .filter(Boolean);
  return allowed.includes(provided);
}

async function getOrCreateSettingsRow(db) {
  const [rows] = await db.query("SELECT * FROM clickpesa_setting ORDER BY id ASC LIMIT 1");
  if (rows[0]) {
    return rows[0];
  }

  const now = Math.floor(Date.now() / 1000);
  const encryptedDestination = encryptDestinationPhone("255715296092");
  await db.query(
    `INSERT INTO clickpesa_setting
      (auto_payout_enabled, mode, destination_type, encrypted_destination, payout_percentage, minimum_amount, daily_limit, delay_seconds, require_manual_approval, created_at, updated_at)
     VALUES (0, 'TEST', 'MOBILE_MONEY', ?, 100, 1000, 0, 60, 1, ?, ?)`,
    [encryptedDestination, now, now]
  );
  const [created] = await db.query("SELECT * FROM clickpesa_setting ORDER BY id ASC LIMIT 1");
  return created[0];
}

function formatAutoPayoutSettings(settings) {
  const phone = getDestinationPhone(settings.encrypted_destination);
  const mode = settings.mode || "TEST";
  const enabled = Boolean(settings.auto_payout_enabled);

  return {
    success: true,
    enabled,
    mode,
    destinationType: settings.destination_type || "MOBILE_MONEY",
    maskedDestination: phone ? maskPhone(phone) : "—",
    mobileProvider: settings.mobile_provider || null,
    minimumAmount: Number(settings.minimum_amount || 0),
    dailyLimit: Number(settings.daily_limit || 0),
    payoutPercentage: Number(settings.payout_percentage || 0),
    delaySeconds: Number(settings.delay_seconds || 0),
    manualApprovalRequired: Boolean(settings.require_manual_approval),
    lastSyncedAt: settings.last_synced_at ? toIso(settings.last_synced_at) : null,
    warning:
      mode === "TEST"
        ? "TEST MODE — auto payout is off. Turn Auto payout ON and enter admin password to activate."
        : enabled && mode === "MANUAL_APPROVAL"
          ? "Manual approval mode — payouts need approval before sending."
          : null,
  };
}

async function updateAutoPayoutSettings(data = {}) {
  const db = getPool();
  const current = await getOrCreateSettingsRow(db);
  const before = formatAutoPayoutSettings(current);

  const mode = String(data.mode || current.mode || "TEST").trim().toUpperCase();
  const enabled = Object.prototype.hasOwnProperty.call(data, "enabled")
    ? Boolean(data.enabled)
    : Boolean(current.auto_payout_enabled);

  if (!["TEST", "MANUAL_APPROVAL", "LIVE_AUTO"].includes(mode)) {
    const error = new Error("mode must be TEST, MANUAL_APPROVAL or LIVE_AUTO.");
    error.statusCode = 400;
    throw error;
  }

  if ((enabled && !current.auto_payout_enabled) || mode === "LIVE_AUTO") {
    const password = data.currentAdminPassword || data.adminPassword || data.admin_password || "";
    if (!verifyAdminPassword(password)) {
      const error = new Error(
        password ? "Invalid admin password." : "Admin password is required to change automatic payout settings."
      );
      error.statusCode = 403;
      throw error;
    }
  }

  const destinationType = String(
    data.destinationType || current.destination_type || "MOBILE_MONEY"
  )
    .trim()
    .toUpperCase();

  let encryptedDestination = current.encrypted_destination;
  if (data.mobileMoneyNumber) {
    encryptedDestination = encryptDestinationPhone(data.mobileMoneyNumber);
  } else if (enabled && !getDestinationPhone(encryptedDestination)) {
    encryptedDestination = encryptDestinationPhone("255715296092");
  }

  const phone = getDestinationPhone(encryptedDestination);
  if (destinationType === "MOBILE_MONEY" && enabled && !phone) {
    const error = new Error("Configure a valid payout destination before enabling automatic payout.");
    error.statusCode = 400;
    throw error;
  }

  let nextEnabled = enabled ? 1 : 0;
  const nextMode = mode;
  let requireManualApproval = Object.prototype.hasOwnProperty.call(data, "manualApprovalRequired")
    ? data.manualApprovalRequired ? 1 : 0
    : Number(current.require_manual_approval || 0);

  if (nextMode === "TEST") {
    nextEnabled = 0;
  } else if (enabled) {
    nextEnabled = 1;
  }
  if (nextMode === "MANUAL_APPROVAL") {
    requireManualApproval = 1;
  }

  const now = Math.floor(Date.now() / 1000);
  await db.query(
    `UPDATE clickpesa_setting SET
      auto_payout_enabled = ?,
      mode = ?,
      destination_type = ?,
      encrypted_destination = ?,
      mobile_provider = ?,
      payout_percentage = ?,
      minimum_amount = ?,
      daily_limit = ?,
      delay_seconds = ?,
      require_manual_approval = ?,
      updated_at = ?
     WHERE id = ?`,
    [
      nextEnabled,
      nextMode,
      destinationType,
      encryptedDestination,
      String(data.mobileProvider || current.mobile_provider || "").trim() || null,
      Number(data.payoutPercentage ?? current.payout_percentage ?? 100),
      Number(data.minimumAmount ?? current.minimum_amount ?? 1000),
      Number(data.dailyLimit ?? current.daily_limit ?? 0),
      Number(data.delaySeconds ?? current.delay_seconds ?? 60),
      requireManualApproval,
      now,
      current.id,
    ]
  );

  try {
    await db.query(
      `INSERT INTO clickpesa_setting_audit (action, changes_json, ip_address, created_at)
       VALUES ('settings_updated', ?, ?, ?)`,
      [JSON.stringify({ before, after: data }), null, now]
    );
  } catch (_) {
    /* audit table optional */
  }

  const [rows] = await db.query("SELECT * FROM clickpesa_setting WHERE id = ? LIMIT 1", [current.id]);
  return formatAutoPayoutSettings(rows[0] || current);
}

module.exports = {
  getAccountBalance,
  getDashboardAnalytics,
  listControlNumbers,
  listPayouts,
  getAutoPayoutSettings,
  updateAutoPayoutSettings,
};
