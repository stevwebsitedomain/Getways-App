/**
 * Local file backup for wallet payments.
 * Survives Node/ngrok restarts when MySQL is unreachable.
 */
const fs = require("fs");
const path = require("path");

const STORE_DIR = path.join(__dirname, "..", "..", "data");
const STORE_FILE = path.join(STORE_DIR, "payments-backup.json");
const MAX_ROWS = 300;

function ensureDir() {
  if (!fs.existsSync(STORE_DIR)) {
    fs.mkdirSync(STORE_DIR, { recursive: true });
  }
}

function readAll() {
  try {
    ensureDir();
    if (!fs.existsSync(STORE_FILE)) {
      return [];
    }
    const raw = fs.readFileSync(STORE_FILE, "utf8");
    const parsed = JSON.parse(raw);
    return Array.isArray(parsed) ? parsed : [];
  } catch (err) {
    console.warn("paymentFileStore read failed:", err.message);
    return [];
  }
}

function writeAll(rows) {
  try {
    ensureDir();
    const trimmed = (rows || []).slice(0, MAX_ROWS);
    fs.writeFileSync(STORE_FILE, JSON.stringify(trimmed, null, 2), "utf8");
  } catch (err) {
    console.warn("paymentFileStore write failed:", err.message);
  }
}

function upsertPayment(entry) {
  if (!entry || !entry.orderReference) {
    return;
  }
  const key = String(entry.orderReference).trim().toUpperCase();
  if (!key) {
    return;
  }
  const rows = readAll();
  const next = {
    id: key,
    orderReference: key,
    amount: Number(entry.amount || 0),
    status: String(entry.status || "PENDING").toUpperCase(),
    phone: entry.phone || "",
    channel: entry.channel || entry.paymentMode || "",
    createdAt: entry.createdAt || new Date().toISOString(),
    updatedAt: new Date().toISOString(),
  };
  const idx = rows.findIndex((r) => String(r.orderReference || "").toUpperCase() === key);
  if (idx >= 0) {
    const prev = rows[idx];
    const prevRank = statusRank(prev.status);
    const nextRank = statusRank(next.status);
    rows[idx] =
      nextRank >= prevRank
        ? { ...prev, ...next, createdAt: prev.createdAt || next.createdAt }
        : { ...next, ...prev, updatedAt: next.updatedAt };
  } else {
    rows.unshift(next);
  }
  rows.sort((a, b) => new Date(b.updatedAt || b.createdAt || 0) - new Date(a.updatedAt || a.createdAt || 0));
  writeAll(rows);
}

function removePayment(orderReference) {
  const key = String(orderReference || "")
    .trim()
    .toUpperCase();
  if (!key) {
    return;
  }
  const rows = readAll().filter((r) => String(r.orderReference || "").toUpperCase() !== key);
  writeAll(rows);
}

function listPayments() {
  return readAll();
}

function statusRank(status) {
  const s = String(status || "")
    .trim()
    .toUpperCase();
  if (["SUCCESS", "SUCCESSFUL", "SETTLED", "COMPLETED", "PAID"].includes(s)) return 3;
  if (["FAILED", "FAILURE", "DECLINED", "REFUNDED"].includes(s)) return 2;
  return 1;
}

module.exports = {
  upsertPayment,
  removePayment,
  listPayments,
};
