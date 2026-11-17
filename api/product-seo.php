<?php
declare(strict_types=1);
function pdg_product_seo_title(string $name): string {
    return $name.' – цена и характеристики | PDG Systems';
}
function pdg_product_seo_offer(array $product, string $url, array $operations=[]): ?array {
    $price=$product['price']??null;
    if(!is_numeric($price)||!is_finite((float)$price)||(float)$price<=0)return null;
    // Public standard EUR price, including the same 20% VAT shown on the page.
    $offer=['@type'=>'Offer','url'=>$url,'priceCurrency'=>'EUR',
        'price'=>number_format(round((float)$price*1.2,2),2,'.',''),
        'itemCondition'=>'https://schema.org/NewCondition'];
    $availability=['in_stock'=>'InStock','on_order'=>'BackOrder','unavailable'=>'OutOfStock'];
    $state=$operations['availability']??'confirm';
    if(isset($availability[$state])) {
        if($state==='in_stock'&&isset($operations['stock_quantity'])&&(int)$operations['stock_quantity']===0)$state='unavailable';
        $offer['availability']='https://schema.org/'.$availability[$state];
    }
    return $offer;
}
