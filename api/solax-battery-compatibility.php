<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('X-Content-Type-Options: nosniff');

if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    echo '{"error":"method_not_allowed"}';
    exit;
}

$catalog = @file_get_contents(__DIR__ . '/../assets/data/solax-battery-compatibility.json');
if ($catalog === false) {
    header('Cache-Control: no-store');
    http_response_code(503);
    echo '{"error":"catalog_unavailable"}';
    exit;
}

header('Cache-Control: public, max-age=300');
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo $catalog;
}
