<?php
declare(strict_types=1);
require __DIR__.'/../api/solax-catalog-20261112.php';
$db=new PDO('mysql:host=127.0.0.1;dbname=pdg_test;charset=utf8mb4','root','test-root-password',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec(file_get_contents(__DIR__.'/../database/schema.sql'));
$db->exec('CREATE TABLE pdg_product_fields(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,product_id BIGINT UNSIGNED NOT NULL,label VARCHAR(100) NOT NULL,value TEXT NOT NULL,sort_order INT NOT NULL DEFAULT 0,FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$items=json_decode(file_get_contents(__DIR__.'/../database/solax-catalog-20261112.json'),true,512,JSON_THROW_ON_ERROR);
function check(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
$seed=$db->prepare('INSERT INTO products(id,brand,code,category,name,price) VALUES(?,?,?,?,?,?)');
foreach($items as $r)if($r['expectedId']!==null)$seed->execute([$r['expectedId'],$r['brand'],$r['code'],$r['category'],'Original '.$r['code'],'99.99']);
$db->exec("INSERT INTO products(id,brand,code,category,name,price) VALUES(99999,'Deye','UNCHANGED','Инвертори','Other brand',100)");
$db->exec("INSERT INTO pdg_product_fields(product_id,label,value) VALUES(134,'Администраторска бележка','Keep me')");
$before=$db->query('SELECT * FROM products ORDER BY id')->fetchAll();
// Fail late, after earlier updates/inserts, and require transaction rollback.
$db->exec("UPDATE products SET code='IDENTITY-CHANGED' WHERE id=204");
$failed=false;try{pdg_solax_catalog_20261112($db);}catch(RuntimeException $e){$failed=true;}
check($failed,'Changed identity must reject import');
check((int)$db->query('SELECT COUNT(*) FROM products')->fetchColumn()===72,'Failed import left new products');
check($db->query('SELECT price FROM products WHERE id=134')->fetchColumn()==='99.99','Failed import changed prices');
check((int)$db->query('SELECT COUNT(*) FROM pdg_solax_import_runs')->fetchColumn()===0,'Failed import marked complete');
$db->exec("UPDATE products SET code='X3-PCAB-750kW-A' WHERE id=204");
$r=pdg_solax_catalog_20261112($db);check($r['updated']===71&&$r['added']===84&&$r['priced']===154&&$r['inquiry']===1,'Wrong import counts');
check((int)$db->query('SELECT COUNT(*) FROM products')->fetchColumn()===156,'Missing or duplicate products');
check($db->query('SELECT price FROM products WHERE id=99999')->fetchColumn()==='100.00','Unrelated brand changed');
check($db->query("SELECT value FROM pdg_product_fields WHERE product_id=134 AND label='Администраторска бележка'")->fetchColumn()==='Keep me','Custom field lost');
$find=$db->prepare('SELECT id,price,image_url,category FROM products WHERE brand=? AND code=?');
foreach($items as $item){$find->execute([$item['brand'],$item['code']]);$p=$find->fetch();check($p&&$p['price']===$item['price']&&$p['image_url']===$item['image']&&$p['category']===$item['category'],'Readback mismatch '.$item['code']);}
$dump=$db->query('SELECT * FROM products ORDER BY id')->fetchAll();check(pdg_solax_catalog_20261112($db)===$r,'Repeated import changed result');check($db->query('SELECT * FROM products ORDER BY id')->fetchAll()===$dump,'Repeated import compounded prices or inserted duplicates');
check((int)$db->query('SELECT COUNT(*) FROM pdg_solax_import_history')->fetchColumn()===155,'Incomplete recovery history');
$old=json_decode($db->query('SELECT product_json FROM pdg_solax_import_history WHERE product_id=134')->fetchColumn(),true);check($old['price']==='99.99','Original price not retained');
$db->exec('UPDATE products SET price=777 WHERE id=134');pdg_solax_catalog_20261112($db);check($db->query('SELECT price FROM products WHERE id=134')->fetchColumn()==='777.00','Later administrator price overwritten');
echo "PASS: 155 identities, exact 30% markup, inquiry pricing, transactional rollback, backups, idempotence and administrator changes\n";
