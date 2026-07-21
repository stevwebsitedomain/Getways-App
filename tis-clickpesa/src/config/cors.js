const DEFAULT_CORS_ORIGINS = [
  "https://getway.legitconsult.co.tz",
  "https://www.getway.legitconsult.co.tz",
  "http://localhost",
  "http://127.0.0.1",
];

function isOriginAllowed(origin, allowed) {
  if (!origin) {
    return true;
  }
  if (allowed.includes("*")) {
    return true;
  }
  if (allowed.some((entry) => origin === entry || origin.startsWith(entry))) {
    return true;
  }
  return /^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?$/.test(origin);
}

function buildCorsOptions() {
  const fromEnv = String(process.env.CORS_ORIGINS || "")
    .split(",")
    .map((value) => value.trim())
    .filter(Boolean);
  const allowed = fromEnv.length > 0 ? fromEnv : DEFAULT_CORS_ORIGINS;

  return {
    origin(origin, callback) {
      callback(null, isOriginAllowed(origin, allowed));
    },
    credentials: true,
  };
}

module.exports = { buildCorsOptions, DEFAULT_CORS_ORIGINS };
