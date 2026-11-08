"""Deploy reviewed application assets with fail-closed source checks and rollback copy."""
import ftplib,ssl,os,io,json,hashlib,subprocess,urllib.request,urllib.error
from pathlib import Path
files=json.loads(Path('scripts/security-cleanup-files.json').read_text())
files += [str(p) for d in ['assets/vendor','assets/photos'] for p in Path(d).rglob('*') if p.is_file()]
files=list(dict.fromkeys(files));files.remove('database/.htaccess');files.append('database/.htaccess')
known=json.loads(Path('scripts/reviewed-hosting-hashes.json').read_text());backup=Path('/tmp/security-rollback');backup.mkdir(exist_ok=True)
with ftplib.FTP_TLS(context=ssl.create_default_context(),timeout=60) as ftp:
 ftp.connect(os.environ['FTP_HOST'],21);ftp.login(os.environ['FTP_USER'],os.environ['FTP_PASSWORD']);ftp.prot_p();ftp.cwd('/')
 for name in files:
  data=io.BytesIO();p=backup/name;p.parent.mkdir(parents=True,exist_ok=True)
  try:ftp.retrbinary('RETR '+name,data.write)
  except ftplib.error_perm as e:
   if str(e).startswith('550'):p.with_name(p.name+'.absent').write_text('Previously absent');continue
   raise
  raw=data.getvalue();p.write_bytes(raw)
  previous=subprocess.run(['git','show','5af6edd575369d59965466ba88aeb52647c32574:'+name],capture_output=True)
  valid=raw==Path(name).read_bytes() or (previous.returncode==0 and raw==previous.stdout) or hashlib.sha256(raw).hexdigest()==known.get(name)
  assert valid,'Hosting changed since review: '+name
 print('Backup and source verification complete:',len(files),'files',flush=True)
 # Dependencies and helper are uploaded before consumers; access restriction is last.
 ordered=sorted(files,key=lambda n:(0 if n.startswith(('assets/','database/')) and n!='database/.htaccess' or n=='api/public-product-fields.php' else 2 if n=='database/.htaccess' else 1))
 for name in ordered:
  parent=Path(name).parent;parts=[]
  for part in parent.parts:
   parts.append(part)
   try:ftp.mkd('/'.join(parts))
   except ftplib.error_perm as e:
    if not str(e).startswith('550'):raise
  raw=Path(name).read_bytes();ftp.storbinary('STOR '+name,io.BytesIO(raw));check=io.BytesIO();ftp.retrbinary('RETR '+name,check.write)
  assert check.getvalue()==raw,'Upload mismatch: '+name
 print('Published and byte-verified:',len(files),'files',flush=True)
base='https://pdgsystems.bg/'
def get(path):
 with urllib.request.urlopen(base+path,timeout=45) as r:return r.status,r.read(),r.headers
for name in ['assets/vendor/leaflet-1.9.4/leaflet.js','assets/vendor/three-0.152.2/three.min.js','assets/vendor/libredwg-0.8.4/dist/libredwg-web.js','assets/photos/site-photo-1.jpg','database/electrical-catalog-public.json','database/electrical-reference-prices-public.json']:
 status,body,h=get(name);assert status==200 and body,'Missing asset: '+name
for name in ['database/electrical-catalog.json','database/official-inverter-specs.json','database/cad-panels.json','database/schrack-shop-catalog.json']:
 try:get(name);raise AssertionError('Private data publicly accessible: '+name)
 except urllib.error.HTTPError as e:assert e.code==403,(name,e.code)
status,body,h=get('api/products.php');data=json.loads(body);assert len(data['products'])>100 and data.get('prices_visible') is False
assert all(p.get('price') is None for p in data['products']),'Guest prices leaked'
for p in [r for r in data['products'] if r.get('brand','').lower() in ['jinko solar','jinko','solax','deye','dyness']][:12]:
 detail=json.loads(get('api/product-details.php?id='+str(p['id']))[1]);assert 'fields' in detail
 assert not any('http://' in str(f.get('value','')) or 'https://' in str(f.get('value','')) for f in detail['fields'])
 assert detail['product']['price'] is None
assert 'images.unsplash.com' not in get('index.html')[1].decode()
print('PASS: live libraries/photos, protected import data, public fallbacks, product fields, guest pricing and homepage',flush=True)
