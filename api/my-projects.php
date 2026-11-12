<?php
declare(strict_types=1);
require __DIR__.'/admin-common.php';require __DIR__.'/my-projects-common.php';
try{
 $method=$_SERVER['REQUEST_METHOD'];if(!in_array($method,['GET','POST'],true))pdg_answer(405,['error'=>'method_not_allowed']);
 [$db,$user]=pdg_account_session(false);pdg_my_projects_schema($db);
 if($method==='GET'){
  if(isset($_GET['id'])){
   $id=filter_var($_GET['id'],FILTER_VALIDATE_INT);$r=$id?pdg_my_project_owned($db,$user,(int)$id):null;if(!$r)pdg_answer(404,['error'=>'not_found']);
   if(($_GET['view']??'')==='history'){$q=$db->prepare('SELECT revision,project_name,created_at FROM pdg_cad_project_versions WHERE project_id=? ORDER BY revision DESC LIMIT 100');$q->execute([$id]);pdg_answer(200,['versions'=>$q->fetchAll(),'revision'=>(int)$r['revision']]);}
   pdg_answer(200,['project'=>json_decode($r['snapshot'],true,128,JSON_THROW_ON_ERROR),'meta'=>pdg_my_project_summary($r)]);
  }
  if(($_GET['view']??'')==='overview'){
   $q=$db->prepare("SELECT COUNT(*) AS total,SUM(CASE WHEN COALESCE(m.status,'draft') IN ('shared','review') THEN 1 ELSE 0 END) AS assistance FROM pdg_cad_projects p LEFT JOIN pdg_cad_project_meta m ON m.project_id=p.id WHERE p.user_id=? AND COALESCE(m.customer_deleted,0)=0 AND COALESCE(m.archived,0)=0");$q->execute([$user]);$counts=$q->fetch();
   $q=$db->prepare("SELECT p.*,m.last_admin_revision,m.last_admin_at,COALESCE(m.revision,1) AS revision,COALESCE(m.status,'draft') AS status,0 AS archived FROM pdg_cad_projects p LEFT JOIN pdg_cad_project_meta m ON m.project_id=p.id WHERE p.user_id=? AND COALESCE(m.customer_deleted,0)=0 AND COALESCE(m.archived,0)=0 ORDER BY p.updated_at DESC,p.id DESC LIMIT 3");$q->execute([$user]);$recent=array_map('pdg_my_project_summary',$q->fetchAll());
   pdg_answer(200,['total'=>(int)$counts['total'],'assistance'=>(int)$counts['assistance'],'recent'=>$recent]);
  }
  $search=is_string($_GET['q']??null)?mb_substr(trim($_GET['q']),0,120):'';$editor=$_GET['editor']??'';$archived=($_GET['archived']??'0')==='1'?1:0;
  if(!in_array($editor,['','solar','electric'],true))pdg_answer(422,['error'=>'invalid_filter']);
  $page=filter_var($_GET['page']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>10000]]);if(!$page)pdg_answer(422,['error'=>'invalid_filter']);$offset=($page-1)*30;
  $q=$db->prepare("SELECT p.*,m.last_admin_revision,m.last_admin_at,COALESCE(m.revision,1) AS revision,COALESCE(m.status,'draft') AS status,COALESCE(m.archived,0) AS archived FROM pdg_cad_projects p LEFT JOIN pdg_cad_project_meta m ON m.project_id=p.id WHERE p.user_id=? AND COALESCE(m.customer_deleted,0)=0 AND COALESCE(m.archived,0)=? AND (?='' OR p.editor=?) AND p.project_name LIKE ? AND (?=0 OR COALESCE(m.status,'draft') IN ('shared','review','admin_edited') OR m.last_admin_at IS NOT NULL) ORDER BY p.updated_at DESC,p.id DESC LIMIT 31 OFFSET $offset");$q->execute([$user,$archived,$editor,$editor,'%'.$search.'%',(($_GET['shared']??'0')==='1'?1:0)]);$rows=$q->fetchAll();pdg_answer(200,['projects'=>array_map('pdg_my_project_summary',array_slice($rows,0,30)),'more'=>count($rows)>30]);
 }
 $raw=file_get_contents('php://input',false,null,0,1048577);if(strlen($raw)>1048576)pdg_answer(413,['error'=>'project_too_large']);$v=json_decode($raw,true,128);if(!is_array($v))pdg_answer(422,['error'=>'invalid_project']);
 if(($v['action']??'')==='delete_selected'){
  $selected=$v['projects']??null;
  if(!is_array($selected)||!count($selected)||count($selected)>100)pdg_answer(422,['error'=>'invalid_project']);
  $ids=[];foreach($selected as $entry){$id=filter_var($entry['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);$rev=filter_var($entry['revision']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if(!$id||!$rev||isset($ids[$id]))pdg_answer(422,['error'=>'invalid_project']);$ids[$id]=$rev;}ksort($ids);
  $db->beginTransaction();$owned=[];
  foreach($ids as $id=>$rev){$row=pdg_my_project_owned($db,$user,(int)$id,true);if(!$row){$db->rollBack();pdg_answer(404,['error'=>'not_found']);}if((int)$row['revision']!==$rev){$db->rollBack();pdg_answer(409,['error'=>'revision_conflict']);}$owned[]=$row;}
  foreach($owned as $row){pdg_my_project_history($db,$row);$q=$db->prepare("INSERT INTO pdg_cad_project_meta(project_id,revision,status,archived,customer_deleted) VALUES(?,?,?,1,1) ON DUPLICATE KEY UPDATE revision=VALUES(revision),archived=1,customer_deleted=1");$q->execute([$row['id'],(int)$row['revision']+1,$row['status']]);}
  $db->commit();pdg_answer(200,['ok'=>true,'deleted'=>count($owned)]);
 }
 $action=$v['action']??'save';if(!in_array($action,['save','duplicate','archive','restore','request','share'],true))pdg_answer(422,['error'=>'invalid_action']);
 if(isset($v['id'])&&!filter_var($v['id'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]))pdg_answer(422,['error'=>'invalid_project']);
 if($action==='share'&&(!is_string($v['problem']??'')||mb_strlen($v['problem']??'')>4000))pdg_answer(422,['error'=>'invalid_request']);
 if($action==='archive'&&!is_bool($v['archived']??null))pdg_answer(422,['error'=>'invalid_request']);
 $id=filter_var($v['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);$db->beginTransaction();$row=$id?pdg_my_project_owned($db,$user,(int)$id,true):null;
 if($id&&!$row){$db->rollBack();pdg_answer(404,['error'=>'not_found']);}
 if($row&&(int)($v['revision']??0)!==(int)$row['revision']){$db->rollBack();pdg_answer(409,['error'=>'revision_conflict','revision'=>(int)$row['revision']]);}
 if($action!=='save'&&!$row){$db->rollBack();pdg_answer(422,['error'=>'invalid_project']);}
 if($action==='save'||$action==='duplicate'){
  $editor=$action==='duplicate'?$row['editor']:($v['editor']??'');$name=$action==='duplicate'?mb_substr($row['project_name'],0,88).' · копие '.bin2hex(random_bytes(3)):($v['name']??'');$project=$action==='duplicate'?json_decode($row['snapshot'],true):($v['project']??null);
  if(!is_string($name)||trim($name)===''||mb_strlen($name)>120||!is_string($editor)||!is_array($project)||!pdg_my_project_valid($editor,$project)||($row&&$row['editor']!==$editor)){$db->rollBack();pdg_answer(422,['error'=>'invalid_project']);}$name=trim($name);
  if($action==='duplicate'){if($editor==='solar')$project['projectName']=$name;else $project['project']=$name;}
  $snapshot=json_encode($project,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
  if(!$row||$action==='duplicate'){
   $q=$db->prepare('INSERT INTO pdg_cad_projects(user_id,editor,project_name,snapshot) VALUES(?,?,?,?)');$q->execute([$user,$editor,$name,$snapshot]);$id=(int)$db->lastInsertId();$revision=1;
  }else{
   if((int)$row['archived']){$db->rollBack();pdg_answer(409,['error'=>'project_archived']);}
   pdg_my_project_history($db,$row);$revision=(int)$row['revision']+1;$q=$db->prepare('UPDATE pdg_cad_projects SET project_name=?,snapshot=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND user_id=?');$q->execute([$name,$snapshot,$id,$user]);
  }
  $q=$db->prepare("INSERT INTO pdg_cad_project_meta(project_id,revision,status,archived) VALUES(?,?,'draft',0) ON DUPLICATE KEY UPDATE revision=VALUES(revision),status='draft'");$q->execute([$id,$revision]);
 }else{
  pdg_my_project_history($db,$row);$revision=(int)$row['revision']+1;$status=$row['status'];$archived=(int)$row['archived'];
  if($action==='archive'){$archived=($v['archived']??null)===true?1:0;}
  if($action==='restore'){
   $q=$db->prepare('SELECT snapshot FROM pdg_cad_project_versions WHERE project_id=? AND revision=?');$q->execute([$id,(int)($v['version']??0)]);$snapshot=$q->fetchColumn();if($snapshot===false){$db->rollBack();pdg_answer(404,['error'=>'version_not_found']);}
   $project=json_decode($snapshot,true);if($row['editor']==='solar')$project['projectName']=$row['project_name'];else $project['project']=$row['project_name'];$snapshot=json_encode($project,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
   $q=$db->prepare('UPDATE pdg_cad_projects SET snapshot=? WHERE id=? AND user_id=?');$q->execute([$snapshot,$id,$user]);$status='draft';$archived=0;
  }
  if($action==='request'||$action==='share'){
   if($archived){$db->rollBack();pdg_answer(409,['error'=>'project_archived']);}$status=$action==='share'?'shared':'review';
   $q=$db->prepare("INSERT INTO pdg_cad_project_requests(project_id,revision,request_kind,problem) VALUES(?,?,?,?)");$q->execute([$id,$row['revision'],$action==='share'?'assistance':'review',$action==='share'?trim($v['problem']??''):'']);
  }
  $q=$db->prepare('INSERT INTO pdg_cad_project_meta(project_id,revision,status,archived) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE revision=VALUES(revision),status=VALUES(status),archived=VALUES(archived)');$q->execute([$id,$revision,$status,$archived]);
  $q=$db->prepare('UPDATE pdg_cad_projects SET updated_at=CURRENT_TIMESTAMP WHERE id=? AND user_id=?');$q->execute([$id,$user]);
 }
 $saved=pdg_my_project_owned($db,$user,(int)$id);pdg_my_project_history($db,$saved);$db->commit();pdg_answer(200,['ok'=>true,'meta'=>pdg_my_project_summary($saved)]);
}catch(Throwable $e){if(isset($db)&&$db->inTransaction())$db->rollBack();if($e instanceof PDOException&&$e->getCode()==='23000')pdg_answer(409,['error'=>'name_or_request_exists']);error_log('PDG user projects: '.get_class($e));pdg_answer(503,['error'=>'service_unavailable']);}
