<?php
declare(strict_types=1);
$db=new PDO('mysql:host=127.0.0.1;dbname=pdg_panels;charset=utf8mb4','root','ci-panels',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
require __DIR__.'/../api/official-inverters-bootstrap.php';
$rows=json_decode(file_get_contents(__DIR__.'/../database/official-inverter-specs.json'),true,512,JSON_THROW_ON_ERROR);
$q=$db->prepare('INSERT INTO products(brand,category,code,name,price,image_url) VALUES(?,?,?,?,?,?)');
foreach($rows as $r)$q->execute([$r['brand'],'Инвертори',$r['code'],'Admin name '.$r['code'],123.45,'admin-image.jpg']);
$before=$db->query('SELECT id,brand,code,name,price,image_url FROM products ORDER BY id')->fetchAll();
pdg_import_official_inverters($db);pdg_import_official_inverters($db);
function verify(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
verify($db->query('SELECT id,brand,code,name,price,image_url FROM products ORDER BY id')->fetchAll()===$before,'Prices/photos/names/identities preserved');
$find=$db->prepare('SELECT id FROM products WHERE brand=? AND code=?');$fields=$db->prepare('SELECT label,value FROM pdg_product_fields WHERE product_id=?');
foreach($rows as $r){$find->execute([$r['brand'],$r['code']]);$fields->execute([$find->fetchColumn()]);$values=$fields->fetchAll(PDO::FETCH_KEY_PAIR);foreach(pdg_official_inverter_fields($r) as $label=>$value)verify($values[$label]===$value,'Correction missing '.$r['code'].' '.$label);}
$count=$db->query('SELECT COUNT(*) FROM pdg_product_fields')->fetchColumn();
$db->exec("UPDATE pdg_product_fields SET value='999' WHERE label='Брой MPPT'");$db->exec("DELETE FROM pdg_catalog_migrations WHERE name LIKE 'official_inverters_%'");pdg_import_official_inverters($db);
verify($db->query('SELECT COUNT(*) FROM pdg_product_fields')->fetchColumn()===$count,'No duplicate fields after repair');
verify($db->query('SELECT id,brand,code,name,price,image_url FROM products ORDER BY id')->fetchAll()===$before,'Retry preserved all product data');
echo "PASS:115 catalog corrections, idempotent repair, exact fields, preserved prices/photos/names and no duplicates\n";
