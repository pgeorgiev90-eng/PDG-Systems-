import ftplib,io,os,ssl,urllib.request,json,urllib.error
from pathlib import Path
files=['api/catalog-facets.php','catalog-filter-core.js','catalog-card.js','catalog-app.js','catalog-filters.css','catalog.html','catalog-sections.js']
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
            body=Path(name).read_bytes();temp=name+'.filters-upload'
            ftp.storbinary('STOR '+temp,io.BytesIO(body));check=io.BytesIO();ftp.retrbinary('RETR '+temp,check.write);assert check.getvalue()==body
            ftp.rename(temp,name);published.append(name)
        base='https://pdgsystems.bg/'
        for name in ['catalog-filter-core.js','catalog-card.js','catalog-app.js','catalog-filters.css','catalog-sections.js']:
            with urllib.request.urlopen(base+name+'?release='+os.environ['GITHUB_SHA'],timeout=45) as r:assert r.read()==Path(name).read_bytes(),name
        with urllib.request.urlopen(base+'api/catalog-facets.php',timeout=45) as r:
            data=json.load(r);assert len(data['items'])>0
        with urllib.request.urlopen(base+'catalog.html?section=inverters&release='+os.environ['GITHUB_SHA'],timeout=45) as r:
            html=r.read().decode();assert 'catalog-app.js?v=20261113-2' in html and 'catalog-filter-core.js' in html
        print('PASS: catalog filters, guest technical API and section page published; product records unchanged')
    except Exception:
        for name in reversed(published):
            if old[name] is None:ftp.delete(name)
            else:ftp.storbinary('STOR '+name,io.BytesIO(old[name]))
        raise
