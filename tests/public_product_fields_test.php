<?php
declare(strict_types=1);
require __DIR__.'/../api/public-product-fields.php';
$fields=[['label'=>'Voc (V)','value'=>'49.08'],['label'=>'Източник на проверката','value'=>'https://supplier.test/item'],['label'=>'Технически лист','value'=>'https://manufacturer.test/model.pdf'],['label'=>'Брой MPPT','value'=>'3'],['label'=>'CAD: проверка на инвертора','value'=>'проверен'],['label'=>'Максимален ток на късо съединение на MPPT','value'=>'50+50+50'],['label'=>'Наличност при доставчика','value'=>'В наличност'],['label'=>'Вид връзка','value'=>'Релсова връзка']];
$public=pdg_public_product_fields($fields);
if(count($public)!==5||$public[0]['value']!=='49.08')throw new RuntimeException('Technical fields or provenance filtering failed');
if(str_contains(pdg_public_product_text('Техническо описание https://supplier.test/item'),'https://'))throw new RuntimeException('Description URL not removed');
echo "PASS: supplier and source links removed while voltage, MPPT, currents, validation status and physical connector fields remain intact\n";
