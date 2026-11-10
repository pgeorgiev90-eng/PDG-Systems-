"""Publish only the approved NAS endpoint; deliver its key RSA-encrypted."""
import ftplib
import hashlib
import hmac
import io
import json
import os
import secrets
import ssl
import subprocess
import time
import urllib.error
import urllib.request
from pathlib import Path

endpoint=Path('api/nas-export.php').read_bytes()
delivery=Path(os.environ['RUNNER_TEMP'])/'nas-integration-delivery'
delivery.mkdir(mode=0o700,exist_ok=True)
config={'enabled':True,'key_id':'pdg-nas-1','secret':secrets.token_hex(32)}
# Only ciphertext is retained as an artifact. No secret enters stdout/arguments.
encrypted=subprocess.run(['openssl','pkeyutl','-encrypt','-pubin','-inkey','.github/nas-delivery-public.pem',
    '-pkeyopt','rsa_padding_mode:oaep','-pkeyopt','rsa_oaep_md:sha256'],input=json.dumps(config).encode(),capture_output=True,check=True).stdout
(delivery/'website-key.rsa').write_bytes(encrypted)
configured=False
with ftplib.FTP_TLS(context=ssl.create_default_context(),timeout=35) as ftp:
    ftp.connect(os.environ['FTP_HOST'],21)
    ftp.login(os.environ['FTP_USER'],os.environ['FTP_PASSWORD'])
    ftp.prot_p();ftp.cwd('/')
    document_root=ftp.pwd()
    # Preserve any existing endpoint before replacing this single file.
    api_names={name.rsplit('/',1)[-1] for name in ftp.nlst('api')}
    if 'nas-export.php' in api_names:
        previous=io.BytesIO();ftp.retrbinary('RETR api/nas-export.php',previous.write)
        (delivery/'nas-export.before.php').write_bytes(previous.getvalue())
    ftp.storbinary('STOR api/nas-export.pending.php',io.BytesIO(endpoint))
    check=io.BytesIO();ftp.retrbinary('RETR api/nas-export.pending.php',check.write)
    if check.getvalue()!=endpoint: raise RuntimeError('Endpoint upload verification failed')
    ftp.rename('api/nas-export.pending.php','api/nas-export.php')
    # Never put a secret in a jailed document root. Parent must match the known
    # private config layout; do not read the existing database credentials.
    try:
        ftp.cwd('..')
        parent=ftp.pwd()
        names={name.rsplit('/',1)[-1] for name in ftp.nlst()}
        private_parent=parent!=document_root and 'pdg-shop-db.php' in names
    except ftplib.error_perm:
        private_parent=False
    if private_parent:
        if 'pdg-nas-integration.php' in names:
            raise RuntimeError('Existing private integration config preserved; key rotation requires separate review')
        php=("<?php\nreturn ['enabled'=>true,'key_id'=>'pdg-nas-1','secret'=>'"+config['secret']+"'];\n").encode()
        ftp.storbinary('STOR pdg-nas-integration.php',io.BytesIO(php))
        ftp.sendcmd('SITE CHMOD 600 pdg-nas-integration.php')
        verify=io.BytesIO();ftp.retrbinary('RETR pdg-nas-integration.php',verify.write)
        if not hmac.compare_digest(verify.getvalue(),php): raise RuntimeError('Private key config verification failed')
        configured=True
    else:
        print('Private hosting directory is not accessible with this FTPS account. Manual private-key installation is required.')

checks={}
for resource in ('products','inquiries','orders'):
    stamp=str(int(time.time()));nonce=secrets.token_hex(16)
    query=f'resource={resource}&after_id=0&limit=1'
    canonical=f'GET\n/api/nas-export.php\n{query}\n{stamp}\n{nonce}'
    headers={'X-PDG-Key-ID':config['key_id'],'X-PDG-Timestamp':stamp,'X-PDG-Nonce':nonce,
             'X-PDG-Signature':hmac.new(config['secret'].encode(),canonical.encode(),hashlib.sha256).hexdigest()}
    request=urllib.request.Request('https://pdgsystems.bg/api/nas-export.php?'+query,headers=headers)
    try: response=urllib.request.urlopen(request,timeout=25)
    except urllib.error.HTTPError as error: response=error
    checks[resource]=response.status
    # Only aggregate status is logged. Personal data is never printed or stored.
    if configured:
        body=json.load(response)
        if response.status!=200 or body.get('resource')!=resource: raise RuntimeError('Authenticated export check failed: '+resource)
    elif response.status!=503:
        raise RuntimeError('Unconfigured endpoint is not failing closed')
result={'endpoint_published':True,'private_config_installed':configured,'http_checks':checks,
        'endpoint_sha256':hashlib.sha256(endpoint).hexdigest()}
(delivery/'result.json').write_text(json.dumps(result,indent=2),encoding='utf-8')
print(json.dumps(result))

