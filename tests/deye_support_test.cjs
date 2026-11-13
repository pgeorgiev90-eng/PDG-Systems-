'use strict';
const {JSDOM}=require(process.env.PDG_JSDOM||'jsdom'),fs=require('node:fs'),assert=require('node:assert/strict');
const html=fs.readFileSync('deye-support.html','utf8'),dom=new JSDOM(html,{url:'https://pdgsystems.bg/deye-support.html',runScripts:'outside-only'}),w=dom.window,d=w.document,home=new JSDOM(fs.readFileSync('index.html','utf8')).window.document;
assert.equal(home.querySelectorAll('.technical-support-links>a[href="solax-support.html"]').length,1);
assert.equal(home.querySelectorAll('.technical-support-links>a[href="deye-support.html"]').length,1);
assert(d.querySelector('h1').textContent.includes('V-TAC Deye'));
assert(!/SolaX|Platinum|Certified/i.test(html));
assert.equal(d.querySelector('link[rel=canonical]').href,'https://pdgsystems.bg/deye-support.html');
for(const a of d.querySelectorAll('a[href^="#"]'))assert(d.getElementById(a.hash.slice(1)));
const schema=JSON.parse(d.querySelector('script[type="application/ld+json"]').textContent);assert.equal(schema['@type'],'Service');
assert.equal(schema.url,d.querySelector('link[rel=canonical]').href);
assert(fs.existsSync(d.querySelector('.service-photo').getAttribute('src')));
const form=d.querySelector('form');assert.equal(form.action,'https://pdgsystems.bg/api/contact-enquiries.php');
// Exercise the existing contact flow against local stubs; no real enquiry or email.
const requests=[];w.fetch=async(url,opts={})=>{requests.push({url,opts});return {ok:true,status:opts.method==='POST'?201:200,json:async()=>opts.method==='POST'?{ok:true,reference:'local-test'}:{csrf:'test-csrf',request_key:'test-key'}};};
w.eval(fs.readFileSync('contact-enquiries.js','utf8'));d.querySelector('#name').value='Local test';d.querySelector('#email').value='local@example.test';form.dispatchEvent(new w.Event('submit',{bubbles:true,cancelable:true}));
setTimeout(()=>{try{assert.equal(requests.length,2);const r=requests[1],p=JSON.parse(r.opts.body);assert(p.message.includes('Deye / V-TAC Deye'));assert(p.message.includes('Вид съдействие'));assert.equal(r.opts.headers['X-CSRF-Token'],'test-csrf');assert(d.querySelector('#contactStatus').textContent.includes('local-test'));console.log('PASS: separate support links, page metadata, real image, anchors, structured data and isolated contact form');w.close();}catch(e){console.error(e);process.exitCode=1;}},20);
