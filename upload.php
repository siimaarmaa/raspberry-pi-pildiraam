<?php
declare(strict_types=1);

// Eraldi üleslaadimisleht senise /pildiraam/index.php kõrvale.
$photoDir = getenv('PILDIRAAM_PILDID') ?: '/home/saarmaa/pildid';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    $reply = static function (int $status, bool $ok, string $message): never {
        http_response_code($status);
        echo json_encode(['ok' => $ok, 'message' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    };

    if (!is_dir($photoDir) || !is_writable($photoDir)) {
        $reply(500, false, 'Fotokaust puudub või Apachel pole sinna kirjutamisõigust.');
    }

    $f = $_FILES['foto'] ?? null;
    if (!is_array($f) || !is_int($f['error'] ?? null)) {
        $reply(400, false, 'Fail puudub. Võimalik, et PHP post_max_size on liiga väike.');
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
        $reply(400, false, $errors[$f['error']] ?? 'Üleslaadimine ebaõnnestus.');
    }

    $tmp = (string) ($f['tmp_name'] ?? '');
    $size = (int) ($f['size'] ?? 0);
    if ($size < 1 || $size > 64 * 1024 * 1024 || !is_uploaded_file($tmp)) {
        $reply(400, false, 'Vigane või liiga suur fail (max 64 MiB).');
    }

    if (!function_exists('finfo_open')) {
        $reply(500, false, 'PHP Fileinfo moodul puudub.');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmp);
    $types = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    if (!is_string($mime) || !isset($types[$mime])) {
        $reply(415, false, 'Toetatud on ainult JPG, PNG ja WebP pildid.');
    }

    $dimensions = @getimagesize($tmp);
    if (
        !$dimensions ||
        ($dimensions['mime'] ?? '') !== $mime ||
        (int) $dimensions[0] < 1 ||
        (int) $dimensions[1] < 1 ||
        (int) $dimensions[0] * (int) $dimensions[1] > 20000000
    ) {
        $reply(415, false, 'Vigane või liiga suure lahutusega pilt (max 20 MP).');
    }

    // Ei kasuta failinime otse: eraldame tee, eemaldame ohtlikud märgid,
    // lisame kordumatu tunnuse ega kirjuta olemasolevaid fotosid üle.
    $original = basename(str_replace('\\', '/', (string) ($f['name'] ?? 'foto')));
    $stem = pathinfo($original, PATHINFO_FILENAME);
    $stem = preg_replace('/[^A-Za-z0-9_-]+/', '-', $stem) ?: 'foto';
    $stem = substr(trim($stem, '-_'), 0, 55) ?: 'foto';
    $filename = date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '_'
        . $stem . '.' . $types[$mime];
    $destination = $photoDir . '/' . $filename;

    if (!move_uploaded_file($tmp, $destination)) {
        $reply(500, false, 'Pildi salvestamine ebaõnnestus. Kontrolli kausta õigusi.');
    }

    @chmod($destination, 0644);
    $reply(201, true, 'Salvestatud: ' . $filename);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET, POST');
    http_response_code(405);
    exit;
}
header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="et">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Pildiraam – lisa fotosid</title>
<style>
:root { color-scheme: dark; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; }
* { box-sizing: border-box; }
body { margin: 0; min-height: 100vh; background: radial-gradient(ellipse at 90% 0%, #243552 0%, transparent 46%), #0b111c; color: #eef3fb; }
main { width: min(650px, calc(100% - 32px)); margin: 0 auto; padding: 55px 0 80px; }
.eyebrow { color: #9fc2fd; font-weight: 700; font-size: .8rem; letter-spacing: .16em; text-transform: uppercase; }
h1 { margin: 10px 0 8px; font-size: clamp(2rem, 7vw, 3rem); letter-spacing: -.05em; }
p { color: #aebdd2; line-height: 1.55; }
.panel { background: #172233; border: 1px solid #34445c; border-radius: 22px; padding: 25px; margin-top: 27px; box-shadow: 0 20px 60px #0003; }
.drop { border: 2px dashed #647d9e; border-radius: 17px; padding: 38px 17px; text-align: center; cursor: pointer; transition: .15s; background: #1b2a40; }
.drop.drag { border-color: #9cccff; background: #29405c; }
.symbol { font-size: 3rem; line-height: 1.2; }
.drop strong { display:block; margin: 12px 0 5px; font-size: 1.2rem; }
.drop small { color: #acbdd2; }
button, .action { border: 0; border-radius: 12px; padding: 14px 20px; font: inherit; font-weight: 700; cursor: pointer; }
.action { display:inline-block; background:#86bdff; color:#102034; margin-top: 19px; }
button { background: #34445c; color: #f4f7fe; }
button:disabled { opacity:.45; cursor:default; }
.row { display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap; align-items: center; margin-top: 25px; }
.stat { font-variant-numeric: tabular-nums; color: #cedcf1; }
.track { height: 11px; background:#30415b; border-radius: 50px; overflow:hidden; margin-top:18px; }
.bar { width:0%; height:100%; background: linear-gradient(90deg,#5c9eff,#90dfcb); transition:width .15s; }
#current { font-size:.9rem; word-break:break-word; min-height:1.4em; }
#history { list-style:none; padding:0; margin: 20px 0 0; max-height: 270px; overflow:auto; }
#history li { padding: 9px 0; border-bottom: 1px solid #33445b; color: #c4d6eb; overflow-wrap:anywhere; font-size:.9rem; }
#history li.ok { color:#a3e9c5; }
#history li.err { color:#ffb0b0; }
footer { margin-top: 23px; text-align:center; color:#92a8c2; font-size:.88rem; }
a { color:#a9ceff; }
</style>
</head>
<body>
<main>
  <div class="eyebrow">Raspberry Pi · Pildiraam</div>
  <h1>Lisa oma fotod</h1>
  <p>Vali telefonist või arvutist kasvõi sadu fotosid korraga. Need saadetakse ükshaaval otse pildiraami. Juba töötav slaidiesitlus leiab uued pildid ise.</p>
  <section class="panel">
    <div class="drop" id="drop" tabindex="0" role="button" aria-label="Vali üleslaaditavad fotod">
      <div class="symbol">✦</div>
      <strong>Vali fotod või lohista siia</strong>
      <small>JPG, PNG, WebP · kuni 64 MiB ühe foto kohta</small><br>
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
  <footer><a href="./">Ava slaidiesitlus</a> · Üleslaadimiseks ei ole parooli</footer>
</main>
<script>
(() => {
  'use strict';
  const input = document.getElementById('files');
  const drop = document.getElementById('drop');
  const bar = document.getElementById('bar');
  const stats = document.getElementById('stats');
  const current = document.getElementById('current');
  const history = document.getElementById('history');
  const pauseButton = document.getElementById('pause');
  const queue = [];
  let total = 0, done = 0, failed = 0, active = false, paused = false;

  function update(progress = 0) {
    const completed = done + failed;
    const percent = total ? ((completed + progress) / total * 100) : 0;
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
      xhr.upload.onprogress = (event) => {
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
  }

  drop.addEventListener('click', () => input.click());
  drop.addEventListener('keydown', e => {
    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); }
  });
  input.addEventListener('change', () => {
    addFiles(input.files);
    input.value = '';
  });
  for (const event of ['dragenter','dragover']) drop.addEventListener(event, e => {
    e.preventDefault(); drop.classList.add('drag');
  });
  for (const event of ['dragleave','drop']) drop.addEventListener(event, e => {
    e.preventDefault(); drop.classList.remove('drag');
  });
  drop.addEventListener('drop', e => addFiles(e.dataTransfer.files));
  pauseButton.addEventListener('click', () => {
    paused = !paused;
    update();
    if (!paused) processQueue();
  });
  update();
})();
</script>
</body>
</html>
