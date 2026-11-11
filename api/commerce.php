<?php
declare(strict_types=1);
require __DIR__.'/shop-common.php';
require_once __DIR__.'/commerce-common.php';
try {
    if($_SERVER['REQUEST_METHOD']!=='GET')shop_answer(405,['error'=>'method_not_allowed']);
    $db=shop_db();pdg_commerce_schema($db);$account=pdg_pricing_account($db);
    $rows=$db->query('SELECT id,name,description,items_json,compatibility_note FROM pdg_product_bundles WHERE active=1 ORDER BY id DESC LIMIT 100')->fetchAll();
    foreach($rows as &$row){$row['items']=json_decode($row['items_json'],true);unset($row['items_json']);}unset($row);
    $ops=$db->query('SELECT product_id,stock_quantity,lead_days,availability FROM pdg_product_operations')->fetchAll();
    shop_answer(200,['bundles'=>$rows,'operations'=>$ops,'prices_visible'=>true]);
}catch(Throwable $e){shop_answer(503,['error'=>'commerce_unavailable']);}
