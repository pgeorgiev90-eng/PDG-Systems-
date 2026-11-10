"""Isolated PHP API tests; optional MySQL fixture in CI, never production."""
import hashlib
import hmac
import json
import os
import shutil
import socket
import subprocess
import tempfile
import time
import unittest
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

SOURCE = Path(__file__).resolve().parents[1] / 'api' / 'nas-export.php'
SECRET = 'a' * 64


class ExportTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.directory = tempfile.TemporaryDirectory()
        cls.root = Path(cls.directory.name)
        cls.public = cls.root / 'public_html'
        (cls.public / 'api').mkdir(parents=True)
        shutil.copyfile(SOURCE, cls.public / 'api' / 'nas-export.php')
        cls.auth = cls.root / 'pdg-nas-integration.php'
        (cls.public / 'router.php').write_text("<?php $_SERVER['HTTPS']='on'; require __DIR__.'/api/nas-export.php';",encoding='utf-8')
        host = os.environ.get('NAS_TEST_MYSQL_HOST','127.0.0.1')
        (cls.root / 'pdg-shop-db.php').write_text("<?php return ['host'=>"+repr(host)+",'database'=>'nas_test','username'=>'nas_reader','password'=>'fixture-password'];",encoding='utf-8')
        with socket.socket() as sock:
            sock.bind(('127.0.0.1',0));cls.port=sock.getsockname()[1]
        cls.server=subprocess.Popen([os.environ.get('PHP_BIN','php'),'-d','display_errors=0','-S',f'127.0.0.1:{cls.port}','router.php'],cwd=cls.public,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        for _ in range(50):
            try:
                urllib.request.urlopen(f'http://127.0.0.1:{cls.port}/',timeout=.2)
            except urllib.error.HTTPError: break
            except OSError: time.sleep(.1)
        else: raise RuntimeError('PHP server did not start')

    @classmethod
    def tearDownClass(cls):
        cls.server.terminate();cls.server.wait(timeout=5);cls.directory.cleanup()

    def setUp(self):
        self.auth.write_text("<?php return ['enabled'=>true,'key_id'=>'test','secret'=>'"+SECRET+"'];",encoding='utf-8')

    def call(self, resource='products', after='0', limit='25', method='GET', stamp=None, headers=None, extra=''):
        stamp=str(int(time.time()) if stamp is None else stamp);nonce='b'*32
        canonical=f'GET\n/api/nas-export.php\nresource={resource}&after_id={after}&limit={limit}\n{stamp}\n{nonce}'
        auth={'X-PDG-Key-ID':'test','X-PDG-Timestamp':stamp,'X-PDG-Nonce':nonce,
              'X-PDG-Signature':hmac.new(SECRET.encode(),canonical.encode(),hashlib.sha256).hexdigest()}
        auth.update(headers or {})
        query=urllib.parse.urlencode({'resource':resource,'after_id':after,'limit':limit})+extra
        request=urllib.request.Request(f'http://127.0.0.1:{self.port}/api/nas-export.php?{query}',headers=auth,method=method)
        try: response=urllib.request.urlopen(request,timeout=5)
        except urllib.error.HTTPError as error: response=error
        return response.status,json.load(response)

    def test_rejects_writes(self): self.assertEqual(self.call(method='POST')[0],405)
    def test_rejects_wrong_signature(self): self.assertEqual(self.call(headers={'X-PDG-Signature':'0'*64})[0],401)
    def test_rejects_expired_signature(self): self.assertEqual(self.call(stamp=int(time.time())-120)[0],401)
    def test_rejects_future_signature(self): self.assertEqual(self.call(stamp=int(time.time())+120)[0],401)
    def test_rejects_wrong_key(self): self.assertEqual(self.call(headers={'X-PDG-Key-ID':'unknown'})[0],401)
    def test_rejects_invalid_resource(self): self.assertEqual(self.call(resource='users')[0],422)
    def test_rejects_array_parameter(self): self.assertEqual(self.call(extra='&resource[]=products')[0],422)
    def test_rejects_oversize_page(self): self.assertEqual(self.call(limit='101')[0],422)
    def test_rejects_negative_cursor(self): self.assertEqual(self.call(after='-1')[0],422)
    def test_rejects_unknown_parameters(self): self.assertEqual(self.call(extra='&sql=SELECT')[0],422)
    def test_disabled_by_default(self):
        self.auth.write_text("<?php return ['enabled'=>false];",encoding='utf-8')
        self.assertEqual(self.call()[0],503)
    def test_missing_secret_configuration(self):
        self.auth.unlink();self.assertEqual(self.call()[0],503)

    @unittest.skipUnless(os.environ.get('NAS_TEST_MYSQL_HOST'),'Requires isolated MySQL fixture')
    def test_signed_read_pagination_soft_disable_and_inquiries(self):
        status,body=self.call(limit='1')
        self.assertEqual(status,200);self.assertEqual(body['items'][0]['code'],'TEST-1');self.assertTrue(body['has_more'])
        status,page2=self.call(after=str(body['next_after_id']),limit='1')
        self.assertEqual(status,200);self.assertEqual(page2['items'][0]['active'],0);self.assertFalse(page2['has_more'])
        self.assertNotIn('password_hash',str(body));self.assertNotIn('request_key',str(body))
        status,inquiries=self.call(resource='inquiries')
        self.assertEqual(status,200);self.assertEqual(inquiries['items'][0]['message'],'Fixture request')
        self.assertNotIn('material_json',inquiries['items'][0])
        status,orders=self.call(resource='orders')
        self.assertEqual(status,200);self.assertEqual(orders['items'][0]['order_number'],'FIXTURE-1')
        self.assertNotIn('request_key',orders['items'][0])


if __name__=='__main__': unittest.main()

