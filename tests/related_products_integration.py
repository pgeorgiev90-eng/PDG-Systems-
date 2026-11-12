import json,urllib.request,urllib.error,http.cookiejar
class Policy(http.cookiejar.DefaultCookiePolicy):
    def return_ok_secure(self,cookie,request):return True
def client():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar(policy=Policy())))
def req(c,path,data=None,csrf=None,expected=200):
    h={'Content-Type':'application/json'}
    if csrf:h['X-CSRF-Token']=csrf
    r=urllib.request.Request('http://127.0.0.1:8091/'+path,data=None if data is None else json.dumps(data).encode(),headers=h)
    try:r=c.open(r)
    except urllib.error.HTTPError as e:r=e
    d=json.load(r);assert r.code==expected,(path,r.code,d);return d
public=client();admin=client();customer=client();endpoint='api/admin-related-products.php'
req(public,endpoint+'?product_id=1',expected=401)
d=req(public,'api/related-products.php?id=1');assert d['complements']==[]
ids=[p['id'] for p in d['similar']];assert 1 not in ids and 5 not in ids and 6 not in ids and ids[0]==2
req(public,'api/related-products.php?id=5',expected=404)
req(public,'api/related-products.php?id=-1',expected=422)
for c,email,password in [(admin,'admin@example.test','Admin-test-123456'),(customer,'customer@example.test','Customer-test-123456')]:
    s=req(c,'api/account-draft.php?action=session');req(c,'api/account-draft.php?action=login',dict(email=email,password=password),s['csrf'])
csrf=req(admin,'api/account-draft.php?action=session')['csrf']
req(customer,endpoint+'?product_id=1',expected=403)
d=req(admin,endpoint+'?product_id=1');payload=dict(product_id=1,version=d['version'],verified=True,links=[dict(id=6,note='Проверено допълнение за тестовия модел')])
req(admin,endpoint,payload,'wrong',403)
req(admin,endpoint,{**payload,'verified':False},csrf,422)
req(admin,endpoint,{**payload,'links':[dict(id=1,note='self')]},csrf,422)
req(admin,endpoint,{**payload,'links':[dict(id=5,note='inactive')]},csrf,422)
req(admin,endpoint,{**payload,'links':payload['links']*2},csrf,422)
saved=req(admin,endpoint,payload,csrf)
d=req(public,'api/related-products.php?id=1');assert [p['id'] for p in d['complements']]==[6] and d['complements'][0]['note']==payload['links'][0]['note']
assert d['similar'][0]['id']==2 and d['similar'][0]['price']=='11.00'
req(admin,endpoint,payload,csrf,409)
req(admin,endpoint,{**payload,'version':saved['version'],'links':[]},csrf)
assert req(public,'api/related-products.php?id=1')['complements']==[]
print('PASS: public related cards, category/brand/price ranking, inactive exclusions, admin-only edits, CSRF, verification, self/duplicate checks and concurrent-edit protection')
