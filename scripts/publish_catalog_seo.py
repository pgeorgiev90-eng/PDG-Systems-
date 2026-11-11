"""Publish only the reviewed catalog renderer, retaining and restoring its predecessor."""
import ftplib, io, os, ssl, urllib.request
from pathlib import Path
path='social-page.php'
backup=Path('production-backup'); backup.mkdir(exist_ok=True)
def verify():
    with urllib.request.urlopen('https://pdgsystems.bg/catalog.html?category=%D0%98%D0%BD%D0%B2%D0%B5%D1%80%D1%82%D0%BE%D1%80%D0%B8&release='+os.environ['GITHUB_SHA'],timeout=45) as r:
        html=r.read().decode()
    assert 'aria-label="Каталожен указател"' in html
    assert '<li><a href="product.html?id=' in html
    assert 'Страница 1 от ' in html
    with urllib.request.urlopen('https://pdgsystems.bg/product.html?id=1',timeout=45) as r:
        html=r.read().decode()
    assert 'data-server-rendered="true"' in html
    assert 'Цена след регистрация' in html
    print('PASS: live category links, product directory, pagination and existing product rendering')
with ftplib.FTP_TLS(context=ssl.create_default_context(),timeout=60) as ftp:
    ftp.connect(os.environ['FTP_HOST'],21)
    ftp.login(os.environ['FTP_USER'],os.environ['FTP_PASSWORD']);ftp.prot_p();ftp.cwd('/')
    old=io.BytesIO();ftp.retrbinary('RETR '+path,old.write)
    (backup/path).write_bytes(old.getvalue())
    body=Path(path).read_bytes(); staged=path+'.catalog-'+os.environ['GITHUB_SHA'][:12]
    ftp.storbinary('STOR '+staged,io.BytesIO(body))
    check=io.BytesIO();ftp.retrbinary('RETR '+staged,check.write);assert check.getvalue()==body
    ftp.rename(staged,path)
    try: verify()
    except Exception:
        ftp.storbinary('STOR '+staged,io.BytesIO(old.getvalue()));ftp.rename(staged,path)
        raise
