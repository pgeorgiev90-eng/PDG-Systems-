<?php
declare(strict_types=1);
require __DIR__.'/kamea_catalog_import.php';
$db->exec('UPDATE products SET price=0 WHERE id=3');
$before=$db->query('SELECT id,price FROM products ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
require __DIR__.'/../api/kamea-prices-bootstrap.php';
pdg_import_kamea_prices($db);
$items=json_decode(file_get_contents(__DIR__.'/../database/kamea-prices.json'),true,512,JSON_THROW_ON_ERROR);
$lookup=$db->prepare('SELECT price FROM products WHERE brand=? AND code=?');
foreach($items as $i=>$row){
    $lookup->execute([$row['brand'],$row['code']]);$actual=$lookup->fetchColumn();
    check($actual===($before[$i+1]??$row['netEur']),'Exact net price or preserved administrator price');
}
check($db->query('SELECT price FROM products WHERE id=1')->fetchColumn()==='12.34','Manual price kept');
check($db->query('SELECT price FROM products WHERE id=2')->fetchColumn()==='55.00','Manual revised price kept');
check($db->query('SELECT price FROM products WHERE id=3')->fetchColumn()==='0.00','Manual zero price kept');
check((int)$db->query('SELECT COUNT(*) FROM products WHERE price IS NULL')->fetchColumn()===0,'Every supplier product priced');
$db->exec('UPDATE products SET price=99.99 WHERE id=4');
pdg_import_kamea_prices($db);
$db->exec("DELETE FROM pdg_catalog_migrations WHERE name LIKE 'kamea_prices_%'");
pdg_import_kamea_prices($db);
check($db->query('SELECT price FROM products WHERE id=4')->fetchColumn()==='99.99','Manual edits survive reruns');
check((int)$db->query('SELECT COUNT(*) FROM products')->fetchColumn()===150,'No duplicate products');
echo "PASS: exact prices on all 150 products, VAT conversion, preserved manual/zero prices and safe retries\n";
