'use strict';
const {JSDOM}=require(process.env.PDG_JSDOM||'jsdom'),fs=require('node:fs'),assert=require('node:assert/strict'),geometry=require('../solar-project-start.js');
for(const name of ['roof-cad.php','solar-cad-preview.html']){
const source=fs.readFileSync(name,'utf8'),start=source.indexOf("(()=>{'use strict';const base=window.render3D"),end=source.indexOf('</script>',start),script=source.slice(start,end);
const dom=new JSDOM('<body class="view-3d"><div id="view3d"><div id="roof3d"></div><div>caption</div></div></body>',{runScripts:'outside-only'}),w=dom.window;
w.PDGSolarEditor={zoom(){},resize(){}};w.PDGSolarProjectStart=geometry;w.render3D=()=>{throw Error('Joined roofs must use the shared height model');};
const planes=geometry.houseRoofs({width:12,depth:8,count:4,aspect:180,tilt:25},{lat:42.7,lng:23.32});
w.eval('const planes='+JSON.stringify(planes)+';'+script);w.render3D();const svg=w.document.querySelector('#roof3d svg');assert(svg);assert.equal(svg.querySelectorAll('polygon').length,8);for(const e of svg.querySelectorAll('*'))for(const a of e.attributes)assert(!/NaN|Infinity|undefined/.test(a.value));
w.PDGSolarEditor.zoom(1);assert(w.document.querySelector('#roof3d svg'));console.log('PASS:',name,'actual 3D renderer creates one joined roof and exterior walls with finite geometry');w.close();}
// A previously saved manual project without modules is upgraded on load.
{
const dom=new JSDOM(fs.readFileSync('roof-cad.php','utf8'),{runScripts:'outside-only'}),w=dom.window,d=w.document;
for(const id of ['welcome-start','menu-new']){const b=d.createElement('button');b.id=id;d.body.append(b);}const R=6371008.8,at=(x,y)=>[42.7+y/R*180/Math.PI,23.32+x/(R*Math.cos(42.7*Math.PI/180))*180/Math.PI];
let project={version:1,center:{lat:42.7,lng:23.32},projectStart:{expectedRoofs:4,mode:'manual'},planes:Array.from({length:4},(_,i)=>({path:[[0,0],[10,0],[10,5],[0,5]].map(([x,y])=>at(x+i*20,y)),azimuth:180,tilt:10,panels:[]}))},view=null;
w.PDGSolarEditor={project:()=>project,restore:p=>{project=p;},state:()=>({roofs:project.planes.length,view}),open(){},toggle2d(){view='2d';},message(){}};
w.eval(fs.readFileSync('solar-project-start.js','utf8'));d.dispatchEvent(new w.Event('DOMContentLoaded'));
assert(project.planes.every(p=>p.roofPlane));assert.equal(project.projectStart.roofModel,'joined-v1');assert.equal(project.planes.length,4);console.log('PASS: saved four-slope manual layout without panels is upgraded to one connected roof');w.close();
}
