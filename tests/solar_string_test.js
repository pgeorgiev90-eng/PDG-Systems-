'use strict';
const assert=require('node:assert/strict');
const {electrical,stringPlan}=require('../solar-offer-core.js');
const one=electrical([{label:'Брой фази',value:'1'}]);
const three=electrical([{label:'Phases',value:'Three-phase'}]);
assert.equal(one.limit,400);assert.equal(three.limit,750);
assert.equal(electrical([{label:'Брой фази',value:'3'},{label:'Максимално DC напрежение (V)',value:'600 V'}]).limit,600);
assert.equal(electrical([{label:'Брой фази',value:'3'},{label:'Max DC voltage',value:'1.1 kV'}]).limit,750);
assert.equal(electrical([{label:'Брой фази',value:'1'},{label:'String voltage limit',value:'350'}]).limit,350);
for(const fields of [[],[{label:'Брой фази',value:'unknown'}],[{label:'Брой фази',value:'1'},{label:'Phases',value:'3'}],[{label:'Брой фази',value:'3'},{label:'Max DC voltage',value:'600/1000'}]])assert.equal(electrical(fields).limit,null);
const planes=[{panels:Array.from({length:20},()=>({model:'jinko440',power:440,voc:39.57}))}];
const p1=stringPlan(planes,one,-20),p3=stringPlan(planes,three,-20);
assert.equal(p1.rows[0].maxPanels,9);assert.equal(p3.rows[0].maxPanels,17);
assert.deepEqual(p1.rows[0].strings,[7,7,6]);assert.equal(p1.rows[0].strings.reduce((s,n)=>s+n,0),20);
assert.equal(p1.rows[0].coldVoc,39.57*(1+0.0025*45));
for(const plan of [p1,p3])for(const row of plan.rows){assert.ok(row.maxPanels*row.coldVoc<=plan.limit);assert.ok((row.maxPanels+1)*row.coldVoc>plan.limit);for(const n of row.strings)assert.ok(n*row.coldVoc<=plan.limit);}
const boundary=stringPlan([{panels:[{power:550,voc:40,vocCoefficient:-.25}]}],one,25);assert.equal(boundary.rows[0].maxPanels,10);
assert.equal(stringPlan(planes,one,'').valid,false);
assert.equal(stringPlan([{panels:[{power:550}]}],one,-20).valid,false);
assert.equal(stringPlan([{panels:[{power:550,voc:40,vocCoefficient:0.25}]}],one,-20).valid,false);
assert.equal(stringPlan(planes,electrical([]),-20).valid,false);
console.log('PASS: product phase parsing, strict 400/750 V caps, lower product caps, cold Voc, exact boundaries, complete string partition and missing-data handling');


// Exercise the actual embedded route engine in both deployed pages.
const fs=require('node:fs'),vm=require('node:vm');
for(const page of ['roof-cad.php','solar-cad-preview.html']){
 const source=fs.readFileSync(page,'utf8'),start=source.indexOf("(function(root){'use strict';\nfunction orderPanels"),end=source.indexOf("})(typeof window==='object'?window:globalThis);",start)+"})(typeof window==='object'?window:globalThis);".length;
 assert(start>=0);const context={module:{exports:{}}};vm.runInNewContext(source.slice(start,end),context);const layout=context.module.exports;
 for(const angle of [0,30,90,135]){
  const R=6371008.8,lat=42.7,lng=23.32,t=angle*Math.PI/180,at=(x,y)=>({lat:lat+(-x*Math.sin(t)+y*Math.cos(t))/R*180/Math.PI,lng:lng+(x*Math.cos(t)+y*Math.sin(t))/(R*Math.cos(lat*Math.PI/180))*180/Math.PI,w:1,h:2,rotation:angle,power:450,voc:40,vocCoefficient:-.25});
  const modules=[];for(let row=0;row<3;row++)for(let col=0;col<4;col++)modules.push(at(col*1.02,-row*2.1));
  const items=modules.map((p,index)=>({p,index})).reverse(),ordered=layout.orderPanels(items);assert.deepEqual(Array.from(ordered,x=>x.index),[0,1,2,3,7,6,5,4,8,9,10,11]);
  const route=layout.wiring(ordered);assert.equal(route.length,16);assert(route.every(p=>Number.isFinite(p.lat)&&Number.isFinite(p.lng)));
  const offer=require('../solar-offer-core.js'),project=[{panels:modules}],plan=offer.stringPlan(project,three,-20),assigned=layout.assign(project,plan,offer);assert.equal(assigned.flatMap(s=>s.panels).length,12);assert.equal(new Set(assigned.flatMap(s=>s.panels.map(p=>p.index))).size,12);for(const s of assigned){assert(s.coldVoc<=s.limit);assert(s.wiring.length>=s.count)}
 }
}
console.log('PASS: rotated and shuffled rows follow nearest neighbours, end-of-row bridges, unique assignment and voltage limits in both pages');
