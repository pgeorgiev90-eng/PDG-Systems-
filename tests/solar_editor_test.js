'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
for(const file of ['roof-cad.php','solar-cad-preview.html']){
 const source=fs.readFileSync(file,'utf8'),part=(a,b)=>source.slice(source.indexOf(a),source.indexOf(b,source.indexOf(a)));
 const fields={panelW:{value:'1'},panelH:{value:'2'},panelPower:{value:'450'},panelGap:{value:'.02'},alignSouth:{checked:true},panelCatalog:{value:'custom'},panelVoc:{value:'40'},panelVmp:{value:'33'},panelVocCoefficient:{value:'-.25'},panelImp:{value:'13'},panelIsc:{value:'14'}};
 const ctx=vm.createContext({console,Math,Number,Set,document:{body:{classList:{contains:()=>true}}},window:{},$:(id)=>fields[id],render:()=>{},msg:()=>{}});
 vm.runInContext('let selected=0,markedPanels=new Set(),planes=[],orientation="portrait";'+part('function rad(','function actualArea(')+part('function contains(','function panelMoveGroup(')+part('function panelMoveGroup(','function choosePanel(')+part('function panelFitsRoof(','function roofPanelAngle(')+part('function roofPanelAngle(','function autoFillRoof(')+part('function autoFillRoof('," $('autoFill')"),ctx);
 const run=s=>vm.runInContext(s,ctx);
 run("planes=[{path:[[0,0],[0,.0001],[.0001,.0001],[.0001,0]],panels:[]}];autoFillRoof(true)");
 assert.ok(run('planes[0].panels.length')>10,file+' rectangle fill');
 assert.ok(run('planes[0].panels.every(p=>panelFitsRoof(p,planes[0].path))'),file+' all panels inside');
 const count=run('planes[0].panels.length');run('autoFillRoof(true)');assert.equal(run('planes[0].panels.length'),count,file+' repeated fill cannot overlap');
 run('markedPanels=new Set([0,1]);const group=panelMoveGroup(0,0);const original=planes[0].panels.map(p=>({...p}));translatePanels(group,.001,.002)');
 assert.ok(run('planes[0].panels[0].lat===original[0].lat+.001&&planes[0].panels[1].lng===original[1].lng+.002&&planes[0].panels[2].lat===original[2].lat'),file+' group translation');
 run('translatePanels(group,0,0)');assert.ok(run('planes[0].panels[0].lat===original[0].lat'),file+' cancelled move rolls back');
 assert.equal(run('panelMoveGroup(0,2).length'),1,file+' unmarked move');
 run('planes[0].panels=Array.from({length:1499},()=>({...original[0]}));autoFillRoof(true)');assert.equal(run('planes[0].panels.length'),1500,file+' maximum includes existing panels');
 run('autoFillRoof(true)');assert.equal(run('planes[0].panels.length'),1500,file+' cap remains stable');
 // A notch can cross a panel whose four corners all remain inside.
 run('const notch=[[0,0],[0,.0001],[.0001,.0001],[.0001,.00006],[.00004,.00006],[.00004,.00004],[.0001,.00004],[.0001,0]];const bridge={lat:.000065,lng:.00005,w:8,h:2,rotation:0}');
 assert.ok(run('panelCorners(bridge).every(c=>contains(c,notch))'));
 assert.equal(run('panelFitsRoof(bridge,notch)'),false,file+' concave roof edge crossing');
 // Reproduce explicit landscape selection and replace a mixed existing layout.
 run("planes=[{path:[[0,0],[0,.0001],[.0001,.0001],[.0001,0]],azimuth:180,roofPlane:{},panels:[]}];orientation='portrait';autoFillRoof(true,true)");
 assert(run('planes[0].panels.every(p=>p.rotation===0)'));
 run("orientation='landscape';autoFillRoof(true,true)");assert(run('planes[0].panels.length>0&&planes[0].panels.every(p=>p.rotation===90)'));
 assert(run('planes[0].panels.every(p=>panelFitsRoof(p,planes[0].path))'));
 const landscapeCount=run('planes[0].panels.length');run('autoFillRoof(true,true)');assert.equal(run('planes[0].panels.length'),landscapeCount);
 fields.alignSouth.checked=false;
 for(const face of require('../solar-project-start.js').houseRoofs({width:12,depth:8,count:4,aspect:180,tilt:20},{lat:42.7,lng:23.32})){
  run('planes=['+JSON.stringify(face)+'];orientation="landscape";autoFillRoof(true,true)');
  assert(run('planes[0].panels.length>0&&planes[0].panels.every(p=>p.rotation===selectedPanelAngle(planes[0]))'),file+' triangular/trapezoidal landscape relative to eave');
  assert(run('planes[0].panels.every(p=>panelFitsRoof(p,planes[0].path))'));
 }
 console.log('PASS: '+file+' portrait/landscape fill, replacing old panels, repeat fill stability and triangular/hip roof orientation');
 console.log('PASS: '+file+' actual editor geometry, non-overlapping fill, concave edges, 1500 limit, group move and cancellation');
}
// Exercise the real autosave function against storage failures and unchanged saves.
{
 const source=fs.readFileSync('solar-cad-preview.html','utf8'),data=new Map(),status={textContent:''};let project={version:1,planes:[],projectName:'One'};
 const ctx=vm.createContext({JSON,Date,Error,localStorage:{getItem:k=>data.get(k)||null,setItem:(k,v)=>data.set(k,v)},window:{PDGSolarProjectFormat:{validProject:d=>d?.version===1&&Array.isArray(d.planes)}},api:{project:()=>project,message:()=>{}},status,ready:true,restoring:false,storeKey:'test'});
 vm.runInContext(source.slice(source.indexOf('function save(show=false)'),source.indexOf('function schedule()',source.indexOf('function save(show=false)'))),ctx);
 vm.runInContext('save()',ctx);const first=data.get('test');
 project={...project,projectName:'Two'};vm.runInContext('save()',ctx);assert.equal(data.get('test-previous'),first);
 vm.runInContext('save()',ctx);assert.equal(data.get('test-previous'),first,'unchanged save preserves previous revision');
 data.set('test','broken');project={...project,projectName:'Three'};vm.runInContext('save()',ctx);assert.equal(data.get('test-previous'),first,'corrupt current cannot replace recovery');
 ctx.localStorage.setItem=()=>{throw Error('quota')};project={...project,projectName:'Four'};vm.runInContext('save()',ctx);assert.match(status.textContent,/Експорт/);
 console.log('PASS: real autosave rotation, stable previous revision, corrupt current and storage quota failure');
}
{
 const source=fs.readFileSync('solar-cad-preview.html','utf8'),ui={'preview-undo':{},'preview-redo':{},'welcome-panel':{hidden:true}};
 let state={version:1,planes:[{}]},opened='';
 const ctx=vm.createContext({JSON,api:{project:()=>state,restore:p=>{state=p},state:()=>({mode:'idle',points:0,roofs:state.planes.length}),open:s=>{opened=s}},$:id=>ui[id],plan:()=>{},refresh:()=>{}});
 vm.runInContext('let undo=[],redo=[],busy=false,last="";'+source.slice(source.indexOf('function historyButtons()'),source.indexOf("$('preview-undo').onclick",source.indexOf('function historyButtons()'))),ctx);
 vm.runInContext('undo.push(JSON.stringify({version:1,planes:[]}));travel(undo,redo)',ctx);
 assert.equal(opened,'draw');assert.equal(ui['welcome-panel'].hidden,false);
 vm.runInContext('travel(redo,undo)',ctx);assert.equal(state.planes.length,1);
 console.log('PASS: actual undo restores empty workspace and redo restores project');
}

// The temporary tracing guide must never become part of the saved roof contour.
{
 const source=fs.readFileSync('roof-cad.php','utf8'),match=source.match(/<script data-pdg-roof-guide="[^"]+">([\s\S]*?)<\/script>/);assert.ok(match);
 const events={},hostEvents={},windowEvents={},frames=[],bodyClasses=new Set(),hostClasses=new Set(),nodes=[];
 const classes=set=>({contains:name=>set.has(name),toggle:(name,on)=>on?set.add(name):set.delete(name)});
 const host={classList:classes(hostClasses),addEventListener:(name,fn)=>hostEvents[name]=fn,getBoundingClientRect:()=>({left:0,top:0})};
 const layer={addTo(){return this},clearLayers(){nodes.length=0}},shape=(kind,points,options)=>({addTo(){nodes.push({kind,points,options});return this}});
 let center={lat:3,lng:4};const map={getContainer:()=>host,on:(names,fn)=>names.split(' ').forEach(n=>events[n]=fn),getCenter:()=>center,containerPointToLatLng:p=>({lat:p.y,lng:p.x}),latLngToContainerPoint:p=>({x:p.lng,y:p.lat}),distance:(a,b)=>Math.hypot(a[0]-b[0],a[1]-b[1])};
 const context=vm.createContext({map,mode:'roof',draft:[],window:{addEventListener:(n,fn)=>windowEvents[n]=fn},document:{body:{classList:classes(bodyClasses)},getElementById:()=>null},L:{layerGroup:()=>layer,marker:(p,o)=>shape('marker',p,o),polyline:(p,o)=>shape('line',p,o),divIcon:o=>o,point:(x,y)=>({x,y})},requestAnimationFrame:fn=>{frames.push(fn);return frames.length}});
 const flush=()=>{while(frames.length)frames.shift()()};vm.runInContext(match[1],context);flush();assert(hostClasses.has('roof-tracing'));assert.equal(nodes.length,0);
 events.mousemove({containerPoint:{x:2,y:1}});flush();assert.equal(nodes.length,1);
 vm.runInContext('draft.push([0,0])',context);windowEvents['pdg-solar-mode']();flush();assert.equal(nodes.filter(n=>n.kind==='line').length,1);assert.equal(nodes.find(n=>n.kind==='line').points[1][1],2);
 vm.runInContext('draft.push([0,1])',context);windowEvents['pdg-solar-mode']();flush();assert.equal(nodes.filter(n=>n.kind==='line').length,2);assert.equal(vm.runInContext('draft.length',context),2);
 events.mouseout();flush();assert.equal(nodes.length,0);
 bodyClasses.add('mobile-cad');context.window.PDGSolarPreciseMode=true;windowEvents['pdg-solar-mode']();flush();assert.equal(nodes.find(n=>n.kind==='line').points[1][0],3);center={lat:6,lng:7};events.move();flush();assert.equal(nodes.find(n=>n.kind==='line').points[1][1],7);
 bodyClasses.add('cad-mode');windowEvents['pdg-solar-mode']();flush();assert.equal(nodes.length,0);assert(!hostClasses.has('roof-tracing'));
 bodyClasses.delete('cad-mode');vm.runInContext("mode='idle'",context);windowEvents['pdg-solar-mode']();flush();assert.equal(nodes.length,0);assert.equal(vm.runInContext('draft.length',context),2);
 console.log('PASS: crosshair, cursor-following dashed edge, closure preview, unchanged contour, mobile centre targeting and guide cleanup');
}
