<?php
declare(strict_types=1);
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
