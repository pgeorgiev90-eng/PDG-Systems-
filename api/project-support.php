<?php
declare(strict_types=1);
require __DIR__.'/admin-common.php';require __DIR__.'/my-projects-common.php';
try{
 $method=$_SERVER['REQUEST_METHOD'];if(!in_array($method,['GET','POST'],true))pdg_answer(405,['error'=>'method_not_allowed']);
 [$db,$actor]=pdg_admin_session();pdg_my_projects_schema($db);
 $v=[];if($method==='POST'){$raw=file_get_contents('php://input',false,null,0,1048577);if(strlen($raw)>1048576)pdg_answer(413,['error'=>'project_too_large']);$v=json_decode($raw,true,128);if(!is_array($v))pdg_answer(422,['error'=>'invalid_request']);}
 $id=filter_var($method==='GET'?($_GET['id']??null):($v['support']??null),FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if(!$id)pdg_answer(422,['error'=>'invalid_request']);
 // Read the request first; lock the project in the same order as customer writes.
 $q=$db->prepare("SELECT r.*,u.email,p.user_id FROM pdg_cad_project_requests r JOIN pdg_cad_projects p ON p.id=r.project_id JOIN pdg_users u ON u.id=p.user_id WHERE r.id=?");$q->execute([$id]);$request=$q->fetch();if(!$request)pdg_answer(404,['error'=>'not_found']);
 if($method==='POST')$db->beginTransaction();
 $row=pdg_my_project_owned($db,(int)$request['user_id'],(int)$request['project_id'],$method==='POST');if(!$row||$row['archived']){if($db->inTransaction())$db->rollBack();pdg_answer(404,['error'=>'not_found']);}
 if($method==='POST'){
  if((int)($v['revision']??0)!==(int)$row['revision']){$db->rollBack();pdg_answer(409,['error'=>'revision_conflict','revision'=>(int)$row['revision']]);}
  $project=$v['project']??null;if(($v['editor']??'')!==$row['editor']||!is_array($project)||!pdg_my_project_valid($row['editor'],$project)){$db->rollBack();pdg_answer(422,['error'=>'invalid_project']);}
  $project[$row['editor']==='solar'?'projectName':'project']=$row['project_name'];
  pdg_my_project_history($db,$row);$revision=(int)$row['revision']+1;
  $q=$db->prepare('UPDATE pdg_cad_projects SET snapshot=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');$q->execute([json_encode($project,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$row['id']]);
  $q=$db->prepare("UPDATE pdg_cad_project_meta SET revision=?,status='admin_edited',last_admin_revision=?,last_admin_at=UTC_TIMESTAMP(),last_admin_id=? WHERE project_id=?");$q->execute([$revision,$revision,$actor,$row['id']]);
  $q=$db->prepare("UPDATE pdg_cad_project_requests SET status='admin_edited',updated_at=CURRENT_TIMESTAMP WHERE id=?");$q->execute([$id]);
  $row=pdg_my_project_owned($db,(int)$request['user_id'],(int)$request['project_id']);pdg_my_project_history($db,$row);$db->commit();
 }
 pdg_answer(200,['ok'=>true,'project'=>json_decode($row['snapshot'],true,128,JSON_THROW_ON_ERROR),'meta'=>pdg_my_project_summary($row),'support'=>['id'=>(int)$request['id'],'problem'=>$request['problem']??'','email'=>$request['email']]]);
}catch(Throwable $e){if(isset($db)&&$db->inTransaction())$db->rollBack();error_log('PDG project support: '.get_class($e));pdg_answer(503,['error'=>'service_unavailable']);}
