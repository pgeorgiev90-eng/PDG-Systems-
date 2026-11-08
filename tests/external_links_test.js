'use strict';const assert=require('node:assert/strict'),fs=require('fs');
for(const name of ['roof-cad.php','solar-cad-preview.html']){const s=fs.readFileSync(name,'utf8');assert.ok(s.includes('assets/vendor/leaflet-1.9.4/leaflet.js'));assert.ok(s.includes('assets/vendor/three-0.152.2/three.min.js'));assert.ok(!/https:\/\/(?:unpkg|cdnjs|www\.enerack|www\.aerocompact|jinkosolar|static\.longi)/.test(s));assert.ok(!s.includes('mountManual'));assert.ok(s.includes('nominatim.openstreetmap.org'));assert.ok(s.includes('server.arcgisonline.com'));}
assert.ok(!fs.readFileSync('index.html','utf8').includes('images.unsplash.com'));
assert.ok(!fs.readFileSync('electrical-catalog.js','utf8').includes('sourceUrl'));
assert.ok(fs.readFileSync('electrical-dwg-worker.js','utf8').includes('./assets/vendor/libredwg-0.8.4'));
for(const name of ['legacy-editor.html','maps-designer.html','roof-editor.html']){const s=fs.readFileSync(name,'utf8');assert.ok(s.includes('url=roof-cad.php'));assert.ok(!/https?:\/\//.test(s));}
for(const name of ['database/electrical-catalog-public.json','database/electrical-reference-prices-public.json']){const data=JSON.parse(fs.readFileSync(name));assert.ok(data);assert.ok(!/www\.schrack\.(?:bg|at)/.test(JSON.stringify(data)));}
console.log('PASS: local vendor scripts/photos, no supplier navigation, preserved map services, local DWG decoder, and retired third-party legacy paths');
