<?php
header('Content-Type: application/geo+json; charset=utf-8');

$archive = __DIR__ . '/south_caloocan.geojson';
$extracted = __DIR__ . '/gadm41_PHL_3.json';
$member = 'gadm41_PHL_3.json';

if (is_file($extracted)) {
    $json = file_get_contents($extracted);
    echo preg_replace('/^\xEF\xBB\xBF/', '', $json);
    exit;
}

if (!class_exists('ZipArchive')) {
    http_response_code(500);
    echo json_encode(['error' => 'The extracted GADM GeoJSON asset is missing and PHP ZipArchive is disabled.']);
    exit;
}

$zip = new ZipArchive();
if ($zip->open($archive) !== true) {
    http_response_code(404);
    echo json_encode(['error' => 'South Caloocan GeoJSON archive could not be opened.']);
    exit;
}

$json = $zip->getFromName($member);
$zip->close();

if ($json === false) {
    http_response_code(404);
    echo json_encode(['error' => 'GADM GeoJSON member was not found.']);
    exit;
}

echo preg_replace('/^\xEF\xBB\xBF/', '', $json);
