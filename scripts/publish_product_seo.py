"""Scoped product SEO release with byte verification, backup and rollback."""
import ftplib,io,json,os,re,ssl,urllib.request
from pathlib import Path
paths=['api/product-seo.php','product.js','product.html','social-page.php']
backup=Path('production-backup');backup.mkdir(exist_ok=True)
def get(path):
    with urllib.request.urlopen('https://pdgsystems.bg/'+path+('&' if '?' in path else '?')+'product_seo='+os.environ['GITHUB_SHA'],timeout=45) as r:return r.read()
def verify():
    products=json.loads(get('api/products.php'))['products']
    priced=[p for p in products if p.get('price') is not None and float(p['price'])>0]
    unpriced=[p for p in products if not p.get('price') or float(p['price'])<=0]
    sample=priced[:2]+priced[-1:]+unpriced[:1]
    assert priced
    for p in sample:
        html=get('product.html?id='+str(p['id'])).decode()
        assert '– цена и характеристики | PDG Systems</title>' in html
        assert 'data-server-rendered="true"' in html
        assert 'product.js?v=20261117-price-seo' in html
        data=json.loads(re.search(r'<script type="application/ld\+json">(.*?)</script>',html,re.S)[1])
        if p in priced:
            assert data['offers']['priceCurrency']=='EUR'
            assert abs(float(data['offers']['price'])-round(float(p['price'])*1.2,2))<0.001
            assert '€ с ДДС</p>' in html
        else: assert 'offers' not in data
        assert data['url']=='https://pdgsystems.bg/product.html?id='+str(p['id'])
        print('Verified product SEO:',p['id'],flush=True)
    assert get('product.js')==Path('product.js').read_bytes()
    assert 'Каталожен указател' in get('catalog.html').decode()
    print('PASS: product titles, VAT-inclusive offers, canonical URLs, public prices and catalog',flush=True)
with ftplib.FTP_TLS(context=ssl.create_default_context(),timeout=60) as ftp:
    ftp.connect(os.environ['FTP_HOST'],21);ftp.login(os.environ['FTP_USER'],os.environ['FTP_PASSWORD']);ftp.prot_p();ftp.cwd('/')
    prior={};suffix='.product-seo-'+os.environ['GITHUB_SHA'][:12]
    for path in paths:
        old=io.BytesIO()
        try:ftp.retrbinary('RETR '+path,old.write);prior[path]=old.getvalue()
        except ftplib.error_perm as e:
            if path!='api/product-seo.php' or not str(e).startswith('550'):raise
            prior[path]=None
        if prior[path] is not None:
            dest=backup/path;dest.parent.mkdir(parents=True,exist_ok=True);dest.write_bytes(prior[path])
        body=Path(path).read_bytes();ftp.storbinary('STOR '+path+suffix,io.BytesIO(body))
        check=io.BytesIO();ftp.retrbinary('RETR '+path+suffix,check.write);assert check.getvalue()==body
    replaced=[]
    try:
        for path in paths:ftp.rename(path+suffix,path);replaced.append(path)
        verify()
    except Exception:
        # Restore the renderer before removing its newly introduced helper.
        for path in reversed(replaced):
            if prior[path] is None:ftp.delete(path)
            else:ftp.storbinary('STOR '+path+suffix,io.BytesIO(prior[path]));ftp.rename(path+suffix,path)
        raise
