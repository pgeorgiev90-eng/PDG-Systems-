(async()=>{
  'use strict';
  const $=id=>document.getElementById(id),id=Number(new URLSearchParams(location.search).get('product_id'));
  const node=(tag,text)=>{const e=document.createElement(tag);if(text)e.textContent=text;return e;};let products=[],csrf='',version='',links=[];
  async function req(data){const r=await fetch('api/admin-related-products.php'+(data?'':'?product_id='+id),data?{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(data)}:{cache:'no-store'});const d=await r.json();if(!r.ok)throw Error(d.error);return d;}
  function render(){
    $('links').replaceChildren();links.forEach((link,index)=>{
      const row=node('div');row.className='link-row';const search=node('input');search.type='search';search.placeholder='Търси по име или код';search.setAttribute('aria-label','Търси допълнение '+(index+1));
      const select=node('select');select.required=true;select.setAttribute('aria-label','Допълнение '+(index+1));
      function options(){select.replaceChildren(new Option('Избери продукт',''));const query=search.value.toLocaleLowerCase('bg');for(const p of products.filter(p=>Number(p.id)!==id&&(Number(p.id)===link.id||[p.name,p.code,p.brand].join(' ').toLocaleLowerCase('bg').includes(query))).sort((a,b)=>(Number(b.id)===link.id)-(Number(a.id)===link.id)).slice(0,200)){select.add(new Option(p.name+' · '+p.code,p.id));}select.value=link.id||'';}
      search.oninput=options;select.onchange=()=>{link.id=Number(select.value);$('verified').checked=false;};options();
      const note=node('input');note.maxLength=200;note.required=true;note.value=link.note;note.placeholder='Защо е подходящо — модел, размер или приложение';note.setAttribute('aria-label','Бележка за допълнение '+(index+1));note.oninput=()=>{link.note=note.value;$('verified').checked=false;};
      const remove=node('button','Премахни');remove.type='button';remove.onclick=()=>{links.splice(index,1);$('verified').checked=false;render();};row.append(search,select,note,remove);$('links').append(row);
    });$('add-link').disabled=links.length>=6;
  }
  $('add-link').onclick=()=>{links.push({id:0,note:''});$('verified').checked=false;render();};
  $('related-form').onsubmit=async e=>{e.preventDefault();$('save-links').disabled=true;try{const d=await req({product_id:id,links,version,verified:$('verified').checked});version=d.version;$('status').textContent='Допълненията са запазени.';}catch(e){$('status').textContent=e.message==='changed_refresh'?'Друг администратор е променил списъка. Презаредете страницата.':'Не е записано. Проверете за повторени или неактивни продукти и опитайте отново.';}finally{$('save-links').disabled=false;}};
  try{const r=await fetch('api/account-draft.php?action=session',{cache:'no-store'});const s=await r.json();if(s.user?.role!=='admin')throw Error();csrf=s.csrf;const d=await req();products=d.products;version=d.version;links=d.links.map(p=>({id:Number(p.id),note:p.note}));$('current').textContent=d.product.name+' · '+d.product.code;$('preview').href='product.html?id='+id;$('related-form').hidden=false;$('status').textContent='Показват се само активни продукти.';render();}catch{$('status').textContent='Необходим е администраторски достъп или валиден продукт.';}
})();
