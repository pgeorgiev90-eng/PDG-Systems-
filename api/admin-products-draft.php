<?php
declare(strict_types=1);
require_once __DIR__.'/admin-common.php';

function productFields(PDO $db, int $id): array {
    $query = $db->prepare('SELECT label,value FROM pdg_product_fields WHERE product_id=? ORDER BY sort_order,id');
    $query->execute([$id]);
    return $query->fetchAll();
}

function productText(mixed $value, int $limit, bool $required = false): string {
    if (!is_string($value) || mb_strlen(trim($value), 'UTF-8') > $limit || ($required && trim($value) === '')) {
        pdg_answer(422, ['error'=>'invalid_product']);
    }
    return trim($value);
}

function productImage(mixed $value): ?string {
    $url = productText($value, 2048);
    if ($url === '') return null;
    if (preg_match('~^(?:/?assets/)[a-z0-9_./%-]+$~iD', $url) && !preg_match('~(?:^|/)\.\.(?:/|$)|%~', $url)) return $url;
    if (filter_var($url, FILTER_VALIDATE_URL) && parse_url($url, PHP_URL_SCHEME) === 'https'
        && parse_url($url, PHP_URL_USER) === null && parse_url($url, PHP_URL_PASS) === null) return $url;
    pdg_answer(422, ['error'=>'invalid_image']);
}

function productVersion(array $product, array $fields): string {
    return hash('sha256', json_encode([$product, $fields], JSON_THROW_ON_ERROR));
}

try {
    [$db] = pdg_admin_session();
    $columns = 'id,brand,category,code,name,price,active,image_url,image_index';
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'GET') {
        if (!isset($_GET['id'])) {
            $products = $db->query("SELECT $columns FROM products ORDER BY id DESC")->fetchAll();
            pdg_answer(200, ['products'=>$products]);
        }
        $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        if (!$id || $id < 1) pdg_answer(422, ['error'=>'invalid_product']);
        $db->beginTransaction();
        $query = $db->prepare("SELECT $columns FROM products WHERE id=?");
        $query->execute([$id]);
        $product = $query->fetch();
        if (!$product) pdg_answer(404, ['error'=>'not_found']);
        $fields = productFields($db, $id);
        $db->commit();
        pdg_answer(200, ['product'=>$product,'fields'=>$fields,'version'=>productVersion($product, $fields)]);
    }
    if ($method !== 'POST') pdg_answer(405, ['error'=>'method_not_allowed']);
    $payload = file_get_contents('php://input', false, null, 0, 131073);
    if (strlen($payload) > 131072) pdg_answer(413, ['error'=>'invalid_request']);
    $data = json_decode($payload, true);
    if (!is_array($data)) pdg_answer(400, ['error'=>'invalid_request']);
    $values = [];
    foreach (['brand'=>120,'category'=>190,'code'=>190,'name'=>500] as $key=>$length) {
        $values[] = productText($data[$key] ?? null, $length, true);
    }
    $price = $data['price'] ?? null;
    if ($price === '') $price = null;
    if ($price !== null && (!is_string($price) || !preg_match('/^(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?$/D', $price))) {
        pdg_answer(422, ['error'=>'invalid_price']);
    }
    $active = $data['active'] ?? true;
    $id = filter_var($data['id'] ?? 0, FILTER_VALIDATE_INT);
    if (!is_bool($active) || $id === false || $id < 0) pdg_answer(422, ['error'=>'invalid_product']);
    $values[] = $price;
    $values[] = (int)$active;
    $image = array_key_exists('image_url', $data) ? productImage($data['image_url']) : null;
    $fields = null;
    if (array_key_exists('fields', $data)) {
        if (!is_array($data['fields']) || count($data['fields']) > 100) pdg_answer(422, ['error'=>'invalid_product']);
        $fields = [];
        foreach ($data['fields'] as $field) {
            if (!is_array($field)) pdg_answer(422, ['error'=>'invalid_product']);
            $label = productText($field['label'] ?? null, 100, true);
            $value = productText($field['value'] ?? null, 10000, true);
            $fields[] = ['label'=>$label,'value'=>$value];
        }
    }

    $db->beginTransaction();
    if ($id > 0) {
        $query = $db->prepare("SELECT $columns FROM products WHERE id=? FOR UPDATE");
        $query->execute([$id]);
        $current = $query->fetch();
        if (!$current) pdg_answer(404, ['error'=>'not_found']);
        if ($fields !== null && (!is_string($data['version'] ?? null)
            || !hash_equals(productVersion($current, productFields($db, $id)), $data['version']))) {
            pdg_answer(409, ['error'=>'changed_refresh']);
        }
        $query = $db->prepare('UPDATE products SET brand=?,category=?,code=?,name=?,price=?,active=? WHERE id=?');
        $query->execute([...$values, $id]);
    } else {
        $query = $db->prepare('INSERT INTO products (brand,category,code,name,price,active) VALUES (?,?,?,?,?,?)');
        $query->execute($values);
        $id = (int)$db->lastInsertId();
    }

    if (array_key_exists('image_url', $data)) {
        $query = $db->prepare('UPDATE products SET image_url=? WHERE id=?');
        $query->execute([$image, $id]);
    }
    if ($fields !== null) {
        $query = $db->prepare('DELETE FROM pdg_product_fields WHERE product_id=?');
        $query->execute([$id]);
        $query = $db->prepare('INSERT INTO pdg_product_fields (product_id,label,value,sort_order) VALUES (?,?,?,?)');
        foreach ($fields as $index=>$field) $query->execute([$id, $field['label'], $field['value'], $index]);
    }
    $db->commit();
    pdg_answer(200, ['ok'=>true,'id'=>$id]);
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    pdg_answer(503, ['error'=>'service_unavailable']);
}
