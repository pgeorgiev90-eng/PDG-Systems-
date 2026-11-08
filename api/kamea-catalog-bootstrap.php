<?php
declare(strict_types=1);
// Bundled supplier catalog only. Never accept remote data or SQL from a request.
function pdg_import_kamea_catalog(PDO $db): void {
 $json=file_get_contents(dirname(__DIR__).'/database/kamea-catalog.json');
 if($json===false)throw new RuntimeException('Kamea catalog unavailable');
 $version='kamea_deye_dyness_'.substr(hash('sha256',$json),0,24);
 $migration=$db->prepare('SELECT name FROM pdg_catalog_migrations WHERE name=?');$migration->execute([$version]);if($migration->fetchColumn())return;
 if((int)$db->query("SELECT GET_LOCK('pdg_kamea_catalog',10)")->fetchColumn()!==1)throw new RuntimeException('Kamea import busy');
 try {
  $migration->execute([$version]);if($migration->fetchColumn())return;
  $items=json_decode($json,true,512,JSON_THROW_ON_ERROR);if(!is_array($items)||!$items)throw new RuntimeException('Invalid Kamea catalog');
  $find=$db->prepare('SELECT id FROM products WHERE brand=? AND code=? FOR UPDATE');
  $insert=$db->prepare('INSERT INTO products(brand,category,code,name,price,image_url) VALUES(?,?,?,?,NULL,?)');
  $photo=$db->prepare("UPDATE products SET image_url=?,image_index=NULL WHERE id=? AND (image_url IS NULL OR TRIM(image_url)='')");
  $labels=$db->prepare('SELECT label FROM pdg_product_fields WHERE product_id=?');
  $field=$db->prepare('INSERT INTO pdg_product_fields(product_id,label,value,sort_order) VALUES(?,?,?,?)');
  $db->beginTransaction();
  foreach($items as $item){
   if(!in_array($item['brand'],['Deye','Dyness'],true)||$item['price']!==null||!preg_match('~^https://pdgsystems\.bg/assets/products/kamea/[a-f0-9]{16}\.(?:jpg|png|webp|gif)$~D',$item['image']))throw new RuntimeException('Invalid Kamea product');
   $find->execute([$item['brand'],$item['code']]);$ids=$find->fetchAll(PDO::FETCH_COLUMN);
   if(!$ids){$insert->execute([$item['brand'],$item['category'],$item['code'],$item['name'],$item['image']]);$ids=[$db->lastInsertId()];}
   foreach($ids as $id){
    $photo->execute([$item['image'],$id]);$labels->execute([$id]);$existing=array_map(fn($x)=>mb_strtolower(trim($x),'UTF-8'),$labels->fetchAll(PDO::FETCH_COLUMN));
    $all=array_merge([['label'=>'Описание','value'=>$item['description']],['label'=>'Бележка за снимката','value'=>$item['imageCaption']]],$item['fields']);
    foreach($all as $i=>$f){if(!is_string($f['label'])||!is_string($f['value'])||mb_strlen($f['label'],'UTF-8')>190||mb_strlen($f['value'],'UTF-8')>20000)throw new RuntimeException('Invalid Kamea specification');$key=mb_strtolower(trim($f['label']),'UTF-8');if(in_array($key,$existing,true))continue;$field->execute([$id,$f['label'],$f['value'],$i+10]);$existing[]=$key;}
   }
  }
  $db->prepare('INSERT INTO pdg_catalog_migrations(name) VALUES(?)')->execute([$version]);$db->commit();
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
 finally{$db->query("SELECT RELEASE_LOCK('pdg_kamea_catalog')");}
}
