<?php
/**
 * api/dashboard-kpis.php
 * Lightweight JSON endpoint for live dashboard refresh.
 * Returns current KPI values + unread notification count for the logged-in org.
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false]);
    exit;
}

$user  = currentUser();
$orgId = (int)$user['org_id'];
$uid   = (int)$user['id'];

try {
    $activeModules = getOrgModules($orgId);
    $activeSlugs   = array_column($activeModules, 'slug');

    $kpis = [];

    // Core KPIs
    $s = $pdo->prepare("SELECT COUNT(*) FROM users WHERE org_id=? AND role != 'super_admin'");
    $s->execute([$orgId]);
    $kpis['users'] = (int)$s->fetchColumn();

    try {
        $s = $pdo->prepare("SELECT COUNT(*) FROM support_tickets WHERE org_id=? AND status IN ('open','in_progress')");
        $s->execute([$orgId]);
        $kpis['tickets'] = (int)$s->fetchColumn();
    } catch(Exception $e) { $kpis['tickets'] = 0; }

    // Module-specific KPIs
    if (in_array('pos', $activeSlugs)) {
        try {
            $s = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM pos_sales WHERE org_id=? AND DATE(created_at)=CURDATE() AND status='completed'");
            $s->execute([$orgId]);
            $kpis['pos_sales'] = 'KES ' . number_format((float)$s->fetchColumn());
        } catch(Exception $e) {}
    }
    if (in_array('hrm', $activeSlugs)) {
        try {
            $s = $pdo->prepare("SELECT COUNT(*) FROM hrm_leave_requests WHERE org_id=? AND status='pending'");
            $s->execute([$orgId]);
            $kpis['hrm_leave'] = (int)$s->fetchColumn();
        } catch(Exception $e) {}
    }
    if (in_array('hotel', $activeSlugs)) {
        try {
            $s = $pdo->prepare("SELECT COUNT(*) FROM hotel_bookings WHERE org_id=? AND status='checked_in'");
            $s->execute([$orgId]);
            $kpis['hotel_guests'] = (int)$s->fetchColumn();
        } catch(Exception $e) {}
    }
    if (in_array('retail', $activeSlugs)) {
        try {
            $s = $pdo->prepare("SELECT COUNT(*) FROM retail_products WHERE org_id=? AND quantity <= reorder_level AND quantity >= 0");
            $s->execute([$orgId]);
            $kpis['retail_lowstock'] = (int)$s->fetchColumn();
        } catch(Exception $e) {}
    }

    // Unread notifications
    $unread = 0;
    try {
        $s = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE org_id=? AND is_read=0");
        $s->execute([$orgId]);
        $unread = (int)$s->fetchColumn();
    } catch(Exception $e) {}

    echo json_encode([
        'success'      => true,
        'kpis'         => $kpis,
        'unread_notifs'=> $unread,
        'ts'           => time(),
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false]);
}
