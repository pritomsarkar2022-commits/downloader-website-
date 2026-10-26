<?php $brand = cfg('brand.name', 'Video Downloader'); ?>
<header class="appbar" id="appbar">
  <a class="brand" href="./" aria-label="<?= e($brand) ?> home">
    <span class="mark"><svg class="ic"><use href="#ic-play_arrow"/></svg></span>
    <span><?= e($brand) ?></span>
  </a>
  <nav class="row-flex" aria-label="Main" style="margin-left:auto">
    <a class="nav-link hide-sm" href="#how">How it works</a>
    <a class="nav-link hide-sm" href="#sites">Supported sites</a>
    <a class="nav-link hide-sm" href="#plans">Plans</a>
    <a class="nav-link hide-sm" href="#faq">FAQ</a>
    <button class="btn-icon" id="theme-toggle" type="button" aria-label="Change theme">
      <svg class="ic" id="theme-icon"><use href="#ic-dark_mode"/></svg>
    </button>
    <a class="btn btn-filled btn-sm" href="#tool" style="margin-left:4px">Get a file</a>
  </nav>
</header>
