<?php
/** Supported TZ mobile networks — real brand logos. */
$gwNetBase = 'images/networks';
$gwNetFiles = ['vodacom.png', 'airtel.svg', 'yas.svg', 'halotel.svg'];
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
      <!-- Inline SVG so Halotel never shows as broken image if a PNG path 404s -->
      <span class="gw-net-ico gw-net-ico--inline" aria-hidden="true">
        <svg viewBox="0 0 64 64" width="30" height="30" focusable="false">
          <rect width="64" height="64" rx="12" fill="#fff"/>
          <g fill="#F15A22">
            <circle cx="26" cy="33" r="11"/>
            <path d="M36.5 16.5c8.2 5.2 13.2 14.2 12.6 24.2-.5 8.8-6.2 16.4-14.4 19.6 9.6-3.6 16.2-12.8 16.2-23.4 0-7.4-3.2-14.1-8.4-18.8-.7-.6-1.4-1.2-2.2-1.6-.5-.3-1.1-.5-1.6-.5-.4 0-.8.2-1.1.5-.5.5-.5 1.2.1 1.7.5.4 1 .8 1.4 1.2 4.6 4.2 7.2 10.2 7.2 16.8 0 8.6-5.2 16-12.8 19.1 5.4-3.2 9-9.2 9-16.2 0-7.4-3.8-13.9-9.6-17.6z"/>
          </g>
        </svg>
      </span>
      <span class="gw-net-name">Halotel</span>
    </li>
  </ul>
</div>
