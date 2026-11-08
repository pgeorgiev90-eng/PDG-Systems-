<?php
declare(strict_types=1);
// Versioned electrical corrections only. Never change prices, photos or product identity.
function pdg_official_inverter_fields(array $r): array {
    $fields=['CAD: проверка на инвертора'=>empty($r['verified'])?'непотвърден':'проверен', 'Източник на проверката на инвертора'=>$r['source'], 'Бележка за версията на инвертора'=>$r['note']];
    if(isset($r['supplierSource']))$fields['Каталожен източник за версията']=$r['supplierSource'];
    if(empty($r['verified']))return $fields;
    $n=count($r['inputs']);
    foreach(['inputs','currents','shortCurrents'] as $key){if(count($r[$key])!==$n||!$n)throw new RuntimeException('Invalid MPPT vector');foreach($r[$key] as $v)if(!is_numeric($v)||$v<=0)throw new RuntimeException('Invalid MPPT rating');}
    if(!in_array($r['phase'],[1,3],true)||$r['minMppt']>=$r['maxMppt']||$r['maxMppt']>$r['maxVoltage']||$r['pvPowerW']<=0)throw new RuntimeException('Invalid inverter voltage/power');
    return array_merge($fields,[
        'Брой фази'=>(string)$r['phase'], 'Номинална AC мощност (kW)'=>(string)$r['acPowerKw'],
        'Допустима мощност на PV масива (W)'=>(string)$r['pvPowerW'],
        'Максимална PV мощност за преобразуване (W)'=>isset($r['conversionPowerW'])?(string)$r['conversionPowerW']:'Не е посочена отделно',
        'Максимално DC напрежение (V)'=>(string)$r['maxVoltage'],
        'Минимално MPPT напрежение (V)'=>(string)$r['minMppt'], 'Максимално MPPT напрежение (V)'=>(string)$r['maxMppt'],
        'Брой MPPT'=>(string)$n, 'MPPT / стрингове на MPPT'=>$n.'/'.implode('+',$r['inputs']),
        'Максимален входен ток на MPPT'=>implode('+',$r['currents']), 'Максимален ток на късо съединение на MPPT'=>implode('+',$r['shortCurrents']),
        'Дата на проверката на инвертора'=>$r['verified']
    ]);
}
function pdg_import_official_inverters(PDO $db): void {
    $json=file_get_contents(dirname(__DIR__).'/database/official-inverter-specs.json');if($json===false)throw new RuntimeException('Inverter corrections unavailable');
    $version='official_inverters_'.substr(hash('sha256',$json),0,24);
    $migration=$db->prepare('SELECT name FROM pdg_catalog_migrations WHERE name=?');$migration->execute([$version]);if($migration->fetchColumn())return;
    if((int)$db->query("SELECT GET_LOCK('pdg_official_inverters',10)")->fetchColumn()!==1)throw new RuntimeException('Inverter correction busy');
    try{
        $migration->execute([$version]);if($migration->fetchColumn())return;
        $rows=json_decode($json,true,512,JSON_THROW_ON_ERROR);if(count($rows)!==115)throw new RuntimeException('Incomplete inverter correction catalog');
        $db->exec('CREATE TABLE IF NOT EXISTS pdg_inverter_spec_history(product_id BIGINT UNSIGNED NOT NULL,version VARCHAR(190) NOT NULL,fields_json MEDIUMTEXT NOT NULL,recorded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(product_id,version)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $db->beginTransaction();
        $find=$db->prepare('SELECT id FROM products WHERE brand=? AND code=? FOR UPDATE');
        $read=$db->prepare('SELECT label,value,sort_order FROM pdg_product_fields WHERE product_id=?');
        $history=$db->prepare('INSERT IGNORE INTO pdg_inverter_spec_history(product_id,version,fields_json) VALUES(?,?,?)');
        $delete=$db->prepare('DELETE FROM pdg_product_fields WHERE product_id=? AND label=?');
        $insert=$db->prepare('INSERT INTO pdg_product_fields(product_id,label,value,sort_order) VALUES(?,?,?,?)');
        foreach($rows as $r){$fields=pdg_official_inverter_fields($r);$find->execute([$r['brand'],$r['code']]);foreach($find->fetchAll(PDO::FETCH_COLUMN) as $id){$read->execute([$id]);$history->execute([$id,$version,json_encode($read->fetchAll(PDO::FETCH_ASSOC),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);$order=-100;foreach($fields as $label=>$value){$delete->execute([$id,$label]);$insert->execute([$id,$label,$value,$order++]);}}}
        $db->prepare('INSERT INTO pdg_catalog_migrations(name) VALUES(?)')->execute([$version]);$db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}finally{$db->query("SELECT RELEASE_LOCK('pdg_official_inverters')");}
}
