<?php
declare(strict_types=1);
require __DIR__.'/admin-common.php';
require __DIR__.'/related-products-common.php';
try {
    [$db]=pdg_admin_session();pdg_related_schema($db);
    if($_SERVER['REQUEST_METHOD']==='GET'){
        $id=filter_input(INPUT_GET,'product_id',FILTER_VALIDATE_INT);
        if(!$id||$id<1)pdg_answer(422,['error'=>'invalid_product']);
        $q=$db->prepare('SELECT id,name,code FROM products WHERE id=?');$q->execute([$id]);$p=$q->fetch();if(!$p)pdg_answer(404,['error'=>'not_found']);
        $links=pdg_related_links($db,$id);$q=$db->query('SELECT id,name,code,brand,category FROM products WHERE active=1 ORDER BY name LIMIT 10000');
        pdg_answer(200,['product'=>$p,'links'=>$links,'version'=>pdg_related_version($links),'products'=>$q->fetchAll()]);
    }
    if($_SERVER['REQUEST_METHOD']!=='POST')pdg_answer(405,['error'=>'method_not_allowed']);
    if((int)($_SERVER['CONTENT_LENGTH']??0)>12000)pdg_answer(413,['error'=>'invalid_input']);
    $v=json_decode(file_get_contents('php://input',false,null,0,12001),true);
    if(!is_array($v)||!is_int($v['product_id']??null)||$v['product_id']<1||!is_array($v['links']??null)||count($v['links'])>6||!is_string($v['version']??null)||($v['verified']??false)!==true)pdg_answer(422,['error'=>'invalid_input']);
    $id=$v['product_id'];$seen=[];$clean=[];
    foreach($v['links'] as $link){
        if(!is_array($link)||!is_int($link['id']??null)||$link['id']<1||$link['id']===$id||isset($seen[$link['id']])||!is_string($link['note']??null)||trim($link['note'])===''||mb_strlen($link['note'],'UTF-8')>200)pdg_answer(422,['error'=>'invalid_links']);
        $seen[$link['id']]=true;$clean[]=['id'=>$link['id'],'note'=>trim($link['note'])];
    }
    $db->beginTransaction();$q=$db->prepare('SELECT id FROM products WHERE id=? FOR UPDATE');$q->execute([$id]);if(!$q->fetch()){$db->rollBack();pdg_answer(404,['error'=>'not_found']);}
    if(!hash_equals(pdg_related_version(pdg_related_links($db,$id)),$v['version'])){$db->rollBack();pdg_answer(409,['error'=>'changed_refresh']);}
    foreach($clean as $link){$q=$db->prepare('SELECT id FROM products WHERE id=? AND active=1');$q->execute([$link['id']]);if(!$q->fetch()){$db->rollBack();pdg_answer(422,['error'=>'invalid_links']);}}
    $q=$db->prepare('DELETE FROM pdg_product_links WHERE product_id=?');$q->execute([$id]);
    $q=$db->prepare('INSERT INTO pdg_product_links(product_id,related_id,note,sort_order) VALUES(?,?,?,?)');foreach($clean as $i=>$link)$q->execute([$id,$link['id'],$link['note'],$i]);
    $db->commit();pdg_answer(200,['ok'=>true,'version'=>pdg_related_version(pdg_related_links($db,$id))]);
} catch(Throwable $e){if(isset($db)&&$db->inTransaction())$db->rollBack();pdg_answer(503,['error'=>'service_unavailable']);}
