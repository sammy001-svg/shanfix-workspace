<?php
/**
 * Events — Report PDF
 */
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/pdf.php';

requireModuleAccess('events');
$user  = currentUser();
$orgId = (int)$user['org_id'];

// ── Summary ───────────────────────────────────────────────────────
$totalEvents    = countRows('events',          'org_id=?', [$orgId]);
$totalAttendees = countRows('event_attendees', 'org_id=?', [$orgId]);
$totalTickets   = countRows('event_tickets',   'org_id=?', [$orgId]);

$ticketRevenue = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(price),0) FROM event_tickets WHERE org_id=?");
    $stmt->execute([$orgId]);
    $ticketRevenue = (float)$stmt->fetchColumn();
} catch (Exception $e) {}

$summary = [
    ['label' => 'Total Events',    'value' => number_format($totalEvents)],
    ['label' => 'Total Attendees', 'value' => number_format($totalAttendees)],
    ['label' => 'Ticket Types',    'value' => number_format($totalTickets)],
    ['label' => 'Ticket Revenue',  'value' => formatCurrency($ticketRevenue)],
];

// ── Events list ───────────────────────────────────────────────────
$rows = [];
try {
    $stmt = $pdo->prepare("
        SELECT e.title, e.start_date, e.end_date, e.venue, e.status,
               COUNT(DISTINCT ea.id) AS attendee_count
        FROM events e
        LEFT JOIN event_attendees ea ON ea.event_id = e.id
        WHERE e.org_id = ?
        GROUP BY e.id
        ORDER BY e.start_date DESC
        LIMIT 100
    ");
    $stmt->execute([$orgId]);
    foreach ($stmt->fetchAll() as $r) {
        $rows[] = [
            mb_strimwidth($r['title'], 0, 38, '…'),
            date('d/m/Y', strtotime($r['start_date'])),
            $r['end_date'] ? date('d/m/Y', strtotime($r['end_date'])) : '—',
            mb_strimwidth($r['venue'] ?? '—', 0, 28, '…'),
            number_format((int)$r['attendee_count']),
            ucfirst($r['status']),
        ];
    }
} catch (Exception $e) {}

$cols = [
    ['label' => 'Event Title', 'width' => 62, 'align' => 'L'],
    ['label' => 'Start Date',  'width' => 24, 'align' => 'L'],
    ['label' => 'End Date',    'width' => 24, 'align' => 'L'],
    ['label' => 'Venue',       'width' => 46, 'align' => 'L'],
    ['label' => 'Attendees',   'width' => 18, 'align' => 'R'],
    ['label' => 'Status',      'width' => 12, 'align' => 'L'],
];

generateModuleReportPDF(
    'Events — Report',
    'As at ' . date('d M Y'),
    $summary,
    $cols,
    $rows,
    'Events-Report-' . date('Ymd') . '.pdf',
    [142, 68, 173]  // events purple
);
