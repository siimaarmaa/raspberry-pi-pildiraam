<?php
// Pildiraam: eraldi varasid, API-faile ega JavaScripti faile pole vaja.
$photoDir = getenv('PILDIRAAM_PILDID') ?: '/home/saarmaa/pildid';
$cacheDir = getenv('PILDIRAAM_CACHE') ?: '/var/cache/pildiraam';
$extensions = ['jpg', 'jpeg', 'png', 'webp'];

function photoList(string $dir, array $extensions): array {
    if (!is_dir($dir)) return [];
    $result = [];
    foreach (scandir($dir) ?: [] as $name) {
        $path = $dir . '/' . $name;
        if (!in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), $extensions, true)) continue;
        if (!is_file($path) || is_link($path)) continue;
        $result[] = ['name' => $name, 'rev' => (string) (@filemtime($path) ?: 0) . '-' . (string) (@filesize($path) ?: 0)];
    }
    usort($result, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
    return $result;
}

if (isset($_GET['list'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode([
        'version' => hash_file('sha256', __FILE__),
        'photos' => photoList($photoDir, $extensions),
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (isset($_GET['photo'])) {
    $name = $_GET['photo'];
    if (!is_string($name) || $name !== basename($name) || str_contains($name, "\\")) {
        http_response_code(400); exit;
    }
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $base = realpath($photoDir);
    $path = realpath($photoDir . '/' . $name);
    if (!$base || !$path || !str_starts_with($path, $base . DIRECTORY_SEPARATOR)
        || !is_file($path) || !in_array($ext, $extensions, true)) {
        http_response_code(404); exit;
    }
    if (!extension_loaded('gd')) {
        http_response_code(500); echo 'PHP GD moodul puudub'; exit;
    }
    if (!is_dir($cacheDir) || !is_writable($cacheDir)) {
        http_response_code(500); exit('Piltide vahemälu kaust ei ole kirjutatav.');
    }
    $cache = $cacheDir . '/' . sha1($name . '|' . filemtime($path) . '|' . filesize($path)) . '.jpg';
    if (!is_file($cache)) {
        $dimensions = @getimagesize($path);
        if (!$dimensions || $dimensions[0] * $dimensions[1] > 20000000) {
            http_response_code(415); exit('Vigane või liiga suur foto (üle 20 MP).');
        }
        [$w, $h] = $dimensions;
        $open = match ($ext) {
            'jpg', 'jpeg' => 'imagecreatefromjpeg',
            'png' => 'imagecreatefrompng',
            'webp' => 'imagecreatefromwebp',
        };
        $source = function_exists($open) ? @$open($path) : false;
        if (!$source) { http_response_code(415); exit; }
        $scale = min(1, 1280 / $w, 1280 / $h);
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $target = imagecreatetruecolor($nw, $nh);
        imagefill($target, 0, 0, imagecolorallocate($target, 12, 16, 22));
        imagecopyresampled($target, $source, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($source);
        // Telefoni JPEG-fotode EXIF-pöörang (tehakse pärast vähendamist).
        if (in_array($ext, ['jpg', 'jpeg'], true) && function_exists('exif_read_data')) {
            $exif = @exif_read_data($path);
            $angle = match ((int) ($exif['Orientation'] ?? 1)) { 3 => 180, 6 => 270, 8 => 90, default => 0 };
            if ($angle) {
                $rotated = imagerotate($target, $angle, 0);
                if ($rotated) { imagedestroy($target); $target = $rotated; }
            }
        }
        imageinterlace($target, true);
        $temp = tempnam($cacheDir, 'photo_');
        if ($temp === false || !imagejpeg($target, $temp, 84) || !rename($temp, $cache)) {
            imagedestroy($target);
            if ($temp !== false) @unlink($temp);
            http_response_code(500); exit('Pildi vahemällu salvestamine ebaõnnestus.');
        }
        imagedestroy($target);
    }
    header('X-Content-Type-Options: nosniff');
    header('Content-Type: image/jpeg');
    header('Cache-Control: public, max-age=86400');
    readfile($cache);
    exit;
}
header('Cache-Control: no-store');
?><!doctype html>
<html lang="et">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Pildiraam</title>
<style>
  :root { color-scheme: dark; }
  * { box-sizing: border-box; }
  html, body { margin: 0; width: 100%; height: 100%; overflow: hidden; background: #0c1016; font: 16px system-ui, sans-serif; color: white; }
  .photo { position: fixed; inset: 0; width: 100%; height: 100%; object-fit: contain; opacity: 0; transition: opacity 1.25s ease-in-out; }
  .photo.visible { opacity: 1; }
  .shade { position: fixed; inset: 0; pointer-events: none; background: linear-gradient(180deg, rgba(0,0,0,.28), transparent 22%, transparent 73%, rgba(0,0,0,.5)); }
  .top { position: fixed; top: 26px; left: 26px; text-shadow: 0 2px 9px #0008; }
  #time { font-size: 38px; font-weight: 300; letter-spacing: .025em; line-height: 1.1; }
  #date { margin-top: 5px; font-size: 13px; opacity: .85; text-transform: capitalize; }
  .bottom { position: fixed; bottom: 28px; left: 26px; right: 26px; display: flex; justify-content: space-between; align-items: baseline; gap: 16px; font-size: 13px; text-shadow: 0 2px 9px #000a; }
  #name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; opacity: .88; }
  #count { white-space: nowrap; opacity: .75; font-variant-numeric: tabular-nums; }
  .progress { position: fixed; bottom: 0; left: 0; width: 100%; height: 3px; background: #ffffff22; }
  #bar { height: 100%; width: 0; background: #e8e2d5; }
  #bar.run { animation: fill 12s linear forwards; }
  @keyframes fill { from { width: 0; } to { width: 100%; } }
  #empty { position: fixed; inset: 0; display: grid; place-content: center; text-align: center; padding: 40px; line-height: 1.7; color: #ccd5dd; }
  #empty[hidden] { display: none; }
  #empty strong { font-size: 24px; font-weight: 400; }
  #empty small { font-size: 14px; opacity: .75; }
</style>
</head>
<body>
<img class="photo" alt=""><img class="photo" alt="">
<div class="shade"></div>
<div class="top"><div id="time"></div><div id="date"></div></div>
<div class="bottom"><span id="name"></span><span id="count"></span></div>
<div class="progress"><div id="bar"></div></div>
<div id="empty"><strong>Ootan pilte …</strong><small>Lisa fotod kausta /home/saarmaa/pildid</small></div>
<script>
const delay = 12000; // Pildi vahetus iga 12 sekundi järel.
const pageVersion = <?= json_encode(hash_file('sha256', __FILE__)) ?>;
const layers = [...document.querySelectorAll('.photo')];
const bar = document.querySelector('#bar');
const empty = document.querySelector('#empty');
let photos = [], current = -1, shown = 0, busy = false;

function clock() {
  const now = new Date();
  document.querySelector('#time').textContent = now.toLocaleTimeString('et-EE', {hour:'2-digit', minute:'2-digit'});
  document.querySelector('#date').textContent = now.toLocaleDateString('et-EE', {weekday:'long', day:'numeric', month:'long'});
}
clock(); setInterval(clock, 15000);

function show(index) {
  if (busy || !photos.length) return;
  busy = true;
  index = (index + photos.length) % photos.length;
  const item = photos[index];
  const incoming = layers[1 - shown];
  incoming.onload = () => {
    layers[shown].classList.remove('visible');
    incoming.classList.add('visible');
    shown = 1 - shown; current = index; busy = false;
    empty.hidden = true;
    document.querySelector('#name').textContent = item.name.replace(/\.[^.]+$/, '').replace(/[_-]/g, ' ');
    document.querySelector('#count').textContent = `${current + 1} / ${photos.length}`;
    bar.classList.remove('run'); void bar.offsetWidth; bar.classList.add('run');
  };
  incoming.onerror = () => {
    busy = false; console.error('Pilti ei saanud avada:', item.name);
    // Jätkame järgmiste piltidega ka siis, kui mõni fail on vigane.
  };
  incoming.src = '?photo=' + encodeURIComponent(item.name) + '&v=' + encodeURIComponent(item.rev);
}

function next() { show(current + 1); }

async function reload() {
  try {
    const response = await fetch('?list=1', {cache: 'no-store'});
    if (!response.ok) throw new Error('HTTP ' + response.status);
    const previous = photos[current];
    const data = await response.json();
    if (data.version !== pageVersion) {
      location.replace('index.php?updated=' + Date.now());
      return;
    }
    photos = data.photos;
    current = photos.findIndex(p => p.name === previous?.name);
    const replaced = current !== -1 && previous && photos[current].rev !== previous.rev;
    if (!photos.length) {
      layers.forEach(img => img.classList.remove('visible'));
      document.querySelector('#name').textContent = '';
      document.querySelector('#count').textContent = '';
      empty.hidden = false; current = -1;
    } else if (current === -1 || replaced) {
      show(current === -1 ? 0 : current);
    } else {
      document.querySelector('#count').textContent = `${current + 1} / ${photos.length}`;
    }
  } catch (error) { console.error('Piltide nimekirja viga:', error); }
}
reload();
setInterval(next, delay);
setInterval(reload, 30000); // Uued pildid ja PHP faili muudatused.
setInterval(() => location.reload(), 30 * 60 * 1000); // Varuvärskendus.
</script>
</body>
</html>
