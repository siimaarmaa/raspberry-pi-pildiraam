<?php
declare(strict_types=1);

/** Pildiraami ühised abifunktsioonid. */
function settings(): array
{
    static $settings = null;
    if ($settings !== null) {
        return $settings;
    }

    $defaults = require __DIR__ . '/config.php';
    $local = __DIR__ . '/config.local.php';
    $overrides = is_file($local) ? require $local : [];
    if (!is_array($defaults) || !is_array($overrides)) {
        throw new RuntimeException('Vigane konfiguratsioon.');
    }

    $settings = array_replace($defaults, $overrides);
    return $settings;
}

function no_cache_headers(): void
{
    header('Cache-Control: no-store, private, max-age=0');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
}

function json_output(array $data, int $status = 200): never
{
    http_response_code($status);
    no_cache_headers();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/** Järjestatud, ülemises kaustas paiknevad JPG/PNG/WebP fotod. */
function photos(): array
{
    $directory = (string) settings()['photo_dir'];
    $items = @scandir($directory);
    if ($items === false) {
        return [];
    }

    $result = [];
    foreach ($items as $name) {
        if (!preg_match('/\.(jpe?g|png|webp)$/i', $name)) {
            continue;
        }
        $path = $directory . DIRECTORY_SEPARATOR . $name;
        if (is_link($path) || !is_file($path) || !is_readable($path)) {
            continue;
        }
        $mtime = @filemtime($path) ?: 0;
        $size = @filesize($path) ?: 0;
        $revision = $mtime . '-' . $size;
        $result[] = [
            'file' => $name,
            'name' => pathinfo($name, PATHINFO_FILENAME),
            'url' => 'photo.php?file=' . rawurlencode($name) . '&v=' . rawurlencode($revision),
        ];
    }

    usort($result, static fn(array $a, array $b): int => strnatcasecmp($a['file'], $b['file']));
    return $result;
}

/** Vältida suvalise failitee lugemist, sh sümbolviited. */
function safe_photo_path(string $name): ?string
{
    if ($name === '' || $name === '.' || $name === '..' ||
        str_contains($name, '/') || str_contains($name, "\\") ||
        str_contains($name, "\0") ||
        !preg_match('/\.(jpe?g|png|webp)$/i', $name)) {
        return null;
    }
    $directory = realpath((string) settings()['photo_dir']);
    if ($directory === false) {
        return null;
    }
    $candidate = $directory . DIRECTORY_SEPARATOR . $name;
    $real = realpath($candidate);
    if ($real === false || dirname($real) !== $directory ||
        is_link($candidate) || !is_file($real) || !is_readable($real)) {
        return null;
    }
    return $real;
}

/** Muudetud PHP/CSS/JS korral brauseri automaatne värskendamine. */
function application_version(): string
{
    $paths = [
        'index.php', 'api.php', 'photo.php', 'lib.php', 'config.php',
        'assets/slideshow.css', 'assets/slideshow.js',
    ];
    $hashes = [];
    foreach ($paths as $path) {
        $full = __DIR__ . '/' . $path;
        $hashes[] = is_file($full) ? hash_file('sha256', $full) : '-';
    }
    if (is_file(__DIR__ . '/config.local.php')) {
        $hashes[] = hash_file('sha256', __DIR__ . '/config.local.php');
    }
    return substr(hash('sha256', implode('|', $hashes)), 0, 16);
}
