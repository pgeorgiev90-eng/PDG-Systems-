'use strict';
const {JSDOM}=require(process.env.PDG_JSDOM||'jsdom'),fs=require('node:fs'),assert=require('node:assert/strict'),geometry=require('../solar-project-start.js');
for(const file of ['roof-cad.php','solar-cad-preview.html']){
 const source=fs.readFileSync(file,'utf8'),part=(a,b)=>source.slice(source.indexOf(a),source.indexOf(b,source.indexOf(a)));
 const dom=new JSDOM(source,{runScripts:'outside-only'}),w=dom.window,d=w.document;d.body.classList.add('cad-mode','view-2d');
 for(const id of ['preview-add-panel','preview-place','preview-2d','preview-3d']){const b=d.createElement('button');b.id=id;d.body.append(b);}
 const svg=d.getElementById('roof2d');svg.createSVGPoint=()=>({x:0,y:0,matrixTransform(){return {x:this.x,y:this.y};}});svg.getScreenCTM=()=>({inverse:()=>({})});
 for(const [id,value]of Object.entries({panelW:'1',panelH:'2',panelPower:'550',panelGap:'.02',panelVoc:'40',panelVmp:'33',panelVocCoefficient:'-.25',panelImp:'13',panelIsc:'14'}))d.getElementById(id).value=value;
 d.getElementById('alignSouth').checked=false;
 const roof=geometry.houseRoofs({width:12,depth:8,count:1,aspect:180,tilt:10},{lat:42.7,lng:23.32});
 const initial='const $=id=>document.getElementById(id),ns="http://www.w3.org/2000/svg";let planes='+JSON.stringify(roof)+',selected=0,selectedPanel=-1,markedPanels=new Set(),orientation="landscape",mode="idle",last="";let messages=[];const api={project:()=>({version:1,planes}),state:()=>({mode,points:0,cad:true,view:"2d"}),message:m=>messages.push(m),open:()=>{throw Error("Must not switch to map")}};function msg(m){messages.push(m);}function setMode(m){mode=m;}function render(){window.render2D();}function choosePanel(){};';
 const render=part('window.render2D=function()',"\n};")+'\n};';
 const functions=part('function rad(','function actualArea(')+part('function contains(','function panelMoveGroup(')+part('function panelFitsRoof(','function roofPanelAngle(')+part('function roofPanelAngle(','function autoFillRoof(')+part('function placePanelAt(',"map.on('click'")+part('function plan()',"$('preview-fill').onclick");
 // The final extraction includes the small Add button binding immediately before Fill.
 const placement=source.slice(source.indexOf("$('preview-place').onclick="),source.indexOf('\n',source.indexOf("$('preview-place').onclick=")));
 const addHandler=source.slice(source.indexOf("$('addPanel').onclick="),source.indexOf('\n',source.indexOf("$('addPanel').onclick=")));
 w.eval(initial+functions+render+addHandler+placement+"$('preview-add-panel').onclick=()=>$('preview-place').click();window.render2D();window.testRead=code=>eval(code);");
 d.getElementById('preview-add-panel').click();assert(d.body.classList.contains('view-2d'));assert.equal(w.testRead('mode'),'panel');assert.equal(w.testRead('planes[0].panels.length'),0);
 const event=(x,y,id=1,primary=true)=>({clientX:x,clientY:y,pointerId:id,isPrimary:primary,button:0,preventDefault(){},stopPropagation(){}});
 svg.onpointerdown(event(0,0));svg.onpointerup(event(0,0));assert.equal(w.testRead('planes[0].panels.length'),1);assert.equal(w.testRead('planes[0].panels[0].rotation'),90);assert(d.body.classList.contains('view-2d'));
 d.getElementById('preview-add-panel').click();svg.onpointerdown(event(0,0));svg.onpointerup(event(0,0));assert.equal(w.testRead('planes[0].panels.length'),1,'overlap rejected');
 svg.onpointerdown(event(20,20));svg.onpointerup(event(20,20));assert.equal(w.testRead('planes[0].panels.length'),1,'outside rejected');
 svg.onpointerdown(event(3,0));svg.onpointerup(event(15,0));assert.equal(w.testRead('planes[0].panels.length'),1,'drag does not add');
 svg.onpointerdown(event(3,0));svg.onpointercancel();svg.onpointerup(event(3,0));assert.equal(w.testRead('planes[0].panels.length'),1,'cancel does not add');
 svg.onpointerdown(event(3,0));svg.onpointerdown(event(4,0,2,false));svg.onpointerup(event(3,0));assert.equal(w.testRead('planes[0].panels.length'),1,'multitouch does not add');
 svg.onpointerdown(event(3,0));svg.onpointerup(event(3,0));assert.equal(w.testRead('planes[0].panels.length'),2,'next tap adds exactly one');assert(d.body.classList.contains('view-2d'));
 w.testRead('mode="idle";planes.push({...JSON.parse(JSON.stringify(planes[0])),panels:[],azimuth:90});window.render2D()');
 const second=d.querySelector('[data-roof-index="1"]');second.dispatchEvent(new w.MouseEvent('click',{bubbles:true}));
 assert.equal(w.testRead('selected'),1);assert.equal(w.testRead('selectedPanel'),-1);
 assert.equal(d.getElementById('preview-roof-picker').value,'1');
 // Existing layout remains attached to its own plane.
 assert.equal(w.testRead('planes[0].panels.length'),2);assert.equal(w.testRead('planes[1].panels.length'),0);
 d.getElementById('preview-roof-picker').value='0';d.getElementById('preview-roof-picker').dispatchEvent(new w.Event('change'));
 assert.equal(w.testRead('selected'),0);
 assert(source.includes('selectedRoof:selected')&&source.includes('planes[data.selectedRoof]'),'active roof saved and restored');
 console.log('PASS:',file,'actual Add button stays in 2D; mouse/touch single placement, chosen orientation, overlap/outside rejection, drag/cancel/multitouch safety');w.close();
}
