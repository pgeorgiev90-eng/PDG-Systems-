"""Coordinated source-only release; database changes are additive and tested first."""
import ftplib
import hashlib
import io
import json
import os
import ssl
from pathlib import Path

paths = json.loads(Path('scripts/growth-files.json').read_text())
suffix = '.growth-' + os.environ['GITHUB_SHA'][:12]
backup = Path('production-backup')
backup.mkdir(exist_ok=True)
new_files = []
with ftplib.FTP_TLS(context=ssl.create_default_context(), timeout=60) as ftp:
    ftp.connect(os.environ['FTP_HOST'], 21)
    ftp.login(os.environ['FTP_USER'], os.environ['FTP_PASSWORD'])
    ftp.prot_p()
    ftp.cwd('/')
    # Read all previous files before replacing anything. 550 is allowed only for new files.
    import subprocess
    for path in paths:
        known = subprocess.run(['git', 'cat-file', '-e', 'HEAD^:' + path], capture_output=True).returncode == 0
        previous = io.BytesIO()
        try:
            ftp.retrbinary('RETR ' + path, previous.write)
        except ftplib.error_perm as error:
            if not known and str(error).startswith('550'):
                new_files.append(path)
                continue
            raise
        target = backup / path
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(previous.getvalue())
    (backup / 'new-files.json').write_text(json.dumps(new_files))
    for path in paths:
        body = Path(path).read_bytes()
        ftp.storbinary('STOR ' + path + suffix, io.BytesIO(body))
        check = io.BytesIO()
        ftp.retrbinary('RETR ' + path + suffix, check.write)
        assert hashlib.sha256(check.getvalue()).digest() == hashlib.sha256(body).digest(), path
    # Helpers and styles become available before callers and navigation.
    helpers = {'api/commerce-common.php','api/backup-common.php','api/product-copy.php'}
    order = sorted(paths, key=lambda p: (0 if p in helpers else 1 if p.endswith(('.js','.css')) else 2 if p.startswith('api/') else 5 if p == 'index.html' else 4 if p.endswith('.html') else 3, p))
    replaced = []
    try:
        for path in order:
            ftp.rename(path + suffix, path)
            replaced.append(path)
            print('Published:', path, flush=True)
        subprocess.run(['python', 'scripts/verify_growth.py'], check=True)
    except Exception:
        # Restore all originals if a rename fails midway; new pages may safely remain unlinked.
        for path in reversed(replaced):
            original = backup / path
            if original.is_file():
                ftp.storbinary('STOR ' + path + '.restore', io.BytesIO(original.read_bytes()))
                ftp.rename(path + '.restore', path)
        raise
