const express = require("express");
const {
  generateToken,
  createPayment,
  webhook,
  getPayments,
  getPaymentDetails,
  streamPayments,
  getInventory,
  addTestItem,
  seedInventory,
} = require("../controllers/paymentController");

const router = express.Router();

router.post("/generate-token", generateToken);
router.post("/create-payment", createPayment);
router.post("/webhook", webhook);
router.get("/payments", getPayments);
router.get("/payments/details", getPaymentDetails);
router.get("/payments/stream", streamPayments);
router.get("/inventory", getInventory);
router.post("/inventory/add-test-item", addTestItem);
router.post("/inventory/seed", seedInventory);

module.exports = router;
