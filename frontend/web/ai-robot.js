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
  let cachedFemaleVoice = null;
  let speakChain = Promise.resolve();
  let speakGen = 0;

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
        <img class="gw-robot-portrait" src="images/agent-robot.png" alt="Agent" />
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

  function isFemaleVoiceName(name) {
    const n = String(name || "").toLowerCase();
    if (/male|david|mark|james|daniel|george|richard|guy|ryan|aaron|fred|tom\b/.test(n)) {
      return false;
    }
    return /female|woman|zira|samantha|aria|jenny|sonia|hazel|susan|linda|karen|heera|natasha|paulina|helen|maria|catherine|lucia|moira|fiona|tessa|ayanda|imani|google.*english.*female|microsoft.*zira/.test(n);
  }

  function pickFemaleVoice() {
    if (!synth) return null;
    const voices = synth.getVoices();
    if (!voices.length) return null;

    if (cachedFemaleVoice && voices.some((v) => v.name === cachedFemaleVoice.name)) {
      return cachedFemaleVoice;
    }

    const langPrefix = getLang() === "sw" ? "sw" : "en";
    const langVoices = voices.filter((v) => (v.lang || "").toLowerCase().startsWith(langPrefix));
    const pools = [langVoices, voices.filter((v) => (v.lang || "").toLowerCase().startsWith("en")), voices];

    for (const pool of pools) {
      const female = pool.find((v) => isFemaleVoiceName(v.name));
      if (female) {
        cachedFemaleVoice = female;
        return female;
      }
    }

    const soft = voices.find((v) => /zira|jenny|aria|sonia|samantha/i.test(v.name || ""));
    cachedFemaleVoice = soft || langVoices[0] || voices[0] || null;
    return cachedFemaleVoice;
  }

  function warmupVoice() {
    if (!synth) return;
    pickFemaleVoice();
    synth.getVoices();
  }

  function stopSpeaking() {
    speakGen += 1;
    if (synth) {
      synth.cancel();
      if (typeof synth.resume === "function") synth.resume();
    }
    speaking = false;
    updateSpeakingUI(false);
  }

  function speakOnce(text, emotion) {
    if (!text || !synth) return Promise.resolve();

    const myGen = ++speakGen;
    synth.cancel();
    if (typeof synth.resume === "function") synth.resume();

    speaking = true;
    updateSpeakingUI(true);
    setSpeechText(text);
    if (emotion) setExpression(emotion);

    return new Promise((resolve) => {
      window.setTimeout(() => {
        if (myGen !== speakGen) {
          resolve();
          return;
        }

        const utter = new SpeechSynthesisUtterance(text);
        const voice = pickFemaleVoice();
        utter.voice = voice;
        utter.lang = voice?.lang || getVoiceLang();
        utter.rate = 0.9;
        utter.pitch = 1.15;
        utter.volume = 1;

        const finish = () => {
          if (myGen !== speakGen) {
            resolve();
            return;
          }
          speaking = false;
          updateSpeakingUI(false);
          if (!listening) setExpression(emotion || "neutral");
          resolve();
        };

        utter.onend = finish;
        utter.onerror = finish;
        synth.speak(utter);
      }, 80);
    });
  }

  function speak(text, emotion) {
    if (!text) return Promise.resolve();
    speakChain = speakChain
      .then(() => speakOnce(text, emotion))
      .catch(() => {});
    return speakChain;
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

      if (data?.agent && !data.agent.authorized) {
        try {
          await apiFetch("bind-agent", { method: "POST", body: {} });
          return refreshStatus();
        } catch (_) {}
      }

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
      if (data.authorized) isAuthorized = true;
      await speak(data.text || "", data.emotion || "neutral");
      if (!data.authorized) setExpression("angry");
      else setExpression(data.emotion || "happy");
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
      const remaining = data.remaining ?? 0;
      let msg = t(
        `Tahadhari ${agentCodename}! Makosa yamegunduliwa.`,
        `Alert ${agentCodename}! Errors detected.`
      );
      if (fixed) {
        msg += t(` Nimerekebisha: ${fixed}.`, ` I fixed: ${fixed}.`);
      } else {
        msg += t(" Nimeangalia mfumo.", " I checked the system.");
      }
      if (remaining > 0) {
        msg += t(` Bado ${remaining}.`, ` ${remaining} remaining.`);
      } else {
        msg += t(" Sasa kila kitu kiko sawa.", " Everything is OK now.");
      }
      await speak(msg, remaining > 0 ? "angry" : "happy");
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
      await autoFixErrors(false);
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

    rec.onerror = (event) => {
      listening = false;
      root?.querySelector(".gw-robot-mic-btn")?.classList.remove("is-listening");
      setExpression("neutral");
      const code = event?.error || "";
      if (code === "not-allowed" || code === "service-not-allowed") {
        setSpeechText(t("Ruhusu microphone kwenye browser yako.", "Allow microphone in your browser."));
      } else if (code !== "aborted" && code !== "no-speech") {
        setSpeechText(t("Sikukusikia. Bonyeza Talk ujaribu tena.", "I did not hear you. Press Talk to try again."));
      }
    };

    rec.onresult = (event) => {
      const transcript = event.results[0]?.[0]?.transcript || "";
      if (transcript) sendChat(transcript);
    };

    return rec;
  }

  function openPanel() {
    if (!panelOpen) {
      panelOpen = true;
      root?.classList.add("is-open");
    }
  }

  function startTalk() {
    openPanel();
    setMode("chat");
    setExpression("happy");
    stopSpeaking();

    if (!recognition) {
      recognition = initSpeechRecognition();
    }
    if (!recognition) {
      setSpeechText(t("Kivinjari chako hakiungi mkono sauti.", "Your browser does not support voice."));
      return;
    }
    if (listening) {
      return;
    }

    try {
      recognition.lang = getLang() === "sw" ? "sw-TZ" : "en-US";
      recognition.start();
    } catch (_) {
      setSpeechText(t("Bonyeza Talk tena kuanza kuzungumza.", "Press Talk again to start speaking."));
    }
  }

  function initEyeTracking() {
    document.addEventListener("mousemove", (e) => {
      const faces = root?.querySelectorAll(".gw-robot-face");
      if (!faces?.length) return;
      faces.forEach((face) => {
        const rect = face.getBoundingClientRect();
        const cx = rect.left + rect.width / 2;
        const cy = rect.top + rect.height / 2;
        const dx = (e.clientX - cx) / 40;
        const dy = (e.clientY - cy) / 40;
        const clampX = Math.max(-6, Math.min(6, dx));
        const clampY = Math.max(-4, Math.min(4, dy));
        face.style.transform = `translate(${clampX}px, ${clampY}px)`;
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
        <button type="button" class="gw-robot-fab" aria-label="${t("Zungumza na Agent", "Talk to Agent")}">
          ${robotFaceHtml()}
          <span class="gw-robot-badge" hidden>0</span>
        </button>
        <div class="gw-robot-panel" role="dialog" aria-label="Agent">
          <div class="gw-robot-head">
            <div class="gw-robot-avatar">${robotFaceHtml()}</div>
            <div class="gw-robot-title">
              <strong>Agent</strong>
              <small>${t("Msaidizi wa Special Agent namba 3", "Assistant for Special Agent #3")}</small>
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
            <div class="gw-robot-speech">${t("Bonyeza Talk kuzungumza na Agent.", "Press Talk to speak with Agent.")}</div>
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
          `Karibu ${agentCodename}. Mimi ni Agent, niko tayari kuzungumza nawe.`,
          `Welcome ${agentCodename}. I am Agent, ready to talk.`
        );
        setSpeechText(greet);
      }
    });
    startMonitoring();
    setupErrorCapture();

    if (synth) {
      warmupVoice();
      window.speechSynthesis?.addEventListener?.("voiceschanged", warmupVoice);
    }
  }

  function bindEvents() {
    const fab = root.querySelector(".gw-robot-fab");
    const closeBtn = root.querySelector(".gw-robot-close");
    const speakBtn = root.querySelector(".gw-robot-speak-btn");
    const micBtn = root.querySelector(".gw-robot-mic-btn");
    const fixBtn = root.querySelector(".gw-robot-fix-btn");

    fab.addEventListener("click", () => {
      if (!panelOpen) {
        startTalk();
        return;
      }
      if (speaking) {
        stopSpeaking();
        return;
      }
      startTalk();
    });

    closeBtn.addEventListener("click", () => {
      panelOpen = false;
      root.classList.remove("is-open");
      stopSpeaking();
      if (listening && recognition) recognition.stop();
      setExpression("neutral");
    });

    speakBtn.addEventListener("click", () => {
      openPanel();
      speakMode(currentMode);
    });
    micBtn.addEventListener("click", () => startTalk());
    fixBtn.addEventListener("click", () => {
      openPanel();
      autoFixErrors(true);
    });

    root.querySelectorAll(".gw-robot-mode").forEach((btn) => {
      btn.addEventListener("click", async () => {
        const mode = btn.dataset.mode;
        setMode(mode);
        openPanel();
        if (mode === "chat") {
          startTalk();
        } else {
          await speakMode(mode);
        }
      });
    });
  }

  function startMonitoring() {
    if (monitorTimer) clearInterval(monitorTimer);
    if (errorTimer) clearInterval(errorTimer);

    monitorTimer = setInterval(async () => {
      await refreshStatus();
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
    listen: () => startTalk(),
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
