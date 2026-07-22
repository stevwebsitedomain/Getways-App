const express = require("express");
const {
  balance,
  analytics,
  payoutSettings,
  updatePayoutSettings,
  controlNumbers,
  payouts,
  invoice,
  withdraw,
  createControlNumber,
} = require("../controllers/adminController");

const router = express.Router();

router.get("/balance", balance);
router.get("/analytics", analytics);
router.get("/payout-settings", payoutSettings);
router.post("/payout-settings", updatePayoutSettings);
router.get("/control-numbers", controlNumbers);
router.get("/invoice/:id", invoice);
router.post("/withdraw/:id", withdraw);
router.post("/withdraw", withdraw);
router.post("/create-control-number", createControlNumber);
router.get("/payouts", payouts);

module.exports = router;
