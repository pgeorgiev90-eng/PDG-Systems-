"""Fetch reviewed source images, verify bytes, and build small local catalog assets."""
import concurrent.futures, hashlib, io, json, urllib.parse, urllib.request
from pathlib import Path
from PIL import Image, ImageOps
manifest=json.loads(Path('database/solax-photos-20261112.json').read_text())
def prepare(row):
    url=urllib.parse.quote(row['source'],safe=':/%?=&')
    with urllib.request.urlopen(urllib.request.Request(url,headers={'User-Agent':'Mozilla/5.0'}),timeout=60) as response:body=response.read()
    if hashlib.sha256(body).hexdigest()!=row['sha256']:raise RuntimeError('Reviewed photograph changed: '+row['path'])
    image=Image.open(io.BytesIO(body));image.verify();image=ImageOps.exif_transpose(Image.open(io.BytesIO(body)))
    if image.size!=(row['width'],row['height']):raise RuntimeError('Image dimensions changed')
    image.thumbnail((900,900))
    if image.mode not in ['RGB','RGBA']:image=image.convert('RGBA' if 'transparency' in image.info else 'RGB')
    path=Path(row['path']);path.parent.mkdir(parents=True,exist_ok=True);image.save(path,'WEBP',quality=85,method=6)
    if path.stat().st_size>250000:raise RuntimeError('Catalog image too large')
    return path.stat().st_size
with concurrent.futures.ThreadPoolExecutor(max_workers=6) as executor:sizes=list(executor.map(prepare,manifest))
print('Verified and optimized',len(sizes),'source photographs;',sum(sizes),'bytes')
