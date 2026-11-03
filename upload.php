<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';
$config = settings();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $reply = static function (int $code, bool $success, string $message): never {
        json_output(['ok' => $success, 'message' => $message], $code);
    };

    // Lihtne kaitse teise veebisaidi poolt algatatud üleslaadimise vastu.
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($origin !== '') {
        $originHost = parse_url($origin, PHP_URL_HOST);
        $originPort = parse_url($origin, PHP_URL_PORT);
        $requestHost = parse_url('http://' . $host, PHP_URL_HOST);
        $requestPort = parse_url('http://' . $host, PHP_URL_PORT);
        if (!is_string($originHost) || !is_string($requestHost) ||
            strcasecmp($originHost, $requestHost) !== 0 ||
            ($originPort ?? 80) !== ($requestPort ?? 80)) {
            $reply(403, false, 'Päritolu ei ühti pildiraami aadressiga.');
        }
    }

    $dir = (string) $config['photo_dir'];
    if (!is_dir($dir) || !is_writable($dir)) {
        $reply(500, false, 'Fotokaust puudub või Apachel puudub kirjutamisõigus.');
    }

    $photo = $_FILES['foto'] ?? null;
    if (!is_array($photo) || !is_int($photo['error'] ?? null)) {
        $reply(400, false, 'Fail puudub. Kontrolli PHP post_max_size seadistust.');
    }

    $error = $photo['error'];
    if ($error !== UPLOAD_ERR_OK) {
        $messages = [
            UPLOAD_ERR_INI_SIZE => 'Fail ületab PHP upload_max_filesize piiri.',
            UPLOAD_ERR_FORM_SIZE => 'Fail on lubatust suurem.',
            UPLOAD_ERR_PARTIAL => 'Üleslaadimine katkes.',
            UPLOAD_ERR_NO_FILE => 'Faili ei valitud.',
            UPLOAD_ERR_NO_TMP_DIR => 'PHP ajutine kaust puudub.',
            UPLOAD_ERR_CANT_WRITE => 'Ajutist faili ei saanud salvestada.',
            UPLOAD_ERR_EXTENSION => 'PHP laiendus katkestas üleslaadimise.',
        ];
        $reply(400, false, $messages[$error] ?? 'Üleslaadimine ebaõnnestus.');
    }

    $tmp = (string) ($photo['tmp_name'] ?? '');
    $size = (int) ($photo['size'] ?? 0);
    if ($size < 1 || $size > (int) $config['max_upload_bytes'] || !is_uploaded_file($tmp)) {
        $reply(413, false, 'Vigane või liiga suur fail (maksimaalselt 64 MiB).');
    }
    if (!class_exists('finfo')) {
        $reply(500, false, 'PHP Fileinfo laiendus puudub.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    $types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!is_string($mime) || !isset($types[$mime])) {
        $reply(415, false, 'Lubatud on JPG, PNG ja WebP fotod.');
    }
    $info = @getimagesize($tmp);
    if (!$info || ($info['mime'] ?? '') !== $mime ||
        $info[0] < 1 || $info[1] < 1 ||
        $info[0] * $info[1] > (int) $config['max_image_pixels']) {
        $reply(415, false, 'Vigane või liiga suure eraldusvõimega foto (max 20 MP).');
    }

    $original = basename(str_replace('\\', '/', (string) ($photo['name'] ?? 'foto')));
    $stem = pathinfo($original, PATHINFO_FILENAME);
    $stem = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $stem) ?: 'foto';
    $stem = trim(substr($stem, 0, 50), '-_') ?: 'foto';
    $name = date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '_' . $stem . '.' . $types[$mime];
    $stage = $dir . '/.pending-' . bin2hex(random_bytes(12)) . '.part';
    $dest = $dir . '/' . $name;

    if (!move_uploaded_file($tmp, $stage)) {
        $reply(500, false, 'Fotokausta salvestamine ebaõnnestus.');
    }
    @chmod($stage, 0644);
    if (!@rename($stage, $dest)) {
        @unlink($stage);
        $reply(500, false, 'Faili ümbernimetamine ebaõnnestus.');
    }
    $reply(201, true, 'Lisatud: ' . $name);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET, POST');
    http_response_code(405);
    exit;
}
no_cache_headers();
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="et">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#0b111c">
  <title>Pildiraam – fotode lisamine</title>
  <link rel="stylesheet" href="assets/upload.css">
</head>
<body>
<main>
  <header>
    <a class="back" href="./">← Slaidiesitlus</a>
    <div class="eyebrow">Raspberry Pi · Pildiraam</div>
    <h1>Lisa fotosid</h1>
    <p>Vali telefonist või arvutist korraga nii palju pilte, kui soovid. Fotod vähendatakse sinu seadmes pildiraamile sobivaks ja saadetakse turvaliselt ükshaaval.</p>
  </header>
  <section class="panel" aria-label="Fotode üleslaadimine">
    <div id="drop" class="drop" role="button" tabindex="0" aria-label="Vali üleslaaditavad fotod">
      <span class="art" aria-hidden="true">✧</span>
      <strong>Vali fotod</strong>
      <small>Või lohista need siia · JPG, PNG, WebP</small>
      <span class="pick">+ Vali failid</span>
    </div>
    <input id="files" type="file" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" multiple hidden>
    <div class="status-line">
      <span id="stats">Ükski foto pole veel valitud</span>
      <button id="pause" type="button" disabled>Peata järjekord</button>
    </div>
    <div class="track" role="progressbar" aria-label="Üleslaadimise edenemine" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><div id="bar"></div></div>
    <p id="current" role="status" aria-live="polite"></p>
    <ul id="history" aria-label="Üleslaadimise tulemused"></ul>
  </section>
  <footer>
    Pildid ilmuvad esitlusse automaatselt. Parooli ei küsita – kasuta ainult usaldatud kohalikus võrgus.
  </footer>
</main>
<script src="assets/upload.js" defer></script>
</body>
</html>
