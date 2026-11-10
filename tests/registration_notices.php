<?php
declare(strict_types=1);
require __DIR__.'/../api/registration-notifications.php';
require __DIR__.'/../api/customer-pricing.php';
$db=new PDO('mysql:host=127.0.0.1;dbname=pdg_test;charset=utf8mb4','root','test-root-password',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
pdg_registration_notice_schema($db);
$db->exec("INSERT INTO pdg_registration_notices(user_id) SELECT id FROM pdg_users WHERE email='customer@example.test'");
$count=0;
$sender=function($to,$subject,$body,$headers)use(&$count){$count++;if($to!=='pdg2019@abv.bg'||!str_contains($body,'customer@example.test')||str_contains($body,'password')||str_contains($body,'#token='))throw new RuntimeException('Unsafe notification');return true;};
pdg_registration_notices($db,$sender);pdg_registration_notices($db,$sender);
if($count!==1)throw new RuntimeException('Duplicate notice');
$db->exec('UPDATE pdg_registration_notices SET sent_at=NULL,attempts=0');
pdg_registration_notices($db,fn()=>false);
if((int)$db->query('SELECT COUNT(*) FROM pdg_registration_notices WHERE sent_at IS NULL')->fetchColumn()!==1)throw new RuntimeException('Failure lost');
pdg_registration_notices($db,$sender);
if($count!==2)throw new RuntimeException('Retry failed');
if(pdg_discounted_cents(10000,1250)!==8750||pdg_discounted_cents(10000,0)!==10000||pdg_discounted_cents(10000,10000)!==0)throw new RuntimeException('Discount incorrect');
echo "PASS: recipient, no secrets, one notice, retry and personal discount arithmetic\n";
