'use strict';
const assert=require('node:assert/strict'),fs=require('fs'),core=require('../solar-offer-core'),match=require('../solar-inverter-match');
const rows=JSON.parse(fs.readFileSync('database/official-inverter-specs.json')),legacy=[...JSON.parse(fs.readFileSync('database/catalog-specifications-v1.json')).filter(r=>r.match.brand==='SolaX').map(r=>({...r.match,fields:r.fields})),...JSON.parse(fs.readFileSync('database/kamea-catalog.json'))];
function fields(r){return Object.entries({'Брой фази':r.phase,'Номинална AC мощност (kW)':r.acPowerKw,'Допустима мощност на PV масива (W)':r.pvPowerW,'Максимална PV мощност за преобразуване (W)':r.conversionPowerW||'Не е посочена отделно','Максимално DC напрежение (V)':r.maxVoltage,'Минимално MPPT напрежение (V)':r.minMppt,'Максимално MPPT напрежение (V)':r.maxMppt,'Брой MPPT':r.inputs?.length,'MPPT / стрингове на MPPT':r.inputs?.length+'/'+r.inputs?.join('+'),'Максимален входен ток на MPPT':r.currents?.join('+'),'Максимален ток на късо съединение на MPPT':r.shortCurrents?.join('+'),'CAD: проверка на инвертора':r.verified?'проверен':'непотвърден'}).map(([label,value])=>({label,value:String(value)}));}
assert.equal(rows.length,115);assert.equal(new Set(rows.map(r=>r.brand+'|'+r.code)).size,115);
let complete=0;
for(const r of rows){const old=legacy.find(i=>i.brand===r.brand&&i.code===r.code);assert.ok(old,r.code+' identity');if(!r.verified){assert.equal(r.model,'SUN-M80G3-EU-Q0');continue;}
 assert.match(r.source,/^https:\/\/(?:[^/]+\.)?(?:solaxpower|deyeinverter)\.com\//);
 const updates=fields(r),labels=new Set(updates.map(f=>f.label)),s=match.specification([...updates,...old.fields.filter(f=>!labels.has(f.label))],core);
 assert.equal(s.errors.length,0,r.code+' base errors '+s.errors);assert.equal(s.mpptCount,r.inputs.length,r.code);assert.equal(s.pvPowerW,r.pvPowerW);assert.deepEqual(s.inputs,r.inputs,r.code);assert.deepEqual(s.currents,r.currents,r.code);assert.deepEqual(s.shortCurrents,r.shortCurrents,r.code);assert.equal(s.mpptMinVoltage,r.minMppt);assert.equal(s.mpptMaxVoltage,r.maxMppt);complete++;
}
assert.equal(complete,114);
const roof={azimuth:180,tilt:20,panels:Array.from({length:16},()=>({model:'test',power:600,voc:45,vmp:37,imp:16,isc:18,vocCoefficient:-.25}))};
const deye=rows.find(r=>r.model==='SUN-6K-SG05LP1-EU-AM2-P'),s=match.specification(fields(deye),core);assert.equal(s.pvPowerW,12000);assert.equal(s.conversionPowerW,9600);assert.equal(match.assess([{...roof,panels:[...roof.panels,{...roof.panels[0]}]}],s,-20,core).status,'incompatible','10.2kWp is below12kWp access but above9.6kW conversion');
const fth=rows.find(r=>r.code==='X3-FTH-100K');assert.equal(fth.inputs.length,9,'9/12 variant cannot silently assume12');
console.log('PASS: all115 catalog identities;114 complete primary-source/conservative variant records; retired G3 excluded; array vs conversion cap; 9/12 tracker variants');
