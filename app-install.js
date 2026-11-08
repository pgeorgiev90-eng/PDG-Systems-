(()=>{'use strict';
let promptEvent=null,installed=window.matchMedia?.('(display-mode: standalone)').matches||navigator.standalone===true,offer,installButton;
const ios=/iPhone|iPad|iPod/i.test(navigator.userAgent)||(navigator.platform==='MacIntel'&&navigator.maxTouchPoints>1);
const make=(tag,text)=>{const e=document.createElement(tag);if(text!==undefined)e.textContent=text;return e;};
function hideInstalled(){installed=true;offer?.remove();document.querySelectorAll('[data-pdg-app-install]').forEach(e=>e.hidden=true);}
window.addEventListener('beforeinstallprompt',e=>{e.preventDefault();promptEvent=e;if(installButton)installButton.textContent='Инсталирай приложението';});
window.addEventListener('appinstalled',hideInstalled);
function guide(){const dialog=make('dialog');dialog.className='pdg-app-guide';dialog.setAttribute('aria-label','Инсталиране на PDG Systems');const title=make('h2','PDG Systems на вашия телефон'),intro=make('p','Приложението използва същия профил и проекти като мобилния сайт.'),list=make('ol');const steps=ios?['Отворете тази страница в Safari.','Натиснете „Сподели“ от менюто на Safari.','Изберете „Добави към Начален екран“.','Включете „Отвори като уеб приложение“, ако е показано, и натиснете „Добави“.']:['Отворете сайта в Chrome на Android.','От менюто ⋮ изберете „Инсталиране на приложението“ или „Добавяне към началния екран“.','Потвърдете инсталирането. Иконата PDG Systems ще се появи на телефона.'];for(const step of steps)list.append(make('li',step));if(!ios)list.append(make('li','На iPhone: Safari → Сподели → Добави към Начален екран.'));const close=make('button','Разбрах');close.type='button';close.onclick=()=>dialog.close();dialog.append(title,intro,list,close);dialog.addEventListener('close',()=>dialog.remove());document.body.append(dialog);dialog.showModal();}
async function install(){if(installed)return;if(!promptEvent){guide();return;}const event=promptEvent;promptEvent=null;try{await event.prompt();const result=await event.userChoice;if(result.outcome==='accepted')hideInstalled();}catch{guide();}}
function init(){
 if('serviceWorker' in navigator&&location.protocol==='https:')navigator.serviceWorker.register('/pdg-app-sw.js',{scope:'/'}).catch(()=>{});
 if(installed)return;
 const host=document.querySelector('main')||document.body,open=make('a','Приложение за Android / iPhone');open.className='pdg-app-open';open.href='/app-download.html';open.dataset.pdgAppInstall='';host.prepend(open);
 let dismissed=false;try{dismissed=Date.now()-Number(localStorage.getItem('pdg-app-offer-dismissed')||0)<7*86400000;}catch{}
 if(dismissed&&!document.body.hasAttribute('data-app-download'))return;
 offer=make('section');offer.className='pdg-app-offer';offer.setAttribute('aria-label','Инсталирайте приложението PDG Systems');const icon=make('img');icon.src='/assets/pdg-app-192.png';icon.alt='';const copy=make('div');copy.className='pdg-app-copy';copy.append(make('strong','PDG Systems на вашия телефон'),make('p','Проектите, CAD инструментите и магазинът — с икона на началния екран.'));installButton=make('button',ios?'Добави на iPhone':'Инсталирай приложението');installButton.type='button';installButton.className='pdg-app-install';installButton.onclick=install;const later=make('button','По-късно');later.type='button';later.className='pdg-app-later';later.onclick=()=>{offer.remove();try{localStorage.setItem('pdg-app-offer-dismissed',String(Date.now()));}catch{}};offer.append(icon,copy,installButton,later);open.after(offer);
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});else init();
})();
