<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/pdf.php';

requireSuperAdmin();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(404); exit('Invoice not found'); }

$stmt = $pdo->prepare("SELECT i.*, o.name, o.email, o.phone, o.city, o.country FROM invoices i JOIN organizations o ON i.org_id = o.id WHERE i.id = ?");
$stmt->execute([$id]);
$invoice = $stmt->fetch();

if (!$invoice) { http_response_code(404); exit('Invoice not found'); }

$org = [
    'name'    => $invoice['name'],
    'email'   => $invoice['email'],
    'phone'   => $invoice['phone'],
    'city'    => $invoice['city'],
    'country' => $invoice['country'],
];

generateInvoicePDF($invoice, $org);
