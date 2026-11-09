<?php
declare(strict_types=1);
require __DIR__.'/admin-common.php';
require_once __DIR__.'/commerce-common.php';
function growth_text($v,int $max,bool $required=false): string {
    if(!is_string($v)||mb_strlen($v)>$max||($required&&trim($v)===''))throw new InvalidArgumentException('invalid_text');
    return trim($v);
}
function growth_int($v,int $max,bool $nullable=true): ?int {
    if($nullable&&$v===null)return null;
    if(!is_int($v)||$v<0||$v>$max)throw new InvalidArgumentException('invalid_number');return $v;
}
try {
    [$db,$actor]=pdg_admin_session();pdg_commerce_schema($db);
    if($_SERVER['REQUEST_METHOD']==='GET') {
        $products=$db->query('SELECT p.id,p.brand,p.code,p.name,p.price,p.category,o.cost_cents,o.min_margin_bp,o.stock_quantity,o.lead_days,o.availability FROM products p LEFT JOIN pdg_product_operations o ON o.product_id=p.id WHERE p.active=1 ORDER BY p.id')->fetchAll();
        foreach($products as &$p){$p['margin_bp']=$p['price']>0&&$p['cost_cents']!==null?(int)round((1-((int)$p['cost_cents']/((float)$p['price']*100)))*10000):null;}unset($p);
        $requests=$db->query('SELECT r.id,r.kind,r.subject,r.details_json,r.stage,r.admin_reply,r.created_at,r.updated_at,u.email FROM pdg_customer_requests r JOIN pdg_users u ON u.id=r.user_id ORDER BY r.id DESC LIMIT 100')->fetchAll();
        $photos=$db->prepare('SELECT id FROM pdg_request_files WHERE request_id=? ORDER BY id');
        foreach($requests as &$r){$r['details']=json_decode($r['details_json'],true);unset($r['details_json']);$photos->execute([$r['id']]);$r['photos']=$photos->fetchAll(PDO::FETCH_COLUMN);}unset($r);
        $bundles=$db->query('SELECT * FROM pdg_product_bundles ORDER BY id DESC LIMIT 100')->fetchAll();
        foreach($bundles as &$b){$b['items']=json_decode($b['items_json'],true);unset($b['items_json']);}unset($b);
        pdg_answer(200,['products'=>$products,'requests'=>$requests,'bundles'=>$bundles,
            'issues'=>$db->query('SELECT * FROM pdg_development_issues ORDER BY id DESC LIMIT 100')->fetchAll(),'csrf'=>$_SESSION['csrf']]);
    }
    if($_SERVER['REQUEST_METHOD']!=='POST')pdg_answer(405,['error'=>'method_not_allowed']);
    if((int)($_SERVER['CONTENT_LENGTH']??0)>20000)pdg_answer(413,['error'=>'too_large']);
    $v=json_decode(file_get_contents('php://input',false,null,0,20001),true);if(!is_array($v))pdg_answer(422,['error'=>'invalid_input']);
    $action=$v['action']??'';
    if($action==='operations') {
        $id=growth_int($v['id']??null,2147483647,false);if(!$id)throw new InvalidArgumentException('invalid_product');
        $cost=growth_int($v['cost_cents']??null,1000000000);$margin=growth_int($v['min_margin_bp']??0,9999,false);
        $stock=growth_int($v['stock_quantity']??null,1000000);$lead=growth_int($v['lead_days']??null,3650);$availability=$v['availability']??'confirm';
        if(!in_array($availability,['confirm','in_stock','on_order','unavailable'],true)||($availability==='in_stock'&&($stock===null||$stock<1)))throw new InvalidArgumentException('invalid_availability');
        $q=$db->prepare('SELECT id FROM products WHERE id=?');$q->execute([$id]);if(!$q->fetchColumn())pdg_answer(404,['error'=>'not_found']);
        $q=$db->prepare('INSERT INTO pdg_product_operations(product_id,cost_cents,min_margin_bp,stock_quantity,lead_days,availability) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE cost_cents=VALUES(cost_cents),min_margin_bp=VALUES(min_margin_bp),stock_quantity=VALUES(stock_quantity),lead_days=VALUES(lead_days),availability=VALUES(availability)');
        $q->execute([$id,$cost,$margin,$stock,$lead,$availability]);pdg_answer(200,['ok'=>true]);
    }
    if($action==='bundle') {
        $name=growth_text($v['name']??'',160,true);$description=growth_text($v['description']??'',2000,true);$note=growth_text($v['compatibility_note']??'',2000,true);$items=pdg_clean_items($v['items']??null);
        $active=$v['active']??false;if(!is_bool($active))throw new InvalidArgumentException('invalid_input');
        if($active&&($v['compatibility_confirmed']??false)!==true)throw new InvalidArgumentException('confirm_compatibility');
        $find=$db->prepare('SELECT id FROM products WHERE id=? AND active=1');foreach($items as $i){$find->execute([$i['id']]);if(!$find->fetchColumn())throw new InvalidArgumentException('invalid_product');}
        $id=growth_int($v['id']??0,2147483647,false);
        if($id){$q=$db->prepare('UPDATE pdg_product_bundles SET name=?,description=?,items_json=?,compatibility_note=?,active=? WHERE id=?');$q->execute([$name,$description,json_encode($items),$note,(int)$active,$id]);}
        else{$q=$db->prepare('INSERT INTO pdg_product_bundles(name,description,items_json,compatibility_note,active) VALUES(?,?,?,?,?)');$q->execute([$name,$description,json_encode($items),$note,(int)$active]);$id=(int)$db->lastInsertId();}
        pdg_answer(200,['ok'=>true,'id'=>$id]);
    }
    if($action==='request') {
        $id=growth_int($v['id']??null,2147483647,false);$stage=$v['stage']??'';$reply=growth_text($v['admin_reply']??'',4000);
        if(!in_array($stage,['new','processing','waiting','confirmed','completed','declined'],true))throw new InvalidArgumentException('invalid_stage');
        $q=$db->prepare('SELECT id FROM pdg_customer_requests WHERE id=?');$q->execute([$id]);if(!$q->fetchColumn())pdg_answer(404,['error'=>'not_found']);
        $q=$db->prepare('UPDATE pdg_customer_requests SET stage=?,admin_reply=? WHERE id=?');$q->execute([$stage,$reply,$id]);pdg_answer(200,['ok'=>true]);
    }
    if($action==='issue') {
        $id=growth_int($v['id']??0,2147483647,false);$title=growth_text($v['title']??'',200,true);$verification=growth_text($v['verification']??'',2000);
        $priority=$v['priority']??'';$stage=$v['stage']??'';
        if(!in_array($priority,['high','medium','low'],true)||!in_array($stage,['new','processing','fixed','verified'],true)||($stage==='verified'&&$verification===''))throw new InvalidArgumentException('invalid_issue');
        if($id){$q=$db->prepare('UPDATE pdg_development_issues SET title=?,priority=?,stage=?,verification=? WHERE id=?');$q->execute([$title,$priority,$stage,$verification,$id]);}
        else{$q=$db->prepare('INSERT INTO pdg_development_issues(title,priority,stage,verification) VALUES(?,?,?,?)');$q->execute([$title,$priority,$stage,$verification]);$id=(int)$db->lastInsertId();}
        pdg_answer(200,['ok'=>true,'id'=>$id]);
    }
    pdg_answer(422,['error'=>'invalid_action']);
}catch(InvalidArgumentException $e){pdg_answer(422,['error'=>$e->getMessage()]);}
catch(Throwable $e){pdg_answer(503,['error'=>'service_unavailable']);}
