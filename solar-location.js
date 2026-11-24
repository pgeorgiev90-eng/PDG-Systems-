(function(root){
 'use strict';
 function currentPosition(geolocation){
  return new Promise((resolve,reject)=>{
   if(!geolocation||typeof geolocation.getCurrentPosition!=='function'){reject(new Error('Този браузър не поддържа определяне на местоположение. Потърси адрес от „Настройки“.'));return;}
   geolocation.getCurrentPosition(position=>{
    const c=position?.coords;
    if(!c||![c.latitude,c.longitude,c.accuracy].every(Number.isFinite)||Math.abs(c.latitude)>90||Math.abs(c.longitude)>180||c.accuracy<0){reject(new Error('Телефонът върна невалидно местоположение. Опитай отново.'));return;}
    resolve({lat:c.latitude,lng:c.longitude,accuracy:c.accuracy});
   },error=>{
    const messages={1:'Достъпът до местоположението е отказан. Разреши го за този сайт от настройките на браузъра или потърси адрес.',2:'Местоположението не е достъпно. Провери дали услугите за местоположение на телефона са включени и опитай отново.',3:'Телефонът не успя да определи местоположението навреме. Опитай отново или потърси адрес.'};
    reject(new Error(messages[error?.code]||'Местоположението не може да се определи. Опитай отново или потърси адрес.'));
   },{enableHighAccuracy:true,timeout:18000,maximumAge:30000});
  });
 }

 function addressSearchUrl(query){
  const url=new URL('https://nominatim.openstreetmap.org/search');
  const params=url.searchParams;
  params.set('format','jsonv2');
  params.set('limit','5');
  params.set('accept-language','bg');
  // Nominatim matches Bulgarian villages by name without the locality prefix.
  const villagePrefix=/^(?:[сц]ело|с\.)\s+/iu;
  const parts=query.split(',').map(part=>part.trim()).filter(Boolean);
  const streetIndex=parts.findIndex(part=>/^(?:ул(?:ица)?\.?|бул(?:евард)?\.?)\s+/iu.test(part));
  if(parts.length===2&&streetIndex!==-1){
   const street=parts[streetIndex].replace(/\s+№\s*/u,' ');
   params.set('street',street.replace(/^(.+?)\s+(\d+[\p{L}]?(?:[-/]\d+)?)$/u,'$2 $1'));
   params.set('city',parts[1-streetIndex].replace(villagePrefix,''));
  }else{
   params.set('q',query.trim().replace(villagePrefix,''));
  }
  return url;
 }
 async function searchAddress(query){
  const response=await fetch(addressSearchUrl(query),{
   headers:{Accept:'application/json'},
   signal:AbortSignal.timeout(15000)
  });
  if(!response.ok)throw new Error('Услугата за търсене е временно недостъпна.');
  const results=await response.json();
  if(!Array.isArray(results))throw new Error('Услугата върна невалидни резултати.');
  return results.flatMap(item=>{
   if(!item||item.lat==null||item.lon==null||!item.display_name)return [];
   const lat=Number(item.lat),lng=Number(item.lon);
   return Number.isFinite(lat)&&Math.abs(lat)<=90&&Number.isFinite(lng)&&Math.abs(lng)<=180
    ? [{lat,lng,label:String(item.display_name)}] : [];
  });
 }
 const api={currentPosition,searchAddress,addressSearchUrl};if(typeof module==='object'&&module.exports)module.exports=api;else root.PDGSolarLocation=api;
})(typeof window==='object'?window:globalThis);
