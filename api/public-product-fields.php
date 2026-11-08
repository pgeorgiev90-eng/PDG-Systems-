<?php
declare(strict_types=1);
// Keep import provenance server-side; expose only customer-facing product facts.
function pdg_public_product_text(string $value):string {
 $value=preg_replace('~https?://[^\s<>"\x27]+~iu','',$value);
 return trim(preg_replace('/[ \t]{2,}/u',' ',$value));
}
function pdg_public_product_fields(array $fields):array {
 return array_values(array_filter($fields,static function(array $f):bool {
  $label=mb_strtolower(trim((string)($f['label']??'')),'UTF-8');$value=(string)($f['value']??'');
  if(preg_match('~https?://~iu',$value))return false;
  return !preg_match('/източник|доставчик|технически лист|datasheet|страница на производителя|официален сайт|каталожен източник|артикулен номер kamea|^source|^url$|^link$/u',$label);
 }));
}
