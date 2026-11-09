<?php
declare(strict_types=1);
require __DIR__.'/admin-common.php';
require __DIR__.'/commerce-common.php';
try {
    [$db,$admin]=pdg_admin_session();
    $db->exec(file_get_contents(dirname(__DIR__).'/database/orders-schema.sql'));
    pdg_commerce_schema($db);
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        if ((int)($_SERVER['CONTENT_LENGTH']??0)>2048) pdg_answer(413,['error'=>'invalid_input']);
        $input=json_decode(file_get_contents('php://input',false,null,0,2049),true);
        $id=$input['id']??null;$status=$input['status']??null;
        if (!is_int($id)||$id<1||!in_array($status,['new','confirmed','processing','shipped','completed','cancelled'],true)) pdg_answer(422,['error'=>'invalid_input']);
        $db->beginTransaction();
        $q=$db->prepare('SELECT status FROM pdg_orders WHERE id=? FOR UPDATE');$q->execute([$id]);
        if(!$q->fetchColumn()){$db->rollBack();pdg_answer(404,['error'=>'not_found']);}
        pdg_adjust_order_stock($db,$id,$status!=='cancelled');
        $q=$db->prepare('UPDATE pdg_orders SET status=? WHERE id=?');$q->execute([$status,$id]);$db->commit();
        pdg_answer(200,['ok'=>true]);
    }
    if ($_SERVER['REQUEST_METHOD']!=='GET') pdg_answer(405,['error'=>'method_not_allowed']);
    $id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);
    if ($id) {
        $q=$db->prepare('SELECT id,order_number,customer_name,email,phone,city,address,notes,subtotal_cents,vat_cents,total_cents,currency,status,created_at FROM pdg_orders WHERE id=?');$q->execute([$id]);$order=$q->fetch();
        if (!$order) pdg_answer(404,['error'=>'not_found']);
        $q=$db->prepare('SELECT product_id,code,name,quantity,unit_cents,line_cents FROM pdg_order_items WHERE order_id=? ORDER BY id');$q->execute([$id]);
        pdg_answer(200,['order'=>$order,'items'=>$q->fetchAll()]);
    }
    $page=max(1,min(100000,(int)($_GET['page']??1)));$offset=($page-1)*30;
    $orders=$db->query('SELECT id,order_number,customer_name,total_cents,currency,status,created_at FROM pdg_orders ORDER BY id DESC LIMIT 30 OFFSET '.$offset)->fetchAll();
    pdg_answer(200,['orders'=>$orders,'page'=>$page,'total'=>(int)$db->query('SELECT COUNT(*) FROM pdg_orders')->fetchColumn()]);
} catch (InvalidArgumentException $e) {if(isset($db)&&$db->inTransaction())$db->rollBack();pdg_answer(409,['error'=>$e->getMessage()]);}
catch (Throwable $e) {if(isset($db)&&$db->inTransaction())$db->rollBack();pdg_answer(503,['error'=>'service_unavailable']);}
