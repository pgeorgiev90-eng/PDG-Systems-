exec(open('tests/my_projects_security.py').read().split('a,ac=login')[0])
a,ac=login('admin@example.test','Admin-test-123456');u,uc=login('customer@example.test','Customer-test-123456')
check(req(client(),'api/project-support.php?id=1')[0]==401,'support requires session')
check(req(u,'api/project-support.php?id=1')[0]==403,'support is admin only')
for editor,project in [('electric',{'version':2,'items':[],'busbars':[],'project':'Original'}),('solar',{'version':1,'planes':[],'projectName':'Original'})]:
 name='Support '+editor
 code,d=req(u,'api/my-projects.php',{'action':'save','editor':editor,'name':name,'project':project},uc);check(code==200,'create project');p=d['meta'];pid=p['id']
 check(req(u,'api/my-projects.php',{'action':'share','id':pid,'revision':1,'problem':'x'*4001},uc)[0]==422,'description bounded')
 check(req(a,'api/my-projects.php',{'action':'share','id':pid,'revision':1,'problem':'help'},ac)[0]==404,'sharing owner check')
 check(req(u,'api/my-projects.php',{'action':'share','id':pid,'revision':1,'problem':'Коригирай неточностите <img src=x>'})[0]==403,'sharing CSRF check')
 code,d=req(u,'api/my-projects.php',{'action':'share','id':pid,'revision':1,'problem':'Коригирай неточностите <img src=x>'},uc);check(code==200 and d['meta']['status']=='shared','customer shares with problem')
 requests=req(a,'api/project-requests.php')[1]['requests'];r=next(v for v in requests if int(v['project_id'])==pid);rid=int(r['id']);check(r['request_kind']=='assistance' and 'Коригирай' in r['problem'],'administrator receives problem')
 code,opened=req(a,'api/project-support.php?id='+str(rid));check(code==200 and opened['meta']['id']==pid,'admin opens current owner drawing')
 check(req(u,'api/project-support.php',{'support':rid,'revision':2,'editor':editor,'project':project},uc)[0]==403,'customer cannot use admin save')
 correction={**opened['project'],'corrected':'administrator fix'}
 payload={'support':rid,'revision':2,'editor':editor,'project':correction}
 check(req(a,'api/project-support.php',payload)[0]==403,'admin save CSRF required')
 code,saved=req(a,'api/project-support.php',payload,ac);check(code==200 and saved['meta']['revision']==3,'administrator writes back to original project')
 check(saved['meta']['status']=='admin_edited' and saved['meta']['lastAdminRevision']==3 and saved['meta']['lastAdminAt'],'administrator attribution recorded')
 code,customer=req(u,'api/my-projects.php?id='+str(pid));check(code==200 and customer['project']['corrected']=='administrator fix','customer sees corrections')
 check(customer['meta']['lastAdminRevision']==3,'customer receives attribution')
 check(req(a,'api/my-projects.php?id='+str(pid))[0]==404,'project remains in original owner account')
 check(req(a,'api/project-support.php',payload,ac)[0]==409,'stale administrator cannot overwrite')
 old={'action':'save','id':pid,'revision':2,'editor':editor,'name':name,'project':project};check(req(u,'api/my-projects.php',old,uc)[0]==409,'stale customer cannot overwrite administrator fixes')
 code,history=req(u,'api/my-projects.php?id='+str(pid)+'&view=history');check(code==200 and len(history['versions'])==3,'before and after history retained')
 newer={**old,'revision':3,'project':{**correction,'customer':'new customer edit'}};check(req(u,'api/my-projects.php',newer,uc)[0]==200,'customer continues after correction')
 check(req(a,'api/project-support.php',{**payload,'revision':3},ac)[0]==409,'new customer changes protected')
 check(req(u,'api/my-projects.php',{'action':'delete_selected','projects':[{'id':pid,'revision':4}]},uc)[0]==200,'customer deletion supported')
 check(req(a,'api/project-support.php?id='+str(rid))[0]==404,'deleted project cannot be edited through old support link')
# Existing review requests also open in CAD for administrator correction.
_,d=req(u,'api/my-projects.php',{'action':'save','editor':'solar','name':'Review only','project':{'version':1,'planes':[]}},uc);pid=d['meta']['id']
req(u,'api/my-projects.php',{'action':'request','id':pid,'revision':1},uc)
r=next(v for v in req(a,'api/project-requests.php')[1]['requests'] if int(v['project_id'])==pid)
code,legacy=req(a,'api/project-support.php?id='+str(r['id']));check(code==200,'existing review opens in administrator CAD')
code,edited=req(a,'api/project-support.php',{'support':int(r['id']),'revision':legacy['meta']['revision'],'editor':'solar','project':{**legacy['project'],'legacyFixed':True}},ac);check(code==200,'existing review can be corrected')
check(req(u,'api/my-projects.php?id='+str(pid))[1]['project']['legacyFixed'],'legacy review correction returns to original owner')
check(req(a,'api/project-support.php?id=999999999')[0]==404,'no arbitrary project access through missing request')
print('PASS: both editors, owner sharing, bounded problem text, admin-only explicit save, CSRF, revisions, retained ownership/history, client attribution and deleted/missing request protection and existing review editing')
