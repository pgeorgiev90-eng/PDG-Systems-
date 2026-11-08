'use strict';
const assert=require('node:assert/strict'),items=require('../database/kamea-catalog.json'),prices=require('../database/kamea-prices.json');
assert.equal(prices.length,150);assert.equal(new Set(prices.map(p=>p.brand+'|'+p.code)).size,150);
for(const p of prices){const item=items.find(i=>i.sourceId===p.sourceId);assert(item);assert.equal(p.brand,item.brand);assert.equal(p.code,item.code);assert.equal(p.source,item.source);assert.equal(p.vatPercent,20);assert(/^\d+\.\d{2}$/.test(p.netEur));const gross=Number(p.grossEur.replace('.','')),net=Number(p.netEur.replace('.',''));assert.equal(net,Math.floor((gross*5+3)/6));assert(net>0);}
assert.equal(prices.find(p=>p.sourceId==='744').netEur,'10.83');
assert.equal(prices.find(p=>p.sourceId==='379').netEur,'702.50');
assert.equal(prices.find(p=>p.sourceId==='821').grossEur,'379.20');
assert.equal(prices.find(p=>p.sourceId==='821').netEur,'316.00');
console.log('PASS: 150 exact supplier ID/code matches, EUR VAT conversion, promotion selection and complete coverage');
