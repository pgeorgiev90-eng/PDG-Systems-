<?php
declare(strict_types=1);
require __DIR__.'/admin-common.php';
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') pdg_answer(405,['error'=>'method_not_allowed']);
    [$db] = pdg_admin_session();
    $period = $_GET['period'] ?? 'today';
    if (!in_array($period,['today','week','month'],true)) pdg_answer(422,['error'=>'invalid_period']);
    $today = new DateTimeImmutable((string)$db->query('SELECT CURDATE()')->fetchColumn());
    $start = ($period === 'week' ? $today->modify('-6 days') : ($period === 'month' ? $today->modify('first day of this month') : $today))->format('Y-m-d');
    $end = $today->modify('+1 day')->format('Y-m-d');
    $errors=[];
    $read=static function(string $key,string $sql,array $params=[],bool $scalar=true) use($db,&$errors) {
        try {$q=$db->prepare($sql);$q->execute($params);return $scalar?(int)$q->fetchColumn():$q->fetchAll();}
        catch(Throwable $e){$errors[]=$key;return null;}
    };
    $metrics=[];
    $metrics['orders']=$read('orders',"SELECT COUNT(*) FROM pdg_orders WHERE status='new' AND created_at>=? AND created_at<?",[$start,$end]);
    $pending=$read('pending',"SELECT COUNT(*) FROM pdg_orders WHERE status IN ('new','confirmed','processing')");
    $metrics['unread']=$read('unread',"SELECT COUNT(*) FROM pdg_electric_enquiries e LEFT JOIN pdg_enquiry_reads r ON r.enquiry_id=e.id WHERE r.enquiry_id IS NULL AND e.status<>'reviewed'");
    $metrics['visits']=$read('visits',"SELECT COALESCE(SUM(total),0) FROM pdg_site_metrics WHERE event_name='visit' AND day>=? AND day<?",[$start,$end]);
    $metrics['clicks']=$read('clicks',"SELECT COALESCE(SUM(total),0) FROM pdg_site_metrics WHERE event_name='product' AND day>=? AND day<?",[$start,$end]);
    $metrics['registrations']=$read('registrations',"SELECT COUNT(*) FROM pdg_users WHERE role='customer' AND created_at>=? AND created_at<?",[$start,$end]);
    $metrics['projects']=$read('projects',"SELECT COUNT(*) FROM pdg_cad_project_requests WHERE status='review'");
    $orders=$read('recent_orders','SELECT id,order_number,customer_name,status,total_cents,currency FROM pdg_orders WHERE created_at>=? AND created_at<? ORDER BY id DESC LIMIT 5',[$start,$end],false);
    $daily=$read('daily',"SELECT day,SUM(total) AS total FROM pdg_site_metrics WHERE event_name='visit' AND day>=? AND day<? GROUP BY day ORDER BY day",[$today->modify('-6 days')->format('Y-m-d'),$end],false);
    $days=null;
    if($daily!==null){$map=array_column($daily,'total','day');$days=[];for($i=6;$i>=0;$i--){$d=$today->modify("-$i days")->format('Y-m-d');$days[]=['day'=>$d,'total'=>(int)($map[$d]??0)];}}
    pdg_answer(200,['period'=>$period,'metrics'=>$metrics,'pending'=>$pending,'orders'=>$orders,'daily'=>$days,'unavailable'=>array_values(array_unique($errors)),'updated_at'=>date(DATE_ATOM)]);
} catch(Throwable $e){pdg_answer(503,['error'=>'service_unavailable']);}
