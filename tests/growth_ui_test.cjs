'use strict';
const {JSDOM,VirtualConsole}=require(process.env.PDG_JSDOM||'jsdom'),fs=require('fs'),assert=require('node:assert/strict');
const pause=()=>new Promise(r=>setTimeout(r,50));
function dom(file){return new JSDOM(fs.readFileSync(file,'utf8'),{url:'https://pdgsystems.bg/'+file,runScripts:'outside-only',virtualConsole:new VirtualConsole()});}
(async()=>{
 const d=dom('shopping-tools.html'),w=d.window;let calls=[];w.HTMLElement.prototype.scrollIntoView=()=>{};w.fetch=async(url,options={})=>{
  if(url.includes('products.php'))return {ok:true,json:async()=>({products:[{id:1,brand:'Test',category:'Инвертори',code:'INV',name:'Inverter'},{id:2,brand:'Test',category:'Батерии',code:'BAT',name:'Battery'},{id:3,brand:'Test',category:'Аксесоари за батерии',code:'RACK',name:'Rack'}]})};
  if(url.includes('account-draft'))return {ok:true,json:async()=>({csrf:'csrf',user:{id:7}})};
  if(url==='api/commerce.php')return {ok:true,json:async()=>({bundles:[]})};
  if(url.includes('product-details'))return {ok:true,json:async()=>({product:{id:url.endsWith('1')?1:2,name:'<img src=x onerror=alert(1)>',price:null},fields:[{label:'Напрежение',value:'48 V'}]})};
  const body=options.body?JSON.parse(options.body):null;if(body)calls.push(body);
  return {ok:true,json:async()=>body?.action==='preview'?{items:[{id:1,code:'INV',name:'Inverter',quantity:2,unit_cents:1000,available:true}],total_cents:2400,can_add:true,revision:'revision'}:body?{ok:true}:{csrf:'csrf',lists:[{id:3,name:'<script>alert(1)</script>',items:[{id:1,quantity:2}]}],orders:[],requests:[]}};
 };
 w.eval(fs.readFileSync('shopping-tools-core.js','utf8'));w.eval(fs.readFileSync('shopping-tools.js','utf8'));await pause();
 assert.equal(w.document.getElementById('private-tools').hidden,false);assert.doesNotMatch(w.document.getElementById('tools-status').textContent,/Зареждане/);assert.equal(w.document.querySelector('#compare-form select').options.length,3,'exclude battery accessories from device comparison');assert.equal(w.document.querySelector('#saved-lists script'),null,'saved names rendered as text');
 w.document.getElementById('quick-lines').value='INV;2';await w.document.getElementById('check-batch').onclick();assert.equal(w.document.getElementById('add-batch').disabled,false);await w.document.getElementById('add-batch').onclick();assert.equal(calls.at(-1).action,'add_batch');assert.equal(calls.at(-1).revision,'revision');
 const selects=[...w.document.querySelectorAll('#compare-form select')];selects[0].value='1';selects[1].value='2';await w.document.getElementById('compare-form').onsubmit({preventDefault(){}});assert.equal(w.document.querySelector('#compare-result img'),null,'product names cannot inject HTML');assert.match(w.document.getElementById('compare-result').textContent,/48 V/);
 w.close();
 const a=dom('admin-growth.html'),x=a.window;x.fetch=async()=>({ok:false,status:403,json:async()=>({error:'forbidden'})});x.eval(fs.readFileSync('shopping-tools-core.js','utf8'));x.eval(fs.readFileSync('admin-growth.js','utf8'));await pause();assert.equal(x.document.getElementById('growth-app').hidden,true);assert.match(x.document.getElementById('tools-status').textContent,/администраторски/);x.close();
 console.log('PASS: authenticated tools, escaped names, comparison, explicit reviewed revision and administrative access boundary');
})().catch(e=>{console.error(e);process.exitCode=1});
