<?php
/**
 * Client Invoice PDF Download
 * GET: invoice-pdf.php?id=INVOICE_ID
 */
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/pdf.php';

requireLogin();
$user  = currentUser();
$orgId = (int)$user['org_id'];

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(404); exit('Invoice not found'); }

// Fetch invoice (must belong to this org)
$stmt = $pdo->prepare("
    SELECT i.*, o.name, o.email, o.phone, o.city, o.country
    FROM invoices i
    JOIN organizations o ON i.org_id = o.id
    WHERE i.id = ? AND i.org_id = ?
");
$stmt->execute([$id, $orgId]);
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
