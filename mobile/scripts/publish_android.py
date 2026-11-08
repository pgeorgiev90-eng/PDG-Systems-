"""Publish only after the signed APK passes validation; enable download links last."""
import ftplib,ssl,os,io,json,hashlib,subprocess,urllib.request
from pathlib import Path
apk=Path('mobile/android/app/build/outputs/apk/release/app-release.apk')
cert=Path('/tmp/pdg-android-public.pem')
subprocess.run(['keytool','-exportcert','-rfc','-alias','pdg-android','-keystore','/tmp/pdg-android-signing.p12','-storepass:env','FTP_PASSWORD','-file',str(cert)],check=True)
fingerprint=subprocess.check_output(['openssl','x509','-in',str(cert),'-noout','-fingerprint','-sha256'],text=True).strip().split('=',1)[1]
digest=hashlib.sha256(apk.read_bytes()).hexdigest()
entry={'relation':['delegate_permission/common.handle_all_urls'],'target':{'namespace':'android_app','package_name':'bg.pdgsystems.app','sha256_cert_fingerprints':[fingerprint]}}
web=['app-install.css','app-install.js','app-launch.js','app-download.html','app.html','auth.html','account-draft.html','my-projects.php','admin-dashboard.html','admin-electric.html','admin-orders.html','admin-products-draft.html','admin-preview.html','profile-preview.html']
with ftplib.FTP_TLS(context=ssl.create_default_context(),timeout=90) as ftp:
 ftp.connect(os.environ['FTP_HOST'],21);ftp.login(os.environ['FTP_USER'],os.environ['FTP_PASSWORD']);ftp.prot_p();ftp.cwd('/')
 for folder in ['downloads','.well-known']:
  try:ftp.mkd(folder)
  except ftplib.error_perm as error:
   if not str(error).startswith('550'):raise
 previous=io.BytesIO()
 try:ftp.retrbinary('RETR .well-known/assetlinks.json',previous.write);links=json.loads(previous.getvalue())
 except ftplib.error_perm as error:
  if not str(error).startswith('550'):raise
  links=[]
 assert isinstance(links,list)
 links=[item for item in links if item.get('target',{}).get('package_name')!='bg.pdgsystems.app']+[entry]
 files={'downloads/pdg-systems.apk':apk.read_bytes(),'downloads/pdg-systems.apk.sha256':(digest+'  pdg-systems.apk\n').encode(),'.well-known/assetlinks.json':json.dumps(links,indent=2).encode()}
 files.update({name:Path(name).read_bytes() for name in web})
 for name,body in files.items():
  ftp.storbinary('STOR '+name+'.pdg-upload',io.BytesIO(body));check=io.BytesIO();ftp.retrbinary('RETR '+name+'.pdg-upload',check.write);assert check.getvalue()==body,name
 for name in files:ftp.rename(name+'.pdg-upload',name)
with urllib.request.urlopen('https://pdgsystems.bg/downloads/pdg-systems.apk?release='+os.environ['GITHUB_SHA'],timeout=90) as response:assert hashlib.sha256(response.read()).hexdigest()==digest
with urllib.request.urlopen('https://pdgsystems.bg/.well-known/assetlinks.json',timeout=60) as response:assert entry in json.load(response)
print('PASS: signed Android APK published, digest verified and website certificate association active.')
