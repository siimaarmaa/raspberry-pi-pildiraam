<?php
declare(strict_types=1);

// Pildid on veebikaustast väljas. Vahemälu asukoht peab kattuma index.php-ga.
$photoDir = getenv('PILDIRAAM_PILDID') ?: '/home/saarmaa/pildid';
$cacheDir = getenv('PILDIRAAM_CACHE') ?: '/var/cache/pildiraam';
$extensions = ['jpg', 'jpeg', 'png', 'webp'];

// Kaitseb vormipäringuid, kui mõni muu veebileht üritab brauserit ära kasutada.
// Ligipääsu see ei piira: leht ise on endiselt ilma paroolita.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Strict',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}
if (empty($_SESSION['pildiraam_token'])) {
    $_SESSION['pildiraam_token'] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION['pildiraam_token'];
session_write_close();

function respond(int $status, bool $ok, string $message, array $extra = []): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode(
        array_merge(['ok' => $ok, 'message' => $message], $extra),
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

function findPhoto(string $photoDir, mixed $name, array $extensions): ?string
{
    if (!is_string($name) || $name === '' || $name !== basename($name)
        || str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, "\0")) {
        return null;
    }

    if (!in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), $extensions, true)) {
        return null;
    }

    $base = realpath($photoDir);
    $candidate = $photoDir . '/' . $name;
    $path = realpath($candidate);

    // Sümbollinke ei luba, ka siis mitte, kui need osutavad samasse kausta.
    if (!$base || !$path || is_link($candidate) || !is_file($path)
        || !str_starts_with($path, $base . DIRECTORY_SEPARATOR)) {
        return null;
    }

    return $path;
}

function photoList(string $photoDir, array $extensions): array
{
    if (!is_dir($photoDir) || !is_readable($photoDir)) {
        return [];
    }

    $photos = [];
    foreach (scandir($photoDir) ?: [] as $name) {
        $path = findPhoto($photoDir, $name, $extensions);
        if ($path === null) {
            continue;
        }
        $mtime = (int) (@filemtime($path) ?: 0);
        $size = (int) (@filesize($path) ?: 0);
        $photos[] = [
            'name' => $name,
            'rev' => $mtime . '-' . $size,
            'size' => $size,
            'mtime' => $mtime,
        ];
    }

    usort($photos, static fn(array $a, array $b): int =>
        ($b['mtime'] <=> $a['mtime']) ?: strnatcasecmp($a['name'], $b['name'])
    );
    return $photos;
}

function clearOldPreview(string $cacheDir, string $name, int $mtime, int $size): void
{
    // index.php teeb JPEG-vahemälu võtme failinime, muutmisaja ja suuruse järgi.
    $cache = $cacheDir . '/' . sha1($name . '|' . $mtime . '|' . $size) . '.jpg';
    if (is_file($cache) && !is_link($cache)) {
        @unlink($cache);
    }
}

function memoryLimitBytes(): int
{
    $value = trim((string) ini_get('memory_limit'));
    if ($value === '' || $value === '-1') {
        return PHP_INT_MAX;
    }
    $number = (float) $value;
    $suffix = strtolower(substr($value, -1));
    $multiplier = match ($suffix) {
        'g' => 1024 ** 3,
        'm' => 1024 ** 2,
        'k' => 1024,
        default => 1,
    };
    return (int) min(PHP_INT_MAX, $number * $multiplier);
}

function turnImage(GdImage $image, int $degrees): GdImage|false
{
    // GD kasutab vastupäeva nurka; sama kokkulepe on siin funktsioonis.
    $background = imagecolorallocatealpha($image, 0, 0, 0, 127);
    $turned = imagerotate($image, $degrees, $background);
    if ($turned !== false) {
        imagealphablending($turned, false);
        imagesavealpha($turned, true);
    }
    return $turned;
}

function rotatePhoto(string $path, string $direction, string $photoDir): ?string
{
    if (!extension_loaded('gd')) {
        return 'Pööramiseks tuleb paigaldada PHP GD moodul (php-gd).';
    }

    $info = @getimagesize($path);
    if (!$info || empty($info[0]) || empty($info[1])) {
        return 'Pildi mõõtmeid ei õnnestunud lugeda.';
    }

    $pixels = (int) $info[0] * (int) $info[1];
    // Täissuuruses pööramine vajab korraga mälu kahele pildile.
    $estimate = $pixels * 11 + 32 * 1024 * 1024;
    if ($pixels > 32000000 || $estimate > memoryLimitBytes() - memory_get_usage(true)) {
        return 'Pilt on selles seadmes täissuuruses pööramiseks liiga suur. Pööra see arvutis väiksemaks.';
    }

    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $reader = match ($extension) {
        'jpg', 'jpeg' => 'imagecreatefromjpeg',
        'png' => 'imagecreatefrompng',
        'webp' => 'imagecreatefromwebp',
        default => null,
    };
    if ($reader === null || !function_exists($reader)) {
        return 'Seda pildivormingut ei saa siin pöörata.';
    }

    $source = @$reader($path);
    if (!$source instanceof GdImage) {
        return 'Pildifaili ei õnnestunud avada.';
    }

    imagealphablending($source, false);
    imagesavealpha($source, true);

    // Kõigepealt arvestame telefoni EXIF-pööramisinfot. GD kirjutatud uuel
    // failil seda infot enam pole, seega ei pöörata pilti järgmine kord topelt.
    $orientation = 1;
    if (in_array($extension, ['jpg', 'jpeg'], true) && function_exists('exif_read_data')) {
        $exif = @exif_read_data($path);
        $orientation = (int) ($exif['Orientation'] ?? 1);
    }

    if (in_array($orientation, [2, 4, 5, 7], true)) {
        imageflip($source, $orientation === 4 ? IMG_FLIP_VERTICAL : IMG_FLIP_HORIZONTAL);
    }

    $autoAngle = match ($orientation) {
        3 => 180,
        5, 8 => 90,
        6, 7 => 270,
        default => 0,
    };
    if ($autoAngle !== 0) {
        $corrected = turnImage($source, $autoAngle);
        if (!$corrected) {
            imagedestroy($source);
            return 'Pildi asendi parandamine ebaõnnestus.';
        }
        imagedestroy($source);
        $source = $corrected;
    }

    $angle = $direction === 'right' ? 270 : 90;
    $rotated = turnImage($source, $angle);
    imagedestroy($source);
    if (!$rotated) {
        return 'Pööramine ebaõnnestus.';
    }

    // Ajutine fail samas kaustas: originaal asendatakse alles õnnestunud
    // salvestamise järel. Failinimi ja vorming jäävad samaks.
    $temp = tempnam($photoDir, '.pildiraam-turn-');
    if ($temp === false) {
        imagedestroy($rotated);
        return 'Ajutist faili ei saanud luua. Kontrolli kausta õigusi.';
    }

    $saved = match ($extension) {
        'jpg', 'jpeg' => imagejpeg($rotated, $temp, 90),
        'png' => imagepng($rotated, $temp, 6),
        'webp' => function_exists('imagewebp') && imagewebp($rotated, $temp, 90),
        default => false,
    };
    imagedestroy($rotated);

    if (!$saved || !is_file($temp) || (int) @filesize($temp) < 1) {
        @unlink($temp);
        return 'Pööratud pildi salvestamine ebaõnnestus. Algne foto jäi alles.';
    }

    @chmod($temp, 0644);
    $previousTime = (int) (@filemtime($path) ?: time());
    if (!@rename($temp, $path)) {
        @unlink($temp);
        return 'Originaalfaili ei õnnestunud asendada. Kontrolli kausta õigusi.';
    }
    // Vahemälu võti peab muutuma ka kahe kiire järjestikuse pööramise järel.
    @touch($path, max(time(), $previousTime + 1));
    clearstatcache(true, $path);
    return null;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'GET' && ($_GET['action'] ?? '') === 'list') {
    if (!is_dir($photoDir) || !is_readable($photoDir)) {
        respond(500, false, 'Fotokausta ei saa lugeda.');
    }
    respond(200, true, 'OK', ['photos' => photoList($photoDir, $extensions)]);
}

if ($method === 'POST') {
    $token = $_POST['csrf'] ?? '';
    if (!is_string($token) || !hash_equals($csrfToken, $token)) {
        respond(403, false, 'Sessioon aegus. Värskenda lehte ja proovi uuesti.');
    }
    if (!is_dir($photoDir) || !is_writable($photoDir)) {
        respond(500, false, 'Fotokaust puudub või Apachel pole sinna kirjutamisõigust.');
    }

    $action = $_POST['action'] ?? 'upload';
    if ($action === 'delete' || $action === 'rotate') {
        $name = $_POST['name'] ?? null;
        $path = findPhoto($photoDir, $name, $extensions);
        if ($path === null) {
            respond(404, false, 'Fotot ei leitud. Värskenda nimekirja.');
        }
        $mtime = (int) (@filemtime($path) ?: 0);
        $size = (int) (@filesize($path) ?: 0);

        if ($action === 'delete') {
            if (!@unlink($path)) {
                respond(500, false, 'Fotot ei saanud kustutada. Kontrolli kausta õigusi.');
            }
            clearOldPreview($cacheDir, (string) $name, $mtime, $size);
            respond(200, true, 'Foto kustutatud.');
        }

        $direction = $_POST['direction'] ?? '';
        if (!in_array($direction, ['left', 'right'], true)) {
            respond(400, false, 'Tundmatu pööramise suund.');
        }
        $error = rotatePhoto($path, $direction, $photoDir);
        if ($error !== null) {
            respond(422, false, $error);
        }
        clearOldPreview($cacheDir, (string) $name, $mtime, $size);
        respond(200, true, 'Foto pööratud ja salvestatud.');
    }

    if ($action !== 'upload') {
        respond(400, false, 'Tundmatu tegevus.');
    }

    // Senine üleslaadimise loogika: üks fail päringu kohta, kuni 64 MiB.
    $f = $_FILES['foto'] ?? null;
    if (!is_array($f) || !is_int($f['error'] ?? null)) {
        respond(400, false, 'Fail puudub. Võimalik, et PHP post_max_size on liiga väike.');
    }
    if ($f['error'] !== UPLOAD_ERR_OK) {
        $errors = [
            UPLOAD_ERR_INI_SIZE => 'Fail ületab PHP upload_max_filesize piiri.',
            UPLOAD_ERR_FORM_SIZE => 'Fail on lubatust suurem.',
            UPLOAD_ERR_PARTIAL => 'Üleslaadimine katkes.',
            UPLOAD_ERR_NO_FILE => 'Faili ei valitud.',
            UPLOAD_ERR_NO_TMP_DIR => 'PHP ajutine kaust puudub.',
            UPLOAD_ERR_CANT_WRITE => 'Ajutise faili kirjutamine ebaõnnestus.',
            UPLOAD_ERR_EXTENSION => 'PHP laiendus katkestas üleslaadimise.',
        ];
        respond(400, false, $errors[$f['error']] ?? 'Üleslaadimine ebaõnnestus.');
    }
    $tmp = (string) ($f['tmp_name'] ?? '');
    $size = (int) ($f['size'] ?? 0);
    if ($size < 1 || $size > 64 * 1024 * 1024 || !is_uploaded_file($tmp)) {
        respond(400, false, 'Vigane või liiga suur fail (max 64 MiB).');
    }
    if (!function_exists('finfo_open')) {
        respond(500, false, 'PHP Fileinfo moodul puudub.');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmp);
    $types = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    if (!is_string($mime) || !isset($types[$mime])) {
        respond(415, false, 'Toetatud on ainult JPG, PNG ja WebP pildid.');
    }
    $dimensions = @getimagesize($tmp);
    if (!$dimensions || ($dimensions['mime'] ?? '') !== $mime
        || (int) $dimensions[0] < 1 || (int) $dimensions[1] < 1
        || (int) $dimensions[0] * (int) $dimensions[1] > 20000000) {
        respond(415, false, 'Vigane või liiga suure lahutusega pilt (max 20 MP).');
    }

    $original = basename(str_replace('\\', '/', (string) ($f['name'] ?? 'foto')));
    $stem = pathinfo($original, PATHINFO_FILENAME);
    $stem = preg_replace('/[^A-Za-z0-9_-]+/', '-', $stem) ?: 'foto';
    $stem = substr(trim($stem, '-_'), 0, 55) ?: 'foto';
    $filename = date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '_'
        . $stem . '.' . $types[$mime];
    $destination = $photoDir . '/' . $filename;
    if (!move_uploaded_file($tmp, $destination)) {
        respond(500, false, 'Pildi salvestamine ebaõnnestus. Kontrolli kausta õigusi.');
    }
    @chmod($destination, 0644);
    respond(201, true, 'Salvestatud: ' . $filename);
}

if ($method !== 'GET') {
    header('Allow: GET, POST');
    http_response_code(405);
    exit;
}
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
?>
<!doctype html>
<html lang="et">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Pildiraam – fotode haldus</title>
<style>
:root { color-scheme: dark; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; }
* { box-sizing: border-box; }
body { margin: 0; min-height: 100vh; background: radial-gradient(ellipse at 90% 0%, #243552 0%, transparent 46%), #0b111c; color: #eef3fb; }
main { width: min(980px, calc(100% - 32px)); margin: 0 auto; padding: 40px 0 70px; }
.eyebrow { color: #9fc2fd; font-weight: 700; font-size: .8rem; letter-spacing: .16em; text-transform: uppercase; }
h1 { margin: 9px 0 8px; font-size: clamp(2rem, 7vw, 3rem); letter-spacing: -.05em; }
h2 { margin: 0; font-size: 1.45rem; }
p { color: #aebdd2; line-height: 1.5; }
.panel { background: #172233; border: 1px solid #34445c; border-radius: 20px; padding: 24px; margin-top: 24px; box-shadow: 0 20px 60px #0003; }
.drop { border: 2px dashed #647d9e; border-radius: 16px; padding: 32px 16px; text-align: center; cursor: pointer; background: #1b2a40; }
.drop.drag, .drop:focus-visible { border-color: #9cccff; background: #29405c; outline: none; }
.symbol { font-size: 2.5rem; }
.drop strong { display: block; margin: 8px 0 4px; font-size: 1.15rem; }
.drop small, .muted { color: #acbdd2; }
button, .action { border: 0; border-radius: 10px; padding: 11px 15px; font: inherit; font-weight: 650; cursor: pointer; }
.action { display: inline-block; margin-top: 16px; background: #86bdff; color: #102034; }
button { background: #34445c; color: #f4f7fe; }
button:hover:not(:disabled) { filter: brightness(1.17); }
button:focus-visible, input:focus-visible { outline: 2px solid #86bdff; outline-offset: 3px; }
button:disabled { opacity: .45; cursor: default; }
.row { display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap; align-items: center; margin-top: 20px; }
.stat { font-variant-numeric: tabular-nums; color: #cedcf1; }
.track { height: 9px; background: #30415b; border-radius: 20px; overflow: hidden; margin-top: 15px; }
.bar { width: 0%; height: 100%; background: linear-gradient(90deg, #5c9eff, #90dfcb); transition: width .15s; }
#current { font-size: .9rem; overflow-wrap: anywhere; min-height: 1.3em; }
#history { list-style: none; padding: 0; margin: 12px 0 0; max-height: 180px; overflow: auto; }
#history li { padding: 7px 0; border-bottom: 1px solid #33445b; font-size: .9rem; overflow-wrap: anywhere; }
#history .ok, .success { color: #a3e9c5; }
#history .err, .error { color: #ffb0b0; }
.gallery-heading { display: flex; gap: 12px; justify-content: space-between; align-items: center; flex-wrap: wrap; }
.gallery-controls { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 18px; }
.gallery-controls input { flex: 1; min-width: 160px; background: #0e1929; border: 1px solid #4a5d77; border-radius: 10px; padding: 11px 12px; color: #fff; font: inherit; }
#gallery-status { min-height: 1.4em; margin: 14px 0; font-size: .94rem; }
.gallery { display: grid; grid-template-columns: repeat(auto-fill, minmax(215px, 1fr)); gap: 15px; }
.photo-card { background: #101a29; border: 1px solid #35455d; border-radius: 13px; overflow: hidden; min-width: 0; }
.photo-preview { aspect-ratio: 4/3; display: grid; place-items: center; background: #080e17; color: #9aadc6; }
.photo-preview img { width: 100%; height: 100%; object-fit: contain; }
.photo-info { padding: 12px; }
.photo-name { font-size: .85rem; overflow-wrap: anywhere; margin: 0 0 5px; line-height: 1.35; }
.photo-meta { font-size: .8rem; color: #a4b6cc; }
.photo-actions { display: flex; gap: 6px; padding: 0 12px 12px; }
.photo-actions button { padding: 9px 8px; flex: 1; font-size: .85rem; }
.photo-actions .danger { color: #ffd4d4; background: #62313a; }
footer { margin-top: 24px; text-align: center; color: #92a8c2; font-size: .88rem; }
a { color: #a9ceff; }
@media (max-width: 540px) { main { padding-top: 25px; } .panel { padding: 16px; } .gallery { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; } .photo-actions { flex-wrap: wrap; } .photo-actions button { flex-basis: 39%; } }
@media (max-width: 370px) { .gallery { grid-template-columns: 1fr; } }
</style>
</head>
<body>
<main>
  <div class="eyebrow">Raspberry Pi · Pildiraam</div>
  <h1>Fotode haldus</h1>
  <p>Lisa uusi fotosid, pööra vales asendis pilte või kustuta neid, mida enam raamis näha ei soovi.</p>

  <section class="panel" aria-labelledby="upload-title">
    <h2 id="upload-title">Lisa fotosid</h2>
    <p>Fotosid võib valida mitu korraga. Üles laaditakse ükshaaval.</p>
    <div class="drop" id="drop" tabindex="0" role="button" aria-label="Vali üleslaaditavad fotod">
      <div class="symbol">✦</div>
      <strong>Vali fotod või lohista siia</strong>
      <small>JPG, PNG, WebP · kuni 64 MiB · max 20 MP</small><br>
      <span class="action">+ Vali fotod</span>
    </div>
    <input id="files" type="file" accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp" multiple hidden>
    <div class="row">
      <div class="stat" id="stats">Ootan pilte…</div>
      <button id="pause" type="button" disabled>Peata järjekord</button>
    </div>
    <div class="track"><div id="bar" class="bar"></div></div>
    <p id="current" aria-live="polite"></p>
    <ul id="history"></ul>
  </section>

  <section class="panel" aria-labelledby="gallery-title">
    <div class="gallery-heading">
      <h2 id="gallery-title">Olemasolevad fotod</h2>
      <span id="count" class="muted"></span>
    </div>
    <div class="gallery-controls">
      <input id="search" type="search" placeholder="Otsi failinime järgi…" aria-label="Otsi fotot">
      <button id="refresh" type="button">Värskenda</button>
    </div>
    <p id="gallery-status" role="status" aria-live="polite">Laen fotosid…</p>
    <div class="gallery" id="gallery"></div>
    <p class="muted" style="font-size:.85rem">Pööramine muudab originaalfaili ja võib eemaldada selle metaandmed. Kustutamist tagasi võtta ei saa.</p>
  </section>

  <footer><a href="./">Ava slaidiesitlus</a> · Sellel halduslehel ei ole parooli; kasuta seda ainult usaldusväärses koduvõrgus.</footer>
</main>
<script>
(() => {
  'use strict';

  const csrf = <?= json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const input = document.getElementById('files');
  const drop = document.getElementById('drop');
  const bar = document.getElementById('bar');
  const stats = document.getElementById('stats');
  const current = document.getElementById('current');
  const history = document.getElementById('history');
  const pauseButton = document.getElementById('pause');
  const gallery = document.getElementById('gallery');
  const galleryStatus = document.getElementById('gallery-status');
  const count = document.getElementById('count');
  const search = document.getElementById('search');
  const refresh = document.getElementById('refresh');

  const queue = [];
  let total = 0, done = 0, failed = 0, active = false, paused = false;
  let photos = [];
  let busyName = '';

  function update(progress = 0) {
    const completed = done + failed;
    const percent = total ? (completed + progress) / total * 100 : 0;
    bar.style.width = Math.max(0, Math.min(100, percent)) + '%';
    stats.textContent = `${completed}/${total} töödeldud · ${done} lisatud · ${failed} viga · ${queue.length} ootel`;
    pauseButton.disabled = !active && queue.length === 0;
    pauseButton.textContent = paused ? 'Jätka üleslaadimist' : 'Peata järjekord';
  }

  function note(message, good) {
    const li = document.createElement('li');
    li.className = good ? 'ok' : 'err';
    li.textContent = (good ? '✓ ' : '✕ ') + message;
    history.prepend(li);
    while (history.children.length > 15) history.lastElementChild.remove();
  }

  function addFiles(files) {
    let accepted = 0;
    for (const file of files) {
      if (!/\.(jpe?g|png|webp)$/i.test(file.name)) {
        note(`${file.name}: toetamata failitüüp`, false);
        continue;
      }
      queue.push(file);
      total++;
      accepted++;
    }
    if (accepted) {
      paused = false;
      update();
      processQueue();
    }
  }

  function upload(file) {
    return new Promise((resolve, reject) => {
      const xhr = new XMLHttpRequest();
      xhr.open('POST', 'upload.php');
      xhr.timeout = 300000;
      xhr.upload.onprogress = event => {
        if (event.lengthComputable) update(event.loaded / event.total);
      };
      xhr.onload = () => {
        let response;
        try { response = JSON.parse(xhr.responseText); }
        catch { reject(new Error(`Server tagastas vigase vastuse (HTTP ${xhr.status})`)); return; }
        if (xhr.status >= 200 && xhr.status < 300 && response.ok) resolve(response.message);
        else reject(new Error(response.message || `HTTP ${xhr.status}`));
      };
      xhr.onerror = () => reject(new Error('Võrguühendus katkes'));
      xhr.ontimeout = () => reject(new Error('Üleslaadimine aegus'));
      const body = new FormData();
      body.append('csrf', csrf);
      body.append('action', 'upload');
      body.append('foto', file, file.name);
      xhr.send(body);
    });
  }

  async function processQueue() {
    if (active || paused) return;
    active = true;
    update();
    while (queue.length && !paused) {
      const file = queue.shift();
      current.textContent = 'Laen üles: ' + file.name;
      try {
        const result = await upload(file);
        done++;
        note(file.name + ' — ' + result, true);
      } catch (err) {
        failed++;
        note(file.name + ' — ' + err.message, false);
      }
      update();
    }
    active = false;
    current.textContent = paused ? 'Järjekord peatatud' : 'Valmis! Fotod ilmuvad peagi slaidiesitlusse.';
    update();
    await loadPhotos();
  }

  // Pisipildid saadakse olemasolevast index.php-st; see kasutab oma vahemälu.
  function drawGallery() {
    const query = search.value.trim().toLocaleLowerCase('et');
    const visible = photos.filter(photo => photo.name.toLocaleLowerCase('et').includes(query));
    count.textContent = `${photos.length} fotot`;
    gallery.replaceChildren();
    if (!visible.length) {
      galleryStatus.textContent = photos.length ? 'Otsingule vastavaid fotosid ei ole.' : 'Ühtegi fotot veel ei ole.';
      return;
    }
    if (!busyName) galleryStatus.textContent = `Näitan ${visible.length} fotot.`;

    const fragment = document.createDocumentFragment();
    for (const photo of visible) {
      const card = document.createElement('article');
      card.className = 'photo-card';
      const preview = document.createElement('div');
      preview.className = 'photo-preview';
      const img = document.createElement('img');
      img.loading = 'lazy';
      img.decoding = 'async';
      img.alt = 'Foto eelvaade';
      img.src = './?photo=' + encodeURIComponent(photo.name) + '&v=' + encodeURIComponent(photo.rev);
      img.onerror = () => { preview.replaceChildren(document.createTextNode('Eelvaade puudub')); };
      preview.append(img);

      const info = document.createElement('div');
      info.className = 'photo-info';
      const name = document.createElement('p');
      name.className = 'photo-name';
      name.textContent = photo.name;
      name.title = photo.name;
      const meta = document.createElement('span');
      meta.className = 'photo-meta';
      meta.textContent = (photo.size / 1024 / 1024).toFixed(1) + ' MiB';
      info.append(name, meta);

      const actions = document.createElement('div');
      actions.className = 'photo-actions';
      const controls = [
        ['↶ Vasakule', 'left'],
        ['↷ Paremale', 'right'],
        ['Kustuta', 'delete'],
      ];
      for (const [label, action] of controls) {
        const button = document.createElement('button');
        button.type = 'button';
        button.textContent = label;
        button.title = action === 'delete' ? 'Kustuta foto jäädavalt' : 'Pööra fotot 90 kraadi';
        if (action === 'delete') button.className = 'danger';
        button.disabled = busyName !== '';
        button.addEventListener('click', () => managePhoto(photo, action));
        actions.append(button);
      }
      card.append(preview, info, actions);
      fragment.append(card);
    }
    gallery.append(fragment);
  }

  async function loadPhotos() {
    refresh.disabled = true;
    try {
      const response = await fetch('upload.php?action=list', { cache: 'no-store' });
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.message || `HTTP ${response.status}`);
      photos = data.photos;
      drawGallery();
    } catch (error) {
      galleryStatus.className = 'error';
      galleryStatus.textContent = 'Fotode laadimine ebaõnnestus: ' + error.message;
    } finally {
      refresh.disabled = false;
    }
  }

  async function managePhoto(photo, action) {
    if (busyName) return;
    if (action === 'delete' && !window.confirm('Kustutan selle foto jäädavalt?\n\n' + photo.name)) return;

    busyName = photo.name;
    galleryStatus.className = '';
    galleryStatus.textContent = action === 'delete' ? 'Kustutan fotot…' : 'Pööran fotot…';
    drawGallery();
    try {
      const body = new FormData();
      body.append('csrf', csrf);
      body.append('action', action === 'delete' ? 'delete' : 'rotate');
      body.append('name', photo.name);
      if (action !== 'delete') body.append('direction', action);
      const response = await fetch('upload.php', { method: 'POST', body });
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.message || `HTTP ${response.status}`);
      busyName = '';
      await loadPhotos();
      galleryStatus.className = 'success';
      galleryStatus.textContent = data.message;
    } catch (error) {
      busyName = '';
      drawGallery();
      galleryStatus.className = 'error';
      galleryStatus.textContent = error.message;
    }
  }

  drop.addEventListener('click', () => input.click());
  drop.addEventListener('keydown', event => {
    if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); input.click(); }
  });
  input.addEventListener('change', () => { addFiles(input.files); input.value = ''; });
  for (const eventName of ['dragenter', 'dragover']) {
    drop.addEventListener(eventName, event => {
      event.preventDefault();
      drop.classList.add('drag');
    });
  }
  for (const eventName of ['dragleave', 'drop']) {
    drop.addEventListener(eventName, event => {
      event.preventDefault();
      drop.classList.remove('drag');
    });
  }
  drop.addEventListener('drop', event => addFiles(event.dataTransfer.files));
  pauseButton.addEventListener('click', () => {
    paused = !paused;
    update();
    if (!paused) processQueue();
  });
  search.addEventListener('input', drawGallery);
  refresh.addEventListener('click', loadPhotos);
  update();
  loadPhotos();
})();
</script>
</body>
</html>
