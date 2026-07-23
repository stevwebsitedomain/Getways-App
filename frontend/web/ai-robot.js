(function () {
  "use strict";

  const API_URL = "ai-robot-api.php";
  const LANG_KEY = "nectaAppLanguage";
  const MODE_KEY = "gwRobotMode";
  const MONITOR_INTERVAL = 30000;
  const ERROR_CHECK_INTERVAL = 12000;

  let root = null;
  let panelOpen = false;
  let currentMode = "overview";
  let statusData = null;
  let speaking = false;
  let listening = false;
  let monitorTimer = null;
  let errorTimer = null;
  let lastSpokenErrorCount = -1;
  let synth = window.speechSynthesis || null;
  let recognition = null;
  let isAuthorized = false;
  let agentCodename = "Special Agent namba 3";

  const MODES = {
    overview: { icon: "fa-gauge-high", labelSw: "Muhtasari", labelEn: "Overview" },
    login: { icon: "fa-right-to-bracket", labelSw: "Ingia", labelEn: "Logins" },
    monitor: { icon: "fa-eye", labelSw: "Ufuatiliaji", labelEn: "Monitor" },
    error: { icon: "fa-bug", labelSw: "Makosa", labelEn: "Errors" },
    chat: { icon: "fa-comments", labelSw: "Ongea", labelEn: "Chat" },
  };

  function robotFaceHtml() {
    return `
      <div class="gw-robot-face" data-expression="neutral">
        <div class="gw-robot-head-shell">
          <div class="gw-robot-antenna"></div>
          <div class="gw-robot-eyes">
            <div class="gw-robot-eye gw-robot-eye--left"><div class="gw-robot-pupil"></div></div>
            <div class="gw-robot-eye gw-robot-eye--right"><div class="gw-robot-pupil"></div></div>
          </div>
          <div class="gw-robot-mouth"></div>
          <div class="gw-robot-cheek gw-robot-cheek--left"></div>
          <div class="gw-robot-cheek gw-robot-cheek--right"></div>
        </div>
      </div>`;
  }

  function getLang() {
    try {
      return localStorage.getItem(LANG_KEY) === "en" ? "en" : "sw";
    } catch (_) {
      return "sw";
    }
  }

  function t(sw, en) {
    return getLang() === "sw" ? sw : en;
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
      fetchOpts.body = JSON.stringify({ ...options.body, lang: getLang() });
    }
    const res = await fetch(url, fetchOpts);
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.message || `API error ${res.status}`);
    return data;
  }

  function reportClientError(message, source = "client") {
    apiFetch("report-error", {
      method: "POST",
      body: { source, message, page: document.title, url: window.location.href, severity: "error" },
    }).catch(() => {});
  }

  function setExpression(emotion) {
    const faces = root?.querySelectorAll(".gw-robot-face");
    const expr = emotion || "neutral";
    faces?.forEach((f) => f.setAttribute("data-expression", expr));
  }

  function updateSpeakingUI(active) {
    const fab = root?.querySelector(".gw-robot-fab");
    const faces = root?.querySelectorAll(".gw-robot-face");
    fab?.classList.toggle("is-speaking", active);
    faces?.forEach((f) => {
      f.classList.toggle("is-speaking", active);
      if (active) f.setAttribute("data-expression", "speaking");
      else if (!listening) f.setAttribute("data-expression", "neutral");
    });
  }

  function getVoiceLang() {
    return getLang() === "sw" ? "sw-TZ" : "en-US";
  }

  function stopSpeaking() {
    synth?.cancel();
    speaking = false;
    updateSpeakingUI(false);
  }

  function speak(text, emotion) {
    if (!text) return Promise.resolve();
    setSpeechText(text);
    if (emotion) setExpression(emotion);

    if (!synth) return Promise.resolve();

    stopSpeaking();
    speaking = true;
    updateSpeakingUI(true);

    return new Promise((resolve) => {
      const utter = new SpeechSynthesisUtterance(text);
      utter.lang = getVoiceLang();
      utter.rate = 0.92;
      utter.pitch = 1.05;

      const voices = synth.getVoices();
      const pref = voices.find((v) => v.lang.startsWith(getLang() === "sw" ? "sw" : "en"));
      if (pref) utter.voice = pref;

      utter.onend = () => {
        speaking = false;
        updateSpeakingUI(false);
        if (!listening) setExpression(emotion || "neutral");
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
    isAuthorized = data?.agent?.authorized === true;
    if (data?.agent?.codename) agentCodename = data.agent.codename;

    const dot = root?.querySelector(".gw-robot-dot");
    const statusEl = root?.querySelector(".gw-robot-status-text");
    const badge = root?.querySelector(".gw-robot-badge");
    const fixBtn = root?.querySelector(".gw-robot-fix-btn");
    const count = data?.errorCount ?? 0;

    dot?.classList.toggle("has-error", count > 0);
    if (statusEl) {
      statusEl.textContent = isAuthorized
        ? t(`${agentCodename} — mfumo salama`, `${agentCodename} — system OK`)
        : count > 0
          ? t(`Makosa ${count}`, `${count} error(s)`)
          : t("Mfumo salama", "System OK");
      if (count > 0 && isAuthorized) {
        statusEl.textContent = t(
          `${agentCodename} — makosa ${count}!`,
          `${agentCodename} — ${count} error(s)!`
        );
      }
    }
    if (badge) {
      badge.textContent = String(count);
      badge.hidden = count === 0;
    }
    if (fixBtn) fixBtn.disabled = count === 0;
  }

  async function refreshStatus() {
    try {
      const data = await apiFetch("status");
      updateStatusUI(data);
      return data;
    } catch (err) {
      reportClientError(err.message || "Status failed", "api");
      return null;
    }
  }

  async function speakMode(mode) {
    currentMode = mode || currentMode;
    try {
      const data = await apiFetch("speak", { mode: currentMode });
      const emotion = mode === "error" && (statusData?.errorCount ?? 0) > 0 ? "angry" : "happy";
      await speak(data.text || "", emotion);
    } catch (err) {
      await speak(t("Imeshindwa kupata taarifa.", "Failed to get info."), "angry");
    }
  }

  async function sendChat(message) {
    if (!message.trim()) return;
    setSpeechText(t("Ninasikiliza...", "Listening..."));
    try {
      const data = await apiFetch("chat", { method: "POST", body: { message } });
      await speak(data.text || "", data.emotion || "neutral");
      if (!data.authorized) setExpression("angry");
    } catch (err) {
      await speak(t("Imeshindwa kujibu.", "Could not respond."), "angry");
    }
  }

  async function autoFixErrors(speakResult = true) {
    try {
      const data = await apiFetch("fix", { method: "POST", body: {} });
      await refreshStatus();
      if (!speakResult) return data;

      const fixed = (data.fixed || []).join(". ");
      let msg = fixed
        ? t(`${agentCodename}, nimerekebisha: ${fixed}`, `${agentCodename}, fixed: ${fixed}`)
        : t(`${agentCodename}, hakuna makosa.`, `${agentCodename}, no errors.`);
      if (data.remaining > 0) {
        msg += t(` Bado ${data.remaining}.`, ` ${data.remaining} left.`);
      }
      await speak(msg, data.remaining > 0 ? "angry" : "happy");
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
      const msg = t(
        `Tahadhari ${agentCodename}! Makosa ${count} yamegunduliwa kwenye mfumo. Nitairekebisha sasa.`,
        `Alert ${agentCodename}! ${count} error(s) detected. Fixing now.`
      );
      setExpression("angry");
      setSpeechText(msg);
      if (isAuthorized || panelOpen) {
        await speak(msg, "angry");
        await autoFixErrors(true);
      }
    }
    if (count === 0) lastSpokenErrorCount = 0;
  }

  function setMode(mode) {
    currentMode = mode;
    try { localStorage.setItem(MODE_KEY, mode); } catch (_) {}
    root?.querySelectorAll(".gw-robot-mode").forEach((btn) => {
      btn.classList.toggle("is-active", btn.dataset.mode === mode);
    });
  }

  function initSpeechRecognition() {
    const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!SR) return null;

    const rec = new SR();
    rec.continuous = false;
    rec.interimResults = false;
    rec.lang = getLang() === "sw" ? "sw-TZ" : "en-US";
    rec.maxAlternatives = 1;

    rec.onstart = () => {
      listening = true;
      setExpression("listening");
      root?.querySelector(".gw-robot-mic-btn")?.classList.add("is-listening");
      setSpeechText(t("Sema sasa, ninasikiliza...", "Speak now, I am listening..."));
    };

    rec.onend = () => {
      listening = false;
      root?.querySelector(".gw-robot-mic-btn")?.classList.remove("is-listening");
      if (!speaking) setExpression("neutral");
    };

    rec.onerror = () => {
      listening = false;
      setExpression("angry");
      speak(t("Sikukusikia vizuri. Jaribu tena.", "I did not hear you. Try again."), "angry");
    };

    rec.onresult = (event) => {
      const transcript = event.results[0]?.[0]?.transcript || "";
      if (transcript) sendChat(transcript);
    };

    return rec;
  }

  function toggleListening() {
    if (!recognition) {
      recognition = initSpeechRecognition();
    }
    if (!recognition) {
      speak(t("Kivinjari chako hakiungi mkono sauti.", "Your browser does not support voice."), "angry");
      return;
    }
    if (listening) {
      recognition.stop();
      return;
    }
    stopSpeaking();
    try {
      recognition.lang = getLang() === "sw" ? "sw-TZ" : "en-US";
      recognition.start();
    } catch (_) {
      speak(t("Jaribu tena.", "Try again."), "neutral");
    }
  }

  function initEyeTracking() {
    document.addEventListener("mousemove", (e) => {
      const pupils = root?.querySelectorAll(".gw-robot-pupil");
      if (!pupils?.length) return;
      pupils.forEach((pupil) => {
        const eye = pupil.parentElement;
        if (!eye) return;
        const rect = eye.getBoundingClientRect();
        const cx = rect.left + rect.width / 2;
        const cy = rect.top + rect.height / 2;
        const dx = e.clientX - cx;
        const dy = e.clientY - cy;
        const angle = Math.atan2(dy, dx);
        const dist = Math.min(4, Math.hypot(dx, dy) / 30);
        const px = Math.cos(angle) * dist;
        const py = Math.sin(angle) * dist;
        pupil.style.transform = `translate(calc(-50% + ${px}px), calc(-50% + ${py}px))`;
      });
    });
  }

  function buildWidget() {
    if (document.getElementById("gw-robot-root")) return;

    root = document.createElement("div");
    root.id = "gw-robot-root";
    root.className = "gw-robot-root";
    root.innerHTML = `
      <div class="gw-robot-layout">
        <button type="button" class="gw-robot-fab" aria-label="${t("Zungumza na Kaka", "Talk to Kaka")}">
          ${robotFaceHtml()}
          <span class="gw-robot-badge" hidden>0</span>
        </button>
        <div class="gw-robot-panel" role="dialog" aria-label="Kaka AI">
          <div class="gw-robot-head">
            <div class="gw-robot-avatar">${robotFaceHtml()}</div>
            <div class="gw-robot-title">
              <strong>Kaka AI</strong>
              <small>${t("Roboti wa Special Agent namba 3", "Robot for Special Agent #3")}</small>
            </div>
            <button type="button" class="gw-robot-close" aria-label="${t("Funga", "Close")}">
              <i class="fa-solid fa-xmark"></i>
            </button>
          </div>
          <div class="gw-robot-body">
            <div class="gw-robot-modes">
              ${Object.entries(MODES).map(([key, m]) => `
                <button type="button" class="gw-robot-mode" data-mode="${key}">
                  <i class="fa-solid ${m.icon}"></i>
                  <span>${t(m.labelSw, m.labelEn)}</span>
                </button>`).join("")}
            </div>
            <div class="gw-robot-status">
              <span class="gw-robot-dot"></span>
              <span class="gw-robot-status-text">${t("Inapakia...", "Loading...")}</span>
            </div>
            <div class="gw-robot-speech">${t("Bonyeza roboti au maikrofoni kuzungumza.", "Click robot or mic to talk.")}</div>
            <div class="gw-robot-actions">
              <button type="button" class="gw-robot-mic-btn">
                <i class="fa-solid fa-microphone"></i>
                ${t("Sema", "Talk")}
              </button>
              <button type="button" class="gw-robot-speak-btn">
                <i class="fa-solid fa-volume-high"></i>
                ${t("Sikiza", "Listen")}
              </button>
              <button type="button" class="gw-robot-fix-btn" disabled>
                <i class="fa-solid fa-wrench"></i>
                ${t("Rekebisha makosa", "Fix errors")}
              </button>
            </div>
          </div>
        </div>
      </div>`;
    document.body.appendChild(root);

    try {
      const saved = localStorage.getItem(MODE_KEY);
      if (saved && MODES[saved]) currentMode = saved;
    } catch (_) {}

    setMode(currentMode);
    bindEvents();
    initEyeTracking();
    refreshStatus().then((data) => {
      if (data?.agent?.authorized) {
        const greet = t(
          `Karibu ${agentCodename}. Mimi ni Kaka, niko tayari kuzungumza nawe.`,
          `Welcome ${agentCodename}. I am Kaka, ready to talk.`
        );
        setSpeechText(greet);
      }
    });
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
    const micBtn = root.querySelector(".gw-robot-mic-btn");
    const fixBtn = root.querySelector(".gw-robot-fix-btn");

    fab.addEventListener("click", async () => {
      if (!panelOpen) {
        panelOpen = true;
        root.classList.add("is-open");
        setExpression("happy");
        if (currentMode === "chat") {
          toggleListening();
        } else {
          await speakMode(currentMode);
        }
        return;
      }
      if (speaking) stopSpeaking();
      else toggleListening();
    });

    closeBtn.addEventListener("click", () => {
      panelOpen = false;
      root.classList.remove("is-open");
      stopSpeaking();
      if (listening && recognition) recognition.stop();
      setExpression("neutral");
    });

    speakBtn.addEventListener("click", () => speakMode(currentMode));
    micBtn.addEventListener("click", () => toggleListening());
    fixBtn.addEventListener("click", () => autoFixErrors(true));

    root.querySelectorAll(".gw-robot-mode").forEach((btn) => {
      btn.addEventListener("click", async () => {
        const mode = btn.dataset.mode;
        setMode(mode);
        if (mode === "chat") toggleListening();
        else await speakMode(mode);
      });
    });
  }

  function startMonitoring() {
    if (monitorTimer) clearInterval(monitorTimer);
    if (errorTimer) clearInterval(errorTimer);

    monitorTimer = setInterval(async () => {
      await refreshStatus();
      if (currentMode === "monitor" && !speaking && !listening) {
        await speakMode("monitor");
      }
    }, MONITOR_INTERVAL);

    errorTimer = setInterval(() => checkErrorsAndSpeak().catch(() => {}), ERROR_CHECK_INTERVAL);
  }

  function setupErrorCapture() {
    window.addEventListener("error", (event) => {
      reportClientError(`${event.message} at ${event.filename}:${event.lineno}`, "javascript");
    });
    window.addEventListener("unhandledrejection", (event) => {
      reportClientError(event.reason?.message || String(event.reason || "rejection"), "promise");
    });
  }

  window.GwAiRobot = {
    speak: (mode) => speakMode(mode || currentMode),
    chat: (msg) => sendChat(msg),
    listen: () => toggleListening(),
    refresh: refreshStatus,
    fix: () => autoFixErrors(true),
    setMode,
    setExpression,
  };

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", buildWidget);
  } else {
    buildWidget();
  }
})();
