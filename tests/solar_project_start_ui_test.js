'use strict';
const {JSDOM}=require(process.env.PDG_JSDOM||'jsdom'),fs=require('node:fs'),assert=require('node:assert/strict');
(async()=>{const dom=new JSDOM(fs.readFileSync('roof-cad.php','utf8'),{url:'https://pdgsystems.bg/roof-cad.php',runScripts:'outside-only'}),w=dom.window,d=w.document;
for(const id of ['welcome-start','menu-new']){const b=d.createElement('button');b.id=id;d.body.append(b);}for(const id of ['welcome-panel','project-menu']){const n=d.createElement(id==='project-menu'?'dialog':'div');n.id=id;d.body.append(n);}
w.HTMLDialogElement.prototype.showModal=function(){this.setAttribute('open','');};w.HTMLDialogElement.prototype.close=function(){this.removeAttribute('open');};
let fresh=0,project={version:1,center:{lat:42.7,lng:23.32},planes:[]},view=null,mode='draw',located=null;
d.getElementById('menu-new').onclick=()=>{fresh++;const blank={version:1,center:{lat:42.7,lng:23.32},planes:[]};if(w.PDGSolarEditor)w.PDGSolarEditor.restore(blank);else project=blank;};
w.PDGSolarEditor={project:()=>project,restore:p=>{project=p;w.dispatchEvent(new w.Event('pdg-solar-change'));},state:()=>({roofs:project.planes.length,view}),open:m=>{mode=m;},toggle2d:()=>{view='2d';},resize(){},message(){},showLocation:p=>{located=p;}};
w.eval(fs.readFileSync('solar-location.js','utf8'));
w.PDGSolarLocation.currentPosition=async()=>({lat:43.2,lng:25.1,accuracy:12});
let requestedAddress, drawingStarted=false;
w.fetch=async url=>{requestedAddress=url;return {ok:true,json:async()=>[{lat:'42.6955267',lon:'23.3212816',display_name:'София, бул. Витоша 1'}]};};
d.getElementById('newPlane').onclick=()=>{drawingStarted=true;};
w.eval(fs.readFileSync('solar-project-start.js','utf8'));await new Promise(r=>setTimeout(r,20));
d.getElementById('welcome-start').click();assert(d.getElementById('new-project-wizard').open);assert(!d.getElementById('new-project-google-row').hidden);assert.equal(fresh,0);
d.getElementById('new-mode-manual').click();assert(d.getElementById('new-project-google-row').hidden);assert.equal(d.getElementById('new-roof-0-azimuth').type,'number');const count=d.getElementById('new-roof-count');count.value='2';count.dispatchEvent(new w.Event('change'));assert.equal(d.querySelectorAll('#new-manual-roofs fieldset').length,2);assert(d.getElementById('new-roof-1-width'));assert(d.getElementById('new-roof-1-azimuth'));const layout=d.getElementById('new-roof-layout');layout.value='joined';layout.dispatchEvent(new w.Event('change'));d.getElementById('new-building-aspect').value='270';d.getElementById('new-building-aspect').dispatchEvent(new w.Event('change'));d.getElementById('new-building-width').value='12';d.getElementById('new-building-depth').value='8';d.getElementById('new-project-name').value='Двускатен тест';await d.getElementById('new-project-locate').onclick();assert.match(d.getElementById('new-location-note').textContent,/Моето местоположение/);d.getElementById('new-manual-create').click();
assert.equal(fresh,1);assert.equal(project.planes.length,2);assert.equal(mode,'cad');assert.equal(view,'2d');assert.equal(project.projectName,'Двускатен тест');assert.equal(project.center.lat,43.2);assert.equal(w.PDGSolarEditor.project().projectStart.expectedRoofs,2);assert.equal(project.planes[0].azimuth,270);assert.equal(project.planes[1].azimuth,90);
const saved=JSON.parse(JSON.stringify(w.PDGSolarEditor.project()));w.PDGSolarEditor.restore({version:1,center:{lat:42.7,lng:23.32},planes:[]});assert.equal(w.PDGSolarEditor.project().projectStart,null);w.PDGSolarEditor.restore(saved);assert.equal(w.PDGSolarEditor.project().projectStart.expectedRoofs,2);
d.getElementById('menu-new').click();assert.equal(fresh,2);assert.equal(project.planes.length,0,'New empty project clears existing roofs immediately');assert.equal(w.PDGSolarEditor.project().projectStart,null);assert(d.getElementById('new-project-wizard').open);d.getElementById('new-roof-count').value='4';d.getElementById('new-roof-count').dispatchEvent(new w.Event('change'));d.getElementById('new-project-address').value='София, бул. Витоша 1';d.getElementById('new-project-address').dispatchEvent(new w.Event('input'));const google=d.getElementById('new-project-google');assert.equal(google.textContent,'Търси в Google Maps');assert.equal(new URL(google.href).searchParams.get('query'),'София, бул. Витоша 1');await d.getElementById('new-project-search').onclick();assert.equal(requestedAddress.searchParams.get('city'),'София');assert.equal(requestedAddress.searchParams.get('street'),'1 бул. Витоша');d.querySelector('#new-project-results button').click();assert.equal(fresh,2);assert.equal(located.lat,42.6955267);assert(drawingStarted);assert.equal(w.PDGSolarEditor.project().projectStart.expectedRoofs,4);assert(!d.getElementById('new-project-wizard').open);
d.getElementById('welcome-start').click();
const pin=d.getElementById('new-project-coordinates');
pin.value='92, 26';
d.getElementById('new-project-draw-pin').click();
assert.match(d.getElementById('new-project-status').textContent,/диапазон/);
pin.value='42.396480, 26.411030';
drawingStarted=false;
d.getElementById('new-project-draw-pin').click();
assert.equal(located.lat,42.39648);
assert.equal(located.lng,26.41103);
assert(drawingStarted);
assert(!d.getElementById('new-project-wizard').open);
d.getElementById('welcome-start').click();
d.getElementById('new-mode-manual').click();
count.value='2';
count.dispatchEvent(new w.Event('change'));
for(const [i,width,length,tilt,azimuth] of [[0,12,6,15,135],[1,7,4,25,315]]){
 for(const [field,value] of Object.entries({width,length,tilt,azimuth}))d.getElementById('new-roof-'+i+'-'+field).value=String(value);
}
d.getElementById('new-manual-create').click();
assert.deepEqual(Array.from(project.planes,p=>[p.azimuth,p.tilt]),[[135,15],[315,25]]);
const {heightAt}=require('../solar-project-start');
const meters=(a,b)=>6371008.8*Math.hypot((a[0]-b[0])*Math.PI/180,(a[1]-b[1])*Math.PI/180*Math.cos(project.center.lat*Math.PI/180));
for(const [i,width,length] of [[0,12,6],[1,7,4]]){
 const plane=project.planes[i], [a,b,c]=plane.path;
 assert(Math.abs(meters(a,b)-width)<.01);
 assert(Math.abs(Math.hypot(meters(b,c),heightAt(plane,b)-heightAt(plane,c))-length)<.01);
}
assert.notDeepEqual(project.planes[0].roofPlane.origin,project.planes[1].roofPlane.origin);
console.log('PASS: Welcome opens mode choice without losing current project; New empty project clears immediately, manual two-roof CAD creation, separate values and aspect, address results, requested count and project metadata');w.close();})().catch(e=>{console.error(e);process.exitCode=1});
