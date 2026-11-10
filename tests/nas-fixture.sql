CREATE DATABASE nas_test;
USE nas_test;
CREATE TABLE products(id BIGINT PRIMARY KEY,brand VARCHAR(120),category VARCHAR(190),code VARCHAR(190),name VARCHAR(500),price DECIMAL(12,2),image_url TEXT,active TINYINT,created_at TIMESTAMP,updated_at TIMESTAMP);
INSERT INTO products VALUES (1,'Test','Panel','TEST-1','Fixture panel',100,NULL,1,NOW(),NOW()),(2,'Test','Panel','TEST-2','Disabled panel',NULL,NULL,0,NOW(),NOW());
CREATE TABLE pdg_electric_enquiries(id BIGINT PRIMARY KEY,public_ref CHAR(24),customer_name VARCHAR(160),email VARCHAR(190),phone VARCHAR(60),project_name VARCHAR(200),status VARCHAR(30),materials_cents BIGINT,created_at TIMESTAMP,updated_at TIMESTAMP,material_json MEDIUMTEXT);
INSERT INTO pdg_electric_enquiries VALUES(1,'aaaaaaaaaaaaaaaaaaaaaaaa','Fixture','test@example.invalid','','Fixture project','new',0,NOW(),NOW(),'{"source":"website_contact","message":"Fixture request"}');
CREATE TABLE pdg_orders(id BIGINT PRIMARY KEY,order_number VARCHAR(40),customer_name VARCHAR(120),email VARCHAR(254),phone VARCHAR(40),city VARCHAR(120),address VARCHAR(500),notes VARCHAR(2000),subtotal_cents BIGINT,vat_cents BIGINT,total_cents BIGINT,currency CHAR(3),status VARCHAR(30),created_at TIMESTAMP,updated_at TIMESTAMP,request_key CHAR(64));
INSERT INTO pdg_orders VALUES(1,'FIXTURE-1','Fixture','test@example.invalid','','','','',100,20,120,'EUR','new',NOW(),NOW(),'not-exported');
CREATE USER 'nas_reader'@'%' IDENTIFIED BY 'fixture-password';
GRANT SELECT ON nas_test.* TO 'nas_reader'@'%';

