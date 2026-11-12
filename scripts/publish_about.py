import ftplib,io,os,ssl,urllib.request,json,urllib.error
from pathlib import Path
files=['contact-enquiries.js','index.html']
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
            body=Path(name).read_bytes();temp=name+'.about-upload'
            ftp.storbinary('STOR '+temp,io.BytesIO(body));check=io.BytesIO();ftp.retrbinary('RETR '+temp,check.write);assert check.getvalue()==body
            ftp.rename(temp,name);published.append(name)
        base='https://pdgsystems.bg/'
        with urllib.request.urlopen(base+'contact-enquiries.js?release='+os.environ['GITHUB_SHA'],timeout=45) as r:assert r.read()==Path('contact-enquiries.js').read_bytes()
        with urllib.request.urlopen(base+'?release='+os.environ['GITHUB_SHA'],timeout=45) as r:
            html=r.read().decode()
            for text in ['Фотоволтаични системи и техническо обслужване в цяла България','Диагностика, ремонт и разширяване на съществуващи системи','София, Пловдив, Варна, Бургас','data-about-enquiry="solar"','data-about-enquiry="diagnostics"','contact-enquiries.js?v=20261112-about1']:
                assert text in html,text
        print('PASS: approved nationwide About copy, locations and enquiry buttons published')
    except Exception:
        for name in reversed(published):
            if old[name] is None:ftp.delete(name)
            else:ftp.storbinary('STOR '+name,io.BytesIO(old[name]))
        raise
