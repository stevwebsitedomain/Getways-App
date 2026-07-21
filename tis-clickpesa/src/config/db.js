const dns = require("dns");
const mysql = require("mysql2/promise");

const DEFAULT_DB_HOST = "sakura.proxy.rlwy.net";
const DEFAULT_DB_PORT = 27413;
const RAILWAY_PUBLIC_HOST = "sakura.proxy.rlwy.net";
const RAILWAY_PUBLIC_PORT = 27413;

let pool;

function sanitizeDbHost(rawHost) {
  const cleaned = String(rawHost || DEFAULT_DB_HOST)
    .trim()
    .replace(/^https?:\/\//i, "")
    .replace(/[;,]+$/g, "")
    .replace(/^['"]+|['"]+$/g, "");

  return cleaned.split(/[\s\r\n\t]/)[0] || DEFAULT_DB_HOST;
}

function sanitizeEnvValue(value, fallback = "") {
  if (value == null) {
    return fallback;
  }

  return String(value).trim().replace(/^['"]+|['"]+$/g, "");
}

function applyRailwayPublicProxy(config) {
  if (
    config.host === "mysql.railway.internal" ||
    config.host.endsWith(".railway.internal")
  ) {
    console.warn(
      `DB_HOST "${config.host}" only works inside Railway; using public proxy ${RAILWAY_PUBLIC_HOST}:${RAILWAY_PUBLIC_PORT}`
    );
    return {
      ...config,
      host: RAILWAY_PUBLIC_HOST,
      port: RAILWAY_PUBLIC_PORT,
    };
  }

  return config;
}

function getDbConfig() {
  const dbHost = sanitizeDbHost(process.env.DB_HOST);
  const dbPort = Number(sanitizeEnvValue(process.env.DB_PORT, String(DEFAULT_DB_PORT)));
  const dbUser = sanitizeEnvValue(process.env.DB_USER, "root");
  const dbPassword =
    process.env.DB_PASSWORD != null
      ? sanitizeEnvValue(process.env.DB_PASSWORD)
      : "ZFntrMWVmvQszgDhmtXMHzqKMCeriUFZ";
  const dbName = sanitizeEnvValue(process.env.DB_NAME, "railway");

  return applyRailwayPublicProxy({
    host: dbHost,
    port: dbPort,
    user: dbUser,
    password: dbPassword,
    database: dbName,
  });
}

function logDatabaseConfig(config) {
  console.log("DB_HOST:", JSON.stringify(config.host));
  console.log(
    "DB config:",
    JSON.stringify({
      host: config.host,
      port: config.port,
      database: config.database,
      user: config.user,
      password: "***",
      ssl: config.ssl === false ? false : config.ssl ?? false,
    })
  );
}

function logMysqlConnectionError(err, config) {
  console.error("MySQL connection error:");
  console.error(err);
  console.error("err.code:", err && err.code);
  console.error("err.errno:", err && err.errno);
  console.error("err.sqlState:", err && err.sqlState);
  console.error("err.sqlMessage:", err && err.sqlMessage);
  console.error("err.fatal:", err && err.fatal);
  console.error(
    "connection:",
    JSON.stringify({
      host: config.host,
      port: config.port,
      database: config.database,
      user: config.user,
      password: "***",
      ssl: config.ssl === false ? false : config.ssl ?? false,
    })
  );
}

function getMysqlConnectionOptions(config) {
  // StackCP remote MySQL on this host does not support SSL (HANDSHAKE_NO_SSL_SUPPORT).
  // Keep SSL disabled unless explicitly configured via DB_SSL=1.
  const sslRaw = String(process.env.DB_SSL || "").trim().toLowerCase();
  const useSsl = sslRaw === "1" || sslRaw === "true" || sslRaw === "yes";
  const connectTimeout = Number(process.env.DB_CONNECT_TIMEOUT_MS || 15000);

  return {
    host: config.host,
    port: config.port,
    user: config.user,
    password: config.password,
    database: config.database,
    connectTimeout: Number.isFinite(connectTimeout) ? connectTimeout : 15000,
    ...(useSsl ? { ssl: { rejectUnauthorized: false } } : { ssl: false }),
  };
}

function verifyDatabaseHostDns(dbHost) {
  return new Promise((resolve) => {
    dns.lookup(dbHost, (err, address) => {
      if (err) {
        console.warn("DB_HOST DNS lookup failed:", err.message);
        resolve(null);
        return;
      }

      console.log("DB_HOST DNS lookup:", address);
      resolve(address);
    });
  });
}

function assertSafeDatabaseName(name) {
  // Allow common DB names including dashes, but block unsafe characters.
  if (!/^[a-zA-Z0-9_-]+$/.test(name)) {
    throw new Error("DB_NAME contains invalid characters.");
  }
}

async function createDatabaseIfMissing(config) {
  assertSafeDatabaseName(config.database);
  const connectionOptions = getMysqlConnectionOptions(config);
  delete connectionOptions.database;

  let bootstrapConnection;
  try {
    bootstrapConnection = await mysql.createConnection(connectionOptions);
    await bootstrapConnection.query(
      `CREATE DATABASE IF NOT EXISTS \`${config.database}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci`
    );
  } catch (err) {
    logMysqlConnectionError(err, config);
    throw err;
  } finally {
    if (bootstrapConnection) {
      await bootstrapConnection.end();
    }
  }
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

  // Shared with Yii2 ClickPesa integration (webhook + wallet history).
  await db.query(`
    CREATE TABLE IF NOT EXISTS clickpesa_transactions (
      id INT NOT NULL AUTO_INCREMENT,
      order_reference VARCHAR(64) NOT NULL,
      control_number VARCHAR(64) DEFAULT NULL,
      amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
      currency VARCHAR(8) NOT NULL DEFAULT 'TZS',
      phone VARCHAR(32) DEFAULT NULL,
      customer_name VARCHAR(255) DEFAULT NULL,
      description VARCHAR(512) DEFAULT NULL,
      payment_status VARCHAR(32) NOT NULL DEFAULT 'PENDING',
      payout_status VARCHAR(32) DEFAULT NULL,
      payout_reference VARCHAR(64) DEFAULT NULL,
      payout_phone VARCHAR(32) DEFAULT NULL,
      payout_amount DECIMAL(18,2) DEFAULT NULL,
      transaction_type VARCHAR(32) NOT NULL DEFAULT 'collection',
      event_type VARCHAR(64) DEFAULT NULL,
      channel VARCHAR(64) DEFAULT NULL,
      checksum VARCHAR(128) DEFAULT NULL,
      raw_payload TEXT DEFAULT NULL,
      payout_payload TEXT DEFAULT NULL,
      created_at INT NOT NULL,
      updated_at INT NOT NULL,
      PRIMARY KEY (id),
      UNIQUE KEY uniq_clickpesa_order_reference (order_reference),
      KEY idx_clickpesa_control_number (control_number),
      KEY idx_clickpesa_payment_status (payment_status),
      KEY idx_clickpesa_payout_reference (payout_reference)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
  `);
}

async function connectDatabase() {
  const config = getDbConfig();
  logDatabaseConfig(config);
  await verifyDatabaseHostDns(config.host);

  const connectionOptions = getMysqlConnectionOptions(config);

  const autoCreateRaw = String(process.env.DB_AUTO_CREATE || "").trim().toLowerCase();
  const shouldAutoCreate =
    autoCreateRaw === "1" ||
    autoCreateRaw === "true" ||
    autoCreateRaw === "yes" ||
    process.env.NODE_ENV !== "production";
  if (shouldAutoCreate) {
    await createDatabaseIfMissing(config);
  }

  try {
    pool = mysql.createPool({
      ...connectionOptions,
      waitForConnections: true,
      connectionLimit: 10,
      queueLimit: 0,
    });

    await pool.query("SELECT 1");
    await initializeTables();
    console.log(`MySQL connected (${config.database}).`);
  } catch (err) {
    logMysqlConnectionError(err, config);
    throw err;
  }
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
