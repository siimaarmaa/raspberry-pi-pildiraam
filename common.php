<?php

declare(strict_types=1);

/**
 * Pildiraami ühine seadistus ja abifunktsioonid.
 * Mõlemad lehed (index.php ja upload.php) kasutavad seda faili,
 * nii et seadistust tuleb muuta ainult siin.
 */

function pildiraamConfig(): array
{
    static $config = null;

    return $config ??= [
        // Muuda neid kahte teed, kui fotod või vahemälu asuvad mujal.
        // Võid kasutada ka keskkonnamuutujaid PILDIRAAM_PILDID ja PILDIRAAM_CACHE.
        'photoDir' => getenv('PILDIRAAM_PILDID') ?: '/home/saarmaa/pildid',
        'cacheDir' => getenv('PILDIRAAM_CACHE') ?: '/var/cache/pildiraam',

        'extensions' => ['jpg', 'jpeg', 'png', 'webp'],

        // Kui kaua ühte fotot näidatakse (sekundites).
        'slideSeconds' => 12,

        // Ilm ekraani nurgas. Pane 'enabled' => false, kui ilma pole vaja.
        // Selleks teeb brauser päringu aadressile api.open-meteo.com.
        'weather' => [
            'enabled' => true,
            'place' => 'Alatskivi',
            'latitude' => 58.59825,
            'longitude' => 27.13214,
        ],
    ];
}

// Eelvaadete pikima külje suurused: slaid ja halduslehe pisipilt.
const PILDIRAAM_SIZES = [1280, 400];

/**
 * Leiab foto tegeliku tee või annab null, kui nimi pole lubatud.
 * Sümbollinke ei luba, ka siis mitte, kui need osutavad samasse kausta.
 */
function findPhoto(string $photoDir, mixed $name, array $extensions, ?string $base = null): ?string
{
    if (!is_string($name) || $name === '' || $name !== basename($name)
        || str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, "\0")) {
        return null;
    }

    if (!in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), $extensions, true)) {
        return null;
    }

    $base ??= realpath($photoDir);
    $candidate = $photoDir . '/' . $name;
    $path = realpath($candidate);

    if (!$base || !$path || is_link($candidate) || !is_file($path)
        || !str_starts_with($path, $base . DIRECTORY_SEPARATOR)) {
        return null;
    }

    return $path;
}

/**
 * Fotode nimekiri. $sort: 'name' (loomulik järjekord) või 'newest'.
 */
function photoList(string $photoDir, array $extensions, string $sort = 'name'): array
{
    if (!is_dir($photoDir) || !is_readable($photoDir)) {
        return [];
    }

    $base = realpath($photoDir);
    $photos = [];

    foreach (scandir($photoDir) ?: [] as $name) {
        $path = findPhoto($photoDir, $name, $extensions, $base ?: null);
        if ($path === null) {
            continue;
        }

        $mtime = (int) (@filemtime($path) ?: 0);
        $size = (int) (@filesize($path) ?: 0);

        $photos[] = [
            'name' => $name,
            // Muutmisaeg ja suurus aitavad brauseril aru saada, kui foto on asendatud.
            'rev' => $mtime . '-' . $size,
            'size' => $size,
            'mtime' => $mtime,
        ];
    }

    if ($sort === 'newest') {
        usort($photos, static fn(array $a, array $b): int =>
            ($b['mtime'] <=> $a['mtime']) ?: strnatcasecmp($a['name'], $b['name'])
        );
    } else {
        usort($photos, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
    }

    return $photos;
}

/** Vahemälufaili tee. Võti sõltub failinimest, muutmisajast, suurusest ja eelvaate suurusest. */
function previewCachePath(string $cacheDir, string $name, int $mtime, int $size, int $maxSide): string
{
    return $cacheDir . '/' . sha1($name . '|' . $mtime . '|' . $size . '|' . $maxSide) . '.jpg';
}

/** Kustutab foto kõik eelvaated (slaid ja pisipilt). */
function clearPreviews(string $cacheDir, string $name, int $mtime, int $size): void
{
    foreach (PILDIRAAM_SIZES as $maxSide) {
        $cache = previewCachePath($cacheDir, $name, $mtime, $size, $maxSide);
        if (is_file($cache) && !is_link($cache)) {
            @unlink($cache);
        }
    }
}

/**
 * Koristab vahemälust eelvaated, mille fotot enam pole, ja vanad pooleli jäänud
 * ajutised failid. Töötab kõige rohkem korra tunnis.
 */
function pruneCache(string $cacheDir, array $photos): void
{
    if (!is_dir($cacheDir) || !is_writable($cacheDir)) {
        return;
    }

    $marker = $cacheDir . '/.prune';
    if (is_file($marker) && time() - (int) @filemtime($marker) < 3600) {
        return;
    }
    @touch($marker);

    $keep = [];
    foreach ($photos as $photo) {
        foreach (PILDIRAAM_SIZES as $maxSide) {
            $keep[basename(previewCachePath($cacheDir, $photo['name'], $photo['mtime'], $photo['size'], $maxSide))] = true;
        }
    }

    $now = time();

    foreach (glob($cacheDir . '/*.jpg') ?: [] as $file) {
        if (!isset($keep[basename($file)]) && $now - (int) @filemtime($file) > 600) {
            @unlink($file);
        }
    }

    foreach (glob($cacheDir . '/photo_*') ?: [] as $file) {
        if ($now - (int) @filemtime($file) > 3600) {
            @unlink($file);
        }
    }
}

/** Kustutab katkenud pööramisest maha jäänud ajutised failid fotokaustas. */
function cleanupTurnTemps(string $photoDir): void
{
    $now = time();

    foreach (glob($photoDir . '/.pildiraam-turn-*') ?: [] as $file) {
        if (is_file($file) && !is_link($file) && $now - (int) @filemtime($file) > 3600) {
            @unlink($file);
        }
    }
}

/** Lehe versioon: kui failid või seadistus muutuvad, laeb avatud leht ennast uuesti. */
function pageVersion(): string
{
    return sha1(
        (string) @hash_file('sha256', __DIR__ . '/index.php')
        . (string) @hash_file('sha256', __FILE__)
    );
}

// EXIF-asend: 1 = normaalne, 2–8 = pööratud või peegeldatud.
function exifOrientation(string $path, string $extension): int
{
    if (!in_array($extension, ['jpg', 'jpeg'], true) || !function_exists('exif_read_data')) {
        return 1;
    }

    $exif = @exif_read_data($path);
    $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

    return $orientation >= 1 && $orientation <= 8 ? $orientation : 1;
}

function orientationFlip(int $orientation): ?int
{
    return match (true) {
        $orientation === 4 => IMG_FLIP_VERTICAL,
        in_array($orientation, [2, 5, 7], true) => IMG_FLIP_HORIZONTAL,
        default => null,
    };
}

// GD pöörab vastupäeva.
function orientationAngle(int $orientation): int
{
    return match ($orientation) {
        3 => 180,
        5, 8 => 90,
        6, 7 => 270,
        default => 0,
    };
}

function turnImage(GdImage $image, int $degrees): GdImage|false
{
    $background = imagecolorallocatealpha($image, 0, 0, 0, 127);
    $turned = imagerotate($image, $degrees, $background === false ? 0 : $background);

    if ($turned !== false) {
        imagealphablending($turned, false);
        imagesavealpha($turned, true);
    }

    return $turned;
}