<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    json_output(['error' => 'Lubatud on GET.'], 405);
}

json_output([
    'version' => application_version(),
    'photos' => photos(),
]);
