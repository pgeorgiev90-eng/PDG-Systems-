import ftplib
import io
import json
import os
import ssl
from pathlib import Path
from urllib.error import HTTPError
from urllib.request import Request, urlopen

BASE = 'https://pdgsystems.bg/ai-chat/'
FILES = ('relay.php', 'index.html')
suffix = '.pdg-' + os.environ['GITHUB_SHA'][:12]

def verify_relay():
    check = Request(BASE + 'relay.php?op=claim', data=b'{}', headers={'Content-Type':'application/json'})
    try:
        urlopen(check, timeout=20)
    except HTTPError as exc:
        if exc.code != 401 or json.load(exc) != {'error':'Unauthorized'}:
            raise
    else:
        raise RuntimeError('Unauthenticated worker request was accepted')

def load_file(ftp, name):
    body = io.BytesIO()
    try:
        ftp.retrbinary('RETR ' + name, body.write)
    except ftplib.error_perm as exc:
        if not str(exc).startswith('550'):
            raise
        return None
    return body.getvalue()

with ftplib.FTP_TLS(context=ssl.create_default_context(), timeout=50) as ftp:
    ftp.connect('185.80.2.82',21)
    ftp.host = 'host-185-80-2-82.superhosting.bg'
    ftp.login(os.environ['FTP_USER'],os.environ['FTP_PASSWORD'])
    ftp.prot_p()
    ftp.cwd('/')
    try:
        ftp.cwd('ai-chat')
    except ftplib.error_perm as exc:
        if not str(exc).startswith('550'):
            raise
        ftp.mkd('ai-chat')
        ftp.cwd('ai-chat')
    backups = {name:load_file(ftp,name) for name in FILES}
    replaced = []
    try:
        for name in FILES:
            data = (Path('ai-chat') / name).read_bytes()
            stage = name + suffix
            ftp.storbinary('STOR ' + stage, io.BytesIO(data))
            if load_file(ftp,stage) != data:
                raise RuntimeError('Upload verification failed for ' + name)
            ftp.rename(stage,name)
            replaced.append(name)
            if name == 'relay.php':
                verify_relay()
        with urlopen(BASE+'?v='+os.environ['GITHUB_SHA'],timeout=20) as response:
            if response.read() != Path('ai-chat/index.html').read_bytes():
                raise RuntimeError('Unexpected public page content')
        print('Verified public test page and authenticated worker endpoint')
    except Exception:
        for name in reversed(replaced):
            old = backups[name]
            if old is None:
                ftp.delete(name)
            else:
                stage = name + suffix
                ftp.storbinary('STOR ' + stage, io.BytesIO(old))
                ftp.rename(stage,name)
        raise
