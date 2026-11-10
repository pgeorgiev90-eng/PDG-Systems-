<?php
declare(strict_types=1);
require __DIR__.'/../api/brand-price-reduction-20261110.php';
$db=new PDO('mysql:host=127.0.0.1;dbname=pdg_test;charset=utf8mb4','root','test-root-password',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec(file_get_contents(__DIR__.'/../database/schema.sql'));
$db->exec("INSERT INTO products(brand,category,code,name,price,active) VALUES ('SolaX','Test','A','A',100.00,1),('Enerack','Test','B','B',1.05,1),('SolaX','Test','C','C',NULL,1),('Enerack','Test','D','D',0,1),('Deye','Test','E','E',100.00,1),(' solax ','Test','F','F',0.25,0),('ENERACK','Test','G','G',0.50,1)");
function ensure(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$r=pdg_brand_price_reduction_20261110($db);
ensure($r['counts']===['SolaX'=>2,'Enerack'=>2],'Correct brand scope');
$prices=$db->query('SELECT code,price FROM products ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
ensure($prices===['A'=>'86.00','B'=>'0.90','C'=>null,'D'=>'0.00','E'=>'100.00','F'=>'0.22','G'=>'0.43'],'Exact rounding, missing prices and other brands');
pdg_brand_price_reduction_20261110($db);
ensure($prices===$db->query('SELECT code,price FROM products ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR),'Second call must not compound prices');
ensure((int)$db->query('SELECT COUNT(*) FROM pdg_price_revision_rows')->fetchColumn()===4,'Original prices retained');
$db->exec("UPDATE products SET price=150.00 WHERE code='A'");
pdg_brand_price_reduction_20261110($db);
ensure($db->query("SELECT price FROM products WHERE code='A'")->fetchColumn()==='150.00','Later administrator edits preserved');
echo "PASS: exact percentage changes, rounding, scope, original prices, idempotence and later edits\n";
