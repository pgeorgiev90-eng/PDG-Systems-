<?php
declare(strict_types=1);
require __DIR__.'/../api/social-metadata.php';
require __DIR__.'/../api/seo-render.php';
function seoCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$description='Едно завършено изречение. '.str_repeat('Допълнителни характеристики ',15);
seoCheck(pdg_seo_excerpt($description)==='Едно завършено изречение.','Prefer a complete sentence');
seoCheck(mb_strlen(pdg_seo_excerpt(str_repeat('дълга дума ',40)))<=190,'Bounded excerpt');
$p=['name'=>'Артикул <script>alert(1)</script>','brand'=>'A&B','category'=>'Категория','code'=>'CODE'];
$html=pdg_seo_product(file_get_contents(__DIR__.'/../product.html'),$p,'Описание <опасно>',[['label'=>'Размер','value'=>'10 < 20']],null);
seoCheck(str_contains($html,'<h1 id="name">Артикул &lt;script&gt;'),'Escaped visible H1');
seoCheck(!str_contains($html,'<article id="product" hidden>'),'Product visible without JavaScript');
seoCheck(str_contains($html,'Описание &lt;опасно&gt;'),'Description in initial HTML');
seoCheck(str_contains($html,'<th scope="row">Размер</th><td>10 &lt; 20</td>'),'Specifications rendered safely');
seoCheck(str_contains($html,'Цена след регистрация'),'No public price');
seoCheck(pdg_seo_image(['image_url'=>'assets/test.webp'])==='https://pdgsystems.bg/assets/test.webp','Relative images normalized');
seoCheck(pdg_seo_image(['image_url'=>'javascript:alert(1)'])===null,'Unsafe image rejected');
echo "PASS: visible server content, escaping, private prices, excerpts and image URLs\n";
