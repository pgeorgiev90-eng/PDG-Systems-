"""Read-only site source/hosting audit. Never execute inspected source or print secrets."""
import argparse,ftplib,hashlib,io,json,os,re,ssl,subprocess
from pathlib import Path
CODE={'.php','.phtml','.inc','.js','.mjs','.html','.htm','.svg','.css'}
RULES={
 'php_dynamic_eval':re.compile(rb'\beval\s*\(',re.I),
 'php_encoded_execution':re.compile(rb'(?:eval|assert)\s*\(\s*(?:base64_decode|gzinflate|gzuncompress|str_rot13)',re.I),
 'php_request_execution':re.compile(rb'\b(?:system|shell_exec|passthru|popen|proc_open)\s*\([^;]{0,300}\$_(?:GET|POST|REQUEST|COOKIE)',re.I),
 'php_assert_request':re.compile(rb'\bassert\s*\(\s*\$_(?:GET|POST|REQUEST|COOKIE)',re.I),
 'php_shell_command':re.compile(rb'(?<!->)(?<!::)\b(?:shell_exec|passthru|popen|proc_open|system)\s*\(',re.I),
 'svg_active_script':re.compile(rb'<\s*script\b|\bon(?:load|error)\s*=',re.I),
 'js_dynamic_eval':re.compile(rb'\beval\s*\(|\bnew\s+Function\s*\('),
}
def inspect(name,data,tracked=None):
 ext=Path(name).suffix.lower();rules=[]
 for key,rule in RULES.items():
  if key.startswith('php_') and ext not in {'.php','.phtml','.inc'}:continue
  if key.startswith('svg_') and ext!='.svg':continue
  if key.startswith('js_') and ext not in {'.js','.mjs','.html','.htm'}:continue
  if rule.search(data):rules.append(key)
 blob=hashlib.sha1(b'blob '+str(len(data)).encode()+b'\0'+data).hexdigest()
 return {'path':name,'bytes':len(data),'sha256':hashlib.sha256(data).hexdigest(),'git_blob':blob,'tracked':tracked is not None,'matches_repository':blob==tracked if tracked else None,'findings':rules}
def tracked_files():
 out={}
 raw=subprocess.check_output(['git','ls-tree','-r','--full-tree','HEAD'],text=True)
 for line in raw.splitlines():
  meta,name=line.split('\t',1);out[name]=meta.split()[2]
 return out
def ftp_audit():
 known=tracked_files();rows=[];visited=0
 with ftplib.FTP_TLS(context=ssl.create_default_context(),timeout=45) as ftp:
  ftp.connect(os.environ['FTP_HOST'],21);ftp.login(os.environ['FTP_USER'],os.environ['FTP_PASSWORD']);ftp.prot_p();ftp.cwd('/')
  pending=['.']
  while pending:
   folder=pending.pop()
   for name,facts in ftp.mlsd(folder):
    if name in {'.','..'}:continue
    path=name if folder=='.' else folder+'/'+name;kind=facts.get('type','');visited+=1
    if visited>10000:raise RuntimeError('Hosting inventory limit exceeded')
    if kind=='dir':
     if name not in {'.git','node_modules','.well-known','cgi-bin'}:pending.append(path)
     continue
    if kind!='file' or (Path(path).suffix.lower() not in CODE and name!='.htaccess'):continue
    if any(part in path.lower() for part in ['pdg-shop-db.php','.env','credentials','private-key']):rows.append({'path':path,'excluded':'private configuration'});continue
    if int(facts.get('size',0))>5000000:rows.append({'path':path,'excluded':'code exceeds5MB'});continue
    data=io.BytesIO();ftp.retrbinary('RETR '+path,data.write);rows.append(inspect(path,data.getvalue(),known.get(path)))
 return rows,visited
if __name__=='__main__':
 p=argparse.ArgumentParser();p.add_argument('--ftp',action='store_true');p.add_argument('--output',required=True);a=p.parse_args()
 if a.ftp:rows,visited=ftp_audit()
 else:
  rows=[];visited=0
  for file in Path('.').rglob('*'):
   if not file.is_file() or any(x in file.parts for x in ['.git','tests','.github','node_modules','vendor']):continue
   if file.suffix.lower() not in CODE:continue
   rows.append(inspect(str(file),file.read_bytes()));visited+=1
 report={'read_only':True,'visited':visited,'code_files':len(rows),'files':rows}
 Path(a.output).write_text(json.dumps(report,ensure_ascii=False,indent=2))
 findings=[{'path':r['path'],'findings':r.get('findings',[])} for r in rows if r.get('findings')]
 unknown=[r['path'] for r in rows if r.get('tracked') is False] if a.ftp else []
 drift=[r['path'] for r in rows if r.get('tracked') and r.get('matches_repository') is False]
 print(json.dumps({'code_files':len(rows),'findings':findings,'untracked_code':unknown,'different_from_repository':drift},ensure_ascii=False))
