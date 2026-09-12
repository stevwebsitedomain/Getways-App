<?php
/** Supported TZ mobile networks strip for payment phone fields. */
$gwNetV = (string) (@filemtime(__DIR__ . '/images/networks/yas.svg') ?: time());
?>
<div class="gw-network-strip" aria-label="Supported mobile networks">
  <span class="gw-network-strip__label">Inaruhusu:</span>
  <ul class="gw-network-strip__list">
    <li>
      <img src="images/networks/mpesa.png?v=<?= urlencode($gwNetV) ?>" width="28" height="28" alt="Vodacom" title="Vodacom M-Pesa" loading="lazy" />
    </li>
    <li>
      <img src="images/networks/airtel.svg?v=<?= urlencode($gwNetV) ?>" width="28" height="28" alt="Airtel" title="Airtel Money" loading="lazy" />
    </li>
    <li>
      <img src="images/networks/yas.svg?v=<?= urlencode($gwNetV) ?>" width="28" height="28" alt="Yas" title="Yas (Tigo)" loading="lazy" />
    </li>
    <li>
      <img src="images/networks/halotel.svg?v=<?= urlencode($gwNetV) ?>" width="28" height="28" alt="Halotel" title="HaloPesa" loading="lazy" />
    </li>
  </ul>
</div>
