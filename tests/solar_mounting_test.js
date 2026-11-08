'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),core=require('../solar-mounting-core.js');
const products=[];for(const category of ['КЕРЕМИДА','ЛАМАРИНА','НАЗЕМЕН'])for(const code of ['R52-5000','R52L-5000','SFR-01','REC','EIC','FEC3035','TRH-T06','ST6.3x80','TRB-D01','TRB-D03','TRB-C04','TRB-D12','R80-4500','SFR-R80','W45-3650','FSR-GRC35','GF1','ATP-1530','RCB'])products.push({id:products.length+1,brand:'Enerack',category,code,name:code,price:'1'});
const R=6371008.8,lat=42.7,lng=23.32;
function panel(x,y,rotation=0,w=1,h=2){return {lat:lat+y/R*180/Math.PI,lng:lng+x/(R*Math.cos(lat*Math.PI/180))*180/Math.PI,w,h,rotation,power:450}}
function roof(panels,roofType='tile',systemId='tile'){return {path:[[lat,lng],[lat,lng+.001],[lat+.001,lng+.001]],tilt:10,panels,mounting:{brand:'enerack',roofType,systemId}}}
const qty=(result,code)=>result.rows.filter(r=>r.code===code).reduce((sum,r)=>sum+r.qty,0);
let input=roof([panel(0,0),panel(1.02,0),panel(2.04,0)]),result=core.calculate([input],products);
assert.equal(result.roofs[0].runs,1);assert.equal(result.railMetres,6.28);assert.equal(qty(result,'R52-5000'),2);assert.equal(qty(result,'EIC'),4);assert.equal(qty(result,'FEC3035'),4);assert.equal(qty(result,'TRH-T06'),8);assert.equal(qty(result,'ST6.3x80'),16);assert.equal(result.pending.length,0);
// Removing the middle module splits the rails and introduces four extra end clamps.
result=core.calculate([roof([panel(0,0),panel(2.04,0)])],products);assert.equal(result.roofs[0].runs,2);assert.equal(qty(result,'EIC'),0);assert.equal(qty(result,'FEC3035'),8);
result=core.calculate([roof([panel(0,0),panel(1.02,0),panel(0,2.02)])],products);assert.equal(result.roofs[0].runs,2);assert.equal(qty(result,'EIC'),2);
result=core.calculate([roof([panel(0,0,90),panel(0,-1.02,90)])],products);assert.equal(result.roofs[0].runs,1);assert.equal(qty(result,'EIC'),2);
const many=Array.from({length:6},(_,i)=>panel(i*1.02,0));result=core.calculate([roof(many)],products);assert.equal(qty(result,'R52-5000'),4);assert.equal(qty(result,'SFR-01'),2);
input=roof(many);input.mounting.estimate={span:.6};const tighter=core.calculate([input],products);assert(tighter.roofs[0].supports>result.roofs[0].supports);
for(const [type,system,code]of [['metal','lfeet','TRB-D01'],['metal','hanger','TRB-D03'],['standing','kliplok','TRB-C04'],['ground','ground','W45-3650']]){result=core.calculate([roof([panel(0,0)],type,system)],products);assert(qty(result,code)>0);assert.equal(qty(result,'TRH-T06'),0)}
input=roof([panel(0,0)],'flat','tilt');input.moduleTilt=20;result=core.calculate([input],products);assert(qty(result,'ATP-1530')>0);assert(result.pending.length>0);
result=core.calculate([roof([panel(0,0)],'flat','ballast')],products);assert.equal(result.rows.length,0);assert.match(result.pending[0],/баластна/);
input=roof([panel(0,0)]);input.mounting.brand='aero';assert.match(core.calculate([input],products).pending[0],/комплектовка/);
input=roof([panel(0,0)]);input.mounting.estimate={components:{foot:'99999'}};assert.match(core.calculate([input],products).pending[0],/Опора/);
result=core.calculate([roof([panel(0,0)]),roof([panel(0,0)])],products);assert.equal(qty(result,'FEC3035'),8);assert.equal(result.rows.find(r=>r.code==='FEC3035').roofs.length,2);
const cost=core.addCosts({rows:[{name:'Монтажна конструкция',qty:1,unit:9999,total:9999},{name:'Панели',qty:1,unit:100,total:100}]},result,x=>x===''?null:Math.round(Number(x)*100));assert(!cost.rows.some(r=>r.name==='Монтажна конструкция'));assert.equal(cost.total,cost.subtotal+Math.round(cost.subtotal*.2));assert.equal(cost.subtotal,100+result.rows.reduce((s,r)=>s+r.qty*100,0));
const hidden=core.calculate([roof([panel(0,0)])],products.map(p=>({...p,price:null})));assert.equal(core.addCosts({rows:[]},hidden,x=>x===''?null:Number(x)*100).complete,false);
assert.equal(core.calculate([],products).rows.length,0);
console.log('PASS: roof-specific catalogue BOMs, row gaps, mixed rows, rotations, rail stock/joints, support spacing, multiple roofs, missing products/prices, unsupported ballast and no duplicated mounting cost');
