<?php
require 'config/database.php';
try {
    $pdo->prepare('INSERT INTO pos_shifts (org_id,cashier_id,cashier_name,shift_date,start_time,opening_float,status,notes) VALUES (?,?,?,CURDATE(),NOW(),?,?,?)')->execute([1,1,'Admin',0,'open','test']); 
    echo 'Inserted';
} catch (Exception $e) {
    echo $e->getMessage();
}
