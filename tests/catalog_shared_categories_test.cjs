'use strict';
const {JSDOM}=require(process.env.PDG_JSDOM||'jsdom'),fs=require('node:fs'),assert=require('node:assert/strict');
const F=require('../catalog-filter-core.js');
const products=[
 {id:1,brand:'Deye',category:'Инвертори / Хибридни',name:'Deye hybrid'},
 {id:2,brand:'SolaX',category:'Инвертори',name:'SolaX hybrid'},
 {id:3,brand:'SolaX',category:'Инвертори',name:'SolaX grid'},
 {id:4,brand:'Dyness',category:'Високоволтови батерии',name:'Dyness HV'},
 {id:5,brand:'SolaX',category:'Батерии',name:'SolaX HV'},
 {id:6,brand:'SolaX',category:'Батерии',name:'SolaX LV'},
 {id:7,brand:'Deye',category:'Нисковолтови батерии',name:'Deye LV'}
];
const metadata={2:{specs:{'Тип инвертор':'Хибриден'}},3:{specs:{'Тип инвертор':'Мрежов'}},5:{specs:{'Клас батерия':'HV'}},6:{specs:{'Клас батерия':'LV'}}};
const html=fs.readFileSync('catalog.html','utf8').replace(/<script\b[^>]*src=[^>]*><\/script>/g,'');
async function check(mobile,section,category,brands,total){
 const dom=new JSDOM(html,{url:'https://pdgsystems.bg/catalog.html?section='+section+'&category='+encodeURIComponent(category),runScripts:'dangerously',beforeParse(w){w.matchMedia=()=>({matches:mobile,addEventListener(){}});w.HTMLDialogElement.prototype.showModal=function(){this.open=true;};w.HTMLDialogElement.prototype.close=function(){this.open=false;};w.fetch=async url=>({ok:true,json:async()=>String(url).includes('catalog-facets')?{items:metadata}:{products,prices_visible:true}});}});
 const w=dom.window,d=w.document;for(const f of ['catalog-sections.js','catalog-filter-core.js','catalog-card.js','catalog-app.js'])w.eval(fs.readFileSync(f,'utf8'));
 await new Promise(r=>setTimeout(r,40));
 if(mobile){d.querySelector('.filter-open').click();assert(d.querySelector('dialog').open);assert(d.querySelector('dialog #brand'));}
 assert.deepEqual([...d.querySelector('#brand').options].map(o=>o.value).filter(Boolean).sort(),brands.sort());
 assert(d.querySelector('#count').textContent.startsWith(total+' намерени продукта'));
 const brand=d.querySelector('#brand');brand.value='SolaX';brand.dispatchEvent(new w.Event('change'));assert([...d.querySelectorAll('.card .tag')].every(n=>n.textContent.startsWith('SolaX · ')));assert(d.querySelectorAll('.card').length);
 d.querySelector('.filter-clear').click();assert([...brand.options].some(o=>o.value===brands[0]));
 w.close();console.log('PASS:',mobile?'mobile':'desktop',section,category||'all','shared manufacturers and filter reset');
}
(async()=>{for(const mobile of [false,true])for(const row of [
 ['inverters','',['Deye','SolaX'],3],['inverters','Инвертори',['Deye','SolaX'],3],['inverters','Инвертори / Хибридни',['Deye','SolaX'],2],['inverters','Инвертори / Мрежови',['SolaX'],1],
 ['batteries','',['Deye','Dyness','SolaX'],4],['batteries','Батерии',['Deye','Dyness','SolaX'],4],['batteries','Високоволтови батерии',['Dyness','SolaX'],2],['batteries','Нисковолтови батерии',['Deye','SolaX'],2]])await check(mobile,...row);
 assert.equal(F.categoryOf({category:'Инвертори',attrs:{}}),'Инвертори');
})().catch(e=>{console.error(e);process.exitCode=1;});
