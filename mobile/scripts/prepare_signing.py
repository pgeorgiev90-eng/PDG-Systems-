"""CI only: retain the Android signing identity encrypted on protected hosting."""
import ftplib,ssl,os,io,subprocess,urllib.request,urllib.error
from pathlib import Path
key=Path('/tmp/pdg-android-signing.p12');encrypted=Path('/tmp/pdg-android-signing.enc')
remote='database/pdg-android-signing.json'
with ftplib.FTP_TLS(context=ssl.create_default_context(),timeout=60) as ftp:
 ftp.connect(os.environ['FTP_HOST'],21);ftp.login(os.environ['FTP_USER'],os.environ['FTP_PASSWORD']);ftp.prot_p();ftp.cwd('/')
 buffer=io.BytesIO()
 try:ftp.retrbinary('RETR '+remote,buffer.write)
 except ftplib.error_perm as error:
  if not str(error).startswith('550'):raise
  subprocess.run(['keytool','-genkeypair','-alias','pdg-android','-keyalg','RSA','-keysize','3072','-validity','10000','-storetype','PKCS12','-keystore',str(key),'-storepass:env','FTP_PASSWORD','-keypass:env','FTP_PASSWORD','-dname','CN=PDG Systems, O=PDG Systems LTD, C=BG','-noprompt'],check=True)
  subprocess.run(['openssl','enc','-aes-256-cbc','-salt','-pbkdf2','-iter','200000','-in',str(key),'-out',str(encrypted),'-pass','env:FTP_PASSWORD'],check=True)
  ftp.storbinary('STOR '+remote,io.BytesIO(encrypted.read_bytes()))
 else:
  encrypted.write_bytes(buffer.getvalue())
  subprocess.run(['openssl','enc','-d','-aes-256-cbc','-pbkdf2','-iter','200000','-in',str(encrypted),'-out',str(key),'-pass','env:FTP_PASSWORD'],check=True)
 key.chmod(0o600)
 try:
  with urllib.request.urlopen('https://pdgsystems.bg/'+remote+'?check='+os.environ['GITHUB_SHA'],timeout=60) as response:raise RuntimeError('Signing backup must not be publicly readable')
 except urllib.error.HTTPError as error:
  if error.code not in [403,404]:raise
print('Signing identity ready; encrypted backup remains protected on hosting.')
