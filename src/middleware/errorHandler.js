function errorHandler(err, req, res, next) {
  const statusCode = err.response?.status || err.status || 500;
  const apiMessage =
    err.response?.data?.message ||
    err.response?.data?.error ||
    err.message ||
    "Internal server error";

  if (statusCode >= 500) {
    console.error("Server error:", apiMessage);
  }

  return res.status(statusCode).json({
    message: apiMessage,
    details: err.response?.data || null,
  });
}

module.exports = errorHandler;
