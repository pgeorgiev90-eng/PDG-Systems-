(function(root){
'use strict';
const types={tile:'Керемиди',metal:'Трапецовидна ламарина',standing:'Фалцова ламарина',flat:'Плосък покрив',ground:'Наземен монтаж'};
const documents={tile:'https://www.enerack.com/uploadfile/downloads/Enerack%20tile%20roof%20solar%20mounting%20system%20installation%20manual.pdf',metal:'https://www.enerack.com/category/downloads/1_5',standing:'https://www.enerack.com/category/downloads/1_5',flat:'https://www.enerack.com/uploadfile/downloads/Enerack%20ballasted%20solar%20mounting%20system%20installation%20manual.pdf',ground:'https://www.enerack.com/uploadfile/downloads/Enerack%20Standard%20Ground%20Mounting%20System%20Installation%20Manual.pdf'};
const defaults={span:1.2,endAllowance:.05,joinGap:.04};
const number=(value,fallback,min,max)=>Number.isFinite(Number(value))&&value!==''&&value!==null&&Number(value)>=min&&Number(value)<=max?Number(value):fallback;
const settings=m=>({span:number(m?.estimate?.span,defaults.span,.2,6),endAllowance:number(m?.estimate?.endAllowance,defaults.endAllowance,.05,.5),joinGap:number(m?.estimate?.joinGap,defaults.joinGap,.005,.1),components:m?.estimate?.components&&typeof m.estimate.components==='object'?m.estimate.components:{}});
function profile(m,roof){
 if(!m||m.brand!=='enerack'||!types[m.roofType])return null;
 const common={mid:['EIC'],end:['FEC3035'],rail:['R52-5000','R52L-5000'],splice:['SFR-01'],cap:['REC']};
 const base={category:'ЛАМАРИНА',roles:{...common},kind:'rail',fixings:0};
 if(m.roofType==='tile'&&m.systemId==='tile')return {...base,category:'КЕРЕМИДА',roles:{...common,foot:['TRH-T06','TRH-T04','TRH-T41','TRH-T03','TRH-T07','TRH-Т16','TRH-Т18','TRH-Т22','TRH-Т29','TRH-F01','TRH-F02'],fixing:['ST6.3x80']},fixings:2};
 if(m.roofType==='metal'&&m.systemId==='lfeet')return {...base,roles:{...common,foot:['TRB-D01'],fixing:['ST6.3x80']},fixings:1};
 if(m.roofType==='metal'&&m.systemId==='hanger')return {...base,roles:{...common,foot:['TRB-D03','TRB-D05']}};
 if(m.roofType==='standing'&&m.systemId==='kliplok')return {...base,roles:{...common,foot:['TRB-C04','TRB-C05'],adapter:['TRB-D12']}};
 if(m.roofType==='ground'&&m.systemId==='ground')return {...base,category:'НАЗЕМЕН',kind:'ground',roles:{rail:['R80-4500'],splice:['SFR-R80'],mid:['EIC'],end:['FEC3035'],foot:['W45-3650'],adapter:['FSR-GRC35'],foundation:['GF1']}};
 if(m.roofType==='flat'&&m.systemId==='tilt'){
  const angle=Number(roof.moduleTilt??roof.tilt);if(angle<15||angle>30)return null;
  return {...base,kind:'tilt',roles:{...common,foot:Math.abs(angle-20)<.01?['FTP-20','ATP-1530']:['ATP-1530'],adapter:['RCB']}};
 }
 // Ballast layouts depend on the manufacturer's project-specific assembly plan.
 return null;
}
const roleLabels={rail:'Релса',splice:'Съединител за релса',cap:'Тапа за релса',mid:'Междинна скоба',end:'Крайна скоба',foot:'Опора / закрепване',fixing:'Винт за закрепване',adapter:'Връзка към опората',foundation:'Земен винт'};
function candidates(p,role,products){const codes=p?.roles[role]||[];return products.filter(x=>String(x.brand).toLowerCase()==='enerack'&&x.category===p.category&&codes.includes(x.code)).sort((a,b)=>codes.indexOf(a.code)-codes.indexOf(b.code)||Number(a.id)-Number(b.id));}
function productFor(p,role,products,options){const list=candidates(p,role,products),id=options.components[role];return id!==undefined?list.find(x=>String(x.id)===String(id))||null:list[0]||null;}
function runs(panels,maxGap=.04){
 if(!panels.length)return [];
 const lat=panels.reduce((s,p)=>s+p.lat,0)/panels.length,lng=panels.reduce((s,p)=>s+p.lng,0)/panels.length,R=6371008.8,cos=Math.cos(lat*Math.PI/180),groups=new Map();
 for(const panel of panels){if(![panel.lat,panel.lng,panel.w,panel.h,panel.rotation].every(Number.isFinite)||panel.w<=0||panel.h<=0)throw Error('Невалидни размери или координати на панел.');
  const angle=((panel.rotation%180)+180)%180,key=Math.round(angle*100)/100,theta=key*Math.PI/180,e=(panel.lng-lng)*Math.PI/180*R*cos,n=(panel.lat-lat)*Math.PI/180*R,x=e*Math.cos(theta)-n*Math.sin(theta),y=e*Math.sin(theta)+n*Math.cos(theta),items=groups.get(key)||[];items.push({x,y,w:panel.w,h:panel.h,angle:key});groups.set(key,items);
 }
 const result=[];
 for(const items of groups.values()){
  items.sort((a,b)=>a.y-b.y||a.x-b.x);const rows=[];
  for(const p of items){let row=rows.find(row=>Math.abs(row.y-p.y)<=.05&&Math.abs(row.h-p.h)<.015);if(!row){row={y:p.y,h:p.h,items:[]};rows.push(row)}row.items.push(p)}
  for(const row of rows){row.items.sort((a,b)=>a.x-b.x);let run=null;
   for(const p of row.items){const left=p.x-p.w/2,right=p.x+p.w/2,gap=run?left-run.end:Infinity;
    if(!run||gap>maxGap+.001||gap<-.015){run={count:1,start:left,end:right,angle:p.angle,y:row.y,height:p.h};result.push(run)}else{run.count++;run.end=right}
   }
  }
 }
 return result.map(r=>({...r,length:r.end-r.start}));
}
// End allowance must not add a rail joint when the panel span already fits.
function railExtent(span,stock,allowance){
 const tolerance=.0001,needed=stock>0?Math.max(1,Math.ceil((span-tolerance)/stock)):0;
 const length=stock>0?Math.min(span+2*allowance,needed*stock):span+2*allowance;
 return {length,allowance:Math.max(0,(length-span)/2)};
}
// First-fit decreasing cutting plan. Reuse offcuts within a roof; reserve 3 mm per cut.
function cutRails(lengths,stock){
 if(!(stock>0))return {bars:0,splices:0,cuts:[]};
 const pieces=[];let splices=0;
 for(const length of lengths){const n=Math.max(1,Math.ceil((length-.0001)/stock));splices+=n-1;for(let i=0;i<n-1;i++)pieces.push(stock);pieces.push(Math.min(stock,length-(n-1)*stock))}
 pieces.sort((a,b)=>b-a);const bins=[];
 for(const piece of pieces){let bin=bins.find(b=>b.remaining+1e-7>=piece);if(!bin){bin={remaining:stock,pieces:[]};bins.push(bin)}bin.pieces.push(piece);bin.remaining=Math.max(0,bin.remaining-piece-.003)}
 return {bars:bins.length,splices,cuts:bins.map(b=>({pieces:b.pieces,remainder:b.remaining}))};
}
function calculate(planes,products=[]){
 const roofs=[],aggregate=new Map();
 for(let index=0;index<planes.length;index++){
  const roof=planes[index],mount=roof.mounting,opts=settings(mount),segments=runs(roof.panels||[],opts.joinGap),p=profile(mount,roof),rows=[],notes=[],pending=[];
  const info={number:index+1,type:mount?.roofType||'',label:types[mount?.roofType]||'Избери тип покрив',panelCount:(roof.panels||[]).length,runs:segments.length,railMetres:0,supports:0,rows,notes,pending,settings:opts};roofs.push(info);
  if(!info.panelCount)continue;
  if(!p){pending.push(!mount?'Избери вид покрив и запази конструкцията за ската.':mount.brand!=='enerack'?'За тази система няма комплектовка с артикули от магазина.':mount.roofType==='flat'&&mount.systemId==='ballast'?'Крака, тави и баласт: необходима е монтажна схема за конкретната баластна система.':mount.roofType==='flat'?'За триподите избери наклон на панелите между 15° и 30°.':'Няма комплектовка за избраната система.');continue;}
  const chosen={};for(const role of Object.keys(p.roles))chosen[role]=productFor(p,role,products,opts);
  const stock=chosen.rail?Number(String(chosen.rail.code).match(/-(\d+)$/)?.[1])/1000:0;
  let railBars=0,splices=0,ends=0,mids=0,supports=0;const lengths=[];
  info.railRuns=[];
  for(const run of segments){const extent=railExtent(run.length,stock,opts.endAllowance),length=extent.length;info.railRuns.push({...run,railLength:length,allowance:extent.allowance});info.railMetres+=2*length;ends+=4;mids+=2*(run.count-1);
   lengths.push(length,length);
   const stations=Math.max(2,Math.ceil((run.length-1e-7)/opts.span)+1);supports+=p.kind==='rail'?2*stations:stations;
  }
  const cutting=cutRails(lengths,stock);railBars=cutting.bars;splices=cutting.splices;info.cutting=cutting;
  info.railMetres=Math.round(info.railMetres*1000)/1000;info.supports=supports;
  const quantities={rail:railBars,splice:splices,cap:4*segments.length,mid:mids,end:ends,foot:supports,fixing:p.fixings*supports,adapter:p.kind==='rail'?supports:2*supports,foundation:2*supports};
  for(const role of Object.keys(p.roles)){
   const qty=quantities[role],product=chosen[role];if(qty===0&&!(role==='rail'&&!stock))continue;
   if(!product){pending.push('Няма активен артикул за: '+roleLabels[role]+'.');continue;}
   const row={id:String(product.id),code:product.code,name:product.name,qty,unit:'бр.',price:product.price??'',role,roofs:[index+1]};rows.push(row);
   const key=row.id,existing=aggregate.get(key);if(existing){existing.qty+=row.qty;if(!existing.roofs.includes(index+1))existing.roofs.push(index+1)}else aggregate.set(key,{...row,roofs:[index+1]});
  }
  notes.push('2 релси на непрекъснат ред; до '+opts.endAllowance+' m запас във всеки край, ограничен до наличната дължина на профила. Връзки се добавят само когато панелите с междините надвишават една цяла релса. Отрезите се използват повторно в същия скат с резерв 3 mm за срез.');
  notes.push('Опорите са по разчетна стъпка '+opts.span+' m; реалните носещи точки и допустимите разстояния се потвърждават по монтажната схема.');
  if(p.kind==='ground')pending.push('Диагонали, свързващ крепеж и фундаменти се уточняват по проекта на наземната конструкция.');
  if(p.kind==='tilt')pending.push('Закрепването на триподите към основата се уточнява по монтажна схема.');
 }
 return {roofs,rows:[...aggregate.values()],pending:roofs.flatMap(r=>r.pending.map(message=>'Скат '+r.number+': '+message)),railMetres:Math.round(roofs.reduce((s,r)=>s+r.railMetres,0)*1000)/1000};
}
function addCosts(costs,result,price){
 const rows=costs.rows.filter(r=>r.name!=='Монтажна конструкция');
 for(const r of result.rows){const unit=price(r.price);rows.push({name:'Конструкция · '+r.code+' · '+r.name+' ('+r.unit+')',qty:r.qty,unit,total:unit===null?null:Math.round(unit*r.qty)})}
 for(const name of result.pending)rows.push({name:'Конструкция · '+name,qty:1,unit:null,total:null});
 const subtotal=rows.reduce((sum,r)=>sum+(r.total||0),0),vat=Math.round(subtotal*.2);return {rows,complete:rows.every(r=>r.total!==null),subtotal,vat,total:subtotal+vat};
}
const api={types,documents,defaults,settings,profile,candidates,runs,railExtent,cutRails,calculate,addCosts,roleLabels};if(typeof module==='object'&&module.exports)module.exports=api;else root.PDGSolarMountingCore=api;
})(typeof window==='object'?window:globalThis);

