<?php
declare(strict_types=1);
function pdg_write_backup(PDO $db, string $path): array {
    $out=fopen($path,'xb');if(!$out)throw new RuntimeException('backup_storage');$tables=0;$rows=0;$bytes=0;
    $write=static function(string $text)use($out,&$bytes):void{$bytes+=strlen($text);if($bytes>100*1024*1024||fwrite($out,$text)!==strlen($text))throw new RuntimeException('backup_size');};
    try {
        $names=$db->query('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"')->fetchAll(PDO::FETCH_COLUMN);
        $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$db->beginTransaction();
        $write("-- PDG Systems database backup, UTC ".gmdate('c')."\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");
        foreach($names as $name){if(!preg_match('/^[a-zA-Z0-9_]+$/D',$name))throw new RuntimeException('backup_table');
            $create=$db->query('SHOW CREATE TABLE `'.$name.'`')->fetch(PDO::FETCH_NUM);$write($create[1].";\n");
            $query=$db->query('SELECT * FROM `'.$name.'`');
            while($row=$query->fetch(PDO::FETCH_ASSOC)){$columns=implode(',',array_map(fn($v)=>'`'.str_replace('`','``',$v).'`',array_keys($row)));$values=implode(',',array_map(fn($v)=>$v===null?'NULL':$db->quote((string)$v),array_values($row)));$write('INSERT INTO `'.$name.'` ('.$columns.') VALUES ('.$values.");\n");$rows++;}
            $tables++;
        }
        $write("SET FOREIGN_KEY_CHECKS=1;\n");$db->commit();fclose($out);return ['tables'=>$tables,'rows'=>$rows,'bytes'=>$bytes];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();fclose($out);unlink($path);throw $e;}
}
