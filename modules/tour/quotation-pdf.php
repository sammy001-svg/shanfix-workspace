<?php
/**
 * Tour & Travel — Printable Quotation
 * URL: quotation-pdf.php?id=123
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/_lib.php';

sendSecurityHeaders();
requireModuleAccess('tour');
if (!canAccessModulePage('tour', 'quotation-pdf')) {
    setFlash('danger', 'Your assigned role does not allow access to this document.');
    redirect('quotations.php');
}
$user  = currentUser();
$orgId = (int)$user['org_id'];

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    setFlash('danger', 'No quotation specified.');
    redirect('quotations.php');
}

$stmt = $pdo->prepare("
    SELECT q.*, p.name AS package_name, p.duration_days,
           dest.name AS dest_name, d.departure_code, d.start_date AS departure_start
    FROM tour_quotations q
    LEFT JOIN tour_packages p        ON p.id = q.package_id
    LEFT JOIN tour_destinations dest ON dest.id = p.destination_id
    LEFT JOIN tour_departures d      ON d.id = q.departure_id
    WHERE q.id = ? AND q.org_id = ?
    LIMIT 1
");
$stmt->execute([$id, $orgId]);
$q = $stmt->fetch();

if (!$q) {
    setFlash('danger', 'Quotation not found.');
    redirect('quotations.php');
}

$items = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM tour_quotation_items WHERE quotation_id=? AND org_id=? ORDER BY sort_order");
    $stmt->execute([$id, $orgId]);
    $items = $stmt->fetchAll();
} catch (Throwable $e) {}

$org = [];
try {
    $stmt = $pdo->prepare("SELECT name, email, phone, city, country, logo FROM organizations WHERE id=? LIMIT 1");
    $stmt->execute([$orgId]);
    $org = $stmt->fetch() ?: [];
} catch (Throwable $e) {}

// Module settings override the org record for traveller-facing documents
if (tourConf($orgId, 't_company_name') !== '') $org['name']  = tourConf($orgId, 't_company_name');
if (tourConf($orgId, 't_contact_phone') !== '') $org['phone'] = tourConf($orgId, 't_contact_phone');
if (tourConf($orgId, 't_contact_email') !== '') $org['email'] = tourConf($orgId, 't_contact_email');
$org['tagline'] = tourConf($orgId, 't_tagline');

$pax = (int)$q['adults'] + (int)$q['children'];

$dateRows = [
    ['Quotation #', $q['quote_no']],
    ['Issued',      formatDate($q['created_at'])],
    ['Valid Until', $q['valid_until'] ? formatDate($q['valid_until']) : 'On request'],
];

$tripRows = array_values(array_filter([
    $q['package_name']    ? ['Package',     $q['package_name']] : null,
    $q['dest_name']       ? ['Destination', $q['dest_name']]    : null,
    $q['departure_code']  ? ['Departure',   $q['departure_code'] . ' (' . formatDate($q['departure_start']) . ')'] : null,
    $q['travel_date']     ? ['Travel Date', formatDate($q['travel_date'])] : null,
    $q['duration_days']   ? ['Duration',    (int)$q['duration_days'] . ' days'] : null,
    ['Travellers', $pax . ' (' . (int)$q['adults'] . ' adult, ' . (int)$q['children'] . ' child)'],
]));

$docType   = 'QUOTATION';
$docNo     = $q['quote_no'];
$docStatus = $q['status'];
$party     = ['name' => $q['customer_name'], 'phone' => $q['customer_phone'], 'email' => $q['customer_email']];
$totals    = [
    'subtotal'   => (float)$q['subtotal'],
    'discount'   => (float)$q['discount'],
    'tax_label'  => tourConf($orgId, 't_tax_label'),
    'tax_rate'   => (float)$q['tax_rate'],
    'tax_amount' => (float)$q['tax_amount'],
    'total'      => (float)$q['total_amount'],
];
$notes   = (string)$q['notes'];
$terms   = (string)$q['terms'];
$backUrl = 'quotations.php';
$accent  = '#2980b9';

require __DIR__ . '/_document.php';
