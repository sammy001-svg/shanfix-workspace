<?php
/**
 * Tour & Travel — Printable Invoice
 * URL: invoice-pdf.php?id=123
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/_lib.php';

sendSecurityHeaders();
requireModuleAccess('tour');
$user  = currentUser();
$orgId = (int)$user['org_id'];

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    setFlash('danger', 'No invoice specified.');
    redirect('invoices.php');
}

tourSyncInvoice($orgId, $id);   // print the current position, not a stale one

$stmt = $pdo->prepare("
    SELECT i.*, b.booking_no, b.travel_date, b.adults, b.children,
           p.name AS package_name, p.duration_days,
           dest.name AS dest_name, d.departure_code, d.start_date AS departure_start
    FROM tour_invoices i
    LEFT JOIN tour_bookings b        ON b.id = i.booking_id
    LEFT JOIN tour_packages p        ON p.id = b.package_id
    LEFT JOIN tour_destinations dest ON dest.id = p.destination_id
    LEFT JOIN tour_departures d      ON d.id = b.departure_id
    WHERE i.id = ? AND i.org_id = ?
    LIMIT 1
");
$stmt->execute([$id, $orgId]);
$inv = $stmt->fetch();

if (!$inv) {
    setFlash('danger', 'Invoice not found.');
    redirect('invoices.php');
}

$items = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM tour_invoice_items WHERE invoice_id=? AND org_id=? ORDER BY sort_order");
    $stmt->execute([$id, $orgId]);
    $items = $stmt->fetchAll();
} catch (Throwable $e) {}

$org = [];
try {
    $stmt = $pdo->prepare("SELECT name, email, phone, city, country, logo FROM organizations WHERE id=? LIMIT 1");
    $stmt->execute([$orgId]);
    $org = $stmt->fetch() ?: [];
} catch (Throwable $e) {}

if (tourConf($orgId, 't_company_name') !== '')  $org['name']  = tourConf($orgId, 't_company_name');
if (tourConf($orgId, 't_contact_phone') !== '') $org['phone'] = tourConf($orgId, 't_contact_phone');
if (tourConf($orgId, 't_contact_email') !== '') $org['email'] = tourConf($orgId, 't_contact_email');
$org['tagline'] = tourConf($orgId, 't_tagline');

$dateRows = [
    ['Invoice #', $inv['invoice_no']],
    ['Issued',    formatDate($inv['issue_date'])],
    ['Due',       $inv['due_date'] ? formatDate($inv['due_date']) : 'On receipt'],
];

$pax = (int)$inv['adults'] + (int)$inv['children'];
$tripRows = array_values(array_filter([
    $inv['booking_no']     ? ['Booking',     $inv['booking_no']]     : null,
    $inv['package_name']   ? ['Package',     $inv['package_name']]   : null,
    $inv['dest_name']      ? ['Destination', $inv['dest_name']]      : null,
    $inv['departure_code'] ? ['Departure',   $inv['departure_code'] . ' (' . formatDate($inv['departure_start']) . ')'] : null,
    $inv['travel_date']    ? ['Travel Date', formatDate($inv['travel_date'])] : null,
    $pax > 0               ? ['Travellers',  $pax . ' (' . (int)$inv['adults'] . ' adult, ' . (int)$inv['children'] . ' child)'] : null,
]));

$paid    = (float)$inv['amount_paid'];
$total   = (float)$inv['total_amount'];

$docType   = 'INVOICE';
$docNo     = $inv['invoice_no'];
$docStatus = $inv['status'];
$party     = ['name' => $inv['customer_name'], 'phone' => $inv['customer_phone'], 'email' => $inv['customer_email']];
$totals    = [
    'subtotal'   => (float)$inv['subtotal'],
    'discount'   => (float)$inv['discount'],
    'tax_label'  => tourConf($orgId, 't_tax_label'),
    'tax_rate'   => (float)$inv['tax_rate'],
    'tax_amount' => (float)$inv['tax_amount'],
    'total'      => $total,
    'paid'       => $paid,
    'balance'    => max(0, $total - $paid),
];
$notes   = (string)$inv['notes'];
$terms   = (string)$inv['terms'];
$backUrl = 'invoices.php';
$accent  = '#2980b9';

require __DIR__ . '/_document.php';
