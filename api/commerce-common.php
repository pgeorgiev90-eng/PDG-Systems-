<?php
declare(strict_types=1);

// Additive operational data. Unknown stock and costs stay unknown.
function pdg_commerce_schema(PDO $db): void {
    static $ready=[];$key=spl_object_id($db);
    if(isset($ready[$key]))return;
    if($db->inTransaction())throw new RuntimeException('Schema must initialize before a transaction');
    $db->exec("CREATE TABLE IF NOT EXISTS pdg_product_operations (
        product_id BIGINT UNSIGNED PRIMARY KEY,
        cost_cents BIGINT UNSIGNED NULL,
        min_margin_bp SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        stock_quantity INT UNSIGNED NULL,
        lead_days SMALLINT UNSIGNED NULL,
        availability VARCHAR(20) NOT NULL DEFAULT 'confirm',
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS pdg_saved_lists (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        name VARCHAR(120) NOT NULL,
        items_json MEDIUMTEXT NOT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY owner_lists(user_id,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS pdg_partner_profiles (
        user_id BIGINT UNSIGNED PRIMARY KEY,
        is_partner TINYINT NOT NULL DEFAULT 0,
        company VARCHAR(160) NOT NULL DEFAULT '',
        vat_number VARCHAR(40) NOT NULL DEFAULT ''
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS pdg_product_bundles (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(160) NOT NULL,
        description VARCHAR(2000) NOT NULL,
        items_json MEDIUMTEXT NOT NULL,
        compatibility_note VARCHAR(2000) NOT NULL,
        active TINYINT NOT NULL DEFAULT 0,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS pdg_customer_requests (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        kind VARCHAR(20) NOT NULL,
        subject VARCHAR(160) NOT NULL,
        details_json MEDIUMTEXT NOT NULL,
        stage VARCHAR(20) NOT NULL DEFAULT 'new',
        admin_reply VARCHAR(4000) NOT NULL DEFAULT '',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY requests_owner(user_id,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS pdg_request_files (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        request_id BIGINT UNSIGNED NOT NULL,
        stored_name VARCHAR(80) NOT NULL,
        mime VARCHAR(30) NOT NULL,
        bytes INT UNSIGNED NOT NULL,
        KEY request_photos(request_id,id)
    ) ENGINE=InnoDB");
    $db->exec("CREATE TABLE IF NOT EXISTS pdg_order_owners (
        order_id BIGINT UNSIGNED PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        delivery_method VARCHAR(20) NOT NULL DEFAULT 'quote',
        KEY owner_orders(user_id,order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS pdg_order_stock (
        order_id BIGINT UNSIGNED NOT NULL,
        product_id BIGINT UNSIGNED NOT NULL,
        quantity INT UNSIGNED NOT NULL,
        applied TINYINT NOT NULL DEFAULT 1,
        PRIMARY KEY(order_id,product_id)
    ) ENGINE=InnoDB");
    $db->exec("CREATE TABLE IF NOT EXISTS pdg_order_costs (
        order_id BIGINT UNSIGNED NOT NULL,
        product_id BIGINT UNSIGNED NOT NULL,
        cost_cents BIGINT UNSIGNED NULL,
        PRIMARY KEY(order_id,product_id)
    ) ENGINE=InnoDB");
    $db->exec("CREATE TABLE IF NOT EXISTS pdg_enquiry_costs (
        enquiry_id BIGINT UNSIGNED PRIMARY KEY,
        cost_cents BIGINT UNSIGNED NULL
    ) ENGINE=InnoDB");
    $db->exec("CREATE TABLE IF NOT EXISTS pdg_development_issues (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(200) NOT NULL,
        priority VARCHAR(10) NOT NULL,
        stage VARCHAR(20) NOT NULL DEFAULT 'new',
        verification VARCHAR(2000) NOT NULL DEFAULT '',
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $ready[$key]=true;
}

function pdg_adjust_order_stock(PDO $db, int $orderId, bool $apply): void {
    $q=$db->prepare('SELECT product_id,quantity,applied FROM pdg_order_stock WHERE order_id=? ORDER BY product_id FOR UPDATE');$q->execute([$orderId]);
    foreach($q->fetchAll() as $row){
        if((bool)$row['applied']===$apply)continue;
        $lock=$db->prepare('SELECT stock_quantity FROM pdg_product_operations WHERE product_id=? FOR UPDATE');$lock->execute([$row['product_id']]);$op=$lock->fetch();
        if(!$op||$op['stock_quantity']===null)throw new InvalidArgumentException('inventory_configuration_changed');
        if($op['stock_quantity']!==null){
            if($apply && (int)$op['stock_quantity']<(int)$row['quantity'])throw new InvalidArgumentException('stock_unavailable');
            $update=$db->prepare('UPDATE pdg_product_operations SET stock_quantity=stock_quantity+? WHERE product_id=?');$update->execute([($apply?-1:1)*(int)$row['quantity'],$row['product_id']]);
        }
        $update=$db->prepare('UPDATE pdg_order_stock SET applied=? WHERE order_id=? AND product_id=?');$update->execute([(int)$apply,$orderId,$row['product_id']]);
    }
}

function pdg_operations(PDO $db, int $id): array {
    $q=$db->prepare('SELECT cost_cents,min_margin_bp,stock_quantity,lead_days,availability FROM pdg_product_operations WHERE product_id=?');
    $q->execute([$id]);
    return $q->fetch() ?: ['cost_cents'=>null,'min_margin_bp'=>0,'stock_quantity'=>null,'lead_days'=>null,'availability'=>'confirm'];
}

function pdg_public_operations(array $data): array {
    return ['stock_quantity'=>$data['stock_quantity']===null?null:(int)$data['stock_quantity'],
        'lead_days'=>$data['lead_days']===null?null:(int)$data['lead_days'],'availability'=>$data['availability']];
}

function pdg_sale_allowed(?int $unit, int $qty, array $op): bool {
    if ($unit===null || $op['availability']==='unavailable') return false;
    if ($op['stock_quantity']!==null && $qty>(int)$op['stock_quantity']) return false;
    if ($op['cost_cents']!==null) {
        // Margin = (sale - cost) / sale, evaluated in integer eurocents.
        if ($unit<=0 || $unit*(10000-(int)$op['min_margin_bp']) < (int)$op['cost_cents']*10000) return false;
    }
    return true;
}

function pdg_clean_items($raw): array {
    if (!is_array($raw) || !$raw || count($raw)>50) throw new InvalidArgumentException('invalid_items');
    $result=[];
    foreach ($raw as $item) {
        if (!is_array($item) || !is_int($item['id']??null) || $item['id']<1
            || !is_int($item['quantity']??null) || $item['quantity']<1 || $item['quantity']>999) throw new InvalidArgumentException('invalid_items');
        $id=$item['id'];$result[$id]=($result[$id]??0)+$item['quantity'];
        if ($result[$id]>999) throw new InvalidArgumentException('invalid_items');
    }
    return array_map(fn($id,$qty)=>['id'=>$id,'quantity'=>$qty],array_keys($result),array_values($result));
}

function pdg_batch_preview(PDO $db, array $items, array $account): array {
    $q=$db->prepare('SELECT id,brand,code,name,price FROM products WHERE id=? AND active=1');
    $lines=[];$total=0;$complete=true;
    foreach ($items as $item) {
        $q->execute([$item['id']]);$p=$q->fetch();$unit=$p?shop_cents($p['price']):null;
        $unit=$unit===null?null:pdg_discounted_cents($unit,$account['discount_bp']);
        $op=pdg_operations($db,$item['id']);$allowed=$p&&pdg_sale_allowed($unit,$item['quantity'],$op);
        $lines[]=['id'=>$item['id'],'code'=>$p['code']??'','name'=>$p['name']??'Липсващ продукт','quantity'=>$item['quantity'],
            'unit_cents'=>$unit,'line_cents'=>$unit===null?null:$unit*$item['quantity'],'available'=>(bool)$allowed,
            'availability'=>pdg_public_operations($op),'problem'=>$allowed?'':'Необходима е проверка на цена, наличност или условията за продажба.'];
        $complete=$complete&&$allowed;$total+=($unit??0)*$item['quantity'];
    }
    return ['items'=>$lines,'can_add'=>$complete,'subtotal_cents'=>$total,'vat_cents'=>intdiv($total*20+50,100),
        'total_cents'=>$total+intdiv($total*20+50,100),'currency'=>'EUR'];
}
