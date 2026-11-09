exec(open('tests/my_projects_security.py').read().split('a,ac=login')[0])
import subprocess,hashlib
admin,ac=login('admin@example.test','Admin-test-123456');user,uc=login('customer@example.test','Customer-test-123456')
other,oc=login('other@example.test','Other-test-123456')
guest=client()
def call(c,data=None,csrf=None,expected=200,path='api/customer-tools.php'):
 code,result=req(c,path,data,csrf);check(code==expected,(path,code,result));return result
call(guest,expected=401);call(user,expected=403,path='api/admin-growth.php');call(guest,expected=401,path='api/admin-growth.php')
ops={'action':'operations','id':1,'cost_cents':800,'min_margin_bp':1000,'stock_quantity':5,'lead_days':2,'availability':'in_stock'}
call(admin,ops,expected=403,path='api/admin-growth.php');call(admin,ops,ac,path='api/admin-growth.php')
for change in [{'cost_cents':-1},{'min_margin_bp':10000},{'stock_quantity':1.2},{'availability':'<script>'},{'stock_quantity':0,'availability':'in_stock'}]:
 call(admin,{**ops,**change},ac,422,'api/admin-growth.php')
items=[{'id':1,'quantity':2}]
preview=call(user,{'action':'preview','items':items},uc);check(preview['can_add'] and preview['subtotal_cents']==2000,'server prices')
check('cost_cents' not in str(preview),'private costs hidden')
call(user,{'action':'add_batch','items':items,'revision':'wrong'},uc,409)
call(user,{'action':'save_list','name':'Private <img>','items':items},expected=403)
saved=call(user,{'action':'save_list','name':'Private <img>','items':items},uc)
check(not call(other)['lists'],'lists isolated');call(other,{'action':'delete_list','id':saved['id']},oc,404)
call(user,{'action':'add_batch','items':items,'revision':preview['revision']},uc)
cart=call(user,path='api/cart.php');check(cart['items'][0]['quantity']==2,'atomic add')
call(user,{'action':'add','id':1,'quantity':4},uc,422,'api/cart.php')
# An unavailable second line must leave the whole cart unchanged.
bad=call(user,{'action':'preview','items':[{'id':1,'quantity':1},{'id':2,'quantity':1}]},uc)
call(user,{'action':'add_batch','items':[{'id':1,'quantity':1},{'id':2,'quantity':1}],'revision':bad['revision']},uc,422)
check(call(user,path='api/cart.php')['items'][0]['quantity']==2,'no partial additions')
# A customer's discount below the configured margin is blocked on the server.
subprocess.run(['php','-r',r'$s=require dirname(getcwd())."/pdg-shop-db.php";$d=new PDO("mysql:host=".$s["host"].";dbname=".$s["database"],$s["username"],$s["password"]);require "api/customer-pricing.php";pdg_discount_schema($d);$d->exec("INSERT INTO pdg_customer_discounts(user_id,discount_bp) SELECT id,3000 FROM pdg_users WHERE email=\"customer@example.test\"");'],check=True)
check(not call(user,path='api/cart.php')['can_checkout'],'discount respects minimum margin')
subprocess.run(['php','-r',r'$s=require dirname(getcwd())."/pdg-shop-db.php";$d=new PDO("mysql:host=".$s["host"].";dbname=".$s["database"],$s["username"],$s["password"]);$d->exec("DELETE FROM pdg_customer_discounts");'],check=True)
cart=call(user,path='api/cart.php')
payload={'name':'Fixture','email':'fixture@example.test','phone':'+359888123456','city':'Sofia','address':'Test address','notes':'Isolated CI','acknowledge':True,'delivery_method':'heavy','checkout_key':cart['checkout_key'],'revision':cart['revision']}
receipt=call(user,payload,uc,path='api/checkout.php');check(receipt['ok'],'checkout')
check(call(user,payload,uc,path='api/checkout.php')==receipt,'idempotent checkout')
orders=call(user)['orders'];check(len(orders)==1 and orders[0]['delivery_method']=='heavy','owner and delivery snapshot');check(not call(other)['orders'],'orders isolated')
op=next(p for p in call(admin,path='api/admin-growth.php')['products'] if p['id']==1);check(int(op['stock_quantity'])==3,'stock decremented once')
call(admin,{'id':int(orders[0]['id']),'status':'cancelled'},ac,path='api/admin-orders.php')
call(admin,{'id':int(orders[0]['id']),'status':'cancelled'},ac,path='api/admin-orders.php')
op=next(p for p in call(admin,path='api/admin-growth.php')['products'] if p['id']==1);check(int(op['stock_quantity'])==5,'cancellation restores once')
bundle={'action':'bundle','name':'Fixture bundle','description':'Fixture','compatibility_note':'Explicit fixture configuration','items':items,'active':True}
call(admin,bundle,ac,422,'api/admin-growth.php');call(admin,{**bundle,'compatibility_confirmed':True},ac,path='api/admin-growth.php')
public=call(guest,path='api/commerce.php');check(len(public['bundles'])==1 and 'cost_cents' not in str(public),'only public fields')
request=call(user,{'action':'request','kind':'service','subject':'Fixture service','details':{'model':'Fixture','serial':'PRIVATE','message':'Test issue'}},uc)
check(not call(other)['requests'],'service requests isolated')
call(user,{'action':'request','id':request['id'],'stage':'completed','admin_reply':'forbidden'},uc,403,'api/admin-growth.php')
call(admin,{'action':'request','id':request['id'],'stage':'confirmed','admin_reply':'Fixture reply'},ac,path='api/admin-growth.php')
check(call(user)['requests'][0]['admin_reply']=='Fixture reply','admin reply returns to owner')
call(user,{'action':'request','kind':'survey','subject':'Survey','details':{'message':'Test'}},uc,422)
call(user,expected=403,path='api/admin-backup.php');call(admin,{},expected=403,path='api/admin-backup.php')
backup=call(admin,{},ac,path='api/admin-backup.php');check(backup['tables']>5 and backup['bytes']>100,'admin backup created')
call(guest,expected=401,path='api/request-files.php?id=1');call(user,expected=404,path='api/request-files.php?id=9999')
call(admin,{'action':'issue','title':'Fixture','priority':'high','stage':'verified','verification':''},ac,422,'api/admin-growth.php')
call(admin,{'action':'issue','title':'Fixture','priority':'high','stage':'verified','verification':'Passed CI'},ac,path='api/admin-growth.php')
print('PASS: private lists/orders/requests, CSRF and roles, batch atomicity, server margin/stock, checkout replay, cancellation inventory, explicit compatibility, delivery, issues and admin backup')
