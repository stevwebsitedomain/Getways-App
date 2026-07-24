(function () {
  "use strict";

  const API_URL = "ai-robot-api.php";
  const LANG_KEY = "nectaAppLanguage";
  const MODE_KEY = "gwRobotMode";
  const POS_KEY = "gwRobotPosition";
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
  let lastErrorFingerprint = "";
  let pendingErrorAnnounce = false;
  let cameraStream = null;
  let cameraActive = false;
  let faceWatchRaf = null;
  let faceDetector = null;
  let aiEnabled = false;
  let dragState = null;
  let suppressFabClick = false;
  let synth = window.speechSynthesis || null;
  let recognition = null;
  let isAuthorized = false;
  let agentCodename = "Special Agent namba 3";
  let cachedFemaleVoice = null;
  let speakChain = Promise.resolve();
  let speakGen = 0;
  let lipTimeouts = [];

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
      <div class="gw-robot-face" data-expression="neutral" data-mouth="rest">
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
    })
      .then(() => {
        setTimeout(() => {
          refreshStatus().then((data) => {
            if (data) announceErrorsIfNew(data).catch(() => {});
          });
        }, 600);
      })
      .catch(() => {});
  }

  function errorFingerprint(data) {
    const errors = data?.openErrors || [];
    return errors.map((e) => String(e.id || e.message || "")).sort().join("|");
  }

  async function announceErrorsIfNew(data) {
    if (!data) return;

    const count = data.errorCount ?? 0;
    if (count === 0) {
      lastErrorFingerprint = "";
      lastSpokenErrorCount = 0;
      return;
    }

    const fp = errorFingerprint(data);
    if (fp === lastErrorFingerprint) return;

    if (speaking || listening) {
      pendingErrorAnnounce = true;
      return;
    }

    lastErrorFingerprint = fp;
    lastSpokenErrorCount = count;

    const errors = data.openErrors || [];
    const detail = errors
      .slice(0, 2)
      .map((e) => String(e.message || "").slice(0, 100))
      .filter(Boolean)
      .join(". ");

    let msg = t(
      `Tahadhari ${agentCodename}! Kuna makosa ${count} kwenye mfumo.`,
      `Alert ${agentCodename}! There are ${count} error(s) in the system.`
    );
    if (detail) msg += ` ${detail}`;

    await speak(msg, "angry");

    const fixData = await apiFetch("fix", { method: "POST", body: {} });
    await refreshStatus();

    const fixed = (fixData.fixed || []).join(". ");
    const remaining = fixData.remaining ?? 0;
    if (fixed || remaining !== count) {
      let fixMsg = "";
      if (fixed) fixMsg += t(`Nimerekebisha: ${fixed}.`, `I fixed: ${fixed}.`);
      if (remaining > 0) fixMsg += t(` Bado makosa ${remaining}.`, ` ${remaining} error(s) remain.`);
      else fixMsg += t(" Sasa mfumo uko sawa.", " System is OK now.");
      if (fixMsg.trim()) await speak(fixMsg, remaining > 0 ? "angry" : "happy");
    }
  }

  function setExpression(emotion) {
    const faces = root?.querySelectorAll(".gw-robot-face");
    const expr = emotion || "neutral";
    faces?.forEach((f) => f.setAttribute("data-expression", expr));
  }

  function setMouthViseme(viseme) {
    const shape = viseme || "rest";
    root?.querySelectorAll(".gw-robot-face").forEach((f) => {
      f.setAttribute("data-mouth", shape);
    });
  }

  function charToViseme(ch) {
    const c = String(ch || "").toLowerCase();
    if (!c || c === " ") return "rest";
    if (/[.,!?;:\-]/.test(c)) return "rest";
    if (/[mbp]/.test(c)) return "mb";
    if (/[fv]/.test(c)) return "ff";
    if (/[oôóòö]/.test(c)) return "oh";
    if (/[uûúùw]/.test(c)) return "uu";
    if (/[eéèêë]/.test(c)) return "ee";
    if (/[iîíìy]/.test(c)) return "ee";
    if (/[aáàâä]/.test(c)) return "aa";
    if (/[h]/.test(c)) return "aa";
    if (/[lr]/.test(c)) return "small";
    if (/[tdszcjnxkg]/.test(c)) return "th";
    return "small";
  }

  function wordToVisemeSequence(word) {
    const chars = Array.from(String(word || "").toLowerCase());
    if (!chars.length) return ["rest"];

    const seq = [];
    chars.forEach((ch) => {
      if (!/[a-zàáâãäåæèéêëìíîïòóôõöùúûüýÿñç]/i.test(ch)) return;
      const viseme = charToViseme(ch);
      if (viseme === "rest") return;
      if (seq[seq.length - 1] !== viseme) seq.push(viseme);
    });

    return seq.length ? seq : ["small"];
  }

  function applyWordViseme(word, rate) {
    lipTimeouts.forEach((id) => window.clearTimeout(id));
    lipTimeouts = [];

    const seq = wordToVisemeSequence(word);
    const wordMs = Math.max(110, Math.min(380, (word.length || 1) * 72 / (rate || 0.9)));
    const step = wordMs / seq.length;
    let elapsed = 0;

    seq.forEach((viseme) => {
      const id = window.setTimeout(() => {
        if (speaking) setMouthViseme(viseme);
      }, elapsed);
      lipTimeouts.push(id);
      elapsed += step;
    });
  }

  function scheduleLipSync(text, rate) {
    const words = String(text || "")
      .split(/\s+/)
      .map((w) => w.trim())
      .filter(Boolean);
    if (!words.length) return;

    lipTimeouts.forEach((id) => window.clearTimeout(id));
    lipTimeouts = [];

    let elapsed = 0;
    words.forEach((word) => {
      const seq = wordToVisemeSequence(word);
      const wordMs = Math.max(110, Math.min(380, word.length * 72 / (rate || 0.9)));
      const step = wordMs / seq.length;

      seq.forEach((viseme) => {
        const delay = elapsed;
        const id = window.setTimeout(() => {
          if (speaking) setMouthViseme(viseme);
        }, delay);
        lipTimeouts.push(id);
        elapsed += step;
      });
      elapsed += 35;
    });

    lipTimeouts.push(window.setTimeout(() => {
      if (speaking) setMouthViseme("rest");
    }, elapsed + 80));
  }

  function stopLipSync() {
    lipTimeouts.forEach((id) => window.clearTimeout(id));
    lipTimeouts = [];
    setMouthViseme("rest");
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
    if (!active) stopLipSync();
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

    return new Promise((resolve) => {
      window.setTimeout(() => {
        if (myGen !== speakGen) {
          resolve();
          return;
        }

        const utter = new SpeechSynthesisUtterance(text);
        const voice = pickFemaleVoice();
        const rate = 0.9;
        utter.voice = voice;
        utter.lang = voice?.lang || getVoiceLang();
        utter.rate = rate;
        utter.pitch = 1.15;
        utter.volume = 1;

        const finish = () => {
          if (myGen !== speakGen) {
            resolve();
            return;
          }
          stopLipSync();
          speaking = false;
          updateSpeakingUI(false);
          if (!listening) setExpression(emotion || "neutral");
          resolve();
          if (pendingErrorAnnounce) {
            pendingErrorAnnounce = false;
            announceErrorsIfNew(statusData).catch(() => {});
          }
        };

        utter.onstart = () => {
          setMouthViseme("rest");
        };

        utter.onboundary = (event) => {
          if (!speaking || event.name !== "word") return;
          const idx = event.charIndex ?? 0;
          const len = event.charLength || 1;
          const word = text.slice(idx, idx + len).trim();
          if (word) applyWordViseme(word, rate);
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

    const aiBadge = root?.querySelector(".gw-robot-ai-badge");
    if (aiBadge) {
      aiBadge.hidden = !aiEnabled;
      aiBadge.textContent = t("AI", "AI");
    }
  }

  function clampPosition(x, y) {
    const w = root?.offsetWidth || 120;
    const h = root?.offsetHeight || 120;
    const pad = 8;
    return {
      x: Math.max(pad, Math.min(window.innerWidth - w - pad, x)),
      y: Math.max(pad, Math.min(window.innerHeight - h - pad, y)),
    };
  }

  function applyPosition(pos, persist = false) {
    if (!root) return;

    if (!pos) {
      root.classList.remove("is-custom-pos");
      root.style.left = "";
      root.style.top = "";
      root.style.right = "";
      root.style.bottom = "";
      root.style.transform = "";
      if (persist) {
        try {
          localStorage.removeItem(POS_KEY);
        } catch (_) {}
      }
      return;
    }

    const clamped = clampPosition(pos.x, pos.y);
    root.classList.add("is-custom-pos");
    root.style.right = "auto";
    root.style.bottom = "auto";
    root.style.left = `${clamped.x}px`;
    root.style.top = `${clamped.y}px`;
    root.style.transform = "none";

    if (persist) {
      try {
        localStorage.setItem(POS_KEY, JSON.stringify(clamped));
      } catch (_) {}
    }
  }

  function loadSavedPosition() {
    try {
      const raw = localStorage.getItem(POS_KEY);
      if (!raw) return;
      const pos = JSON.parse(raw);
      if (typeof pos?.x === "number" && typeof pos?.y === "number") {
        applyPosition(pos);
      }
    } catch (_) {}
  }

  function snapPosition(preset) {
    if (!root) return;
    const w = root.offsetWidth || 120;
    const h = root.offsetHeight || 120;
    const pad = 12;
    let x = 0;
    let y = 0;

    switch (preset) {
      case "left":
        x = pad;
        y = (window.innerHeight - h) / 2;
        break;
      case "right":
        x = window.innerWidth - w - pad;
        y = (window.innerHeight - h) / 2;
        break;
      case "top":
        x = (window.innerWidth - w) / 2;
        y = pad;
        break;
      case "bottom":
        x = (window.innerWidth - w) / 2;
        y = window.innerHeight - h - pad;
        break;
      case "center":
      default:
        x = (window.innerWidth - w) / 2;
        y = (window.innerHeight - h) / 2;
        break;
    }

    applyPosition({ x, y }, true);
  }

  function resetPosition() {
    applyPosition(null, true);
  }

  function beginDrag(clientX, clientY) {
    if (!root) return;
    const rect = root.getBoundingClientRect();
    dragState = {
      startX: clientX,
      startY: clientY,
      originX: rect.left,
      originY: rect.top,
      moved: false,
    };
    root.classList.add("is-dragging");
  }

  function moveDrag(clientX, clientY) {
    if (!dragState || !root) return;
    const dx = clientX - dragState.startX;
    const dy = clientY - dragState.startY;
    if (Math.abs(dx) > 4 || Math.abs(dy) > 4) dragState.moved = true;
    applyPosition({ x: dragState.originX + dx, y: dragState.originY + dy });
  }

  function endDrag() {
    if (!dragState || !root) return;
    const didMove = dragState.moved;
    dragState = null;
    root.classList.remove("is-dragging");
    if (didMove) {
      const rect = root.getBoundingClientRect();
      applyPosition({ x: rect.left, y: rect.top }, true);
      suppressFabClick = true;
      setTimeout(() => {
        suppressFabClick = false;
      }, 120);
    }
  }

  function initDrag() {
    const handles = () => [
      root?.querySelector(".gw-robot-fab"),
      root?.querySelector(".gw-robot-head"),
    ].filter(Boolean);

    const onPointerDown = (e) => {
      if (e.button !== undefined && e.button !== 0) return;
      if (e.target.closest(".gw-robot-close, .gw-robot-mode, .gw-robot-mic-btn, .gw-robot-speak-btn, .gw-robot-fix-btn, .gw-robot-camera-btn, .gw-robot-pos-btn")) {
        return;
      }
      beginDrag(e.clientX, e.clientY);
      e.preventDefault();
    };

    const onPointerMove = (e) => {
      if (!dragState) return;
      moveDrag(e.clientX, e.clientY);
    };

    const onPointerUp = () => endDrag();

    handles().forEach((el) => {
      el.addEventListener("mousedown", onPointerDown);
      el.addEventListener("touchstart", (e) => {
        const touch = e.touches[0];
        if (!touch) return;
        onPointerDown({ ...e, clientX: touch.clientX, clientY: touch.clientY, button: 0, target: e.target, preventDefault: () => e.preventDefault() });
      }, { passive: false });
    });

    document.addEventListener("mousemove", onPointerMove);
    document.addEventListener("mouseup", onPointerUp);
    document.addEventListener("touchmove", (e) => {
      if (!dragState) return;
      const touch = e.touches[0];
      if (!touch) return;
      moveDrag(touch.clientX, touch.clientY);
      e.preventDefault();
    }, { passive: false });
    document.addEventListener("touchend", onPointerUp);

    root?.querySelector(".gw-robot-fab")?.addEventListener("dblclick", (e) => {
      e.preventDefault();
      e.stopPropagation();
      resetPosition();
      setSpeechText(t("Agent imewekwa katikati.", "Agent moved to center."));
    });

    root?.querySelectorAll(".gw-robot-pos-btn").forEach((btn) => {
      btn.addEventListener("click", () => {
        snapPosition(btn.dataset.pos || "center");
      });
    });

    window.addEventListener("resize", () => {
      if (!root?.classList.contains("is-custom-pos")) return;
      try {
        const raw = localStorage.getItem(POS_KEY);
        if (!raw) return;
        const pos = JSON.parse(raw);
        if (typeof pos?.x === "number" && typeof pos?.y === "number") {
          applyPosition(pos, true);
        }
      } catch (_) {}
    });
  }

  async function refreshStatus() {
    try {
      const data = await apiFetch("status");
      updateStatusUI(data);
      aiEnabled = data?.aiEnabled === true;

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

    const lower = message.trim().toLowerCase();
    if (/unaona|nione|camera|kuona|see me|can you see/i.test(lower)) {
      if (cameraActive) {
        await speak(
          t(`Ndiyo ${agentCodename}, nakuona vizuri!`, `Yes ${agentCodename}, I can see you clearly!`),
          "happy"
        );
      } else {
        openPanel();
        setSpeechText(
          t(
            "Bonyeza Unganisha Camera hapo chini ili nikuone.",
            "Press Connect Camera below so I can see you."
          )
        );
        await speak(
          t(
            "Bonyeza kitufe cha Unganisha Camera ili nikuone.",
            "Press the Connect Camera button so I can see you."
          ),
          "neutral"
        );
      }
      return;
    }

    setSpeechText(t("Ninafikiri...", "Thinking..."));
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
    await announceErrorsIfNew(data);
    if (pendingErrorAnnounce && !speaking && !listening) {
      pendingErrorAnnounce = false;
      await announceErrorsIfNew(statusData);
    }
  }

  function updateCameraUI() {
    const preview = root?.querySelector(".gw-robot-camera-preview");
    const btn = root?.querySelector(".gw-robot-camera-btn");
    const btnText = root?.querySelector(".gw-robot-camera-btn-text");
    const hint = root?.querySelector(".gw-robot-camera-hint");

    preview?.toggleAttribute("hidden", !cameraActive);
    root?.classList.toggle("gw-robot-camera-on", cameraActive);

    if (btnText) {
      btnText.textContent = cameraActive
        ? t("Zima Camera", "Turn Off Camera")
        : t("Unganisha Camera", "Connect Camera");
    }
    if (btn) {
      btn.classList.toggle("is-active", cameraActive);
      btn.querySelector("i")?.classList.toggle("fa-video-slash", cameraActive);
      btn.querySelector("i")?.classList.toggle("fa-video", !cameraActive);
    }
    if (hint) {
      hint.textContent = cameraActive
        ? t("Agent anakuona sasa.", "Agent can see you now.")
        : t(
            "Bonyeza ili Agent akuone. Ruhusu camera kwenye browser yako.",
            "Press to let Agent see you. Allow camera access in your browser."
          );
    }
  }

  function moveEyesToVideoPoint(nx, ny, video) {
    const irises = root?.querySelectorAll(".gw-robot-iris");
    if (!irises?.length || !video?.videoWidth) return;
    const px = ((nx / video.videoWidth) - 0.5) * 10;
    const py = ((ny / video.videoHeight) - 0.5) * 8;
    const tx = Math.max(-5, Math.min(5, px));
    const ty = Math.max(-4, Math.min(4, py));
    irises.forEach((iris) => {
      iris.style.transform = `translate(${tx}px, ${ty}px)`;
    });
  }

  function startFaceWatch(video) {
    if (faceWatchRaf) cancelAnimationFrame(faceWatchRaf);

    if ("FaceDetector" in window) {
      try {
        faceDetector = new FaceDetector({ fastMode: true, maxDetectedFaces: 1 });
      } catch (_) {
        faceDetector = null;
      }
    }

    let lastDetect = 0;
    const tick = (now) => {
      if (!cameraActive || !video) return;

      if (faceDetector && video.readyState >= 2 && now - lastDetect > 180) {
        lastDetect = now;
        faceDetector
          .detect(video)
          .then((faces) => {
            if (faces?.length > 0) {
              const box = faces[0].boundingBox;
              moveEyesToVideoPoint(box.x + box.width / 2, box.y + box.height / 2, video);
            } else {
              moveEyesToVideoPoint(video.videoWidth / 2, video.videoHeight * 0.42, video);
            }
          })
          .catch(() => {});
      } else if (!faceDetector && video.videoWidth) {
        moveEyesToVideoPoint(video.videoWidth / 2, video.videoHeight * 0.42, video);
      }

      faceWatchRaf = requestAnimationFrame(tick);
    };
    faceWatchRaf = requestAnimationFrame(tick);
  }

  function stopFaceWatch() {
    if (faceWatchRaf) {
      cancelAnimationFrame(faceWatchRaf);
      faceWatchRaf = null;
    }
    faceDetector = null;
    root?.querySelectorAll(".gw-robot-iris").forEach((iris) => {
      iris.style.transform = "";
    });
  }

  async function startCamera() {
    if (!navigator.mediaDevices?.getUserMedia) {
      setSpeechText(
        t(
          "Kivinjari chako hakiungi mkono camera. Tumia Chrome au Edge.",
          "Your browser does not support camera. Use Chrome or Edge."
        )
      );
      await speak(
        t("Samahani, camera haiwezi kufunguliwa hapa.", "Sorry, camera cannot be opened here."),
        "angry"
      );
      return;
    }

    try {
      const stream = await navigator.mediaDevices.getUserMedia({
        video: { facingMode: "user", width: { ideal: 320 }, height: { ideal: 240 } },
        audio: false,
      });
      cameraStream = stream;
      cameraActive = true;

      const video = root?.querySelector(".gw-robot-camera-video");
      if (video) {
        video.srcObject = stream;
        await video.play().catch(() => {});
        startFaceWatch(video);
      }

      updateCameraUI();
      setExpression("happy");
      const msg = t(
        `Sawa ${agentCodename}, nakuona sasa!`,
        `OK ${agentCodename}, I can see you now!`
      );
      setSpeechText(msg);
      await speak(msg, "happy");
    } catch (err) {
      cameraStream = null;
      cameraActive = false;
      updateCameraUI();
      const denied = err?.name === "NotAllowedError" || err?.name === "PermissionDeniedError";
      const msg = denied
        ? t(
            "Umeruhusu camera? Bonyeza ruhusu kwenye browser kisha jaribu tena.",
            "Did you allow camera? Click Allow in the browser and try again."
          )
        : t("Imeshindwa kufungua camera. Jaribu tena.", "Could not open camera. Try again.");
      setSpeechText(msg);
      await speak(msg, "angry");
    }
  }

  function stopCamera() {
    if (cameraStream) {
      cameraStream.getTracks().forEach((track) => track.stop());
      cameraStream = null;
    }
    cameraActive = false;
    const video = root?.querySelector(".gw-robot-camera-video");
    if (video) video.srcObject = null;
    stopFaceWatch();
    updateCameraUI();
  }

  async function toggleCamera() {
    if (cameraActive) {
      stopCamera();
      setSpeechText(t("Camera imezimwa.", "Camera turned off."));
      return;
    }
    openPanel();
    await startCamera();
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
      if (cameraActive) return;
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
              <strong>Agent <span class="gw-robot-ai-badge" hidden>AI</span></strong>
              <small>${t("Buruta uso kuhama · Bonyeza mara mbili katikati", "Drag face to move · Double-click to center")}</small>
            </div>
            <button type="button" class="gw-robot-close" aria-label="${t("Funga", "Close")}">
              <i class="fa-solid fa-xmark"></i>
            </button>
          </div>
          <div class="gw-robot-position">
            <span class="gw-robot-position-label">${t("Mahali", "Position")}</span>
            <button type="button" class="gw-robot-pos-btn" data-pos="left" title="${t("Kushoto", "Left")}"><i class="fa-solid fa-arrow-left"></i></button>
            <button type="button" class="gw-robot-pos-btn" data-pos="top" title="${t("Juu", "Top")}"><i class="fa-solid fa-arrow-up"></i></button>
            <button type="button" class="gw-robot-pos-btn" data-pos="center" title="${t("Katikati", "Center")}"><i class="fa-solid fa-crosshairs"></i></button>
            <button type="button" class="gw-robot-pos-btn" data-pos="bottom" title="${t("Chini", "Bottom")}"><i class="fa-solid fa-arrow-down"></i></button>
            <button type="button" class="gw-robot-pos-btn" data-pos="right" title="${t("Kulia", "Right")}"><i class="fa-solid fa-arrow-right"></i></button>
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
            <div class="gw-robot-camera">
              <div class="gw-robot-camera-preview" hidden>
                <video class="gw-robot-camera-video" playsinline muted autoplay></video>
                <span class="gw-robot-camera-live">LIVE</span>
                <span class="gw-robot-camera-see">${t("Nakuona", "I see you")}</span>
              </div>
              <button type="button" class="gw-robot-camera-btn">
                <i class="fa-solid fa-video"></i>
                <span class="gw-robot-camera-btn-text">${t("Unganisha Camera", "Connect Camera")}</span>
              </button>
              <small class="gw-robot-camera-hint">${t("Bonyeza ili Agent akuone. Ruhusu camera kwenye browser yako.", "Press to let Agent see you. Allow camera access in your browser.")}</small>
            </div>
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
    initDrag();
    loadSavedPosition();
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
      if (suppressFabClick) return;
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
      stopCamera();
      setExpression("neutral");
    });

    root.querySelector(".gw-robot-camera-btn")?.addEventListener("click", () => {
      toggleCamera().catch(() => {});
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
    startCamera,
    stopCamera,
    toggleCamera,
    resetPosition,
    snapPosition,
    get aiOn() {
      return aiEnabled;
    },
    get cameraOn() {
      return cameraActive;
    },
  };

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", buildWidget);
  } else {
    buildWidget();
  }
})();
