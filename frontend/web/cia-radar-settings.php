<?php

declare(strict_types=1);

require __DIR__ . '/auth-guard.php';

function ciaSettingsEnv(string $key, string $default = ''): string
{
    $fromServer = getenv($key);
    if ($fromServer !== false && $fromServer !== '') {
        return (string) $fromServer;
    }
    $envPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env';
    if (!is_file($envPath)) {
        return $default;
    }
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
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

$radarApi = ciaSettingsEnv('RADAR_SERVICE_URL', 'http://127.0.0.1:8765');
$cssVersion = (string) (@filemtime(__DIR__ . '/cia-radar.css') ?: time());
$jsVersion = (string) (@filemtime(__DIR__ . '/cia-radar.js') ?: time());
$bkVersion = (string) (@filemtime(__DIR__ . '/wallet-banking-theme.css') ?: time());
$ptVersion = (string) (@filemtime(__DIR__ . '/part-two.css') ?: time());
$radarMode = ciaSettingsEnv('RADAR_MODE', 'mock');
$phoneTopbarTitle = 'Detection Settings';
$phoneTopbarBack = 'cia-radar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
  <title>CIA | Detection Settings</title>
  <link rel="icon" type="image/png" href="images/favicon.png" />
  <link
    href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;0,9..40,800&family=Orbitron:wght@500;700&family=Share+Tech+Mono&display=swap"
    rel="stylesheet"
  />
  <link rel="stylesheet" href="style.css" />
  <link rel="stylesheet" href="part-two.css?v=<?= urlencode($ptVersion) ?>" />
  <link rel="stylesheet" href="wallet-banking-theme.css?v=<?= urlencode($bkVersion) ?>" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
  <link rel="stylesheet" href="cia-radar.css?v=<?= urlencode($cssVersion) ?>" />
</head>
<body class="tis-shell tis-wallet-dash layout-phone w-home-sample bk-theme cia-radar-page cia-settings-page">
<?php $activeTopNav = 'cia'; require __DIR__ . '/wallet-top-nav.php'; ?>

<main class="tis-wrap w-shell">
  <div class="w-app">
<?php require __DIR__ . '/wallet-phone-topbar.php'; ?>

<div class="cia-wrap cia-wrap--settings">
  <header class="cia-settings-header">
    <div>
      <p class="cia-kicker">Sub-Command</p>
      <h1>Detection Settings</h1>
    </div>
    <a href="cia-radar.php" class="cia-btn"><i class="fa-solid fa-satellite-dish"></i> Back to Radar</a>
  </header>

  <section class="cia-panel cia-panel--settings-full" aria-label="Detection settings">
    <form id="cia-settings-form" class="cia-settings cia-settings--grid">
      <label class="cia-toggle cia-toggle--big"><input type="checkbox" id="cia-armed" /> Arm perimeter system</label>

      <label>Detection range (m)
        <input type="range" id="cia-range" min="1" max="5" step="0.1" value="5" />
        <output id="cia-range-out">5 m</output>
      </label>

      <label>Sensitivity
        <select id="cia-sensitivity">
          <option value="low">Low</option>
          <option value="medium" selected>Medium</option>
          <option value="high">High</option>
        </select>
      </label>

      <label>Minimum confidence
        <input type="range" id="cia-confidence" min="0.3" max="0.95" step="0.05" value="0.6" />
        <output id="cia-confidence-out">60%</output>
      </label>

      <label>Detection cooldown (seconds)
        <input type="number" id="cia-cooldown" min="3" max="120" value="10" />
      </label>

      <label>Alarm volume
        <input type="range" id="cia-volume" min="0" max="1" step="0.05" value="0.7" />
      </label>

      <label class="cia-toggle"><input type="checkbox" id="cia-alarm-enabled" checked /> Enable audible alarm</label>
      <label class="cia-toggle"><input type="checkbox" id="cia-recording-enabled" checked /> Enable event recording</label>

      <label>Alert filter
        <select id="cia-alert-filter">
          <option value="all">All motion</option>
          <option value="people">People only</option>
          <option value="vehicles">Vehicles only</option>
          <option value="animals">Animals only</option>
          <option value="unknown">Unknown objects only</option>
        </select>
      </label>

      <label>Category filter (history)
        <select id="cia-filter-category">
          <option value="">All categories</option>
          <option value="person">Person</option>
          <option value="car">Car</option>
          <option value="unknown moving object">Unknown</option>
        </select>
      </label>

      <div class="cia-settings-actions">
        <button type="submit" class="cia-btn cia-btn--primary">Save Settings</button>
        <button type="button" class="cia-btn cia-btn--danger" id="cia-stop-all">Emergency STOP ALL</button>
      </div>
    </form>

    <p class="cia-privacy cia-privacy--settings">
      Recordings stored locally only. Max range comes from sensor profile (MR24HPC1 default 5m). Camera classifies objects; radar supplies distance/angle when hardware connected.
    </p>
  </section>
</div>

  </div>
</main>

<?php $activeNav = 'cia'; require __DIR__ . '/wallet-bottom-nav.php'; ?>
<script>
  window.GW_RADAR_API = <?= json_encode($radarApi, JSON_UNESCAPED_SLASHES) ?>;
  window.GW_RADAR_PROXY = 'cia-radar-api.php';
  window.GW_RADAR_MODE = <?= json_encode($radarMode, JSON_UNESCAPED_UNICODE) ?>;
  window.CIA_RADAR_PAGE = 'settings';
</script>
<script src="cia-radar.js?v=<?= urlencode($jsVersion) ?>"></script>
<script src="wallet-shell.js"></script>
</body>
</html>
