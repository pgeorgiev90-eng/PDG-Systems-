'use strict';
const {JSDOM}=require(process.env.PDG_JSDOM||'jsdom'),fs=require('node:fs'),assert=require('node:assert/strict');
const html=fs.readFileSync('roof-cad.php','utf8'),script=fs.readFileSync('solar-offer.js','utf8');
(async()=>{
 const old=new JSDOM(html,{url:'https://pdgsystems.bg/roof-cad.php',runScripts:'outside-only'});old.window.PDGSolarOfferCore={quoteVersion:'old'};old.window.eval(script);assert(old.window.document.getElementById('offer-generate').disabled);assert.match(old.window.document.getElementById('solar-offer-settings').textContent,/стара версия/);old.window.close();
 const dom=new JSDOM(html,{url:'https://pdgsystems.bg/roof-cad.php',runScripts:'outside-only'}),w=dom.window,d=w.document;
 w.PDGSolarOfferCore={...require('../solar-offer-core.js')};
 const panels=Array.from({length:16},(_,i)=>({power:620,model:'custom',lat:42.7,lng:23.3+i*.00001,w:1.134,h:2.382,rotation:0,voc:55,vmp:46,imp:13.5,isc:14.2,vocCoefficient:-.25}));
 w.PDGSolarProject={snapshot:()=>[{path:[[42.7,23.3],[42.7001,23.3],[42.7001,23.3001]],panels,tilt:10,azimuth:180}],setTilt(){}};
 w.fetch=async()=>({ok:true,json:async()=>({products:[]})});
 d.getElementById('solarOfferDialog').showModal=function(){this.setAttribute('open','');};
 w.eval(script);w.PDGSolarOffer.restore({inverterName:'Test',inverterKw:'10',inverterQty:'1',inverterPrice:'1541',panelPrice:'80',mountPrice:'450.52',installMode:'perW',installCentsPerW:'13',materialsPrice:'1000',yieldMode:'manual',manualYield:'1200',loss:'14',minTemperature:'-20'});
 await d.getElementById('offer-generate').onclick();
 const text=d.getElementById('solar-offer-report').textContent.replace(/\s/g,'');assert(text.includes('Монтаж—общопомощност'));assert(text.includes('1289,60'));assert(text.includes('Останалиматериали'));assert(text.includes('1000,00'));assert(!text.includes('Монтажиостаналиматериали'));
 assert(!d.getElementById('offer-download').disabled);console.log('PASS: stale calculator blocks quote; actual offer generation yields 1,289.60 EUR labour and 1,000 EUR materials for 16 x 620 W at 13 eurocent/W');w.close();
})().catch(e=>{console.error(e);process.exitCode=1});
