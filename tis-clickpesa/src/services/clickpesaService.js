const axios = require("axios");
const crypto = require("crypto");

/** ClickPesa API is sometimes slow; override with CLICKPESA_HTTP_TIMEOUT_MS in .env (ms). */
const clickpesaTimeoutMs = (() => {
  const raw = Number(process.env.CLICKPESA_HTTP_TIMEOUT_MS);
  if (Number.isFinite(raw) && raw >= 5000 && raw <= 300000) {
    return raw;
  }
  return 120000;
})();

const clickpesaApi = axios.create({
  baseURL: "https://api.clickpesa.com/third-parties",
  timeout: clickpesaTimeoutMs,
});

/** Separate token caches so default Pay and AutoPay never mix credentials. */
const tokenCaches = {
  default: { accessToken: null, expiresAt: 0 },
  autopay: { accessToken: null, expiresAt: 0 },
};

/**
 * ClickPesa canonical HMAC-SHA256 checksum.
 * @see https://docs.clickpesa.com/home/checksum
 */
function canonicalize(obj) {
  if (obj === null || typeof obj !== "object") {
    return obj;
  }
  if (Array.isArray(obj)) {
    return obj.map(canonicalize);
  }
  return Object.keys(obj)
    .sort()
    .reduce((acc, key) => {
      acc[key] = canonicalize(obj[key]);
      return acc;
    }, {});
}

function createPayloadChecksum(checksumKey, payload) {
  const canonicalPayload = canonicalize(payload);
  const payloadString = JSON.stringify(canonicalPayload);
  return crypto.createHmac("sha256", checksumKey).update(payloadString).digest("hex");
}

function withChecksum(payload, channel = "default") {
  const body = { ...(payload || {}) };
  delete body.checksum;
  delete body.checksumMethod;

  const key =
    String(channel || "default").toLowerCase() === "autopay"
      ? process.env.AUTOPAY_CHECKSUM_KEY || process.env.CHECKSUM_KEY || ""
      : process.env.CHECKSUM_KEY || "";

  if (!key) {
    return body;
  }

  body.checksum = createPayloadChecksum(key, body);
  body.checksumMethod = "canonical";
  return body;
}

function resolveCredentials(channel = "default") {
  const key = String(channel || "default").toLowerCase() === "autopay" ? "autopay" : "default";
  if (key === "autopay") {
    return {
      channel: "autopay",
      clientId: process.env.AUTOPAY_CLIENT_ID || "",
      apiKey: process.env.AUTOPAY_API_KEY || "",
      missingMessage: "AUTOPAY_CLIENT_ID or AUTOPAY_API_KEY is missing in environment variables.",
    };
  }
  return {
    channel: "default",
    clientId: process.env.CLIENT_ID || "",
    apiKey: process.env.API_KEY || "",
    missingMessage: "CLIENT_ID or API_KEY is missing in environment variables.",
  };
}

async function generateAccessToken(channel = "default") {
  const creds = resolveCredentials(channel);
  if (!creds.clientId || !creds.apiKey) {
    throw new Error(creds.missingMessage);
  }

  const response = await clickpesaApi.post("/generate-token", null, {
    headers: {
      "client-id": creds.clientId,
      "api-key": creds.apiKey,
    },
  });

  const rawToken =
    response?.data?.accessToken ||
    response?.data?.access_token ||
    response?.data?.token ||
    response?.data?.data?.accessToken ||
    response?.data?.data?.access_token;

  if (!rawToken) {
    throw new Error("Token was not returned by ClickPesa.");
  }

  const accessToken = String(rawToken).replace(/^Bearer\s+/i, "").trim();
  const expiresInSeconds =
    Number(response?.data?.expiresIn || response?.data?.expires_in || 3300) || 3300;

  tokenCaches[creds.channel] = {
    accessToken,
    expiresAt: Date.now() + expiresInSeconds * 1000,
  };

  return accessToken;
}

async function getAccessToken(channel = "default") {
  const key = String(channel || "default").toLowerCase() === "autopay" ? "autopay" : "default";
  const cache = tokenCaches[key];
  const hasValidToken = cache.accessToken && Date.now() < cache.expiresAt - 60 * 1000;
  if (hasValidToken) {
    return cache.accessToken;
  }
  return generateAccessToken(key);
}

async function createCheckoutLink(payload, channel = "default") {
  const token = await getAccessToken(channel);
  const body = withChecksum(payload, channel);
  const response = await clickpesaApi.post("/checkout-link/generate-checkout-url", body, {
    headers: {
      Authorization: `Bearer ${token}`,
      "Content-Type": "application/json",
    },
  });

  const checkoutLink =
    response?.data?.checkoutLink ||
    response?.data?.checkoutUrl ||
    response?.data?.data?.checkoutLink ||
    response?.data?.data?.checkoutUrl;

  if (!checkoutLink) {
    throw new Error("Checkout link was not returned by ClickPesa.");
  }

  return {
    checkoutLink,
    raw: response.data,
    channel: String(channel || "default").toLowerCase() === "autopay" ? "autopay" : "default",
  };
}

/**
 * Direct mobile-money collection (USSD-PUSH) — no hosted checkout link.
 * @see https://docs.clickpesa.com/api-reference/collection/ussd-push-requests/initiate-ussd-push-request
 */
async function initiateUssdPush(payload, channel = "autopay") {
  const token = await getAccessToken(channel);
  const rawBody = {
    amount: String(payload.amount),
    currency: String(payload.currency || "TZS"),
    orderReference: String(payload.orderReference),
    phoneNumber: String(payload.phoneNumber).replace(/\D/g, ""),
  };
  const body = withChecksum(rawBody, channel);

  if (!body.checksum) {
    throw new Error(
      "checksum is required. Set AUTOPAY_CHECKSUM_KEY (or CHECKSUM_KEY) in tis-clickpesa/.env from ClickPesa Dashboard → Developers → Checksum."
    );
  }

  const response = await clickpesaApi.post("/payments/initiate-ussd-push-request", body, {
    headers: {
      Authorization: `Bearer ${token}`,
      "Content-Type": "application/json",
    },
  });

  return {
    raw: response.data,
    id: response?.data?.id || response?.data?.data?.id || "",
    status: response?.data?.status || response?.data?.data?.status || "PROCESSING",
    channelName: response?.data?.channel || response?.data?.data?.channel || "",
    orderReference: response?.data?.orderReference || body.orderReference,
  };
}

/**
 * Query payment status by order reference.
 * @see https://docs.clickpesa.com/api-reference/collection/querying-for-payments/querying-for-payments
 */
async function queryPaymentStatus(orderReference, channel = "autopay") {
  const token = await getAccessToken(channel);
  const ref = encodeURIComponent(String(orderReference || "").trim());
  if (!ref) {
    throw new Error("orderReference is required.");
  }

  const response = await clickpesaApi.get(`/payments/${ref}`, {
    headers: {
      Authorization: `Bearer ${token}`,
      Accept: "application/json",
    },
  });

  // ClickPesa docs: response is an ARRAY of payment objects (not a single object).
  let data = response?.data;
  if (data && typeof data === "object" && !Array.isArray(data) && data.data != null) {
    data = data.data;
  }
  if (Array.isArray(data)) {
    // Prefer SUCCESS/SETTLED/FAILED over PROCESSING/PENDING when multiple rows exist.
    const ranked = [...data].sort((a, b) => {
      const rank = (row) => {
        const s = String(row?.status || "").toUpperCase();
        if (["SUCCESS", "SETTLED", "COMPLETED", "PAID"].includes(s)) return 0;
        if (["FAILED", "FAILURE"].includes(s)) return 1;
        return 2;
      };
      return rank(a) - rank(b);
    });
    data = ranked[0] || {};
  }
  if (!data || typeof data !== "object" || Array.isArray(data)) {
    data = {};
  }

  const status = String(
    data.status || data.paymentStatus || data.state || ""
  )
    .trim()
    .toUpperCase();

  return {
    raw: response.data,
    orderReference: String(data.orderReference || orderReference).toUpperCase(),
    status,
    amount: data.collectedAmount ?? data.amount ?? "",
    currency: data.collectedCurrency || data.currency || "TZS",
    phone: data.paymentPhoneNumber || data.phoneNumber || data.phone || "",
    channelName: data.channel || data.paymentChannel || "",
    message: data.message || "",
    id: data.id || data.paymentReference || "",
  };
}

async function getAccountBalance(channel = "default") {
  const token = await getAccessToken(channel);
  const response = await clickpesaApi.get("/account/balance", {
    headers: { Authorization: `Bearer ${token}` },
  });
  const data = response?.data || {};
  const balance = Number(
    data.balance ?? data.availableBalance ?? data.accountBalance ?? data.data?.balance ?? 0
  );
  const currency = String(data.currency ?? data.data?.currency ?? "TZS").toUpperCase();

  return {
    success: true,
    currency,
    balance: Number.isFinite(balance) ? balance : 0,
    lastUpdated: new Date().toISOString(),
  };
}

async function previewMobileMoneyPayout(payload) {
  const token = await getAccessToken("default");
  const body = withChecksum(payload, "default");
  const response = await clickpesaApi.post("/payouts/preview-mobile-money-payout", body, {
    headers: {
      Authorization: `Bearer ${token}`,
      "Content-Type": "application/json",
    },
  });
  return response.data;
}

async function createMobileMoneyPayout(payload) {
  const token = await getAccessToken("default");
  const body = withChecksum(payload, "default");
  const response = await clickpesaApi.post("/payouts/create-mobile-money-payout", body, {
    headers: {
      Authorization: `Bearer ${token}`,
      "Content-Type": "application/json",
    },
  });
  return response.data;
}

async function createOrderControlNumber(payload) {
  const token = await getAccessToken("default");
  const body = withChecksum(payload, "default");
  const response = await clickpesaApi.post("/billpay/create-order-control-number", body, {
    headers: {
      Authorization: `Bearer ${token}`,
      "Content-Type": "application/json",
    },
  });
  return response.data;
}

module.exports = {
  generateAccessToken,
  getAccessToken,
  getAccountBalance,
  createCheckoutLink,
  initiateUssdPush,
  queryPaymentStatus,
  createPayloadChecksum,
  previewMobileMoneyPayout,
  createMobileMoneyPayout,
  createOrderControlNumber,
};
