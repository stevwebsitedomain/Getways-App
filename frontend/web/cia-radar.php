<?php

declare(strict_types=1);

require __DIR__ . '/auth-guard.php';

function ciaRadarEnv(string $key, string $default = ''): string
{
    $fromServer = getenv($key);
    if ($fromServer !== false && $fromServer !== '') {
        return (string) $fromServer;
    }
    $envPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env';
    if (!is_file($envPath)) {
        return $default;
    }
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return $default;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        if (trim($k) === $key) {
            return trim($v, " \t\"'");
        }
    }
    return $default;
}

$radarApi = ciaRadarEnv('RADAR_SERVICE_URL', 'http://127.0.0.1:8765');
$cssVersion = (string) (@filemtime(__DIR__ . '/cia-radar.css') ?: time());
$jsVersion = (string) (@filemtime(__DIR__ . '/cia-radar.js') ?: time());
$bkVersion = (string) (@filemtime(__DIR__ . '/wallet-banking-theme.css') ?: time());
$ptVersion = (string) (@filemtime(__DIR__ . '/part-two.css') ?: time());
$radarMode = ciaRadarEnv('RADAR_MODE', 'mock');
$phoneTopbarTitle = 'CIA Radar';
$phoneTopbarBack = 'part-two.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
  <title>CIA | AI Motion Radar</title>
  <link rel="icon" type="image/png" href="images/favicon.png" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link
    href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;0,9..40,800&family=Orbitron:wght@500;700;800&family=Share+Tech+Mono&display=swap"
    rel="stylesheet"
  />
  <link rel="stylesheet" href="style.css" />
  <link rel="stylesheet" href="part-two.css?v=<?= urlencode($ptVersion) ?>" />
  <link rel="stylesheet" href="wallet-banking-theme.css?v=<?= urlencode($bkVersion) ?>" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
  <link rel="stylesheet" href="cia-radar.css?v=<?= urlencode($cssVersion) ?>" />
</head>
<body class="tis-shell tis-wallet-dash layout-phone w-home-sample bk-theme cia-radar-page">
<?php $activeTopNav = 'cia'; require __DIR__ . '/wallet-top-nav.php'; ?>

<main class="tis-wrap w-shell">
  <div class="w-app">
<?php require __DIR__ . '/wallet-phone-topbar.php'; ?>

<div class="cia-wrap cia-wrap--tactical">
  <section class="cia-tactical-screen" id="cia-radar-panel" aria-label="Radar display">
    <div class="cia-flight-bar">
      <span class="cia-flight-label">RADAR FLIGHT DATA:</span>
      <span class="cia-flight-ids" id="cia-flight-ids">— STANDBY —</span>
      <span class="cia-demo-badge" id="cia-demo-badge">DEMO MODE</span>
      <span class="cia-recording-indicator" id="cia-recording-indicator" hidden><span></span> REC</span>
    </div>

    <div class="cia-radar-viewport">
      <canvas id="cia-radar-canvas" width="640" height="640" aria-label="Tactical radar"></canvas>
      <div class="cia-radar-hud">
        <span id="cia-system-status">OFFLINE</span>
        <span id="cia-clock">--:--:--</span>
      </div>
    </div>

    <nav class="cia-tactical-bar" aria-label="Tactical controls">
      <a href="part-two.php" class="cia-tac-btn">DIRECTORY</a>
      <a href="cia-radar-settings.php" class="cia-tac-btn">SUB-COMMAND</a>
      <button type="button" class="cia-tac-btn" id="cia-camera-connect">PROXY</button>
      <button type="button" class="cia-tac-btn" id="cia-arm-toggle">SCAN</button>
      <button type="button" class="cia-tac-btn" id="cia-refresh-events">TRACK</button>
      <button type="button" class="cia-tac-btn cia-tac-btn--alert" id="cia-stop-all">SECURITY BREACH</button>
    </nav>
  </section>

  <aside class="cia-side-stack">
    <section class="cia-panel cia-panel--camera" aria-label="Live camera">
      <div class="cia-panel-head">
        <h2>Live Camera</h2>
        <span id="cia-camera-status">Disconnected</span>
      </div>
      <div class="cia-camera-wrap">
        <video id="cia-camera-video" playsinline muted autoplay></video>
        <canvas id="cia-camera-overlay"></canvas>
      </div>
      <div class="cia-side-actions">
        <button type="button" class="cia-btn" id="cia-enable-audio"><i class="fa-solid fa-volume-high"></i> Alarm Sound</button>
        <a href="cia-radar-settings.php" class="cia-btn cia-btn--ghost"><i class="fa-solid fa-sliders"></i> Settings</a>
      </div>
      <div class="cia-status-strip">
        <span><strong>Mode:</strong> <span id="cia-mode-label"><?= htmlspecialchars(strtoupper($radarMode), ENT_QUOTES) ?></span></span>
        <span><strong>Radar:</strong> <span id="cia-radar-status">Disconnected</span></span>
      </div>
    </section>

    <section class="cia-panel cia-panel--history" aria-label="Event history">
      <div class="cia-panel-head">
        <h2>Event Log</h2>
        <div class="cia-filters">
          <input type="date" id="cia-filter-date" />
          <select id="cia-filter-severity">
            <option value="">All</option>
            <option value="high">High</option>
            <option value="medium">Medium</option>
            <option value="low">Low</option>
          </select>
        </div>
      </div>
      <div class="cia-table-wrap">
        <table class="cia-table" id="cia-events-table">
          <thead>
            <tr><th>ID</th><th>Object</th><th>Dist.</th><th>Time</th><th>Status</th></tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
    </section>
  </aside>
</div>

  </div>
</main>

<div class="cia-marker-detail" id="cia-marker-detail" hidden></div>

<div class="cia-popup" id="cia-popup" hidden role="alertdialog" aria-labelledby="cia-popup-title">
  <div class="cia-popup-card">
    <h3 id="cia-popup-title">MOTION DETECTED</h3>
    <img id="cia-popup-image" alt="Detection snapshot" />
    <div id="cia-popup-body"></div>
    <div class="cia-popup-actions">
      <button type="button" class="cia-btn" data-popup="live">View Live</button>
      <button type="button" class="cia-btn" data-popup="clip">Play Clip</button>
      <button type="button" class="cia-btn" data-popup="ack">Acknowledge</button>
      <button type="button" class="cia-btn" data-popup="false">False Alarm</button>
      <button type="button" class="cia-btn cia-btn--ghost" data-popup="dismiss">Dismiss</button>
    </div>
  </div>
</div>

<?php $activeNav = 'cia'; require __DIR__ . '/wallet-bottom-nav.php'; ?>
<?php require __DIR__ . '/ai-robot-include.php'; ?>
<script>
  window.GW_RADAR_API = <?= json_encode($radarApi, JSON_UNESCAPED_SLASHES) ?>;
  window.GW_RADAR_PROXY = 'cia-radar-api.php';
  window.GW_RADAR_MODE = <?= json_encode($radarMode, JSON_UNESCAPED_UNICODE) ?>;
  window.CIA_RADAR_PAGE = 'main';
</script>
<script src="https://cdn.jsdelivr.net/npm/@tensorflow/tfjs@4.22.0/dist/tf.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@tensorflow-models/coco-ssd@2.2.3/dist/coco-ssd.min.js"></script>
<script src="cia-radar.js?v=<?= urlencode($jsVersion) ?>"></script>
<script src="wallet-shell.js"></script>
</body>
</html>
