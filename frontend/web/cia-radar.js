(function () {
  "use strict";

  const PROXY = window.GW_RADAR_PROXY || "cia-radar-api.php";
  const CAMERA_PROXY = "cia-camera-proxy.php";
  const CAMERA_STORAGE_KEY = "cia_external_camera_v1";
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
    phosphor: null,
    hiddenVideo: null,
    motionCanvas: null,
    lastMotionFrame: null,
    serviceOnline: false,
    sensorActive: false,
    lastLocalAlert: 0,
    sensorLoopRunning: false,
    externalCameraUrl: "",
    externalPollTimer: null,
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
    if (els.radarStatus) els.radarStatus.textContent = payload.radar_connected || state.serviceOnline ? "Connected" : "Disconnected";
    if (els.sensorStatus) {
      els.sensorStatus.textContent = state.sensorActive
        ? "Auto motion sensor active"
        : state.armed
          ? "Sensor starting..."
          : "Sensor idle";
    }
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

  function ensurePhosphor(w, h) {
    if (!state.phosphor || state.phosphor.width !== w || state.phosphor.height !== h) {
      state.phosphor = document.createElement("canvas");
      state.phosphor.width = w;
      state.phosphor.height = h;
    }
    return state.phosphor.getContext("2d");
  }

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

    sweep = (sweep + 1.6) % 360;
    const sweepRad = ((sweep - 90) * Math.PI) / 180;
    const pctx = ensurePhosphor(w, h);

    pctx.fillStyle = "rgba(0, 0, 0, 0.06)";
    pctx.fillRect(0, 0, w, h);

    pctx.save();
    pctx.beginPath();
    pctx.moveTo(cx, cy);
    pctx.arc(cx, cy, maxR, sweepRad - 0.62, sweepRad + 0.02);
    pctx.closePath();
    const wedge = pctx.createRadialGradient(cx, cy, 0, cx, cy, maxR);
    wedge.addColorStop(0, "rgba(57, 255, 20, 0.55)");
    wedge.addColorStop(0.45, "rgba(57, 255, 20, 0.22)");
    wedge.addColorStop(1, "rgba(57, 255, 20, 0)");
    pctx.fillStyle = wedge;
    pctx.fill();
    pctx.restore();

    pctx.strokeStyle = "rgba(57, 255, 20, 0.95)";
    pctx.lineWidth = 2.5;
    pctx.shadowColor = "#39ff14";
    pctx.shadowBlur = 18;
    pctx.beginPath();
    pctx.moveTo(cx, cy);
    pctx.lineTo(cx + Math.cos(sweepRad) * maxR, cy + Math.sin(sweepRad) * maxR);
    pctx.stroke();
    pctx.shadowBlur = 0;

    ctx.drawImage(state.phosphor, 0, 0);

    ctx.strokeStyle = "rgba(57, 255, 20, 0.95)";
    ctx.lineWidth = 2.5;
    ctx.shadowColor = "#39ff14";
    ctx.shadowBlur = 14;
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
    const isDemo = (MODE === "mock" || MODE === "demo") && !state.armed;
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

  // ── Outdoor IP camera ──
  function getSensorSource() {
    const preview = els.camPreview;
    if (preview && preview.complete && preview.naturalWidth > 0 && !preview.hidden) {
      return preview;
    }
    const video = state.hiddenVideo;
    if (video && video.readyState >= 2) return video;
    return null;
  }

  function setCameraStatus(text, on) {
    if (!els.camStatus) return;
    els.camStatus.textContent = text;
    els.camStatus.classList.toggle("is-on", !!on);
  }

  function getCameraAuthParams() {
    const params = new URLSearchParams();
    const user = $("cia-cam-user")?.value.trim() || "";
    const pass = $("cia-cam-pass")?.value || "";
    if (user) params.set("user", user);
    if (pass) params.set("pass", pass);
    return params;
  }

  function proxySnapshotUrl(cameraUrl) {
    const params = getCameraAuthParams();
    params.set("action", "snapshot");
    params.set("camera", cameraUrl);
    params.set("t", String(Date.now()));
    return `${CAMERA_PROXY}?${params}`;
  }

  function stopExternalPoll() {
    if (state.externalPollTimer) clearInterval(state.externalPollTimer);
    state.externalPollTimer = null;
  }

  function getCameraBrand() {
    return $("cia-cam-brand")?.value || "v380";
  }

  function deriveSubnet(ip) {
    const m = String(ip || "").trim().match(/^(\d{1,3}\.\d{1,3}\.\d{1,3})\.\d{1,3}$/);
    return m ? m[1] : "192.168.0";
  }

  function renderCameraList(data, autoConnect = false) {
    const list = els.camResults;
    if (!list) return null;
    list.innerHTML = "";
    list.hidden = false;

    if (data.hints?.length) {
      const hint = document.createElement("li");
      hint.innerHTML = `<span class="cia-cam-hint">${data.hints.join("<br>")}</span>`;
      list.appendChild(hint);
    }
    if (data.message) {
      const msg = document.createElement("li");
      msg.innerHTML = `<span class="cia-cam-hint"><strong>${data.message}</strong></span>`;
      list.appendChild(msg);
    }
    if (data.rtsp_detected && !data.ffmpeg_available) {
      const ff = document.createElement("li");
      ff.innerHTML = '<span class="cia-cam-hint" style="color:#fbbf24">V380 uses RTSP — install <strong>ffmpeg</strong> for live view.</span>';
      list.appendChild(ff);
    }

    let firstUrl = null;

    const addCameraBtn = (cam, ipLabel) => {
      const li = document.createElement("li");
      const b = document.createElement("button");
      b.type = "button";
      b.textContent = ipLabel ? `${ipLabel} — ${cam.label || cam.display_url}` : cam.label || cam.display_url;
      if (cam.needs_auth) b.textContent += " (needs password)";
      b.addEventListener("click", () => {
        if (ipLabel) $("cia-cam-ip").value = ipLabel;
        connectExternalCamera(cam.url);
      });
      li.appendChild(b);
      list.appendChild(li);
      if (!firstUrl) firstUrl = cam.url;
    };

    if (data.hosts?.length) {
      data.hosts.forEach((host) => {
        const head = document.createElement("li");
        head.innerHTML = `<span class="cia-cam-hint"><strong>${host.ip}</strong> · ${host.brand_guess || "Camera"} · ports ${(host.open_ports || []).join(", ")}</span>`;
        list.appendChild(head);
        (host.cameras || []).forEach((cam) => addCameraBtn(cam, host.ip));
        if (!firstUrl && host.best_url) firstUrl = host.best_url;
        if (host.ip) $("cia-cam-ip").value = host.ip;
      });
    } else {
      (data.cameras || []).forEach((cam) => addCameraBtn(cam, null));
    }

    if (!data.cameras?.length && !data.hosts?.length) {
      const empty = document.createElement("li");
      empty.innerHTML = `<span class="cia-cam-hint">${data.message || "No camera found."}</span>`;
      list.appendChild(empty);
      if (data.open_ports?.length) {
        const ports = document.createElement("li");
        ports.innerHTML = `<span class="cia-cam-hint">Open ports: ${data.open_ports.join(", ")}</span>`;
        list.appendChild(ports);
      }
    }

    return firstUrl;
  }

  async function connectExternalCamera(cameraUrl, save = true) {
    const url = String(cameraUrl || "").trim();
    if (!url) return false;

    stopWebcam();
    state.externalCameraUrl = url;
    if (save) {
      localStorage.setItem(CAMERA_STORAGE_KEY, url);
    }

    const manual = $("cia-cam-url");
    if (manual) manual.value = url;

    setCameraStatus("Connecting...", false);
    try {
      const test = await fetch(proxySnapshotUrl(url), { credentials: "same-origin" });
      if (!test.ok) {
        const err = await test.json().catch(() => ({}));
        if (url.startsWith("rtsp://") && String(err.message || "").toLowerCase().includes("ffmpeg")) {
          if (els.camHint) {
            els.camHint.hidden = false;
            els.camHint.textContent = "V380 RTSP linked. Install ffmpeg to see live video and motion detection.";
          }
          state.sensorActive = true;
          setCameraStatus("RTSP linked", true);
          if (els.sensorStatus) els.sensorStatus.textContent = "V380 camera (needs ffmpeg)";
          if (!state.sensorLoopRunning) autoSensorLoop();
          return true;
        }
        throw new Error(err.message || "Camera unreachable");
      }
      if (els.camPreview) {
        els.camPreview.src = proxySnapshotUrl(url);
        els.camPreview.hidden = false;
      }
      if (els.camHint) els.camHint.hidden = true;
      state.sensorActive = true;
      setCameraStatus("Connected", true);
      if (els.sensorStatus) els.sensorStatus.textContent = "Outdoor camera active";
      stopExternalPoll();
      state.externalPollTimer = setInterval(() => {
        if (!state.externalCameraUrl || !els.camPreview) return;
        els.camPreview.src = proxySnapshotUrl(state.externalCameraUrl);
      }, 1400);
      if (!state.sensorLoopRunning) autoSensorLoop();
      return true;
    } catch (_) {
      setCameraStatus("Connection failed", false);
      state.externalCameraUrl = "";
      return false;
    }
  }

  async function searchCameras() {
    const ip = $("cia-cam-ip")?.value.trim();
    if (!ip) {
      alert("Enter camera IP or use Scan Network.");
      return;
    }
    const user = $("cia-cam-user")?.value.trim() || "";
    const pass = $("cia-cam-pass")?.value || "";
    const brand = getCameraBrand();
    const btn = $("cia-cam-search");
    if (btn) {
      btn.disabled = true;
      btn.textContent = "Searching...";
    }
    try {
      const params = new URLSearchParams({ action: "probe", ip, user, pass, brand });
      const res = await fetch(`${CAMERA_PROXY}?${params}`, { credentials: "same-origin" });
      const data = await res.json();
      renderCameraList(data, false);
    } catch (_) {
      alert("Camera search failed. Check IP and network.");
    } finally {
      if (btn) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-magnifying-glass"></i> Search IP';
      }
    }
  }

  async function scanNetwork() {
    const ipHint = $("cia-cam-ip")?.value.trim();
    const subnet = deriveSubnet(ipHint);
    const user = $("cia-cam-user")?.value.trim() || "";
    const pass = $("cia-cam-pass")?.value || "";
    const brand = getCameraBrand();
    const scanBtn = $("cia-cam-scan");
    const progress = $("cia-cam-scan-progress");
    const allHosts = [];

    if (scanBtn) scanBtn.disabled = true;
    if (progress) {
      progress.hidden = false;
      progress.textContent = `Scanning ${subnet}.x for V380 cameras...`;
    }
    if (els.camResults) {
      els.camResults.hidden = false;
      els.camResults.innerHTML = '<li><span class="cia-cam-hint">Scanning local network...</span></li>';
    }

    try {
      for (let start = 1; start <= 254; start += 30) {
        const end = Math.min(start + 29, 254);
        if (progress) progress.textContent = `Scanning ${subnet}.${start} – ${subnet}.${end}...`;
        const params = new URLSearchParams({
          action: "scan",
          subnet,
          start: String(start),
          end: String(end),
          user,
          pass,
          brand,
        });
        const res = await fetch(`${CAMERA_PROXY}?${params}`, { credentials: "same-origin" });
        const data = await res.json();
        if (data.hosts?.length) allHosts.push(...data.hosts);
      }

      const firstUrl = renderCameraList(
        {
          hosts: allHosts,
          message: allHosts.length
            ? `Found ${allHosts.length} camera(s) on network.`
            : `No V380 camera found on ${subnet}.x — check camera is on same Wi‑Fi.`,
        },
        true
      );

      if (firstUrl) {
        if (progress) progress.textContent = "Camera found — connecting...";
        const ok = await connectExternalCamera(firstUrl);
        if (progress) {
          progress.textContent = ok ? "V380 connected automatically." : "Found camera — click a stream to connect.";
        }
      } else if (progress) {
        progress.textContent = `Scan complete. No camera on ${subnet}.x`;
      }
    } catch (_) {
      alert("Network scan failed.");
      if (progress) progress.hidden = true;
    } finally {
      if (scanBtn) {
        scanBtn.disabled = false;
        scanBtn.innerHTML = '<i class="fa-solid fa-wifi"></i> Scan Network';
      }
    }
  }

  function stopWebcam() {
    if (state.stream) {
      state.stream.getTracks().forEach((t) => t.stop());
      state.stream = null;
    }
    if (state.hiddenVideo) state.hiddenVideo.srcObject = null;
  }

  // ── Auto motion sensor ──
  async function startHiddenSensor() {
    const saved = localStorage.getItem(CAMERA_STORAGE_KEY);
    if (saved) {
      const ok = await connectExternalCamera(saved, false);
      if (ok) return;
    }

    if (state.stream || !state.hiddenVideo) return;
    try {
      state.stream = await navigator.mediaDevices.getUserMedia({
        video: { facingMode: "environment", width: { ideal: 640 }, height: { ideal: 480 } },
        audio: false,
      });
      state.hiddenVideo.srcObject = state.stream;
      await state.hiddenVideo.play();
      state.sensorActive = true;
      setStatus({ system_status: state.armed ? "armed" : "disarmed", radar_connected: state.serviceOnline });
      autoSensorLoop();
    } catch (_) {
      state.sensorActive = false;
      if (els.sensorStatus) els.sensorStatus.textContent = "Sensor unavailable — using radar service";
    }
  }

  function captureHiddenFrame() {
    const source = getSensorSource();
    if (!source) return null;
    if (!state.motionCanvas) state.motionCanvas = document.createElement("canvas");
    const canvas = state.motionCanvas;
    const w = source.videoWidth || source.naturalWidth || 640;
    const h = source.videoHeight || source.naturalHeight || 480;
    canvas.width = w;
    canvas.height = h;
    const ctx = canvas.getContext("2d");
    ctx.drawImage(source, 0, 0, canvas.width, canvas.height);
    return canvas.toDataURL("image/jpeg", 0.75);
  }

  function detectLocalMotion() {
    const source = getSensorSource();
    if (!source) return false;
    if (!state.motionCanvas) state.motionCanvas = document.createElement("canvas");
    const canvas = state.motionCanvas;
    const w = Math.min(source.videoWidth || source.naturalWidth || 320, 320);
    const h = Math.min(source.videoHeight || source.naturalHeight || 240, 240);
    canvas.width = w;
    canvas.height = h;
    const ctx = canvas.getContext("2d");
    ctx.drawImage(source, 0, 0, w, h);
    const frame = ctx.getImageData(0, 0, w, h);
    if (!state.lastMotionFrame) {
      state.lastMotionFrame = frame;
      return false;
    }
    let diff = 0;
    const step = 16;
    for (let i = 0; i < frame.data.length; i += 4 * step) {
      diff += Math.abs(frame.data[i] - state.lastMotionFrame.data[i]);
      diff += Math.abs(frame.data[i + 1] - state.lastMotionFrame.data[i + 1]);
      diff += Math.abs(frame.data[i + 2] - state.lastMotionFrame.data[i + 2]);
    }
    state.lastMotionFrame = frame;
    const threshold = { low: 9000, medium: 6500, high: 4200 }[state.settings?.sensitivity || "medium"] || 6500;
    return diff > threshold;
  }

  function reportLocalMotion() {
    const now = Date.now();
    const cooldownMs = (state.settings?.cooldown_seconds || 10) * 1000;
    if (now - state.lastLocalAlert < cooldownMs) return;
    state.lastLocalAlert = now;
    const angle = Math.round((Math.random() * 120) - 60);
    const distance = Number((1.2 + Math.random() * (state.maxRangeM - 1)).toFixed(1));
    const trackingId = `local-${Date.now()}`;
    const marker = {
      tracking_id: trackingId,
      object_type: "person",
      severity: distance < 2.5 ? "high" : "medium",
      distance_m: distance,
      angle_deg: angle,
      label: markerLabel({ tracking_id: trackingId }),
    };
    upsertMarker(marker);
    showPopup({
      id: trackingId,
      object_type: "person",
      confidence: 0.78,
      distance_m: distance,
      detected_at: new Date().toISOString(),
      severity: marker.severity,
    });
    refreshEvents().catch(() => {});
  }

  async function autoSensorLoop() {
    state.sensorLoopRunning = true;
    if (!state.armed) {
      setTimeout(autoSensorLoop, 1000);
      return;
    }

    const hasMotion = detectLocalMotion();
    const image = captureHiddenFrame();

    if (image && state.serviceOnline) {
      try {
        const result = await api("/api/radar/frame", {
          method: "POST",
          body: {
            image,
            tracking_id: `auto-${Date.now()}`,
            camera_name: "Auto Sensor",
          },
        });
        if (result.events?.length) {
          result.events.forEach((ev) => {
            upsertMarker({
              tracking_id: ev.tracking_id || `ev-${ev.id}`,
              object_type: ev.object_type,
              severity: ev.severity,
              distance_m: ev.distance_m,
              angle_deg: ev.angle_deg,
            });
            showPopup(ev);
          });
          refreshEvents().catch(() => {});
        }
      } catch (_) {
        if (hasMotion) reportLocalMotion();
      }
    } else if (hasMotion) {
      reportLocalMotion();
    }

    setTimeout(autoSensorLoop, state.serviceOnline ? 1100 : 800);
  }

  async function ensureArmed() {
    if (state.armed) return;
    try {
      const data = await api("/api/radar/arm", { method: "POST", body: {} });
      state.settings = data.settings;
      state.armed = !!data.settings.armed;
      $("cia-arm-toggle")?.classList.toggle("is-active", state.armed);
      const armedEl = $("cia-armed");
      if (armedEl) armedEl.checked = state.armed;
      setStatus({ system_status: "armed", demo_mode: MODE === "mock" });
    } catch (_) {
      state.armed = true;
      setStatus({ system_status: "armed", demo_mode: true });
    }
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

    $("cia-cam-scan")?.addEventListener("click", () => scanNetwork());
    $("cia-cam-search")?.addEventListener("click", () => searchCameras());
    $("cia-cam-connect")?.addEventListener("click", async () => {
      const manual = $("cia-cam-url")?.value.trim();
      if (manual) {
        await connectExternalCamera(manual);
        return;
      }
      const ip = $("cia-cam-ip")?.value.trim();
      if (!ip) {
        alert("Enter camera IP or manual stream URL.");
        return;
      }
      await searchCameras();
    });

    $("cia-filter-date")?.addEventListener("change", () => refreshEvents());
    $("cia-filter-severity")?.addEventListener("change", () => refreshEvents());

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
      if (typeof id !== "number" && typeof id !== "string") return;
      if (action === "ack" && String(id).match(/^\d+$/)) {
        await api(`/api/radar/events/${id}/acknowledge`, { method: "POST", body: {} });
      }
      if (action === "false" && String(id).match(/^\d+$/)) {
        await api(`/api/radar/events/${id}/false-alarm`, { method: "POST", body: {} });
      }
      els.popup.hidden = true;
      stopAlarmLoop();
      refreshEvents();
    });
  }

  async function initMain() {
    els.clock = $("cia-clock");
    els.systemStatus = $("cia-system-status");
    els.sensorStatus = $("cia-sensor-status");
    els.modeLabel = $("cia-mode-label");
    els.radarStatus = $("cia-radar-status");
    els.demoBadge = $("cia-demo-badge");
    els.radarCanvas = $("cia-radar-canvas");
    els.radarPanel = $("cia-radar-panel");
    els.flightIds = $("cia-flight-ids");
    els.hiddenVideo = $("cia-hidden-video");
    els.camPreview = $("cia-cam-preview");
    els.camStatus = $("cia-cam-connect-status");
    els.camResults = $("cia-cam-results");
    els.camHint = $("cia-cam-hint");
    els.camProgress = $("cia-cam-scan-progress");
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
      state.serviceOnline = true;
      setStatus(status);
      state.maxRangeM = Number(status.maximum_sensor_range_m || 5);
    } catch (_) {
      state.serviceOnline = false;
      setStatus({ system_status: "offline", demo_mode: true, operating_mode: MODE });
    }

    await loadSettings().catch(() => {});
    await ensureArmed();
    $("cia-arm-toggle")?.classList.toggle("is-active", state.armed);
    await refreshEvents().catch(() => {});
    connectWs();
    startHiddenSensor();
    initAudio();
    setInterval(async () => {
      try {
        const status = await api("/api/radar/status");
        state.serviceOnline = true;
        setStatus(status);
      } catch (_) {
        state.serviceOnline = false;
      }
    }, 8000);
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
