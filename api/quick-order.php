<?php
declare(strict_types=1);
require __DIR__.'/shop-common.php';
try {
    $db=shop_db();
    $account=pdg_pricing_account($db);
    shop_session();
    if ($_SERVER['REQUEST_METHOD']==='GET') {
        $_SESSION['quick_key']??=bin2hex(random_bytes(32));
        shop_answer(200,['csrf'=>$_SESSION['csrf'],'request_key'=>$_SESSION['quick_key']]);
    }
    $input=shop_input();
    $key=$input['request_key']??'';
    if(!is_string($key)||!preg_match('/^[a-f0-9]{64}$/D',$key))shop_answer(422,['error'=>'invalid_input']);
    $request=hash('sha256',session_id().':quick:'.$key);
    if(isset($_SESSION['quick_receipt'][$request]))shop_answer(200,$_SESSION['quick_receipt'][$request]);
    if(!hash_equals($_SESSION['quick_key']??'',$key))shop_answer(409,['error'=>'request_expired']);
    $id=$input['product_id']??null;$qty=$input['quantity']??null;
    if(!is_int($id)||$id<1||!is_int($qty)||$qty<1||$qty>999)shop_answer(422,['error'=>'invalid_input']);
    $customer=[];
    foreach(['name'=>120,'phone'=>40,'notes'=>1500] as $field=>$limit){
        $v=$input[$field]??'';
        if(!is_string($v)||strlen($v)>$limit||preg_match('/[\x00-\x1F\x7F]/',$v)||($field!=='notes'&&trim($v)===''))shop_answer(422,['error'=>'invalid_customer']);
        $customer[$field]=trim($v);
    }
    if(!preg_match('/^[+0-9() .-]{6,40}$/D',$customer['phone'])||strlen(preg_replace('/\D/','',$customer['phone']))<6||($input['acknowledge']??false)!==true||($input['website']??'')!=='')shop_answer(422,['error'=>'invalid_customer']);
    $db->exec(file_get_contents(dirname(__DIR__).'/database/orders-schema.sql'));
    pdg_commerce_schema($db);
    $settings=require dirname(__DIR__,2).'/pdg-shop-db.php';
    $ip=hash_hmac('sha256','quick:'.($_SERVER['REMOTE_ADDR']??''),(string)$settings['password']);
    $db->beginTransaction();
    $q=$db->prepare('INSERT INTO pdg_checkout_limits(identifier_hash) VALUES(?) ON DUPLICATE KEY UPDATE identifier_hash=VALUES(identifier_hash)');$q->execute([$ip]);
    $q=$db->prepare('SELECT attempt_count,window_start < DATE_SUB(NOW(),INTERVAL 1 HOUR) AS expired FROM pdg_checkout_limits WHERE identifier_hash=? FOR UPDATE');$q->execute([$ip]);$rate=$q->fetch();
    $count=(int)$rate['expired']?0:(int)$rate['attempt_count'];
    if($count>=10){$db->rollBack();shop_answer(429,['error'=>'try_later']);}
    $q=$db->prepare('SELECT order_number,total_cents FROM pdg_orders WHERE request_key=?');$q->execute([$request]);$previous=$q->fetch();
    if($previous){$db->rollBack();shop_answer(200,['ok'=>true,'order_number'=>$previous['order_number'],'total_cents'=>(int)$previous['total_cents'],'currency'=>'EUR']);}
    $q=$db->prepare('SELECT id,code,name,price FROM products WHERE id=? AND active=1 FOR UPDATE');$q->execute([$id]);$p=$q->fetch();
    $q=$db->prepare('SELECT product_id FROM pdg_product_operations WHERE product_id=? FOR UPDATE');$q->execute([$id]);$q->fetchAll();
    $unit=$p?shop_cents($p['price']):null;
    $unit=$unit===null?null:pdg_discounted_cents($unit,$account['discount_bp']??0);
    $op=pdg_operations($db,$id);
    if(!$p||!pdg_sale_allowed($unit,$qty,$op)){$db->rollBack();shop_answer(409,['error'=>'product_unavailable']);}
    if(($input['unit_cents']??null)!==$unit){$db->rollBack();shop_answer(409,['error'=>'price_changed']);}
    $subtotal=$unit*$qty;$vat=intdiv($subtotal*20+50,100);$total=$subtotal+$vat;
    $number='PDG-Q-'.gmdate('Ymd').'-'.strtoupper(bin2hex(random_bytes(5)));
    $q=$db->prepare('INSERT INTO pdg_orders(order_number,request_key,customer_name,email,phone,city,address,notes,subtotal_cents,vat_cents,total_cents) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
    $q->execute([$number,$request,$customer['name'],'',$customer['phone'],'','','Бърза поръчка — адресът и доставката се уточняват по телефона. '.$customer['notes'],$subtotal,$vat,$total]);
    $orderId=(int)$db->lastInsertId();
    $q=$db->prepare('INSERT INTO pdg_order_items(order_id,product_id,code,name,quantity,unit_cents,line_cents) VALUES(?,?,?,?,?,?,?)');$q->execute([$orderId,$id,$p['code'],$p['name'],$qty,$unit,$subtotal]);
    if($account){$q=$db->prepare('INSERT INTO pdg_order_owners(order_id,user_id,delivery_method) VALUES(?,?,?)');$q->execute([$orderId,$account['id'],'quote']);}
    $q=$db->prepare('INSERT INTO pdg_order_costs(order_id,product_id,cost_cents) VALUES(?,?,?)');$q->execute([$orderId,$id,$op['cost_cents']]);
    if($op['stock_quantity']!==null){
        $q=$db->prepare('UPDATE pdg_product_operations SET stock_quantity=stock_quantity-? WHERE product_id=? AND stock_quantity>=?');$q->execute([$qty,$id,$qty]);
        if(!$q->rowCount())throw new RuntimeException('stock_changed');
        $q=$db->prepare('INSERT INTO pdg_order_stock(order_id,product_id,quantity) VALUES(?,?,?)');$q->execute([$orderId,$id,$qty]);
    }
    $q=$db->prepare('UPDATE pdg_checkout_limits SET attempt_count=?,window_start=IF(?,NOW(),window_start) WHERE identifier_hash=?');$q->execute([$count+1,(int)$rate['expired'],$ip]);
    $db->commit();
    $receipt=['ok'=>true,'order_number'=>$number,'total_cents'=>$total,'currency'=>'EUR'];
    $_SESSION['quick_receipt']=[$request=>$receipt];$_SESSION['quick_key']=bin2hex(random_bytes(32));
    $body="Нова бърза поръчка: $number\nИме: {$customer['name']}\nТелефон: {$customer['phone']}\nПродукт: {$p['name']} ({$p['code']})\nКоличество: $qty\nПродукти с ДДС: ".number_format($total/100,2,'.','')." EUR\nБележка: {$customer['notes']}\nАдминистрация: https://pdgsystems.bg/admin-orders.html?order_id=$orderId";
    try{$sent=mail('pdg2019@abv.bg','PDG Systems - Quick order '.$number,$body,['From'=>'no-reply@pdgsystems.bg','Content-Type'=>'text/plain; charset=UTF-8']);}catch(Throwable $e){$sent=false;}
    if(!$sent)error_log('PDG quick order notification failed: '.$number);
    shop_answer(201,$receipt);
} catch(Throwable $e){
    if(isset($db)&&$db->inTransaction())$db->rollBack();
    error_log('PDG quick order: '.get_class($e));shop_answer(503,['error'=>'service_unavailable']);
}
