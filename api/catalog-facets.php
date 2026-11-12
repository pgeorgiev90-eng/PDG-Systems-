<?php
declare(strict_types=1);
require __DIR__.'/shop-common.php';
require_once __DIR__.'/public-product-fields.php';
try {
    if($_SERVER['REQUEST_METHOD']!=='GET')shop_answer(405,['error'=>'method_not_allowed']);
    $db=shop_db();$items=[];
    foreach($db->query('SELECT id,created_at FROM products WHERE active=1') as $p)$items[(int)$p['id']]=['created_at'=>$p['created_at'],'specs'=>[],'availability'=>'confirm'];
    try {
        $fields=$db->query('SELECT f.product_id,f.label,f.value FROM pdg_product_fields f JOIN products p ON p.id=f.product_id WHERE p.active=1 ORDER BY f.sort_order,f.id');
        foreach($fields as $f){
            if(!pdg_public_product_fields([$f])||mb_strlen($f['value'],'UTF-8')>160)continue;
            // Export only technical attributes used by catalog facets; no provenance, CAD or supplier data.
            if(!preg_match('/мощност|фази|mppt|енергия|капацитет|клас батери|клас на батери|клас батерия|напреженов клас|тип инвертор|тип устройство|тип компонент|тип панел|технология|двулицев|бифациал|рамка|размери|дължина|широчина|ширина|дебелина|височина|монтажна система|конектор|номинален ток|брой полюси|характеристика|изключвателна|степен на защита|материал|сечение|цвят|място на монтаж|тип монтаж|серия|проверена съвместимост|предназначение|вид дейност/iu',$f['label']))continue;
            $label=$f['label'];if(!array_key_exists($label,$items[(int)$f['product_id']]['specs']))$items[(int)$f['product_id']]['specs'][$label]=$f['value'];
        }
    }catch(PDOException $e){if($e->getCode()!=='42S02')throw $e;}
    try{foreach($db->query('SELECT o.product_id,o.stock_quantity,o.availability FROM pdg_product_operations o JOIN products p ON p.id=o.product_id WHERE p.active=1') as $op){
        $status=$op['availability'];
        if($status!=='unavailable'&&$op['stock_quantity']!==null)$status=(int)$op['stock_quantity']>0?'in_stock':'out_of_stock';
        $items[(int)$op['product_id']]['availability']=in_array($status,['in_stock','on_order','unavailable','out_of_stock'],true)?$status:'confirm';
    }}catch(PDOException $e){if($e->getCode()!=='42S02')throw $e;}
    shop_answer(200,['items'=>$items]);
}catch(Throwable $e){shop_answer(503,['error'=>'facets_unavailable']);}
