<?php
/**
 * Admin Invoice — HTML view + print to PDF (no org_id restriction)
 * GET: invoice-pdf.php?id=INVOICE_ID
 */
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

requireSuperAdmin();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(404); die('<p style="font-family:sans-serif;padding:2rem">Invoice not found.</p>'); }

// Fetch invoice — admin can view any org's invoice
$stmt = $pdo->prepare("
    SELECT i.id, i.org_id, i.subscription_id, i.module_id,
           i.invoice_number, i.status,
           CAST(i.amount AS DECIMAL(12,2)) AS amount,
           CAST(i.tax    AS DECIMAL(12,2)) AS tax,
           CAST(i.total  AS DECIMAL(12,2)) AS total,
           i.due_date, i.paid_at, i.notes, i.created_at,
           o.name    AS org_name,
           o.email   AS org_email,
           o.phone   AS org_phone,
           o.address AS org_address,
           o.city    AS org_city,
           o.country AS org_country
    FROM invoices i
    JOIN organizations o ON i.org_id = o.id
    WHERE i.id = ?
");
$stmt->execute([$id]);
$invoice = $stmt->fetch();

if (!$invoice) { http_response_code(404); die('<p style="font-family:sans-serif;padding:2rem">Invoice not found.</p>'); }

$org = [
    'name'    => $invoice['org_name'],
    'email'   => $invoice['org_email'],
    'phone'   => $invoice['org_phone'],
    'address' => $invoice['org_address'],
    'city'    => $invoice['org_city'],
    'country' => $invoice['org_country'],
];

// Fetch line items
$items = [];
try {
    $s = $pdo->prepare("
        SELECT imi.amount,
               m.name        AS module_name,
               m.description AS module_description,
               1             AS qty,
               imi.amount    AS price
        FROM invoice_module_items imi
        JOIN modules m ON imi.module_id = m.id
        WHERE imi.invoice_id = ?
        ORDER BY m.name
    ");
    $s->execute([$id]);
    $items = $s->fetchAll();
} catch (Exception $e) {}

// Load billing settings
$cfg = getSettings([
    'invoice_tax_rate', 'invoice_footer', 'invoice_notes',
    'mpesa_paybill', 'mpesa_shortcode', 'mpesa_account_ref',
    'bank_name', 'bank_account', 'bank_branch',
    'company_address', 'company_website', 'support_email',
]);

// Inject variables for the shared renderer
$invoiceBackUrl   = APP_URL . '/admin/invoices.php';
$invoiceAdminMode = true;

require_once __DIR__ . '/../includes/invoice-html.php';
