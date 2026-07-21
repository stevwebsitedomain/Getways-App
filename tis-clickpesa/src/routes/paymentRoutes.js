const express = require("express");
const {
  generateToken,
  generateAutoPayToken,
  createPayment,
  createAutoPayPayment,
  getAutoPayStatus,
  webhook,
  getPayments,
  getPaymentDetails,
  deletePayment,
  streamPayments,
  getInventory,
  addTestItem,
  seedInventory,
} = require("../controllers/paymentController");

const router = express.Router();

router.post("/generate-token", generateToken);
router.post("/create-payment", createPayment);
router.post("/autopay/generate-token", generateAutoPayToken);
router.post("/autopay/create-payment", createAutoPayPayment);
router.get("/autopay/payment-status/:orderReference", getAutoPayStatus);
router.post("/webhook", webhook);
router.get("/payments", getPayments);
router.get("/payments/details", getPaymentDetails);
router.delete("/payments/:orderReference", deletePayment);
router.get("/payments/stream", streamPayments);
router.get("/inventory", getInventory);
router.post("/inventory/add-test-item", addTestItem);
router.post("/inventory/seed", seedInventory);

module.exports = router;
