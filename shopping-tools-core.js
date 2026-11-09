(function(root){
  'use strict';
  function parse(text,products){
    if(typeof text!=='string'||text.length>100000)throw Error('Списъкът е твърде голям.');
    const lines=text.replace(/^\uFEFF/,'').split(/\r?\n/).map(x=>x.trim()).filter(Boolean);
    if(lines.length>51)throw Error('Позволени са до 50 позиции.');
    const items=new Map(),errors=[];
    for(const [index,line] of lines.entries()){
      if(index===0&&/^(код|code|sku|id)[;,\t]/i.test(line))continue;
      const parts=line.split(/[;\t,]/).map(x=>x.trim());
      const code=parts[0],qty=parts[1]===undefined?1:Number(parts[1]);
      if(parts.length>2||!Number.isInteger(qty)||qty<1||qty>999){errors.push('Ред '+(index+1)+': количество от 1 до 999.');continue;}
      const matches=products.filter(p=>/^#[1-9]\d*$/.test(code)?Number(p.id)===Number(code.slice(1)):String(p.code).toLocaleUpperCase()===code.toLocaleUpperCase());
      if(matches.length!==1){errors.push('Ред '+(index+1)+': '+code+(matches.length?' има различни варианти. Използвайте #ID от продуктовия адрес.':' не е намерен.'));continue;}
      const id=Number(matches[0].id),quantity=(items.get(id)||0)+qty;
      if(quantity>999){errors.push('Ред '+(index+1)+': сборът надхвърля 999.');continue;}items.set(id,quantity);
    }
    if(items.size>50)errors.push('Позволени са до 50 различни продукта.');
    return {items:[...items].map(([id,quantity])=>({id,quantity})),errors};
  }
  function solar(project,mounting={rows:[],pending:[]}){
    const items=[],unresolved=[...(mounting.pending||[])];
    const add=(id,quantity,name)=>{id=Number(String(id||'').replace(/^shop:/,''));if(Number.isInteger(id)&&id>0&&Number.isInteger(Number(quantity))&&Number(quantity)>0)items.push({id,quantity:Number(quantity)});else unresolved.push(name||'Неопределена позиция');};
    for(const roof of project.planes||[])for(const p of roof.panels||[])add(/^shop:/.test(p.model)?p.model:null,1,'Панел с ръчно въведен модел');
    if(project.offer?.inverter)add(project.offer.inverter,project.offer.inverterQty||1,'Инвертор');else unresolved.push('Не е избран каталожен инвертор');
    if(project.battery?.name)add(project.battery.id,project.battery.quantity,'Батерия');
    for(const group of Object.values(project.equipment||{}))for(const item of group)add(item.id,item.quantity,item.name);
    for(const row of mounting.rows||[])add(row.id,row.qty,row.name);
    if(!(mounting.rows||[]).length)unresolved.push('Монтажната конструкция не е включена');
    return {items,unresolved:[...new Set(unresolved)]};
  }
  function electric(project,products){
    const items=[],unresolved=[];
    function add(code,name){const p=products.filter(p=>p.brand==='Schrack Technik'&&p.code===code);if(p.length===1)items.push({id:Number(p[0].id),quantity:1});else unresolved.push(name+' · '+(code||'без каталожен код'));}
    add(project.cabinet?.sku,'Корпус');for(const item of project.items||[])if(item.type!=='BLANK')add(item.sku,item.name||'Апарат');
    if(project.busbars?.length)unresolved.push('Шини и шинни връзки: дължините и сеченията изискват отделна поръчка');
    if(project.connections?.length)unresolved.push('Проводници: дължините и сеченията изискват отделна поръчка');
    return {items,unresolved};
  }
  const api={parse,solar,electric};if(typeof module==='object'&&module.exports)module.exports=api;else root.PDGShoppingCore=api;
})(typeof window==='object'?window:globalThis);
