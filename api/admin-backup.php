<?php
declare(strict_types=1);
require __DIR__.'/admin-common.php';require __DIR__.'/backup-common.php';
try {
    [$db,$actor]=pdg_admin_session();$folder=dirname(__DIR__,2).'/pdg-private-backups';
    if($_SERVER['REQUEST_METHOD']==='POST'){
        if(!is_dir($folder)&&!mkdir($folder,0700,true))throw new RuntimeException('backup_storage');
        $id=bin2hex(random_bytes(24));$path=$folder.'/'.$id.'.sql';$result=pdg_write_backup($db,$path);chmod($path,0600);
        pdg_answer(200,['ok'=>true,'id'=>$id,...$result]);
    }
    if($_SERVER['REQUEST_METHOD']!=='GET')pdg_answer(405,['error'=>'method_not_allowed']);
    $id=$_GET['id']??'';if(!is_string($id)||!preg_match('/^[a-f0-9]{48}$/D',$id))pdg_answer(422,['error'=>'invalid_backup']);
    $path=$folder.'/'.$id.'.sql';if(!is_file($path))pdg_answer(404,['error'=>'not_found']);
    header('Content-Type: application/sql; charset=utf-8');header('Content-Disposition: attachment; filename="PDG-database-'.gmdate('Ymd-His',filemtime($path)).'.sql"');header('Content-Length: '.filesize($path));readfile($path);
}catch(Throwable $e){pdg_answer(503,['error'=>'backup_unavailable']);}
