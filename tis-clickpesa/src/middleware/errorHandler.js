function pickMessage(payload) {
  if (!payload) return null;
  if (typeof payload === "string" && payload.trim()) return payload.trim();
  if (typeof payload !== "object") return null;

  if (typeof payload.message === "string" && payload.message.trim()) {
    return payload.message.trim();
  }
  if (Array.isArray(payload.message)) {
    return payload.message.map(String).join(" ").trim();
  }
  if (typeof payload.error === "string" && payload.error.trim()) {
    return payload.error.trim();
  }
  if (Array.isArray(payload.errors)) {
    return payload.errors
      .map((e) => (typeof e === "string" ? e : e?.message || JSON.stringify(e)))
      .join(" ")
      .trim();
  }
  return null;
}

function errorHandler(err, req, res, next) {
  const statusCode = err.response?.status || err.status || 500;
  const upstream = err.response?.data;
  const apiMessage =
    pickMessage(upstream) ||
    pickMessage(err) ||
    err.message ||
    "Internal server error";

  if (statusCode >= 500) {
    console.error("Server error:", apiMessage);
  }

  return res.status(statusCode).json({
    message: apiMessage,
    details: upstream || null,
  });
}

module.exports = errorHandler;
