<?php
/**
 * Meetings — Report PDF
 */
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/pdf.php';

requireModuleAccess('meetings');
$user  = currentUser();
$orgId = (int)$user['org_id'];

// ── Summary ───────────────────────────────────────────────────────
$totalMeetings  = countRows('meetings',             'org_id=?', [$orgId]);
$totalMinutes   = countRows('meeting_minutes',      'org_id=?', [$orgId]);
$totalActions   = countRows('meeting_action_items', 'org_id=?', [$orgId]);
$pendingActions = countRows('meeting_action_items', "org_id=? AND status='pending'", [$orgId]);

$summary = [
    ['label' => 'Total Meetings',    'value' => number_format($totalMeetings)],
    ['label' => 'Meeting Minutes',   'value' => number_format($totalMinutes)],
    ['label' => 'Action Items',      'value' => number_format($totalActions)],
    ['label' => 'Pending Actions',   'value' => number_format($pendingActions)],
];

// ── Meetings list ─────────────────────────────────────────────────
$rows = [];
try {
    $stmt = $pdo->prepare("
        SELECT m.title, m.meeting_date, m.start_time, m.meeting_type, m.location, m.status,
               COUNT(DISTINCT a.id) AS action_count
        FROM meetings m
        LEFT JOIN meeting_action_items a ON a.meeting_id = m.id
        WHERE m.org_id = ?
        GROUP BY m.id
        ORDER BY m.meeting_date DESC
        LIMIT 100
    ");
    $stmt->execute([$orgId]);
    foreach ($stmt->fetchAll() as $r) {
        $rows[] = [
            mb_strimwidth($r['title'], 0, 42, '…'),
            date('d/m/Y', strtotime($r['meeting_date'])),
            $r['start_time'] ? substr($r['start_time'], 0, 5) : '—',
            ucfirst(str_replace('_', ' ', $r['meeting_type'] ?? '—')),
            mb_strimwidth($r['location'] ?? '—', 0, 22, '…'),
            number_format((int)$r['action_count']),
            ucfirst($r['status']),
        ];
    }
} catch (Exception $e) {}

$cols = [
    ['label' => 'Meeting Title', 'width' => 58, 'align' => 'L'],
    ['label' => 'Date',          'width' => 22, 'align' => 'L'],
    ['label' => 'Time',          'width' => 14, 'align' => 'L'],
    ['label' => 'Type',          'width' => 24, 'align' => 'L'],
    ['label' => 'Location',      'width' => 36, 'align' => 'L'],
    ['label' => 'Actions',       'width' => 14, 'align' => 'R'],
    ['label' => 'Status',        'width' => 18, 'align' => 'L'],
];

generateModuleReportPDF(
    'Meetings — Report',
    'As at ' . date('d M Y'),
    $summary,
    $cols,
    $rows,
    'Meetings-Report-' . date('Ymd') . '.pdf',
    [11, 45, 78]  // meetings navy
);
