"""Publish only the approved Deye service page, its homepage link and image."""
import ftplib,io,os,ssl,hashlib,urllib.request,xml.etree.ElementTree as ET
from pathlib import Path
files=['assets/deye-support-inverter.webp','deye-support.html','index.html','sitemap.xml']
backup=Path('production-backup');backup.mkdir(exist_ok=True)
ns='http://www.sitemaps.org/schemas/sitemap/0.9';ET.register_namespace('',ns)
old={};published=[]
with ftplib.FTP_TLS(context=ssl.create_default_context(),timeout=60) as ftp:
    ftp.connect(os.environ['FTP_HOST'],21);ftp.login(os.environ['FTP_USER'],os.environ['FTP_PASSWORD']);ftp.prot_p();ftp.cwd('/')
    for name in files:
        b=io.BytesIO()
        try:ftp.retrbinary('RETR '+name,b.write);old[name]=b.getvalue()
        except ftplib.error_perm as e:
            if not str(e).startswith('550') or name in ['index.html','sitemap.xml']:raise
            old[name]=None
        target=backup/name;target.parent.mkdir(parents=True,exist_ok=True);target.write_bytes(old[name] or b'')
    before=ET.fromstring(old['sitemap.xml']);prior={x.text for x in before.findall('{'+ns+'}url/{'+ns+'}loc')};url='https://pdgsystems.bg/deye-support.html'
    if url not in prior:ET.SubElement(ET.SubElement(before,'{'+ns+'}url'),'{'+ns+'}loc').text=url
    bodies={name:Path(name).read_bytes() for name in files};bodies['sitemap.xml']=ET.tostring(before,encoding='utf-8',xml_declaration=True)
    try:
        for name in files:
            temp=name+'.deye-upload';ftp.storbinary('STOR '+temp,io.BytesIO(bodies[name]));b=io.BytesIO();ftp.retrbinary('RETR '+temp,b.write);assert b.getvalue()==bodies[name],name
            ftp.rename(temp,name);published.append(name)
        for name in files:
            with urllib.request.urlopen('https://pdgsystems.bg/'+name+'?deye_release='+os.environ['GITHUB_SHA'],timeout=45) as r:body=r.read();assert r.status==200
            if name in ['index.html','deye-support.html']:
                text=body.decode();assert 'deye-support.html' in text
                if name=='index.html':assert 'solax-support-button deye-support-button' in text and 'href="solax-support.html"' in text
                else:assert 'Вид съдействие' in text and 'contact-enquiries.js' in text and 'Platinum' not in text
            elif name.endswith('.webp'):assert body==bodies[name]
            else:
                after=ET.fromstring(body);urls={x.text for x in after.findall('{'+ns+'}url/{'+ns+'}loc')};assert prior<=urls and url in urls
        print('PASS: separate Deye button, service page, real product image and additive sitemap published on pdgsystems.bg; prior sitemap URLs retained')
    except Exception:
        for name in reversed(published):
            if old[name] is None:ftp.delete(name)
            else:ftp.storbinary('STOR '+name,io.BytesIO(old[name]))
        raise
