import ftplib
import io
import json
import os
import ssl
from pathlib import Path
from urllib.error import HTTPError
from urllib.request import Request, urlopen

BASE = 'https://pdgsystems.bg/'
FILES = {
    'assets/data/solax-battery-compatibility.json': 'assets/data/solax-battery-compatibility.json',
    'api/solax-battery-compatibility.php': 'api/solax-battery-compatibility.php',
    'solax-compatibility.js': 'solax-compatibility.js',
    'pdg-helper.js': 'pdg-helper.js',
    'api/site-ai-chat.php': 'ai-chat/relay.php',
    'ai-chat.html': 'ai-chat/index.html',
}
suffix = '.pdg-' + os.environ['GITHUB_SHA'][:12]

def verify_relay():
    check = Request(BASE + 'api/site-ai-chat.php?op=claim&v=' + os.environ['GITHUB_SHA'], data=b'{}', headers={'Content-Type':'application/json'})
    try:
        with urlopen(check, timeout=20) as response:
            raise RuntimeError(f'Unexpected relay response: {response.status} {response.geturl()} {response.headers.get("Content-Type")}')
    except HTTPError as exc:
        if exc.code != 401 or json.load(exc) != {'error':'Unauthorized'}:
            raise

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
        ftp.mkd('assets/data')
    except ftplib.error_perm:
        ftp.cwd('assets/data')
        ftp.cwd('/')
    backups = {name:load_file(ftp,name) for name in FILES}
    replaced = []
    try:
        for name in FILES:
            data = Path(FILES[name]).read_bytes()
            stage = name + suffix
            ftp.storbinary('STOR ' + stage, io.BytesIO(data))
            if load_file(ftp,stage) != data:
                raise RuntimeError('Upload verification failed for ' + name)
            ftp.rename(stage,name)
            replaced.append(name)
            if name == 'api/site-ai-chat.php':
                verify_relay()
        with urlopen(BASE+'ai-chat.html?v='+os.environ['GITHUB_SHA'],timeout=20) as response:
            if response.read() != Path('ai-chat/index.html').read_bytes():
                raise RuntimeError('Unexpected public page content')
        with urlopen(BASE + 'api/solax-battery-compatibility.php?v=' + os.environ['GITHUB_SHA'], timeout=20) as response:
            expected = Path(FILES['assets/data/solax-battery-compatibility.json']).read_bytes()
            if response.read() != expected or response.headers.get('Access-Control-Allow-Origin') != '*':
                raise RuntimeError('Public compatibility API verification failed')
        with urlopen(BASE + 'solax-compatibility.js?v=' + os.environ['GITHUB_SHA'], timeout=20) as response:
            if response.read() != Path(FILES['solax-compatibility.js']).read_bytes():
                raise RuntimeError('Public checker verification failed')
        print('Verified chat and cross-origin SolaX catalogue')
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
