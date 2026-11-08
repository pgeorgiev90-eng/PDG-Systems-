(function(root){'use strict';
const norm=s=>String(s).toLowerCase().normalize('NFKC').replace(/[()[\]_.:–—-]+/g,' ').replace(/\s+/g,' ').trim();
function specification(fields,core){
 const base=core.electrical(fields),find=test=>fields.find(f=>test(norm(f.label))),number=(f,unit='')=>{if(!f)return null;const m=String(f.value).replace(',','.').trim().match(/^(\d+(?:\.\d+)?)\s*(kwp?|квт|wp?|вт|a|а|v|в)?$/i);return m?Number(m[1])*(unit==='W'&&/^(kwp?|квт)$/i.test(m[2]||'')?1000:1):null;};
 const pv=fields.find(f=>norm(f.label)==='допустима мощност на pv масива w')||find(l=>/^(препоръчителна максимална pv мощност|макс dc входна мощност w|макс pv входна мощност w|макс pv мощност на достъп w|максимална входна мощност на фотоволтаичния панел w|maximum pv input power w|max pv input power w|max recommended pv array power)$/.test(l));
 const layout=fields.find(f=>norm(f.label)==='mppt / стрингове на mppt')||find(l=>/mpp?t.*стрингове на mpp?t|брой mpp?t тракери\/.*(?:низ|струн|strings)|number of mppt.*strings/.test(l));
 let inputs=null,mppt=base.mpptCount;const raw=String(layout?.value||'').replace(/\s/g,'').replace(/\(/g,'/').replace(/\)/g,'').replace(/\/+/g,'/');
 let m=raw.match(/^(\d+)[/(,]([\d+/]+)\)?$/);if(m){const n=Number(m[1]),a=m[2].split(/[+/]/).map(Number);if(!mppt)mppt=n;if(mppt===n&&a.every(x=>Number.isInteger(x)&&x>0)){if(a.length===n)inputs=a;else if(a.length===1)inputs=Array(n).fill(a[0]);}}
 const vector=f=>{if(!f||!mppt)return null;const t=String(f.value).replace(/\s/g,'').replace(/d\.c\.|A|А/gi,'').replace(/,/g,'.');if(!/^\d+(\.\d+)?([+/]\d+(\.\d+)?)*$/.test(t))return null;const a=t.split(/[+/]/).map(Number);if(a.length===1&&/на mppt|per mppt/.test(norm(f.label)))return Array(mppt).fill(a[0]);return a.length===mppt&&a.every(x=>x>0)?a:null;};
 const currents=vector(fields.find(f=>norm(f.label)==='максимален входен ток на mppt')||find(l=>/^(максимален входен ток на mppt|макс работен pv входен ток a|max input current per mppt|максимален работен входен ток на фотоволтаичния панел a)$/.test(l)));
 const shortCurrents=vector(fields.find(f=>norm(f.label)==='максимален ток на късо съединение на mppt')||find(l=>/^(макс входен ток на късо съединение a|максимален входен ток на късо съединение a|максимален ток на късо съединение на mppt|максимален ток на късо съединение|maximum short circuit current per mppt)$/.test(l)));
 return {...base,mpptCount:mppt,pvPowerW:number(pv,'W'),conversionPowerW:number(find(l=>l==='максимална pv мощност за преобразуване w'),'W'),inputs,currents,shortCurrents,unverified:find(l=>l==='cad проверка на инвертора')?.value==='непотвърден',note:find(l=>l==='бележка за версията на инвертора')?.value||''};
}
function assess(planes,spec,temperature,core,quantity=1){
 const errors=[],missing=[],active=planes.filter(r=>r.panels?.length),watts=active.reduce((s,r)=>s+r.panels.reduce((n,p)=>n+Number(p.power),0),0),groups=new Set();
 if(!active.length)return {status:'empty',errors:['Първо поставете панели.'],missing:[],watts:0,requiredMppt:0,allocation:[]};
 for(const roof of active){if(!Number.isFinite(roof.azimuth)||!Number.isFinite(roof.moduleTilt??roof.tilt))missing.push('изложение/наклон');for(const p of roof.panels)groups.add([roof.azimuth,roof.moduleTilt??roof.tilt,p.model||'custom',p.power].join('|'));}
 if(spec.unverified)missing.push('официална спецификация на точната хардуерна версия');
 if(spec.conversionPowerW&&watts>spec.conversionPowerW+.01)errors.push('PV мощност '+(watts/1000)+' kWp надвишава максималната PV входна мощност за преобразуване '+(spec.conversionPowerW/1000)+' kW.');
 if(quantity!==1)missing.push('разпределение между отделните инвертори — автоматичният подбор е за един инвертор');
 if(!spec.mpptCount)missing.push('брой MPPT');else if(groups.size>spec.mpptCount)errors.push(groups.size+' независими групи по изложение/наклон/модел изискват поне '+groups.size+' MPPT; моделът има '+spec.mpptCount+'.');
 if(!spec.pvPowerW)missing.push('максимална PV мощност');else if(watts>spec.pvPowerW+.01)errors.push('PV мощност '+(watts/1000)+' kWp надвишава '+(spec.pvPowerW/1000)+' kWp.');
 if(!spec.inputs)missing.push('стрингови входове за всеки MPPT');
 if(!spec.currents)missing.push('работен ток за всеки MPPT');
 if(!spec.shortCurrents)missing.push('ток на късо съединение за всеки MPPT');
 if(!spec.mpptMinVoltage||!spec.mpptMaxVoltage)missing.push('MPPT диапазон на напрежението');
 const plan=core.stringPlan(planes,spec,temperature),buckets=new Map();
 if(!plan.valid)missing.push(...plan.errors);
 for(const row of plan.rows){const roof=planes[row.roof-1],sample=roof.panels.find(p=>(p.model||'custom')===row.model&&p.power===row.power);for(const length of row.strings){
  if(!sample||![sample.vmp,sample.imp,sample.isc].every(v=>Number.isFinite(v)&&v>0)){missing.push('Vmp/Imp/Isc на панелите');continue;}
  const v=length*sample.vmp;if(spec.mpptMinVoltage&&spec.mpptMaxVoltage&&(v<spec.mpptMinVoltage||v>spec.mpptMaxVoltage))errors.push('Скат '+row.roof+': Vmp '+v.toFixed(1)+' V е извън MPPT диапазона.');
  const key=[roof.azimuth,roof.moduleTilt??roof.tilt,row.model,row.power,length].join('|'),b=buckets.get(key)||{key,length,count:0,imp:sample.imp,isc:sample.isc,roofs:new Set()};b.count++;b.roofs.add(row.roof);buckets.set(key,b);
 }}
 let allocation=[];if(!errors.length&&!missing.length){
  const jobs=[...buckets.values()].sort((a,b)=>b.imp*b.count-a.imp*a.count),used=Array(spec.mpptCount).fill(false);let steps=0;
  function assign(index){if(++steps>50000)return false;if(index===jobs.length)return true;const b=jobs[index];
   function distribute(remaining,start){if(!remaining)return assign(index+1);for(let t=start;t<used.length;t++){if(used[t])continue;const cap=Math.min(spec.inputs[t],Math.floor((spec.currents[t]+1e-9)/b.imp),Math.floor((spec.shortCurrents[t]+1e-9)/b.isc),remaining);for(let n=cap;n>=1;n--){used[t]=true;allocation.push({mppt:t+1,strings:n,panelsPerString:b.length,roofs:[...b.roofs],imp:n*b.imp,isc:n*b.isc});if(distribute(remaining-n,t+1))return true;allocation.pop();used[t]=false;}}return false;}
   return distribute(b.count,0);
  }
  if(!assign(0)){allocation=[];if(steps>50000)missing.push('сложно разпределение — необходима е ръчна проверка');else errors.push('Стринговете не се побират в независимите MPPT, входове или токови ограничения.');}
 }
 return {status:errors.length?'incompatible':missing.length?'unknown':'candidate',errors:[...new Set(errors)],missing:[...new Set(missing)],watts,requiredMppt:groups.size,allocation,plan};
}
const exported={specification,assess};if(typeof module==='object'&&module.exports){module.exports=exported;return;}root.PDGSolarInverterMatch=exported;
function init(){
 const core=root.PDGSolarOfferCore,editor=root.PDGSolarEditor,$=id=>document.getElementById(id),select=$('offer-inverter');if(!core||!editor||!select)return;
 const panel=document.createElement('section');panel.id='inverter-fit';panel.innerHTML='<h3>Подбор по панели и MPPT</h3><p id="inverter-fit-status" role="status"></p><p id="inverter-fit-selected"></p><p>Предварителна проверка по каталог: обща PV мощност, независими MPPT, еднакви паралелни стрингове, входове, токове и MPPT напрежение при STC. Температурният MPPT диапазон, допустимата мощност на отделен тракер, засенчването и специалните изисквания в ръководството се потвърждават преди изпълнение.</p>';select.after(panel);
 const cache=new Map();let loading=false,timer=null;const originalLabels=new Map();
 function evaluate(id){return assess(editor.project().planes,cache.get(id)||{},$('offer-minTemperature').value,core,Number($('offer-inverterQty').value));}
 function paint(){const all=[...select.options].filter(o=>o.value),known=all.filter(o=>cache.has(o.value));let candidates=0,unknown=0,excluded=0;
  for(const option of all){if(!originalLabels.has(option.value))originalLabels.set(option.value,option.textContent);const result=evaluate(option.value),empty=result.status==='empty';if(result.status==='candidate')candidates++;else if(result.status==='unknown')unknown++;else if(result.status==='incompatible')excluded++;
   option.disabled=!empty&&result.status!=='candidate';option.textContent=originalLabels.get(option.value)+(empty?'':result.status==='candidate'?' · Подходящ по наличните проверки':result.status==='unknown'?' · Непълни данни':' · Не съответства');option.title=[...result.errors,...result.missing].join(' ');
  }
  $('inverter-fit-status').textContent=known.length<all.length?'Проверка на характеристиките: '+known.length+' / '+all.length+' модела…':candidates+' предварително подходящи · '+excluded+' несъответстващи · '+unknown+' с непълни данни. Непотвърдените модели не участват в автоматичния подбор.';
  const result=select.value?evaluate(select.value):null;$('inverter-fit-selected').textContent=result?(result.status==='candidate'?'Разпределение: '+result.allocation.map(a=>'MPPT '+a.mppt+': '+a.strings+' × '+a.panelsPerString+' панела (скатове '+a.roofs.join(', ')+')').join('; '):[...result.errors,...result.missing.map(s=>'Липсва проверка: '+s)].join(' ')):'Изберете предварително подходящ модел от списъка.';if(select.value&&cache.get(select.value)?.note)$('inverter-fit-selected').textContent+=' '+cache.get(select.value).note;
 }
 async function load(){if(loading)return;const pending=[...select.options].filter(o=>o.value&&!cache.has(o.value));if(!pending.length){paint();return;}loading=true;let next=0;
  async function worker(){while(next<pending.length){const option=pending[next++];try{const r=await fetch('api/product-details.php?id='+encodeURIComponent(option.value),{cache:'no-store'});if(!r.ok)throw Error();const data=await r.json();cache.set(option.value,specification(data.fields||[],core));}catch{cache.set(option.value,{});}paint();}}
  try{await Promise.all([worker(),worker(),worker()]);}finally{loading=false;paint();}
 }
 function schedule(){clearTimeout(timer);timer=setTimeout(()=>{paint();load();},250);}
 new MutationObserver(()=>{if([...select.options].some(o=>o.value&&!cache.has(o.value))&&!loading)schedule();}).observe(select,{childList:true});
 root.addEventListener('pdg-solar-change',schedule);for(const id of ['offer-inverter','offer-minTemperature','offer-inverterQty'])$(id).addEventListener('change',schedule);
 const generate=$('offer-generate'),previous=generate.onclick;generate.onclick=async function(e){if(select.value){await load();const result=evaluate(select.value);if(result.status!=='candidate'&&result.status!=='empty'){editor.message('Инверторът не е потвърден за проекта: '+[...result.errors,...result.missing].join(' '));paint();return;}}return previous.call(this,e);};
 schedule();
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
})(typeof window==='object'?window:globalThis);
