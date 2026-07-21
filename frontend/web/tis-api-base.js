/**
 * Central API configuration — Node backend on Render.
 */
(function () {
  const BASE_API_URL = "https://getways-app.onrender.com";

  function resolveAppWebBase() {
    const path = String(window.location.pathname || "/");
    const dir = path.replace(/[^/]*$/, "");
    return `${window.location.origin}${dir}`;
  }

  function resolveApiBase(suffix) {
    const base = resolveAppWebBase();
    return `${base}${String(suffix || "").replace(/^\//, "")}`;
  }

  try {
    window.BASE_API_URL = BASE_API_URL;
    window.NECTA_WEB_BASE = resolveAppWebBase();
    window.TIS_API_BASE = BASE_API_URL.replace(/\/$/, "");
    window.CLICKPESA_API_BASE = resolveApiBase("api/clickpesa");
  } catch (_) {
    window.BASE_API_URL = BASE_API_URL;
    window.NECTA_WEB_BASE = "/";
    window.TIS_API_BASE = BASE_API_URL;
    window.CLICKPESA_API_BASE = "/api/clickpesa";
  }
})();
