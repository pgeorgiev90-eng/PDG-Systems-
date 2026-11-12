import json, subprocess, urllib.request, urllib.error, http.cookiejar
jar=http.cookiejar.CookieJar()
client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
def api(data=None,csrf=None,expected=200):
    headers={'Content-Type':'application/json'}
    if csrf: headers['X-CSRF-Token']=csrf
    req=urllib.request.Request('http://127.0.0.1:8091/api/quick-order.php',data=None if data is None else json.dumps(data).encode(),headers=headers)
    try:r=client.open(req)
    except urllib.error.HTTPError as e:r=e
    result=json.load(r)
    for c in jar:c.secure=False
    assert r.code==expected,(r.code,result)
    return result
s=api(); csrf=s['csrf']
p=dict(request_key=s['request_key'],product_id=1,quantity=2,unit_cents=1000,name='Тест клиент',phone='+359888123456',notes='CI isolated test',acknowledge=True)
api(p,'wrong',403)
for change in [dict(quantity=0),dict(quantity='2'),dict(quantity=1000)]:api({**p,**change},csrf,422)
api({**p,'phone':'abc'},csrf,422)
api({**p,'acknowledge':False},csrf,422)
api({**p,'unit_cents':1},csrf,409)
api({**p,'product_id':2},csrf,409)
r=api(p,csrf,201);assert r['total_cents']==2400
assert api(p,csrf)==r
code='''$s=require dirname(getcwd())."/pdg-shop-db.php";$d=new PDO("mysql:host=".$s["host"].";dbname=".$s["database"],$s["username"],$s["password"]);echo json_encode([$d->query("SELECT * FROM pdg_orders")->fetchAll(PDO::FETCH_ASSOC),$d->query("SELECT * FROM pdg_order_items")->fetchAll(PDO::FETCH_ASSOC),$d->query("SELECT stock_quantity FROM pdg_product_operations WHERE product_id=1")->fetchColumn()]);'''
orders,items,stock=json.loads(subprocess.check_output(['php','-r',code]))
assert len(orders)==len(items)==1
assert orders[0]['email']=='' and orders[0]['phone']==p['phone'] and orders[0]['status']=='new'
assert int(items[0]['quantity'])==2 and int(items[0]['unit_cents'])==1000 and int(stock)==3
s=api();p['request_key']=s['request_key'];p['quantity']=4
api(p,s['csrf'],409)
print('PASS: guest order, CSRF, validation, server price, no-price product, stock, VAT, persistence and duplicate retry')
