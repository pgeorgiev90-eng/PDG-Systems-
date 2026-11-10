<?php
declare(strict_types=1);
function pdg_seo_excerpt(string $text, int $limit=190): string {
    $text=trim(preg_replace('/\s+/u',' ',strip_tags($text)));
    if(mb_strlen($text,'UTF-8')<=$limit)return $text;
    $prefix=mb_substr($text,0,$limit-1,'UTF-8');
    if(preg_match('/^(.+[.!?])(?:\s|$)/u',$prefix,$m))return $m[1];
    $prefix=preg_replace('/\s+\S*$/u','',$prefix);
    return rtrim($prefix,' ,;:—–-').'…';
}
function pdg_seo_image(array $product): ?string {
    $url=trim((string)($product['image_url']??''));
    if(str_starts_with($url,'/assets/'))$url='https://pdgsystems.bg'.$url;
    elseif(str_starts_with($url,'assets/'))$url='https://pdgsystems.bg/'.$url;
    if(filter_var($url,FILTER_VALIDATE_URL)&&str_starts_with($url,'https://'))return $url;
    $index=$product['image_index']??null;
    if($index!==null&&ctype_digit((string)$index)&&(int)$index<130){
        $path='/assets/enerack-product-'.(int)$index.'.webp';
        if(is_file(dirname(__DIR__).$path))return 'https://pdgsystems.bg'.$path;
    }
    return null;
}
function pdg_seo_fill(string $template,string $id,string $content): string {
    return preg_replace_callback('~(<([a-z0-9]+)\b[^>]*\bid="'.preg_quote($id,'~').'"[^>]*>).*?(</\2>)~si',static fn($m)=>$m[1].$content.$m[3],$template,1);
}
function pdg_seo_product(string $template,array $p,string $description,array $fields,?string $image): string {
    $e='pdg_social_escape';
    $template=str_replace('<article id="product" hidden>','<article id="product" data-server-rendered="true">',$template);
    $template=preg_replace('~<p id="status"[^>]*>.*?</p>~s','<p id="status" role="status" hidden></p>',$template,1);
    foreach(['name'=>$p['name'],'category'=>$p['brand'].' · '.$p['category'],'code'=>'Код: '.$p['code'],'price'=>'Цена след регистрация','description'=>$description] as $id=>$text)$template=pdg_seo_fill($template,$id,$e($text));
    if($description!=='')$template=str_replace('<section id="description-section" hidden>','<section id="description-section">',$template);
    if($image)$template=pdg_seo_fill($template,'photo','<img class="product-image" src="'.$e($image).'" alt="'.$e($p['name']).'" referrerpolicy="no-referrer">');
    $rows='';
    foreach(array_merge([['label'=>'Производител','value'=>$p['brand']],['label'=>'Категория','value'=>$p['category']],['label'=>'Код на продукта','value'=>$p['code']]],$fields) as $f)$rows.='<tr><th scope="row">'.$e((string)$f['label']).'</th><td>'.$e((string)$f['value']).'</td></tr>';
    return pdg_seo_fill($template,'specs',$rows);
}
