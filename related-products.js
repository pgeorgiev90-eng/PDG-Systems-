(() => {
  'use strict';
  const id=new URLSearchParams(location.search).get('id');if(!id||!/^\d+$/.test(id))return;
  const node=(tag,text)=>{const e=document.createElement(tag);if(text)e.textContent=text;return e;};
  const money=p=>Number(p).toLocaleString('bg-BG',{style:'currency',currency:'EUR'});
  function card(p){
    const item=node('article');item.className='related-card';
    const link=node('a');link.href='product.html?id='+encodeURIComponent(p.id);link.className='related-main';
    let imageUrl=p.imageUrl;
    const index=Number(p.imageIndex);
    if(!imageUrl&&p.imageIndex!==null&&Number.isInteger(index)&&index>=0&&index<130)imageUrl='assets/enerack-product-'+index+'.webp';
    let safe=false;try{const u=new URL(imageUrl,location.href);safe=Boolean(imageUrl)&&['http:','https:'].includes(u.protocol);}catch{}
    if(safe){const img=node('img');img.src=imageUrl;img.alt=p.name;img.loading='lazy';img.referrerPolicy='no-referrer';img.onerror=()=>{const fallback=node('div',p.brand);fallback.className='related-image-placeholder';img.replaceWith(fallback);};link.append(img);}else{const fallback=node('div',p.brand);fallback.className='related-image-placeholder';link.append(fallback);}
    link.append(node('small',p.brand+' · '+p.code),node('h3',p.name));item.append(link);
    if(p.note)item.append(node('p',p.note));
    const price=node('p',p.price?money(p.price)+' без ДДС':'Цена по запитване');price.className='related-price';item.append(price);
    if(p.price){const vat=node('small',money(Math.round(Number(p.price)*100)*1.2/100)+' с ДДС');item.append(vat);}
    const action=node('a','Разгледай продукта');action.href=link.href;action.className='related-action';item.append(action);return item;
  }
  async function load(){try{
    const r=await fetch('api/related-products.php?id='+encodeURIComponent(id),{cache:'no-store'});if(!r.ok)return;const data=await r.json();
    const root=node('div');root.id='related-products';
    for(const [key,title,description]of [['complements','Подходящи допълнения','Подбрани от PDG Systems за този продукт.'],['similar','Разгледайте още','Сходни артикули от същата категория. Сравнете характеристиките преди избор.']]){
      if(!Array.isArray(data[key])||!data[key].length)continue;
      const section=node('section');section.className='related-section';const h=node('h2',title);h.id='related-'+key;section.setAttribute('aria-labelledby',h.id);section.append(h,node('p',description));
      const grid=node('div');grid.className='related-grid';grid.tabIndex=0;grid.setAttribute('role','region');grid.setAttribute('aria-label',title);for(const p of data[key].slice(0,6))grid.append(card(p));section.append(grid);root.append(section);
    }
    if(root.children.length){document.querySelector('main').append(root);const fallback=document.getElementById('pdg-category-fallback');if(fallback)fallback.hidden=true;}
  }catch{/* Existing category links remain available. */}}
  load();
})();
