<?php
declare(strict_types=1);
require __DIR__.'/admin-common.php';require __DIR__.'/my-projects-common.php';
try{
 if($_SERVER['REQUEST_METHOD']!=='POST')pdg_answer(405,['error'=>'method_not_allowed']);[$db,$admin]=pdg_admin_session();pdg_my_projects_schema($db);
 // New customer identity only: this endpoint cannot reset or elevate any account.
 $email='demo-cad-'.bin2hex(random_bytes(5)).'@pdgsystems.bg';$password='Pdg!'.bin2hex(random_bytes(10));
 $db->beginTransaction();$q=$db->prepare("INSERT INTO pdg_users(email,password_hash,role,is_active) VALUES(?,?,'customer',1)");$q->execute([$email,password_hash($password,PASSWORD_DEFAULT)]);$user=(int)$db->lastInsertId();
 $at=fn(float $x,float $y)=>[42.7+$y/111195,23.32+$x/(111195*cos(deg2rad(42.7)))];$planes=[];
 foreach([0,5] as $offset){$panels=[];for($r=0;$r<2;$r++)for($c=0;$c<6;$c++){[$lat,$lng]=$at(1+$c*1.16,$offset+1.2+$r*2.32);$panels[]=['lat'=>$lat,'lng'=>$lng,'w'=>1.134,'h'=>2.278,'power'=>450,'rotation'=>0,'model'=>'custom','voc'=>40,'vmp'=>33,'imp'=>13.64,'isc'=>14.2,'vocCoefficient'=>-0.25];}$planes[]=['path'=>[$at(0,$offset),$at(10,$offset),$at(10,$offset+5),$at(0,$offset+5)],'azimuth'=>$offset?0:180,'tilt'=>25,'panels'=>$panels,'manualArea'=>null,'moduleTilt'=>null];}
 $examples=[['solar','Демо · къща с два ската',['version'=>1,'projectName'=>'Демо · къща с два ската','planes'=>$planes,'center'=>['lat'=>42.7,'lng'=>23.32],'zoom'=>20,'offer'=>null]],['electric','Демо · електрическо табло',['version'=>2,'project'=>'Демо · електрическо табло','rows'=>3,'modules'=>12,'mode'=>'din','cabinet'=>['sku'=>'','widthMm'=>600,'heightMm'=>800,'depthMm'=>260],'items'=>[['type'=>'MAIN','width'=>4,'row'=>0,'col'=>0,'name'=>'Главен прекъсвач','rating'=>'40 A'],['type'=>'MCB','width'=>1,'row'=>1,'col'=>0,'name'=>'Осветление','rating'=>'10 A'],['type'=>'MCB','width'=>1,'row'=>1,'col'=>1,'name'=>'Контакти','rating'=>'16 A']],'busbars'=>[],'connections'=>[]]]];
 $q=$db->prepare('INSERT INTO pdg_cad_projects(user_id,editor,project_name,snapshot) VALUES(?,?,?,?)');
 foreach($examples as [$editor,$name,$project]){$q->execute([$user,$editor,$name,json_encode($project,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);$id=(int)$db->lastInsertId();$db->prepare('INSERT INTO pdg_cad_project_meta(project_id) VALUES(?)')->execute([$id]);pdg_my_project_history($db,pdg_my_project_owned($db,$user,$id));}
 $db->commit();pdg_answer(200,['ok'=>true,'email'=>$email,'password'=>$password,'role'=>'customer']);
}catch(Throwable $e){if(isset($db)&&$db->inTransaction())$db->rollBack();pdg_answer(503,['error'=>'service_unavailable']);}
