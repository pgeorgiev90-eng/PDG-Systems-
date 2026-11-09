<?php
declare(strict_types=1);
function pdg_priority_description(array $product): ?string {
    static $rows=null;
    if($rows===null){$path=dirname(__DIR__).'/database/seo-priority-products.json';$rows=is_file($path)?json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR):[];}
    foreach($rows as $row)if($row['brand']===($product['brand']??'')&&$row['code']===($product['code']??''))return $row['description'];
    return null;
}
// Only facts already present in the catalog identity are used; no inferred ratings.
function pdg_product_copy(array $product, string $description=''): string {
    if(trim($description)!=='')return $description;
    $name=trim((string)($product['name']??''));$brand=trim((string)($product['brand']??''));$code=trim((string)($product['code']??''));
    if($name==='')return '';
    $category=trim((string)($product['category']??''));
    return $name.' от '.$brand.', каталожен номер '.$code.'. Категория: '.$category.'. '
        .'Изпълнението и параметрите в наименованието се отнасят за този артикул. '
        .'Проверете техническите характеристики за избрания проект; липсващи размери или параметри се уточняват преди поръчка. '
        .'Можете да добавите артикула в списък за материали или да поискате оферта от PDG Systems.';
}
