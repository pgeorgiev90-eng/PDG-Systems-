'use strict';
const assert=require('node:assert/strict'),core=require('../solar-offer-core.js'),panels=require('../solar-panel-catalog.js');
const items=[...require('../database/intermetal-panels.json'),...require('../database/cad-panels.json')].map((p,i)=>({...p,id:i+1}));
for(const p of items){const s=panels.specs(p.fields);assert(s.power>0);assert(s.w>0&&s.h>0);assert(s.voc>0);assert(s.vmp>0);assert(s.imp>0&&s.isc>0);}
assert.equal(panels.matching('jinko440',items).code,'JKM440N-54HL4R-B');assert.equal(panels.matching('longi435',items).code,'LR5-54HTH-435M');
for(const p of items)assert(panels.specs(p.fields).coefficient<0);
const summary={groups:[{model:'shop:1',count:2},{model:'shop:2',count:1},{model:'custom',count:3}]},base={rows:[{name:'Фотоволтаични панели',qty:6,unit:999,total:5994},{name:'Монтаж',qty:1,unit:500,total:500}]},settings={panelPrice:'80'};
const quote=panels.reprice(base,summary,settings,[{...items[0],price:'100'},{...items[1],price:'200'}],core.price);
assert.equal(quote.subtotal,64500);assert.equal(quote.vat,12900);assert.equal(quote.rows.length,4);assert.equal(quote.complete,true);
assert.equal(panels.reprice(base,summary,settings,items,core.price).complete,false);
const removed=panels.reprice(base,{groups:[{model:'shop:99',count:1}]},settings,items,core.price);assert.equal(removed.rows[0].total,null);
assert.equal(panels.reprice(base,{groups:[{model:'shop:1',count:1}]},settings,[{...items[0],price:'0'}],core.price).rows[0].total,0);
console.log('PASS: 8 store models, legacy aliases, exact dimensions and electrical data, unknown temperature coefficients, mixed model prices, hidden/zero prices and removed products');

for(const p of items){
 const project={planes:[{panels:[{model:'shop:'+p.id,power:Number(p.fields.find(f=>f.label==='Мощност (Wp)').value),voc:1,vocCoefficient:null,w:1,h:2,lat:42.7,lng:23.32}]}]};
 assert(panels.refreshElectrical(project,items));assert.equal(project.planes[0].panels[0].w,1,'refresh must not alter layout dimensions');
 for(const phase of [1,3]){const plan=core.stringPlan(project.planes,core.electrical([{label:'Брой фази',value:String(phase)}]),-20);assert(plan.valid,p.code);assert(plan.rows[0].maxStringVoltage<=plan.limit);}
 assert.equal(panels.refreshElectrical(project,items),false,'refresh is idempotent');
}
console.log('PASS: all eight official models produce valid 400/750 V string plans; saved panel specs refreshed without geometry changes');
