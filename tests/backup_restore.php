<?php
declare(strict_types=1);
require __DIR__.'/../api/backup-common.php';
$s=require dirname(__DIR__,2).'/pdg-shop-db.php';
$db=new PDO('mysql:host='.$s['host'].';dbname='.$s['database'].';charset=utf8mb4',$s['username'],$s['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$q=$db->prepare('UPDATE products SET name=? WHERE id=1');$q->execute(["Тест ' кавички ; и нов\nред <b>UTF-8</b>"]);
$file=tempnam(sys_get_temp_dir(),'pdg-backup-test-');unlink($file);$result=pdg_write_backup($db,$file);
$db->exec('CREATE DATABASE pdg_restore_ci CHARACTER SET utf8mb4');
$restore=new PDO('mysql:host='.$s['host'].';dbname=pdg_restore_ci;charset=utf8mb4',$s['username'],$s['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$restore->exec(file_get_contents($file));unlink($file);
$tables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
foreach($tables as $table){$a=$db->query('SELECT * FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC);$b=$restore->query('SELECT * FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC);if($a!==$b)throw new RuntimeException('Restore mismatch: '.$table);}
echo 'PASS: consistent SQL archive restored into separate database with all '.count($tables).' tables and exact values, including Unicode, quotes and line breaks.'.PHP_EOL;
