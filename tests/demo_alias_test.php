<?php
declare(strict_types=1);
$db=new PDO('mysql:host=127.0.0.1;dbname=pdg_test;charset=utf8mb4','root','test-root-password',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->prepare("INSERT INTO pdg_users(email,password_hash,role) VALUES(?,?,'customer')")->execute(['demo-cad-a7eb27c84d@pdgsystems.bg',password_hash('Old-demo-password-123',PASSWORD_DEFAULT)]);
require __DIR__.'/../api/demo-account-bootstrap.php';pdg_prepare_demo_login($db);pdg_prepare_demo_login($db);
$q=$db->query("SELECT password_hash,role FROM pdg_users WHERE email='demo-cad-a7eb27c84d@pdgsystems.bg'");$u=$q->fetch(PDO::FETCH_ASSOC);
if(!password_verify('демо',$u['password_hash'])||$u['role']!=='customer')throw new RuntimeException('Demo correction failed');
if((int)$db->query("SELECT COUNT(*) FROM pdg_users WHERE role='admin'")->fetchColumn()!==1)throw new RuntimeException('Administrator changed');
echo "PASS: demo password corrected once, normal customer role and administrator retained\n";
