require("dotenv").config();
const mysql = require("mysql2/promise");

function getConfig(ssl) {
  return {
    host: process.env.DB_HOST,
    port: Number(process.env.DB_PORT),
    user: process.env.DB_USER,
    password: process.env.DB_PASSWORD,
    database: process.env.DB_NAME,
    ...(ssl != null ? { ssl } : {}),
  };
}

function logConfig(config) {
  console.log("Config:", {
    host: config.host,
    port: config.port,
    database: config.database,
    user: config.user,
    password: "***",
    ssl: config.ssl ?? false,
  });
}

function logMysqlError(err) {
  console.error(err);
  console.error("code:", err && err.code);
  console.error("errno:", err && err.errno);
  console.error("sqlState:", err && err.sqlState);
  console.error("sqlMessage:", err && err.sqlMessage);
  console.error("fatal:", err && err.fatal);
}

async function tryConnect(label, config) {
  console.log(`\n=== ${label} ===`);
  logConfig(config);
  try {
    const conn = await mysql.createConnection(config);
    const [rows] = await conn.query("SELECT 1 AS ok");
    console.log("SUCCESS:", rows);
    await conn.end();
    return true;
  } catch (err) {
    logMysqlError(err);
    return false;
  }
}

(async () => {
  const noSsl = getConfig();
  const rejectUnauthorizedFalse = getConfig({ rejectUnauthorized: false });
  const enabledSsl = getConfig({});

  await tryConnect("No SSL", noSsl);
  await tryConnect("SSL rejectUnauthorized:false", rejectUnauthorizedFalse);
  await tryConnect("SSL enabled (empty object)", enabledSsl);
})();
