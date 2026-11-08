<?php
declare(strict_types=1);
// Public demo credentials apply only to the already-created customer demo identity.
function pdg_prepare_demo_login(PDO $db):void {
 $version='demo_alias_20261108_1';
 $db->exec('CREATE TABLE IF NOT EXISTS pdg_demo_account_migrations(name VARCHAR(100) PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
 $db->beginTransaction();
 try{
  $q=$db->prepare('SELECT name FROM pdg_demo_account_migrations WHERE name=? FOR UPDATE');$q->execute([$version]);if($q->fetchColumn()){$db->commit();return;}
  $q=$db->prepare("SELECT id FROM pdg_users WHERE email=? AND role='customer' AND is_active=1 FOR UPDATE");$q->execute(['demo-cad-a7eb27c84d@pdgsystems.bg']);$id=$q->fetchColumn();
  if($id===false){$db->rollBack();return;}
  $q=$db->prepare("UPDATE pdg_users SET password_hash=? WHERE id=? AND role='customer' AND is_active=1");$q->execute([password_hash('демо',PASSWORD_DEFAULT),$id]);
  $db->prepare('INSERT INTO pdg_demo_account_migrations(name) VALUES(?)')->execute([$version]);$db->commit();
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
