<?php
/** Supported TZ mobile networks — real brand logos. */
$gwNetBase = 'images/networks';
$gwNetFiles = ['vodacom.png', 'airtel.svg', 'yas.svg', 'halotel-mark.png'];
$gwNetV = 0;
foreach ($gwNetFiles as $gwNetFile) {
    $mt = @filemtime(__DIR__ . '/' . $gwNetBase . '/' . $gwNetFile);
    if ($mt && $mt > $gwNetV) {
        $gwNetV = $mt;
    }
}
if ($gwNetV < 1) {
    $gwNetV = time();
}
$gwNetV = (string) $gwNetV;
?>
<div class="gw-network-strip" aria-label="Supported mobile networks">
  <span class="gw-network-strip__label">Inaruhusu:</span>
  <ul class="gw-network-strip__list">
    <li title="Vodacom M-Pesa">
      <img class="gw-net-ico" src="<?= htmlspecialchars($gwNetBase, ENT_QUOTES) ?>/vodacom.png?v=<?= urlencode($gwNetV) ?>" width="32" height="32" alt="Vodacom" loading="lazy" decoding="async" />
      <span class="gw-net-name">Vodacom</span>
    </li>
    <li title="Airtel Money">
      <img class="gw-net-ico gw-net-ico--airtel" src="<?= htmlspecialchars($gwNetBase, ENT_QUOTES) ?>/airtel.svg?v=<?= urlencode($gwNetV) ?>" width="32" height="32" alt="Airtel" loading="lazy" decoding="async" />
      <span class="gw-net-name">Airtel</span>
    </li>
    <li title="Yas (Mixx by Yas)">
      <img class="gw-net-ico" src="<?= htmlspecialchars($gwNetBase, ENT_QUOTES) ?>/yas.svg?v=<?= urlencode($gwNetV) ?>" width="32" height="32" alt="Yas" loading="lazy" decoding="async" />
      <span class="gw-net-name">Yas</span>
    </li>
    <li title="Halotel HaloPesa">
      <img class="gw-net-ico" src="<?= htmlspecialchars($gwNetBase, ENT_QUOTES) ?>/halotel-mark.png?v=<?= urlencode($gwNetV) ?>" width="32" height="32" alt="Halotel" loading="lazy" decoding="async" />
      <span class="gw-net-name">Halotel</span>
    </li>
  </ul>
</div>
