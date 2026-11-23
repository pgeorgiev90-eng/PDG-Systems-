<?php
declare(strict_types=1);
require_once __DIR__.'/admin-common.php';

try {
    [$db] = pdg_admin_session();
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'GET') {
        try {
            $rows = $db->query('SELECT category,facet_keys FROM pdg_catalog_filter_settings')->fetchAll();
        } catch (PDOException $e) {
            if ($e->getCode() !== '42S02') throw $e;
            $rows = [];
        }
        foreach ($rows as &$row) $row['facet_keys'] = json_decode($row['facet_keys'], true, 512, JSON_THROW_ON_ERROR);
        unset($row);
        pdg_answer(200, ['settings'=>$rows]);
    }
    if ($method !== 'POST') pdg_answer(405, ['error'=>'method_not_allowed']);
    $body = file_get_contents('php://input', false, null, 0, 8193);
    if (strlen($body) > 8192) pdg_answer(413, ['error'=>'invalid_request']);
    $data = json_decode($body, true);
    $category = is_array($data) && is_string($data['category'] ?? null) ? trim($data['category']) : '';
    $keys = $data['facet_keys'] ?? null;
    if ($category === '' || mb_strlen($category, 'UTF-8') > 190 || !is_array($data)
        || !array_key_exists('facet_keys', $data) || ($keys !== null && (!is_array($keys) || count($keys) > 40))) {
        pdg_answer(422, ['error'=>'invalid_request']);
    }
    if ($keys !== null) {
        foreach ($keys as $key) {
            if (!is_string($key) || !preg_match('/^[a-z_]{1,30}$/D', $key)) pdg_answer(422, ['error'=>'invalid_request']);
        }
    }

    $db->exec('CREATE TABLE IF NOT EXISTS pdg_catalog_filter_settings (
        category VARCHAR(190) NOT NULL PRIMARY KEY,
        facet_keys TEXT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    if ($keys === null) {
        $query = $db->prepare('DELETE FROM pdg_catalog_filter_settings WHERE category=?');
        $query->execute([$category]);
    } else {
        $json = json_encode(array_values(array_unique($keys)), JSON_THROW_ON_ERROR);
        $query = $db->prepare('INSERT INTO pdg_catalog_filter_settings (category,facet_keys) VALUES (?,?)
            ON DUPLICATE KEY UPDATE facet_keys=?');
        $query->execute([$category, $json, $json]);
    }
    pdg_answer(200, ['ok'=>true]);
} catch (Throwable $e) {
    pdg_answer(503, ['error'=>'service_unavailable']);
}
