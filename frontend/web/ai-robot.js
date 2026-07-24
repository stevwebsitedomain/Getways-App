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
  let lipTimer = null;

  const MODES = {
    overview: { icon: "fa-gauge-high", labelSw: "Muhtasari", labelEn: "Overview" },
    login: { icon: "fa-right-to-bracket", labelSw: "Ingia", labelEn: "Logins" },
    monitor: { icon: "fa-eye", labelSw: "Ufuatiliaji", labelEn: "Monitor" },
    error: { icon: "fa-bug", labelSw: "Makosa", labelEn: "Errors" },
    chat: { icon: "fa-comments", labelSw: "Ongea", labelEn: "Chat" },
  };

  function robotFaceHtml(uid) {
    const id = String(uid || "r").replace(/[^a-z0-9]/gi, "");
    return `
      <div class="gw-robot-face" data-expression="neutral">
        <svg class="gw-robot-svg" viewBox="0 0 200 240" aria-hidden="true">
          <defs>
            <linearGradient id="gwMetal-${id}" x1="0%" y1="0%" x2="100%" y2="100%">
              <stop offset="0%" stop-color="#f4f7fb"/>
              <stop offset="35%" stop-color="#cdd6e0"/>
              <stop offset="70%" stop-color="#a3b0be"/>
              <stop offset="100%" stop-color="#8896a6"/>
            </linearGradient>
            <radialGradient id="gwEye-${id}" cx="50%" cy="50%" r="50%">
              <stop offset="0%" stop-color="#d4fbff"/>
              <stop offset="35%" stop-color="#00d4ff"/>
              <stop offset="75%" stop-color="#0096c7"/>
              <stop offset="100%" stop-color="#023e58"/>
            </radialGradient>
            <filter id="gwGlow-${id}" x="-60%" y="-60%" width="220%" height="220%">
              <feGaussianBlur stdDeviation="2.8" result="blur"/>
              <feMerge><feMergeNode in="blur"/><feMergeNode in="SourceGraphic"/></feMerge>
            </filter>
          </defs>
          <path class="gw-robot-neck" d="M78 188 Q100 202 122 188 L118 228 Q100 236 82 228 Z" fill="#8d99a8" stroke="#6f7d8c" stroke-width="0.8"/>
          <ellipse class="gw-robot-skull" cx="100" cy="110" rx="70" ry="84" fill="url(#gwMetal-${id})" stroke="#a8b4c2" stroke-width="1.2"/>
          <path d="M52 78 Q100 70 148 78" fill="none" stroke="#8a98a8" stroke-width="0.9" opacity="0.65"/>
          <path d="M48 94 Q100 88 152 94" fill="none" stroke="#8a98a8" stroke-width="0.7" opacity="0.45"/>
          <path d="M54 168 Q100 176 146 168" fill="none" stroke="#8a98a8" stroke-width="0.6" opacity="0.4"/>
          <ellipse cx="36" cy="108" rx="9" ry="16" fill="#707d8c" stroke="#38bdf8" stroke-width="1.1"/>
          <ellipse cx="164" cy="108" rx="9" ry="16" fill="#707d8c" stroke="#fb923c" stroke-width="1.1"/>
          <circle cx="36" cy="102" r="2.8" fill="#22d3ee" class="gw-robot-side-light"/>
          <circle cx="164" cy="114" r="2.8" fill="#fb923c" class="gw-robot-side-light"/>
          <path class="gw-robot-nose" d="M100 118 L95 136 Q100 140 105 136 Z" fill="#b8c2cc" stroke="#8f9baa" stroke-width="0.6"/>
          <g class="gw-robot-eye gw-robot-eye--left">
            <ellipse cx="72" cy="106" rx="19" ry="21" fill="#1e2a38"/>
            <circle class="gw-robot-iris" cx="72" cy="106" r="14" fill="url(#gwEye-${id})" filter="url(#gwGlow-${id})"/>
            <circle cx="68" cy="102" r="3.5" fill="#fff" opacity="0.6"/>
          </g>
          <g class="gw-robot-eye gw-robot-eye--right">
            <ellipse cx="128" cy="106" rx="19" ry="21" fill="#1e2a38"/>
            <circle class="gw-robot-iris" cx="128" cy="106" r="14" fill="url(#gwEye-${id})" filter="url(#gwGlow-${id})"/>
            <circle cx="124" cy="102" r="3.5" fill="#fff" opacity="0.6"/>
          </g>
          <g class="gw-robot-mouth-wrap">
            <path class="gw-robot-lip-top" d="M84 152 Q100 147 116 152" fill="none" stroke="#7d8b9a" stroke-width="2.4" stroke-linecap="round"/>
            <path class="gw-robot-lip-bottom" d="M84 152 Q100 160 116 152" fill="none" stroke="#6b7888" stroke-width="2" stroke-linecap="round"/>
            <ellipse class="gw-robot-mouth-hole" cx="100" cy="155" rx="13" ry="1.5" fill="#2a3848"/>
            <ellipse class="gw-robot-tongue" cx="100" cy="157" rx="8" ry="2" fill="#c45c6a" opacity="0"/>
          </g>
        </svg>
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

  function startLipSync() {
    stopLipSync();
    lipTimer = window.setInterval(() => {
      root?.querySelectorAll(".gw-robot-face").forEach((f) => {
        f.classList.toggle("gw-mouth-open");
      });
    }, 110);
  }

  function stopLipSync() {
    if (lipTimer) {
      window.clearInterval(lipTimer);
      lipTimer = null;
    }
    root?.querySelectorAll(".gw-robot-face").forEach((f) => {
      f.classList.remove("gw-mouth-open");
    });
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
    if (active) startLipSync();
    else stopLipSync();
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
    stopLipSync();
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
      const irises = root?.querySelectorAll(".gw-robot-iris");
      if (!irises?.length || speaking) return;
      irises.forEach((iris) => {
        const svg = iris.ownerSVGElement;
        if (!svg) return;
        const rect = svg.getBoundingClientRect();
        const cx = rect.left + rect.width / 2;
        const cy = rect.top + rect.height * 0.44;
        const dx = (e.clientX - cx) / 28;
        const dy = (e.clientY - cy) / 28;
        const px = Math.max(-5, Math.min(5, dx));
        const py = Math.max(-4, Math.min(4, dy));
        iris.style.transform = `translate(${px}px, ${py}px)`;
      });
    });
  }

  function buildWidget() {
    const buildV = String(window.GW_ROBOT_ASSET_V || "2");
    const existing = document.getElementById("gw-robot-root");
    if (existing) {
      if (existing.dataset.build === buildV) return;
      existing.remove();
    }

    root = document.createElement("div");
    root.id = "gw-robot-root";
    root.className = "gw-robot-root";
    root.dataset.build = buildV;
    root.innerHTML = `
      <div class="gw-robot-layout">
        <button type="button" class="gw-robot-fab" aria-label="${t("Zungumza na Agent", "Talk to Agent")}">
          ${robotFaceHtml("fab")}
          <span class="gw-robot-badge" hidden>0</span>
        </button>
        <div class="gw-robot-panel" role="dialog" aria-label="Agent">
          <div class="gw-robot-head">
            <div class="gw-robot-avatar">${robotFaceHtml("panel")}</div>
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
