# NAS read-only integration

The endpoint `/api/nas-export.php` exposes only products, electric/contact enquiries and orders to the NAS. It does not load existing bootstrap helpers, change prices, mark enquiries as read, modify order statuses or create database tables. Each authenticated request uses a read-only MySQL transaction.

The endpoint is disabled without `pdg-nas-integration.php`, placed outside the document root alongside the existing `pdg-shop-db.php`. A disabled placeholder example is included. Real secrets must never be committed or placed under public_html.

The service key authorizes read access to all three resources and therefore includes customer contact and order data. Revoke it by setting `enabled=false` or rotating the secret. Prefer a separate SELECT-only database account for this endpoint when provisioning permissions; the initial implementation reuses the existing connection config in a read-only transaction.

## Contract

`GET /api/nas-export.php?resource=products&after_id=0&limit=25`

Resources: products, inquiries, orders. IDs are ascending, `after_id` is exclusive; limit 1–100. The response includes `version=1`, resource, items, next_after_id and has_more. Soft-disabled products remain in the export with active=0. Hard deletions are not propagated. Enquiries export contact/message metadata, not complete material or CAD project JSON. Orders export the order header; order line items are a later extension.

Headers: X-PDG-Key-ID, X-PDG-Timestamp (Unix seconds), X-PDG-Nonce (32 lowercase hex characters), X-PDG-Signature (64 lowercase hex characters).

HMAC-SHA256 uses the 64-character hexadecimal secret as a UTF-8 string, not decoded bytes. Canonical content, with LF separators and no final newline:

```text
GET
/api/nas-export.php
resource=products&after_id=0&limit=25
TIMESTAMP
NONCE
```

The 90-second timestamp window requires synchronized clocks. HTTPS protects the request and response. The nonce is signed but not persisted: identical read requests can be replayed within the timestamp window. No writes are supported. Keys and signature headers must be excluded from HTTP debug logs.

## NAS behavior

The prepared NAS module performs one page per resource every five minutes once a service key is configured and the application is restarted. A complete scan wraps back to ID zero to discover updates. At 25 records per page, large catalogs take multiple runs to complete a scan; adjust deliberately after measuring source load. Completion time depends on catalog size. Cursors are advanced in the same SQLite transaction as the imported page.

Records are unique by resource and source ID. Products and orders are retained as read-only source snapshots in the website-data screen. A new source enquiry creates one local CRM enquiry. Later sync never overwrites operator changes to that local enquiry's status, notes or customer link; the latest source data remains separately available. Customers are not automatically merged by email. Hard deletions, payments, order updates and price writes are not synchronized.

## Validation and deployment

Local validation: PHP syntax and 12 authorization/input tests passed; the MySQL read test requires the isolated fixture workflow. Python NAS import tests cover signatures, idempotence, local-note preservation, pagination validation and rejecting redirects. CI creates a disposable MySQL database and a SELECT-only reader; it never connects to production.

This change is prepared for review and must not be considered an active integration until the hosting endpoint, private key config, upgraded NAS code and a real authenticated synchronization are verified. Do not run the repository's full production deployment to publish this one endpoint: it can publish unrelated pending changes. Deploy only the reviewed endpoint after approval and preserve the current file for rollback if one already exists.

Activation steps after approval:

1. Publish only api/nas-export.php to the matching website path over verified FTPS/SFTP.
2. Generate a random 32-byte key on the NAS. Store the hex secret in data/website-key.json, mode 600, owned by the application UID. Keep it disabled initially.
3. Create pdg-nas-integration.php outside public_html with matching key_id/secret and enabled=true. Do not expose the production DB password to the NAS.
4. Upgrade the NAS application with a verified backup and the existing HTTPS settings preserved.
5. Enable the NAS key, restart the application, perform a manual sync and verify row counts and source/local mappings. Only then report the integration as connected.
6. Retain off-NAS backups and document retention for imported customer data.

