const adminService = require("../services/adminService");

function isAuthorized(req) {
  const expected = String(process.env.ADMIN_API_TOKEN || "").trim();
  if (!expected) {
    return true;
  }
  const provided = String(req.get("x-admin-proxy-token") || req.get("authorization") || "")
    .replace(/^Bearer\s+/i, "")
    .trim();
  return provided === expected;
}

function unauthorized(res) {
  return res.status(401).json({
    ok: false,
    success: false,
    message: "Unauthorized admin proxy request.",
  });
}

async function balance(req, res, next) {
  try {
    if (!isAuthorized(req)) {
      return unauthorized(res);
    }
    const data = await adminService.getAccountBalance();
    return res.json({ ok: true, success: true, ...data });
  } catch (error) {
    return next(error);
  }
}

async function analytics(req, res, next) {
  try {
    if (!isAuthorized(req)) {
      return unauthorized(res);
    }
    const data = await adminService.getDashboardAnalytics({
      period: req.query.period,
      startDate: req.query.startDate,
      endDate: req.query.endDate,
    });
    return res.json({ ok: true, success: true, ...data });
  } catch (error) {
    return next(error);
  }
}

async function payoutSettings(req, res, next) {
  try {
    if (!isAuthorized(req)) {
      return unauthorized(res);
    }
    const data = await adminService.getAutoPayoutSettings();
    return res.json({ ok: true, success: true, ...data });
  } catch (error) {
    return next(error);
  }
}

async function updatePayoutSettings(req, res, next) {
  try {
    if (!isAuthorized(req)) {
      return unauthorized(res);
    }
    const data = await adminService.updateAutoPayoutSettings(req.body || {});
    return res.json({ ok: true, success: true, ...data });
  } catch (error) {
    if (error.statusCode) {
      return res.status(error.statusCode).json({
        ok: false,
        success: false,
        message: error.message,
      });
    }
    return next(error);
  }
}

async function controlNumbers(req, res, next) {
  try {
    if (!isAuthorized(req)) {
      return unauthorized(res);
    }
    const data = await adminService.listControlNumbers(100);
    return res.json({ ok: true, success: true, ...data });
  } catch (error) {
    return next(error);
  }
}

async function payouts(req, res, next) {
  try {
    if (!isAuthorized(req)) {
      return unauthorized(res);
    }
    const data = await adminService.listPayouts(100);
    return res.json({ ok: true, success: true, ...data });
  } catch (error) {
    return next(error);
  }
}

module.exports = {
  balance,
  analytics,
  payoutSettings,
  updatePayoutSettings,
  controlNumbers,
  payouts,
};
