import json,urllib.request,urllib.error
base='http://127.0.0.1:8091/'
with urllib.request.urlopen(base+'api/catalog-facets.php') as r:d=json.load(r)
items=d['items'];assert '1' in items and '3' not in items
assert items['1']['specs']['Номинална AC мощност (kW)']=='10'
assert 'Източник' not in items['1']['specs'] and 'Описание' not in items['1']['specs']
assert items['1']['availability']=='in_stock' and items['2']['availability']=='confirm'
assert 'cost_cents' not in json.dumps(d) and '1999' not in json.dumps(d)
try:urllib.request.urlopen(urllib.request.Request(base+'api/catalog-facets.php',data=b'{}'));raise AssertionError()
except urllib.error.HTTPError as e:assert e.code==405
print('PASS: guest facets, active products only, public technical fields, no supplier provenance or costs, known/unknown availability and read-only endpoint')
