<?php
declare(strict_types=1);
$db=new PDO('mysql:host=127.0.0.1;dbname=pdg_kamea;charset=utf8mb4','root','ci-panels',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec(file_get_contents(__DIR__.'/../database/schema.sql'));
$db->exec('CREATE TABLE pdg_catalog_migrations(name VARCHAR(190) PRIMARY KEY)');
$db->exec('CREATE TABLE pdg_product_fields(id BIGINT AUTO_INCREMENT PRIMARY KEY,product_id BIGINT UNSIGNED,label VARCHAR(190),value TEXT,sort_order INT)');
$items=json_decode(file_get_contents(__DIR__.'/../database/kamea-catalog.json'),true,512,JSON_THROW_ON_ERROR);assert(count($items)===150);
$first=$items[0];$q=$db->prepare('INSERT INTO products(brand,category,code,name,price,image_url) VALUES(?,?,?,?,?,?)');$q->execute([$first['brand'],$first['category'],$first['code'],$first['name'],12.34,'admin-photo.jpg']);
$db->exec("INSERT INTO pdg_product_fields(product_id,label,value,sort_order) VALUES(1,'Описание','Редактирано от администратор',1)");
require __DIR__.'/../api/kamea-catalog-bootstrap.php';
pdg_import_kamea_catalog($db);pdg_import_kamea_catalog($db);
function check(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
check((int)$db->query('SELECT COUNT(*) FROM products')->fetchColumn()===150,'No duplicate products');
check((int)$db->query('SELECT COUNT(*) FROM products WHERE price IS NULL')->fetchColumn()===149,'New products have no prices');
check($db->query('SELECT price FROM products WHERE id=1')->fetchColumn()==='12.34','Existing price preserved');
check($db->query('SELECT image_url FROM products WHERE id=1')->fetchColumn()==='admin-photo.jpg','Existing photo preserved');
check($db->query("SELECT value FROM pdg_product_fields WHERE product_id=1 AND label='Описание'")->fetchColumn()==='Редактирано от администратор','Existing description preserved');
$counts=(int)$db->query('SELECT COUNT(*) FROM pdg_product_fields')->fetchColumn();
$db->exec('UPDATE products SET price=55 WHERE id=2');$db->exec('DELETE FROM pdg_catalog_migrations');pdg_import_kamea_catalog($db);
check($db->query('SELECT price FROM products WHERE id=2')->fetchColumn()==='55.00','Manual price survives import retry');
check((int)$db->query('SELECT COUNT(*) FROM pdg_product_fields')->fetchColumn()===$counts,'No duplicate technical fields');
echo "PASS: 150 models, null prices for new products, preserved admin prices/photos/descriptions, repeated import and no duplicated fields\n";
