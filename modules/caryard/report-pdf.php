<?php
/**
 * Car Yard — Sales Report PDF
 * GET: report-pdf.php  (no extra params needed)
 */
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/pdf.php';

requireModuleAccess('caryard');
$user  = currentUser();
$orgId = (int)$user['org_id'];

// ── Summary stats ─────────────────────────────────────────────────
$totalVehicles = countRows('caryard_vehicles', 'org_id=?', [$orgId]);
$totalSales    = countRows('caryard_sales',    'org_id=?', [$orgId]);

$revenue = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(sale_price),0) FROM caryard_sales WHERE org_id=?");
    $stmt->execute([$orgId]);
    $revenue = (float)$stmt->fetchColumn();
} catch (Exception $e) {}

$totalServices = countRows('caryard_services', 'org_id=?', [$orgId]);

$summary = [
    ['label' => 'Total Vehicles', 'value' => number_format($totalVehicles)],
    ['label' => 'Total Sales',    'value' => number_format($totalSales)],
    ['label' => 'Revenue',        'value' => formatCurrency($revenue)],
    ['label' => 'Service Records','value' => number_format($totalServices)],
];

// ── Sales ledger rows ─────────────────────────────────────────────
$rows = [];
try {
    $stmt = $pdo->prepare("
        SELECT s.sale_date, s.buyer_name, CONCAT(v.make,' ',v.model,' (',v.year,')') AS vehicle,
               v.stock_no, s.sale_price, v.purchase_price,
               (s.sale_price - v.purchase_price) AS profit, s.payment_method
        FROM caryard_sales s
        JOIN caryard_vehicles v ON s.vehicle_id = v.id
        WHERE s.org_id = ?
        ORDER BY s.sale_date DESC
        LIMIT 100
    ");
    $stmt->execute([$orgId]);
    foreach ($stmt->fetchAll() as $r) {
        $rows[] = [
            date('d/m/Y', strtotime($r['sale_date'])),
            $r['stock_no'],
            mb_strimwidth($r['vehicle'], 0, 28, '…'),
            mb_strimwidth($r['buyer_name'], 0, 20, '…'),
            formatCurrency((float)$r['sale_price']),
            formatCurrency((float)$r['profit']),
            ucfirst($r['payment_method'] ?? '—'),
        ];
    }
} catch (Exception $e) {}

$cols = [
    ['label' => 'Date',     'width' => 20, 'align' => 'L'],
    ['label' => 'Stock #',  'width' => 18, 'align' => 'L'],
    ['label' => 'Vehicle',  'width' => 52, 'align' => 'L'],
    ['label' => 'Buyer',    'width' => 38, 'align' => 'L'],
    ['label' => 'Sale Price','width'=> 26, 'align' => 'R'],
    ['label' => 'Profit',   'width' => 22, 'align' => 'R'],
    ['label' => 'Payment',  'width' => 10, 'align' => 'L'],
];

generateModuleReportPDF(
    'Car Yard — Sales Report',
    'As at ' . date('d M Y'),
    $summary,
    $cols,
    $rows,
    'CarYard-Report-' . date('Ymd') . '.pdf',
    [230, 126, 34]  // caryard orange
);
