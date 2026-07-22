const { getPool } = require("../config/db");
const { previewMobileMoneyPayout, createMobileMoneyPayout } = require("./clickpesaService");
const { getOrCreateSettingsRow, getDestinationPhone, maskPhone, normalizePhone } = require("./adminService");

const FINAL_PAYOUT_STATUSES = new Set(["SUCCESS", "REFUNDED", "REVERSED"]);
const IN_FLIGHT_PAYOUT_STATUSES = new Set(["QUEUED", "AWAITING_APPROVAL", "PROCESSING", "PREVIEWED", "PENDING"]);

function isSuccessfulPayment(status) {
  const value = String(status || "").trim().toUpperCase();
  return ["SUCCESS", "SUCCESSFUL", "COMPLETED", "PAID", "SETTLED"].includes(value);
}

function extractValue(obj, keys) {
  for (const key of keys) {
    const parts = String(key).split(".");
    let current = obj;
    for (const part of parts) {
      if (!current || typeof current !== "object") {
        current = undefined;
        break;
      }
      current = current[part];
    }
    if (current != null && current !== "") {
      return current;
    }
  }
  return null;
}

function mapPayoutStatus(response) {
  const status = String(
    extractValue(response, ["status", "payoutStatus", "data.status", "data.payoutStatus"]) || ""
  )
    .trim()
    .toUpperCase();
  if (["SUCCESS", "SUCCESSFUL", "COMPLETED", "SETTLED"].includes(status)) {
    return "SUCCESS";
  }
  if (["FAILED", "FAILURE", "DECLINED", "REJECTED"].includes(status)) {
    return "FAILED";
  }
  return status || "PENDING";
}

function calculatePayoutAmount(tx, settings) {
  const base = Number(tx.received_amount || tx.expected_amount || tx.amount || 0);
  let pct = Number(settings.payout_percentage || 100);
  if (!Number.isFinite(pct) || pct <= 0) {
    pct = 100;
  }
  return Math.round(base * (pct / 100) * 100) / 100;
}

async function syncLegacyPayoutFields(db, payout) {
  await db.query(
    `UPDATE clickpesa_transactions SET
      payout_reference = ?,
      payout_amount = ?,
      payout_status = ?,
      payout_phone = ?,
      payout_payload = ?,
      updated_at = ?
     WHERE id = ?`,
    [
      payout.payout_reference,
      payout.amount,
      payout.payout_status,
      payout.destination_masked,
      payout.raw_response,
      Math.floor(Date.now() / 1000),
      payout.payment_id,
    ]
  );
}

async function processPayoutRecord(db, payout, phone) {
  const normalizedPhone = normalizePhone(phone);
  const payload = {
    amount: Number(payout.amount),
    phoneNumber: normalizedPhone,
    currency: payout.currency || "TZS",
    orderReference: payout.payout_reference,
  };
  const now = Math.floor(Date.now() / 1000);

  await db.query("UPDATE clickpesa_payout SET payout_status = ?, raw_request = ?, updated_at = ? WHERE id = ?", [
    "PROCESSING",
    JSON.stringify(payload),
    now,
    payout.id,
  ]);

  try {
    const preview = await previewMobileMoneyPayout(payload);
    const fee = extractValue(preview, ["fee", "charges", "data.fee"]);
    await db.query(
      "UPDATE clickpesa_payout SET payout_status = ?, fee = ?, raw_response = ?, updated_at = ? WHERE id = ?",
      ["PREVIEWED", fee != null ? Number(fee) : null, JSON.stringify(preview), now, payout.id]
    );

    const response = await createMobileMoneyPayout(payload);
    let mapped = mapPayoutStatus(response);
    if (mapped === "SUCCESS") {
      mapped = "PENDING";
    }
    const provider = extractValue(response, ["provider", "channel", "data.provider"]);
    await db.query(
      "UPDATE clickpesa_payout SET payout_status = ?, provider = ?, raw_response = ?, updated_at = ? WHERE id = ?",
      [mapped === "FAILED" ? "FAILED" : "PENDING", provider ? String(provider) : null, JSON.stringify(response), now, payout.id]
    );

    const [rows] = await db.query("SELECT * FROM clickpesa_payout WHERE id = ? LIMIT 1", [payout.id]);
    const updated = rows[0];
    if (updated) {
      await syncLegacyPayoutFields(db, updated);
    }
    return updated;
  } catch (error) {
    await db.query(
      "UPDATE clickpesa_payout SET payout_status = ?, last_error = ?, updated_at = ? WHERE id = ?",
      ["FAILED", String(error.message || error), now, payout.id]
    );
    const [rows] = await db.query("SELECT * FROM clickpesa_payout WHERE id = ? LIMIT 1", [payout.id]);
    const updated = rows[0];
    if (updated) {
      await syncLegacyPayoutFields(db, updated);
    }
    throw error;
  }
}

async function queueOrCreatePayout(db, tx, amount, phone, fromAuto = false) {
  const [existingRows] = await db.query("SELECT * FROM clickpesa_payout WHERE payment_id = ? LIMIT 1", [tx.id]);
  const existing = existingRows[0];
  if (existing) {
    const status = String(existing.payout_status || "").toUpperCase();
    if (FINAL_PAYOUT_STATUSES.has(status) || IN_FLIGHT_PAYOUT_STATUSES.has(status)) {
      const error = new Error("Payout already exists for this payment.");
      error.statusCode = 409;
      throw error;
    }
    return existing;
  }

  const now = Math.floor(Date.now() / 1000);
  const payoutReference = `TIS-PAYOUT-${tx.id}-${now}${Math.floor(Math.random() * 90) + 10}`;
  await db.query(
    `INSERT INTO clickpesa_payout
      (payment_id, payout_reference, destination_type, destination_masked, amount, currency, payout_status, retry_count, created_at, updated_at)
     VALUES (?, ?, 'MOBILE_MONEY', ?, ?, ?, 'QUEUED', 0, ?, ?)`,
    [
      tx.id,
      payoutReference,
      maskPhone(normalizePhone(phone)),
      amount,
      tx.currency || "TZS",
      now,
      now,
    ]
  );
  const [rows] = await db.query("SELECT * FROM clickpesa_payout WHERE payment_id = ? LIMIT 1", [tx.id]);
  const payout = rows[0];
  if (!payout) {
    const error = new Error("Failed to create payout record.");
    error.statusCode = 500;
    throw error;
  }
  if (!fromAuto) {
    console.info("Payout queued", { paymentId: tx.id, payoutReference, amount });
  }
  return payout;
}

async function maybeQueueAutomaticPayout(tx) {
  const db = getPool();
  const settings = await getOrCreateSettingsRow(db);
  const mode = String(settings.mode || "TEST").toUpperCase();
  if (!settings.auto_payout_enabled || mode === "TEST" || mode !== "LIVE_AUTO") {
    return false;
  }

  const [existingRows] = await db.query("SELECT id FROM clickpesa_payout WHERE payment_id = ? LIMIT 1", [tx.id]);
  if (existingRows[0]) {
    return false;
  }

  const phone = getDestinationPhone(settings.encrypted_destination);
  if (!phone) {
    return false;
  }

  const amount = calculatePayoutAmount(tx, settings);
  if (amount < Number(settings.minimum_amount || 0)) {
    return false;
  }

  const payout = await queueOrCreatePayout(db, tx, amount, phone, true);
  const delay = Math.max(0, Number(settings.delay_seconds || 0));
  const nextRetryAt = Math.floor(Date.now() / 1000) + delay;
  await db.query("UPDATE clickpesa_payout SET next_retry_at = ?, updated_at = ? WHERE id = ?", [
    nextRetryAt,
    Math.floor(Date.now() / 1000),
    payout.id,
  ]);

  if (delay <= 0) {
    await processPayoutRecord(db, payout, phone);
  }
  return true;
}

async function processPendingAutoPayouts(limit = 5) {
  const db = getPool();
  const settings = await getOrCreateSettingsRow(db);
  const mode = String(settings.mode || "TEST").toUpperCase();
  if (!settings.auto_payout_enabled || mode !== "LIVE_AUTO") {
    return { processed: 0 };
  }

  const phone = getDestinationPhone(settings.encrypted_destination);
  if (!phone) {
    return { processed: 0 };
  }

  const now = Math.floor(Date.now() / 1000);
  const [rows] = await db.query(
    `SELECT * FROM clickpesa_payout
     WHERE payout_status IN ('QUEUED', 'FAILED')
       AND (next_retry_at IS NULL OR next_retry_at <= ?)
     ORDER BY id ASC
     LIMIT ?`,
    [now, limit]
  );

  let processed = 0;
  for (const payout of rows) {
    if (String(payout.payout_status).toUpperCase() === "FAILED" && Number(payout.retry_count || 0) >= 3) {
      continue;
    }
    try {
      await processPayoutRecord(db, payout, phone);
      processed += 1;
    } catch (error) {
      console.warn("Auto payout failed:", error.message);
    }
  }
  return { processed };
}

async function finalizeSuccessfulPayment(orderReference, amount = 0, phone = "") {
  const db = getPool();
  const ref = String(orderReference || "").trim().toUpperCase();
  if (!ref) {
    return null;
  }

  const now = Math.floor(Date.now() / 1000);
  const [rows] = await db.query("SELECT * FROM clickpesa_transactions WHERE order_reference = ? LIMIT 1", [ref]);
  const tx = rows[0];
  if (!tx) {
    return null;
  }

  const wasPaid = isSuccessfulPayment(tx.payment_status);
  const paidAmount = Number(amount || tx.received_amount || tx.expected_amount || tx.amount || 0);
  await db.query(
    `UPDATE clickpesa_transactions SET
      payment_status = 'SUCCESS',
      received_amount = CASE WHEN ? > 0 THEN ? ELSE COALESCE(received_amount, expected_amount, amount) END,
      paid_at = COALESCE(paid_at, ?),
      phone = COALESCE(NULLIF(?, ''), phone),
      updated_at = ?
     WHERE id = ?`,
    [paidAmount, paidAmount, now, String(phone || "").trim(), now, tx.id]
  );

  const [updatedRows] = await db.query("SELECT * FROM clickpesa_transactions WHERE id = ? LIMIT 1", [tx.id]);
  const updated = updatedRows[0];
  if (!updated) {
    return null;
  }

  if (!wasPaid) {
    try {
      await maybeQueueAutomaticPayout(updated);
    } catch (error) {
      console.warn("Auto payout queue failed:", error.message);
    }
  }
  return updated;
}

async function withdrawPayment(paymentId) {
  const db = getPool();
  const [txRows] = await db.query("SELECT * FROM clickpesa_transactions WHERE id = ? LIMIT 1", [paymentId]);
  const tx = txRows[0];
  if (!tx) {
    const error = new Error("Payment not found.");
    error.statusCode = 404;
    throw error;
  }
  if (!isSuccessfulPayment(tx.payment_status)) {
    const error = new Error("Payment must be successful before withdraw.");
    error.statusCode = 400;
    throw error;
  }

  const settings = await getOrCreateSettingsRow(db);
  const mode = String(settings.mode || "TEST").toUpperCase();
  if (!settings.auto_payout_enabled || mode === "TEST") {
    const error = new Error("Enable payout in settings before withdrawing.");
    error.statusCode = 400;
    throw error;
  }
  if (mode === "LIVE_AUTO") {
    const error = new Error("Automatic payout is enabled. Funds withdraw automatically after payment.");
    error.statusCode = 400;
    throw error;
  }

  const phone = getDestinationPhone(settings.encrypted_destination);
  if (!phone) {
    const error = new Error("Configure payout destination phone first.");
    error.statusCode = 400;
    throw error;
  }

  const amount = calculatePayoutAmount(tx, settings);
  let payout;
  const [existingRows] = await db.query("SELECT * FROM clickpesa_payout WHERE payment_id = ? LIMIT 1", [tx.id]);
  payout = existingRows[0];
  if (!payout) {
    payout = await queueOrCreatePayout(db, tx, amount, phone, false);
  } else {
    const status = String(payout.payout_status || "").toUpperCase();
    if (FINAL_PAYOUT_STATUSES.has(status)) {
      const error = new Error("Payment already withdrawn.");
      error.statusCode = 409;
      throw error;
    }
    if (!IN_FLIGHT_PAYOUT_STATUSES.has(status) && status !== "FAILED") {
      payout = await queueOrCreatePayout(db, tx, amount, phone, false);
    }
  }

  payout = await processPayoutRecord(db, payout, phone);
  return {
    success: true,
    message: "Withdraw initiated to destination number.",
    payout: {
      id: payout.id,
      payoutReference: payout.payout_reference,
      amount: Number(payout.amount || 0),
      status: payout.payout_status,
      destinationMasked: payout.destination_masked,
    },
  };
}

function resolveWithdrawInfo(tx, payout, settings) {
  if (!isSuccessfulPayment(tx.payment_status)) {
    return { withdrawStatus: "—", canWithdraw: false };
  }

  const enabled = Boolean(settings?.auto_payout_enabled);
  const mode = String(settings?.mode || "TEST").toUpperCase();
  if (!enabled || mode === "TEST") {
    return { withdrawStatus: "Payout off", canWithdraw: false };
  }

  const payoutStatus = String(payout?.payout_status || tx.payout_status || "").toUpperCase();
  if (["SUCCESS", "COMPLETED", "SETTLED"].includes(payoutStatus)) {
    return { withdrawStatus: "Withdrawn", canWithdraw: false };
  }
  if (IN_FLIGHT_PAYOUT_STATUSES.has(payoutStatus)) {
    return { withdrawStatus: "Processing…", canWithdraw: false };
  }
  if (payoutStatus === "FAILED") {
    return { withdrawStatus: "Failed", canWithdraw: mode === "MANUAL_APPROVAL" };
  }
  if (mode === "LIVE_AUTO") {
    return { withdrawStatus: payoutStatus || "Auto queued", canWithdraw: false };
  }
  return { withdrawStatus: "Not withdrawn", canWithdraw: true };
}

module.exports = {
  finalizeSuccessfulPayment,
  maybeQueueAutomaticPayout,
  processPendingAutoPayouts,
  withdrawPayment,
  resolveWithdrawInfo,
  isSuccessfulPayment,
};
