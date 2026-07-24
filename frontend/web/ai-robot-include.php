<?php

declare(strict_types=1);

$gwRobotCssV = (string) (@filemtime(__DIR__ . '/ai-robot.css') ?: time());
$gwRobotJsV = (string) (@filemtime(__DIR__ . '/ai-robot.js') ?: time());
$gwRobotImgV = (string) (@filemtime(__DIR__ . '/images/agent-robot.png') ?: time());
$gwRobotCssV = htmlspecialchars($gwRobotCssV, ENT_QUOTES);
$gwRobotJsV = htmlspecialchars($gwRobotJsV, ENT_QUOTES);
$gwRobotImgV = htmlspecialchars($gwRobotImgV, ENT_QUOTES);
?>
<link rel="stylesheet" href="ai-robot.css?v=<?php echo $gwRobotCssV; ?>" />
<script>
  window.GW_ROBOT_ASSET_V = "<?php echo $gwRobotJsV; ?>";
  window.GW_ROBOT_IMG_V = "<?php echo $gwRobotImgV; ?>";
</script>
<script src="ai-robot.js?v=<?php echo $gwRobotJsV; ?>"></script>
