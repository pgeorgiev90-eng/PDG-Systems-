"""Scoped technical SEO release; preserve host configuration and rollback on failure."""
import ftplib,hashlib,io,json,os,re,ssl,urllib.request,urllib.error
from pathlib import Path
from PIL import Image
base='https://pdgsystems.bg/'
paths=json.loads(Path('scripts/seo-files.json').read_text())
# Exact crops from the same atlas already used by the product interface.
atlas=Image.open('assets/enerack-products.jpg')
images=[]
for index in range(130):
    path=f'assets/enerack-product-{index}.webp'
    x,y=index%10*150,index//10*150
    atlas.crop((x,y,x+150,y+150)).save(path,'WEBP',quality=92)
    images.append(path)
rules=b'''# BEGIN PDG TECHNICAL SEO
RewriteEngine On
RewriteCond %{HTTP_HOST} ^www\\.pdgsystems\\.bg$ [NC]
RewriteRule ^ https://pdgsystems.bg%{REQUEST_URI} [R=301,L,NE]
<IfModule mod_headers.c>
<FilesMatch "\\.(?:css|js|webp|png|jpe?g|svg|ico|woff2?)$">
Header set Cache-Control "public, max-age=604800"
</FilesMatch>
</IfModule>
# END PDG TECHNICAL SEO
'''
backup=Path('production-backup');backup.mkdir(exist_ok=True)
suffix='.seo-'+os.environ['GITHUB_SHA'][:12]
def get(path):
    return urllib.request.urlopen(base+path+('&' if '?' in path else '?')+'seo_release='+os.environ['GITHUB_SHA'],timeout=40)
def verify():
    for id in (1,2,3,905):
        with get('product.html?id='+str(id)) as response: html=response.read().decode()
        assert re.search(r'<h1[^>]*>[^<]+</h1>',html),('Missing server H1',id)
        assert '<article id="product" data-server-rendered="true">' in html
        assert '<section id="description-section">' in html
        assert '<tbody id="specs"><tr>' in html
        assert 'Цена след регистрация' in html
        structured=json.loads(re.search(r'<script type="application/ld\+json">(.*?)</script>',html,re.S)[1])
        assert structured['name'] and structured.get('image'),('Missing product image',id)
        with urllib.request.urlopen(structured['image'],timeout=30) as image:assert image.status==200
        print('Verified server-rendered product',id,flush=True)
    category='BMS модули'
    from urllib.parse import quote
    with get('catalog.html?category='+quote(category)) as response:html=response.read().decode()
    assert re.search(r'<h1[^>]*>'+category+'</h1>',html)
    with urllib.request.urlopen('https://www.pdgsystems.bg/catalog.html?category='+quote(category),timeout=40) as response:
        assert response.url.startswith(base+'catalog.html?category=')
    with get('assets/enerack-product-1.webp') as response:assert 'max-age=604800' in response.headers.get('Cache-Control','')
    for path in ['auth.html','cart.html']:
        with get(path) as response:assert 'noindex' in response.headers.get('X-Robots-Tag','')
    try:get('product.html?id=99999999')
    except urllib.error.HTTPError as e:assert e.code==404
    else:raise AssertionError('Missing product must return 404')
    print('Verified category H1, www redirect, caching, noindex and 404',flush=True)
with ftplib.FTP_TLS(context=ssl.create_default_context(),timeout=60) as ftp:
    ftp.connect(os.environ['FTP_HOST'],21);ftp.login(os.environ['FTP_USER'],os.environ['FTP_PASSWORD']);ftp.prot_p();ftp.cwd('/')
    for path in paths+['.htaccess']:
        old=io.BytesIO()
        try:ftp.retrbinary('RETR '+path,old.write)
        except ftplib.error_perm as error:
            if path=='api/seo-render.php' and str(error).startswith('550'):continue
            raise
        target=backup/path;target.parent.mkdir(parents=True,exist_ok=True);target.write_bytes(old.getvalue())
    original=(backup/'.htaccess').read_bytes()
    updated=rules+re.sub(rb'# BEGIN PDG TECHNICAL SEO\n.*?# END PDG TECHNICAL SEO\n?',b'',original,flags=re.S)
    for path in images+paths:
        body=Path(path).read_bytes();ftp.storbinary('STOR '+path+suffix,io.BytesIO(body))
        check=io.BytesIO();ftp.retrbinary('RETR '+path+suffix,check.write)
        assert check.getvalue()==body,path
    replaced=[]
    try:
        # Helpers and assets must exist before the server renderer uses them.
        ordered=images+sorted(paths,key=lambda p:0 if p=='api/seo-render.php' else 2 if p=='social-page.php' else 1)
        for path in ordered:ftp.rename(path+suffix,path);replaced.append(path)
        ftp.storbinary('STOR .htaccess'+suffix,io.BytesIO(updated));ftp.rename('.htaccess'+suffix,'.htaccess');replaced.append('.htaccess')
        verify()
    except Exception:
        for path in reversed(replaced):
            saved=backup/path
            if saved.is_file():ftp.storbinary('STOR '+path+'.restore',io.BytesIO(saved.read_bytes()));ftp.rename(path+'.restore',path)
        raise
