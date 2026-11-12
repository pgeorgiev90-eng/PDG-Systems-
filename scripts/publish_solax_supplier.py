"""Scoped, backed-up release; activate the transactional database import last."""
import ftplib,hashlib,io,json,os,ssl,subprocess,urllib.request,urllib.error
from pathlib import Path
items=json.loads(Path('database/solax-catalog-20261112.json').read_text())
photos=json.loads(Path('database/solax-photos-20261112.json').read_text())
files=['database/solax-catalog-20261112.json','database/solax-photos-20261112.json','api/solax-catalog-20261112.php','api/products.php']
backup=Path('/tmp/solax-production-backup');backup.mkdir(exist_ok=True)
def get(path):
    with urllib.request.urlopen('https://pdgsystems.bg/'+path+('&' if '?' in path else '?')+'solax_release='+os.environ['GITHUB_SHA'],timeout=90) as response:return response.read()
before=json.loads(get('api/products.php'))
(backup/'catalog-before.json').write_text(json.dumps(before,ensure_ascii=False))
old={p['id']:p for p in before['products']};old_keys={(p['brand'],p['code']):p for p in before['products']}
expected_keys={(r['brand'],r['code']) for r in items}
for row in items:
    existing=old_keys.get((row['brand'],row['code']))
    if row['expectedId'] is not None:assert existing and existing['id']==row['expectedId'],'Identity changed before deployment'
    else:assert existing is None,'New variant already exists before deployment'
previous={};activated=False
with ftplib.FTP_TLS(context=ssl.create_default_context(),timeout=60) as ftp:
    ftp.connect(os.environ['FTP_HOST'],21);ftp.login(os.environ['FTP_USER'],os.environ['FTP_PASSWORD']);ftp.prot_p();ftp.cwd('/')
    for name in files+['database/.htaccess']:
        b=io.BytesIO()
        try:ftp.retrbinary('RETR '+name,b.write);previous[name]=b.getvalue()
        except ftplib.error_perm as exc:
            if not str(exc).startswith('550') or name in ['api/products.php','database/.htaccess']:raise
            previous[name]=None
        if previous[name] is not None:
            path=backup/name;path.parent.mkdir(parents=True,exist_ok=True);path.write_bytes(previous[name])
    prior=subprocess.run(['git','show','HEAD^:api/products.php'],capture_output=True,check=True).stdout
    assert previous['api/products.php']==prior,'Unreviewed live API differs from parent commit; no upload'
    assert previous['database/.htaccess']==Path('database/.htaccess').read_bytes(),'Database data protection differs; no upload'
    for folder in ['assets/products','assets/products/solax-20261112']:
        try:ftp.mkd(folder)
        except ftplib.error_perm as exc:
            if not str(exc).startswith('550'):raise
    for row in photos:
        name=row['path'];body=Path(name).read_bytes();ftp.storbinary('STOR '+name,io.BytesIO(body));check=io.BytesIO();ftp.retrbinary('RETR '+name,check.write);assert check.getvalue()==body,name
    try:
        for name in files:
            body=Path(name).read_bytes();ftp.storbinary('STOR '+name+'.solax-upload',io.BytesIO(body));check=io.BytesIO();ftp.retrbinary('RETR '+name+'.solax-upload',check.write);assert check.getvalue()==body,name
        for name in files:ftp.rename(name+'.solax-upload',name)
        activated=True
    except Exception:
        for name in files:
            if previous[name] is not None:ftp.storbinary('STOR '+name,io.BytesIO(previous[name]))
        raise
response=json.loads(get('api/products.php'));after=response['products'];live={(p['brand'],p['code']):p for p in after}
assert response['solax_import']=={'version':'solax_supplier_20261112_plus30_v1','verified':True,'updated':71,'added':84,'priced':154,'inquiry':1,'total':155},response.get('solax_import')
assert response['prices_visible'] is True
assert len(after)==len(before['products'])+84
assert set(old)<={p['id'] for p in after}
for row in items:
    p=live[(row['brand'],row['code'])]
    assert p['price']==row['price'] and p['category']==row['category'] and p['imageUrl']==row['image'],row['code']
    assert p.get('description') and len(p['description'])>150,row['code']
for key,p in old_keys.items():
    if key not in expected_keys:assert live[key]==p,'Unrelated product changed: '+str(key)
facets=json.loads(get('api/catalog-facets.php'))['items']
# Every new/updated variant exposes its supported attributes to category filters.
for row in items:
    p=live[(row['brand'],row['code'])];assert str(p['id']) in facets,row['code']
    fields={f['label']:f['value'] for f in row['fields']};exposed=facets[str(p['id'])]['specs']
    for label in ['Брой фази','Номинална AC мощност (kW)','Номинална зарядна мощност (kW)','Номинална енергия (kWh)','Клас батерия','Сечение (mm²)','Цвят']:
        if label in fields:assert exposed.get(label)==fields[label],(row['code'],label)
sample=[]
for kind in ['inverter','battery','bms','battery_accessory','storage','management','charger','meter','sensor','communication','optimizer','cable','connector']:
    sample.append(next(r for r in items if r['kind']==kind and r['expectedId'] is None)) if any(r['kind']==kind and r['expectedId'] is None for r in items) else sample.append(next(r for r in items if r['kind']==kind))
for row in sample:
    p=live[(row['brand'],row['code'])];detail=json.loads(get('api/product-details.php?id='+str(p['id'])));fields={f['label']:f['value'] for f in detail['fields']}
    assert detail['product']['price']==row['price']
    assert fields['CAD: тип']==row['kind']
    assert get('product.html?id='+str(p['id']))
    print('Verified live product details:',p['id'],row['code'],flush=True)
for photo in photos:assert get(photo['path'])==Path(photo['path']).read_bytes(),photo['path']
for name in ['database/solax-catalog-20261112.json','database/solax-photos-20261112.json']:
    try:get(name);raise AssertionError('Supplier data exposed')
    except urllib.error.HTTPError as error:assert error.code==403
repeat=json.loads(get('api/products.php'));assert repeat['products']==after and repeat['solax_import']==response['solax_import'],'Import compounded prices'
print('PASS: live 155 positions, 71 price updates, 84 additions, local photos, categories, filters, CAD detail metadata and idempotence',flush=True)
