import hashlib
import json
import os
import urllib.request
import urllib.error
from pathlib import Path

base = 'https://pdgsystems.bg/'
def read(path):
    separator = '&' if '?' in path else '?'
    return urllib.request.urlopen(base + path + separator + 'release=' + os.environ['GITHUB_SHA'], timeout=40)

for path in ['shopping-tools.html','admin-growth.html','help-center.html','panel-projects.html',
             'auth.html','admin-preview.html','profile-preview.html','shopping-tools.js','admin-growth.js','cad-document.js','cad-shopping.js','store.js']:
    with read(path) as response:
        assert response.status == 200
        assert hashlib.sha256(response.read()).digest() == hashlib.sha256(Path(path).read_bytes()).digest(), path
    print('Verified exact production file:', path)
for path in ['api/customer-tools.php','api/admin-growth.php','api/admin-backup.php','api/request-files.php?id=1']:
    try:
        read(path)
        raise AssertionError('Anonymous access unexpectedly accepted: ' + path)
    except urllib.error.HTTPError as error:
        assert error.code == 401, (path, error.code)
    print('Verified authenticated boundary:', path)
with read('api/products.php') as response:
    data = json.load(response)
    assert len(data['products']) >= 905
    assert data['prices_visible'] is False
    assert all(p['price'] is None and p['description'] for p in data['products'])
    print('Verified complete public descriptions and private prices:', len(data['products']))
with read('api/commerce.php') as response:
    data = json.load(response)
    assert 'cost_cents' not in str(data) and 'min_margin_bp' not in str(data)
    print('Verified private operational fields stay private')
