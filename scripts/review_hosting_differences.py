"""Export selected deployed application source after rejecting credential literals."""
import base64,ftplib,io,json,os,re,ssl
FILES=['catalog-products.js','electrical-quote.js','electrical-cad-design.js','api/shop-common.php','api/electric-catalog.php','api/admin-users.php','api/products.php','api/cart.php','api/electric-enquiries.php','api/electric-common.php','api/checkout.php','api/customer-pricing.php','electrical-schematic-symbols.js','electrical-dwg-plan.js','.htaccess','database/.htaccess','logo.svg']
with ftplib.FTP_TLS(context=ssl.create_default_context(),timeout=45) as ftp:
 ftp.connect(os.environ['FTP_HOST'],21);ftp.login(os.environ['FTP_USER'],os.environ['FTP_PASSWORD']);ftp.prot_p();ftp.cwd('/')
 for name in FILES:
  buffer=io.BytesIO();ftp.retrbinary('RETR '+name,buffer.write);data=buffer.getvalue()
  if len(data)>500000:raise RuntimeError('Source review limit')
  if re.search(rb"(?:password|secret|token|api[_-]?key)\s*['\"]?\s*(?:=>|=|:)\s*['\"][^'\"]{8,}['\"]|AIza[0-9A-Za-z_-]{25,}|-----BEGIN (?:RSA |EC )?PRIVATE KEY",data,re.I):
   print(json.dumps({'source_review_excluded':name,'reason':'potential credential literal'}));continue
  print('PDG_REVIEW_SOURCE '+json.dumps({'path':name,'base64':base64.b64encode(data).decode('ascii')}),flush=True)
