const axios = require("axios");

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

let tokenCache = {
  accessToken: null,
  expiresAt: 0,
};

async function generateAccessToken() {
  const clientId = process.env.CLIENT_ID;
  const apiKey = process.env.API_KEY;

  if (!clientId || !apiKey) {
    throw new Error("CLIENT_ID or API_KEY is missing in environment variables.");
  }

  const response = await clickpesaApi.post("/generate-token", null, {
    headers: {
      "client-id": clientId,
      "api-key": apiKey,
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
  // If API does not return expiry, use a safe default (55 mins).
  const expiresInSeconds =
    Number(response?.data?.expiresIn || response?.data?.expires_in || 3300) || 3300;

  tokenCache = {
    accessToken,
    expiresAt: Date.now() + expiresInSeconds * 1000,
  };

  return accessToken;
}

async function getAccessToken() {
  const hasValidToken = tokenCache.accessToken && Date.now() < tokenCache.expiresAt - 60 * 1000;
  if (hasValidToken) {
    return tokenCache.accessToken;
  }
  return generateAccessToken();
}

async function createCheckoutLink(payload) {
  const token = await getAccessToken();
  const response = await clickpesaApi.post("/checkout-link/generate-checkout-url", payload, {
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
  };
}

module.exports = {
  generateAccessToken,
  getAccessToken,
  createCheckoutLink,
};
