'use strict';
const assert=require('node:assert/strict');
const {project,costs,price}=require('../solar-offer-core.js');
const path=[[42.7,23.3],[42.7001,23.3],[42.7001,23.3001]];
const summary=project([
 {path,tilt:10,azimuth:180,mountingName:'Enerack',panels:[{power:440,model:'jinko440'},{power:440,model:'jinko440'}]},
 {path,tilt:25,moduleTilt:35,azimuth:90,panels:[{power:550}]}
]);
assert.equal(summary.count,3);assert.equal(summary.kwp,1.43);
assert.equal(summary.groups.length,2);assert.equal(summary.groups[0].count,2);
assert.equal(summary.roofs[1].tilt,25);assert.equal(summary.roofs[1].moduleTilt,35);assert.equal(summary.roofs[0].moduleTilt,10);assert.equal(summary.roofs[1].mounting,'Не е избрана');
const settings={inverterName:'Test',inverterQty:'2',panelPrice:'100.25',inverterPrice:'1000',mountPrice:'500',installPrice:'300'};
const quote=costs(summary,settings);
assert.equal(quote.subtotal,310075);assert.equal(quote.vat,62015);assert.equal(quote.total,372090);assert.equal(quote.complete,true);
const partial=costs(summary,{...settings,mountPrice:''});assert.equal(partial.complete,false);assert.equal(partial.rows[2].total,null);
assert.equal(price('0'),0);assert.equal(price(''),null);assert.throws(()=>price('-1'));assert.throws(()=>price('NaN'));
assert.throws(()=>project([{path,tilt:10,azimuth:180,panels:[{power:NaN}]}]));
console.log('PASS: multi-roof panel totals, mixed models, per-roof angles, exact VAT, missing and zero prices');

const base={...settings,installMode:'detailed',installPanelsPrice:'300',installInverterPrice:'120',installBatteryPrice:'80',materialsPrice:'50',installCentsPerW:'99',installPrice:'999'};
const detail=costs(summary,base),labour=detail.rows.filter(r=>r.name.startsWith('Монтаж —'));
assert.equal(labour.length,3);assert.equal(labour.reduce((s,r)=>s+r.total,0),50000);
assert.equal(detail.rows.find(r=>r.name==='Останали материали').total,5000);
const perW=costs({...summary,kwp:10},{...base,installMode:'perW',installCentsPerW:'8'});
assert.equal(perW.rows.filter(r=>r.name.startsWith('Монтаж —')).length,1);
assert.equal(perW.rows.find(r=>r.name==='Монтаж — общо по мощност').total,80000);
assert.equal(perW.rows.find(r=>r.name==='Монтаж — общо по мощност').qty,10000);
assert.match(perW.rows.find(r=>r.name==='Монтаж — общо по мощност').unitLabel,/8 евроцента\/W/);
const fractional=costs(summary,{...base,installMode:'perW',installCentsPerW:'3.125'});
assert.equal(fractional.rows.find(r=>r.name==='Монтаж — общо по мощност').total,4469);
assert.equal(costs(summary,{...base,installMode:'perW',installCentsPerW:''}).complete,false);
assert.equal(costs(summary,{...base,installPanelsPrice:'0',installInverterPrice:'0',installBatteryPrice:'0',materialsPrice:'0'}).complete,true);
assert.throws(()=>costs(summary,{...base,installMode:'perW',installCentsPerW:'-1'}));
assert.throws(()=>costs(summary,{...base,installMode:'bad'}));
const fixed=costs(summary,{...base,installMode:'total',installPrice:'250'});
assert.equal(fixed.rows.filter(r=>r.name.startsWith('Монтаж —')).length,1);
assert.equal(fixed.rows.find(r=>r.name==='Монтаж — обща сума').total,25000);
console.log('PASS: three labour subtotals, separate materials, exact eurocent/W arithmetic, one active mode, zero/unknown prices and legacy totals');

// User regression: 16 x 620 W, labour 13 eurocent/W, other materials EUR 1,000.
const screenshot=costs({count:16,kwp:9.92},{...base,installMode:'perW',installCentsPerW:'13',materialsPrice:'1000'});
assert.equal(screenshot.rows.find(r=>r.name==='Монтаж — общо по мощност').total,128960);
assert.equal(screenshot.rows.find(r=>r.name==='Останали материали').total,100000);
assert(!screenshot.rows.some(r=>r.name==='Монтаж и останали материали'));
const labourAndMaterials=screenshot.rows.filter(r=>r.name.startsWith('Монтаж —')||r.name==='Останали материали');assert.equal(labourAndMaterials.reduce((s,r)=>s+r.total,0),228960);
console.log('PASS: user screenshot 9,920 W x 13 eurocent/W = EUR 1,289.60, plus EUR 1,000 separate materials');
