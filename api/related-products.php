<?php
declare(strict_types=1);
require __DIR__.'/shop-common.php';
require __DIR__.'/related-products-common.php';
try {
    if($_SERVER['REQUEST_METHOD']!=='GET')shop_answer(405,['error'=>'method_not_allowed']);
    $id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);
    if(!$id||$id<1)shop_answer(422,['error'=>'invalid_product']);
    $db=shop_db();$q=$db->prepare('SELECT id,brand,category,price FROM products WHERE id=? AND active=1');$q->execute([$id]);$p=$q->fetch();
    if(!$p)shop_answer(404,['error'=>'not_found']);
    $account=pdg_pricing_account($db);
    $groups=pdg_related_products($db,$p);
    foreach($groups as &$items)foreach($items as &$item){$item['id']=(int)$item['id'];$item['price']=pdg_product_price($item['price'],$account);}
    unset($items,$item);
    shop_answer(200,$groups);
} catch(Throwable $e){shop_answer(503,['error'=>'service_unavailable']);}
