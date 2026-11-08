'use strict';
const {JSDOM}=require(process.env.PDG_JSDOM||'jsdom'),fs=require('fs'),assert=require('node:assert/strict'),core=require('../solar-offer-core');
(async()=>{
const dom=new JSDOM('<select id="offer-inverter"><option value="">Ръчно</option><option value="1">MPPT3</option><option value="2">MPPT2</option><option value="3">Unknown</option></select><input id="offer-minTemperature" value="-20"><input id="offer-inverterQty" value="1"><button id="offer-generate">Generate</button>',{runScripts:'outside-only',url:'https://pdgsystems.bg/roof-cad.php'}),w=dom.window,d=w.document;
const panel={model:'shop:1',power:620,voc:49.08,vmp:40.74,imp:15.22,isc:16.08,vocCoefficient:-.25},project={planes:[90,180,270].map(azimuth=>({azimuth,tilt:20,panels:Array.from({length:8},()=>({...panel}))}))};let generated=0;
const fields=mppt=>[['Брой фази','3'],['Брой MPPT',String(mppt)],['Препоръчителна максимална PV мощност','30 kWp'],['MPPT / стрингове на MPPT',mppt+' / ('+Array(mppt).fill('2').join('/')+')'],['Максимален входен ток на MPPT',Array(mppt).fill('40 A').join('/')],['Максимален ток на късо съединение на MPPT',Array(mppt).fill('50 A').join('/')],['Минимално MPPT напрежение (V)','100'],['Максимално MPPT напрежение (V)','700']].map(([label,value])=>({label,value}));
w.PDGSolarOfferCore=core;w.PDGSolarEditor={project:()=>project,message(){}};d.getElementById('offer-generate').onclick=()=>generated++;
w.fetch=async url=>({ok:true,json:async()=>({fields:url.endsWith('1')?fields(3):url.endsWith('2')?fields(2):[]})});
w.eval(fs.readFileSync('solar-inverter-match.js','utf8'));await new Promise(r=>setTimeout(r,400));
assert.equal(d.querySelector('option[value="1"]').disabled,false);assert.equal(d.querySelector('option[value="2"]').disabled,true);assert.equal(d.querySelector('option[value="3"]').disabled,true);
const select=d.getElementById('offer-inverter');select.value='2';await d.getElementById('offer-generate').onclick();assert.equal(generated,0,'invalid saved selection cannot generate');
select.value='1';await d.getElementById('offer-generate').onclick();assert.equal(generated,1);
project.planes.pop();w.dispatchEvent(new w.Event('pdg-solar-change'));await new Promise(r=>setTimeout(r,400));assert.equal(d.querySelector('option[value="2"]').disabled,false,'filter follows project changes');
console.log('PASS: real inverter selector filters by project, disables incomplete/incompatible models, protects generation, recomputes after roof changes');w.close();
})().catch(e=>{console.error(e);process.exitCode=1});
