"""Build reproducible same-origin copies of the existing pinned browser dependencies."""
import base64,hashlib,io,json,shutil,subprocess,tarfile,tempfile,urllib.request
from pathlib import Path
PACKAGES=[('leaflet@1.9.4','leaflet-1.9.4','leaflet'),('three@0.152.2','three-0.152.2','three'),('@mlightcad/libredwg-web@0.8.4','libredwg-0.8.4','dwg')]
root=Path('assets/vendor');root.mkdir(parents=True,exist_ok=True);manifest=[]
with tempfile.TemporaryDirectory(prefix='pdg-vendor-') as tmp:
 for spec,directory,kind in PACKAGES:
  result=json.loads(subprocess.check_output(['npm','pack',spec,'--ignore-scripts','--json','--pack-destination',tmp],text=True))[0]
  packed=Path(tmp)/result['filename'];raw=packed.read_bytes();integrity='sha512-'+base64.b64encode(hashlib.sha512(raw).digest()).decode()
  if result['integrity']!=integrity:raise RuntimeError('Package integrity mismatch')
  target=root/directory;target.mkdir(exist_ok=True);count=0
  with tarfile.open(packed,'r:gz') as archive:
   for member in archive:
    path=Path(member.name)
    if not member.isfile() or '..' in path.parts or path.is_absolute() or not member.name.startswith('package/'):continue
    relative=Path(*path.parts[1:]);parts=relative.parts
    license_file=relative.name.lower().startswith(('license','copying','notice','readme'))
    if kind=='leaflet' and parts and parts[0]=='dist':out=target/Path(*parts[1:])
    elif kind=='three' and relative.as_posix()=='build/three.min.js':out=target/'three.min.js'
    elif kind=='dwg' and parts and parts[0] in {'dist','wasm'}:out=target/relative
    elif license_file:out=target/relative.name
    else:continue
    out.parent.mkdir(parents=True,exist_ok=True);out.write_bytes(archive.extractfile(member).read());count+=1
  if not count:raise RuntimeError('Missing vendor package content')
  manifest.append({'package':spec,'integrity':integrity,'files':count,'path':str(target)})
  print('Prepared pinned package:',spec,'files:',count,flush=True)
(root/'manifest.json').write_text(json.dumps(manifest,indent=2)+'\n')
(root/'THIRD-PARTY-NOTICES.txt').write_text('PDG Systems browser dependencies\n\nThese pinned packages are served from this website. Original copyright and license notices are retained in the package folders.\n\n'+'\n'.join(r['package']+' · npm archive '+r['integrity'] for r in manifest)+'\n')
(root/'.htaccess').write_text('Options -Indexes\nAddType application/wasm .wasm\n<FilesMatch "\\.(?:php|phtml|phar|inc)$">\nRequire all denied\n</FilesMatch>\n')
for name,url in json.loads(Path('scripts/site-photo-sources.json').read_text()).items():
 request=urllib.request.Request(url,headers={'User-Agent':'PDG-Systems-asset-migration/1.0'})
 with urllib.request.urlopen(request,timeout=45) as response:data=response.read(5000001)
 if len(data)>5000000 or not data.startswith(b'\xff\xd8\xff'):raise RuntimeError('Invalid photographic image')
 path=Path(name);path.parent.mkdir(parents=True,exist_ok=True);path.write_bytes(data);print('Prepared existing site photograph:',name,hashlib.sha256(data).hexdigest(),flush=True)
# Native module imports in this package must remain local-relative, never CDN/bare imports.
for path in (root/'libredwg-0.8.4').rglob('*.js'):
 import re
 for dependency in re.findall(r'(?:from\s*|import\s*\()\s*["\x27]([^"\x27]+)',path.read_text(errors='replace')):
  if dependency.startswith(('http:','https:')):raise RuntimeError('Vendor contains remote module import')
