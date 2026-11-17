<?php
require __DIR__.'/../api/product-seo.php';
function expect($ok){if(!$ok)throw new RuntimeException('Product SEO regression');}
expect(pdg_product_seo_title('SolaX')==='SolaX – цена и характеристики | PDG Systems');
foreach([null,'',0,-1,'bad'] as $price)expect(pdg_product_seo_offer(['price'=>$price],'https://example.com')===null);
$o=pdg_product_seo_offer(['price'=>'123.45'],'https://example.com');
expect($o['price']==='148.14'&&$o['priceCurrency']==='EUR'&&!isset($o['availability']));
foreach(['in_stock'=>'InStock','on_order'=>'BackOrder','unavailable'=>'OutOfStock'] as $state=>$schema){
$o=pdg_product_seo_offer(['price'=>100],'https://example.com',['availability'=>$state]);expect($o['availability']==='https://schema.org/'.$schema);}
$o=pdg_product_seo_offer(['price'=>100],'https://example.com',['availability'=>'in_stock','stock_quantity'=>0]);expect($o['availability']==='https://schema.org/OutOfStock');
echo "PASS: titles, public VAT-inclusive prices, missing prices and truthful availability\n";
