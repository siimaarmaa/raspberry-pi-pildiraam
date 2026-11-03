<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';
no_cache_headers();
header('Content-Type: text/html; charset=utf-8');
$settings = settings();
$version = application_version();
$clientSettings = [
    'version' => $version,
    'slideMs' => (int) $settings['slide_ms'],
    'checkMs' => (int) $settings['check_ms'],
    'reloadMs' => (int) $settings['full_reload_ms'],
    'timezone' => (string) $settings['timezone'],
];
?>
<!doctype html>
<html lang="et">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
  <meta name="theme-color" content="#0c1016">
  <title>Pildiraam</title>
  <link rel="stylesheet" href="assets/slideshow.css?v=<?= rawurlencode($version) ?>">
</head>
<body>
  <img class="photo" alt="">
  <img class="photo" alt="">
  <div class="shade" aria-hidden="true"></div>
  <div class="top">
    <div id="clock"></div>
    <div id="date"></div>
  </div>
  <div class="bottom">
    <span id="photo-name"></span>
    <span id="photo-count"></span>
  </div>
  <div class="progress"><div id="progress-bar"></div></div>
  <div id="empty">
    <strong>Ootan fotosid …</strong>
    <small>Lisa fotod kausta /home/saarmaa/pildid või ava telefonis /pildiraam/upload.php</small>
  </div>
  <script>window.PILDIRAAM = <?= json_encode($clientSettings, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
  <script src="assets/slideshow.js?v=<?= rawurlencode($version) ?>" defer></script>
</body>
</html>
