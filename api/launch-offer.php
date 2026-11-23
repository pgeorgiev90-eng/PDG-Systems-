<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    echo '{"error":"method_not_allowed"}';
    exit;
}

try {
    $config = require dirname(__DIR__, 2) . '/pdg-shop-db.php';
    $dsn = 'mysql:host=' . ($config['host'] ?? 'localhost')
        . ';dbname=' . $config['database'] . ';charset=utf8mb4';
    $db = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    // Seven places were already taken at this cutoff.
    // Registration notices are inserted once for each newly created customer.
    $query = $db->prepare(
        'SELECT COUNT(*) FROM pdg_registration_notices WHERE created_at >= FROM_UNIXTIME(?)'
    );
    $query->execute([1795455480]);
    echo json_encode(['remaining' => max(0, 50 - 7 - (int) $query->fetchColumn())]);
} catch (Throwable $error) {
    error_log('PDG launch offer count unavailable');
    http_response_code(503);
    echo '{"error":"offer_count_unavailable"}';
}
