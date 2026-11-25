<?php
declare(strict_types=1);
require_once __DIR__.'/admin-common.php';

function ops_tables(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS pdg_ops_snapshot (id TINYINT PRIMARY KEY, payload MEDIUMTEXT NOT NULL, updated TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS pdg_ops_queue (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, action VARCHAR(16) NOT NULL, payload TEXT NOT NULL, author VARCHAR(254) NOT NULL, status ENUM('pending','done','failed') NOT NULL DEFAULT 'pending', error VARCHAR(255), created TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX(status,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function ops_input(int $limit): array {
    $body=file_get_contents('php://input',false,null,0,$limit+1);
    if($body===false||strlen($body)>$limit)pdg_answer(413,['error'=>'Заявката е прекалено голяма.']);
    $data=json_decode($body,true);
    if(!is_array($data))pdg_answer(422,['error'=>'Невалидна заявка.']);
    return $data;
}

try {
    if(isset($_GET['sync'])){
        $authorization=$_SERVER['HTTP_AUTHORIZATION']??'';
        if($_SERVER['REQUEST_METHOD']!=='POST'||!str_starts_with($authorization,'Bearer ')||
            !hash_equals('c75eeba13cb8deb0f2b7c91557f67ddf3e48cae8cf62d3d95b74e33e6ae1f3a2',hash('sha256',substr($authorization,7))))pdg_answer(401,['error'=>'unauthorized']);
        $s=require dirname(__DIR__,2).'/pdg-shop-db.php';
        $db=new PDO('mysql:host='.($s['host']??'localhost').';dbname='.$s['database'].';charset=utf8mb4',$s['username'],$s['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
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
        pdg_answer(200,['commands'=>$commands]);
    }

    [$db,$actor]=pdg_admin_session();
    if($actor!==(int)$db->query("SELECT MIN(id) FROM pdg_users WHERE role='admin'")->fetchColumn())pdg_answer(403,['error'=>'Достъпът е ограничен до собственика.']);
    ops_tables($db);
    if($_SERVER['REQUEST_METHOD']==='POST'){
        $data=ops_input(4096);
        $action=$data['action']??null;
        $payload=$data['payload']??null;
        if(!in_array($action,['order','task','note','complete'],true)||!is_array($payload))pdg_answer(422,['error'=>'Невалидна операция.']);
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
