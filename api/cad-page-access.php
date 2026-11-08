<?php
declare(strict_types=1);
require_once __DIR__.'/admin-common.php';

function pdg_require_cad_page(string $editor): void {
    try {
        $next=$editor;
        $args=[];if(in_array($editor,['roof-cad.php','electrical-cad.php'],true)){if(isset($_GET['project'])&&filter_var($_GET['project'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]))$args['project']=(int)$_GET['project'];if(in_array($_GET['prices']??'', ['current','saved'],true))$args['prices']=$_GET['prices'];if(($_GET['fresh']??'')==='1')$args['fresh']='1';}
        if($args)$next.='?'.http_build_query($args);
        pdg_account_session(false, '/auth.html?next='.rawurlencode($next));
        session_write_close();
        header('Content-Type: text/html; charset=utf-8');
    } catch (Throwable $e) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Входът в CAD временно е недостъпен. Опитайте отново след малко.');
    }
}
