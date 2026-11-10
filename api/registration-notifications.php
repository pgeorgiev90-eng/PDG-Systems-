<?php
declare(strict_types=1);
function pdg_registration_notice_schema(PDO $db):void {
 $db->exec('CREATE TABLE IF NOT EXISTS pdg_registration_notices (user_id BIGINT UNSIGNED PRIMARY KEY, attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0, sent_at DATETIME NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
}
function pdg_registration_notices(PDO $db,?callable $sender=null):void {
 if((int)$db->query("SELECT GET_LOCK('pdg_registration_notices',0)")->fetchColumn()!==1)return;
 try{
  $rows=$db->query('SELECT n.user_id,u.email,u.is_active,u.created_at FROM pdg_registration_notices n JOIN pdg_users u ON u.id=n.user_id WHERE n.sent_at IS NULL AND n.attempts<5 ORDER BY n.created_at LIMIT 5')->fetchAll(PDO::FETCH_ASSOC);
  foreach($rows as $u){
   $body="Нов потребител в PDG Systems\n\nИмейл: ".$u['email']."\nРегистрация: ".$u['created_at']."\nСтатус: ".((int)$u['is_active']?'Потвърден':'Чака потвърждение на имейла')."\n\nПерсонална отстъпка: https://pdgsystems.bg/admin-dashboard.html#admin-users\n";
   $headers="From: no-reply@pdgsystems.bg\r\nContent-Type: text/plain; charset=UTF-8";
   $db->prepare('UPDATE pdg_registration_notices SET attempts=attempts+1 WHERE user_id=?')->execute([$u['user_id']]);
   $ok=$sender?$sender('pdg2019@abv.bg','PDG Systems - Nov potrebitel',$body,$headers):mail('pdg2019@abv.bg','PDG Systems - Nov potrebitel',$body,$headers);
   if($ok)$db->prepare('UPDATE pdg_registration_notices SET sent_at=NOW() WHERE user_id=?')->execute([$u['user_id']]);
   else error_log('PDG administrator registration notice was not accepted by mail transport');
  }
 }finally{$db->query("SELECT RELEASE_LOCK('pdg_registration_notices')");}
}
