<?php
declare(strict_types=1);

// One-time supplier prices. A non-null price always belongs to the administrator.
function pdg_import_kamea_prices(PDO $db): void {
    $json=file_get_contents(dirname(__DIR__).'/database/kamea-prices.json');
    if ($json===false) throw new RuntimeException('Kamea prices unavailable');
    $version='kamea_prices_'.substr(hash('sha256',$json),0,24);
    $done=$db->prepare('SELECT name FROM pdg_catalog_migrations WHERE name=?');
    $done->execute([$version]);if($done->fetchColumn())return;
    if((int)$db->query("SELECT GET_LOCK('pdg_kamea_prices',10)")->fetchColumn()!==1)throw new RuntimeException('Kamea prices busy');
    try {
        $done->execute([$version]);if($done->fetchColumn())return;
        $rows=json_decode($json,true,512,JSON_THROW_ON_ERROR);
        if(!is_array($rows)||count($rows)!==150)throw new RuntimeException('Incomplete Kamea prices');
        $find=$db->prepare('SELECT id,price FROM products WHERE brand=? AND code=? FOR UPDATE');
        $update=$db->prepare('UPDATE products SET price=? WHERE id=? AND price IS NULL');
        $verify=$db->prepare('SELECT price FROM products WHERE id=?');
        $seen=[];$db->beginTransaction();
        foreach($rows as $row){
            $key=$row['brand'].'|'.$row['code'];
            if(isset($seen[$key])||!in_array($row['brand'],['Deye','Dyness'],true)||!preg_match('/^\d+\.\d{2}$/D',$row['netEur'])||!preg_match('/^\d+\.\d{2}$/D',$row['grossEur'])||$row['vatPercent']!==20)throw new RuntimeException('Invalid Kamea price');
            $seen[$key]=true;
            $gross=(int)str_replace('.','',$row['grossEur']);$net=(int)str_replace('.','',$row['netEur']);
            if($net<=0||$net!==intdiv($gross*5+3,6))throw new RuntimeException('Invalid VAT conversion');
            $find->execute([$row['brand'],$row['code']]);$products=$find->fetchAll(PDO::FETCH_ASSOC);
            if(!$products)throw new RuntimeException('Missing Kamea product');
            foreach($products as $p){
                if($p['price']!==null)continue;
                $update->execute([$row['netEur'],$p['id']]);
                $verify->execute([$p['id']]);
                if($verify->fetchColumn()!==$row['netEur'])throw new RuntimeException('Kamea price verification failed');
            }
        }
        $db->prepare('INSERT INTO pdg_catalog_migrations(name) VALUES(?)')->execute([$version]);
        $db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    finally{$db->query("SELECT RELEASE_LOCK('pdg_kamea_prices')");}
}
