const express = require("express");
const {
  balance,
  analytics,
  payoutSettings,
  updatePayoutSettings,
  controlNumbers,
  payouts,
} = require("../controllers/adminController");

const router = express.Router();

router.get("/balance", balance);
router.get("/analytics", analytics);
router.get("/payout-settings", payoutSettings);
router.post("/payout-settings", updatePayoutSettings);
router.get("/control-numbers", controlNumbers);
router.get("/payouts", payouts);

module.exports = router;
