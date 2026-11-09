import http.cookiejar
import json
import urllib.error
import urllib.request

BASE = 'http://127.0.0.1:8199/'
ORIGIN = 'https://pdgsystems.bg'


class LocalCookiePolicy(http.cookiejar.DefaultCookiePolicy):
    def return_ok_secure(self, cookie, request):
        return True


def client():
    return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(
        http.cookiejar.CookieJar(policy=LocalCookiePolicy())
    ))


def request(opener, path, payload=None, headers=None, expected=200):
    request_headers = {'Content-Type': 'application/json', **(headers or {})}
    req = urllib.request.Request(BASE + 'api/' + path,
        data=None if payload is None else json.dumps(payload).encode(),
        headers=request_headers)
    try:
        response = opener.open(req, timeout=10)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        body = json.load(response)
        assert response.code == expected, (path, response.code, body)
        return body


visitor = client()
admin = client()
request(visitor, 'enquiry-inbox.php', expected=401)
request(visitor, 'admin-site-metrics.php', expected=401)
session = request(admin, 'account-draft.php?action=session')
request(admin, 'account-draft.php?action=login',
    {'email':'admin@example.test','password':'Admin-test-123456'},
    {'X-CSRF-Token':session['csrf']})
baseline = request(admin, 'enquiry-inbox.php')['unread']
ticket = request(visitor, 'contact-enquiries.php')
payload = {'name':'Тест PDG', 'email':'pdg-test@example.test', 'message':'Тест за непрочетено запитване.',
           'website':'', 'request_key':ticket['request_key']}
receipt = request(visitor, 'contact-enquiries.php', payload, {'X-CSRF-Token':ticket['csrf']}, expected=201)
ref = receipt['reference']
inbox = request(admin, 'enquiry-inbox.php')
assert inbox['unread'] == baseline + 1
assert next(row for row in inbox['enquiries'] if row['public_ref'] == ref)['unread']
assert request(admin, 'enquiry-inbox.php')['unread'] == baseline + 1
request(admin, 'enquiry-inbox.php', {'reference':ref}, expected=403)
for _ in range(2):
    read = request(admin, 'enquiry-inbox.php', {'reference':ref}, {'X-CSRF-Token':inbox['csrf']})
    assert read['unread'] == baseline
assert not next(row for row in request(admin, 'enquiry-inbox.php')['enquiries'] if row['public_ref'] == ref)['unread']

event = {'event':'enquiry','source':'google','item':''}
request(visitor, 'site-metrics.php', event, {'Origin':ORIGIN}, expected=403)
request(visitor, 'site-metrics.php', event, {'Origin':ORIGIN,'Cookie':'pdg_statistics=yes'})
request(visitor, 'site-metrics.php', event, {'Origin':ORIGIN,'Cookie':'pdg_statistics=no'}, expected=403)
metrics = request(admin, 'admin-site-metrics.php')
assert any(row['event_name']=='enquiry' and row['source']=='google' and int(row['total'])==1 for row in metrics['events'])
print('PASS: contact form, unread count, explicit read, repeat read, consent and admin-only metrics')

workflow = {'action':'workflow','reference':ref,'stage':'quoted','follow_up':'2026-11-20','decline_reason':'price','note':'Вътрешна бележка <script>test</script>'}
request(visitor, 'enquiry-inbox.php', workflow, expected=401)
request(admin, 'enquiry-inbox.php', workflow, expected=403)
request(admin, 'enquiry-inbox.php', workflow, {'X-CSRF-Token':inbox['csrf']})
row = next(row for row in request(admin, 'enquiry-inbox.php')['enquiries'] if row['public_ref'] == ref)
for key in ['stage','follow_up','decline_reason','note']:
    assert row[key] == workflow[key], (key, row)
for changed in [{'stage':'admin'}, {'follow_up':'2026-02-30'}, {'decline_reason':'untrusted'}, {'note':'x'*2001}]:
    request(admin, 'enquiry-inbox.php', {**workflow, **changed}, {'X-CSRF-Token':inbox['csrf']}, expected=422)
request(admin, 'enquiry-inbox.php', {**workflow,'stage':'completed','follow_up':'','decline_reason':'','note':''}, {'X-CSRF-Token':inbox['csrf']})
row = next(row for row in request(admin, 'enquiry-inbox.php')['enquiries'] if row['public_ref'] == ref)
assert row['stage'] == 'completed' and row['follow_up'] is None and not row['decline_reason'] and not row['note']
print('PASS: admin-only workflow, CSRF, exact follow-up dates, safe notes, invalid input rejection and clearing metadata')
