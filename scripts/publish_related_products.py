import ftplib,io,os,ssl,urllib.request,json,urllib.error
from pathlib import Path
files=['api/related-products-common.php','api/related-products.php','api/admin-related-products.php','related-products.js','related-products.css','admin-related-products.js','admin-related-products.php','admin-products-draft.html','social-page.php','product.html']
backup=Path('production-backup');backup.mkdir(exist_ok=True)
old={};published=[]
with ftplib.FTP_TLS(context=ssl.create_default_context(),timeout=60) as ftp:
    ftp.connect(os.environ['FTP_HOST'],21);ftp.login(os.environ['FTP_USER'],os.environ['FTP_PASSWORD']);ftp.prot_p();ftp.cwd('/')
    for name in files:
        data=io.BytesIO()
        try:ftp.retrbinary('RETR '+name,data.write);old[name]=data.getvalue()
        except ftplib.error_perm as e:
            if not str(e).startswith('550'):raise
            old[name]=None
        path=backup/name;path.parent.mkdir(parents=True,exist_ok=True)
        path.write_bytes(old[name] or b'')
    try:
        for name in files:
            body=Path(name).read_bytes();temp=name+'.related-upload'
            ftp.storbinary('STOR '+temp,io.BytesIO(body));check=io.BytesIO();ftp.retrbinary('RETR '+temp,check.write);assert check.getvalue()==body
            ftp.rename(temp,name);published.append(name)
        base='https://pdgsystems.bg/'
        for name in ['related-products.js','related-products.css','admin-related-products.js']:
            with urllib.request.urlopen(base+name+'?release='+os.environ['GITHUB_SHA'],timeout=45) as r:assert r.read()==Path(name).read_bytes(),name
        with urllib.request.urlopen(base+'api/related-products.php?id=1',timeout=45) as r:
            d=json.load(r);assert len(d['similar'])>0 and all(int(p['id'])!=1 for p in d['similar'])
        with urllib.request.urlopen(base+'product.html?id=1&release='+os.environ['GITHUB_SHA'],timeout=45) as r:
            html=r.read().decode();assert 'related-products.js?v=20261112-1' in html and 'pdg-category-fallback' in html
        for path in ['api/admin-related-products.php?product_id=1','admin-related-products.php?product_id=1']:
            try:urllib.request.urlopen(base+path,timeout=45);raise AssertionError('Admin must remain private')
            except urllib.error.HTTPError as e:assert e.code==401
        print('PASS: related cards, public API, product page and private administration published; no production product links changed')
    except Exception:
        for name in reversed(published):
            if old[name] is None:ftp.delete(name)
            else:ftp.storbinary('STOR '+name,io.BytesIO(old[name]))
        raise
