<?php
declare(strict_types=1);
require __DIR__.'/shop-common.php';
try {
 $db=shop_db();$account=pdg_pricing_account($db,true);
 if($account===null)shop_answer(401,['error'=>'authentication_required']);
 $db->exec('CREATE TABLE IF NOT EXISTS pdg_profile_branding(user_id BIGINT UNSIGNED PRIMARY KEY,logo MEDIUMTEXT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
 if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!hash_equals($_SESSION['csrf']??'',(string)($_SERVER['HTTP_X_CSRF_TOKEN']??'')))shop_answer(403,['error'=>'invalid_request']);
  if((int)($_SERVER['CONTENT_LENGTH']??0)>710000)shop_answer(413,['error'=>'request_too_large']);
  $raw=file_get_contents('php://input',false,null,0,710001);if(strlen($raw)>710000)shop_answer(413,['error'=>'request_too_large']);
  $v=json_decode($raw,true);$logo=is_array($v)?($v['logo']??null):null;
  if(!is_string($logo)||strlen($logo)>700000)shop_answer(422,['error'=>'invalid_logo']);
  if($logo!==''){
   if(!preg_match('~^data:image/(png|jpeg);base64,([A-Za-z0-9+/=]+)$~D',$logo,$m))shop_answer(422,['error'=>'invalid_logo']);
   $bytes=base64_decode($m[2],true);$size=$bytes===false?false:@getimagesizefromstring($bytes);
   if(!$size||!in_array($size[2],[IMAGETYPE_PNG,IMAGETYPE_JPEG],true)||$size[0]>1600||$size[1]>1600||strlen($bytes)>500000)shop_answer(422,['error'=>'invalid_logo']);
   $mime=$size[2]===IMAGETYPE_PNG?'png':'jpeg';$logo='data:image/'.$mime.';base64,'.base64_encode($bytes);
  }
  $q=$db->prepare('INSERT INTO pdg_profile_branding(user_id,logo) VALUES(?,?) ON DUPLICATE KEY UPDATE logo=VALUES(logo)');$q->execute([$account['id'],$logo]);
 }elseif($_SERVER['REQUEST_METHOD']!=='GET')shop_answer(405,['error'=>'method_not_allowed']);
 $q=$db->prepare('SELECT logo FROM pdg_profile_branding WHERE user_id=?');$q->execute([$account['id']]);
 shop_answer(200,['logo'=>$q->fetchColumn()?:'','csrf'=>$_SESSION['csrf']]);
}catch(Throwable $e){shop_answer(503,['error'=>'branding_unavailable']);}
