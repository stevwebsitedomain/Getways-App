(function () {
  "use strict";

  const API_URL = "ai-robot-api.php";
  const LANG_KEY = "nectaAppLanguage";
  const MODE_KEY = "gwRobotMode";
  const MONITOR_INTERVAL = 30000;
  const ERROR_CHECK_INTERVAL = 15000;

  let root = null;
  let panelOpen = false;
  let currentMode = "overview";
  let statusData = null;
  let speaking = false;
  let monitorTimer = null;
  let errorTimer = null;
  let lastSpokenErrorCount = -1;
  let synth = window.speechSynthesis || null;

  const MODES = {
    overview: { icon: "fa-gauge-high", labelSw: "Muhtasari", labelEn: "Overview" },
    login: { icon: "fa-right-to-bracket", labelSw: "Ingia", labelEn: "Logins" },
    monitor: { icon: "fa-eye", labelSw: "Ufuatiliaji", labelEn: "Monitor" },
    error: { icon: "fa-bug", labelSw: "Makosa", labelEn: "Errors" },
  };

  function getLang() {
    try {
      const stored = localStorage.getItem(LANG_KEY);
      return stored === "en" ? "en" : "sw";
    } catch (_) {
      return "sw";
    }
  }

  function t(sw, en) {
    return getLang() === "sw" ? sw : en;
  }

  function escapeHtml(v) {
    return String(v ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;");
  }

  async function apiFetch(action, options = {}) {
    const method = options.method || "GET";
    const params = new URLSearchParams({ action, lang: getLang() });
    if (options.mode) params.set("mode", options.mode);
    const url = `${API_URL}?${params.toString()}`;
    const fetchOpts = {
      method,
      credentials: "same-origin",
      cache: "no-store",
      headers: { "Content-Type": "application/json" },
    };
    if (method === "POST" && options.body) {
      fetchOpts.body = JSON.stringify(options.body);
    }
    const res = await fetch(url, fetchOpts);
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
      throw new Error(data.message || `API error ${res.status}`);
    }
    return data;
  }

  function reportClientError(message, source = "client") {
    apiFetch("report-error", {
      method: "POST",
      body: {
        source,
        message,
        page: document.title,
        url: window.location.href,
        severity: "error",
      },
    }).catch(() => {});
  }

  function getVoiceLang() {
    return getLang() === "sw" ? "sw-TZ" : "en-US";
  }

  function stopSpeaking() {
    if (synth) {
      synth.cancel();
    }
    speaking = false;
    updateSpeakingUI(false);
  }

  function updateSpeakingUI(active) {
    const fab = root?.querySelector(".gw-robot-fab");
    const avatar = root?.querySelector(".gw-robot-avatar");
    fab?.classList.toggle("is-speaking", active);
    avatar?.classList.toggle("is-speaking", active);
  }

  function speak(text) {
    if (!text || !synth) {
      setSpeechText(text || t("Sauti haipatikani.", "Speech not available."));
      return Promise.resolve();
    }

    stopSpeaking();
    speaking = true;
    updateSpeakingUI(true);

    return new Promise((resolve) => {
      const utter = new SpeechSynthesisUtterance(text);
      utter.lang = getVoiceLang();
      utter.rate = 0.95;
      utter.pitch = 1;

      const voices = synth.getVoices();
      const preferred = voices.find(
        (v) => v.lang.startsWith(getLang() === "sw" ? "sw" : "en") && !v.localService
      ) || voices.find((v) => v.lang.startsWith(getLang() === "sw" ? "sw" : "en"));
      if (preferred) utter.voice = preferred;

      utter.onend = () => {
        speaking = false;
        updateSpeakingUI(false);
        resolve();
      };
      utter.onerror = () => {
        speaking = false;
        updateSpeakingUI(false);
        resolve();
      };

      synth.speak(utter);
    });
  }

  function setSpeechText(text) {
    const el = root?.querySelector(".gw-robot-speech");
    if (el) el.textContent = text;
  }

  function updateStatusUI(data) {
    statusData = data;
    const dot = root?.querySelector(".gw-robot-dot");
    const statusEl = root?.querySelector(".gw-robot-status-text");
    const badge = root?.querySelector(".gw-robot-badge");
    const fixBtn = root?.querySelector(".gw-robot-fix-btn");
    const count = data?.errorCount ?? 0;

    dot?.classList.toggle("has-error", count > 0);
    if (statusEl) {
      statusEl.textContent = count > 0
        ? t(`Makosa ${count} yamegunduliwa`, `${count} error(s) detected`)
        : t("Mfumo unaendeshwa vizuri", "System running smoothly");
    }
    if (badge) {
      if (count > 0) {
        badge.textContent = String(count);
        badge.hidden = false;
      } else {
        badge.hidden = true;
      }
    }
    if (fixBtn) {
      fixBtn.disabled = count === 0;
    }
  }

  async function refreshStatus() {
    try {
      const data = await apiFetch("status");
      updateStatusUI(data);
      return data;
    } catch (err) {
      reportClientError(err.message || "Status fetch failed", "api");
      return null;
    }
  }

  async function speakMode(mode) {
    currentMode = mode || currentMode;
    try {
      const data = await apiFetch("speak", { mode: currentMode });
      setSpeechText(data.text || "");
      await speak(data.text || "");
    } catch (err) {
      const msg = t("Imeshindwa kupata taarifa.", "Failed to get information.");
      setSpeechText(msg);
      reportClientError(err.message || "Speak failed", "api");
    }
  }

  async function autoFixErrors(speakResult = true) {
    try {
      const data = await apiFetch("fix", { method: "POST", body: {} });
      await refreshStatus();

      if (speakResult) {
        const fixed = (data.fixed || []).join(". ");
        const failed = (data.failed || []).join(". ");
        let msg;
        if (fixed) {
          msg = t(`Nimerekebisha: ${fixed}`, `Fixed: ${fixed}`);
        } else if (failed) {
          msg = t(`Imeshindwa: ${failed}`, `Failed: ${failed}`);
        } else {
          msg = t("Hakuna makosa ya kurekebisha.", "No errors to fix.");
        }
        if (data.remaining > 0) {
          msg += t(` Bado ${data.remaining}.`, ` ${data.remaining} remaining.`);
        }
        setSpeechText(msg);
        await speak(msg);
      }
      return data;
    } catch (err) {
      reportClientError(err.message || "Fix failed", "api");
      return null;
    }
  }

  async function checkErrorsAndSpeak() {
    const data = await refreshStatus();
    if (!data) return;

    const count = data.errorCount ?? 0;
    if (count > 0 && count !== lastSpokenErrorCount) {
      lastSpokenErrorCount = count;
      if (currentMode === "error" || currentMode === "monitor") {
        await autoFixErrors(true);
      } else if (panelOpen) {
        const msg = t(
          `Tahadhari! Makosa ${count} yamegunduliwa.`,
          `Alert! ${count} error(s) detected.`
        );
        setSpeechText(msg);
        await speak(msg);
      }
    }
    if (count === 0) {
      lastSpokenErrorCount = 0;
    }
  }

  function setMode(mode) {
    currentMode = mode;
    try {
      localStorage.setItem(MODE_KEY, mode);
    } catch (_) {}
    root?.querySelectorAll(".gw-robot-mode").forEach((btn) => {
      btn.classList.toggle("is-active", btn.dataset.mode === mode);
    });
  }

  function buildWidget() {
    if (document.getElementById("gw-robot-root")) return;

    root = document.createElement("div");
    root.id = "gw-robot-root";
    root.className = "gw-robot-root";
    root.innerHTML = `
      <div class="gw-robot-panel" role="dialog" aria-label="Kaka AI Robot">
        <div class="gw-robot-head">
          <div class="gw-robot-avatar" aria-hidden="true">
            <i class="fa-solid fa-robot"></i>
          </div>
          <div class="gw-robot-title">
            <strong>Kaka AI</strong>
            <small>${t("Msaidizi wa mfumo", "System assistant")}</small>
          </div>
          <button type="button" class="gw-robot-close" aria-label="${t("Funga", "Close")}">
            <i class="fa-solid fa-xmark"></i>
          </button>
        </div>
        <div class="gw-robot-body">
          <div class="gw-robot-modes">
            ${Object.entries(MODES)
              .map(
                ([key, m]) => `
              <button type="button" class="gw-robot-mode" data-mode="${key}">
                <i class="fa-solid ${m.icon}"></i>
                <span>${t(m.labelSw, m.labelEn)}</span>
              </button>`
              )
              .join("")}
          </div>
          <div class="gw-robot-status">
            <span class="gw-robot-dot"></span>
            <span class="gw-robot-status-text">${t("Inapakia...", "Loading...")}</span>
          </div>
          <div class="gw-robot-speech">${t("Bonyeza roboti kuzungumza.", "Click the robot to speak.")}</div>
          <div class="gw-robot-actions">
            <button type="button" class="gw-robot-speak-btn">
              <i class="fa-solid fa-volume-high"></i>
              ${t("Zungumza", "Speak")}
            </button>
            <button type="button" class="gw-robot-fix-btn" disabled>
              <i class="fa-solid fa-wrench"></i>
              ${t("Rekebisha", "Fix")}
            </button>
          </div>
        </div>
      </div>
      <button type="button" class="gw-robot-fab" aria-label="${t("Fungua Kaka AI", "Open Kaka AI")}">
        <i class="fa-solid fa-robot"></i>
        <span class="gw-robot-badge" hidden>0</span>
      </button>
    `;
    document.body.appendChild(root);

    try {
      const saved = localStorage.getItem(MODE_KEY);
      if (saved && MODES[saved]) currentMode = saved;
    } catch (_) {}

    setMode(currentMode);
    bindEvents();
    refreshStatus();
    startMonitoring();
    setupErrorCapture();

    if (synth) {
      synth.getVoices();
      window.speechSynthesis?.addEventListener?.("voiceschanged", () => synth.getVoices());
    }
  }

  function bindEvents() {
    const fab = root.querySelector(".gw-robot-fab");
    const closeBtn = root.querySelector(".gw-robot-close");
    const speakBtn = root.querySelector(".gw-robot-speak-btn");
    const fixBtn = root.querySelector(".gw-robot-fix-btn");

    fab.addEventListener("click", async () => {
      if (!panelOpen) {
        panelOpen = true;
        root.classList.add("is-open");
        await speakMode(currentMode);
        return;
      }
      if (speaking) {
        stopSpeaking();
      } else {
        await speakMode(currentMode);
      }
    });

    closeBtn.addEventListener("click", () => {
      panelOpen = false;
      root.classList.remove("is-open");
      stopSpeaking();
    });

    speakBtn.addEventListener("click", () => speakMode(currentMode));
    fixBtn.addEventListener("click", () => autoFixErrors(true));

    root.querySelectorAll(".gw-robot-mode").forEach((btn) => {
      btn.addEventListener("click", async () => {
        const mode = btn.dataset.mode;
        setMode(mode);
        await speakMode(mode);
      });
    });
  }

  function startMonitoring() {
    if (monitorTimer) clearInterval(monitorTimer);
    if (errorTimer) clearInterval(errorTimer);

    monitorTimer = setInterval(async () => {
      await refreshStatus();
      if (currentMode === "monitor" && !speaking) {
        await speakMode("monitor");
      }
    }, MONITOR_INTERVAL);

    errorTimer = setInterval(() => {
      checkErrorsAndSpeak().catch(() => {});
    }, ERROR_CHECK_INTERVAL);
  }

  function setupErrorCapture() {
    window.addEventListener("error", (event) => {
      const msg = event.message || "Unknown JS error";
      reportClientError(`${msg} at ${event.filename}:${event.lineno}`, "javascript");
    });

    window.addEventListener("unhandledrejection", (event) => {
      const reason = event.reason?.message || String(event.reason || "Unhandled rejection");
      reportClientError(reason, "promise");
    });

    const origFetch = window.fetch;
    if (typeof origFetch === "function") {
      window.fetch = async function (...args) {
        const res = await origFetch.apply(this, args);
        try {
          const url = typeof args[0] === "string" ? args[0] : args[0]?.url || "";
          if (!res.ok && (url.includes("api.php") || url.includes("/api/"))) {
            reportClientError(`API ${res.status}: ${url}`, "api");
          }
        } catch (_) {}
        return res;
      };
    }
  }

  function init() {
    buildWidget();
  }

  window.GwAiRobot = {
    speak: (mode) => speakMode(mode || currentMode),
    refresh: refreshStatus,
    fix: () => autoFixErrors(true),
    setMode,
  };

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
