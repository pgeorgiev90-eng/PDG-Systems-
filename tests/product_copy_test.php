<?php
declare(strict_types=1);
require __DIR__.'/../api/product-copy.php';
$items=json_decode(file_get_contents(__DIR__.'/../database/seo-priority-products.json'),true,512,JSON_THROW_ON_ERROR);
if(count($items)!==10)throw new RuntimeException('Expected ten priority products');
$keys=[];foreach($items as $item){$key=$item['brand'].'|'.$item['code'];if(isset($keys[$key]))throw new RuntimeException('Duplicate priority identity');$keys[$key]=true;if(!str_starts_with($item['source'],'https://'))throw new RuntimeException('Missing provenance');if(pdg_priority_description($item)!==$item['description'])throw new RuntimeException('Wrong priority copy');}
$p=['brand'=>'Schrack Technik','code'=>'TEST','name'=>'Тест <b> име','category'=>'Категория'];
if(!str_contains(pdg_product_copy($p),'TEST'))throw new RuntimeException('Missing catalog identity');
if(pdg_product_copy($p,'Описание на администратора')!=='Описание на администратора')throw new RuntimeException('Admin copy overwritten');
echo "PASS: ten exact priority identities, source provenance, factual fallback and preserved administrator descriptions\n";
