const { getPool } = require("../config/db");
const { generateAccessToken, createCheckoutLink } = require("../services/clickpesaService");

const paymentStreams = new Set();

function broadcastPaymentUpdate(payload) {
  const event = `data: ${JSON.stringify(payload)}\n\n`;
  for (const stream of paymentStreams) {
    stream.write(event);
  }
}

function generateOrderReference() {
  const now = Date.now();
  const randomPart = Math.floor(Math.random() * 900 + 100);
  return `TIS${now}${randomPart}`;
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

async function calculateInventoryTotal(items) {
  if (!items.length) {
    return 0;
  }

  const skuList = items.map((item) => item.sku);
  const placeholders = skuList.map(() => "?").join(", ");
  const db = getPool();
  const [inventoryRows] = await db.query(
    `SELECT sku, stock, unitPrice FROM inventory_items WHERE sku IN (${placeholders})`,
    skuList
  );
  const inventoryMap = new Map(inventoryRows.map((item) => [item.sku, item]));

  let total = 0;
  for (const item of items) {
    const inventory = inventoryMap.get(item.sku);
    if (!inventory) {
      throw new Error(`Inventory item ${item.sku} does not exist.`);
    }
    if (inventory.stock < item.quantity) {
      throw new Error(`Not enough stock for ${item.sku}. Available: ${inventory.stock}`);
    }
    total += Number(inventory.unitPrice || 0) * item.quantity;
  }

  return total;
}

async function generateToken(req, res, next) {
  try {
    const accessToken = await generateAccessToken();
    return res.json({ accessToken });
  } catch (error) {
    return next(error);
  }
}

async function createPayment(req, res, next) {
  try {
    const {
      totalPrice,
      orderReference,
      orderCurrency = "TZS",
      customerName = "Customer",
      customerEmail = "customer@example.com",
      customerPhone = "255700000000",
      description = "Inventory Payment",
      items = [],
    } = req.body || {};

    const customerPhoneSafe = String(customerPhone ?? "")
      .trim()
      .replace(/\s+/g, "")
      .slice(0, 50);
    const customerPhoneForOrder = customerPhoneSafe || "255700000000";

    const normalizedItems = normalizeItems(items);
    const orderTotalFromInventory = await calculateInventoryTotal(normalizedItems);
    let finalTotal = Number(totalPrice || 0);
    if (finalTotal <= 0) {
      finalTotal = orderTotalFromInventory;
    }

    if (finalTotal <= 0) {
      return res.status(400).json({
        message: "totalPrice is required when items are not provided.",
      });
    }

    const safeOrderReference = sanitizeOrderReference(orderReference || generateOrderReference());
    if (!safeOrderReference) {
      return res.status(400).json({
        message: "orderReference must contain letters/numbers only.",
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

    const { checkoutLink, raw } = await createCheckoutLink(payload);

    const db = getPool();
    const connection = await db.getConnection();
    let createdOrderReference = safeOrderReference;

    try {
      await connection.beginTransaction();
      const [existingRows] = await connection.query(
        "SELECT id FROM orders WHERE orderReference = ? LIMIT 1",
        [safeOrderReference]
      );

      if (existingRows.length > 0) {
        await connection.rollback();
        return res.status(409).json({
          message: "orderReference already exists. Use a unique reference.",
        });
      }

      const [insertOrderResult] = await connection.query(
        `INSERT INTO orders
          (orderReference, totalPrice, orderCurrency, status, source, customerName, customerEmail, customerPhone, description, checkoutLink)
         VALUES
          (?, ?, ?, 'PENDING', 'TIS', ?, ?, ?, ?, ?)`,
        [
          safeOrderReference,
          finalTotal,
          orderCurrency,
          customerName,
          customerEmail,
          customerPhoneForOrder,
          description,
          checkoutLink,
        ]
      );
      const orderId = insertOrderResult.insertId;

      for (const item of normalizedItems) {
        await connection.query("INSERT INTO order_items (orderId, sku, quantity) VALUES (?, ?, ?)", [
          orderId,
          item.sku,
          item.quantity,
        ]);
      }

      await connection.commit();
      createdOrderReference = safeOrderReference;
    } catch (dbError) {
      await connection.rollback();
      throw dbError;
    } finally {
      connection.release();
    }

    return res.status(201).json({
      message: "Checkout link created successfully.",
      orderReference: createdOrderReference,
      checkoutLink,
      clickpesaResponse: raw,
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

    // Rule 1: save only payments created from this TIS system.
    const db = getPool();
    const connection = await db.getConnection();
    let order;

    try {
      await connection.beginTransaction();
      const [orderRows] = await connection.query(
        "SELECT * FROM orders WHERE orderReference = ? AND source = 'TIS' LIMIT 1 FOR UPDATE",
        [orderReference]
      );

      if (!orderRows.length) {
        await connection.rollback();
        connection.release();
        return res.status(404).json({
          message: "Order was not created by this system.",
        });
      }
      order = orderRows[0];

      // Rule 5: amount check (fees / gateway rounding). Tolerance % via PAYMENT_AMOUNT_TOLERANCE_PCT (default 5).
      const orderTotal = Number(order.totalPrice || 0);
      const amountNum = Number(amount || 0);
      const diff = Math.abs(orderTotal - amountNum);
      const pct = Number(process.env.PAYMENT_AMOUNT_TOLERANCE_PCT || 5);
      const safePct = Number.isFinite(pct) && pct > 0 && pct <= 50 ? pct : 5;
      const tolerance = Math.max(5, Math.min(500, Math.round(orderTotal * (safePct / 100))));
      if (amountNum > 0 && diff > tolerance) {
        await connection.rollback();
        connection.release();
        return res.status(400).json({
          message: "Amount mismatch for this order. Payment rejected.",
          orderTotal,
          receivedAmount: amountNum,
        });
      }

      if (status === "SUCCESS") {
        const [existingSuccessRows] = await connection.query(
          "SELECT id FROM payments WHERE orderReference = ? AND status = 'SUCCESS' LIMIT 1",
          [orderReference]
        );
        if (existingSuccessRows.length > 0 || order.status === "PAID") {
          await connection.commit();
          connection.release();
          return res.json({ message: "Payment already processed." });
        }

        const warnings = [];
        const [orderItemRows] = await connection.query(
          "SELECT sku, quantity FROM order_items WHERE orderId = ?",
          [order.id]
        );
        for (const item of orderItemRows) {
          const [updatedInventory] = await connection.query(
            "UPDATE inventory_items SET stock = stock - ? WHERE sku = ? AND stock >= ?",
            [item.quantity, item.sku, item.quantity]
          );
          if (!updatedInventory.affectedRows) {
            warnings.push(`Could not reduce stock for ${item.sku}.`);
          }
        }

        const payPhone = String(phone || "").trim() || String(order.customerPhone || "").trim() || "";
        const payPhoneDb = payPhone.slice(0, 50);

        await connection.query(
          `INSERT INTO payments (orderReference, amount, status, phone, source)
           VALUES (?, ?, 'SUCCESS', ?, 'TIS')
           ON DUPLICATE KEY UPDATE amount = VALUES(amount), status = VALUES(status), phone = VALUES(phone), source = VALUES(source)`,
          [orderReference, amount > 0 ? amount : orderTotal, payPhoneDb]
        );
        if (String(phone || "").trim()) {
          await connection.query("UPDATE orders SET customerPhone = ? WHERE id = ?", [
            String(phone).trim().slice(0, 50),
            order.id,
          ]);
        }
        await connection.query("UPDATE orders SET status = 'PAID' WHERE id = ?", [order.id]);
        await connection.commit();
        connection.release();

        broadcastPaymentUpdate({
          type: "PAYMENT_SUCCESS",
          orderReference,
        });
        return res.json({
          message: "Webhook processed successfully.",
          warnings,
        });
      }

      if (status === "FAILED") {
        const failPhone = String(phone || "").trim() || String(order.customerPhone || "").trim() || "";
        const failPhoneDb = failPhone.slice(0, 50);

        await connection.query(
          `INSERT INTO payments (orderReference, amount, status, phone, source)
           VALUES (?, ?, 'FAILED', ?, 'TIS')
           ON DUPLICATE KEY UPDATE amount = VALUES(amount), status = VALUES(status), phone = VALUES(phone), source = VALUES(source)`,
          [orderReference, amount > 0 ? amount : Number(order.totalPrice || 0), failPhoneDb]
        );
        if (String(phone || "").trim()) {
          await connection.query("UPDATE orders SET customerPhone = ? WHERE id = ?", [
            String(phone).trim().slice(0, 50),
            order.id,
          ]);
        }
        await connection.query("UPDATE orders SET status = 'FAILED' WHERE id = ?", [order.id]);
        await connection.commit();
        connection.release();

        broadcastPaymentUpdate({
          type: "PAYMENT_FAILED",
          orderReference,
        });
        return res.json({ message: "Failed payment recorded." });
      }

      await connection.rollback();
      connection.release();
      return res.status(400).json({
        message: "Unsupported payment status. Expected a success or failure state.",
        receivedStatus: rawStatus || status,
        normalizedStatus: status,
      });
    } catch (dbError) {
      try {
        await connection.rollback();
      } catch (rollbackError) {
        // Ignore rollback errors.
      }
      connection.release();
      throw dbError;
    }
  } catch (error) {
    return next(error);
  }
}

async function getPayments(req, res, next) {
  try {
    const db = getPool();
    const [payments] = await db.query(
      `SELECT id, orderReference, amount, status, phone, createdAt
       FROM payments
       WHERE source = 'TIS'
       ORDER BY createdAt DESC`
    );

    // Fallback: include paid orders that may not yet have a payments row.
    const [paidOrdersWithoutPayment] = await db.query(
      `SELECT
         (1000000000 + o.id) AS id,
         o.orderReference,
         o.totalPrice AS amount,
         'SUCCESS' AS status,
         NULLIF(TRIM(o.customerPhone), '') AS phone,
         o.updatedAt AS createdAt
       FROM orders o
       LEFT JOIN payments p
         ON p.orderReference = o.orderReference
        AND p.source = 'TIS'
       WHERE o.source = 'TIS'
         AND o.status = 'PAID'
         AND p.id IS NULL
       ORDER BY o.updatedAt DESC`
    );
    const [pendingRows] = await db.query(
      `SELECT COUNT(*) AS pendingCount
       FROM orders o
       LEFT JOIN payments p
         ON p.orderReference = o.orderReference
        AND p.source = 'TIS'
        AND UPPER(TRIM(p.status)) = 'SUCCESS'
       WHERE o.source = 'TIS'
         AND o.status = 'PENDING'
         AND p.id IS NULL`
    );
    const mergedPayments = [...payments, ...paidOrdersWithoutPayment];
    mergedPayments.sort((a, b) => new Date(b.createdAt || 0) - new Date(a.createdAt || 0));

    const totalSales = mergedPayments
      .filter((payment) => isSuccessStatus(payment.status))
      .reduce((sum, payment) => sum + Number(payment.amount || 0), 0);
    const failedSales = mergedPayments
      .filter((payment) => isFailedStatus(payment.status))
      .reduce((sum, payment) => sum + Number(payment.amount || 0), 0);
    const pendingTransactions = Number(pendingRows?.[0]?.pendingCount || 0);
    return res.json({
      totalSales,
      failedSales,
      pendingTransactions,
      count: mergedPayments.length,
      payments: mergedPayments,
    });
  } catch (error) {
    return next(error);
  }
}

async function getPaymentDetails(req, res, next) {
  try {
    const type = String(req.query.type || "").toLowerCase();
    const db = getPool();

    if (type === "success" || type === "failed") {
      const allowedStatuses = type === "success" ? ["SUCCESS"] : ["FAILED"];
      const placeholders = allowedStatuses.map(() => "?").join(", ");
      const [rows] = await db.query(
        `SELECT id, orderReference, amount, status, phone, createdAt
         FROM payments
         WHERE source = 'TIS'
           AND UPPER(TRIM(status)) IN (${placeholders})
         UNION ALL
         SELECT
           (1000000000 + o.id) AS id,
           o.orderReference,
           o.totalPrice AS amount,
           CASE
             WHEN o.status = 'PAID' THEN 'SUCCESS'
             WHEN o.status = 'FAILED' THEN 'FAILED'
             ELSE o.status
           END AS status,
           NULLIF(TRIM(o.customerPhone), '') AS phone,
           o.updatedAt AS createdAt
         FROM orders o
         LEFT JOIN payments p
           ON p.orderReference = o.orderReference
          AND p.source = 'TIS'
         WHERE o.source = 'TIS'
           AND p.id IS NULL
           AND (
             (? = 'success' AND o.status = 'PAID')
             OR (? = 'failed' AND o.status = 'FAILED')
           )
         ORDER BY createdAt DESC`,
        [...allowedStatuses, type, type]
      );
      return res.json({
        type,
        count: rows.length,
        rows,
      });
    }

    if (type === "pending" || type === "unpaid") {
      const [rows] = await db.query(
        `SELECT
           o.id,
           o.orderReference,
           o.totalPrice AS amount,
           o.status,
           NULLIF(TRIM(o.customerPhone), '') AS phone,
           o.createdAt
         FROM orders o
         LEFT JOIN payments p
           ON p.orderReference = o.orderReference
          AND p.source = 'TIS'
          AND UPPER(TRIM(p.status)) IN ('SUCCESS', 'PAID', 'SUCCESSFUL', 'COMPLETED')
         WHERE o.source = 'TIS'
           AND o.status = 'PENDING'
           AND p.id IS NULL
         ORDER BY o.createdAt DESC`
      );
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
    const db = getPool();
    const [items] = await db.query(
      "SELECT id, sku, name, stock, unitPrice, createdAt, updatedAt FROM inventory_items ORDER BY name ASC"
    );
    return res.json({ items });
  } catch (error) {
    return next(error);
  }
}

async function addTestItem(req, res, next) {
  try {
    const db = getPool();
    const testItem = {
      sku: "ITEM-050",
      name: "Test Item TZS 50",
      stock: 100,
      unitPrice: 50,
    };
    await db.query(
      `INSERT INTO inventory_items (sku, name, stock, unitPrice)
       VALUES (?, ?, ?, ?)
       ON DUPLICATE KEY UPDATE
         name = VALUES(name),
         unitPrice = VALUES(unitPrice),
         stock = GREATEST(stock, VALUES(stock))`,
      [testItem.sku, testItem.name, testItem.stock, testItem.unitPrice]
    );
    return res.json({
      message: "Test item added successfully.",
      item: testItem,
    });
  } catch (error) {
    return next(error);
  }
}

async function seedInventory(req, res, next) {
  try {
    const db = getPool();
    const defaults = [
      { sku: "ITEM-001", name: "Soda Crate", stock: 30, unitPrice: 10000 },
      { sku: "ITEM-002", name: "Rice Bag 25kg", stock: 20, unitPrice: 65000 },
      { sku: "ITEM-003", name: "Cooking Oil 5L", stock: 40, unitPrice: 30000 },
    ];

    for (const item of defaults) {
      await db.query(
        `INSERT INTO inventory_items (sku, name, stock, unitPrice)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
           name = VALUES(name),
           stock = VALUES(stock),
           unitPrice = VALUES(unitPrice)`,
        [item.sku, item.name, item.stock, item.unitPrice]
      );
    }
    return res.json({ message: "Inventory seeded successfully." });
  } catch (error) {
    return next(error);
  }
}

module.exports = {
  generateToken,
  createPayment,
  webhook,
  getPayments,
  getPaymentDetails,
  streamPayments,
  getInventory,
  addTestItem,
  seedInventory,
};
