const { getPool } = require("../config/db");
const { getAccountBalance, createOrderControlNumber } = require("./clickpesaService");

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

function parseJsonSafe(value) {
  if (value == null || value === "") {
    return null;
  }
  if (typeof value === "object") {
    return value;
  }
  try {
    return JSON.parse(String(value));
  } catch (_) {
    return null;
  }
}

function pickFirstString(...values) {
  for (const value of values) {
    if (value == null) {
      continue;
    }
    const text = String(value).trim();
    if (text !== "" && text.toLowerCase() !== "customer") {
      return text;
    }
  }
  return null;
}

function deepFindString(obj, keys, depth = 0) {
  if (!obj || depth > 5) {
    return null;
  }
  if (Array.isArray(obj)) {
    for (const item of obj) {
      const found = deepFindString(item, keys, depth + 1);
      if (found) {
        return found;
      }
    }
    return null;
  }
  if (typeof obj === "object") {
    for (const key of keys) {
      if (obj[key] != null) {
        const text = String(obj[key]).trim();
        if (text) {
          return text;
        }
      }
    }
    for (const value of Object.values(obj)) {
      const found = deepFindString(value, keys, depth + 1);
      if (found) {
        return found;
      }
    }
  }
  return null;
}

const PAYER_NAME_KEYS = [
  "customerName",
  "customer_name",
  "beneficiaryName",
  "beneficiary_name",
  "payerName",
  "senderName",
  "name",
  "fullName",
];

function formatPhoneLabel(phone) {
  const digits = String(phone || "").replace(/\D/g, "");
  if (!digits) {
    return null;
  }
  if (digits.length >= 12 && digits.startsWith("255")) {
    return `+${digits.slice(0, 3)} ${digits.slice(3, 5)} ${digits.slice(5, 8)} ${digits.slice(8)}`.trim();
  }
  if (digits.length === 10 && digits.startsWith("0")) {
    return `${digits.slice(0, 4)} ${digits.slice(4, 7)} ${digits.slice(7)}`;
  }
  return `+${digits}`;
}

function looksLikePhone(value) {
  const digits = String(value || "").replace(/\D/g, "");
  return digits.length >= 9;
}

function extractPayerNameFromTx(tx) {
  const direct = pickFirstString(tx.customer_name);
  if (direct && !looksLikePhone(direct)) {
    return direct;
  }

  const rawPayload = parseJsonSafe(tx.raw_payload);
  const rawRequest = parseJsonSafe(tx.raw_request);
  const fromPayload = deepFindString(rawPayload, PAYER_NAME_KEYS);
  const payloadName = pickFirstString(fromPayload);
  if (payloadName && !looksLikePhone(payloadName)) {
    return payloadName;
  }
  const fromRequest = deepFindString(rawRequest, PAYER_NAME_KEYS);
  const requestName = pickFirstString(fromRequest);
  if (requestName && !looksLikePhone(requestName)) {
    return requestName;
  }

  const description = pickFirstString(tx.description);
  if (description && !looksLikePhone(description) && !/payment|clickpesa|autopay/i.test(description)) {
    return description;
  }

  return null;
}

async function lookupOrderCustomerName(db, orderReference) {
  const ref = String(orderReference || "").trim();
  if (!ref) {
    return null;
  }
  try {
    const [rows] = await db.query("SELECT customerName FROM orders WHERE orderReference = ? LIMIT 1", [ref]);
    const name = pickFirstString(rows[0]?.customerName);
    return name && !looksLikePhone(name) ? name : null;
  } catch (_) {
    return null;
  }
}

function lookupBackupCustomerName(orderReference) {
  try {
    const paymentFileStore = require("./paymentFileStore");
    const ref = String(orderReference || "").trim().toUpperCase();
    const entry = paymentFileStore.listPayments().find(
      (row) => String(row.orderReference || "").trim().toUpperCase() === ref
    );
    const name = pickFirstString(entry?.customerName);
    return name && !looksLikePhone(name) ? name : null;
  } catch (_) {
    return null;
  }
}

async function resolvePayerDetails(db, tx) {
  let displayName =
    extractPayerNameFromTx(tx) ||
    (await lookupOrderCustomerName(db, tx.order_reference)) ||
    lookupBackupCustomerName(tx.order_reference);

  if (!displayName || looksLikePhone(displayName) || displayName.toLowerCase() === "customer") {
    displayName = "Mteja";
  }

  const phoneFormatted = formatPhoneLabel(tx.phone);
  return {
    displayName,
    maskedPhone: tx.phone ? maskPhone(tx.phone) : null,
    phoneFormatted: phoneFormatted || null,
  };
}

function resolvePayerName(tx) {
  const name = extractPayerNameFromTx(tx);
  if (name) {
    return name;
  }
  const backup = lookupBackupCustomerName(tx.order_reference);
  if (backup) {
    return backup;
  }
  return "Mteja";
}

function resolveOrderLabel(tx) {
  if (tx.order_id) {
    return String(tx.order_id);
  }
  const ref = String(tx.order_reference || "");
  if (ref.startsWith("APAY")) {
    return "AutoPay";
  }
  if (ref.startsWith("TIS")) {
    return "Wallet Pay";
  }
  if (ref.startsWith("STATEMENT-")) {
    return "Statement Sync";
  }
  if (String(tx.channel || "").toLowerCase() === "billpay") {
    return "BillPay";
  }
  if (tx.channel) {
    return String(tx.channel);
  }
  return "—";
}

function resolveControlNumber(tx) {
  const controlNumber = String(tx.control_number || "").trim();
  return controlNumber || null;
}

function formatDisplayDate(value) {
  if (!value) {
    return "—";
  }
  const date = typeof value === "number" ? new Date(value * 1000) : new Date(String(value));
  if (Number.isNaN(date.getTime())) {
    return "—";
  }
  return date.toLocaleString("en-GB", {
    day: "2-digit",
    month: "2-digit",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
    second: "2-digit",
  });
}

function resolvePaidAt(tx) {
  if (isSuccessfulStatus(tx.payment_status) && tx.paid_at) {
    return toIso(tx.paid_at);
  }
  if (isSuccessfulStatus(tx.payment_status) && tx.updated_at) {
    return toIso(tx.updated_at);
  }
  return toIso(tx.created_at);
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

function resolveReceivedAmount(tx) {
  const stored = tx.received_amount != null ? Number(tx.received_amount) : null;
  if (stored != null && stored > 0) {
    return stored;
  }
  if (isSuccessfulStatus(tx.payment_status)) {
    return Number(tx.expected_amount || tx.amount || 0);
  }
  return stored;
}

async function listControlNumbers(limit = 100) {
  const db = getPool();
  const { processPendingAutoPayouts, resolveWithdrawInfo } = require("./payoutService");
  await processPendingAutoPayouts(5).catch(() => {});

  const [rows] = await db.query(
    `SELECT * FROM clickpesa_transactions
     WHERE transaction_type = 'collection'
     ORDER BY id DESC LIMIT ?`,
    [limit]
  );

  const settings = await getOrCreateSettingsRow(db);
  const payoutSettings = formatAutoPayoutSettings(settings);
  const paymentIds = rows.map((tx) => tx.id);
  const payoutByPaymentId = new Map();
  if (paymentIds.length) {
    const [payoutRows] = await db.query(
      `SELECT * FROM clickpesa_payout WHERE payment_id IN (${paymentIds.map(() => "?").join(",")})`,
      paymentIds
    );
    for (const payout of payoutRows) {
      payoutByPaymentId.set(payout.payment_id, payout);
    }
  }

  return {
    success: true,
    payoutSettings: {
      enabled: payoutSettings.enabled,
      mode: payoutSettings.mode,
    },
    items: await Promise.all(
      rows.map(async (tx) => {
      const controlNumber = resolveControlNumber(tx);
      const payout = payoutByPaymentId.get(tx.id) || null;
      const withdraw = resolveWithdrawInfo(tx, payout, settings);
      const payer = await resolvePayerDetails(db, tx);
      return {
        id: tx.id,
        orderId: resolveOrderLabel(tx),
        customerName: payer.displayName,
        controlNumber: controlNumber || "—",
        hasControlNumber: Boolean(controlNumber),
        reference: tx.order_reference,
        amount: Number(tx.expected_amount || tx.amount || 0),
        receivedAmount: resolveReceivedAmount(tx),
        status: tx.payment_status,
        description: tx.description,
        channel: tx.channel || null,
        paidAt: resolvePaidAt(tx),
        createdAt: toIso(tx.created_at),
        withdrawStatus: withdraw.withdrawStatus,
        canWithdraw: withdraw.canWithdraw,
        payoutStatus: payout?.payout_status || tx.payout_status || null,
        invoiceUrl: `admin-invoice.php?id=${tx.id}`,
      };
    })
    ),
  };
}

async function getInvoiceData(id) {
  const db = getPool();
  const [rows] = await db.query("SELECT * FROM clickpesa_transactions WHERE id = ? LIMIT 1", [id]);
  const tx = rows[0];
  if (!tx) {
    const error = new Error("Transaction not found.");
    error.statusCode = 404;
    throw error;
  }

  const controlNumber = resolveControlNumber(tx);
  const paidAt = resolvePaidAt(tx);
  const createdAt = toIso(tx.created_at);
  const amount = Number(tx.received_amount || tx.expected_amount || tx.amount || 0);
  const payer = await resolvePayerDetails(db, tx);

  return {
    id: tx.id,
    invoiceNumber: `INV-CP-${String(tx.id).padStart(6, "0")}`,
    orderId: resolveOrderLabel(tx),
    customerName: payer.displayName,
    customerPhone: payer.maskedPhone,
    customerPhoneFormatted: payer.phoneFormatted,
    controlNumber: controlNumber || "—",
    hasControlNumber: Boolean(controlNumber),
    billReference: tx.order_reference || "—",
    amount,
    currency: tx.currency || "TZS",
    paymentMode: tx.payment_mode || "EXACT",
    description: tx.description || "ClickPesa payment",
    status: tx.payment_status,
    channel: tx.channel || "clickpesa",
    createdAt,
    createdAtFormatted: formatDisplayDate(createdAt),
    paidAt,
    paidAtFormatted: formatDisplayDate(paidAt),
    qrReference: tx.order_reference || "",
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

async function withdrawPayment(paymentId) {
  const payoutService = require("./payoutService");
  return payoutService.withdrawPayment(paymentId);
}

function normalizeBillReference(value) {
  return String(value || "")
    .replace(/[^A-Za-z0-9]/g, "")
    .toUpperCase()
    .slice(0, 20);
}

function generateBillReference(orderId) {
  const safe =
    String(orderId || "ORD")
      .replace(/[^A-Za-z0-9]/g, "")
      .toUpperCase()
      .slice(0, 8) || "ORD";
  const suffix = `${String(Math.floor(Date.now() / 1000)).slice(-6)}${Math.floor(Math.random() * 90) + 10}`;
  return `${safe}${suffix}`.slice(0, 20);
}

function extractNestedValue(obj, path) {
  const parts = String(path).split(".");
  let current = obj;
  for (const part of parts) {
    if (!current || typeof current !== "object") {
      return null;
    }
    current = current[part];
  }
  return current == null || current === "" ? null : current;
}

function extractBillPayNumber(response) {
  const paths = [
    "billPayNumber",
    "data.billPayNumber",
    "data.billPay.billPayNumber",
    "billPay.billPayNumber",
    "controlNumber",
    "data.controlNumber",
    "control_number",
    "data.control_number",
  ];
  for (const path of paths) {
    const value = extractNestedValue(response, path);
    if (value == null || value === "") {
      continue;
    }
    const normalized = String(value).trim().toUpperCase();
    if (!/^[A-Z0-9]+$/.test(normalized)) {
      continue;
    }
    if (normalized.length < 3 || normalized.length > 20) {
      continue;
    }
    return normalized;
  }
  return null;
}

async function createControlNumber(data = {}) {
  const db = getPool();
  let orderId = String(data.order_id || data.orderId || "").trim();
  const amount = Number(data.amount || 0);
  let description = String(data.description || data.billDescription || "").trim();
  const paymentMode = String(data.payment_mode || data.paymentMode || "EXACT").toUpperCase();

  if (!Number.isFinite(amount) || amount <= 0) {
    const error = new Error("amount must be a positive value.");
    error.statusCode = 400;
    throw error;
  }
  if (!orderId) {
    orderId = `GW${String(Date.now()).slice(-8)}`;
  }
  if (!description) {
    description = `Payment for ${orderId}`;
  }
  if (!["EXACT", "ALLOW_PARTIAL_AND_OVER_PAYMENT"].includes(paymentMode)) {
    const error = new Error("payment_mode must be EXACT or ALLOW_PARTIAL_AND_OVER_PAYMENT.");
    error.statusCode = 400;
    throw error;
  }

  const [activeRows] = await db.query(
    `SELECT * FROM clickpesa_transactions
     WHERE order_id = ? AND payment_status = 'PENDING' AND control_number IS NOT NULL
     LIMIT 1`,
    [orderId]
  );
  if (activeRows[0] && !data.force_new && !data.forceNew) {
    const active = activeRows[0];
    return {
      success: true,
      id: active.id,
      controlNumber: active.control_number,
      reference: active.order_reference,
      orderId: active.order_id || orderId,
      amount: Number(active.expected_amount || active.amount || 0),
      status: active.payment_status,
      existing: true,
      invoiceUrl: `admin-invoice.php?id=${active.id}`,
    };
  }

  let billReference = normalizeBillReference(data.orderReference || data.billReference || orderId);
  if (!billReference) {
    billReference = generateBillReference(orderId);
  }
  for (let attempt = 0; attempt < 5; attempt += 1) {
    const [exists] = await db.query(
      "SELECT id FROM clickpesa_transactions WHERE order_reference = ? LIMIT 1",
      [billReference]
    );
    if (!exists[0]) {
      break;
    }
    billReference = generateBillReference(orderId);
  }

  const payload = {
    billDescription: description,
    billPaymentMode: paymentMode,
    billAmount: amount,
    billReference,
  };
  if (data.customerName) {
    payload.customerName = String(data.customerName);
  }
  if (data.phone) {
    payload.customerPhone = normalizePhone(data.phone);
  }
  if (data.email) {
    payload.customerEmail = String(data.email);
  }

  const response = await createOrderControlNumber(payload);
  const controlNumber = extractBillPayNumber(response);
  if (!controlNumber) {
    const error = new Error(
      "ClickPesa did not return a BillPay control number. Check API credentials and BillPay access."
    );
    error.statusCode = 502;
    throw error;
  }

  const now = Math.floor(Date.now() / 1000);
  await db.query(
    `INSERT INTO clickpesa_transactions
      (order_id, order_reference, control_number, amount, expected_amount, currency, payment_mode, phone, customer_name, description, payment_status, transaction_type, channel, raw_request, raw_payload, created_at, updated_at)
     VALUES (?, ?, ?, ?, ?, 'TZS', ?, ?, ?, ?, 'PENDING', 'collection', 'billpay', ?, ?, ?, ?)`,
    [
      orderId,
      billReference,
      controlNumber,
      amount,
      amount,
      paymentMode,
      payload.customerPhone || null,
      payload.customerName || null,
      description,
      JSON.stringify(payload),
      JSON.stringify(response),
      now,
      now,
    ]
  );

  const [createdRows] = await db.query(
    "SELECT * FROM clickpesa_transactions WHERE order_reference = ? LIMIT 1",
    [billReference]
  );
  const created = createdRows[0];

  return {
    success: true,
    id: created?.id,
    controlNumber,
    reference: billReference,
    orderId,
    amount,
    status: "PENDING",
    invoiceUrl: created?.id ? `admin-invoice.php?id=${created.id}` : null,
  };
}

module.exports = {
  getAccountBalance,
  getDashboardAnalytics,
  listControlNumbers,
  listPayouts,
  getInvoiceData,
  createControlNumber,
  withdrawPayment,
  getAutoPayoutSettings,
  updateAutoPayoutSettings,
  getOrCreateSettingsRow,
  getDestinationPhone,
  maskPhone,
  normalizePhone,
};
