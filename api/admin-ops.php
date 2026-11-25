<?php
declare(strict_types=1);
require_once __DIR__.'/admin-common.php';

function ops_tables(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS pdg_ops_snapshot (id TINYINT PRIMARY KEY, payload MEDIUMTEXT NOT NULL, updated TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS pdg_ops_queue (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, action VARCHAR(16) NOT NULL, payload TEXT NOT NULL, author VARCHAR(254) NOT NULL, status ENUM('pending','done','failed') NOT NULL DEFAULT 'pending', error VARCHAR(255), created TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX(status,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function ops_login_table(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS pdg_ops_login_ticket (token CHAR(64) PRIMARY KEY, owner_id BIGINT UNSIGNED NOT NULL, audience VARCHAR(16) NOT NULL, expires DATETIME NOT NULL) ENGINE=InnoDB");
}

function ops_incoming(PDO $db,array $cursors): array {
    $base=[
        'order'=>["pdg_orders o", "o.id","o.order_number AS reference,o.customer_name AS customer,CONCAT('Поръчка ',o.order_number) AS title,LEFT(COALESCE(o.notes,''),1000) AS detail,o.status,o.city AS place", "o.status IN ('new','confirmed','processing')"],
        'enquiry'=>["pdg_electric_enquiries e", "e.id","e.public_ref AS reference,e.customer_name AS customer,e.project_name AS title,LEFT(CASE WHEN JSON_VALID(e.material_json) THEN COALESCE(JSON_UNQUOTE(JSON_EXTRACT(e.material_json,'$.message')),'') ELSE '' END,1000) AS detail,e.status,'' AS place", "e.status='new'"],
        'cad'=>["pdg_cad_project_requests r JOIN pdg_cad_projects p ON p.id=r.project_id JOIN pdg_users u ON u.id=p.user_id", "r.id","CAST(r.id AS CHAR) AS reference,u.email AS customer,p.project_name AS title,LEFT(COALESCE(r.problem,''),1000) AS detail,r.status,p.editor AS place", "r.status='review'"],
    ];
    $events=[];$next=[];
    foreach($base as $kind=>[$source,$id,$columns,$pending]){
        try {
            $bootstrap=!isset($cursors[$kind]);
            $last=max(0,(int)($cursors[$kind]??0));
            if($bootstrap)$last=(int)$db->query("SELECT COALESCE(MAX($id),0) FROM $source")->fetchColumn();
            $filter=$bootstrap?"$id<=? AND ($pending)":"$id>?";
            $direction=$bootstrap?'DESC':'ASC';
            $q=$db->prepare("SELECT $id AS id,$columns FROM $source WHERE $filter ORDER BY $id $direction LIMIT 40");
            $q->execute([$last]);$rows=$q->fetchAll();
            foreach($rows as $row){
                $row['id']=(int)$row['id'];
                $events[]=$row+['kind'=>$kind,'notify'=>!$bootstrap];
                $last=max($last,$row['id']);
            }
            $next[$kind]=$last;
        }catch(PDOException $e){if(($e->errorInfo[1]??0)!==1146)throw $e;}
    }
    return ['events'=>$events,'cursors'=>$next];
}

function ops_apply_incoming(PDO $db, array $changes): array {
    $results=[];
    foreach(array_slice($changes,0,20) as $change){
        if(!is_array($change))continue;
        $kind=$change['kind']??null;$id=$change['id']??null;$status=$change['status']??null;
        if(!is_int($id)||$id<1||!(($kind==='order'&&$status==='processing')||
            (in_array($kind,['cad','enquiry'],true)&&$status==='processed')))continue;
        $result=['kind'=>$kind,'id'=>$id,'status'=>$status,'ok'=>false];
        try{
            if($kind==='order'){
                require_once __DIR__.'/commerce-common.php';
                pdg_commerce_schema($db);
                $db->beginTransaction();
                $q=$db->prepare('SELECT status FROM pdg_orders WHERE id=? FOR UPDATE');
                $q->execute([$id]);$old=$q->fetchColumn();
                if(!in_array($old,['new','confirmed','processing'],true))throw new DomainException('Статусът на поръчката вече е променен.');
                if($old!=='processing'){
                    pdg_adjust_order_stock($db,$id,true);
                    $q=$db->prepare("UPDATE pdg_orders SET status='processing' WHERE id=?");$q->execute([$id]);
                }
            }elseif($kind==='cad'){
                $db->beginTransaction();
                $q=$db->prepare('SELECT project_id,revision,status FROM pdg_cad_project_requests WHERE id=? FOR UPDATE');
                $q->execute([$id]);$row=$q->fetch();
                if(!$row)throw new DomainException('CAD заявката липсва.');
                if($row['status']!=='ops_processed'){
                    $q=$db->prepare("UPDATE pdg_cad_project_requests SET status='ops_processed',updated_at=CURRENT_TIMESTAMP WHERE id=?");$q->execute([$id]);
                    $q=$db->prepare("UPDATE pdg_cad_project_meta SET status='ops_processed' WHERE project_id=? AND revision=? AND status IN ('shared','review')");
                    $q->execute([$row['project_id'],(int)$row['revision']+1]);
                }
            }else{
                $db->exec("CREATE TABLE IF NOT EXISTS pdg_enquiry_reads (enquiry_id BIGINT UNSIGNED PRIMARY KEY,read_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
                $db->beginTransaction();
                $q=$db->prepare('SELECT status FROM pdg_electric_enquiries WHERE id=? FOR UPDATE');$q->execute([$id]);
                if(!$q->fetchColumn())throw new DomainException('Запитването липсва.');
                $q=$db->prepare('INSERT INTO pdg_enquiry_reads(enquiry_id) VALUES(?) ON DUPLICATE KEY UPDATE enquiry_id=enquiry_id');
                $q->execute([$id]);
            }
            $db->commit();$result['ok']=true;
        }catch(DomainException|InvalidArgumentException $e){
            if($db->inTransaction())$db->rollBack();
            $result['error']=$e instanceof DomainException ? $e->getMessage() : 'Провери наличността на продуктите в поръчката.';
        }catch(Throwable $e){
            if($db->inTransaction())$db->rollBack();
            error_log('Ops inbound status could not be applied: '.get_class($e));
            continue;
        }
        $results[]=$result;
    }
    return $results;
}

function ops_input(int $limit): array {
    $body=file_get_contents('php://input',false,null,0,$limit+1);
    if($body===false||strlen($body)>$limit)pdg_answer(413,['error'=>'Заявката е прекалено голяма.']);
    $data=json_decode($body,true);
    if(!is_array($data))pdg_answer(422,['error'=>'Невалидна заявка.']);
    return $data;
}

try {
    if(isset($_GET['sync']) || isset($_GET['claim_login'])){
        $authorization=$_SERVER['HTTP_AUTHORIZATION']??'';
        if($_SERVER['REQUEST_METHOD']!=='POST'||!str_starts_with($authorization,'Bearer ')||
            !hash_equals('c75eeba13cb8deb0f2b7c91557f67ddf3e48cae8cf62d3d95b74e33e6ae1f3a2',hash('sha256',substr($authorization,7))))pdg_answer(401,['error'=>'unauthorized']);
        $s=require dirname(__DIR__,2).'/pdg-shop-db.php';
        $db=new PDO('mysql:host='.($s['host']??'localhost').';dbname='.$s['database'].';charset=utf8mb4',$s['username'],$s['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        if(isset($_GET['claim_login'])){
            ops_login_table($db);
            $body=ops_input(1024);
            $ticket=$body['ticket']??null;
            $audience=$body['audience']??null;
            if(!is_string($ticket)||!preg_match('/^[a-f0-9]{64}$/D',$ticket)||!in_array($audience,['local','public'],true))
                pdg_answer(422,['error'=>'invalid_ticket']);
            $db->beginTransaction();
            $q=$db->prepare("SELECT t.owner_id FROM pdg_ops_login_ticket t JOIN pdg_users u ON u.id=t.owner_id WHERE t.token=? AND t.audience=? AND t.expires>NOW() AND u.is_active=1 AND u.role='admin' AND u.id=(SELECT MIN(id) FROM pdg_users WHERE role='admin') FOR UPDATE");
            $hash=hash('sha256',$ticket);
            $q->execute([$hash,$audience]);
            $owner=$q->fetchColumn();
            if(!$owner){$db->rollBack();pdg_answer(401,['error'=>'invalid_ticket']);}
            $q=$db->prepare('DELETE FROM pdg_ops_login_ticket WHERE token=?');
            $q->execute([$hash]);
            $db->commit();
            pdg_answer(200,['owner'=>true]);
        }
        ops_tables($db);
        $data=ops_input(2000000);
        $state=$data['state']??null;
        $results=$data['results']??null;
        if(!is_array($state)||!is_array($results)||count($results)>30)pdg_answer(422,['error'=>'invalid_snapshot']);
        foreach(['orders','tasks','deliveries','notes'] as $key)if(!is_array($state[$key]??null))pdg_answer(422,['error'=>'invalid_snapshot']);
        $db->beginTransaction();
        $update=$db->prepare("UPDATE pdg_ops_queue SET status=?,error=? WHERE id=? AND status='pending'");
        foreach($results as $row){
            if(!is_array($row)||!is_int($row['id']??null))continue;
            $error=is_string($row['error']??null)?$row['error']:null;
            $update->execute([$error===null?'done':'failed',$error,$row['id']]);
        }
        $q=$db->prepare('INSERT INTO pdg_ops_snapshot (id,payload) VALUES (1,?) ON DUPLICATE KEY UPDATE payload=VALUES(payload),updated=CURRENT_TIMESTAMP');
        $q->execute([json_encode($state,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
        $db->commit();
        $commands=$db->query("SELECT id,action,payload,author FROM pdg_ops_queue WHERE status='pending' ORDER BY id LIMIT 30")->fetchAll();
        foreach($commands as &$row){$row['id']=(int)$row['id'];$row['payload']=json_decode($row['payload'],true);}
        $response=['commands'=>$commands];
        if(is_array($data['incoming_cursors']??null)){
            $response['incoming']=ops_incoming($db,$data['incoming_cursors']);
            $response['incoming_results']=ops_apply_incoming($db,is_array($data['incoming_changes']??null)?$data['incoming_changes']:[]);
        }
        pdg_answer(200,$response);
    }

    [$db,$actor]=isset($_GET['login']) ? pdg_account_session(true,'/auth.html?next=admin-dashboard.html') : pdg_admin_session();
    if($actor!==(int)$db->query("SELECT MIN(id) FROM pdg_users WHERE role='admin'")->fetchColumn())pdg_answer(403,['error'=>'Достъпът е ограничен до собственика.']);
    if(isset($_GET['login'])){
        if($_SERVER['REQUEST_METHOD']!=='GET')pdg_answer(405,['error'=>'method_not_allowed']);
        $audience=$_GET['login'];
        if(!in_array($audience,['local','public'],true))pdg_answer(422,['error'=>'invalid_audience']);
        ops_login_table($db);
        $db->exec('DELETE FROM pdg_ops_login_ticket WHERE expires<=NOW()');
        $ticket=bin2hex(random_bytes(32));
        $q=$db->prepare('INSERT INTO pdg_ops_login_ticket VALUES(?,?,?,DATE_ADD(NOW(),INTERVAL 60 SECOND))');
        $q->execute([hash('sha256',$ticket),$actor,$audience]);
        $target=$audience==='local'?'http://127.0.0.1:13010/':'https://ops.pdgsystems.bg/';
        header('Referrer-Policy: no-referrer');
        $view=$_GET['view']??'';
        $route=in_array($view,['team','alerts','mine','sent','incoming'],true)?'&view='.$view:'';
        header('Location: '.$target.'#login='.$ticket.$route,true,303);
        exit;
    }
    ops_tables($db);
    if($_SERVER['REQUEST_METHOD']==='POST'){
        $data=ops_input(8192);
        $action=$data['action']??null;
        $payload=$data['payload']??null;
        if(!in_array($action,['order','task','note','complete','priority','order_complete','order_reopen'],true)||!is_array($payload))pdg_answer(422,['error'=>'Невалидна операция.']);
        $q=$db->prepare('INSERT INTO pdg_ops_queue(action,payload,author) VALUES(?,?,?)');
        $q->execute([$action,json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),(string)$actor]);
        pdg_answer(202,['id'=>(int)$db->lastInsertId()]);
    }
    if($_SERVER['REQUEST_METHOD']!=='GET')pdg_answer(405,['error'=>'method_not_allowed']);
    $row=$db->query('SELECT payload, UNIX_TIMESTAMP(updated) AS updated FROM pdg_ops_snapshot WHERE id=1')->fetch();
    $queue=$db->query("SELECT id,action,status,error FROM pdg_ops_queue ORDER BY id DESC LIMIT 12")->fetchAll();
    pdg_answer(200,['state'=>$row?json_decode($row['payload'],true):null,'updated'=>$row?(int)$row['updated']:null,'queue'=>$queue]);
} catch(Throwable $error) {
    if(isset($db)&&$db->inTransaction())$db->rollBack();
    pdg_answer(503,['error'=>'PDG Ops временно е недостъпен.']);
}
