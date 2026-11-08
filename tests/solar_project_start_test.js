'use strict';
const assert=require('node:assert/strict'),{manualRoofs}=require('../solar-project-start.js'),{validProject}=require('../solar-project-format.js');
const at={lat:42.7,lng:23.32},R=6371008.8;
function distance(a,b){const rad=Math.PI/180;return R*Math.hypot((a[0]-b[0])*rad,(a[1]-b[1])*rad*Math.cos(at.lat*rad));}
for(const azimuth of [0,45,90,135,180,225,270,315]){
 const [roof]=manualRoofs([{width:10,length:5,tilt:30,azimuth}],at);
 assert(Math.abs(distance(roof.path[0],roof.path[1])-10)<1e-6);assert(Math.abs(distance(roof.path[1],roof.path[2])-5*Math.cos(Math.PI/6))<1e-6);assert.equal(roof.azimuth,azimuth);assert.equal(roof.tilt,30);
 assert(validProject({version:1,planes:[roof]}));
}
const roofs=manualRoofs([{width:10,length:5,tilt:10,azimuth:180},{width:8,length:4,tilt:25,azimuth:0},{width:6,length:3,tilt:15,azimuth:90},{width:7,length:5,tilt:35,azimuth:270}],at);assert.equal(roofs.length,4);assert(validProject(JSON.parse(JSON.stringify({version:1,planes:roofs}))));
assert.equal(new Set(roofs.map(r=>JSON.stringify(r.path))).size,4);
for(const c of [{width:0,length:5,tilt:10,azimuth:180},{width:10,length:5,tilt:81,azimuth:180},{width:10,length:5,tilt:10,azimuth:360},{width:10,length:5,tilt:'',azimuth:0}])assert.throws(()=>manualRoofs([c],at));
assert.throws(()=>manualRoofs([],at));assert.throws(()=>manualRoofs(Array(21).fill({width:10,length:5,tilt:10,azimuth:180}),at));
console.log('PASS: physical roof dimensions, tilt projection, all cardinal aspects, multiple independent roofs, saved-project validation and invalid input rejection');
