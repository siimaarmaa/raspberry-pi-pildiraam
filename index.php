<?php
// Muuda neid kahte teed, kui fotod või vahemälu asuvad mujal.
$photoDir = getenv('PILDIRAAM_PILDID') ?: '/home/saarmaa/pildid';
$cacheDir = getenv('PILDIRAAM_CACHE') ?: '/var/cache/pildiraam';
$extensions = ['jpg', 'jpeg', 'png', 'webp'];

function photoList(string $dir, array $extensions): array
{
    if (!is_dir($dir)) {
        return [];
    }

    $photos = [];

    foreach (scandir($dir) ?: [] as $name) {
        $path = $dir . '/' . $name;
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if (!in_array($ext, $extensions, true) || !is_file($path) || is_link($path)) {
            continue;
        }

        // Muutmisaeg ja suurus aitavad brauseril aru saada, kui foto on asendatud.
        $photos[] = [
            'name' => $name,
            'rev' => (string) (@filemtime($path) ?: 0) . '-' . (string) (@filesize($path) ?: 0),
        ];
    }

    usort($photos, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));

    return $photos;
}

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

    echo json_encode([
        'version' => hash_file('sha256', __FILE__),
        'photos' => $error ? [] : photoList($photoDir, $extensions),
        'error' => $error,
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

    exit;
}

// ?photo=fail.jpg annab väiksemaks tehtud JPEG-pildi.
if (isset($_GET['photo'])) {
    $name = $_GET['photo'];

    if (!is_string($name) || $name !== basename($name) || str_contains($name, '\\')) {
        http_response_code(400);
        exit;
    }

    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $base = realpath($photoDir);
    $path = realpath($photoDir . '/' . $name);

    // Pilte tohib lugeda ainult määratud fotokaustast.
    if (
        !$base || !$path ||
        !str_starts_with($path, $base . DIRECTORY_SEPARATOR) ||
        !is_file($path) ||
        !in_array($ext, $extensions, true)
    ) {
        http_response_code(404);
        exit;
    }

    // Kui GD või vahemälu pole saadaval, näitame originaalfaili.
    // Nii ei jää kogu slaidiesitlus ühe puuduva PHP mooduli taha kinni.
    if (!extension_loaded('gd') || !is_dir($cacheDir) || !is_writable($cacheDir)) {
        $details = @getimagesize($path);
        $mime = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png', 'webp' => 'image/webp'][$ext];

        if (!$details || $details[0] * $details[1] > 20000000 || $details['mime'] !== $mime) {
            http_response_code(415);
            exit('Vigane või liiga suur foto.');
        }

        header('X-Content-Type-Options: nosniff');
        header('Content-Type: ' . $mime);
        header('Cache-Control: public, max-age=86400');
        readfile($path);
        exit;
    }

    $cache = $cacheDir . '/' . sha1($name . '|' . filemtime($path) . '|' . filesize($path)) . '.jpg';

    if (!is_file($cache)) {
        $dimensions = @getimagesize($path);

        if (!$dimensions || $dimensions[0] * $dimensions[1] > 20000000) {
            http_response_code(415);
            exit('Vigane või liiga suur foto (üle 20 MP).');
        }

        [$width, $height] = $dimensions;
        $reader = match ($ext) {
            'jpg', 'jpeg' => 'imagecreatefromjpeg',
            'png' => 'imagecreatefrompng',
            'webp' => 'imagecreatefromwebp',
        };

        $source = function_exists($reader) ? @$reader($path) : false;

        if (!$source) {
            http_response_code(415);
            exit;
        }

        // Raami jaoks pole mõtet originaali täissuuruses laadida.
        $scale = min(1, 1280 / $width, 1280 / $height);
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));
        $image = imagecreatetruecolor($newWidth, $newHeight);

        imagefill($image, 0, 0, imagecolorallocate($image, 12, 16, 22));
        imagecopyresampled(
            $image, $source,
            0, 0, 0, 0,
            $newWidth, $newHeight, $width, $height
        );
        imagedestroy($source);

        // Telefoniga tehtud fotodel võib pööramisinfo olla EXIF-is.
        if (in_array($ext, ['jpg', 'jpeg'], true) && function_exists('exif_read_data')) {
            $exif = @exif_read_data($path);
            $angle = match ((int) ($exif['Orientation'] ?? 1)) {
                3 => 180,
                6 => 270,
                8 => 90,
                default => 0,
            };

            if ($angle) {
                $rotated = imagerotate($image, $angle, 0);

                if ($rotated) {
                    imagedestroy($image);
                    $image = $rotated;
                }
            }
        }

        imageinterlace($image, true);
        $temp = tempnam($cacheDir, 'photo_');

        // Esmalt ajutine fail, siis ümbernimetamine: poolik pilt ei jõua vahemällu.
        if ($temp === false || !imagejpeg($image, $temp, 84) || !rename($temp, $cache)) {
            imagedestroy($image);

            if ($temp !== false) {
                @unlink($temp);
            }

            http_response_code(500);
            exit('Pildi vahemällu salvestamine ebaõnnestus.');
        }

        imagedestroy($image);
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
        :root { color-scheme: dark; }
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

        /* Kell ja kuupäev vasakul. Kuupäeva taust jääb alati mustaks. */
        .top {
            position: fixed;
            top: 26px;
            left: 26px;
            z-index: 2;
            text-shadow: 0 2px 9px #0008;
        }

        #time {
            font-size: 38px;
            font-weight: 300;
            letter-spacing: .025em;
            line-height: 1.1;
        }

        #date {
            display: table;
            margin-top: 5px;
            padding: 6px 10px;
            border-radius: 5px;
            background: #000;
            color: #fff;
            font-size: 13px;
            text-transform: capitalize;
            text-shadow: none;
        }

        /* Alatskivi ilm paremas ülanurgas. */
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

        .weather-place { font-size: 13px; font-weight: 600; opacity: .9; }
        .weather-main { display: flex; justify-content: flex-end; align-items: center; gap: 9px; margin-top: 2px; }
        #weather-icon { font-size: 28px; line-height: 1.2; }
        #weather-temp { font-size: 32px; font-weight: 300; line-height: 1.2; }
        #weather-desc { font-size: 13px; }
        #weather-details { margin-top: 3px; font-size: 11px; opacity: .78; }

        .bottom {
            position: fixed;
            bottom: 28px;
            left: 26px;
            right: 26px;
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            gap: 16px;
            font-size: 13px;
            text-shadow: 0 2px 9px #000a;
        }

        #name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; opacity: .88; }
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
        #bar.run { animation: fill 12s linear forwards; }

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
            #time { font-size: 30px; }
            #date { font-size: 11px; padding: 5px 7px; }

            .weather {
                top: 14px;
                right: 14px;
                min-width: 0;
                max-width: 48vw;
                padding: 8px 10px;
            }

            .weather-place, #weather-desc { font-size: 12px; }
            #weather-icon { font-size: 22px; }
            #weather-temp { font-size: 25px; }
            #weather-details { font-size: 10px; }
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

    <div class="weather" aria-label="Alatskivi ilm">
        <div class="weather-place">Alatskivi</div>
        <div class="weather-main">
            <span id="weather-icon" aria-hidden="true">🌡️</span>
            <span id="weather-temp">–</span>
        </div>
        <div id="weather-desc">Ilma laadimine…</div>
        <div id="weather-details"></div>
    </div>

    <div class="bottom">
        <span id="name"></span>
        <span id="count"></span>
    </div>

    <div class="progress"><div id="bar"></div></div>

    <div id="empty">
        <strong>Ootan pilte …</strong>
        <small id="empty-detail">Lisa fotod kausta <?= htmlspecialchars($photoDir, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small>
    </div>

    <script>
        const delay = 12000; // Foto vahetub iga 12 sekundi järel.
        const pageVersion = <?= json_encode(hash_file('sha256', __FILE__)) ?>;
        const layers = [...document.querySelectorAll('.photo')];
        const bar = document.querySelector('#bar');
        const empty = document.querySelector('#empty');
        const emptyTitle = empty.querySelector('strong');
        const emptyDetail = document.querySelector('#empty-detail');
        const photoDirectory = <?= json_encode($photoDir, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?>;

        let photos = [];
        let current = -1;
        let shown = 0;
        let busy = false;

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

        // Kase tn 9 piirkonna ilm. Väline päring ei ole fotode kuvamiseks vajalik.
        const weatherUrl = 'https://api.open-meteo.com/v1/forecast'
            + '?latitude=58.59825&longitude=27.13214'
            + '&current=temperature_2m,apparent_temperature,weather_code,is_day,wind_speed_10m'
            + '&timezone=Europe%2FTallinn&wind_speed_unit=ms';

        function weatherCondition(code, isDay) {
            if (code === 0) return [isDay ? '☀️' : '🌙', 'Selge'];
            if (code === 1) return [isDay ? '🌤️' : '🌙', 'Peamiselt selge'];
            if (code === 2) return ['⛅', 'Vahelduv pilvisus'];
            if (code === 3) return ['☁️', 'Pilves'];
            if ([45, 48].includes(code)) return ['🌫️', 'Udu'];
            if ([51, 53, 55, 56, 57].includes(code)) return ['🌦️', 'Uduvihm'];
            if ([61, 63, 65, 66, 67, 80, 81, 82].includes(code)) return ['🌧️', 'Vihm'];
            if ([71, 73, 75, 77, 85, 86].includes(code)) return ['❄️', 'Lumesadu'];
            if ([95, 96, 99].includes(code)) return ['⛈️', 'Äike'];
            return ['🌡️', 'Ilm'];
        }

        let weatherLoaded = false;

        async function updateWeather() {
            try {
                const response = await fetch(weatherUrl, { cache: 'no-store' });

                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }

                const data = await response.json();
                const now = data.current;

                if (!now || !Number.isFinite(now.temperature_2m)) {
                    throw new Error('Ilmaandmed puuduvad');
                }

                const [icon, description] = weatherCondition(now.weather_code, now.is_day === 1);
                document.querySelector('#weather-icon').textContent = icon;
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

        function showEmpty(title, detail) {
            emptyTitle.textContent = title;
            emptyDetail.textContent = detail;
            empty.hidden = false;
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

            incoming.onload = () => {
                layers[shown].classList.remove('visible');
                incoming.classList.add('visible');
                shown = 1 - shown;
                current = index;
                busy = false;
                empty.hidden = true;

                document.querySelector('#name').textContent = item.name
                    .replace(/\.[^.]+$/, '')
                    .replace(/[_-]/g, ' ');
                document.querySelector('#count').textContent = `${current + 1} / ${photos.length}`;

                bar.classList.remove('run');
                void bar.offsetWidth;
                bar.classList.add('run');
            };

            incoming.onerror = () => {
                busy = false;
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

            incoming.src = '?photo=' + encodeURIComponent(item.name)
                + '&v=' + encodeURIComponent(item.rev);
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
                const previous = photos[current];
                photos = data.photos;

                if (!photos.length) {
                    layers.forEach(image => image.classList.remove('visible'));
                    document.querySelector('#name').textContent = '';
                    document.querySelector('#count').textContent = '';
                    current = -1;
                    showEmpty('Ootan pilte …', 'Lisa fotod kausta ' + photoDirectory);
                    return;
                }

                const found = previous
                    ? photos.findIndex(photo => photo.name === previous.name)
                    : -1;
                const replaced = found !== -1 && photos[found].rev !== previous.rev;
                current = found;

                if (found === -1 || replaced) {
                    show(found === -1 ? 0 : found);
                } else {
                    document.querySelector('#count').textContent = `${current + 1} / ${photos.length}`;
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
        setInterval(() => location.reload(), 30 * 60 * 1000);

        updateWeather();
        setInterval(updateWeather, 15 * 60 * 1000);
    </script>
</body>
</html>
