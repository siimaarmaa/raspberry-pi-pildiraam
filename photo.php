<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit;
}

$name = $_GET['file'] ?? null;
$path = is_string($name) ? safe_photo_path($name) : null;
if ($path === null) {
    http_response_code(404);
    exit('Pilti ei leitud.');
}

$config = settings();
$cacheDir = (string) $config['cache_dir'];
if (!is_dir($cacheDir) || !is_writable($cacheDir)) {
    http_response_code(500);
    exit('Vahemälukausta ei saa kirjutada.');
}

$stamp = (string) (@filemtime($path) ?: 0);
$size = (string) (@filesize($path) ?: 0);
$cache = $cacheDir . '/' . hash(
    'sha256', $path . '|' . $stamp . '|' . $size . '|v2|' . $config['thumb_max_side']
) . '.jpg';

if (!is_file($cache)) {
    if (!extension_loaded('gd')) {
        http_response_code(500);
        exit('PHP GD laiendus puudub.');
    }
    $info = @getimagesize($path);
    if ($info === false || !isset($info[2]) || $info[0] < 1 || $info[1] < 1 ||
        $info[0] * $info[1] > (int) $config['max_image_pixels']) {
        http_response_code(415);
        exit('Vigane või liiga suure lahutusega pilt.');
    }

    $type = (int) $info[2];
    $source = match ($type) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
        IMAGETYPE_PNG => @imagecreatefrompng($path),
        IMAGETYPE_WEBP => @imagecreatefromwebp($path),
        default => false,
    };
    if (!$source instanceof GdImage) {
        http_response_code(415);
        exit('Pildifaili ei saanud avada.');
    }

    $width = imagesx($source);
    $height = imagesy($source);
    $scale = min(1.0, (int) $config['thumb_max_side'] / max($width, $height));
    $newW = max(1, (int) round($width * $scale));
    $newH = max(1, (int) round($height * $scale));

    $target = imagecreatetruecolor($newW, $newH);
    $background = imagecolorallocate($target, 12, 16, 22);
    imagefill($target, 0, 0, $background);
    imagecopyresampled($target, $source, 0, 0, 0, 0, $newW, $newH, $width, $height);
    imagedestroy($source);

    if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $exif = @exif_read_data($path);
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
        $angle = match ($orientation) {
            3 => 180,
            6 => 270,
            8 => 90,
            default => 0,
        };
        if ($angle !== 0) {
            $rotated = imagerotate($target, $angle, $background);
            if ($rotated instanceof GdImage) {
                imagedestroy($target);
                $target = $rotated;
            }
        }
    }

    imageinterlace($target, true);
    $temp = tempnam($cacheDir, 'new_');
    if ($temp === false || !imagejpeg($target, $temp, 84)) {
        imagedestroy($target);
        if ($temp !== false) {
            @unlink($temp);
        }
        http_response_code(500);
        exit('Pildi vähendamine ebaõnnestus.');
    }
    imagedestroy($target);
    if (!@rename($temp, $cache)) {
        @unlink($temp);
        http_response_code(500);
        exit('Pildi vahemällu salvestamine ebaõnnestus.');
    }
}

header('Content-Type: image/jpeg');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=86400');
header('Content-Length: ' . filesize($cache));
readfile($cache);
