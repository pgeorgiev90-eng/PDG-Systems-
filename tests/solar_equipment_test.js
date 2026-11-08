'use strict';
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync('roof-cad.php','utf8');
const products=[
 {id:1,brand:'SolaX',name:'AC charger',code:'EV-1',category:'Зарядни станции',price:'450.80'},
 {id:2,name:'Solar cable',code:'PV-6',category:'Кабели и аксесоари',unit:'m',price:'2.50'},
 {id:3,name:'Connector',code:'CON',category:'Кабели и аксесоари',price:'0'},
 {id:4,name:'Cable clamp',category:'МИНИ РЕЙЛ',price:'1'},
 {id:5,name:'Battery cable',category:'Аксесоари за батерии',price:'3'}
];
const context=vm.createContext({window:{PDGCatalogSections:require('../catalog-sections.js')},catalogue:products,console});
vm.runInContext(source.slice(source.indexOf('let equipment={'),source.indexOf('const accountNamespace=')),context);
const run=code=>vm.runInContext(code,context);
assert.equal(run("equipmentProducts('charger').length"),1);
assert.equal(run("equipmentProducts('cables').length"),2);
assert.equal(run("equipmentUnit(equipmentProduct('cables',2))"),'m');
assert.equal(run("equipmentUnit(equipmentProduct('cables',3))"),'бр.');
run("equipment=cleanEquipment({charger:[{id:'1',name:'Old name',quantity:2,price:'1'}],cables:[{id:'2',name:'Cable',quantity:35.5,unit:'m',price:'1'}]}); const price=v=>v===''?null:Math.round(Number(v)*100);const base=()=>({rows:[],subtotal:0,complete:true});");
assert.equal(run('equipmentCosts(base(),price).subtotal'),99035);
assert.equal(run('equipmentCosts(base(),price).total'),118842);
assert.match(run('equipmentCosts(base(),price).rows[0].name'),/EV-1/);
assert.equal(run('equipmentItem("charger",equipment.charger[0]).name'),'SolaX · AC charger');
run('equipment=cleanEquipment(JSON.parse(JSON.stringify(equipment)))');
assert.equal(run('equipment.cables[0].id'),'2');assert.equal(run('equipment.cables[0].unit'),'m');
products[1].price='3';assert.equal(run('equipmentCosts(base(),price).subtotal'),100810);
products[1].price=null;assert.equal(run('equipmentCosts(base(),price).complete'),false);
products[1].price='0';assert.equal(run('equipmentCosts(base(),price).complete'),true);
products[1].category='МИНИ РЕЙЛ';assert.equal(run('equipmentCosts(base(),price).complete'),false);
assert.equal(run('equipmentProducts("cables").length'),1);
assert.equal(run("cleanEquipment({cables:[{name:'Legacy manual cable',quantity:10,price:'2'}]}).cables[0].unit"),'m');
console.log('PASS: store category matching, product IDs, live/hidden/zero prices, unit and JSON preservation, fractional metres, VAT and removed products');
