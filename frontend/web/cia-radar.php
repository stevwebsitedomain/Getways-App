<?php

declare(strict_types=1);

require __DIR__ . '/admin-guard.php';

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
$adVersion = (string) (@filemtime(__DIR__ . '/admin-dashboard.css') ?: time());
$radarMode = ciaRadarEnv('RADAR_MODE', 'mock');
$ciaPageTitle = 'AI Motion Radar';
$ciaPageKicker = 'CIA Perimeter Detection';
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
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=Orbitron:wght@500;700;800&family=Share+Tech+Mono&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="admin-dashboard.css?v=<?= urlencode($adVersion) ?>" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
  <link rel="stylesheet" href="cia-radar.css?v=<?= urlencode($cssVersion) ?>" />
</head>
<body class="ad-body cia-radar-page">
<?php require __DIR__ . '/admin-cia-header.php'; ?>

<main class="ad-main cia-wrap cia-wrap--tactical">
  <section class="cia-tactical-screen" id="cia-radar-panel" aria-label="Radar display">
    <div class="cia-flight-bar">
      <span class="cia-flight-label">RADAR FLIGHT DATA:</span>
      <span class="cia-flight-ids" id="cia-flight-ids">— STANDBY —</span>
      <span class="cia-demo-badge" id="cia-demo-badge">DEMO MODE</span>
      <span class="cia-recording-indicator" id="cia-recording-indicator" hidden><span></span> SCANNING</span>
    </div>

    <div class="cia-radar-viewport">
      <canvas id="cia-radar-canvas" width="640" height="640" aria-label="Tactical radar"></canvas>
      <div class="cia-radar-hud">
        <span id="cia-system-status">OFFLINE</span>
        <span id="cia-sensor-status">Sensor idle</span>
        <span id="cia-clock">--:--:--</span>
      </div>
    </div>

    <div class="cia-status-strip cia-status-strip--inline">
      <span><strong>Mode:</strong> <span id="cia-mode-label"><?= htmlspecialchars(strtoupper($radarMode), ENT_QUOTES) ?></span></span>
      <span><strong>Radar:</strong> <span id="cia-radar-status">Disconnected</span></span>
      <button type="button" class="cia-btn cia-btn--ghost cia-btn--compact" id="cia-enable-audio"><i class="fa-solid fa-volume-high"></i> Alarm</button>
    </div>

    <nav class="cia-tactical-bar" aria-label="Tactical controls">
      <a href="admin-dashboard.php" class="cia-tac-btn">DIRECTORY</a>
      <a href="cia-radar-settings.php" class="cia-tac-btn">SUB-COMMAND</a>
      <button type="button" class="cia-tac-btn" id="cia-arm-toggle">SCAN</button>
      <button type="button" class="cia-tac-btn" id="cia-refresh-events">TRACK</button>
      <button type="button" class="cia-tac-btn cia-tac-btn--alert" id="cia-stop-all">SECURITY BREACH</button>
    </nav>
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
    <div class="cia-table-wrap cia-table-wrap--tall">
      <table class="cia-table" id="cia-events-table">
        <thead>
          <tr><th>ID</th><th>Object</th><th>Dist.</th><th>Time</th><th>Status</th></tr>
        </thead>
        <tbody></tbody>
      </table>
    </div>
  </section>
</main>

<video id="cia-hidden-video" hidden playsinline muted aria-hidden="true"></video>

<div class="cia-marker-detail" id="cia-marker-detail" hidden></div>

<div class="cia-popup" id="cia-popup" hidden role="alertdialog" aria-labelledby="cia-popup-title">
  <div class="cia-popup-card">
    <h3 id="cia-popup-title">MOTION DETECTED</h3>
    <img id="cia-popup-image" alt="Detection snapshot" />
    <div id="cia-popup-body"></div>
    <div class="cia-popup-actions">
      <button type="button" class="cia-btn" data-popup="ack">Acknowledge</button>
      <button type="button" class="cia-btn" data-popup="false">False Alarm</button>
      <button type="button" class="cia-btn cia-btn--ghost" data-popup="dismiss">Dismiss</button>
    </div>
  </div>
</div>

<?php require __DIR__ . '/ai-robot-include.php'; ?>
<script>
  window.GW_RADAR_API = <?= json_encode($radarApi, JSON_UNESCAPED_SLASHES) ?>;
  window.GW_RADAR_PROXY = 'cia-radar-api.php';
  window.GW_RADAR_MODE = <?= json_encode($radarMode, JSON_UNESCAPED_UNICODE) ?>;
  window.CIA_RADAR_PAGE = 'main';
</script>
<script src="cia-radar.js?v=<?= urlencode($jsVersion) ?>"></script>
</body>
</html>
