<?php
$p = new PDO('mysql:host=127.0.0.1;dbname=shanfix_db', 'root', '');
$p->exec("CREATE TABLE IF NOT EXISTS crm_leads (
  id int(11) NOT NULL AUTO_INCREMENT,
  org_id int(11) NOT NULL,
  contact_id int(11) DEFAULT NULL,
  first_name varchar(100) NOT NULL,
  last_name varchar(100) NOT NULL DEFAULT '',
  email varchar(255) DEFAULT NULL,
  phone varchar(25) DEFAULT NULL,
  company varchar(255) DEFAULT NULL,
  source varchar(100) DEFAULT NULL,
  status enum('new','contacted','qualified','converted','lost') NOT NULL DEFAULT 'new',
  assigned_to int(11) DEFAULT NULL,
  notes text,
  created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY org_id (org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
echo 'crm_leads created OK' . PHP_EOL;
