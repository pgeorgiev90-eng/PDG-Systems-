<?php
declare(strict_types=1);
function pdg_related_schema(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS pdg_product_links (
      product_id BIGINT UNSIGNED NOT NULL, related_id BIGINT UNSIGNED NOT NULL,
      note VARCHAR(500) NOT NULL, sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
      PRIMARY KEY(product_id,related_id), KEY related_target(related_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function pdg_related_links(PDO $db,int $id): array {
    try{$q=$db->prepare('SELECT related_id AS id,note FROM pdg_product_links WHERE product_id=? ORDER BY sort_order,related_id');$q->execute([$id]);return $q->fetchAll();}
    catch(PDOException $e){if($e->getCode()!=='42S02')throw $e;return [];}
}
function pdg_related_version(array $links): string {return hash('sha256',json_encode($links,JSON_UNESCAPED_UNICODE));}
function pdg_related_products(PDO $db,array $p): array {
    $columns='p.id,p.brand,p.category,p.code,p.name,p.price,p.image_url AS imageUrl,p.image_index AS imageIndex';
    $extras=[];
    try{$q=$db->prepare("SELECT $columns,l.note FROM pdg_product_links l JOIN products p ON p.id=l.related_id WHERE l.product_id=? AND p.active=1 AND p.id<>? ORDER BY l.sort_order,l.related_id LIMIT 6");$q->execute([$p['id'],$p['id']]);$extras=$q->fetchAll();}
    catch(PDOException $e){if($e->getCode()!=='42S02')throw $e;}
    $excluded=array_merge([(int)$p['id']],array_map(fn($v)=>(int)$v['id'],$extras));
    $marks=implode(',',array_fill(0,count($excluded),'?'));
    $q=$db->prepare("SELECT $columns FROM products p WHERE p.active=1 AND p.category=? AND p.id NOT IN ($marks) ORDER BY (p.brand=?) DESC,(p.price IS NULL OR p.price<=0),ABS(COALESCE(p.price,0)-?),p.id LIMIT 6");
    $q->execute(array_merge([$p['category']],$excluded,[$p['brand'],(float)($p['price']??0)]));
    return ['complements'=>$extras,'similar'=>$q->fetchAll()];
}
