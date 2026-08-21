<?php
/**
 * Tour & Travel — Report PDF
 */
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/pdf.php';
require_once __DIR__ . '/_lib.php';

requireModuleAccess('tour');
if (!canAccessModulePage('tour', 'report-pdf')) {
    setFlash('danger', 'Your assigned role does not allow access to this document.');
    redirect('reports.php');
}
$user  = currentUser();
$orgId = (int)$user['org_id'];

// ── Summary ───────────────────────────────────────────────────────
$totalPackages   = countRows('tour_packages',   'org_id=?', [$orgId]);
$totalBookings   = countRows('tour_bookings',   'org_id=?', [$orgId]);
$totalCustomers  = countRows('tour_customers',  'org_id=?', [$orgId]);
$totalDepartures = countRows('tour_departures', 'org_id=?', [$orgId]);

$revenue = $collected = $costOfSales = 0.0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0), COALESCE(SUM(paid_amount),0) FROM tour_bookings WHERE org_id=? AND status IN ('confirmed','completed')");
    $stmt->execute([$orgId]);
    [$revenue, $collected] = array_map('floatval', array_values($stmt->fetch(PDO::FETCH_NUM) ?: [0, 0]));

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(cost),0) FROM tour_supplier_bookings WHERE org_id=? AND status <> 'cancelled'");
    $stmt->execute([$orgId]);
    $costOfSales = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM tour_expenses WHERE org_id=?");
    $stmt->execute([$orgId]);
    $costOfSales += (float)$stmt->fetchColumn();
} catch (Exception $e) {}

$summary = [
    ['label' => 'Bookings',   'value' => number_format($totalBookings)],
    ['label' => 'Departures', 'value' => number_format($totalDepartures)],
    ['label' => 'Packages',   'value' => number_format($totalPackages)],
    ['label' => 'Customers',  'value' => number_format($totalCustomers)],
    ['label' => 'Sales',      'value' => formatCurrency($revenue)],
    ['label' => 'Collected',  'value' => formatCurrency($collected)],
    ['label' => 'Cost',       'value' => formatCurrency($costOfSales)],
    ['label' => 'Margin',     'value' => formatCurrency($revenue - $costOfSales)],
];

// ── Bookings list ─────────────────────────────────────────────────
$rows = [];
try {
    $stmt = $pdo->prepare("
        SELECT b.booking_no, b.created_at, b.travel_date, b.customer_name,
               b.adults, b.children, b.total_amount, b.paid_amount, b.status,
               p.name AS package_name, d.departure_code
        FROM tour_bookings b
        LEFT JOIN tour_packages p   ON p.id = b.package_id
        LEFT JOIN tour_departures d ON d.id = b.departure_id
        WHERE b.org_id = ?
        ORDER BY b.travel_date DESC, b.id DESC
        LIMIT 100
    ");
    $stmt->execute([$orgId]);
    foreach ($stmt->fetchAll() as $r) {
        $rows[] = [
            $r['booking_no'],
            $r['travel_date'] ? date('d/m/Y', strtotime($r['travel_date'])) : '—',
            mb_strimwidth((string)$r['customer_name'], 0, 24, '…'),
            mb_strimwidth((string)($r['package_name'] ?? '—'), 0, 32, '…'),
            $r['departure_code'] ?: 'Private',
            number_format((int)$r['adults'] + (int)$r['children']),
            formatCurrency((float)$r['total_amount']),
            formatCurrency(max(0, (float)$r['total_amount'] - (float)$r['paid_amount'])),
            ucfirst((string)$r['status']),
        ];
    }
} catch (Exception $e) {}

$cols = [
    ['label' => 'Ref',       'width' => 24, 'align' => 'L'],
    ['label' => 'Travel',    'width' => 20, 'align' => 'L'],
    ['label' => 'Customer',  'width' => 34, 'align' => 'L'],
    ['label' => 'Package',   'width' => 40, 'align' => 'L'],
    ['label' => 'Departure', 'width' => 24, 'align' => 'L'],
    ['label' => 'Pax',       'width' => 12, 'align' => 'R'],
    ['label' => 'Total',     'width' => 24, 'align' => 'R'],
    ['label' => 'Balance',   'width' => 24, 'align' => 'R'],
    ['label' => 'Status',    'width' => 18, 'align' => 'L'],
];

generateModuleReportPDF(
    'Tour & Travel — Bookings Report',
    'As at ' . date('d M Y'),
    $summary,
    $cols,
    $rows,
    'Tour-Report-' . date('Ymd') . '.pdf',
    [41, 128, 185]  // tour blue
);
