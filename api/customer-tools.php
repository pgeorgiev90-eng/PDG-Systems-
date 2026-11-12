<?php
declare(strict_types=1);
require __DIR__.'/shop-common.php';
require_once __DIR__.'/commerce-common.php';
try {
    $db=shop_db();$account=pdg_pricing_account($db,true);
    if ($account===null) shop_answer(401,['error'=>'authentication_required']);
    shop_customer_cart();pdg_commerce_schema($db);$user=$account['id'];
    $db->exec(file_get_contents(dirname(__DIR__).'/database/orders-schema.sql'));
    if ($_SERVER['REQUEST_METHOD']==='GET') {
        $q=$db->prepare('SELECT id,name,items_json,updated_at FROM pdg_saved_lists WHERE user_id=? ORDER BY id DESC LIMIT 50');$q->execute([$user]);$lists=$q->fetchAll();
        foreach($lists as &$list){$list['items']=json_decode($list['items_json'],true);unset($list['items_json']);}unset($list);
        $q=$db->prepare('SELECT id,kind,subject,details_json,stage,admin_reply,created_at,updated_at FROM pdg_customer_requests WHERE user_id=? ORDER BY id DESC LIMIT 50');$q->execute([$user]);$requests=$q->fetchAll();
        $photos=$db->prepare('SELECT id FROM pdg_request_files WHERE request_id=? ORDER BY id');
        foreach($requests as &$row){$row['details']=json_decode($row['details_json'],true);unset($row['details_json']);$photos->execute([$row['id']]);$row['photos']=$photos->fetchAll(PDO::FETCH_COLUMN);}unset($row);
        $q=$db->prepare('SELECT o.id,o.order_number,o.total_cents,o.status,o.created_at,x.delivery_method FROM pdg_orders o JOIN pdg_order_owners x ON x.order_id=o.id WHERE x.user_id=? ORDER BY o.id DESC LIMIT 30');$q->execute([$user]);$orders=$q->fetchAll();
        $q=$db->prepare('SELECT product_id AS id,quantity FROM pdg_order_items WHERE order_id=? ORDER BY id');
        foreach($orders as &$o){$q->execute([$o['id']]);$o['items']=array_map(fn($i)=>['id'=>(int)$i['id'],'quantity'=>(int)$i['quantity']],$q->fetchAll());}unset($o);
        $q=$db->prepare('SELECT is_partner,company,vat_number FROM pdg_partner_profiles WHERE user_id=?');$q->execute([$user]);$partner=$q->fetch()?:null;
        shop_answer(200,['lists'=>$lists,'requests'=>$requests,'orders'=>$orders,'partner'=>$partner,'discount_bp'=>$account['discount_bp'],'active_orders'=>(int)(function()use($db,$user){$q=$db->prepare("SELECT COUNT(*) FROM pdg_orders o JOIN pdg_order_owners x ON x.order_id=o.id WHERE x.user_id=? AND o.status IN ('new','confirmed','processing')");$q->execute([$user]);return $q->fetchColumn();})(),'csrf'=>$_SESSION['csrf']]);
    }
    $v=shop_input();$action=$v['action']??'';
    if ($action==='save_list') {
        $name=$v['name']??'';if(!is_string($name)||trim($name)===''||mb_strlen($name)>120)shop_answer(422,['error'=>'invalid_name']);
        $items=pdg_clean_items($v['items']??null);$q=$db->prepare('SELECT COUNT(*) FROM pdg_saved_lists WHERE user_id=?');$q->execute([$user]);
        if((int)$q->fetchColumn()>=50)shop_answer(422,['error'=>'list_limit']);
        $q=$db->prepare('INSERT INTO pdg_saved_lists(user_id,name,items_json) VALUES(?,?,?)');$q->execute([$user,trim($name),json_encode($items)]);
        shop_answer(200,['ok'=>true,'id'=>(int)$db->lastInsertId()]);
    }
    if ($action==='delete_list') {
        if(!is_int($v['id']??null)||$v['id']<1)shop_answer(422,['error'=>'invalid_id']);
        $q=$db->prepare('DELETE FROM pdg_saved_lists WHERE id=? AND user_id=?');$q->execute([$v['id'],$user]);
        if(!$q->rowCount())shop_answer(404,['error'=>'not_found']);shop_answer(200,['ok'=>true]);
    }
    if (in_array($action,['preview','add_batch'],true)) {
        $items=pdg_clean_items($v['items']??null);$preview=pdg_batch_preview($db,$items,$account);
        if($action==='preview')shop_answer(200,[...$preview,'revision'=>hash('sha256',json_encode($preview))]);
        // Explicit preview confirmation. Changes in prices or operational data invalidate it.
        $revision=hash('sha256',json_encode($preview));
        if(!is_string($v['revision']??null)||!hash_equals($revision,$v['revision']))shop_answer(409,['error'=>'batch_changed','preview'=>$preview]);
        if(!$preview['can_add'])shop_answer(422,['error'=>'batch_unavailable','preview'=>$preview]);
        $next=$_SESSION['cart'];foreach($items as $item)$next[$item['id']]=($next[$item['id']]??0)+$item['quantity'];
        $combined=array_map(fn($id,$quantity)=>['id'=>$id,'quantity'=>$quantity],array_keys($next),array_values($next));
        $combined=pdg_clean_items($combined);$check=pdg_batch_preview($db,$combined,$account);
        if(!$check['can_add'])shop_answer(422,['error'=>'batch_unavailable','preview'=>$check]);
        $_SESSION['cart']=$next;$_SESSION['checkout_key']=bin2hex(random_bytes(32));shop_answer(200,['ok'=>true]);
    }
    if($action==='request') {
        $kind=$v['kind']??'';$subject=$v['subject']??'';$details=$v['details']??null;
        if(!in_array($kind,['service','survey'],true)||!is_string($subject)||trim($subject)===''||mb_strlen($subject)>160||!is_array($details))shop_answer(422,['error'=>'invalid_request']);
        $clean=[];foreach(['model','serial','firmware','message','city','object_type','preferred_period'] as $key){$value=$details[$key]??'';if(!is_string($value)||mb_strlen($value)>2000)shop_answer(422,['error'=>'invalid_details']);$clean[$key]=trim($value);}
        if($clean['message']===''||($kind==='survey'&&$clean['city']===''))shop_answer(422,['error'=>'invalid_details']);
        $q=$db->prepare('SELECT COUNT(*) FROM pdg_customer_requests WHERE user_id=? AND created_at>DATE_SUB(NOW(),INTERVAL 1 HOUR)');$q->execute([$user]);if((int)$q->fetchColumn()>=10)shop_answer(429,['error'=>'try_later']);
        $q=$db->prepare('INSERT INTO pdg_customer_requests(user_id,kind,subject,details_json) VALUES(?,?,?,?)');$q->execute([$user,$kind,trim($subject),json_encode($clean,JSON_UNESCAPED_UNICODE)]);
        shop_answer(200,['ok'=>true,'id'=>(int)$db->lastInsertId()]);
    }
    shop_answer(422,['error'=>'invalid_action']);
} catch(InvalidArgumentException $e){shop_answer(422,['error'=>$e->getMessage()]);}
catch(Throwable $e){if(isset($db)&&$db->inTransaction())$db->rollBack();shop_answer(503,['error'=>'tools_unavailable']);}
