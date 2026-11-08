<?php
declare(strict_types=1);
function pdg_my_projects_schema(PDO $db):void {
 $db->exec("CREATE TABLE IF NOT EXISTS pdg_cad_projects(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,editor VARCHAR(12) NOT NULL,project_name VARCHAR(120) NOT NULL,snapshot MEDIUMTEXT NOT NULL,updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY owner_editor_name(user_id,editor,project_name),KEY recent(updated_at,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
 $db->exec("CREATE TABLE IF NOT EXISTS pdg_cad_project_meta(project_id BIGINT UNSIGNED PRIMARY KEY,revision INT UNSIGNED NOT NULL DEFAULT 1,status VARCHAR(20) NOT NULL DEFAULT 'draft',archived TINYINT NOT NULL DEFAULT 0,CONSTRAINT fk_pdg_project_meta FOREIGN KEY(project_id) REFERENCES pdg_cad_projects(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
 $column=$db->query("SHOW COLUMNS FROM pdg_cad_project_meta LIKE 'customer_deleted'")->fetch();
 if(!$column){try{$db->exec("ALTER TABLE pdg_cad_project_meta ADD COLUMN customer_deleted TINYINT NOT NULL DEFAULT 0");}catch(PDOException $e){if(($e->errorInfo[1]??0)!==1060)throw $e;}}
 $db->exec("CREATE TABLE IF NOT EXISTS pdg_cad_project_versions(project_id BIGINT UNSIGNED NOT NULL,revision INT UNSIGNED NOT NULL,project_name VARCHAR(120) NOT NULL,snapshot MEDIUMTEXT NOT NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(project_id,revision),CONSTRAINT fk_pdg_project_versions FOREIGN KEY(project_id) REFERENCES pdg_cad_projects(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
 $db->exec("CREATE TABLE IF NOT EXISTS pdg_cad_project_requests(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,project_id BIGINT UNSIGNED NOT NULL,revision INT UNSIGNED NOT NULL,status VARCHAR(20) NOT NULL DEFAULT 'review',created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY project_revision(project_id,revision),CONSTRAINT fk_pdg_project_requests FOREIGN KEY(project_id) REFERENCES pdg_cad_projects(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function pdg_my_project_owned(PDO $db,int $user,int $id,bool $lock=false):?array {
 $q=$db->prepare('SELECT p.*,COALESCE(m.revision,1) AS revision,COALESCE(m.status,\'draft\') AS status,COALESCE(m.archived,0) AS archived FROM pdg_cad_projects p LEFT JOIN pdg_cad_project_meta m ON m.project_id=p.id WHERE p.id=? AND p.user_id=? AND COALESCE(m.customer_deleted,0)=0'.($lock?' FOR UPDATE':''));$q->execute([$id,$user]);return $q->fetch()?:null;
}
function pdg_my_project_valid(string $editor,array $p):bool {
 if($editor==='electric')return ($p['version']??null)===2&&is_array($p['items']??null)&&count($p['items'])<=10000&&is_array($p['busbars']??null)&&count($p['busbars'])<=10000;
 if($editor!=='solar'||($p['version']??null)!==1||!is_array($p['planes']??null)||count($p['planes'])>100)return false;
 $total=0;foreach($p['planes'] as $r){if(!is_array($r)||!is_array($r['path']??null)||count($r['path'])<3||count($r['path'])>200||!is_array($r['panels']??null)||count($r['panels'])>1500)return false;$total+=count($r['panels']);foreach($r['path'] as $v)if(!is_array($v)||count($v)!==2||!is_numeric($v[0])||!is_numeric($v[1])||abs((float)$v[0])>90||abs((float)$v[1])>180)return false;}return $total<=20000;
}
function pdg_my_project_history(PDO $db,array $row):void {
 $q=$db->prepare('INSERT IGNORE INTO pdg_cad_project_versions(project_id,revision,project_name,snapshot) VALUES(?,?,?,?)');$q->execute([$row['id'],$row['revision'],$row['project_name'],$row['snapshot']]);
}
function pdg_my_project_summary(array $row):array {
 $p=json_decode($row['snapshot'],true);$count=0;$watts=0;$preview=[];
 if($row['editor']==='solar'){foreach($p['planes']??[] as $roof){$path=$roof['path']??[];$panels=$roof['panels']??[];$count+=count($panels);foreach($panels as $v)$watts+=(float)($v['power']??0);$preview[]=['path'=>$path,'panels'=>array_slice(array_map(fn($v)=>['lat'=>$v['lat']??0,'lng'=>$v['lng']??0],$panels),0,200)];}}
 else $count=count($p['items']??[]);
 return ['id'=>(int)$row['id'],'editor'=>$row['editor'],'name'=>$row['project_name'],'updatedAt'=>$row['updated_at'],'revision'=>(int)$row['revision'],'status'=>$row['status'],'archived'=>(bool)$row['archived'],'count'=>$count,'kwp'=>$watts/1000,'preview'=>$preview];
}
