const assert=require('node:assert/strict'),fs=require('node:fs');
const rows=JSON.parse(fs.readFileSync('database/solax-catalog-20261112.json','utf8'));
const photos=JSON.parse(fs.readFileSync('database/solax-photos-20261112.json','utf8'));
const sections=require('../catalog-sections.js'),filters=require('../catalog-filter-core.js');
assert.equal(rows.length,155);assert.equal(new Set(rows.map(r=>r.brand+'|'+r.code)).size,155);
assert.equal(rows.filter(r=>r.expectedId).length,71);
for(const row of rows){
 assert.notEqual(sections.sectionOf(row),'other',row.code);
 assert(row.description.length>150,row.code);assert(row.image.startsWith('https://pdgsystems.bg/assets/products/solax-20261112/'));
 assert(photos.some(p=>'https://pdgsystems.bg/'+p.path===row.image),row.code);
 const fields=Object.fromEntries(row.fields.map(f=>[f.label,f.value]));assert.equal(Object.keys(fields).length,row.fields.length,row.code);
 assert(fields['CAD: тип']);assert(fields['CAD: статус']);
 if(row.price!==null){const cost=Number(row.sourceNetEur.replace('.',''));const cents=Number(row.price.replace('.',''));assert.equal(cents,Math.floor((cost*130+50)/100),row.code);}
 const attrs=filters.attributes(row,{specs:fields});
 if(row.category==='Инвертори')assert(attrs.phases&&attrs.power&&attrs.inverter_type,row.code);
 if(row.category==='Батерии')assert(attrs.energy&&attrs.battery_class,row.code);
 if(row.kind==='cable'){assert.equal(attrs.cross_section,'6');assert(attrs.color);assert.equal(fields['Единица за продажба'],'m');}
 if(row.code.includes('NO-AFCI'))assert.equal(fields['CAD: проверка на инвертора'],'непотвърден');
}
assert.equal(rows.filter(r=>r.price===null).length,1);assert.equal(rows.find(r=>r.code==='X1-BOOST-5K-G4').price,'530.40');assert.equal(rows.find(r=>r.code==='MC4-6MM2').price,'1.46');
console.log('PASS: all supplier positions, unique variants, prices, local photos, categories, CAD metadata and filter attributes');
