'use strict';
const assert=require('node:assert/strict'),core=require('../solar-offer-core.js'),panels=require('../solar-panel-catalog.js');
const items=[...require('../database/intermetal-panels.json'),...require('../database/cad-panels.json')].map((p,i)=>({...p,id:i+1}));
for(const p of items){const s=panels.specs(p.fields);assert(s.power>0);assert(s.w>0&&s.h>0);assert(s.voc>0);assert(s.vmp>0);assert(s.imp>0&&s.isc>0);}
assert.equal(panels.matching('jinko440',items).code,'JKM440N-54HL4R-B');assert.equal(panels.matching('longi435',items).code,'LR5-54HTH-435M');
assert.equal(panels.specs(items[0].fields).coefficient,null);
const summary={groups:[{model:'shop:1',count:2},{model:'shop:2',count:1},{model:'custom',count:3}]},base={rows:[{name:'Фотоволтаични панели',qty:6,unit:999,total:5994},{name:'Монтаж',qty:1,unit:500,total:500}]},settings={panelPrice:'80'};
const quote=panels.reprice(base,summary,settings,[{...items[0],price:'100'},{...items[1],price:'200'}],core.price);
assert.equal(quote.subtotal,64500);assert.equal(quote.vat,12900);assert.equal(quote.rows.length,4);assert.equal(quote.complete,true);
assert.equal(panels.reprice(base,summary,settings,items,core.price).complete,false);
const removed=panels.reprice(base,{groups:[{model:'shop:99',count:1}]},settings,items,core.price);assert.equal(removed.rows[0].total,null);
assert.equal(panels.reprice(base,{groups:[{model:'shop:1',count:1}]},settings,[{...items[0],price:'0'}],core.price).rows[0].total,0);
console.log('PASS: 8 store models, legacy aliases, exact dimensions and electrical data, unknown temperature coefficients, mixed model prices, hidden/zero prices and removed products');
