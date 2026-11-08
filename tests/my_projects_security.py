import http.cookiejar,json,urllib.request,urllib.error
BASE='http://127.0.0.1:8199/'
class Policy(http.cookiejar.DefaultCookiePolicy):
 def return_ok_secure(self,c,r):return True
def client():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar(policy=Policy())))
def req(c,path,data=None,csrf=None):
 h={}
 if data is not None:h['Content-Type']='application/json'
 if csrf:h['X-CSRF-Token']=csrf
 r=urllib.request.Request(BASE+path,data=json.dumps(data).encode() if data is not None else None,headers=h,method='POST' if data is not None else 'GET')
 try:
  with c.open(r,timeout=15) as x:return x.status,json.load(x)
 except urllib.error.HTTPError as x:return x.code,json.load(x)
def check(x,m):
 if not x:raise AssertionError(m)
def login(email,password):
 c=client();_,s=req(c,'api/account-draft.php?action=session');code,_=req(c,'api/account-draft.php?action=login',{'email':email,'password':password},s['csrf']);check(code==200,'login '+email);_,s=req(c,'api/account-draft.php?action=session');return c,s['csrf']
a,ac=login('admin@example.test','Admin-test-123456');u,uc=login('customer@example.test','Customer-test-123456')
check(req(client(),'api/my-projects.php')[0]==401,'guest list rejected')
check(req(u,'api/project-requests.php')[0]==403,'customer cannot see admin requests')
check(req(u,'api/my-projects-demo.php',{},uc)[0]==403,'customer cannot create demo identities')
check(req(a,'api/my-projects-demo.php',{})[0]==403,'demo CSRF enforced')
code,demo=req(a,'api/my-projects-demo.php',{},ac);check(code==200 and demo['role']=='customer','demo customer creation')
b,bc=login(demo['email'],demo['password']);code,listing=req(b,'api/my-projects.php');check(code==200 and len(listing['projects'])==2,'demo examples both CADs')
solar=next(p for p in listing['projects'] if p['editor']=='solar');electric=next(p for p in listing['projects'] if p['editor']=='electric')
_,electricdata=req(b,'api/my-projects.php?id='+str(electric['id']));open('/tmp/pdg-demo-electric.json','w').write(json.dumps(electricdata['project']))
check(req(b,'api/admin-users.php')[0]==403,'demo is not administrator')
_,demo_session=req(b,'api/account-draft.php?action=session');_,admin_session=req(a,'api/account-draft.php?action=session')
check(demo_session['user']['id']!=admin_session['user']['id'],'demo and administrator are distinct database users')
for endpoint in ['api/cad-projects.php','api/admin-products-draft.php','api/project-requests.php']:check(req(b,endpoint)[0]==403,'demo cannot access '+endpoint)
import re
for c,uid in [(a,admin_session['user']['id']),(b,demo_session['user']['id'])]:
 with c.open(BASE+'roof-cad.php',timeout=15) as response:check(('data-account-user="'+str(uid)+'"') in response.read().decode(),'server renders the correct local-storage owner')
check(req(u,'api/my-projects.php?id='+str(solar['id']))[0]==404,'other user cannot read project')
check(req(u,'api/my-projects.php?id='+str(solar['id'])+'&view=history')[0]==404,'other user cannot read history')
for action in ['save','archive','restore','duplicate','request']:
 code,_=req(u,'api/my-projects.php',{'action':action,'id':solar['id'],'revision':1,'archived':True},uc);check(code==404,'owner enforcement '+action)
check(req(u,'api/my-projects.php')[1]['projects']==[],'owner list isolated')
p={'version':1,'planes':[],'projectName':'Личен тест','accountPrices':{'products':{'158':1541}}}
v={'action':'save','editor':'solar','name':'Личен тест','project':p}
check(req(u,'api/my-projects.php',v)[0]==403,'save CSRF required')
code,d=req(u,'api/my-projects.php',v,uc);check(code==200 and d['meta']['revision']==1,'new private project saved');pid=d['meta']['id']
check(req(a,'api/my-projects.php?id='+str(pid))[0]==404,'even admin personal API is owner scoped')
v.update(id=pid,revision=1);p['projectName']='Редакция';code,d=req(u,'api/my-projects.php',v,uc);check(code==200 and d['meta']['revision']==2,'update revision')
check(req(u,'api/my-projects.php',v,uc)[0]==409,'stale tab blocked')
_,history=req(u,'api/my-projects.php?id='+str(pid)+'&view=history');check(len(history['versions'])==2,'versions retained')
code,d=req(u,'api/my-projects.php',{'action':'restore','id':pid,'revision':2,'version':1},uc);check(code==200 and d['meta']['revision']==3,'restore creates new revision')
_,saved=req(u,'api/my-projects.php?id='+str(pid));check(saved['project']['accountPrices']['products']['158']==1541,'saved prices survive')
code,d=req(u,'api/my-projects.php',{'action':'duplicate','id':pid,'revision':3},uc);check(code==200 and d['meta']['id']!=pid and d['meta']['revision']==1,'duplicate independent identity')
code,d=req(u,'api/my-projects.php',{'action':'archive','id':pid,'revision':3,'archived':True},uc);check(code==200 and d['meta']['archived'],'archive')
check(all(p['id']!=pid for p in req(u,'api/my-projects.php')[1]['projects']),'archived hidden from active')
check(any(p['id']==pid for p in req(u,'api/my-projects.php?archived=1')[1]['projects']),'archive recoverable')
code,d=req(u,'api/my-projects.php',{'action':'archive','id':pid,'revision':4,'archived':False},uc);check(code==200,'recover archive')
code,d=req(u,'api/my-projects.php',{'action':'request','id':pid,'revision':5},uc);check(code==200 and d['meta']['status']=='review','submit frozen revision')
code,requests=req(a,'api/project-requests.php');check(code==200 and len(requests['requests'])==1,'admin sees request');rid=int(requests['requests'][0]['id'])
code,frozen=req(a,'api/project-requests.php?id='+str(rid));check(code==200 and frozen['project']['accountPrices']['products']['158']==1541,'submitted snapshot retrievable')
check(req(a,'api/project-requests.php',{'id':rid,'status':'completed'})[0]==403,'admin status CSRF')
check(req(a,'api/project-requests.php',{'id':rid,'status':'offer_sent'},ac)[0]==200,'admin updates request status')
check(req(u,'api/my-projects.php?id='+str(pid))[1]['meta']['status']=='offer_sent','customer sees offer status')
v.update(revision=6);p['projectName']='След заявката';check(req(u,'api/my-projects.php',v,uc)[0]==200,'customer edits after submission')
check(req(a,'api/project-requests.php',{'id':rid,'status':'completed'},ac)[0]==200,'finish older submission')
check(req(u,'api/my-projects.php?id='+str(pid))[1]['meta']['status']=='draft','older request cannot approve later edits')
check(req(u,'api/project-requests.php?id='+str(rid))[0]==403,'request detail private to admin')
check(req(u,'api/cad-projects.php',v,uc)[0]==410,'legacy write cannot bypass revision checks')
check(req(u,'api/my-projects.php',{'action':'save','editor':'solar','name':'bad','project':{'version':1,'planes':[{}]}},uc)[0]==422,'malformed project blocked')
check(req(u,'api/my-projects.php',{'action':'save','editor':'solar','name':'big','project':{'version':1,'planes':[],'x':'x'*1048576}},uc)[0]==413,'payload cap')
print('PASS: isolated owners, guest/admin/customer authorization, CSRF, demo login, both editors, revision conflicts, histories, duplicate, archive/recover, preserved prices, frozen requests and protected legacy writes')
