import ftplib
import io
import os
import re
import ssl
from pathlib import Path
from urllib.request import urlopen

base = 'https://pdgsystems.bg/'
pages = ('index.html', 'shop.html')
suffix = '.pdg-ai-' + os.environ['GITHUB_SHA'][:12]
anchor_pattern = rb'<a id="pdg-ai-chat-link" href="/ai-chat\.html"[^>]*>[^<]+</a>'

widget_pattern = rb'<!-- pdg-ai-chat-widget:start -->[\s\S]*?<!-- pdg-ai-chat-widget:end -->'

def public(path):
    with urlopen(base+path+'?chat_link='+os.environ['GITHUB_SHA'], timeout=20) as r:
        return r.read()

def read(ftp, path):
    buffer = io.BytesIO()
    ftp.retrbinary('RETR '+path, buffer.write)
    return buffer.getvalue()

def upload(ftp, path, content):
    stage = path+suffix
    ftp.storbinary('STOR '+stage, io.BytesIO(content))
    if read(ftp, stage)!=content:
        raise RuntimeError('Upload verification failed: '+path)
    ftp.rename(stage,path)

def render(page, original):
    source = Path(page).read_bytes()
    widgets = re.findall(widget_pattern, source)
    if len(widgets)!=1:
        raise RuntimeError('Missing or duplicate chat widget in '+page)
    widget, = widgets
    for pattern in (widget_pattern, anchor_pattern):
        existing = re.findall(pattern, original)
        if existing:
            if len(existing)!=1:
                raise RuntimeError('Duplicate live chat markup in '+page)
            return re.sub(pattern, lambda _:widget, original)
    closing = list(re.finditer(rb'</body\s*>', original, re.I))
    if len(closing)!=1:
        raise RuntimeError('Unexpected live document body in '+page)
    index = closing[0].start()
    return original[:index]+widget+b'\n'+original[index:]

if b'PDG Systems' not in public('ai-chat.html'):
    raise RuntimeError('The live chat page did not respond')

with ftplib.FTP_TLS(context=ssl.create_default_context(),timeout=45) as ftp:
    ftp.connect('185.80.2.82',21)
    ftp.host = 'host-185-80-2-82.superhosting.bg'
    ftp.login(os.environ['FTP_USER'],os.environ['FTP_PASSWORD'])
    ftp.prot_p()
    ftp.cwd('/')
    old = {page:read(ftp,page) for page in pages}
    patched = {page:render(page,old[page]) for page in pages}
    replaced = []
    try:
        for page in pages:
            if patched[page]!=old[page]:
                upload(ftp,page,patched[page])
                replaced.append(page)
        for route in ('','shop.html'):
            html = public(route)
            if len(re.findall(widget_pattern, html))!=1:
                raise RuntimeError('Chat link not visible on '+(route or '/'))
        print('Verified chat link on both production pages')
    except Exception:
        for page in reversed(replaced):
            upload(ftp,page,old[page])
        raise
