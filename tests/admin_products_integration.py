from admin_overview_security import admin, client, customer, request

endpoint = 'api/admin-products-draft.php'
assert request(client(), endpoint)[0] == 401
assert request(customer, endpoint)[0] == 403
_, session = request(admin, 'api/account-draft.php?action=session')
csrf = session['csrf']

draft = {
    'id': 0, 'active': False, 'name': 'Тестов соларен панел', 'brand': 'PDG Test',
    'category': 'Соларни панели', 'code': 'PDG-TEST-001', 'price': '87.15',
    'image_url': 'https://example.test/panel.webp',
    'fields': [
        {'label': 'Описание', 'value': 'Продуктово описание.'},
        {'label': 'Мощност', 'value': '585 W'},
    ],
}
assert request(admin, endpoint, draft)[0] == 403
assert request(admin, endpoint, {**draft, 'image_url': 'javascript:alert(1)'}, csrf)[0] == 422
status, saved = request(admin, endpoint, draft, csrf)
assert status == 200, saved
product_id = saved['id']
detail = endpoint + '?id=' + str(product_id)
status, original = request(admin, detail)
assert status == 200 and int(original['product']['active']) == 0, original
assert original['fields'] == draft['fields'], original
assert request(client(), 'api/product-details.php?id=' + str(product_id))[0] == 404

published = {**draft, 'id': product_id, 'active': True, 'version': original['version']}
published['fields'] = [{**draft['fields'][0], 'value': 'Ново описание.'}, draft['fields'][1]]
assert request(admin, endpoint, published, csrf)[0] == 200
assert request(admin, endpoint, {**published, 'active': False}, csrf)[0] == 409
_, changed = request(admin, detail)
assert int(changed['product']['active']) == 1 and changed['fields'] == published['fields']
status, public = request(client(), 'api/product-details.php?id=' + str(product_id))
assert status == 200 and public['product']['description'] == 'Ново описание.', public
print('PASS: authenticated catalog writes, drafts, publishing, fields and concurrent edit protection')
