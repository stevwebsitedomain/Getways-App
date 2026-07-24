(function () {
  "use strict";

  const PROXY = window.GW_RADAR_PROXY || "cia-radar-api.php";
  const DIRECT = (window.GW_RADAR_API || "http://127.0.0.1:8765").replace(/\/$/, "");
  const MODE = String(window.GW_RADAR_MODE || "mock").toLowerCase();

  const state = {
    settings: null,
    markers: new Map(),
    markerTimeoutMs: 30000,
    maxRangeM: 5,
    armed: false,
    cameraOn: false,
    model: null,
    stream: null,
    ws: null,
    audioCtx: null,
    beepTimer: null,
    activeEvent: null,
    tracked: new Map(),
    recordingChunks: [],
    mediaRecorder: null,
    preBuffer: [],
  };

  const els = {};

  function $(id) {
    return document.getElementById(id);
  }

  async function api(path, options = {}) {
    const method = options.method || "GET";
    const proxyUrl = `${PROXY}?path=${encodeURIComponent(path)}`;
    const fetchOpts = {
      method,
      credentials: "same-origin",
      headers: { Accept: "application/json" },
    };
    if (options.body) {
      fetchOpts.headers["Content-Type"] = "application/json";
      fetchOpts.body = JSON.stringify(options.body);
    }
    let res = await fetch(proxyUrl, fetchOpts);
    if (!res.ok && res.status === 502) {
      const directUrl = `${DIRECT}${path}`;
      res = await fetch(directUrl, fetchOpts).catch(() => res);
    }
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.message || `API ${res.status}`);
    return data;
  }

  function severityColor(sev) {
    if (sev === "high") return "#f87171";
    if (sev === "medium") return "#fbbf24";
    return "#39ff14";
  }

  function formatDistance(d) {
    return d == null || Number.isNaN(Number(d)) ? "Unavailable" : `${Number(d).toFixed(1)} m`;
  }

  function updateClock() {
    const now = new Date();
    if (els.clock) els.clock.textContent = now.toLocaleTimeString();
  }

  function setStatus(payload) {
    if (!payload) return;
    const status = payload.system_status || "offline";
    if (els.systemStatus) {
      els.systemStatus.textContent = status.toUpperCase();
      els.systemStatus.classList.toggle("is-alert", status === "alert");
    }
    if (els.modeLabel) els.modeLabel.textContent = (payload.operating_mode || MODE).toUpperCase();
    if (els.cameraStatus) els.cameraStatus.textContent = payload.camera_connected || state.cameraOn ? "Connected" : "Disconnected";
    if (els.radarStatus) els.radarStatus.textContent = payload.radar_connected ? "Connected" : "Disconnected";
    if (els.demoBadge) els.demoBadge.hidden = !(payload.demo_mode || MODE === "mock" || MODE === "demo");
    if (els.recordingIndicator) els.recordingIndicator.hidden = !state.armed;
  }

  async function loadSettings() {
    const data = await api("/api/radar/settings");
    state.settings = data.settings;
    state.armed = !!data.settings.armed;
    state.maxRangeM = Number(data.settings.maximum_sensor_range_m || 5);
    state.markerTimeoutMs = (data.settings.marker_timeout_seconds || 30) * 1000;
    applySettingsToForm(data.settings);
    setStatus({ system_status: state.armed ? "armed" : "disarmed", demo_mode: MODE === "mock" });
  }

  function applySettingsToForm(s) {
    if (!s) return;
    const armed = $("cia-armed");
    if (armed) armed.checked = !!s.armed;
    const range = $("cia-range");
    if (range) {
      range.max = String(s.maximum_sensor_range_m || 5);
      range.value = String(s.selected_range_m || 5);
    }
    const rangeOut = $("cia-range-out");
    if (rangeOut) rangeOut.textContent = `${s.selected_range_m || 5} m`;
    const sens = $("cia-sensitivity");
    if (sens) sens.value = s.sensitivity || "medium";
    const conf = $("cia-confidence");
    if (conf) conf.value = String(s.confidence_threshold || 0.6);
    const confOut = $("cia-confidence-out");
    if (confOut) confOut.textContent = `${Math.round((s.confidence_threshold || 0.6) * 100)}%`;
    const cool = $("cia-cooldown");
    if (cool) cool.value = String(s.cooldown_seconds || 10);
    const vol = $("cia-volume");
    if (vol) vol.value = String(s.alarm_volume ?? 0.7);
    const alarm = $("cia-alarm-enabled");
    if (alarm) alarm.checked = !!s.alarm_enabled;
    const rec = $("cia-recording-enabled");
    if (rec) rec.checked = !!s.event_recording_enabled;
    const filt = $("cia-alert-filter");
    if (filt) filt.value = s.alert_filter || "all";
  }

  function readSettingsFromForm() {
    const max = Number($("cia-range")?.max || state.maxRangeM || 5);
    return {
      armed: !!$("cia-armed")?.checked,
      selected_range_m: Math.min(Number($("cia-range")?.value || 5), max),
      maximum_sensor_range_m: max,
      sensitivity: $("cia-sensitivity")?.value || "medium",
      confidence_threshold: Number($("cia-confidence")?.value || 0.6),
      alert_filter: $("cia-alert-filter")?.value || "all",
      alarm_enabled: !!$("cia-alarm-enabled")?.checked,
      alarm_volume: Number($("cia-volume")?.value || 0.7),
      event_recording_enabled: !!$("cia-recording-enabled")?.checked,
      pre_event_seconds: 5,
      post_event_seconds: 10,
      cooldown_seconds: Number($("cia-cooldown")?.value || 10),
      retention_days: 7,
      marker_timeout_seconds: 30,
      consecutive_frames_required: 3,
    };
  }

  function markerLabel(marker) {
    if (marker.label) return marker.label;
    const id = String(marker.tracking_id || "UNK");
    const hash = id.split("").reduce((a, c) => a + c.charCodeAt(0), 0);
    const letters = "ABCDEFGHIJKLMNOPQRSTUVWXYZ";
    return `${letters[hash % 26]}${letters[(hash >> 3) % 26]}${100 + (hash % 900)}`;
  }

  function updateFlightBar() {
    if (!els.flightIds) return;
    const labels = [];
    state.markers.forEach((m) => labels.push(markerLabel(m)));
    els.flightIds.textContent = labels.length ? labels.join("  |  ") : "— STANDBY —";
  }

  function drawPlaneIcon(ctx, x, y, color, scale) {
    const s = scale || 1;
    ctx.save();
    ctx.translate(x, y);
    ctx.scale(s, s);
    ctx.fillStyle = color;
    ctx.beginPath();
    ctx.moveTo(0, -10);
    ctx.lineTo(6, 6);
    ctx.lineTo(0, 3);
    ctx.lineTo(-6, 6);
    ctx.closePath();
    ctx.fill();
    ctx.restore();
  }

  function drawTargetBracket(ctx, x, y, size) {
    const h = size || 16;
    ctx.strokeStyle = "#ffffff";
    ctx.lineWidth = 2;
    const corners = [
      [x - h, y - h, x - h + 6, y - h, x - h, y - h + 6],
      [x + h, y - h, x + h - 6, y - h, x + h, y - h + 6],
      [x - h, y + h, x - h + 6, y + h, x - h, y + h - 6],
      [x + h, y + h, x + h - 6, y + h, x + h, y + h - 6],
    ];
    corners.forEach(([x1, y1, x2, y2, x3, y3]) => {
      ctx.beginPath();
      ctx.moveTo(x1, y1);
      ctx.lineTo(x2, y2);
      ctx.moveTo(x1, y1);
      ctx.lineTo(x3, y3);
      ctx.stroke();
    });
  }

  const DEMO_BLIPS = [
    { label: "DF105", angle_deg: -42, distance_m: 2.1, severity: "low" },
    { label: "OT357", angle_deg: 18, distance_m: 3.4, severity: "low" },
    { label: "PA396", angle_deg: 55, distance_m: 1.8, severity: "low" },
    { label: "SK755", angle_deg: -15, distance_m: 4.2, severity: "low" },
  ];

  let sweep = 0;
  const sweepTrail = [];

  function drawRadar() {
    const canvas = els.radarCanvas;
    if (!canvas) {
      requestAnimationFrame(drawRadar);
      return;
    }
    const ctx = canvas.getContext("2d");
    const w = canvas.width;
    const h = canvas.height;
    const cx = w / 2;
    const cy = h / 2;
    const maxR = Math.min(cx, cy) - 24;

    ctx.fillStyle = "#000000";
    ctx.fillRect(0, 0, w, h);

    ctx.strokeStyle = "rgba(220, 38, 38, 0.35)";
    ctx.lineWidth = 1;
    for (let gx = 0; gx < w; gx += 20) {
      ctx.beginPath();
      ctx.moveTo(gx, 0);
      ctx.lineTo(gx, h);
      ctx.stroke();
    }
    for (let gy = 0; gy < h; gy += 20) {
      ctx.beginPath();
      ctx.moveTo(0, gy);
      ctx.lineTo(w, gy);
      ctx.stroke();
    }

    ctx.save();
    ctx.beginPath();
    ctx.arc(cx, cy, maxR + 4, 0, Math.PI * 2);
    ctx.clip();

    ctx.strokeStyle = "rgba(57, 255, 20, 0.85)";
    ctx.lineWidth = 1.5;
    for (let i = 1; i <= 4; i++) {
      const r = (maxR / 4) * i;
      ctx.beginPath();
      ctx.arc(cx, cy, r, 0, Math.PI * 2);
      ctx.stroke();
    }

    ctx.strokeStyle = "rgba(57, 255, 20, 0.25)";
    ctx.lineWidth = 1;
    ctx.beginPath();
    ctx.moveTo(cx - maxR, cy);
    ctx.lineTo(cx + maxR, cy);
    ctx.moveTo(cx, cy - maxR);
    ctx.lineTo(cx, cy + maxR);
    ctx.stroke();

    ctx.fillStyle = "rgba(57, 255, 20, 0.75)";
    ctx.font = "11px 'Share Tech Mono', monospace";
    [-3, -2, -1, 1, 2, 3].forEach((n) => {
      const x = cx + (n / 3) * maxR * 0.85;
      ctx.fillText(String(n), x - 4, cy + 16);
    });

    sweep = (sweep + 1.8) % 360;
    sweepTrail.push(sweep);
    if (sweepTrail.length > 28) sweepTrail.shift();
    sweepTrail.forEach((deg, i) => {
      const alpha = (i / sweepTrail.length) * 0.22;
      const rad = ((deg - 90) * Math.PI) / 180;
      const grad = ctx.createConicGradient(rad, cx, cy);
      grad.addColorStop(0, `rgba(57, 255, 20, 0)`);
      grad.addColorStop(0.08, `rgba(57, 255, 20, ${alpha})`);
      grad.addColorStop(0.18, `rgba(57, 255, 20, 0)`);
      ctx.fillStyle = grad;
      ctx.beginPath();
      ctx.moveTo(cx, cy);
      ctx.arc(cx, cy, maxR, rad - 0.55, rad + 0.05);
      ctx.closePath();
      ctx.fill();
    });

    const sweepRad = ((sweep - 90) * Math.PI) / 180;
    ctx.strokeStyle = "rgba(57, 255, 20, 0.95)";
    ctx.lineWidth = 2;
    ctx.shadowColor = "#39ff14";
    ctx.shadowBlur = 12;
    ctx.beginPath();
    ctx.moveTo(cx, cy);
    ctx.lineTo(cx + Math.cos(sweepRad) * maxR, cy + Math.sin(sweepRad) * maxR);
    ctx.stroke();
    ctx.shadowBlur = 0;

    function plotMarker(marker, isDemo) {
      const dist = marker.distance_m;
      let x = cx;
      let y = cy;
      if (dist != null && marker.angle_deg != null) {
        const ratio = Math.min(dist / state.maxRangeM, 1);
        const r = ratio * maxR;
        const ang = ((marker.angle_deg - 90) * Math.PI) / 180;
        x = cx + Math.cos(ang) * r;
        y = cy + Math.sin(ang) * r;
      } else if (dist != null) {
        const ratio = Math.min(dist / state.maxRangeM, 1);
        x = cx;
        y = cy - ratio * maxR * 0.85;
      } else {
        return;
      }

      const isAlert = marker.severity === "high";
      const color = isAlert ? "#ff2b2b" : marker.severity === "medium" ? "#fbbf24" : "#39ff14";
      drawPlaneIcon(ctx, x, y, color, isAlert ? 1.15 : 0.95);
      if (isAlert) drawTargetBracket(ctx, x, y, 18);

      ctx.fillStyle = color;
      ctx.font = "10px 'Share Tech Mono', monospace";
      ctx.shadowColor = color;
      ctx.shadowBlur = 6;
      ctx.fillText(marker.label || markerLabel(marker), x + 10, y - 8);
      ctx.shadowBlur = 0;
    }

    const now = Date.now();
    const isDemo = MODE === "mock" || MODE === "demo";
    if (isDemo && state.markers.size === 0) {
      DEMO_BLIPS.forEach((b) => plotMarker(b, true));
    }

    state.markers.forEach((marker, key) => {
      if (now - marker.seenAt > state.markerTimeoutMs) {
        state.markers.delete(key);
        return;
      }
      marker.label = markerLabel(marker);
      plotMarker(marker, false);
    });

    ctx.restore();
    updateFlightBar();
    requestAnimationFrame(drawRadar);
  }

  function upsertMarker(marker) {
    if (!marker) return;
    state.markers.set(marker.tracking_id || `m-${Date.now()}`, {
      ...marker,
      seenAt: Date.now(),
    });
  }

  // ── Alarm ──
  function initAudio() {
    if (state.audioCtx) return;
    state.audioCtx = new (window.AudioContext || window.webkitAudioContext)();
  }

  function playBeep(volume = 0.7) {
    if (!state.audioCtx || !state.settings?.alarm_enabled) return;
    const osc = state.audioCtx.createOscillator();
    const gain = state.audioCtx.createGain();
    osc.type = "square";
    osc.frequency.value = 880;
    gain.gain.value = volume * 0.08;
    osc.connect(gain);
    gain.connect(state.audioCtx.destination);
    osc.start();
    osc.stop(state.audioCtx.currentTime + 0.12);
  }

  function startAlarmLoop(volume) {
    stopAlarmLoop();
    state.beepTimer = setInterval(() => playBeep(volume), 450);
  }

  function stopAlarmLoop() {
    if (state.beepTimer) clearInterval(state.beepTimer);
    state.beepTimer = null;
  }

  // ── Camera + detection ──
  async function connectCamera() {
    if (state.stream) return;
    state.stream = await navigator.mediaDevices.getUserMedia({
      video: { facingMode: "environment", width: { ideal: 640 }, height: { ideal: 480 } },
      audio: false,
    });
    els.video.srcObject = state.stream;
    await els.video.play();
    state.cameraOn = true;
    resizeOverlay();
    if (window.cocoSsd && !state.model) {
      state.model = await window.cocoSsd.load();
    }
    startRecorder();
    detectLoop();
  }

  function resizeOverlay() {
    const rect = els.video.getBoundingClientRect();
    els.overlay.width = rect.width;
    els.overlay.height = rect.height;
  }

  function startRecorder() {
    if (!state.stream || state.mediaRecorder) return;
    try {
      state.mediaRecorder = new MediaRecorder(state.stream, { mimeType: "video/webm;codecs=vp8" });
      state.mediaRecorder.ondataavailable = (e) => {
        if (!e.data.size) return;
        state.preBuffer.push(e.data);
        if (state.preBuffer.length > 20) state.preBuffer.shift();
      };
      state.mediaRecorder.start(250);
    } catch (_) {}
  }

  async function captureSnapshot() {
    const canvas = document.createElement("canvas");
    canvas.width = els.video.videoWidth || 640;
    canvas.height = els.video.videoHeight || 480;
    const ctx = canvas.getContext("2d");
    ctx.drawImage(els.video, 0, 0, canvas.width, canvas.height);
    return canvas.toDataURL("image/jpeg", 0.92);
  }

  async function captureClipBlob() {
    return new Promise((resolve) => {
      if (!state.stream) return resolve(null);
      const chunks = [...state.preBuffer];
      const rec = new MediaRecorder(state.stream, { mimeType: "video/webm;codecs=vp8" });
      rec.ondataavailable = (e) => e.data.size && chunks.push(e.data);
      rec.onstop = () => resolve(new Blob(chunks, { type: "video/webm" }));
      rec.start();
      setTimeout(() => rec.stop(), (state.settings?.post_event_seconds || 10) * 1000);
    });
  }

  const LABEL_MAP = {
    person: "person",
    car: "car",
    motorcycle: "motorcycle",
    bicycle: "bicycle",
    bus: "bus",
    truck: "truck",
    dog: "dog",
    cat: "cat",
    bird: "bird",
  };

  async function detectLoop() {
    if (!state.cameraOn || !state.armed) {
      requestAnimationFrame(detectLoop);
      return;
    }
    if (!state.model) {
      requestAnimationFrame(detectLoop);
      return;
    }
    const preds = await state.model.detect(els.video);
    const ctx = els.overlay.getContext("2d");
    ctx.clearRect(0, 0, els.overlay.width, els.overlay.height);
  const scaleX = els.overlay.width / (els.video.videoWidth || 1);
  const scaleY = els.overlay.height / (els.video.videoHeight || 1);

    for (const p of preds) {
      const label = LABEL_MAP[p.class] || (p.score >= 0.45 ? p.class : "unknown moving object");
      if (p.score < (state.settings?.confidence_threshold || 0.6) && label !== "person") continue;
      const [x, y, w, h] = p.bbox;
      ctx.strokeStyle = "#39ff14";
      ctx.lineWidth = 2;
      ctx.strokeRect(x * scaleX, y * scaleY, w * scaleX, h * scaleY);
      ctx.fillStyle = "rgba(57,255,20,0.85)";
      ctx.font = "12px DM Sans";
      ctx.fillText(`${label} ${Math.round(p.score * 100)}%`, x * scaleX + 4, y * scaleY + 14);

      const trackId = `cam-${label}-${Math.round(x / 40)}`;
      const hits = (state.tracked.get(trackId) || 0) + 1;
      state.tracked.set(trackId, hits);
      if (hits < 3) continue;

      if (MODE === "camera" || MODE === "mock" || MODE === "demo") {
        const image = await captureSnapshot();
        await api("/api/radar/frame", {
          method: "POST",
          body: {
            image,
            tracking_id: trackId,
            camera_name: "Webcam",
            distance_m: null,
            angle_deg: null,
          },
        }).catch(() => {});
      }
    }

    setTimeout(detectLoop, 900);
  }

  // ── Events UI ──
  function showPopup(event) {
    state.activeEvent = event;
    els.popup.hidden = false;
    els.popupBody.innerHTML = `
      <p><strong>Type:</strong> ${event.object_type}</p>
      <p><strong>Confidence:</strong> ${Math.round((event.confidence || 0) * 100)}%</p>
      <p><strong>Distance:</strong> ${formatDistance(event.distance_m)}</p>
      <p><strong>Time:</strong> ${new Date(event.detected_at).toLocaleTimeString()}</p>
      <p><strong>Severity:</strong> ${(event.severity || "medium").toUpperCase()}</p>
    `;
    if (event.snapshot_path) {
      els.popupImage.src = `${PROXY}?path=${encodeURIComponent(`/api/radar/events/${event.id}/snapshot`)}`;
    } else {
      els.popupImage.removeAttribute("src");
    }
    els.radarPanel?.classList.add("is-flash");
    setTimeout(() => els.radarPanel?.classList.remove("is-flash"), 900);
    if (state.settings?.alarm_enabled) startAlarmLoop(state.settings.alarm_volume || 0.7);
  }

  async function refreshEvents() {
    const params = new URLSearchParams();
    const dateEl = $("cia-filter-date");
    const catEl = $("cia-filter-category");
    const sevEl = $("cia-filter-severity");
    if (dateEl?.value) params.set("date", dateEl.value);
    if (catEl?.value) params.set("category", catEl.value);
    if (sevEl?.value) params.set("severity", sevEl.value);
    const q = params.toString();
    const data = await api(`/api/radar/events${q ? `?${q}` : ""}`);
    const tbody = els.eventsTable?.querySelector("tbody");
    if (!tbody) return;
    tbody.innerHTML = "";
    (data.events || []).forEach((ev) => {
      const tr = document.createElement("tr");
      tr.innerHTML = `
        <td>${ev.id}</td>
        <td>${ev.object_type}</td>
        <td>${formatDistance(ev.distance_m)}</td>
        <td>${new Date(ev.detected_at).toLocaleTimeString()}</td>
        <td>${ev.false_alarm ? "False" : ev.acknowledged ? "Ack" : "Open"}</td>`;
      tbody.appendChild(tr);
    });
  }

  function connectWs() {
    const wsUrl = DIRECT.replace(/^http/, "ws") + "/ws/radar/events";
    try {
      state.ws = new WebSocket(wsUrl);
      state.ws.onmessage = (msg) => {
        const payload = JSON.parse(msg.data);
        if (payload.type === "status") setStatus(payload.payload);
        if (payload.type === "detection") {
          upsertMarker(payload.marker);
          showPopup(payload.event);
          refreshEvents().catch(() => {});
        }
        if (payload.type === "radar_hardware") {
          const hw = payload.payload;
          if (hw && hw.event === "motion") {
            upsertMarker({
              tracking_id: `radar-${hw.timestamp || Date.now()}`,
              distance_m: hw.distance_m ?? null,
              angle_deg: hw.angle_deg ?? null,
              severity: "medium",
              object_type: "radar motion",
            });
          }
        }
      };
      state.ws.onclose = () => setTimeout(connectWs, 3000);
    } catch (_) {}
  }

  function bindEvents() {
    const form = $("cia-settings-form");
    form?.addEventListener("submit", async (e) => {
      e.preventDefault();
      const body = readSettingsFromForm();
      const data = await api("/api/radar/settings", { method: "PUT", body });
      state.settings = data.settings;
      state.armed = !!data.settings.armed;
      setStatus({ system_status: state.armed ? "armed" : "disarmed", demo_mode: MODE === "mock" });
      alert("Settings saved.");
    });

    $("cia-range")?.addEventListener("input", (e) => {
      const out = $("cia-range-out");
      if (out) out.textContent = `${e.target.value} m`;
    });
    $("cia-confidence")?.addEventListener("input", (e) => {
      const out = $("cia-confidence-out");
      if (out) out.textContent = `${Math.round(e.target.value * 100)}%`;
    });

    $("cia-camera-connect")?.addEventListener("click", () =>
      connectCamera().catch(() => alert("Camera access failed"))
    );

    $("cia-arm-toggle")?.addEventListener("click", async () => {
      const endpoint = state.armed ? "/api/radar/disarm" : "/api/radar/arm";
      const data = await api(endpoint, { method: "POST", body: {} });
      state.settings = data.settings;
      state.armed = !!data.settings.armed;
      const armedEl = $("cia-armed");
      if (armedEl) armedEl.checked = state.armed;
      $("cia-arm-toggle")?.classList.toggle("is-active", state.armed);
      setStatus({ system_status: state.armed ? "armed" : "disarmed", demo_mode: MODE === "mock" });
    });

    $("cia-enable-audio")?.addEventListener("click", () => {
      initAudio();
      playBeep(Number($("cia-volume")?.value || 0.7));
    });

    $("cia-stop-all")?.addEventListener("click", async () => {
      stopAlarmLoop();
      await api("/api/radar/stop-all", { method: "POST", body: {} });
      state.armed = false;
      const armedEl = $("cia-armed");
      if (armedEl) armedEl.checked = false;
      $("cia-arm-toggle")?.classList.remove("is-active");
      setStatus({ system_status: "disarmed" });
    });

    $("cia-refresh-events")?.addEventListener("click", () => refreshEvents());

    els.popup?.addEventListener("click", async (e) => {
      const btn = e.target.closest("[data-popup]");
      if (!btn || !state.activeEvent) return;
      const action = btn.dataset.popup;
      const id = state.activeEvent.id;
      if (action === "dismiss") {
        els.popup.hidden = true;
        stopAlarmLoop();
        return;
      }
      if (action === "ack") await api(`/api/radar/events/${id}/acknowledge`, { method: "POST", body: {} });
      if (action === "false") await api(`/api/radar/events/${id}/false-alarm`, { method: "POST", body: {} });
      if (action === "live") window.scrollTo({ top: 0, behavior: "smooth" });
      if (action === "clip" && state.activeEvent.video_path) {
        window.open(`${PROXY}?path=${encodeURIComponent(`/api/radar/events/${id}/video`)}`, "_blank");
      }
      els.popup.hidden = true;
      stopAlarmLoop();
      refreshEvents();
    });

    window.addEventListener("resize", resizeOverlay);
  }

  async function initMain() {
    els.clock = $("cia-clock");
    els.systemStatus = $("cia-system-status");
    els.modeLabel = $("cia-mode-label");
    els.cameraStatus = $("cia-camera-status");
    els.radarStatus = $("cia-radar-status");
    els.demoBadge = $("cia-demo-badge");
    els.radarCanvas = $("cia-radar-canvas");
    els.radarPanel = $("cia-radar-panel");
    els.flightIds = $("cia-flight-ids");
    els.video = $("cia-camera-video");
    els.overlay = $("cia-camera-overlay");
    els.popup = $("cia-popup");
    els.popupBody = $("cia-popup-body");
    els.popupImage = $("cia-popup-image");
    els.eventsTable = $("cia-events-table");
    els.recordingIndicator = $("cia-recording-indicator");

    bindEvents();
    updateClock();
    setInterval(updateClock, 1000);
    drawRadar();

    try {
      const status = await api("/api/radar/status");
      setStatus(status);
      state.maxRangeM = Number(status.maximum_sensor_range_m || 5);
    } catch (_) {
      setStatus({ system_status: "offline", demo_mode: true, operating_mode: MODE });
    }

    await loadSettings().catch(() => {});
    $("cia-arm-toggle")?.classList.toggle("is-active", state.armed);
    await refreshEvents().catch(() => {});
    connectWs();
  }

  async function initSettings() {
    bindEvents();
    await loadSettings().catch(() => {});
  }

  async function init() {
    const page = window.CIA_RADAR_PAGE || "main";
    if (page === "settings") {
      await initSettings();
      return;
    }
    await initMain();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
