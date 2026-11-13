(function(root){
'use strict';
const collator=new Intl.Collator('bg',{numeric:true,sensitivity:'base'});
const norm=v=>String(v??'').normalize('NFKC').toLocaleLowerCase('bg').replace(/\s+/g,' ').trim();
const numeric=v=>{const m=String(v??'').trim().replace(',','.').match(/^([0-9]+(?:\.[0-9]+)?)\s*(?:[a-zа-я²°]+)?$/iu);return m?Number(m[1]):null;};
const defs=[
 ['power','Мощност (kW)',/^(номинална ac мощност \(kw\)|номинална зарядна мощност \(kw\)|системна мощност \(kw\))$/],
 ['panel_power','Мощност на панела (W)',/^(мощност \(wp?\)|номинална мощност \(wp?\)|максимална мощност \(wp?\)|мощност на панела \(w\)|номинална мощност на панела.*)$/],
 ['energy','Капацитет (kWh)',/^(номинална енергия \(kwh\)|капацитет \(kwh\)|енергиен капацитет \(kwh\))$/],
 ['phases','Фазност',/^брой фази$/],['inverter_type','Тип инвертор',/^тип инвертор$/],['mppt','Брой MPPT',/^брой mppt$/],
 ['battery_class','Батерийно напрежение',/^(клас батерийна система|клас батерия|напреженов клас)$/],
 ['technology','Технология на панела',/^(технология|тип клетки|технология на клетките|тип панел)$/],['bifacial','Двулицев панел',/^(двулицев|двулицев панел|бифациален)$/],['frame','Цвят на рамката',/^цвят на рамката$/],
 ['roof','Монтажна система / покрив',/^монтажна система$/],['component','Вид продукт',/^(тип компонент|тип устройство)$/],
 ['length','Дължина (mm)',/^дължина \(mm\)$/],['width','Ширина (mm)',/^(ширина|широчина) \(mm\)$/],['height','Височина (mm)',/^височина \(mm\)$/],['thickness','Дебелина (mm)',/^дебелина \(mm\)$/],['cable_length','Дължина (m)',/^дължина \(m\)$/],['dimensions','Размери',/^размери(?:\s|$)/],
 ['connector','Конектор',/^конектор$/],['cross_section','Сечение (mm²)',/^сечение(?: на кабела)?(?:\s|$)/],['color','Цвят',/^цвят$/],['purpose','Предназначение',/^предназначение$/],
 ['current','Номинален ток (A)',/^номинален ток(?:\s*\(a\))?$/],['poles','Брой полюси',/^брой полюси$/],['curve','Характеристика',/^характеристика(?: на изключване)?$/],['breaking','Изключвателна способност (kA)',/^изключвателна способност(?:\s*\(ka\))?$/],
 ['ip','IP защита',/^степен на защита(?:\s*\(ip\))?$/],['material','Материал',/^материал$/],['mount','Монтаж',/^(място на монтаж|тип монтаж)$/],['series','Серия',/^серия$/],['compatibility','Проверена съвместимост',/^проверена съвместимост$/],['service','Вид дейност',/^вид дейност$/]
].map(([key,label,pattern])=>({key,label,pattern}));
const numberKeys=new Set(['power','panel_power','energy','mppt','length','current','width','height','thickness','cable_length','breaking','cross_section']);
function attributes(p,meta={}){
 const attrs={};
 for(const [label,raw] of Object.entries(meta.specs||{})){
  const match=defs.find(d=>d.pattern.test(norm(label)));if(!match||attrs[match.key])continue;
  let value=String(raw).trim();if(!value||value.length>160||/https?:|неизвест|непотвърден|предстои/iu.test(value))continue;
  if(numberKeys.has(match.key)){const n=numeric(value);if(n===null)continue;value=String(n);}
  if(match.key==='phases'){value=/^(1|монофаз|еднофаз)/iu.test(value)?'Монофазен':/^(3|трифаз)/iu.test(value)?'Трифазен':value;}
  if(match.key==='battery_class'){value=/^(hv|високоволт)/iu.test(value)?'Високоволтова':/^(lv|нисковолт)/iu.test(value)?'Нисковолтова':value;}
  attrs[match.key]=value;
 }
 const name=String(p.name||'');
 const set=(key,value)=>{if(!attrs[key]&&value)attrs[key]=value;};
 // Only explicit units and terms in the product name; do not infer specifications from SKU numbers.
 if(/инвертор|зарядн/iu.test(p.category||'')){const m=name.match(/\b(\d+(?:[.,]\d+)?)\s*kW\b/i);if(m)set('power',String(Number(m[1].replace(',','.'))));}
 if(/панели/iu.test(p.category||'')){const m=name.match(/\b(\d{2,4})\s*W\b/i);if(m)set('panel_power',m[1]);}
 if(/батери/iu.test(p.category||'')){const m=name.match(/\b(\d+(?:[.,]\d+)?)\s*kWh\b/i);if(m)set('energy',String(Number(m[1].replace(',','.'))));}
 if(/електроапаратура|защити/iu.test(p.category||'')){
  let m=name.match(/(?:^|[\s,;])(\d+(?:[.,]\d+)?)\s*A\b/i);if(m)set('current',String(Number(m[1].replace(',','.'))));
  m=name.match(/(?:^|[\s,;])(\d(?:\+N)?)\s*[PР](?=[\s,;/]|$)/iu);if(m)set('poles',m[1]);
  m=name.match(/(?:^|[\s,;])(\d+(?:[.,]\d+)?)\s*kA\b/i);if(m)set('breaking',String(Number(m[1].replace(',','.'))));
  m=name.match(/(?:характеристика\s*|(?:^|[\s,;]))([BCD])\s*(?:\d+\s*A|(?=[,;\s]|$))/i);if(m)set('curve',m[1].toUpperCase());
 }
 attrs.availability=({in_stock:'В наличност',out_of_stock:'Изчерпан',on_order:'По заявка',unavailable:'Не се предлага',confirm:'За потвърждение'})[meta.availability]||'За потвърждение';
 return attrs;
}
// Use shared technical specifications rather than supplier-specific category labels.
function categoryOf(p){
 const c=String(p.category||'').trim(),a=p.attrs||{};
 if(c==='Инвертори')return ({'Хибриден':'Инвертори / Хибридни','Мрежов':'Инвертори / Мрежови','Микроинвертор':'Инвертори / Микроинвертори'})[a.inverter_type]||c;
 if(c==='Батерии')return ({'Високоволтова':'Високоволтови батерии','Нисковолтова':'Нисковолтови батерии'})[a.battery_class]||c;
 return c;
}
function categoryMatches(p,category){
 if(!category)return true;
 const c=categoryOf(p);
 if(category==='Батерии')return ['Батерии','Високоволтови батерии','Нисковолтови батерии'].includes(c);
 return c===category||c.startsWith(category+' / ');
}
function matches(p,state,skip){
 const q=norm(state.q);if(q&&!q.split(' ').every(w=>norm([p.name,p.code,p.brand,p.category,p.description].join(' ')).includes(w)))return false;
 if(skip!=='brand'&&state.brand&&p.brand!==state.brand)return false;
 if(!categoryMatches(p,state.category))return false;
 const price=Number(p.price);if(skip!=='price'&&((state.min!==''&&state.min!=null&&(!(price>0)||price<Number(state.min)))||(state.max!==''&&state.max!=null&&(!(price>0)||price>Number(state.max)))))return false;
 for(const [key,values]of Object.entries(state.facets||{})){if(key!==skip&&values.length&&!values.includes(p.attrs?.[key]))return false;}
 return true;
}
function sort(items,order='recommended',sectionOf=()=>'',sectionOrder=[]){
 const priced=(a,b,desc)=>{const x=Number(a.price)>0?Number(a.price):null,y=Number(b.price)>0?Number(b.price):null;return x===null?(y===null?0:1):y===null?-1:(desc?y-x:x-y);};
 const value=(a,b,key,desc=false)=>{const x=numeric(a.attrs?.[key]),y=numeric(b.attrs?.[key]);return x===null?(y===null?0:1):y===null?-1:(desc?y-x:x-y);};
 return items.slice().sort((a,b)=>{
 let n=0;
 if(order==='price_asc'||order==='price_desc')n=priced(a,b,order==='price_desc');
 else if(order==='name')n=collator.compare(a.name,b.name);
 else if(order==='newest')n=String(b.created_at||'').localeCompare(String(a.created_at||''))||Number(b.id)-Number(a.id);
 else if(order==='power_asc'||order==='power_desc')n=value({...a,attrs:{power:a.attrs.power??a.attrs.panel_power}},{...b,attrs:{power:b.attrs.power??b.attrs.panel_power}},'power',order==='power_desc');
 else if(order==='energy_asc'||order==='energy_desc')n=value(a,b,'energy',order==='energy_desc');
 else {const rank=p=>{const i=sectionOrder.indexOf(sectionOf(p));return i<0?999:i;};n=rank(a)-rank(b)||collator.compare(a.category,b.category)||collator.compare(a.brand,b.brand)||value(a,b,'power')||value(a,b,'panel_power')||value(a,b,'energy');}
 return n||collator.compare(a.name,b.name)||Number(a.id)-Number(b.id);
 });
}
const api={defs,attributes,categoryOf,categoryMatches,matches,sort,collator,numeric};if(typeof module==='object'&&module.exports)module.exports=api;else root.PDGFilters=api;
})(typeof window==='object'?window:globalThis);
