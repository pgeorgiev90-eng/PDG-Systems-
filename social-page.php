<?php
declare(strict_types=1);
require_once __DIR__.'/api/social-metadata.php';
require_once __DIR__.'/api/product-copy.php';
require_once __DIR__.'/api/public-product-fields.php';
require_once __DIR__.'/api/seo-render.php';
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-cache');
$base = 'https://pdgsystems.bg/';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$productPage = $path === '/product.html';
$catalogPage = $path === '/catalog.html';
if (!$productPage && !$catalogPage) { http_response_code(404); exit('Page not found'); }
$template = file_get_contents(__DIR__.($productPage ? '/product.html' : '/catalog.html'));
$data = [
    'title' => 'Продуктов каталог | PDG Systems',
    'description' => 'Инвертори, батерии, конструкции и електроапаратура. Технически характеристики и оборудване от PDG Systems.',
    'url' => $base.'catalog.html'
];
$publicContent='';$structured=null;
try {
    if ($productPage) {
        $rawId = $_GET['id'] ?? null;
        $id = is_string($rawId) && preg_match('/^[1-9][0-9]*$/D', $rawId)
            ? filter_var($rawId, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) : false;
        $p = false;
        if ($id !== false) {
            $db = pdg_social_db();
            $q = $db->prepare('SELECT id,brand,category,code,name,price,image_url,image_index FROM products WHERE id=? AND active=1');
            $q->execute([$id]);
            $p = $q->fetch();
        }
        if (!$p) {
            http_response_code(404);
            header('X-Robots-Tag: noindex');
            $data['title'] = 'Продуктът не е намерен | PDG Systems';
        } else {
            $fields=[]; $full='';
            $description = trim($p['brand'].' · '.$p['category'].' · Код: '.$p['code'].'. Технически характеристики и запитване към PDG Systems.');
            try {
                $q=$db->prepare('SELECT label,value FROM pdg_product_fields WHERE product_id=? ORDER BY sort_order,id');
                $q->execute([$id]);
                $allFields=$q->fetchAll();
                foreach($allFields as $f)if(mb_strtolower(trim($f['label']),'UTF-8')==='описание')$full=trim(strip_tags($f['value']));
                $fields=pdg_public_product_fields(array_values(array_filter($allFields,static fn($f)=>!in_array(mb_strtolower(trim($f['label']),'UTF-8'),['описание','бележка за снимката','източник за фазност','източник на фазност','phase source','source of phase','source of phases'],true))));
                if($full!=='')$description=$full;
            } catch (PDOException $e) {
                if ($e->getCode() !== '42S02') throw $e;
            }
            $full=pdg_product_copy($p,pdg_public_product_text($full?:$description));
            $description=pdg_seo_excerpt(pdg_priority_description($p)??$full);
            $image=pdg_seo_image($p);
            $template=pdg_seo_product($template,$p,$full,$fields,$image);
            $data = [
                'title' => $p['name'].' | PDG Systems',
                'description' => $description,
                'url' => $base.'product.html?id='.$p['id'],
                'image' => $image ?: $base.'assets/pdg-social.jpg',
                'alt' => $p['brand'].' '.$p['name'],
                'type' => 'product'
            ];
            $structured=['@context'=>'https://schema.org','@type'=>'Product','name'=>$p['name'],'sku'=>$p['code'],
                'brand'=>['@type'=>'Brand','name'=>$p['brand']],'description'=>$full,'url'=>$data['url']];
            if($image)$structured['image']=$image;
            $related=$db->prepare('SELECT id,name FROM products WHERE active=1 AND category=? AND id<>? ORDER BY id LIMIT 12');$related->execute([$p['category'],$p['id']]);
            $publicContent='<section id="pdg-category-fallback" class="tools-panel"><h2>Още от тази категория</h2><ul>';
            foreach($related->fetchAll() as $r)$publicContent.='<li><a href="product.html?id='.(int)$r['id'].'">'.pdg_social_escape($r['name']).'</a></li>';
            $publicContent.='</ul><p><a href="catalog.html?category='.rawurlencode($p['category']).'">Всички артикули от категорията</a> · <a href="shopping-tools.html#compare">Сравни продукти</a></p></section>';
        }
    } else {
        $sections = [
            'inverters'=>'Инвертори', 'batteries'=>'Батерии', 'mounting'=>'Конструкции и крепежи',
            'bms'=>'BMS модули', 'battery_accessories'=>'Аксесоари за батерии', 'storage'=>'Системи за съхранение',
            'chargers'=>'Зарядни станции', 'energy_management'=>'Енергийно управление', 'system_cabinets'=>'Системни шкафове',
            'panels'=>'Фотоволтаични панели', 'electrical'=>'Електроапаратура', 'protection'=>'Защити и апаратура',
            'enclosures'=>'Табла и кутии', 'accessories'=>'Кабели и аксесоари'
        ];
        $params = []; $label = '';
        $section = $_GET['section'] ?? '';
        if(!is_string($section))$section='';
        if (is_string($section) && isset($sections[$section])) {
            $params['section'] = $section;
            $label = $sections[$section];
        }
        foreach (['brand','category'] as $key) {
            $value = $_GET[$key] ?? '';
            if (!is_string($value) || $value === '' || mb_strlen($value, 'UTF-8') > 300) continue;
            $db = $db ?? pdg_social_db();
            $q = $db->prepare('SELECT 1 FROM products WHERE active=1 AND '.$key.'=? LIMIT 1');
            $q->execute([$value]);
            if ($q->fetchColumn()) { $params[$key] = $value; $label = $value; }
        }
        if ($label !== '') {
            $data['title'] = $label.' | PDG Systems';
            $data['description'] = $label.' — продуктов каталог на PDG Systems. Разгледайте оборудването, техническите характеристики и снимките и изпратете запитване.';
            $data['url'] = $base.'catalog.html?'.http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        }
        // Crawlable category navigation and bounded product directory, independent of JS.
        $db = $db ?? pdg_social_db();
        $directory = '<section class="tools-panel" aria-label="Каталожен указател"><h2>Каталожен указател</h2><p>Категории с директни връзки към продуктите:</p><ul>';
        $categories = $db->query('SELECT DISTINCT category FROM products WHERE active=1 ORDER BY category')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($categories as $categoryName) {
            $directory .= '<li><a href="catalog.html?category='.rawurlencode($categoryName).'">'.pdg_social_escape($categoryName).'</a></li>';
        }
        $directory .= '</ul>';
        // Section pages lead to the category directory; exact category/brand pages list products.
        if (isset($params['category']) || isset($params['brand']) || !isset($params['section'])) {
            $where = ['active=1']; $values = [];
            foreach (['brand','category'] as $filter) if (isset($params[$filter])) {
                $where[] = $filter.'=?'; $values[] = $params[$filter];
            }
            $rawPage = $_GET['page'] ?? '1';
            $page = is_string($rawPage) && preg_match('/^[1-9][0-9]{0,4}$/D', $rawPage) ? (int)$rawPage : 1;
            $counter = $db->prepare('SELECT COUNT(*) FROM products WHERE '.implode(' AND ', $where));
            $counter->execute($values); $total = (int)$counter->fetchColumn();
            $pages = max(1, (int)ceil($total/60)); $page = min($page, $pages);
            $query = $db->prepare('SELECT id,name FROM products WHERE '.implode(' AND ', $where).' ORDER BY id LIMIT 60 OFFSET '.(($page-1)*60));
            $query->execute($values);
            $directory .= '<h3>Продукти'.(isset($params['category']) ? ': '.pdg_social_escape($params['category']) : '').'</h3><ul>';
            foreach ($query->fetchAll() as $item) $directory .= '<li><a href="product.html?id='.(int)$item['id'].'">'.pdg_social_escape($item['name']).'</a></li>';
            $directory .= '</ul><nav aria-label="Страници на указателя">';
            foreach ([$page-1=>'Предишна страница', $page+1=>'Следваща страница'] as $target=>$caption) if ($target>=1 && $target<=$pages) {
                $url = 'catalog.html?'.http_build_query(array_merge($params,['page'=>$target]), '', '&', PHP_QUERY_RFC3986);
                $directory .= '<a href="'.pdg_social_escape($url).'">'.$caption.'</a> ';
            }
            $directory .= '</nav><p>Страница '.$page.' от '.$pages.'</p>';
            if ($page>1) { $data['url'] .= (str_contains($data['url'],'?') ? '&' : '?').'page='.$page; $data['title'] .= ' — страница '.$page; }
        }
        $directory .= '</section>';
        if($label!=='')$template=preg_replace_callback('~(<h1[^>]*>).*?(</h1>)~s',static fn($m)=>$m[1].pdg_social_escape($label).$m[2],$template,1);
        $intro=[
            'inverters'=>'Сравнете инверторите по фазност, мощност, MPPT входове и допустимо DC напрежение. За система с батерия проверете съвместимостта на конкретните модели.',
            'batteries'=>'Системите за съхранение се избират по работно напрежение, енергия, допустим ток и съвместим BMS протокол. Капацитетът сам по себе си не потвърждава съвместимост.',
            'panels'=>'Подберете фотоволтаичните панели според мощност, размери, Voc и температурен коефициент. В PDG Solar CAD използвайте характеристиките на точния модел.',
            'electrical'=>'Изберете апаратура и корпуси по номинални параметри, прекъсвателна способност, размери и условия на монтаж. Разположението може да се подготви с PDG Electric CAD.',
            'mounting'=>'Изберете крепежите според вида покрив, монтажната система и конкретните размери. Еднакъв код може да има различни варианти; проверете наименованието на артикула.'
        ];
        $text=$intro[$section]??'Разгледайте артикулите по производител, каталожен код и характеристики. Наличностите и сроковете без въведени данни се потвърждават преди поръчка.';
        $publicContent=$directory.'<section class="tools-panel"><h2>'.pdg_social_escape($label?:'Избор на оборудване').'</h2><p>'.pdg_social_escape($text).'</p><p><a href="shopping-tools.html#compare">Сравни продукти</a> · <a href="shopping-tools.html#quick-order">Бърза поръчка по кодове</a> · <a href="electrical-panels.html">Табла по поръчка</a> · <a href="photovoltaic-systems.html">Фотоволтаични системи</a></p></section>';
    }
} catch (Throwable $e) {
    http_response_code(503);
    header('Retry-After: 300');
    header('X-Robots-Tag: noindex');
    $data['title'] = 'Каталогът временно е недостъпен | PDG Systems';
}
$html=pdg_social_render($template, $data);
if($structured)$html=str_replace('</head>','<script type="application/ld+json">'.json_encode($structured,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).'</script></head>',$html);
if($publicContent)$html=str_replace('</main>',$publicContent.'</main>',$html);
echo $html;
