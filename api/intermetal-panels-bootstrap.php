<?php
declare(strict_types=1);
// Import published supplier models once; never replace a manually maintained price.
function pdg_import_intermetal_panels(PDO $db): void {
    $json=file_get_contents(dirname(__DIR__).'/database/intermetal-panels.json');
    if($json===false)throw new RuntimeException('Panel catalog unavailable');
    $version='intermetal_panels_'.substr(hash('sha256',$json),0,24);
    $migration=$db->prepare('SELECT name FROM pdg_catalog_migrations WHERE name=?');
    $migration->execute([$version]);if($migration->fetchColumn())return;
    if((int)$db->query("SELECT GET_LOCK('pdg_intermetal_panels',10)")->fetchColumn()!==1)throw new RuntimeException('Panel import busy');
    try {
        $migration->execute([$version]);if($migration->fetchColumn())return;
        $items=json_decode($json,true,512,JSON_THROW_ON_ERROR);
        if(!is_array($items)||!$items)throw new RuntimeException('Empty panel catalog');
        $db->beginTransaction();
        $find=$db->prepare('SELECT id FROM products WHERE brand=? AND code=? FOR UPDATE');
        $insert=$db->prepare('INSERT INTO products(brand,category,code,name,price,image_url) VALUES(?,?,?,?,NULL,?)');
        $photo=$db->prepare("UPDATE products SET image_url=?,image_index=NULL WHERE id=? AND (image_url IS NULL OR TRIM(image_url)='')");
        $labels=$db->prepare('SELECT label FROM pdg_product_fields WHERE product_id=?');
        $field=$db->prepare('INSERT INTO pdg_product_fields(product_id,label,value,sort_order) VALUES(?,?,?,?)');
        foreach($items as $item){
            if(($item['price']??null)!==null||$item['category']!=='Фотоволтаични панели'||!preg_match('~^https://pdgsystems\.bg/assets/products/[a-z0-9-]+\.jpg$~D',$item['image']))throw new RuntimeException('Invalid panel import');
            $find->execute([$item['brand'],$item['code']]);$ids=$find->fetchAll(PDO::FETCH_COLUMN);
            if(!$ids){$insert->execute([$item['brand'],$item['category'],$item['code'],$item['name'],$item['image']]);$ids=[$db->lastInsertId()];}
            foreach($ids as $id){
                $photo->execute([$item['image'],$id]);$labels->execute([$id]);$existing=array_map(fn($s)=>mb_strtolower(trim($s),'UTF-8'),$labels->fetchAll(PDO::FETCH_COLUMN));
                $all=array_merge([['label'=>'Описание','value'=>$item['description']],['label'=>'Бележка за снимката','value'=>$item['imageCaption']]],$item['fields']);
                foreach($all as $i=>$f){$key=mb_strtolower(trim($f['label']),'UTF-8');if(in_array($key,$existing,true))continue;$field->execute([$id,$f['label'],$f['value'],$i+10]);$existing[]=$key;}
            }
        }
        $db->prepare('INSERT INTO pdg_catalog_migrations(name) VALUES(?)')->execute([$version]);$db->commit();
    }catch(Throwable $error){if($db->inTransaction())$db->rollBack();throw $error;}
    finally{$db->query("SELECT RELEASE_LOCK('pdg_intermetal_panels')");}
}
