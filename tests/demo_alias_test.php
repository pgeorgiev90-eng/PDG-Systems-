<?php
declare(strict_types=1);
$db=new PDO('mysql:host=127.0.0.1;dbname=pdg_test;charset=utf8mb4','root','test-root-password',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->prepare("INSERT INTO pdg_users(email,password_hash,role) VALUES(?,?,'customer')")->execute(['demo-cad-a7eb27c84d@pdgsystems.bg',password_hash('Old-demo-password-123',PASSWORD_DEFAULT)]);
require __DIR__.'/../api/demo-account-bootstrap.php';
pdg_retire_demo_accounts($db);
pdg_retire_demo_accounts($db);
if(!pdg_is_demo_account('demo-cad-a7eb27c84d@pdgsystems.bg')||pdg_is_demo_account('buyer@example.com'))throw new RuntimeException('Invalid demo matcher');
if((int)$db->query("SELECT is_active FROM pdg_users WHERE email='demo-cad-a7eb27c84d@pdgsystems.bg'")->fetchColumn()!==0)throw new RuntimeException('Demo account still active');
if((int)$db->query("SELECT COUNT(*) FROM pdg_users WHERE role='admin' AND is_active=1")->fetchColumn()!==1)throw new RuntimeException('Administrator changed');
echo "PASS: demo login retired, administrator retained\n";
