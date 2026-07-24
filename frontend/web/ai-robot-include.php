<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    require_once __DIR__ . '/auth-init.php';
    gwAuthStartSession();
}

$gwRobotCssV = (string) (@filemtime(__DIR__ . '/ai-robot.css') ?: time());
$gwRobotJsV = (string) (@filemtime(__DIR__ . '/ai-robot.js') ?: time());
$gwRobotImgV = (string) (@filemtime(__DIR__ . '/images/agent-robot.png') ?: time());
$gwRobotCssV = htmlspecialchars($gwRobotCssV, ENT_QUOTES);
$gwRobotJsV = htmlspecialchars($gwRobotJsV, ENT_QUOTES);
$gwRobotImgV = htmlspecialchars($gwRobotImgV, ENT_QUOTES);
$gwRobotShowCia = strtolower((string) (($_SESSION['gw_auth_user']['role'] ?? '') ?: '')) === 'admin';
?>
<link rel="stylesheet" href="ai-robot.css?v=<?php echo $gwRobotCssV; ?>" />
<script>
  window.GW_ROBOT_ASSET_V = "<?php echo $gwRobotJsV; ?>";
  window.GW_ROBOT_IMG_V = "<?php echo $gwRobotImgV; ?>";
  window.GW_ROBOT_SHOW_CIA = <?php echo $gwRobotShowCia ? 'true' : 'false'; ?>;
</script>
<script src="ai-robot.js?v=<?php echo $gwRobotJsV; ?>"></script>
