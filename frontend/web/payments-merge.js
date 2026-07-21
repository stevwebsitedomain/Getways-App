/**
 * Merge Yii + Node payment lists so history is never empty when either source has data.
 */
(function (global) {
  function statusRank(status) {
    const s = String(status || "")
      .trim()
      .toUpperCase();
    if (["SUCCESS", "SUCCESSFUL", "SETTLED", "COMPLETED", "PAID"].includes(s)) return 3;
    if (["FAILED", "FAILURE", "DECLINED"].includes(s)) return 2;
    if (s === "REFUNDED") return 2;
    return 1; // PENDING / unknown
  }

  function normalizePayment(p) {
    if (!p || typeof p !== "object") return null;
    const orderReference = String(p.orderReference || p.order_reference || "")
      .trim()
      .toUpperCase();
    if (!orderReference) return null;
    let status = String(p.status || p.paymentStatus || p.payment_status || "PENDING")
      .trim()
      .toUpperCase();
    if (["SUCCESSFUL", "SETTLED", "COMPLETED", "PAID"].includes(status)) status = "SUCCESS";
    if (["FAILURE", "DECLINED"].includes(status)) status = "FAILED";
    return {
      id: p.id || orderReference,
      orderReference,
      amount: Number(p.amount || 0),
      status,
      phone: p.phone || "",
      channel: p.channel || p.mobileChannel || "",
      createdAt: p.createdAt || p.created_at || null,
      updatedAt: p.updatedAt || p.updated_at || null,
    };
  }

  function mergePaymentLists(listA, listB) {
    const map = new Map();
    for (const raw of [...(listA || []), ...(listB || [])]) {
      const row = normalizePayment(raw);
      if (!row) continue;
      const prev = map.get(row.orderReference);
      if (!prev) {
        map.set(row.orderReference, row);
        continue;
      }
      const keep =
        statusRank(row.status) > statusRank(prev.status)
          ? { ...prev, ...row }
          : statusRank(row.status) < statusRank(prev.status)
            ? { ...row, ...prev }
            : { ...prev, ...row, amount: row.amount || prev.amount };
      map.set(row.orderReference, keep);
    }
    return Array.from(map.values()).sort(
      (a, b) => new Date(b.createdAt || b.updatedAt || 0) - new Date(a.createdAt || a.updatedAt || 0)
    );
  }

  function summarizePayments(payments) {
    let totalSales = 0;
    let failedSales = 0;
    let pendingTransactions = 0;
    for (const p of payments) {
      const status = String(p.status || "").toUpperCase();
      const amount = Number(p.amount || 0);
      if (status === "SUCCESS") totalSales += amount;
      else if (status === "FAILED") failedSales += amount;
      else if (status === "PENDING") pendingTransactions += 1;
    }
    return {
      totalSales,
      failedSales,
      pendingTransactions,
      count: payments.length,
      payments,
    };
  }

  function filterByType(payments, type) {
    const t = String(type || "success").toLowerCase();
    if (t === "failed") {
      return payments.filter((p) => String(p.status).toUpperCase() === "FAILED");
    }
    if (t === "pending" || t === "unpaid") {
      return payments.filter((p) => String(p.status).toUpperCase() === "PENDING");
    }
    return payments.filter((p) => String(p.status).toUpperCase() === "SUCCESS");
  }

  async function fetchJsonSafe(url, headers) {
    try {
      const res = await fetch(url, { cache: "no-store", headers: headers || {} });
      if (!res.ok) return null;
      return await res.json();
    } catch (_) {
      return null;
    }
  }

  /**
   * Load payments from Yii + Node and merge.
   */
  async function loadMergedPayments(apiBase, clickpesaBase, headers) {
    const yiiBase = clickpesaBase || global.CLICKPESA_API_BASE || `${global.location.origin}/api/clickpesa`;
    const nodeBase = apiBase || global.TIS_API_BASE || `${global.location.origin}/api/tis`;
    const hdrs = headers || { "ngrok-skip-browser-warning": "true" };

    const [yii, node] = await Promise.all([
      fetchJsonSafe(`${yiiBase}/payments`, hdrs),
      fetchJsonSafe(`${nodeBase}/payments`, hdrs),
    ]);

    const payments = mergePaymentLists(yii && yii.payments, node && node.payments);
    if (!payments.length && !yii && !node) {
      throw new Error("Unable to load payments from server.");
    }
    return summarizePayments(payments);
  }

  async function loadMergedDetails(type, apiBase, clickpesaBase, headers) {
    const summary = await loadMergedPayments(apiBase, clickpesaBase, headers);
    const rows = filterByType(summary.payments || [], type);
    return {
      type: String(type || "success").toLowerCase(),
      count: rows.length,
      rows,
    };
  }

  global.GetwayPaymentsMerge = {
    mergePaymentLists,
    summarizePayments,
    filterByType,
    loadMergedPayments,
    loadMergedDetails,
  };
})(window);
