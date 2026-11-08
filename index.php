<?php

declare(strict_types=1);

require __DIR__ . '/common.php';

$config = pildiraamConfig();
$photoDir = $config['photoDir'];
$cacheDir = $config['cacheDir'];
$extensions = $config['extensions'];
$slideSeconds = max(3, (int) $config['slideSeconds']);
$weather = $config['weather'];

$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

$h = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

// Nimekirja päring. Anname veateate ka siis, kui Apache ei pääse kaustale ligi.
if (isset($_GET['list'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    $error = null;

    if (!is_dir($photoDir)) {
        $error = 'Pildikausta ei leitud või puudub sellele ligipääs: ' . $photoDir;
    } elseif (!is_readable($photoDir)) {
        $error = 'Veebiserver ei saa pildikausta lugeda: ' . $photoDir;
    }

    $photos = $error ? [] : photoList($photoDir, $extensions);

    if (!$error) {
        // Koristab vahemälust eelvaated, mille fotot enam pole (korra tunnis).
        pruneCache($cacheDir, $photos);
    }

    echo json_encode([
        'version' => pageVersion(),
        'photos' => $photos,
        'error' => $error,
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

// Originaali näitame siis, kui pildi töötlemine Pi jaoks liiga kulukas on.
function serveOriginalPhoto(string $path, string $mime): never
{
    header('X-Content-Type-Options: nosniff');
    header('Content-Type: ' . $mime);
    header('Cache-Control: public, max-age=86400');
    readfile($path);
    exit;
}

/**
 * Teeb väiksema JPEG-koopia ja salvestab selle vahemällu.
 * Annab false, kui töötlemine ebaõnnestub (siis näidatakse originaali).
 */
function buildPreview(
    string $path,
    string $mime,
    string $ext,
    int $width,
    int $height,
    int $maxSide,
    string $cacheDir,
    string $cache
): bool {
    // Kasutame faili tegelikku vormingut, mitte ainult nime laiendit.
    $reader = match ($mime) {
        'image/jpeg' => 'imagecreatefromjpeg',
        'image/png' => 'imagecreatefrompng',
        'image/webp' => 'imagecreatefromwebp',
        default => '',
    };

    // Kõiki JPEG- ja WebP-variante ei pruugi paigaldatud GD toetada.
    if ($reader === '' || !function_exists($reader)) {
        return false;
    }

    $source = @$reader($path);
    if (!$source instanceof GdImage) {
        return false;
    }

    $scale = min(1, $maxSide / $width, $maxSide / $height);
    $newWidth = max(1, (int) round($width * $scale));
    $newHeight = max(1, (int) round($height * $scale));

    $image = imagecreatetruecolor($newWidth, $newHeight);
    if (!$image instanceof GdImage) {
        return false;
    }

    imagefill($image, 0, 0, (int) imagecolorallocate($image, 12, 16, 22));
    imagecopyresampled($image, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
    unset($source);

    // Telefoniga tehtud fotodel võib pööramisinfo olla EXIF-is.
    $orientation = exifOrientation($path, $ext);

    $flip = orientationFlip($orientation);
    if ($flip !== null) {
        imageflip($image, $flip);
    }

    $angle = orientationAngle($orientation);
    if ($angle !== 0) {
        $turned = turnImage($image, $angle);
        if ($turned !== false) {
            $image = $turned;
        }
    }

    imageinterlace($image, true);

    // Esmalt ajutine fail, siis ümbernimetamine: poolik pilt ei jõua vahemällu.
    $temp = tempnam($cacheDir, 'photo_');
    if ($temp === false) {
        return false;
    }

    $quality = $maxSide <= 400 ? 78 : 84;

    if (!imagejpeg($image, $temp, $quality) || !rename($temp, $cache)) {
        @unlink($temp);
        return false;
    }

    return true;
}

// ?photo=fail.jpg annab väiksemaks tehtud JPEG-pildi, lisaga &size=thumb pisipildi.
if (isset($_GET['photo'])) {
    $name = $_GET['photo'];
    $path = findPhoto($photoDir, $name, $extensions);

    // Pilte tohib lugeda ainult määratud fotokaustast.
    if ($path === null) {
        http_response_code(404);
        exit;
    }

    $name = (string) $name;
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $maxSide = ($_GET['size'] ?? '') === 'thumb' ? 400 : 1280;

    // Mõõtmed ja failitüüp kontrollime enne pildi töötlemist.
    $details = @getimagesize($path);
    $mime = is_array($details) ? ($details['mime'] ?? '') : '';

    if (
        !$details || $details[0] < 1 || $details[1] < 1 ||
        !in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)
    ) {
        http_response_code(415);
        exit('Fail ei ole toetatud või on vigane pilt.');
    }

    [$width, $height] = $details;

    // Suurt fotot ei töötle GD-ga: Pi võib muidu mälust tühjaks saada.
    // Sama varuvariant aitab puuduva GD või kirjutuskaitstud vahemälu korral.
    if (
        $width * $height > 20000000 ||
        !extension_loaded('gd') ||
        !is_dir($cacheDir) ||
        !is_writable($cacheDir)
    ) {
        serveOriginalPhoto($path, $mime);
    }

    $cache = previewCachePath($cacheDir, $name, (int) filemtime($path), (int) filesize($path), $maxSide);

    if (!is_file($cache) || filesize($cache) === 0) {
        // Korraga töötleb ühe suuruse pilte ainult üks päring. Nii ei tee halduslehe
        // paljud pisipildid Pi mälu täis ega aeglusta slaidiesitlust.
        $lock = @fopen($cacheDir . '/.lock-' . $maxSide, 'c');
        if ($lock) {
            flock($lock, LOCK_EX);
        }

        // Võib-olla tegi teine päring selle vahepeal valmis.
        clearstatcache(true, $cache);
        $ready = is_file($cache) && filesize($cache) > 0;

        if (!$ready) {
            $ready = buildPreview($path, $mime, $ext, $width, $height, $maxSide, $cacheDir, $cache);
        }

        if ($lock) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        if (!$ready) {
            serveOriginalPhoto($path, $mime);
        }
    }

    header('X-Content-Type-Options: nosniff');
    header('Content-Type: image/jpeg');
    header('Cache-Control: public, max-age=86400');
    readfile($cache);
    exit;
}

header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="et">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Pildiraam</title>
<style>
:root { color-scheme: dark; --slide: <?= $slideSeconds ?>s; }
* { box-sizing: border-box; }
html, body {
  width: 100%;
  height: 100%;
  margin: 0;
  overflow: hidden;
  background: #0c1016;
  color: white;
  font: 16px system-ui, sans-serif;
}
.photo {
  position: fixed;
  inset: 0;
  width: 100%;
  height: 100%;
  object-fit: contain;
  opacity: 0;
  transition: opacity 1.25s ease-in-out;
}
.photo.visible { opacity: 1; }
.shade {
  position: fixed;
  inset: 0;
  pointer-events: none;
  background: linear-gradient(180deg, rgba(0, 0, 0, .28), transparent 22%, transparent 73%, rgba(0, 0, 0, .5));
}
/* Kell ja kuupäev vasakus ülanurgas. */
.top {
  position: fixed;
  top: 26px;
  left: 26px;
  z-index: 2;
  text-shadow: 0 2px 9px #0008;
}
#time {
  display: table;
  padding: 8px 12px;
  border-radius: 7px;
  background: #000;
  font-size: 38px;
  font-weight: 300;
  letter-spacing: .025em;
  line-height: 1.1;
  text-shadow: none;
}
#date {
  display: table;
  margin-top: 5px;
  padding: 6px 10px;
  border-radius: 5px;
  background: #000;
  color: #fff;
  font-size: 17px;
  text-transform: capitalize;
  text-shadow: none;
}
/* Ilm paremas ülanurgas. Ikoonid on SVG, seega ei sõltu need paigaldatud fontidest. */
.weather {
  position: fixed;
  top: 26px;
  right: 26px;
  z-index: 2;
  min-width: 160px;
  max-width: 45vw;
  padding: 10px 14px;
  border-radius: 9px;
  background: rgba(0, 0, 0, .85);
  text-align: right;
  font-variant-numeric: tabular-nums;
}
.weather-place { font-size: 16px; font-weight: 600; opacity: .9; }
.weather-main { display: flex; justify-content: flex-end; align-items: center; gap: 9px; margin-top: 2px; }
#weather-icon { display: block; width: 42px; height: 42px; flex: none; }
#weather-icon svg { display: block; width: 100%; height: 100%; }
#weather-temp { font-size: 36px; font-weight: 300; line-height: 1.2; }
#weather-desc { font-size: 16px; }
#weather-details { margin-top: 3px; font-size: 14px; opacity: .78; }
.bottom {
  position: fixed;
  bottom: 28px;
  left: 26px;
  right: 26px;
  display: flex;
  justify-content: flex-end;
  align-items: baseline;
  font-size: 13px;
  text-shadow: 0 2px 9px #000a;
}
#count { white-space: nowrap; opacity: .75; font-variant-numeric: tabular-nums; }
.progress {
  position: fixed;
  bottom: 0;
  left: 0;
  width: 100%;
  height: 3px;
  background: #ffffff22;
}
#bar { height: 100%; width: 0; background: #e8e2d5; }
#bar.run { animation: fill var(--slide) linear forwards; }
@keyframes fill {
  from { width: 0; }
  to { width: 100%; }
}
#empty {
  position: fixed;
  inset: 0;
  display: grid;
  place-content: center;
  padding: 40px;
  color: #ccd5dd;
  line-height: 1.7;
  text-align: center;
}
#empty[hidden] { display: none; }
#empty strong { font-size: 24px; font-weight: 400; }
#empty small { font-size: 14px; opacity: .75; }
@media (max-width: 520px) {
  .top { top: 14px; left: 14px; }
  #time { font-size: 30px; padding: 7px 10px; }
  #date { font-size: 14px; padding: 6px 8px; }
  .weather {
    top: 14px;
    right: 14px;
    min-width: 0;
    max-width: 48vw;
    padding: 8px 10px;
  }
  .weather-place, #weather-desc { font-size: 14px; }
  #weather-icon { width: 32px; height: 32px; }
  #weather-temp { font-size: 29px; }
  #weather-details { font-size: 12px; }
}
</style>
</head>
<body>
<!-- Kaks kihti võimaldavad fotode vahel sujuvalt vahetada. -->
<img class="photo" alt="">
<img class="photo" alt="">
<div class="shade"></div>

<div class="top">
  <div id="time"></div>
  <div id="date"></div>
</div>

<?php if (!empty($weather['enabled'])): ?>
<div class="weather" aria-label="<?= $h('Ilm: ' . (string) $weather['place']) ?>">
  <div class="weather-place"><?= $h((string) $weather['place']) ?></div>
  <div class="weather-main">
    <span id="weather-icon" aria-hidden="true"></span>
    <span id="weather-temp">–</span>
  </div>
  <div id="weather-desc">Ilma laadimine…</div>
  <div id="weather-details"></div>
</div>
<?php endif; ?>

<div class="bottom">
  <span id="count"></span>
</div>
<div class="progress"><div id="bar"></div></div>

<div id="empty">
  <strong>Ootan pilte …</strong>
  <small id="empty-detail">Lisa fotod kausta <?= $h($photoDir) ?></small>
</div>

<script>
const delay = <?= $slideSeconds * 1000 ?>; // Foto vahetub iga N sekundi järel (seadistus: common.php).
const pageVersion = <?= json_encode(pageVersion(), $jsonFlags) ?>;
const photoDirectory = <?= json_encode($photoDir, $jsonFlags) ?>;
const weatherEnabled = <?= json_encode(!empty($weather['enabled'])) ?>;
const weatherLat = <?= json_encode((float) $weather['latitude']) ?>;
const weatherLon = <?= json_encode((float) $weather['longitude']) ?>;

const layers = [...document.querySelectorAll('.photo')];
const bar = document.querySelector('#bar');
const empty = document.querySelector('#empty');
const emptyTitle = empty.querySelector('strong');
const emptyDetail = document.querySelector('#empty-detail');
const countEl = document.querySelector('#count');

const STORAGE_KEY = 'pildiraam:last';
const LOAD_TIMEOUT = 25000;

let photos = [];
let current = -1;
let shown = 0;
let busy = false;
let preloader = null;

function clock() {
  const now = new Date();
  const options = { timeZone: 'Europe/Tallinn' };
  document.querySelector('#time').textContent = now.toLocaleTimeString('et-EE', {
    ...options, hour: '2-digit', minute: '2-digit'
  });
  document.querySelector('#date').textContent = now.toLocaleDateString('et-EE', {
    ...options, weekday: 'long', day: 'numeric', month: 'long'
  });
}
clock();
setInterval(clock, 15000);

/* ---------- Ilm ---------- */

// Ikoonid on SVG-d, mitte emoji: Raspberry Pi OS Lite'is pole värvilist emoji-fonti
// ja emoji asemel näidatakse muidu ruute.
const svgIcon = inner =>
  `<svg viewBox="0 0 48 48" xmlns="http://www.w3.org/2000/svg" focusable="false">${inner}</svg>`;

function sunShape(cx, cy, r) {
  let rays = '';
  for (let i = 0; i < 8; i++) {
    const a = i * Math.PI / 4;
    const c = Math.cos(a);
    const s = Math.sin(a);
    rays += `<line x1="${(cx + c * (r + 4)).toFixed(1)}" y1="${(cy + s * (r + 4)).toFixed(1)}"`
      + ` x2="${(cx + c * (r + 8)).toFixed(1)}" y2="${(cy + s * (r + 8)).toFixed(1)}"/>`;
  }
  return `<g fill="#ffc933" stroke="#ffc933" stroke-width="3" stroke-linecap="round">`
    + `<circle cx="${cx}" cy="${cy}" r="${r}" stroke="none"/>${rays}</g>`;
}

function moonShape(cx, cy, r) {
  return `<defs><mask id="moon-mask"><rect width="48" height="48" fill="#fff"/>`
    + `<circle cx="${cx + r * 0.55}" cy="${cy - r * 0.45}" r="${r * 0.8}" fill="#000"/></mask></defs>`
    + `<circle cx="${cx}" cy="${cy}" r="${r}" fill="#f3e9b5" mask="url(#moon-mask)"/>`;
}

function cloudShape(dx, dy, fill) {
  return `<g transform="translate(${dx} ${dy})" fill="${fill}">`
    + `<circle cx="17" cy="29" r="7"/><circle cx="27" cy="23" r="10"/>`
    + `<circle cx="36" cy="30" r="6"/><rect x="17" y="29" width="19" height="7"/></g>`;
}

function weatherIcon(kind, isDay) {
  const cloud = '#d5dde7';
  const drops = (extra) => `<g stroke="#6ab0ff" stroke-width="3" stroke-linecap="round">${extra}</g>`;

  switch (kind) {
    case 'clear':
      return svgIcon(isDay ? sunShape(24, 24, 9) : moonShape(22, 26, 14));
    case 'partly':
      return svgIcon((isDay ? sunShape(18, 17, 5) : moonShape(17, 17, 9)) + cloudShape(4, 6, cloud));
    case 'cloud':
      return svgIcon(cloudShape(0, 0, cloud));
    case 'fog':
      return svgIcon(cloudShape(0, -6, '#c3ccd6')
        + `<g stroke="#c3ccd6" stroke-width="3" stroke-linecap="round">`
        + `<line x1="9" y1="35" x2="37" y2="35"/><line x1="14" y1="41" x2="42" y2="41"/></g>`);
    case 'drizzle':
      return svgIcon(cloudShape(0, -6, cloud)
        + drops(`<line x1="16" y1="35" x2="15" y2="38"/><line x1="25" y1="38" x2="24" y2="41"/>`
          + `<line x1="34" y1="35" x2="33" y2="38"/>`));
    case 'rain':
      return svgIcon(cloudShape(0, -6, cloud)
        + drops(`<line x1="16" y1="34" x2="13" y2="42"/><line x1="25" y1="34" x2="22" y2="42"/>`
          + `<line x1="34" y1="34" x2="31" y2="42"/>`));
    case 'snow':
      return svgIcon(cloudShape(0, -6, cloud)
        + `<g fill="#fff"><circle cx="16" cy="36" r="2.6"/><circle cx="25" cy="41" r="2.6"/>`
        + `<circle cx="34" cy="36" r="2.6"/></g>`);
    case 'thunder':
      return svgIcon(cloudShape(0, -6, '#a9b5c3')
        + `<polygon fill="#ffd23f" points="26,27 18,38 24,38 21,45 31,32 25,32 29,27"/>`);
    default:
      return svgIcon(`<rect x="21" y="8" width="6" height="22" rx="3" fill="#dfe7ef"/>`
        + `<circle cx="24" cy="34" r="7" fill="#ff6b6b"/><rect x="22.5" y="18" width="3" height="16" fill="#ff6b6b"/>`);
  }
}

function weatherCondition(code) {
  if (code === 0) return ['clear', 'Selge'];
  if (code === 1) return ['partly', 'Peamiselt selge'];
  if (code === 2) return ['partly', 'Vahelduv pilvisus'];
  if (code === 3) return ['cloud', 'Pilves'];
  if ([45, 48].includes(code)) return ['fog', 'Udu'];
  if ([51, 53, 55, 56, 57].includes(code)) return ['drizzle', 'Uduvihm'];
  if ([61, 63, 65, 66, 67, 80, 81, 82].includes(code)) return ['rain', 'Vihm'];
  if ([71, 73, 75, 77, 85, 86].includes(code)) return ['snow', 'Lumesadu'];
  if ([95, 96, 99].includes(code)) return ['thunder', 'Äike'];
  return ['unknown', 'Ilm'];
}

let weatherLoaded = false;

async function updateWeather() {
  try {
    const url = 'https://api.open-meteo.com/v1/forecast'
      + `?latitude=${weatherLat}&longitude=${weatherLon}`
      + '&current=temperature_2m,apparent_temperature,weather_code,is_day,wind_speed_10m'
      + '&timezone=Europe%2FTallinn&wind_speed_unit=ms';

    const response = await fetch(url, { cache: 'no-store' });
    if (!response.ok) {
      throw new Error('HTTP ' + response.status);
    }

    const data = await response.json();
    const now = data.current;
    if (!now || !Number.isFinite(now.temperature_2m)) {
      throw new Error('Ilmaandmed puuduvad');
    }

    const [kind, description] = weatherCondition(now.weather_code);
    document.querySelector('#weather-icon').innerHTML = weatherIcon(kind, now.is_day === 1);
    document.querySelector('#weather-temp').textContent = `${Math.round(now.temperature_2m)}°C`;
    document.querySelector('#weather-desc').textContent = description;

    const details = [];
    if (Number.isFinite(now.wind_speed_10m)) {
      details.push(`Tuul ${now.wind_speed_10m.toFixed(1)} m/s`);
    }
    if (Number.isFinite(now.apparent_temperature)) {
      details.push(`Tajutav ${Math.round(now.apparent_temperature)}°`);
    }
    document.querySelector('#weather-details').textContent = details.join(' · ');
    weatherLoaded = true;
  } catch (error) {
    console.error('Ilmaandmete viga:', error);
    // Lühikese võrgukatkestuse ajal jätame viimase ilma ekraanile.
    if (!weatherLoaded) {
      document.querySelector('#weather-desc').textContent = 'Ilm pole saadaval';
    }
  }
}

/* ---------- Slaidiesitlus ---------- */

function savedName() {
  try { return localStorage.getItem(STORAGE_KEY); } catch { return null; }
}

function saveName(name) {
  try { localStorage.setItem(STORAGE_KEY, name); } catch { /* pole oluline */ }
}

// Pärast lehe uuesti laadimist jätkame sealt, kus pooleli jäime.
function resumeIndex() {
  const name = savedName();
  const found = name ? photos.findIndex(photo => photo.name === name) : -1;
  return found === -1 ? 0 : (found + 1) % photos.length;
}

function photoUrl(item) {
  return '?photo=' + encodeURIComponent(item.name) + '&v=' + encodeURIComponent(item.rev);
}

function updateCount() {
  countEl.textContent = current >= 0 && photos.length ? `${current + 1} / ${photos.length}` : '';
}

function showEmpty(title, detail) {
  emptyTitle.textContent = title;
  emptyDetail.textContent = detail;
  empty.hidden = false;
}

// Laeme järgmise foto taustal ette, et vahetus oleks kohene (Pi 2 töötleb suuri pilte aeglaselt).
function preloadNext() {
  if (photos.length < 2 || current < 0) {
    return;
  }
  preloader = new Image();
  preloader.src = photoUrl(photos[(current + 1) % photos.length]);
}

// Proovime järgmise fotoga, kui üks fail ei avane.
function show(index, attempts = 0) {
  if (busy || !photos.length) {
    return;
  }

  busy = true;
  index = (index + photos.length) % photos.length;

  const item = photos[index];
  const incoming = layers[1 - shown];
  let finished = false;
  let timer = 0;

  const finish = ok => {
    if (finished) {
      return;
    }
    finished = true;
    clearTimeout(timer);
    incoming.onload = null;
    incoming.onerror = null;
    busy = false;

    if (ok) {
      layers[shown].classList.remove('visible');
      incoming.classList.add('visible');
      shown = 1 - shown;

      // Nimekiri võis laadimise ajal muutuda, seega leiame koha nime järgi.
      const position = photos.findIndex(photo => photo.name === item.name);
      current = position === -1 ? Math.min(index, photos.length - 1) : position;
      saveName(item.name);

      empty.hidden = true;
      updateCount();

      bar.classList.remove('run');
      void bar.offsetWidth;
      bar.classList.add('run');

      preloadNext();
      return;
    }

    incoming.removeAttribute('src');
    console.error('Foto laadimine ebaõnnestus:', item.name);

    if (attempts + 1 < photos.length) {
      setTimeout(() => show(index + 1, attempts + 1), 0);
    } else {
      showEmpty(
        'Fotod leiti, aga neid ei saa avada',
        'Kontrolli, kas Apache saab pilte lugeda ja failid on terved.'
      );
    }
  };

  // Kui foto laadimine takerdub, ei jää esitlus igaveseks ootama.
  timer = setTimeout(() => finish(false), LOAD_TIMEOUT);
  incoming.onload = () => finish(true);
  incoming.onerror = () => finish(false);
  incoming.src = photoUrl(item);
}

function next() {
  show(current + 1);
}

async function reload() {
  try {
    const response = await fetch('?list=1', { cache: 'no-store' });
    if (!response.ok) {
      throw new Error('HTTP ' + response.status);
    }

    const data = await response.json();

    if (data.version !== pageVersion) {
      location.replace('index.php?updated=' + Date.now());
      return;
    }

    if (data.error) {
      showEmpty('Pildikausta ei saa avada', data.error);
      return;
    }

    if (!Array.isArray(data.photos)) {
      throw new Error('Vigane piltide nimekiri');
    }

    // Hoiame sama foto ekraanil, kui kausta sisu muutub.
    const previous = current >= 0 ? photos[current] : null;
    const oldIndex = current;
    photos = data.photos;

    if (!photos.length) {
      layers.forEach(image => image.classList.remove('visible'));
      current = -1;
      updateCount();
      showEmpty('Ootan pilte …', 'Lisa fotod kausta ' + photoDirectory);
      return;
    }

    if (!previous) {
      // Esimene laadimine: jätkame viimati näidatud fotost.
      current = -1;
      show(resumeIndex());
      return;
    }

    const found = photos.findIndex(photo => photo.name === previous.name);

    if (found === -1) {
      // Näidatud foto kustutati: liigume lähimale.
      current = -1;
      show(Math.min(oldIndex, photos.length - 1));
      return;
    }

    const replaced = photos[found].rev !== previous.rev;
    current = found;

    if (replaced) {
      show(found);
    } else {
      updateCount();
    }
  } catch (error) {
    console.error('Piltide nimekirja viga:', error);
    if (current === -1) {
      showEmpty('Piltide nimekirja ei saa laadida',
        'Kontrolli aadressi ?list=1 ja Apache/PHP seadistust.');
    }
  }
}

// Fotod käivitame enne ilma päringut.
reload();
setInterval(next, delay);
setInterval(reload, 30000);

// Varuks värskendame lehe harva. Viimane foto jäetakse meelde, nii et esitlus ei alga otsast.
setInterval(() => location.reload(), 6 * 60 * 60 * 1000);

if (weatherEnabled) {
  document.querySelector('#weather-icon').innerHTML = weatherIcon('unknown', true);
  updateWeather();
  setInterval(updateWeather, 15 * 60 * 1000);
}
</script>
</body>
</html>