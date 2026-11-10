<?php
declare(strict_types=1);
// User-authorized one-time revision of existing prices; no recurring markup.
function pdg_brand_price_revision_20261110(PDO $db): array {
    $version='brand_prices_20261110_solax26_enerack33';
    $db->exec('CREATE TABLE IF NOT EXISTS pdg_price_revision_runs (version VARCHAR(100) PRIMARY KEY, changed_count INT UNSIGNED NOT NULL, summary_json TEXT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
    $db->exec('CREATE TABLE IF NOT EXISTS pdg_price_revision_rows (version VARCHAR(100) NOT NULL, product_id BIGINT UNSIGNED NOT NULL, brand VARCHAR(120) NOT NULL, old_price DECIMAL(12,2) NOT NULL, new_price DECIMAL(12,2) NOT NULL, PRIMARY KEY(version,product_id)) ENGINE=InnoDB');
    $done=$db->prepare('SELECT changed_count,summary_json FROM pdg_price_revision_runs WHERE version=?');
    $done->execute([$version]);
    if($run=$done->fetch(PDO::FETCH_ASSOC))return ['version'=>$version,'verified'=>true,'counts'=>json_decode($run['summary_json'],true,512,JSON_THROW_ON_ERROR)];
    if((int)$db->query("SELECT GET_LOCK('pdg_brand_prices_20261110',20)")->fetchColumn()!==1)throw new RuntimeException('Price revision busy');
    try {
        $done->execute([$version]);
        if($run=$done->fetch(PDO::FETCH_ASSOC))return ['version'=>$version,'verified'=>true,'counts'=>json_decode($run['summary_json'],true,512,JSON_THROW_ON_ERROR)];
        $db->beginTransaction();
        $rows=$db->query("SELECT id,brand,price FROM products WHERE LOWER(TRIM(brand)) IN ('solax','enerack') AND price>0 ORDER BY id FOR UPDATE")->fetchAll(PDO::FETCH_ASSOC);
        $update=$db->prepare('UPDATE products SET price=? WHERE id=? AND price=?');
        $backup=$db->prepare('INSERT INTO pdg_price_revision_rows(version,product_id,brand,old_price,new_price) VALUES(?,?,?,?,?)');
        $verify=$db->prepare('SELECT price FROM products WHERE id=?');
        $counts=['SolaX'=>0,'Enerack'=>0];
        foreach($rows as $p){
            $key=strtolower(trim($p['brand']))==='solax'?'SolaX':'Enerack';
            $factor=$key==='SolaX'?126:133;
            if(!preg_match('/^(\d+)\.(\d{2})$/D',(string)$p['price'],$m))throw new RuntimeException('Invalid stored price');
            $cents=(int)$m[1]*100+(int)$m[2];
            $newCents=intdiv($cents*$factor+50,100);
            if($newCents>999999999999)throw new RuntimeException('Price exceeds database precision');
            $new=intdiv($newCents,100).'.'.str_pad((string)($newCents%100),2,'0',STR_PAD_LEFT);
            $backup->execute([$version,$p['id'],$p['brand'],$p['price'],$new]);
            $update->execute([$new,$p['id'],$p['price']]);
            $verify->execute([$p['id']]);
            if($verify->fetchColumn()!==$new)throw new RuntimeException('Price readback failed');
            $counts[$key]++;
        }
        $db->prepare('INSERT INTO pdg_price_revision_runs(version,changed_count,summary_json) VALUES(?,?,?)')->execute([$version,count($rows),json_encode($counts,JSON_THROW_ON_ERROR)]);
        $db->commit();
        return ['version'=>$version,'verified'=>true,'counts'=>$counts];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    finally{$db->query("SELECT RELEASE_LOCK('pdg_brand_prices_20261110')");}
}
