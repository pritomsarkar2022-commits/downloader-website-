<?php $brand = (string) cfg('brand.name', 'Video Downloader'); ?>
<footer class="footer">
  <div class="wrap">
    <div class="footer-cols">
      <div>
        <a class="brand" href="./" style="margin-bottom:12px">
          <span class="mark"><svg class="ic"><use href="#ic-play_arrow"/></svg></span>
          <span><?= e($brand) ?></span>
        </a>
        <p class="body-sm" style="max-width:34ch">A video page reader for people who just need the file.</p>
      </div>
      <div>
        <h4>Tool</h4>
        <ul>
          <li><a href="#tool">Downloader</a></li>
          <li><a href="#sites">Supported sites</a></li>
          <li><a href="#how">How it works</a></li>
        </ul>
      </div>
      <div>
        <h4>Company</h4>
        <ul>
          <li><a href="#plans">Plans</a></li>
          <li><a href="#faq">Questions</a></li>
        </ul>
      </div>
      <div>
        <h4>Help</h4>
        <ul>
          <li><a href="#how">How it works</a></li>
          <li><a href="#faq">A link did not work</a></li>
        </ul>
      </div>
    </div>
    <div class="foot-note">
      <span>&copy; <?= date('Y') ?> <?= e($brand) ?></span>
      <span>We do not host any video files. Content belongs to the site you pasted.</span>
    </div>
    <?php $notice = (string) cfg('legal.owner_notice', ''); ?>
    <?php if ($notice !== ''): ?>
      <p class="legal"><?= e($notice) ?></p>
    <?php endif; ?>
  </div>
</footer>
<div class="snackbar-host" id="snackbar-host"></div>
