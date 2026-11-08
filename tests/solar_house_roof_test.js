'use strict';
const assert=require('node:assert/strict'),{houseRoofs,heightAt}=require('../solar-project-start.js'),{validProject}=require('../solar-project-format.js');
const center={lat:42.7,lng:23.32},R=6371008.8,rad=Math.PI/180,xy=p=>[(p[1]-center.lng)*rad*R*Math.cos(center.lat*rad),(p[0]-center.lat)*rad*R];
const area=path=>Math.abs(path.reduce((s,p,i)=>{const a=xy(p),b=xy(path[(i+1)%path.length]);return s+a[0]*b[1]-b[0]*a[1];},0))/2;
for(const count of [1,2,3,4])for(const aspect of [0,90,180,270])for(const tilts of [Array(count).fill(25),[15,30,20,35].slice(0,count)]){
 const roofs=houseRoofs({width:12,depth:8,count,aspect,tilts},center);assert.equal(roofs.length,count);assert(Math.abs(roofs.reduce((s,p)=>s+area(p.path),0)-96)<1e-5);
 const edges=new Map();for(const p of roofs)for(let i=0;i<p.path.length;i++){const a=p.path[i],b=p.path[(i+1)%p.path.length],key=[xy(a).map(v=>v.toFixed(5)).join(','),xy(b).map(v=>v.toFixed(5)).join(',')].sort().join('|');const value=edges.get(key)||[];value.push({p,a,b});edges.set(key,value);}
 const connected=new Set([0]),links=[];
 for(const values of edges.values()){assert(values.length<=2);if(values.length===2){const [a,b]=values;assert(Math.abs(heightAt(a.p,a.a)-heightAt(b.p,a.a))<1e-6);assert(Math.abs(heightAt(a.p,a.b)-heightAt(b.p,a.b))<1e-6);links.push([roofs.indexOf(a.p),roofs.indexOf(b.p)]);}}
 for(let i=0;i<count;i++)for(const [a,b]of links)if(connected.has(a)||connected.has(b)){connected.add(a);connected.add(b);}assert.equal(connected.size,count);
 for(const p of roofs){assert(area(p.path)>0);for(const q of p.path)assert(heightAt(p,q)>=2.7-1e-6);}assert(validProject(JSON.parse(JSON.stringify({version:1,planes:roofs}))));
 if(count===4&&tilts.every(t=>t===25)){assert.deepEqual(roofs.map(p=>p.path.length),[4,4,3,3]);}
}
const bad=houseRoofs({width:12,depth:8,count:4,tilt:25,aspect:180},center);bad[0].roofPlane.east=NaN;assert(!validProject({version:1,planes:bad}));
assert.throws(()=>houseRoofs({width:12,depth:8,count:4,tilt:0,aspect:180},center));
console.log('PASS: connected shed/gable/three-sided/hip roofs, exact footprint partition, common ridge/eave vertices, continuous 3D heights, unequal pitches, orientations and JSON persistence');
