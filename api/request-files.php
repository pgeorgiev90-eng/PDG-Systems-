<?php
declare(strict_types=1);
require __DIR__.'/admin-common.php';
require_once __DIR__.'/commerce-common.php';
try {
    [$db,$user]=pdg_account_session(false);pdg_commerce_schema($db);
    if($_SERVER['REQUEST_METHOD']==='GET'){
        $id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);if(!$id)pdg_answer(422,['error'=>'invalid_file']);
        $q=$db->prepare('SELECT f.*,r.user_id FROM pdg_request_files f JOIN pdg_customer_requests r ON r.id=f.request_id WHERE f.id=?');$q->execute([$id]);$f=$q->fetch();
        $role=$db->prepare('SELECT role FROM pdg_users WHERE id=?');$role->execute([$user]);$admin=$role->fetchColumn()==='admin';
        if(!$f||((int)$f['user_id']!==$user&&!$admin))pdg_answer(404,['error'=>'not_found']);
        $path=dirname(__DIR__,2).'/pdg-request-files/'.$f['stored_name'];if(!is_file($path))pdg_answer(404,['error'=>'not_found']);
        header('Content-Type: '.$f['mime']);header('Content-Disposition: attachment; filename="request-photo-'.(int)$id.'.'.pathinfo($f['stored_name'],PATHINFO_EXTENSION).'"');
        header('Content-Length: '.filesize($path));readfile($path);exit;
    }
    if($_SERVER['REQUEST_METHOD']!=='POST')pdg_answer(405,['error'=>'method_not_allowed']);
    $id=filter_input(INPUT_POST,'request_id',FILTER_VALIDATE_INT);$q=$db->prepare('SELECT id FROM pdg_customer_requests WHERE id=? AND user_id=?');$q->execute([$id,$user]);if(!$q->fetchColumn())pdg_answer(404,['error'=>'not_found']);
    $file=$_FILES['photo']??null;if(!is_array($file)||($file['error']??-1)!==UPLOAD_ERR_OK||($file['size']??0)<1||$file['size']>4*1024*1024||!is_uploaded_file($file['tmp_name']))pdg_answer(422,['error'=>'invalid_photo']);
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);$ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$mime]??null;
    $size=@getimagesize($file['tmp_name']);if(!$ext||!$size||$size[0]>12000||$size[1]>12000)pdg_answer(422,['error'=>'invalid_photo']);
    $db->beginTransaction();$q=$db->prepare('SELECT id FROM pdg_customer_requests WHERE id=? FOR UPDATE');$q->execute([$id]);
    $q=$db->prepare('SELECT COUNT(*) FROM pdg_request_files WHERE request_id=?');$q->execute([$id]);if((int)$q->fetchColumn()>=4){$db->rollBack();pdg_answer(422,['error'=>'photo_limit']);}
    $folder=dirname(__DIR__,2).'/pdg-request-files';if(!is_dir($folder)&&!mkdir($folder,0750,true))throw new RuntimeException('storage');
    $name=bin2hex(random_bytes(20)).'.'.$ext;$stored=$folder.'/'.$name;if(!move_uploaded_file($file['tmp_name'],$stored))throw new RuntimeException('storage');chmod($stored,0640);
    $q=$db->prepare('INSERT INTO pdg_request_files(request_id,stored_name,mime,bytes) VALUES(?,?,?,?)');$q->execute([$id,$name,$mime,$file['size']]);$fileId=(int)$db->lastInsertId();$db->commit();pdg_answer(200,['ok'=>true,'id'=>$fileId]);
}catch(Throwable $e){if(isset($db)&&$db->inTransaction())$db->rollBack();if(isset($stored)&&is_file($stored))unlink($stored);pdg_answer(503,['error'=>'file_unavailable']);}
