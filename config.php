<?php
declare(strict_types=1);

/**
 * Projekti vaikeseaded. Oma masina seaded pane config.local.php faili
 * (see fail on .gitignore'is ega satu GitHubi).
 */
return [
    'photo_dir' => '/home/saarmaa/pildid',
    'cache_dir' => '/var/cache/pildiraam',
    'slide_ms' => 12000,
    'check_ms' => 30000,
    'full_reload_ms' => 1800000,
    'thumb_max_side' => 1600,
    'max_upload_bytes' => 64 * 1024 * 1024,
    'max_image_pixels' => 20000000,
    'timezone' => 'Europe/Tallinn',
];
