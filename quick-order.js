(() => {
  'use strict';
  window.PDGQuickOrder = product => {
    if(document.getElementById('quick-order-button'))return;
    const node=(tag,text)=>{const e=document.createElement(tag);if(text)e.textContent=text;return e;};
    const button=node('button','Бърза поръчка');button.type='button';button.id='quick-order-button';
    const hint=node('small','Без регистрация · потвърждение по телефона');hint.style.display='block';
    document.getElementById('buy').append(button,hint);
    const dialog=node('dialog');dialog.className='quick-order-dialog';dialog.setAttribute('aria-labelledby','quick-order-title');
    const close=node('button','Затвори');close.type='button';close.className='quick-close';close.onclick=()=>dialog.close();
    const title=node('h2','Бърза поръчка');title.id='quick-order-title';
    const form=node('form');const summary=node('p');
    const fields={};
    function field(name,label,type,required,max){const wrap=node('label',label);const input=node('input');input.name=name;input.type=type;input.required=required;input.maxLength=max;if(name==='name')input.autocomplete='name';if(name==='phone')input.autocomplete='tel';fields[name]=input;wrap.append(input);form.append(wrap);}
    field('name','Име','text',true,60);field('phone','Телефон','tel',true,40);fields.phone.pattern='[+0-9() .-]{6,40}';
    field('notes','Коментар (незадължително)','text',false,700);
    field('website','Оставете празно','text',false,100);fields.website.parentElement.hidden=true;fields.website.tabIndex=-1;fields.website.autocomplete='off';
    const acknowledge=node('label');acknowledge.className='quick-ack';const check=node('input');check.type='checkbox';check.required=true;
    acknowledge.append(check,document.createTextNode('Желая да се свържете с мен за потвърждение на поръчката. '));
    acknowledge.append(document.createTextNode('PDG Systems използва името и телефона за обработка на тази поръчка.'));
    const submit=node('button','Изпрати поръчка');submit.type='submit';const status=node('p');status.setAttribute('role','status');
    form.append(acknowledge,submit);dialog.append(close,title,node('p',product.name),summary,form,status);document.body.append(dialog);
    const money=c=>(c/100).toLocaleString('bg-BG',{style:'currency',currency:'EUR'});
    let token=null,quantity=1,busy=false;
    button.onclick=()=>{quantity=Number(document.getElementById('quantity').value);if(!document.getElementById('quantity').reportValidity())return;summary.textContent=quantity+' бр. · '+money(Math.round(Number(product.price)*100)*quantity+Math.floor((Math.round(Number(product.price)*100)*quantity*20+50)/100))+' с ДДС. Доставката се уточнява допълнително.';dialog.showModal();fields.name.focus();};
    dialog.addEventListener('cancel',e=>{if(busy)e.preventDefault();});
    form.onsubmit=async e=>{e.preventDefault();if(busy||!form.reportValidity())return;busy=true;submit.disabled=true;close.disabled=true;status.textContent='Изпращане…';
      try{
        if(!token){const r=await fetch('api/quick-order.php',{cache:'no-store'});if(!r.ok)throw Error('service_unavailable');token=await r.json();}
        const r=await fetch('api/quick-order.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':token.csrf},body:JSON.stringify({request_key:token.request_key,product_id:Number(product.id),quantity,unit_cents:Math.round(Number(product.price)*100),name:fields.name.value,phone:fields.phone.value,notes:fields.notes.value,website:fields.website.value,acknowledge:check.checked})});
        const data=await r.json();if(!r.ok)throw Error(data.error);
        form.hidden=true;status.textContent='Получихме поръчка '+data.order_number+'. Ще се свържем с вас за потвърждение на наличността, доставката и крайната сума.';
        button.disabled=true;button.textContent='Поръчката е изпратена';
      }catch(err){const messages={price_changed:'Цената е променена. Презаредете продукта, за да видите актуалната цена.',product_unavailable:'Количеството или продуктът не са налични за поръчка. Свържете се с нас.',try_later:'Достигнат е лимитът за заявки. Опитайте по-късно.',invalid_customer:'Проверете името и телефонния номер.',request_expired:'Заявката е изтекла. Опитайте отново.'};status.textContent=messages[err.message]||'Поръчката не е потвърдена. Опитайте отново — повторното изпращане няма да я дублира.';if(err.message==='request_expired')token=null;}
      finally{busy=false;submit.disabled=false;close.disabled=false;}
    };
  };
})();
