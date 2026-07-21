<?php
declare(strict_types=1);
/** Language picker with country flags (EN / SW) for mobile banking auth pages. */
?>
<div class="mb-head-right">
  <div class="mb-lang-wrap">
    <span class="mb-sep" aria-hidden="true"></span>
    <div class="mb-lang" data-mb-lang>
      <button type="button" class="mb-lang-toggle" data-mb-lang-toggle aria-expanded="false" aria-haspopup="listbox" aria-label="Choose language">
        <img class="mb-flag-img" data-mb-lang-flag src="images/flag-gb.svg?v=1" width="40" height="20" alt="" />
        <i class="fa-solid fa-chevron-down mb-lang-chevron" aria-hidden="true"></i>
      </button>
      <div class="mb-lang-menu" data-mb-lang-menu role="listbox" hidden>
        <button type="button" class="mb-lang-option" data-mb-lang-option="en" role="option">
          <img class="mb-flag-img mb-flag-img--sm" src="images/flag-gb.svg?v=1" width="32" height="16" alt="" />
          <span>English</span>
        </button>
        <button type="button" class="mb-lang-option" data-mb-lang-option="sw" role="option">
          <img class="mb-flag-img mb-flag-img--sm" src="images/flag-tz.svg?v=4" width="32" height="21" alt="" />
          <span>Kiswahili</span>
        </button>
      </div>
    </div>
  </div>
</div>
