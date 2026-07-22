const {
  generateAccessToken,
  createCheckoutLink,
  initiateUssdPush,
  queryPaymentStatus,
} = require("../services/clickpesaService");
const { finalizeSuccessfulPayment } = require("../services/payoutService");
const { getPool } = require("../config/db");
const paymentFileStore = require("../services/paymentFileStore");

const paymentStreams = new Set();
const recentPayments = new Map();
const MAX_RECENT_PAYMENTS = 300;

function broadcastPaymentUpdate(payload) {
  const event = `data: ${JSON.stringify(payload)}\n\n`;
  for (const stream of paymentStreams) {
    stream.write(event);
  }
}

function mapWalletStatus(status) {
  const s = String(status || "").trim().toUpperCase();
  if (["SUCCESS", "SUCCESSFUL", "COMPLETED", "PAID", "SETTLED"].includes(s)) {
    return "SUCCESS";
  }
  if (["FAILED", "FAILURE", "DECLINED", "CANCELLED", "CANCELED"].includes(s)) {
    return "FAILED";
  }
  if (["REFUNDED", "REFUND"].includes(s)) {
    return "REFUNDED";
  }
  return "PENDING";
}

// Warm in-memory cache from local file so history survives Node restarts
try {
  for (const row of paymentFileStore.listPayments()) {
    const key = String(row.orderReference || "")
      .trim()
      .toUpperCase();
    if (!key) continue;
    recentPayments.set(key, {
      ...row,
      orderReference: key,
      status: mapWalletStatus(row.status),
    });
  }
} catch (_) {
  /* ignore warm-up errors */
}

async function persistPaymentToDb(entry) {
  if (!entry || !entry.orderReference) {
    return;
  }
  const orderReference = String(entry.orderReference).trim().toUpperCase();
  const now = Math.floor(Date.now() / 1000);
  const amount = Number(entry.amount || 0);
  const status = mapWalletStatus(entry.status);
  const phone = String(entry.phone || "").trim().slice(0, 32) || null;
  const channel = String(entry.channel || entry.paymentMode || "tis").slice(0, 64) || null;
  const customerName = String(entry.customerName || "").trim().slice(0, 255) || null;
  const description = String(entry.description || "").trim().slice(0, 512) || null;

  try {
    const db = getPool();
    await db.query(
      `INSERT INTO clickpesa_transactions
        (order_reference, amount, currency, phone, customer_name, description, payment_status, transaction_type, channel, created_at, updated_at)
       VALUES (?, ?, 'TZS', ?, ?, ?, ?, 'collection', ?, ?, ?)
       ON DUPLICATE KEY UPDATE
         amount = IF(VALUES(amount) > 0, VALUES(amount), amount),
         phone = COALESCE(VALUES(phone), phone),
         customer_name = COALESCE(VALUES(customer_name), customer_name),
         description = COALESCE(VALUES(description), description),
         payment_status = CASE
           WHEN payment_status IN ('SUCCESS', 'FAILED', 'REFUNDED')
             AND VALUES(payment_status) = 'PENDING' THEN payment_status
           ELSE VALUES(payment_status)
         END,
         channel = COALESCE(VALUES(channel), channel),
         updated_at = VALUES(updated_at)`,
      [orderReference, amount, phone, customerName, description, status, channel, now, now]
    );
  } catch (err) {
    console.warn("persistPaymentToDb failed:", err.message);
  }
}

function rememberPayment(entry) {
  if (!entry || !entry.orderReference) {
    return;
  }
  const key = String(entry.orderReference).trim().toUpperCase();
  if (!key) {
    return;
  }
  const normalized = {
    ...entry,
    orderReference: key,
    status: mapWalletStatus(entry.status),
  };
  recentPayments.set(key, normalized);
  if (recentPayments.size > MAX_RECENT_PAYMENTS) {
    const oldest = recentPayments.keys().next().value;
    recentPayments.delete(oldest);
  }
  // Local file backup — survives Node restart even if MySQL is down.
  try {
    paymentFileStore.upsertPayment(normalized);
  } catch (_) {
    /* ignore */
  }
  // Fire-and-forget DB write (wallet history when MySQL is reachable).
  void persistPaymentToDb(normalized);
}

async function listRecentPayments() {
  const byRef = new Map();

  try {
    const db = getPool();
    const [rows] = await db.query(
      `SELECT order_reference, amount, phone, payment_status, channel, created_at, updated_at
       FROM clickpesa_transactions
       ORDER BY updated_at DESC
       LIMIT 300`
    );
    for (const row of rows || []) {
      const orderReference = String(row.order_reference || "").toUpperCase();
      if (!orderReference) continue;
      const createdSec = Number(row.created_at || 0);
      byRef.set(orderReference, {
        id: orderReference,
        orderReference,
        amount: Number(row.amount || 0),
        status: mapWalletStatus(row.payment_status),
        phone: row.phone || "",
        channel: row.channel || "",
        createdAt: createdSec > 0 ? new Date(createdSec * 1000).toISOString() : new Date().toISOString(),
        updatedAt:
          Number(row.updated_at || 0) > 0
            ? new Date(Number(row.updated_at) * 1000).toISOString()
            : undefined,
      });
    }
    // If local backup is empty but DB has data, seed the file once
    try {
      if (paymentFileStore.listPayments().length === 0 && byRef.size > 0) {
        for (const row of byRef.values()) {
          paymentFileStore.upsertPayment(row);
        }
      }
    } catch (_) {
      /* ignore */
    }
  } catch (err) {
    console.warn("listRecentPayments DB read failed:", err.message);
  }

  // Local file backup (covers MySQL outages + fresh Node restarts)
  try {
    for (const row of paymentFileStore.listPayments()) {
      const key = String(row.orderReference || "").toUpperCase();
      if (!key) continue;
      const existing = byRef.get(key);
      byRef.set(key, {
        ...(existing || {}),
        ...row,
        orderReference: key,
        status: mapWalletStatus(row.status || (existing && existing.status)),
      });
    }
  } catch (err) {
    console.warn("listRecentPayments file read failed:", err.message);
  }

  for (const entry of recentPayments.values()) {
    const key = String(entry.orderReference || "").toUpperCase();
    if (!key) continue;
    const existing = byRef.get(key);
    byRef.set(key, {
      ...(existing || {}),
      ...entry,
      orderReference: key,
      status: mapWalletStatus(entry.status),
    });
  }

  const out = Array.from(byRef.values());
  out.sort((a, b) => new Date(b.createdAt || 0) - new Date(a.createdAt || 0));
  return out;
}

function generateOrderReference(prefix = "TIS") {
  const now = Date.now();
  const randomPart = Math.floor(Math.random() * 900 + 100);
  const safePrefix = String(prefix || "TIS")
    .toUpperCase()
    .replace(/[^A-Z0-9]/g, "")
    .slice(0, 8) || "TIS";
  return `${safePrefix}${now}${randomPart}`;
}

function sanitizeOrderReference(orderReference) {
  return String(orderReference || "")
    .toUpperCase()
    .replace(/[^A-Z0-9]/g, "")
    .trim();
}

/** ClickPesa / gateways often send COMPLETED, PAID, etc. — map to our ENUM. */
function normalizePaymentWebhookStatus(raw) {
  const s = String(raw || "")
    .trim()
    .toUpperCase()
    .replace(/\s+/g, "_");
  const success = new Set([
    "SUCCESS",
    "SUCCESSFUL",
    "COMPLETED",
    "COMPLETE",
    "PAID",
    "APPROVED",
    "SETTLED",
    "SUCCEEDED",
    "SUCCESSFUL_PAYMENT",
    "CONFIRMED",
    "AUTHORIZED",
    "DONE",
    "OK",
    "SETTLEMENT_COMPLETED",
    "PAYMENT_SUCCESS",
    "PAYMENT_COMPLETED",
  ]);
  const failed = new Set([
    "FAILED",
    "FAILURE",
    "DECLINED",
    "REJECTED",
    "CANCELLED",
    "CANCELED",
    "EXPIRED",
    "VOID",
    "VOIDED",
    "UNSUCCESSFUL",
    "PAYMENT_FAILED",
  ]);
  if (success.has(s)) return "SUCCESS";
  if (failed.has(s)) return "FAILED";
  return s;
}

/** BFS over nested objects/arrays (max nodes) so webhook shapes from ClickPesa are covered. */
function flattenWebhookObjects(root, maxNodes = 48) {
  const list = [];
  const queue = [];
  const seen = new WeakSet();
  if (root && typeof root === "object") {
    queue.push(root);
  }
  while (queue.length && list.length < maxNodes) {
    const cur = queue.shift();
    if (!cur || typeof cur !== "object" || seen.has(cur)) {
      continue;
    }
    seen.add(cur);
    list.push(cur);
    for (const v of Object.values(cur)) {
      if (!v || typeof v !== "object") {
        continue;
      }
      if (Array.isArray(v)) {
        for (const item of v) {
          if (item && typeof item === "object") {
            queue.push(item);
          }
        }
      } else {
        queue.push(v);
      }
    }
  }
  return list;
}

function pickFirstString(objects, keys) {
  for (const o of objects) {
    for (const k of keys) {
      if (o[k] == null) {
        continue;
      }
      const s = String(o[k]).trim();
      if (s) {
        return s;
      }
    }
  }
  return "";
}

function pickFirstNumber(objects, keys) {
  for (const o of objects) {
    for (const k of keys) {
      if (o[k] == null || o[k] === "") {
        continue;
      }
      const n = Number(o[k]);
      if (Number.isFinite(n)) {
        return n;
      }
    }
  }
  return 0;
}

function extractWebhookFields(payload) {
  let parsed = payload;
  if (typeof payload === "string") {
    try {
      parsed = JSON.parse(payload);
    } catch (_) {
      parsed = {};
    }
  }
  const p = parsed && typeof parsed === "object" ? parsed : {};
  const objects = flattenWebhookObjects(p);

  const refKeys = [
    "orderReference",
    "order_reference",
    "merchantReference",
    "merchant_reference",
    "merchantOrderId",
    "merchant_order_id",
    "externalReference",
    "external_reference",
    "transactionReference",
    "transaction_reference",
    "invoiceId",
    "invoice_id",
    "reference",
    "Reference",
  ];
  const orderReference = sanitizeOrderReference(pickFirstString(objects, refKeys));

  const amountKeys = [
    "amount",
    "paidAmount",
    "paid_amount",
    "totalPrice",
    "total_price",
    "transactionAmount",
    "transaction_amount",
    "payableAmount",
    "payable_amount",
    "value",
    "Amount",
  ];
  const amount = pickFirstNumber(objects, amountKeys);

  const statusKeys = [
    "status",
    "paymentStatus",
    "payment_status",
    "transactionStatus",
    "transaction_status",
    "orderStatus",
    "order_status",
    "payment_state",
    "result",
    "State",
  ];
  const rawStatus = pickFirstString(objects, statusKeys);
  const status = normalizePaymentWebhookStatus(rawStatus);

  const phoneKeys = [
    "phone",
    "customerPhone",
    "customer_phone",
    "msisdn",
    "payerPhone",
    "payer_phone",
    "payerMsisdn",
    "payer_msisdn",
    "mobileNumber",
    "mobile_number",
    "walletPhone",
    "wallet_phone",
    "phoneNumber",
    "phone_number",
    "customerMsisdn",
    "CustomerPhone",
    "PhoneNumber",
  ];
  const phone = pickFirstString(objects, phoneKeys);

  return { orderReference, amount, status, phone, rawStatus: rawStatus };
}

function normalizeItems(rawItems) {
  if (!Array.isArray(rawItems)) {
    return [];
  }

  return rawItems
    .map((item) => ({
      sku: String(item?.sku || "").trim().toUpperCase(),
      quantity: Number(item?.quantity || 0),
    }))
    .filter((item) => item.sku && item.quantity > 0);
}

function isSuccessStatus(value) {
  const s = String(value || "").trim().toUpperCase();
  return s === "SUCCESS" || s === "PAID" || s === "SUCCESSFUL" || s === "COMPLETED";
}

function isFailedStatus(value) {
  const s = String(value || "").trim().toUpperCase();
  return s === "FAILED" || s === "FAILURE";
}

async function generateToken(req, res, next) {
  try {
    const accessToken = await generateAccessToken();
    return res.json({ accessToken });
  } catch (error) {
    return next(error);
  }
}

async function createPaymentWithChannel(req, res, next, channel = "default") {
  try {
    const {
      totalPrice,
      amount,
      orderReference,
      orderCurrency = "TZS",
      customerName = "Customer",
      customerEmail = "customer@example.com",
      customerPhone = "255700000000",
      description = channel === "autopay" ? "AutoPay HaloPesa Payment" : "ClickPesa Payment",
    } = req.body || {};

    const customerPhoneSafe = String(customerPhone ?? "")
      .trim()
      .replace(/\s+/g, "")
      .slice(0, 50);
    const customerPhoneForOrder = customerPhoneSafe || "255700000000";
    const amountNum = Number(totalPrice || amount || 0);
    const finalTotal = Number.isFinite(amountNum) ? Math.floor(amountNum) : 0;

    if (finalTotal <= 0) {
      return res.status(400).json({
        message: "totalPrice (or amount) must be greater than 0.",
      });
    }

    const prefix = channel === "autopay" ? "APAY" : "TIS";
    const safeOrderReference = sanitizeOrderReference(orderReference || generateOrderReference(prefix));
    if (!safeOrderReference) {
      return res.status(400).json({
        message: "orderReference must contain letters/numbers only.",
      });
    }

    // AutoPay: direct USSD-PUSH to phone (no hosted checkout link).
    if (channel === "autopay") {
      const phoneDigits = customerPhoneForOrder.replace(/\D/g, "");
      if (phoneDigits.length < 10) {
        return res.status(400).json({
          message: "Enter a valid phone number with country code (e.g. 2557XXXXXXXX).",
        });
      }

      const ussd = await initiateUssdPush(
        {
          amount: String(finalTotal),
          currency: orderCurrency || "TZS",
          orderReference: safeOrderReference,
          phoneNumber: phoneDigits,
        },
        "autopay"
      );

      rememberPayment({
        id: safeOrderReference,
        orderReference: safeOrderReference,
        amount: finalTotal,
        status: String(ussd.status || "PROCESSING").toUpperCase() === "SUCCESS" ? "SUCCESS" : "PENDING",
        phone: phoneDigits,
        channel: "autopay",
        paymentMode: "ussd-push",
        customerName: String(customerName || "").trim() || "Customer",
        description,
        createdAt: new Date().toISOString(),
      });
      broadcastPaymentUpdate({
        type: "PAYMENT_CREATED",
        orderReference: safeOrderReference,
        channel: "autopay",
        paymentMode: "ussd-push",
      });

      return res.status(201).json({
        message: "USSD push sent. Ask the customer to enter their PIN on the phone.",
        channel: "autopay",
        paymentMode: "ussd-push",
        orderReference: safeOrderReference,
        status: ussd.status || "PROCESSING",
        mobileChannel: ussd.channelName || "",
        clickpesaResponse: ussd.raw,
      });
    }

    const payload = {
      totalPrice: String(finalTotal),
      orderReference: safeOrderReference,
      orderCurrency,
      customerName,
      customerEmail,
      customerPhone: customerPhoneForOrder,
      description,
    };

    const { checkoutLink, raw } = await createCheckoutLink(payload, channel);
    rememberPayment({
      id: safeOrderReference,
      orderReference: safeOrderReference,
      amount: finalTotal,
      status: "PENDING",
      phone: customerPhoneForOrder,
      channel,
      paymentMode: "checkout-link",
      customerName: String(customerName || "").trim() || "Customer",
      description,
      createdAt: new Date().toISOString(),
    });
    broadcastPaymentUpdate({
      type: "PAYMENT_CREATED",
      orderReference: safeOrderReference,
      channel,
    });

    return res.status(201).json({
      message: "Checkout link created successfully.",
      channel,
      paymentMode: "checkout-link",
      orderReference: safeOrderReference,
      checkoutLink,
      clickpesaResponse: raw,
    });
  } catch (error) {
    return next(error);
  }
}

async function createPayment(req, res, next) {
  return createPaymentWithChannel(req, res, next, "default");
}

async function createAutoPayPayment(req, res, next) {
  return createPaymentWithChannel(req, res, next, "autopay");
}

async function generateAutoPayToken(req, res, next) {
  try {
    const accessToken = await generateAccessToken("autopay");
    return res.json({ accessToken, channel: "autopay" });
  } catch (error) {
    return next(error);
  }
}

function mapAutoPayStatus(rawStatus) {
  const s = String(rawStatus || "").trim().toUpperCase().replace(/\s+/g, "_");
  if (["SUCCESS", "SUCCESSFUL", "SETTLED", "COMPLETED", "PAID", "APPROVED"].includes(s)) {
    return "SUCCESS";
  }
  if (["FAILED", "FAILURE", "DECLINED", "REJECTED", "CANCELLED", "CANCELED", "EXPIRED", "REVERSED"].includes(s)) {
    return "FAILED";
  }
  if (["PROCESSING", "PENDING", "ON-HOLD", "ON_HOLD"].includes(s)) {
    return "PENDING";
  }
  return s || "PENDING";
}

async function readDbPaymentStatus(orderReference) {
  try {
    const db = getPool();
    const [rows] = await db.query(
      `SELECT payment_status, amount, phone, channel, created_at
       FROM clickpesa_transactions
       WHERE order_reference = ?
       LIMIT 1`,
      [orderReference]
    );
    const row = rows && rows[0];
    if (!row) return null;
    return {
      status: mapWalletStatus(row.payment_status),
      amount: Number(row.amount || 0),
      phone: row.phone || "",
      channel: row.channel || "",
    };
  } catch (_) {
    return null;
  }
}

async function getAutoPayStatus(req, res, next) {
  try {
    const orderReference = sanitizeOrderReference(req.params.orderReference || req.query.orderReference || "");
    if (!orderReference) {
      return res.status(400).json({ message: "orderReference is required." });
    }

    const previous = recentPayments.get(orderReference);
    const dbRow = await readDbPaymentStatus(orderReference);

    let mapped = "PENDING";
    let result = {
      status: "",
      amount: previous?.amount || dbRow?.amount || 0,
      currency: "TZS",
      phone: previous?.phone || dbRow?.phone || "",
      channelName: "",
      message: "",
      raw: null,
    };

    // Prefer already-final local status (Yii webhook / prior poll) so we never "lose" SUCCESS.
    if (dbRow && (dbRow.status === "SUCCESS" || dbRow.status === "FAILED")) {
      mapped = dbRow.status;
      result.amount = dbRow.amount || result.amount;
      result.phone = dbRow.phone || result.phone;
      result.message = "Status from local database";
    } else if (previous && (previous.status === "SUCCESS" || previous.status === "FAILED")) {
      mapped = mapAutoPayStatus(previous.status);
      result.amount = previous.amount || result.amount;
      result.phone = previous.phone || result.phone;
      result.message = "Status from memory cache";
    } else {
      try {
        result = await queryPaymentStatus(orderReference, "autopay");
        mapped = mapAutoPayStatus(result.status);
      } catch (queryErr) {
        // If ClickPesa query fails, keep PENDING (or DB) instead of hard-failing the poller.
        console.warn("getAutoPayStatus ClickPesa query failed:", queryErr.message);
        mapped = dbRow ? dbRow.status : "PENDING";
        result.message = queryErr.message || "ClickPesa status query failed";
      }
    }

    const amountNum = Number(result.amount || previous?.amount || dbRow?.amount || 0);

    rememberPayment({
      id: orderReference,
      orderReference,
      amount: amountNum > 0 ? amountNum : Number(previous?.amount || 0),
      status: mapped === "PENDING" ? "PENDING" : mapped,
      phone: String(result.phone || previous?.phone || dbRow?.phone || "").trim(),
      channel: "autopay",
      paymentMode: "ussd-push",
      createdAt: previous?.createdAt || new Date().toISOString(),
      updatedAt: new Date().toISOString(),
    });

    if (mapped === "SUCCESS") {
      void finalizeSuccessfulPayment(
        orderReference,
        amountNum > 0 ? amountNum : Number(previous?.amount || dbRow?.amount || 0),
        String(result.phone || previous?.phone || dbRow?.phone || "").trim()
      ).catch((err) => console.warn("finalizeSuccessfulPayment:", err.message));
    }

    if (mapped === "SUCCESS" || mapped === "FAILED") {
      broadcastPaymentUpdate({
        type: mapped === "SUCCESS" ? "PAYMENT_SUCCESS" : "PAYMENT_FAILED",
        orderReference,
        channel: "autopay",
      });
    }

    return res.json({
      ok: true,
      channel: "autopay",
      orderReference,
      status: mapped,
      rawStatus: result.status || mapped,
      amount: amountNum > 0 ? amountNum : Number(previous?.amount || 0),
      currency: result.currency || "TZS",
      phone: result.phone || previous?.phone || dbRow?.phone || "",
      mobileChannel: result.channelName || "",
      message: result.message || "",
      clickpesaResponse: result.raw,
    });
  } catch (error) {
    return next(error);
  }
}

async function webhook(req, res, next) {
  try {
    const payload = req.body || {};
    const { orderReference, amount, status, phone, rawStatus } = extractWebhookFields(payload);

    if (!orderReference || !status) {
      return res.status(400).json({
        message: "Missing required webhook fields (orderReference, status).",
        received: { rawStatus, keys: Object.keys(payload).slice(0, 20) },
      });
    }
    if (status !== "SUCCESS" && status !== "FAILED") {
      return res.status(400).json({
        message: "Unsupported payment status. Expected SUCCESS or FAILED.",
        receivedStatus: rawStatus || status,
        normalizedStatus: status,
      });
    }
    const previous = recentPayments.get(orderReference);
    const amountNum = Number(amount || 0);
    const finalAmount = amountNum > 0 ? amountNum : Number(previous?.amount || 0);
    const finalPhone = String(phone || previous?.phone || "").trim().slice(0, 50);

    rememberPayment({
      id: orderReference,
      orderReference,
      amount: finalAmount,
      status,
      phone: finalPhone,
      createdAt: previous?.createdAt || new Date().toISOString(),
      updatedAt: new Date().toISOString(),
    });
    if (status === "SUCCESS") {
      void finalizeSuccessfulPayment(orderReference, finalAmount, finalPhone).catch((err) =>
        console.warn("finalizeSuccessfulPayment:", err.message)
      );
    }
    broadcastPaymentUpdate({ type: status === "SUCCESS" ? "PAYMENT_SUCCESS" : "PAYMENT_FAILED", orderReference });
    return res.json({ message: "Webhook processed successfully (in-memory)." });
  } catch (error) {
    return next(error);
  }
}

async function deletePayment(req, res, next) {
  try {
    const orderReference = String(req.params.orderReference || "")
      .trim()
      .toUpperCase()
      .replace(/[^A-Z0-9]/g, "");
    if (!orderReference) {
      return res.status(400).json({ success: false, message: "orderReference is required." });
    }

    recentPayments.delete(orderReference);
    try {
      paymentFileStore.removePayment(orderReference);
    } catch (_) {
      /* ignore */
    }

    let deleted = 0;
    try {
      const db = getPool();
      const [result] = await db.query(
        `DELETE FROM clickpesa_transactions WHERE order_reference = ? LIMIT 1`,
        [orderReference]
      );
      deleted = Number(result?.affectedRows || 0);
    } catch (err) {
      console.warn("deletePayment DB failed:", err.message);
    }

    if (!deleted && !recentPayments.has(orderReference)) {
      // Still OK if it was only in-memory and already removed
    }

    return res.json({
      success: true,
      message: "Payment deleted.",
      orderReference,
      deleted,
    });
  } catch (error) {
    return next(error);
  }
}

async function getPayments(req, res, next) {
  try {
    const payments = await listRecentPayments();
    const totalSales = payments
      .filter((payment) => isSuccessStatus(payment.status))
      .reduce((sum, payment) => sum + Number(payment.amount || 0), 0);
    const failedSales = payments
      .filter((payment) => isFailedStatus(payment.status))
      .reduce((sum, payment) => sum + Number(payment.amount || 0), 0);
    const pendingTransactions = payments.filter((payment) => {
      const status = String(payment.status || "").trim().toUpperCase();
      return status === "PENDING";
    }).length;
    return res.json({
      totalSales,
      failedSales,
      pendingTransactions,
      count: payments.length,
      payments,
    });
  } catch (error) {
    return next(error);
  }
}

async function getPaymentDetails(req, res, next) {
  try {
    const type = String(req.query.type || "").toLowerCase();
    const payments = await listRecentPayments();

    if (type === "success" || type === "failed") {
      const wanted = type === "success" ? "SUCCESS" : "FAILED";
      const rows = payments.filter((p) => String(p.status || "").trim().toUpperCase() === wanted);
      return res.json({
        type,
        count: rows.length,
        rows,
      });
    }

    if (type === "pending" || type === "unpaid") {
      const rows = payments.filter((p) => {
        const status = String(p.status || "").trim().toUpperCase();
        return status === "PENDING";
      });
      return res.json({
        type: "pending",
        count: rows.length,
        rows,
      });
    }

    return res.status(400).json({
      message: "Invalid type. Use success, failed, or pending.",
    });
  } catch (error) {
    return next(error);
  }
}

async function streamPayments(req, res) {
  res.setHeader("Content-Type", "text/event-stream");
  res.setHeader("Cache-Control", "no-cache, no-transform");
  res.setHeader("Connection", "keep-alive");
  res.setHeader("X-Accel-Buffering", "no");
  if (typeof res.flushHeaders === "function") {
    res.flushHeaders();
  }
  res.write(`data: ${JSON.stringify({ type: "CONNECTED" })}\n\n`);

  paymentStreams.add(res);

  // Idle SSE connections are often closed by proxies/browsers; comment pings keep the stream alive.
  const pingMs = 15000;
  const ping = setInterval(() => {
    try {
      res.write(":\n\n");
    } catch (_) {
      clearInterval(ping);
      paymentStreams.delete(res);
    }
  }, pingMs);

  const detach = () => {
    clearInterval(ping);
    paymentStreams.delete(res);
  };
  req.on("close", detach);
  res.on("close", detach);
}

async function getInventory(req, res, next) {
  try {
    return res.json({ items: [] });
  } catch (error) {
    return next(error);
  }
}

async function addTestItem(req, res, next) {
  try {
    return res.json({
      message: "Inventory is disabled in direct-pay mode.",
      item: null,
    });
  } catch (error) {
    return next(error);
  }
}

async function seedInventory(req, res, next) {
  try {
    return res.json({ message: "Inventory is disabled in direct-pay mode." });
  } catch (error) {
    return next(error);
  }
}

module.exports = {
  generateToken,
  generateAutoPayToken,
  createPayment,
  createAutoPayPayment,
  getAutoPayStatus,
  webhook,
  getPayments,
  getPaymentDetails,
  deletePayment,
  streamPayments,
  getInventory,
  addTestItem,
  seedInventory,
};
