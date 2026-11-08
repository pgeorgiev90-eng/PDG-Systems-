(()=>{'use strict';let actor=Number(document.documentElement.dataset.accountUser)||null,checking=false;
function invalidate(){window.dispatchEvent(new Event('pdg-account-invalidated'));document.documentElement.style.visibility='hidden';location.replace('auth.html?next=my-projects.php');}
async function check(){if(checking)return;checking=true;try{const r=await fetch('api/account-draft.php?action=session',{cache:'no-store',credentials:'same-origin'});if(!r.ok)return;const d=await r.json(),id=Number(d.user?.id)||0;if(actor===null)actor=id;else if(actor!==id)invalidate();}catch{}finally{checking=false;}}
window.addEventListener('storage',e=>{if(e.key!=='pdg-account-active-user')return;try{const id=Number(JSON.parse(e.newValue).id)||0;if(actor!==null&&actor!==id)invalidate();else check();}catch{check();}});window.addEventListener('focus',check);window.addEventListener('pageshow',check);check();
})();
