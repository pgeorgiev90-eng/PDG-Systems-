(() => {
'use strict';
const F=window.PDGFilters,S=window.PDGCatalogSections,$=id=>document.getElementById(id);
const out=$('products'),search=$('search'),brand=$('brand'),category=$('category'),count=$('count'),more=$('more'),main=document.querySelector('main');
const node=(tag,text)=>{const e=document.createElement(tag);if(text)e.textContent=text;return e;};
const keys=new Set(['availability',...F.defs.map(d=>d.key)]),orders=new Set(['recommended','price_asc','price_desc','name','newest','power_asc','power_desc','energy_asc','energy_desc']);
let list=[],scope=[],limit=24,pricesVisible=true,section='',timer,activeDefs=[];
function readState(){const p=new URLSearchParams(location.search);section=p.get('section')||'';const facets={};for(const key of keys){const values=p.getAll('f.'+key).slice(0,20).map(v=>v.slice(0,160));if(values.length)facets[key]=values;}
 const amount=key=>{const v=p.get(key);return v!==null&&v!==''&&Number.isFinite(Number(v))&&Number(v)>=0?String(Number(v)):'';};
 return {q:(p.get('q')||'').slice(0,200),brand:p.get('brand')||'',category:p.get('category')||'',sort:orders.has(p.get('sort'))?p.get('sort'):'recommended',min:amount('min'),max:amount('max'),facets};}
let state=readState();
const controls=document.querySelector('.controls'),layout=node('div');layout.className='catalog-layout';
const aside=node('aside');aside.className='catalog-filter-panel';aside.setAttribute('aria-label','Филтри за продуктите');
const heading=node('h2','Филтри'),clear=node('button','Изчисти всички');clear.type='button';clear.className='filter-clear';aside.append(heading,clear);
const content=node('div');content.className='catalog-content';const toolbar=node('div');toolbar.className='catalog-toolbar';
const open=node('button','Филтри');open.type='button';open.className='filter-open';open.setAttribute('aria-haspopup','dialog');
const sortLabel=node('label','Подреди по');const sort=node('select');sort.id='catalog-sort';sortLabel.htmlFor=sort.id;
const sortOptions=[['recommended','Препоръчано'],['price_asc','Цена: ниска → висока'],['price_desc','Цена: висока → ниска'],['name','Име: А → Я'],['newest','Най-нови'],['power_asc','Мощност: възходящо'],['power_desc','Мощност: низходящо'],['energy_asc','Капацитет: възходящо'],['energy_desc','Капацитет: низходящо']];
for(const [value,label]of sortOptions)sort.add(new Option(label,value));sortLabel.append(sort);search.placeholder='Търси по име, код или описание';toolbar.append(open,search,sortLabel);
const chips=node('div');chips.className='filter-chips';chips.setAttribute('aria-label','Избрани филтри');
const technical=node('div');technical.id='technical-filters';const error=node('p');error.className='filter-note';error.hidden=true;
function selectWrap(input,label){const wrap=node('label',label);wrap.htmlFor=input.id;wrap.append(input);aside.append(wrap);}
selectWrap(category,'Категория');selectWrap(brand,'Производител');
const range=node('fieldset'),legend=node('legend','Цена без ДДС (€)');range.append(legend);const rangeRow=node('div');rangeRow.className='filter-price-range';
const min=node('input'),max=node('input');for(const [input,label,id]of [[min,'От','filter-min'],[max,'До','filter-max']]){input.type='number';input.min='0';input.step='0.01';input.id=id;input.placeholder=label;input.setAttribute('aria-label','Цена '+label.toLocaleLowerCase('bg'));rangeRow.append(input);}range.append(rangeRow);aside.append(range,error,technical);
layout.append(aside,content);controls.before(layout);content.append(toolbar,chips,count,out,more);controls.remove();
const dialog=node('dialog');dialog.className='catalog-filter-dialog';dialog.setAttribute('aria-label','Филтри за продуктите');const close=node('button','Затвори');close.type='button';close.className='filter-close';close.onclick=()=>dialog.close();const mobileBody=node('div'),apply=node('button','Покажи продуктите');apply.type='button';apply.className='filter-apply';apply.onclick=()=>dialog.close();dialog.append(close,mobileBody,apply);document.body.append(dialog);
open.onclick=()=>dialog.showModal();const media=matchMedia('(max-width: 800px)');function mobile(){if(media.matches){mobileBody.append(aside);}else{if(dialog.open)dialog.close();layout.prepend(aside);}}media.addEventListener('change',mobile);mobile();
function writeURL(){const p=new URLSearchParams(location.search);for(const key of [...p.keys()])if(['q','brand','category','sort','min','max','page'].includes(key)||key.startsWith('f.'))p.delete(key);
 for(const key of ['q','brand','category','min','max'])if(state[key]!=='')p.set(key,state[key]);if(state.sort!=='recommended')p.set('sort',state.sort);for(const [key,values]of Object.entries(state.facets))for(const value of values)p.append('f.'+key,value);
 history.replaceState(null,'',location.pathname+(p.size?'?'+p.toString():''));}
function sync(){search.value=state.q;brand.value=state.brand;category.value=state.category;min.value=state.min;max.value=state.max;sort.value=state.sort;}
function baseOptions(){scope=list.filter(p=>S.matches(p,section));if(state.category&&!scope.some(p=>F.categoryMatches(p,state.category)))state.category='';const inCategory=scope.filter(p=>F.categoryMatches(p,state.category));
 category.replaceChildren(new Option('Всички категории',''));for(const c of [...new Set(scope.flatMap(p=>{const c=F.categoryOf(p);return [c,...(c.startsWith('Инвертори / ')?['Инвертори']:[]),...(['Високоволтови батерии','Нисковолтови батерии'].includes(c)?['Батерии']:[])];}))].sort(F.collator.compare))category.add(new Option(c,c));
 brand.replaceChildren(new Option('Всички производители',''));for(const b of [...new Set(inCategory.map(p=>p.brand))].sort(F.collator.compare))brand.add(new Option(b,b));
 if(state.category&&![...category.options].some(o=>o.value===state.category))state.category='';if(state.brand&&![...brand.options].some(o=>o.value===state.brand))state.brand='';
 activeDefs=[{key:'availability',label:'Наличност'},...F.defs].filter(d=>inCategory.some(p=>p.attrs[d.key]));
 const available=new Set(activeDefs.map(d=>d.key));for(const key of Object.keys(state.facets))if(!available.has(key))delete state.facets[key];
 const power=inCategory.some(p=>p.attrs.power||p.attrs.panel_power),energy=inCategory.some(p=>p.attrs.energy);for(const o of sort.options){o.hidden=o.value.startsWith('power_')?!power:o.value.startsWith('energy_')?!energy:false;}if((state.sort.startsWith('power_')&&!power)||(state.sort.startsWith('energy_')&&!energy))state.sort='recommended';sync();
}
function facets(){const focused=document.activeElement?.id;technical.replaceChildren();
 const pool=scope.filter(p=>F.categoryMatches(p,state.category));
 for(const def of activeDefs){const values=[...new Set(pool.map(p=>p.attrs[def.key]).filter(Boolean).concat(state.facets[def.key]||[]))].sort(F.collator.compare);if(values.length<2&&!(state.facets[def.key]||[]).length)continue;
  const counts={};for(const p of scope)if(F.matches(p,state,def.key)&&p.attrs[def.key])counts[p.attrs[def.key]]=(counts[p.attrs[def.key]]||0)+1;
  const details=node('details');details.open=['power','panel_power','energy','phases','availability','current','roof'].includes(def.key)||(state.facets[def.key]||[]).length>0;details.append(node('summary',def.label));
  const choices=node('div');choices.className='filter-values';
  values.forEach((value,i)=>{const label=node('label'),input=node('input');input.type='checkbox';input.id='facet-'+def.key+'-'+i;input.checked=(state.facets[def.key]||[]).includes(value);input.disabled=!counts[value]&&!input.checked;const caption=node('span',value),number=node('small',String(counts[value]||0));label.append(input,caption,number);input.onchange=()=>{const chosen=new Set(state.facets[def.key]||[]);input.checked?chosen.add(value):chosen.delete(value);state.facets[def.key]=[...chosen];changed();};choices.append(label);});details.append(choices);technical.append(details);
 }
 if(focused&&$(focused))$(focused).focus({preventScroll:true});
}
function selected(){chips.replaceChildren();const add=(label,remove)=>{const b=node('button',label+' ×');b.type='button';b.setAttribute('aria-label','Премахни филтър '+label);b.onclick=()=>{remove();changed(true);};chips.append(b);};
 if(state.brand)add(state.brand,()=>state.brand='');if(state.category)add(state.category,()=>state.category='');if(state.min!=='')add('От '+state.min+' €',()=>state.min='');if(state.max!=='')add('До '+state.max+' €',()=>state.max='');
 for(const [key,values]of Object.entries(state.facets))for(const value of values)add((activeDefs.find(d=>d.key===key)?.label||key)+': '+value,()=>{state.facets[key]=state.facets[key].filter(v=>v!==value);});
 const n=chips.children.length;open.textContent='Филтри'+(n?' ('+n+')':'');clear.disabled=n===0&&!state.q;
}
function render(){const items=F.sort(scope.filter(p=>F.matches(p,state)),state.sort,S.sectionOf,Object.keys(S.sections));out.replaceChildren();let previous='';for(const p of items.slice(0,limit)){
 if(state.sort==='recommended'&&!S.sections[section]&&!state.category){const group=S.sectionOf(p);if(group!==previous){const h=node('h2',S.sections[group]||'Други продукти');h.className='catalog-group-title';out.append(h);previous=group;}}
 out.append(window.PDGCatalogCard(p,pricesVisible));
 }count.textContent=items.length?items.length+' намерени продукта · показани '+Math.min(limit,items.length):'Няма продукти по избраните условия. Премахнете филтър или променете търсенето.';more.hidden=limit>=items.length;apply.textContent='Покажи '+items.length+' продукта';facets();selected();}
function changed(options=false){limit=24;if(options)baseOptions();sync();writeURL();render();}
clear.onclick=()=>{state={...state,q:'',brand:'',category:'',min:'',max:'',facets:{}};changed(true);};
search.addEventListener('input',()=>{state.q=search.value.slice(0,200);clearTimeout(timer);timer=setTimeout(()=>changed(),180);});
brand.addEventListener('change',()=>{state.brand=brand.value;changed();});category.addEventListener('change',()=>{state.category=category.value;state.facets={};changed(true);});sort.onchange=()=>{state.sort=sort.value;changed();};
for(const [input,key]of [[min,'min'],[max,'max']])input.oninput=()=>{if(!input.validity.valid)return;state[key]=input.value;clearTimeout(timer);timer=setTimeout(()=>changed(),220);};
more.onclick=()=>{limit+=24;render();};window.addEventListener('popstate',()=>{state=readState();baseOptions();render();});
function sectionLinks(){const nav=$('catalog-sections');nav.replaceChildren();const totals={};for(const p of list){const key=S.sectionOf(p);totals[key]=(totals[key]||0)+1;}for(const [key,label,total]of [['','Всички продукти',list.length],...Object.entries(S.sections).filter(([key])=>totals[key]).map(([key,label])=>[key,label,totals[key]])]){const a=node('a',label+' ('+total+')');a.href=key?'catalog.html?section='+encodeURIComponent(key):'catalog.html';if((!key&&!S.sections[section])||key===section)a.setAttribute('aria-current','page');nav.append(a);}}
(async()=>{count.textContent='Зареждане на продукти и филтри…';more.hidden=true;
 try{
  const results=await Promise.allSettled([fetch('api/products.php',{cache:'no-store'}).then(r=>{if(!r.ok)throw Error();return r.json();}),fetch('api/catalog-facets.php',{cache:'no-store'}).then(r=>{if(!r.ok)throw Error();return r.json();})]);
  if(results[0].status!=='fulfilled'||!Array.isArray(results[0].value.products))throw Error();const data=results[0].value;const metadata=results[1].status==='fulfilled'?results[1].value.items||{}:{};
  pricesVisible=data.prices_visible===true;list=data.products.map(p=>({...p,attrs:F.attributes(p,metadata[p.id]),created_at:metadata[p.id]?.created_at||''}));
  if(results[1].status!=='fulfilled'){error.hidden=false;error.textContent='Техническите филтри временно не са достъпни. Можете да търсите по име, производител и цена.';}
  if(S.sections[section]){document.querySelector('h1').textContent=S.sections[section];document.querySelector('.muted').textContent='Изберете характеристики и сравнете подходящите продукти.';}
  sectionLinks();baseOptions();writeURL();render();
 }catch{out.replaceChildren();count.textContent='Каталогът временно не е достъпен. Презаредете страницата, за да опитате отново.';more.hidden=true;}
})();
})();
