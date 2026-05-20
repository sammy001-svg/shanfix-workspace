<?php
$p = new PDO('mysql:host=127.0.0.1;dbname=shanfix_db','root','');
// Check if order_items / quote_items exist
foreach(['sales_order_items','sales_quote_items'] as $t){
    try{$s=$p->query('DESCRIBE '.$t);echo '--- '.$t.' ---'.PHP_EOL;foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r)echo $r['Field'].' ('.$r['Type'].')'.PHP_EOL;}
    catch(Exception $e){echo $t.' : NOT FOUND'.PHP_EOL;}
    echo PHP_EOL;
}
// Create missing tables
$p->exec("CREATE TABLE IF NOT EXISTS sales_order_items (
  id int(11) NOT NULL AUTO_INCREMENT,
  order_id int(11) NOT NULL,
  product_id int(11) DEFAULT NULL,
  description varchar(255) NOT NULL DEFAULT '',
  qty decimal(10,2) NOT NULL DEFAULT 1,
  unit_price decimal(12,2) NOT NULL DEFAULT 0,
  tax_rate decimal(5,2) NOT NULL DEFAULT 0,
  total decimal(12,2) NOT NULL DEFAULT 0,
  PRIMARY KEY(id), KEY order_id(order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
$p->exec("CREATE TABLE IF NOT EXISTS sales_quote_items (
  id int(11) NOT NULL AUTO_INCREMENT,
  quote_id int(11) NOT NULL,
  product_id int(11) DEFAULT NULL,
  description varchar(255) NOT NULL DEFAULT '',
  qty decimal(10,2) NOT NULL DEFAULT 1,
  unit_price decimal(12,2) NOT NULL DEFAULT 0,
  tax_rate decimal(5,2) NOT NULL DEFAULT 0,
  total decimal(12,2) NOT NULL DEFAULT 0,
  PRIMARY KEY(id), KEY quote_id(quote_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
echo 'sales_order_items and sales_quote_items ensured OK'.PHP_EOL;
