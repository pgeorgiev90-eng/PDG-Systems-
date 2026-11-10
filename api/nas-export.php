<?php
declare(strict_types=1);

// NAS-only read API. No sessions, bootstrap routines, DDL or write operations.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function nas_answer(int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function nas_uint($value, int $maximum): ?int {
    if (!is_string($value) || !preg_match('/^(0|[1-9][0-9]{0,15})$/D', $value)) return null;
    $parsed = (int)$value;
    return $parsed <= $maximum ? $parsed : null;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') nas_answer(405, ['error'=>'method_not_allowed']);
    if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') nas_answer(400, ['error'=>'https_required']);
    $privateRoot = dirname(__DIR__, 2);
    $authFile = $privateRoot.'/pdg-nas-integration.php';
    if (!is_file($authFile)) nas_answer(503, ['error'=>'integration_not_configured']);
    $auth = require $authFile;
    if (!is_array($auth) || ($auth['enabled'] ?? false) !== true
        || !is_string($auth['secret'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $auth['secret'])
        || !is_string($auth['key_id'] ?? null)) nas_answer(503, ['error'=>'integration_not_configured']);

    $resource = $_GET['resource'] ?? '';
    if (!is_string($resource) || !in_array($resource, ['products','inquiries','orders'], true)) nas_answer(422, ['error'=>'invalid_resource']);
    if (array_diff(array_keys($_GET), ['resource','after_id','limit'])) nas_answer(422, ['error'=>'invalid_query']);
    $after = nas_uint($_GET['after_id'] ?? '0', PHP_INT_MAX);
    $limit = nas_uint($_GET['limit'] ?? '25', 100);
    if ($after === null || $limit === null || $limit < 1) nas_answer(422, ['error'=>'invalid_pagination']);
    $stamp = $_SERVER['HTTP_X_PDG_TIMESTAMP'] ?? '';
    $nonce = $_SERVER['HTTP_X_PDG_NONCE'] ?? '';
    $keyId = $_SERVER['HTTP_X_PDG_KEY_ID'] ?? '';
    $signature = $_SERVER['HTTP_X_PDG_SIGNATURE'] ?? '';
    if (!is_string($stamp) || !preg_match('/^[0-9]{10}$/D', $stamp) || abs(time() - (int)$stamp) > 90
        || !is_string($nonce) || !preg_match('/^[a-f0-9]{32}$/D', $nonce)
        || !is_string($keyId) || !hash_equals($auth['key_id'], $keyId)
        || !is_string($signature) || !preg_match('/^[a-f0-9]{64}$/D', $signature)) nas_answer(401, ['error'=>'unauthorized']);
    $canonical = "GET\n/api/nas-export.php\nresource=$resource&after_id=$after&limit=$limit\n$stamp\n$nonce";
    if (!hash_equals(hash_hmac('sha256', $canonical, $auth['secret']), $signature)) nas_answer(401, ['error'=>'unauthorized']);

    $config = require $privateRoot.'/pdg-shop-db.php';
    if (!is_array($config)) throw new RuntimeException('configuration');
    $db = new PDO('mysql:host='.($config['host'] ?? 'localhost').';dbname='.($config['database'] ?? '').';charset=utf8mb4',
        $config['username'] ?? '', $config['password'] ?? '',
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
    $db->exec('SET TRANSACTION READ ONLY');
    $db->beginTransaction();
    $queries = [
        'products'=>'SELECT id,brand,category,code,name,price,image_url,active,created_at,updated_at FROM products',
        'inquiries'=>'SELECT id,public_ref,customer_name,email,phone,project_name,status,materials_cents,created_at,updated_at,LEFT(material_json,16384) AS material_json FROM pdg_electric_enquiries',
        'orders'=>'SELECT id,order_number,customer_name,email,phone,city,address,notes,subtotal_cents,vat_cents,total_cents,currency,status,created_at,updated_at FROM pdg_orders',
    ];
    $query = $db->prepare($queries[$resource].' WHERE id > ? ORDER BY id ASC LIMIT '.($limit + 1));
    $query->bindValue(1, $after, PDO::PARAM_INT);
    $query->execute();
    $rows = $query->fetchAll();
    $hasMore = count($rows) > $limit;
    if ($hasMore) array_pop($rows);
    foreach ($rows as &$row) {
        $row['id'] = (int)$row['id'];
        if ($resource === 'inquiries') {
            $material = json_decode($row['material_json'] ?? '', true);
            unset($row['material_json']);
            $row['source'] = is_array($material) && ($material['source'] ?? '') === 'website_contact' ? 'website_contact' : 'electric';
            $row['message'] = $row['source'] === 'website_contact' && is_string($material['message'] ?? null) ? mb_substr($material['message'], 0, 3000, 'UTF-8') : '';
        }
    }
    unset($row);
    $db->commit();
    nas_answer(200, ['version'=>1, 'resource'=>$resource, 'items'=>$rows,
        'next_after_id'=>$rows ? (int)$rows[count($rows)-1]['id'] : $after, 'has_more'=>$hasMore]);
} catch (Throwable $error) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log('PDG NAS export: '.get_class($error));
    nas_answer(503, ['error'=>'export_unavailable']);
}

