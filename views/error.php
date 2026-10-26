<?php $fatal = $fatal ?? ''; $notFound = !empty($notFound); ?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="utf-8">
<script>
  (function () {
    try {
      var t = localStorage.getItem('vd_theme');
      if (t !== 'light' && t !== 'dark') {
        t = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
      }
      document.documentElement.setAttribute('data-theme', t);
    } catch (e) {}
  })();
</script>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<title><?= $notFound ? 'Page not found' : 'Something broke' ?></title>
<meta name="robots" content="noindex">
<link rel="stylesheet" href="<?= e(base_href()) ?>assets/css/material.css?v<?= ASSET_VER ?>">
<link rel="stylesheet" href="<?= e(base_href()) ?>assets/css/app.css?v<?= ASSET_VER ?>">
</head>
<body>
<?php require __DIR__ . '/parts/sprite.php'; ?>
<main class="wrap section" style="min-height:70vh;display:grid;place-items:center;text-align:center">
  <div class="stack" style="justify-items:center">
    <svg class="ic" style="font-size:64px;color:var(--md-error)"><use href="#ic-error"/></svg>
    <h1 class="display-sm"><?= $notFound ? 'No page here' : 'That page fell over' ?></h1>
    <p class="body-lg muted" style="max-width:48ch"><?= $notFound ? 'The address you opened is not part of this site. The downloader is on the home page.' : 'The server hit an unexpected error. Try the link again in a moment.' ?></p>
    <?php if ($fatal !== ''): ?>
      <pre class="mono body-sm" style="max-width:100%;overflow:auto;text-align:left;background:var(--md-surface-container);padding:12px;border-radius:var(--r-sm)"><?= htmlspecialchars($fatal, ENT_QUOTES) ?></pre>
    <?php endif; ?>
    <a class="btn btn-filled" href="./">Back to the downloader</a>
  </div>
</main>
</body>
</html>
