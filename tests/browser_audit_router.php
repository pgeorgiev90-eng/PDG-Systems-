<?php
// CI only: serve the real editor markup without production credentials.
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if($path==='/roof-cad.php'){$s=file_get_contents(__DIR__.'/../roof-cad.php');$s=substr($s,strpos($s,'?>')+2);$s=preg_replace('/<\?=.*?\?>/s','1',$s);header('Content-Type: text/html; charset=utf-8');echo $s;return true;}
if($path==='/api/account-draft.php'){header('Content-Type: application/json');echo json_encode(['csrf'=>'browser-test','user'=>['id'=>1,'email'=>'fixture@example.test','role'=>'customer']]);return true;}
if(str_starts_with($path,'/api/')){header('Content-Type: application/json');echo json_encode(['products'=>[],'prices_visible'=>false,'fields'=>[]]);return true;}
return false;
