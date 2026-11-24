'use strict';
const assert=require('node:assert/strict'),{currentPosition,addressSearchUrl}=require('../solar-location.js');
(async()=>{
 assert.equal(addressSearchUrl('цело роза ямбол').searchParams.get('q'),'роза ямбол');
 assert.equal(addressSearchUrl('село Роза, Ямбол').searchParams.get('q'),'Роза, Ямбол');
 assert.equal(addressSearchUrl('София, бул. Витоша 1').searchParams.get('street'),'1 бул. Витоша');
 let options;
 const p=await currentPosition({getCurrentPosition(ok,fail,o){options=o;ok({coords:{latitude:42.6977,longitude:23.3219,accuracy:12}});}});
 assert.deepEqual(p,{lat:42.6977,lng:23.3219,accuracy:12});assert.equal(options.enableHighAccuracy,true);assert.equal(options.timeout,18000);
 for(const code of [1,2,3])await assert.rejects(currentPosition({getCurrentPosition(ok,fail){fail({code});}}),Error);
 await assert.rejects(currentPosition(undefined),/не поддържа/);
 for(const coords of [{latitude:NaN,longitude:0,accuracy:1},{latitude:91,longitude:0,accuracy:1},{latitude:0,longitude:181,accuracy:1},{latitude:0,longitude:0,accuracy:-1}])await assert.rejects(currentPosition({getCurrentPosition(ok){ok({coords});}}),/невалидно/);
 console.log('PASS: GPS coordinates and accuracy, explicit one-shot request, permission denied, unavailable/timeout, unsupported browser and invalid coordinates');
})().catch(error=>{console.error(error);process.exitCode=1;});
