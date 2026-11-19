<?php
declare(strict_types=1);

require __DIR__.'/shop-common.php';

try {
    shop_session();
    if ($_SERVER['REQUEST_METHOD']==='GET') {
        $key = bin2hex(random_bytes(24));
        $_SESSION['contact_requests'][$key] = null;
        $_SESSION['contact_requests'] = array_slice($_SESSION['contact_requests'], -32, null, true);
        shop_answer(200, ['csrf'=>$_SESSION['csrf'], 'request_key'=>$key]);
    }

    $input = shop_input();
    $key = $input['request_key'] ?? '';
    if (!is_string($key) || !array_key_exists($key, $_SESSION['contact_requests'] ?? [])) {
        shop_answer(409, ['error'=>'request_expired']);
    }
    if (isset($_SESSION['contact_requests'][$key])) {
        shop_answer(200, $_SESSION['contact_requests'][$key]);
    }
    if (time() - (int)($_SESSION['contact_last'] ?? 0) < 30) {
        shop_answer(429, ['error'=>'try_later']);
    }
    $fields = [];
    foreach (['name'=>160, 'email'=>190, 'message'=>3000] as $field=>$limit) {
        $value = $input[$field] ?? null;
        if (!is_string($value) || trim($value)==='' || mb_strlen($value, 'UTF-8') > $limit) {
            shop_answer(422, ['error'=>'invalid_contact']);
        }
        $fields[$field] = trim($value);
    }
    if (($input['website'] ?? '') !== '' || !filter_var($fields['email'], FILTER_VALIDATE_EMAIL)) {
        shop_answer(422, ['error'=>'invalid_contact']);
    }

    $db = shop_db();
    $ref = substr(hash('sha256', $key), 0, 24);
    $payload = ['source'=>'website_contact', 'message'=>$fields['message']];
    $projectName = 'Запитване от сайта';
    $subject = 'PDG Systems - Website enquiry '.$ref;
    if (array_key_exists('product_id', $input)) {
        $productId = $input['product_id'];
        if (!is_string($productId) || !ctype_digit($productId) || !filter_var($productId, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]])) {
            shop_answer(422, ['error'=>'invalid_product']);
        }
        $query = $db->prepare('SELECT id, code, name FROM products WHERE id=? AND active=1');
        $query->execute([$productId]);
        $product = $query->fetch();
        if (!$product) shop_answer(422, ['error'=>'invalid_product']);

        $product['url'] = 'https://pdgsystems.bg/product.html?id='.(int)$product['id'];
        $payload['product'] = $product;
        $payload['message'] = "Продукт: {$product['name']}\nКод: {$product['code']}\nЛинк: {$product['url']}\n\n{$fields['message']}";
        $productName = trim(str_replace(["\r", "\n"], ' ', $product['name']));
        $projectName = mb_substr('Запитване за '.$productName, 0, 120, 'UTF-8');
        $subject = mb_encode_mimeheader('Запитване за '.mb_substr($productName, 0, 120, 'UTF-8').' #'.$ref, 'UTF-8', 'B', "\r\n");
    }
    $insert = $db->prepare('INSERT INTO pdg_electric_enquiries (public_ref,customer_name,email,phone,project_name,material_json,materials_cents,missing_prices) VALUES (?,?,?,?,?,?,0,0)');
    $insert->execute([$ref, $fields['name'], $fields['email'], '', $projectName, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
    $result = ['ok'=>true, 'reference'=>$ref];
    $_SESSION['contact_last'] = time();
    $_SESSION['contact_requests'][$key] = $result;

    $body = "Запитване от pdgsystems.bg\nНомер: $ref\nИме: {$fields['name']}\nИмейл: {$fields['email']}\n\n{$payload['message']}";
    $headers = [
        'From'=>'no-reply@pdgsystems.bg',
        'Reply-To'=>$fields['email'],
        'Content-Type'=>'text/plain; charset=UTF-8',
    ];
    try {
        $accepted = mail('pdg2019@abv.bg', $subject, $body, $headers);
    } catch (Throwable $e) {
        $accepted = false;
    }
    if (!$accepted) error_log('PDG contact notification failed for '.$ref);
    $result['notification_accepted'] = $accepted;
    $_SESSION['contact_requests'][$key] = $result;
    shop_answer(201, $result);
} catch (Throwable $e) {
    error_log('PDG contact enquiry failed: '.get_class($e));
    shop_answer(503, ['error'=>'service_unavailable']);
}
