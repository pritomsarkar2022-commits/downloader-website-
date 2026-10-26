<?php
/** @var array $view */
$brand = cfg('brand.name', 'Video Downloader');
$tld = cfg('brand.tagline', 'Paste a link. Get the video.');
$lede = cfg('brand.sub_tagline', '');
$metaTitle = (string) cfg('brand.meta_title', $brand . ': paste a video link, get an MP4');
$pageTitle = ($route ?? '') === '' ? $metaTitle : $brand;
?>
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
<title><?= e($pageTitle) ?></title>
<meta name="description" content="<?= e($lede) ?>">
<meta name="theme-color" content="#6750a4" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#141218" media="(prefers-color-scheme: dark)">
<meta name="robots" content="index,follow">
<link rel="canonical" href="<?= e(site_url('/')) ?>">
<meta property="og:site_name" content="<?= e($brand) ?>">
<meta property="og:title" content="<?= e($pageTitle) ?>">
<meta property="og:description" content="<?= e($lede) ?>">
<meta property="og:url" content="<?= e(site_url('/')) ?>">
<meta property="og:type" content="website">
<meta name="twitter:card" content="summary">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&family=Roboto+Mono:wght@400&display=swap" media="print" onload="this.media='all'">
<link rel="stylesheet" href="<?= e(base_href()) ?>assets/css/material.css?v<?= ASSET_VER ?>">
<link rel="stylesheet" href="<?= e(base_href()) ?>assets/css/app.css?v<?= ASSET_VER ?>">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='18' fill='%236750a4'/><path d='M38 30l34 20-34 20z' fill='white'/></svg>">
</head>
<body>
<?php require __DIR__ . '/sprite.php'; ?>
<a class="skip" href="#tool">Skip to the downloader</a>
<?php require __DIR__ . '/nav.php'; ?>
<main id="top">
