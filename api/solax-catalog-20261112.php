<?php
declare(strict_types=1);
// Owner-authorized supplier-list replacement: cost + 30%, stored net EUR.
// All inputs are immutable bundled files, never request parameters.
function pdg_solax_catalog_20261112(PDO $db): array {
    $version='solax_supplier_20261112_plus30_v1';
    $db->exec('CREATE TABLE IF NOT EXISTS pdg_solax_import_runs(version VARCHAR(100) PRIMARY KEY, summary_json TEXT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
    $db->exec('CREATE TABLE IF NOT EXISTS pdg_solax_import_history(version VARCHAR(100) NOT NULL,product_id BIGINT UNSIGNED NOT NULL,created_product TINYINT NOT NULL,product_json MEDIUMTEXT NULL,fields_json MEDIUMTEXT NOT NULL,PRIMARY KEY(version,product_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $done=$db->prepare('SELECT summary_json FROM pdg_solax_import_runs WHERE version=?');
    $done->execute([$version]);if($json=$done->fetchColumn())return pdg_solax_finish_20261112($db,json_decode($json,true,512,JSON_THROW_ON_ERROR));
    if((int)$db->query("SELECT GET_LOCK('pdg_solax_supplier_20261112',20)")->fetchColumn()!==1)throw new RuntimeException('SolaX catalog busy');
    try {
        $done->execute([$version]);if($json=$done->fetchColumn())return pdg_solax_finish_20261112($db,json_decode($json,true,512,JSON_THROW_ON_ERROR));
        $json=file_get_contents(dirname(__DIR__).'/database/solax-catalog-20261112.json');
        if($json===false)throw new RuntimeException('SolaX data unavailable');
        $items=json_decode($json,true,512,JSON_THROW_ON_ERROR);
        if(count($items)!==155)throw new RuntimeException('Incomplete SolaX supplier list');
        $seen=[];
        foreach($items as $r){
            $key=$r['brand'].'|'.$r['code'];if(isset($seen[$key]))throw new RuntimeException('Duplicate variant');$seen[$key]=true;
            if(!in_array($r['brand'],['SolaX','Chint','Eastron','KBE','Leader','Stäubli','MC4','Tigo'],true)||!preg_match('~^https://pdgsystems\.bg/assets/products/solax-20261112/[a-f0-9]{20}\.(png|jpg|webp|avif|gif)$~D',$r['image'])||mb_strlen($r['description'],'UTF-8')<150)throw new RuntimeException('Invalid SolaX item');
            if($r['sourceNetEur']===null){if($r['price']!==null)throw new RuntimeException('Inquiry-only item priced');}
            else {
                if(!preg_match('/^\d+\.\d{2}$/D',$r['sourceNetEur'])||!preg_match('/^\d+\.\d{2}$/D',$r['price']))throw new RuntimeException('Invalid price');
                $cost=(int)str_replace('.','',$r['sourceNetEur']);$price=(int)str_replace('.','',$r['price']);
                if($price!==intdiv($cost*130+50,100))throw new RuntimeException('Markup differs from 30 percent');
            }
            $labels=[];foreach($r['fields'] as $f){if(!is_string($f['label'])||!is_string($f['value'])||mb_strlen($f['label'],'UTF-8')>190||mb_strlen($f['value'],'UTF-8')>20000||isset($labels[$f['label']]))throw new RuntimeException('Invalid specification');$labels[$f['label']]=true;}
        }
        $find=$db->prepare('SELECT * FROM products WHERE brand=? AND code=? FOR UPDATE');
        $read=$db->prepare('SELECT label,value,sort_order FROM pdg_product_fields WHERE product_id=? ORDER BY id');
        $backup=$db->prepare('INSERT INTO pdg_solax_import_history(version,product_id,created_product,product_json,fields_json) VALUES(?,?,?,?,?)');
        $insert=$db->prepare('INSERT INTO products(brand,category,code,name,price,image_url) VALUES(?,?,?,?,?,?)');
        $update=$db->prepare('UPDATE products SET category=?,name=?,price=?,image_url=?,image_index=NULL WHERE id=?');
        $remove=$db->prepare('DELETE FROM pdg_product_fields WHERE product_id=? AND label=?');
        $field=$db->prepare('INSERT INTO pdg_product_fields(product_id,label,value,sort_order) VALUES(?,?,?,?)');
        $verify=$db->prepare('SELECT price,image_url,category FROM products WHERE id=?');
        $summary=['version'=>$version,'verified'=>true,'updated'=>0,'added'=>0,'priced'=>0,'inquiry'=>0,'total'=>count($items)];
        $db->beginTransaction();
        foreach($items as $r){
            $find->execute([$r['brand'],$r['code']]);$matches=$find->fetchAll(PDO::FETCH_ASSOC);
            if(count($matches)>1)throw new RuntimeException('Ambiguous live product');
            $old=$matches[0]??null;
            if($r['expectedId']!==null&&(!$old||(int)$old['id']!==$r['expectedId']))throw new RuntimeException('Catalog identity changed');
            if($r['expectedId']===null&&$old)throw new RuntimeException('New variant already exists unexpectedly');
            if($old){
                $id=(int)$old['id'];$read->execute([$id]);$previous=$read->fetchAll(PDO::FETCH_ASSOC);
                $backup->execute([$version,$id,0,json_encode($old,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),json_encode($previous,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
                $update->execute([$r['category'],$r['name'],$r['price'],$r['image'],$id]);$summary['updated']++;
            }else{
                $insert->execute([$r['brand'],$r['category'],$r['code'],$r['name'],$r['price'],$r['image']]);$id=(int)$db->lastInsertId();
                $backup->execute([$version,$id,1,null,'[]']);$summary['added']++;
            }
            $read->execute([$id]);$existingLabels=array_column($read->fetchAll(PDO::FETCH_ASSOC),'label');
            $all=array_merge([['label'=>'Описание','value'=>$r['description']]],$r['fields'],[['label'=>'Източник на ценовата листа','value'=>implode("\n",$r['sources'])]]);
            foreach($all as $order=>$f){if($old&&$f['label']!=='Описание'&&in_array($f['label'],$existingLabels,true))continue;$remove->execute([$id,$f['label']]);$field->execute([$id,$f['label'],$f['value'],$order]);}
            $verify->execute([$id]);$saved=$verify->fetch(PDO::FETCH_ASSOC);
            if($saved['price']!==$r['price']||$saved['image_url']!==$r['image']||$saved['category']!==$r['category'])throw new RuntimeException('Catalog readback failed');
            $summary[$r['price']===null?'inquiry':'priced']++;
        }
        $db->prepare('INSERT INTO pdg_solax_import_runs(version,summary_json) VALUES(?,?)')->execute([$version,json_encode($summary,JSON_THROW_ON_ERROR)]);
        $db->commit();return pdg_solax_finish_20261112($db,$summary);
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    finally{$db->query("SELECT RELEASE_LOCK('pdg_solax_supplier_20261112')");}
}

// Reassert reviewed manufacturer electrical ratings after any older specification seed.
// Also covers an import completed by the first release before this preservation guard.
function pdg_solax_finish_20261112(PDO $db,array $summary):array {
    if((int)$db->query("SELECT GET_LOCK('pdg_solax_reviewed_20261112',20)")->fetchColumn()!==1)throw new RuntimeException('Reviewed specifications busy');
    try {
    $version='solax_reviewed_specs_20261112_v1';
    $q=$db->prepare('SELECT summary_json FROM pdg_solax_import_runs WHERE version=?');$q->execute([$version]);if($q->fetchColumn())return $summary;
    require_once __DIR__.'/official-inverters-bootstrap.php';
    $rows=json_decode(file_get_contents(dirname(__DIR__).'/database/official-inverter-specs.json'),true,512,JSON_THROW_ON_ERROR);
    $catalog=json_decode(file_get_contents(dirname(__DIR__).'/database/solax-catalog-20261112.json'),true,512,JSON_THROW_ON_ERROR);
    $descriptions=[];foreach($catalog as $r)if($r['brand']==='SolaX')$descriptions[$r['code']]=$r['description'];
    $find=$db->prepare('SELECT id FROM products WHERE brand=? AND code=?');
    $remove=$db->prepare('DELETE FROM pdg_product_fields WHERE product_id=? AND label=?');
    $insert=$db->prepare('INSERT INTO pdg_product_fields(product_id,label,value,sort_order) VALUES(?,?,?,?)');
    $db->beginTransaction();
    try {
        foreach($rows as $r){
            if($r['brand']!=='SolaX'||empty($r['verified']))continue;
            $fields=pdg_official_inverter_fields($r);
            if(isset($descriptions[$r['code']]))$fields['Описание']=$descriptions[$r['code']];
            $find->execute(['SolaX',$r['code']]);
            foreach($find->fetchAll(PDO::FETCH_COLUMN) as $id){$order=0;foreach($fields as $label=>$value){$remove->execute([$id,$label]);$insert->execute([$id,$label,$value,$order++]);}}
        }
        $db->prepare('INSERT INTO pdg_solax_import_runs(version,summary_json) VALUES(?,?)')->execute([$version,'{"verified":true}']);
        $db->commit();return $summary;
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }finally{$db->query("SELECT RELEASE_LOCK('pdg_solax_reviewed_20261112')");}
}
