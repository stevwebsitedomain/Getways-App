/**
 * Resolve Yii API bases from the current frontend/web URL (XAMPP subdirectory safe).
 */
(function () {
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
    window.NECTA_WEB_BASE = resolveAppWebBase();
    window.TIS_API_BASE = resolveApiBase("api/tis");
    window.CLICKPESA_API_BASE = resolveApiBase("api/clickpesa");
  } catch (_) {
    window.NECTA_WEB_BASE = "/";
    window.TIS_API_BASE = "/api/tis";
    window.CLICKPESA_API_BASE = "/api/clickpesa";
  }
})();
