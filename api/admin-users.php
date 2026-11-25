<?php
declare(strict_types=1);
require_once __DIR__.'/admin-common.php';
require_once __DIR__.'/customer-pricing.php';
require_once __DIR__.'/demo-account-bootstrap.php';
try {
  [$db,$actor]=pdg_admin_session();
  pdg_discount_schema($db);
  pdg_retire_demo_accounts($db);
  // The initial administrator is created by the site's one-time, protected setup.
  $owner=(int)$db->query("SELECT MIN(id) FROM pdg_users WHERE role='admin'")->fetchColumn();
  $canManage=$actor===$owner;
  if($_SERVER['REQUEST_METHOD']==='GET'){
    try{require_once __DIR__.'/registration-notifications.php';pdg_registration_notice_schema($db);pdg_registration_notices($db);}catch(Throwable $noticeError){error_log('PDG registration notice retry failed');}
    $q=$db->query("SELECT u.id,u.email,u.role,u.is_active,u.created_at,COALESCE(d.discount_bp,0) AS discount_bp,EXISTS(SELECT 1 FROM pdg_email_tokens t WHERE t.user_id=u.id) AS verification_pending FROM pdg_users u LEFT JOIN pdg_customer_discounts d ON d.user_id=u.id WHERE LOWER(u.email) NOT REGEXP '^demo-cad-[a-f0-9]{10}@pdgsystems[.]bg$' ORDER BY u.created_at DESC,u.id DESC LIMIT 500");
    pdg_answer(200,['users'=>$q->fetchAll(),'can_manage_accounts'=>$canManage,'owner_id'=>$owner]);
  }
  if($_SERVER['REQUEST_METHOD']!=='POST')pdg_answer(405,['error'=>'method_not_allowed']);
  if((int)($_SERVER['CONTENT_LENGTH']??0)>2048)pdg_answer(413,['error'=>'invalid_request']);
  $v=json_decode(file_get_contents('php://input'),true);
  $action=is_array($v)?($v['action']??null):null;
  if(in_array($action,['create','set_role'],true)){
    if(!$canManage)pdg_answer(403,['error'=>'forbidden']);
    $role=$v['role']??null;
    if(!in_array($role,['admin','customer'],true))pdg_answer(422,['error'=>'invalid_details']);
    if($action==='create'){
      $email=is_string($v['email']??null)?strtolower(trim($v['email'])):'';
      $password=$v['password']??null;
      if(strlen($email)>254||!filter_var($email,FILTER_VALIDATE_EMAIL)||pdg_is_demo_account($email)||
         !is_string($password)||strlen($password)<16||strlen($password)>72||str_contains($password,chr(0))){
        pdg_answer(422,['error'=>'invalid_details']);
      }
      try{
        $q=$db->prepare('INSERT INTO pdg_users(email,password_hash,role,is_active) VALUES(?,?,?,1)');
        $q->execute([$email,password_hash($password,PASSWORD_DEFAULT),$role]);
      }catch(PDOException $e){
        if((int)($e->errorInfo[1]??0)===1062)pdg_answer(409,['error'=>'email_exists']);
        throw $e;
      }
      pdg_answer(201,['ok'=>true]);
    }
    $target=filter_var($v['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if(!$target||$target===$owner)pdg_answer(403,['error'=>'forbidden']);
    $db->beginTransaction();
    $q=$db->prepare('SELECT email,is_active FROM pdg_users WHERE id=? FOR UPDATE');
    $q->execute([$target]);$account=$q->fetch();
    if(!$account||pdg_is_demo_account($account['email']))pdg_answer(404,['error'=>'not_found']);
    if($role==='admin'){
      $q=$db->prepare('SELECT 1 FROM pdg_email_tokens WHERE user_id=? LIMIT 1');
      $q->execute([$target]);
      if((int)$account['is_active']!==1||$q->fetchColumn())pdg_answer(409,['error'=>'inactive_or_pending']);
    }
    $q=$db->prepare('UPDATE pdg_users SET role=? WHERE id=?');
    $q->execute([$role,$target]);
    $db->commit();
    pdg_answer(200,['ok'=>true]);
  }
  if(!is_array($v)||!isset($v['id'])||!filter_var($v['id'],FILTER_VALIDATE_INT))pdg_answer(422,['error'=>'invalid_request']);
  $discountAction=array_key_exists('discount_bp',$v);
  if($discountAction ? (!is_int($v['discount_bp'])||$v['discount_bp']<0||$v['discount_bp']>10000) : !is_bool($v['active']??null))
    pdg_answer(422,['error'=>'invalid_request']);
  $target=(int)$v['id'];if($target<1||$target===$actor)pdg_answer(403,['error'=>'forbidden']);
  $db->beginTransaction();
  $q=$db->prepare('SELECT email,role,is_active FROM pdg_users WHERE id=? FOR UPDATE');$q->execute([$target]);$user=$q->fetch();
  if(!$user){$db->rollBack();pdg_answer(404,['error'=>'not_found']);}
  if($user['role']!=='customer'||pdg_is_demo_account($user['email'])){$db->rollBack();pdg_answer(403,['error'=>'forbidden']);}
  if($discountAction){
    $q=$db->prepare('INSERT INTO pdg_customer_discounts(user_id,discount_bp) VALUES(?,?) ON DUPLICATE KEY UPDATE discount_bp=VALUES(discount_bp)');
    $q->execute([$target,$v['discount_bp']]);$db->commit();pdg_answer(200,['ok'=>true]);
  }
  if($v['active']){
    $q=$db->prepare('SELECT COUNT(*) FROM pdg_email_tokens WHERE user_id=?');$q->execute([$target]);
    if((int)$q->fetchColumn()>0){$db->rollBack();pdg_answer(409,['error'=>'verification_pending']);}
  }
  $q=$db->prepare("UPDATE pdg_users SET is_active=? WHERE id=? AND role='customer'");$q->execute([(int)$v['active'],$target]);
  $db->commit();pdg_answer(200,['ok'=>true]);
} catch(Throwable $e) {if(isset($db)&&$db->inTransaction())$db->rollBack();pdg_answer(503,['error'=>'service_unavailable']);}
