'use strict';
const {JSDOM}=require(process.env.PDG_JSDOM||'jsdom');
const fs=require('node:fs'),assert=require('node:assert/strict');
const sections=require('../catalog-sections.js');
const rows=JSON.parse(fs.readFileSync(process.env.PDG_PRODUCTS||'database/kamea-catalog.json','utf8'));
const products=Array.isArray(rows)?rows.map((p,i)=>({...p,id:i+1,imageUrl:p.image})):rows.products;
if(Array.isArray(rows))for(const [category,code] of [['Батерии','S-BAT'],['Инвертори','S-INV']])products.push({id:1000+products.length,brand:'SolaX',category,code,name:code,price:null});
const html=fs.readFileSync('catalog.html','utf8').replace(/<script\b[^>]*src=[^>]*><\/script>/g,'');
async function check(section,brand,mobile=false){
 const errors=[];const dom=new JSDOM(html,{url:'https://pdgsystems.bg/catalog.html?section='+section+'&brand='+brand,runScripts:'dangerously',beforeParse(w){w.PDGCatalogSections=sections;w.matchMedia=()=>({matches:mobile,addEventListener(){}});w.fetch=async url=>({ok:true,json:async()=>String(url).includes('catalog-facets')?({items:{}}):({products,prices_visible:true})});w.addEventListener('error',e=>errors.push(e.message));}});
 for(const file of ['catalog-filter-core.js','catalog-card.js','catalog-app.js'])dom.window.eval(fs.readFileSync(file,'utf8'));
 await new Promise(r=>setTimeout(r,40));const d=dom.window.document;assert.deepEqual(errors,[]);assert.equal(d.querySelector('#brand').value,brand);
 const expected=products.filter(p=>sections.matches(p,section)&&p.brand===brand);assert(expected.length);assert(d.querySelector('#count').textContent.startsWith(expected.length+' намерени продукта'));
 for(const tag of d.querySelectorAll('.card .tag'))assert(tag.textContent.startsWith(brand+' · '));
 const actual=[...d.querySelector('#brand').options].map(o=>o.value).filter(Boolean).sort();assert.deepEqual(actual,[...new Set(products.filter(p=>sections.matches(p,section)).map(p=>p.brand))].sort());
 console.log('PASS:',section,brand,expected.length,'products; category-specific manufacturer choices and direct link selection');dom.window.close();
}
(async()=>{for(const b of ['Deye','Dyness','SolaX'])await check('batteries',b);for(const b of ['Deye','SolaX'])for(const mobile of [false,true])await check('inverters',b,mobile);})().catch(e=>{console.error(e);process.exitCode=1});
