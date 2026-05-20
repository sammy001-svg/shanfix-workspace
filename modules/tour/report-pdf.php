<?php
/**
 * Tour & Travel — Report PDF
 */
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/pdf.php';

requireModuleAccess('tour');
$user  = currentUser();
$orgId = (int)$user['org_id'];

// ── Summary ───────────────────────────────────────────────────────
$totalPackages  = countRows('tour_packages',     'org_id=?', [$orgId]);
$totalBookings  = countRows('tour_bookings',     'org_id=?', [$orgId]);
$totalCustomers = countRows('tour_customers',    'org_id=?', [$orgId]);

$revenue = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM tour_bookings WHERE org_id=? AND status='confirmed'");
    $stmt->execute([$orgId]);
    $revenue = (float)$stmt->fetchColumn();
} catch (Exception $e) {}

$summary = [
    ['label' => 'Packages',      'value' => number_format($totalPackages)],
    ['label' => 'Bookings',      'value' => number_format($totalBookings)],
    ['label' => 'Customers',     'value' => number_format($totalCustomers)],
    ['label' => 'Revenue',       'value' => formatCurrency($revenue)],
];

// ── Bookings list ─────────────────────────────────────────────────
$rows = [];
try {
    $stmt = $pdo->prepare("
        SELECT b.booking_date, b.travel_date, b.customer_name, b.num_persons,
               p.name AS package_name, b.total_amount, b.status
        FROM tour_bookings b
        LEFT JOIN tour_packages p ON b.package_id = p.id
        WHERE b.org_id = ?
        ORDER BY b.booking_date DESC
        LIMIT 100
    ");
    $stmt->execute([$orgId]);
    foreach ($stmt->fetchAll() as $r) {
        $rows[] = [
            date('d/m/Y', strtotime($r['booking_date'])),
            $r['travel_date'] ? date('d/m/Y', strtotime($r['travel_date'])) : '—',
            mb_strimwidth($r['customer_name'], 0, 28, '…'),
            mb_strimwidth($r['package_name'] ?? '—', 0, 38, '…'),
            number_format((int)$r['num_persons']),
            formatCurrency((float)$r['total_amount']),
            ucfirst($r['status']),
        ];
    }
} catch (Exception $e) {}

$cols = [
    ['label' => 'Booked',    'width' => 22, 'align' => 'L'],
    ['label' => 'Travel',    'width' => 22, 'align' => 'L'],
    ['label' => 'Customer',  'width' => 40, 'align' => 'L'],
    ['label' => 'Package',   'width' => 52, 'align' => 'L'],
    ['label' => 'Persons',   'width' => 14, 'align' => 'R'],
    ['label' => 'Amount',    'width' => 24, 'align' => 'R'],
    ['label' => 'Status',    'width' => 12, 'align' => 'L'],
];

generateModuleReportPDF(
    'Tour & Travel — Bookings Report',
    'As at ' . date('d M Y'),
    $summary,
    $cols,
    $rows,
    'Tour-Report-' . date('Ymd') . '.pdf',
    [22, 128, 133]  // tour teal
);
