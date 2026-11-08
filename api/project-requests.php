<?php
declare(strict_types=1);
require __DIR__.'/admin-common.php';require __DIR__.'/my-projects-common.php';
try{
 if(!in_array($_SERVER['REQUEST_METHOD'],['GET','POST'],true))pdg_answer(405,['error'=>'method_not_allowed']);[$db,$actor]=pdg_admin_session();pdg_my_projects_schema($db);
 if($_SERVER['REQUEST_METHOD']==='POST'){
  $v=json_decode(file_get_contents('php://input',false,null,0,2049),true);$id=filter_var($v['id']??0,FILTER_VALIDATE_INT);$status=$v['status']??'';if(!$id||!in_array($status,['review','offer_sent','completed','admin_edited'],true))pdg_answer(422,['error'=>'invalid_request']);
  $db->beginTransaction();$q=$db->prepare('SELECT * FROM pdg_cad_project_requests WHERE id=? FOR UPDATE');$q->execute([$id]);$r=$q->fetch();if(!$r){$db->rollBack();pdg_answer(404,['error'=>'not_found']);}
  $q=$db->prepare('UPDATE pdg_cad_project_requests SET status=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');$q->execute([$status,$id]);
  // Do not mark later customer edits as approved/completed for an older submission.
  $q=$db->prepare('UPDATE pdg_cad_project_meta SET status=? WHERE project_id=? AND revision=?');$q->execute([$status,$r['project_id'],(int)$r['revision']+1]);$db->commit();pdg_answer(200,['ok'=>true]);
 }
 if(isset($_GET['id'])){$q=$db->prepare('SELECT v.snapshot,v.project_name,p.editor FROM pdg_cad_project_requests r JOIN pdg_cad_projects p ON p.id=r.project_id JOIN pdg_cad_project_versions v ON v.project_id=r.project_id AND v.revision=r.revision WHERE r.id=?');$q->execute([(int)$_GET['id']]);$r=$q->fetch();if(!$r)pdg_answer(404,['error'=>'not_found']);pdg_answer(200,['name'=>$r['project_name'],'editor'=>$r['editor'],'project'=>json_decode($r['snapshot'],true)]);}
 $q=$db->query('SELECT r.id,r.project_id,r.revision,r.status,r.created_at,r.request_kind,r.problem,p.project_name,p.editor,u.email FROM pdg_cad_project_requests r JOIN pdg_cad_projects p ON p.id=r.project_id JOIN pdg_users u ON u.id=p.user_id ORDER BY r.created_at DESC,r.id DESC LIMIT 200');pdg_answer(200,['requests'=>$q->fetchAll()]);
}catch(Throwable $e){if(isset($db)&&$db->inTransaction())$db->rollBack();pdg_answer(503,['error'=>'service_unavailable']);}
