const mysql = require("mysql2/promise");

let pool;

function getDbConfig() {
  return {
    host: process.env.DB_HOST || "sdb-84.hosting.stackcp.net",
    port: Number(process.env.DB_PORT || 3306),
    user: process.env.DB_USER || "steve-b80b",
    password: process.env.DB_PASSWORD || "steven2026",
    database: process.env.DB_NAME || "clickpesa-353039360f5f",
  };
}

function assertSafeDatabaseName(name) {
  if (!/^[a-zA-Z0-9_]+$/.test(name)) {
    throw new Error("DB_NAME contains invalid characters.");
  }
}

async function createDatabaseIfMissing(config) {
  assertSafeDatabaseName(config.database);
  const bootstrapConnection = await mysql.createConnection({
    host: config.host,
    port: config.port,
    user: config.user,
    password: config.password,
    multipleStatements: true,
  });

  await bootstrapConnection.query(
    `CREATE DATABASE IF NOT EXISTS \`${config.database}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci`
  );
  await bootstrapConnection.end();
}

async function initializeTables() {
  const db = getPool();

  await db.query(`
    CREATE TABLE IF NOT EXISTS inventory_items (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      sku VARCHAR(64) NOT NULL,
      name VARCHAR(150) NOT NULL,
      stock INT NOT NULL DEFAULT 0,
      unitPrice DECIMAL(14,2) NOT NULL DEFAULT 0,
      createdAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updatedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY uniq_inventory_sku (sku)
    ) ENGINE=InnoDB;
  `);

  await db.query(`
    CREATE TABLE IF NOT EXISTS orders (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      orderReference VARCHAR(120) NOT NULL,
      totalPrice DECIMAL(14,2) NOT NULL,
      orderCurrency VARCHAR(10) NOT NULL DEFAULT 'TZS',
      status ENUM('PENDING','PAID','FAILED') NOT NULL DEFAULT 'PENDING',
      source VARCHAR(20) NOT NULL DEFAULT 'TIS',
      customerName VARCHAR(150) NOT NULL,
      customerEmail VARCHAR(150) NOT NULL,
      customerPhone VARCHAR(50) NOT NULL,
      description VARCHAR(255) DEFAULT 'Inventory Payment',
      checkoutLink TEXT,
      createdAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updatedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY uniq_orders_reference (orderReference)
    ) ENGINE=InnoDB;
  `);

  await db.query(`
    CREATE TABLE IF NOT EXISTS order_items (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      orderId BIGINT UNSIGNED NOT NULL,
      sku VARCHAR(64) NOT NULL,
      quantity INT NOT NULL,
      createdAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY idx_order_items_order (orderId),
      CONSTRAINT fk_order_items_order
        FOREIGN KEY (orderId) REFERENCES orders(id)
        ON DELETE CASCADE
    ) ENGINE=InnoDB;
  `);

  await db.query(`
    CREATE TABLE IF NOT EXISTS payments (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      orderReference VARCHAR(120) NOT NULL,
      amount DECIMAL(14,2) NOT NULL,
      status ENUM('SUCCESS','FAILED') NOT NULL,
      phone VARCHAR(50) DEFAULT '',
      source VARCHAR(20) NOT NULL DEFAULT 'TIS',
      createdAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY uniq_payments_reference (orderReference),
      KEY idx_payments_created_at (createdAt)
    ) ENGINE=InnoDB;
  `);
}

async function connectDatabase() {
  const config = getDbConfig();
  await createDatabaseIfMissing(config);

  pool = mysql.createPool({
    host: config.host,
    port: config.port,
    user: config.user,
    password: config.password,
    database: config.database,
    waitForConnections: true,
    connectionLimit: 10,
    queueLimit: 0,
  });

  await pool.query("SELECT 1");
  await initializeTables();
  console.log(`MySQL connected (${config.database}).`);
}

function getPool() {
  if (!pool) {
    throw new Error("Database pool is not initialized.");
  }
  return pool;
}

module.exports = {
  connectDatabase,
  getPool,
};
