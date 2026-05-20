<?php
$p = new PDO('mysql:host=127.0.0.1;dbname=shanfix_db','root','');
foreach(['sales_customers','sales_products','sales_orders','sales_quotes'] as $t){
    echo '--- '.$t.' ---'.PHP_EOL;
    $s=$p->query('DESCRIBE '.$t);
    foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r) echo $r['Field'].' ('.$r['Type'].')'.PHP_EOL;
    echo PHP_EOL;
}
