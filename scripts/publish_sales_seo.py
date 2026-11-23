"""Scoped SEO release: back up, stage, verify and roll back the reviewed files."""
import ftplib,io,json,os,re,ssl,subprocess,urllib.request,xml.etree.ElementTree as ET
from pathlib import Path
cad_pages = ('solar-cad.html', 'electric-cad.html')
event_path = os.environ.get('GITHUB_EVENT_PATH')
event = json.loads(Path(event_path).read_text()) if event_path else {}
commits = event.get('commits') or []
changed = {
    path for commit in commits for action in ('added', 'modified', 'removed')
    for path in commit.get(action, ())
}
if not changed and event.get('before') and event.get('after'):
    before = event['before']
    after = event['after']
    subprocess.run(['git', 'fetch', '--depth=1', 'origin', before], check=True)
    diff = subprocess.check_output(['git', 'diff', '--name-only', '-z', before, after])
    changed = set(diff.decode().rstrip('\0').split('\0')) if diff else set()
cad_only = bool(changed) and changed <= {
    *cad_pages, 'scripts/publish_sales_seo.py'
}
homepage_only = 'index.html' in changed and changed <= {
    'index.html', 'scripts/publish_sales_seo.py'
}
offer_api = 'api/launch-offer.php'
offer_only = offer_api in changed and changed <= {
    offer_api, 'index.html', 'scripts/publish_sales_seo.py'
}
if offer_only:
    paths = [offer_api, 'index.html']
elif homepage_only:
    paths = ['index.html']
else:
    paths = list(cad_pages) if cad_only else [*cad_pages, 'index.html', 'social-page.php']
def get(path):
    with urllib.request.urlopen('https://pdgsystems.bg/'+path+('&' if '?' in path else '?')+'sales_seo='+os.environ['GITHUB_SHA'],timeout=45) as r:
        assert r.status==200
        return r.read(),r.headers

def verify():
    if offer_only:
        body, _ = get('index.html')
        assert body == Path('index.html').read_bytes()
        assert 'launch-offer-count' in body.decode()
        offer, headers = get(offer_api)
        remaining = json.loads(offer)['remaining']
        assert type(remaining) is int and 0 <= remaining <= 29
        assert 'no-store' in headers.get('Cache-Control', '')
        print(f'PASS: launch offer is live with {remaining} places left', flush=True)
        return
    if homepage_only:
        body, _ = get('index.html')
        assert body == Path('index.html').read_bytes()
        homepage = body.decode()
        assert 'href="solar-cad.html">Ръководство за Solar CAD' in homepage
        assert 'href="electric-cad.html">Ръководство за Electric CAD' in homepage
        if 'launch-amount' in homepage:
            assert 'launch-offer-approved.webp' in homepage
            assert 'Регистрирай се и вземи до 5%' in homepage
            remaining = json.loads(get(offer_api)[0])['remaining']
            assert type(remaining) is int and 0 <= remaining <= 29
        print('PASS: homepage publication verified', flush=True)
        return
    for path in ('solar-cad.html','electric-cad.html'):
        body,headers=get(path);html=body.decode()
        assert body==Path(path).read_bytes()
        assert 'noindex' not in headers.get('X-Robots-Tag','').lower()
        assert '<h1>' in html and 'application/ld+json' in html
        assert '<a class="button" href="' in html
        print('Verified public CAD page:',path,flush=True)
    home=get('index.html')[0].decode()
    assert 'href="solar-cad.html"' in home and 'href="electric-cad.html"' in home
    if cad_only:
        sitemap = ET.fromstring(get('sitemap.xml')[0])
        urls = {loc.text for loc in sitemap.findall('.//{*}loc')}
        for path in cad_pages:
            assert 'https://pdgsystems.bg/' + path in urls, path
        print('PASS: CAD pages published and listed in sitemap.xml', flush=True)
        return
    products=json.loads(get('api/products.php')[0])['products']
    priced=[p for p in products if p.get('price') and float(p['price'])>0]
    unpriced=[p for p in products if not p.get('price') or float(p['price'])<=0]
    sample=priced[:2]+priced[-2:]+[p for p in priced if int(p['id']) in (114,947)]+unpriced[:2]
    for p in sample:
        html,headers=get('product.html?id='+str(p['id']));html=html.decode()
        assert 'noindex' not in headers.get('X-Robots-Tag','').lower()
        data=[json.loads(x) for x in re.findall(r'<script type="application/ld\+json">(.*?)</script>',html,re.S)]
        item=next(x for x in data if x.get('@type') in ('Product','WebPage'))
        assert item['url']=='https://pdgsystems.bg/product.html?id='+str(p['id'])
        if p in priced:
            assert item['@type']=='Product' and item['offers']['priceCurrency']=='EUR'
            assert abs(float(item['offers']['price'])-round(float(p['price'])*1.2,2))<.001
        else:assert item['@type']=='WebPage' and 'offers' not in item
        trail=next(x for x in data if x.get('@type')=='BreadcrumbList')
        assert len(trail['itemListElement'])==3
        assert 'aria-label="Навигационен път"' in html
        assert '<a id="inquiry" class="button" href="/#contact">' in html
        print('Verified product, price and breadcrumbs:',p['id'],flush=True)
    html=get('catalog.html')[0].decode()
    assert 'Каталожен указател' in html and 'href="solar-cad.html"' in html
    print('PASS: public CAD pages, product offers, request-only pages, breadcrumbs and internal links',flush=True)
backup=Path('production-backup');backup.mkdir(exist_ok=True)
with ftplib.FTP_TLS(context=ssl.create_default_context(),timeout=60) as ftp:
    ftp.connect(os.environ['FTP_HOST'],21);ftp.login(os.environ['FTP_USER'],os.environ['FTP_PASSWORD']);ftp.prot_p();ftp.cwd('/')
    prior={};suffix='.sales-seo-'+os.environ['GITHUB_SHA'][:12]
    for path in paths:
        old=io.BytesIO()
        try:ftp.retrbinary('RETR '+path,old.write);prior[path]=old.getvalue()
        except ftplib.error_perm as e:
            if path not in (*cad_pages, offer_api) or not str(e).startswith('550'):raise
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
        for path in reversed(replaced):
            if prior[path] is None:ftp.delete(path)
            else:ftp.storbinary('STOR '+path+suffix,io.BytesIO(prior[path]));ftp.rename(path+suffix,path)
        raise
