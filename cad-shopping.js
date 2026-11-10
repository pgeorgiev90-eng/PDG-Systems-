(()=>{
  'use strict';
  const core=window.PDGShoppingCore;
  async function transfer(){
    const button=this;button.disabled=true;
    try{const [s,c]=await Promise.all([fetch('api/account-draft.php?action=session').then(r=>r.json()),fetch('api/products.php').then(r=>r.json())]);if(!s.user){location.href='auth.html?next='+encodeURIComponent(location.pathname);return;}
      let bom;
      if(window.PDGSolarEditor){await window.PDGSolarMounting?.refreshCatalog?.();bom=core.solar(window.PDGSolarEditor.project(),window.PDGSolarMounting?.result());}
      else bom=core.electric(window.PDGCad.project(),c.products||[]);
      if(!bom.items.length)throw Error('Няма еднозначно свързани артикули. Изберете каталожни компоненти в проекта.');
      bom.unresolved.push('Трудът, доставката и допълнителните ръчно зададени материали не се добавят като продукти.');
      sessionStorage.setItem('pdg-tools-bom-'+s.user.id,JSON.stringify(bom));location.href='shopping-tools.html#quick-order';
    }catch(e){alert(e.message||'Списъкът не може да се подготви.');}finally{button.disabled=false;}
  }
  const button=document.createElement('button');button.type='button';button.textContent=window.PDGSolarEditor?'Добави материалите в количката':'Добави проекта в количката';button.className='btn primary';button.onclick=transfer;
  const target=document.querySelector('.studio-project-nav')||document.querySelector('.toolbar');if(target)target.append(button);
  if(window.PDGSolarEditor){
    const mobileButton=button.cloneNode(true);mobileButton.id='solar-mobile-cart';mobileButton.onclick=transfer;
    const mobileTarget=document.querySelector('.studio-ribbon');
    if(mobileTarget)mobileTarget.prepend(mobileButton);
    const style=document.createElement('style');style.textContent='#solar-mobile-cart{display:none}body.mobile-cad #solar-mobile-cart{display:block;width:auto;min-height:44px;margin:0;padding:10px 12px;white-space:nowrap;flex-shrink:0;font-weight:700;background:#1046ef;color:#fff;border:1px solid #1046ef;border-radius:7px}@media(max-width:760px){#solar-mobile-cart{display:block;width:auto;min-height:44px;margin:0;padding:10px 12px;white-space:nowrap;flex-shrink:0;font-weight:700;background:#1046ef;color:#fff;border:1px solid #1046ef;border-radius:7px}}';document.head.append(style);
  }
  const template=new URLSearchParams(location.search).get('template');
  if(window.PDGCad&&['din','empty'].includes(template)&&!new URLSearchParams(location.search).has('project'))window.PDGCad.restore({version:2,project:template==='din'?'Шаблон · DIN табло':'Шаблон · празен шкаф',rows:3,modules:18,mode:template==='din'?'din':'free',cabinet:{sku:'',widthMm:600,heightMm:800,depthMm:260},items:[],busbars:[],connections:[]});
})();
