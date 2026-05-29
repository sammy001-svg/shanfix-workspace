<?php
/**
 * OrbitDesk API — Reports / Analytics Endpoint
 *
 * GET /api/v1/reports.php?type=summary
 *   Returns org KPI summary: total users, active modules, monthly revenue, paid invoices
 *
 * GET /api/v1/reports.php?type=activity&days=30
 *   Returns last N days of activity_log entries
 *
 * GET /api/v1/reports.php?type=modules
 *   Lists active modules for the org
 *
 * All data is scoped to org_id from Bearer token.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/_auth_helper.php';

apiSetHeaders();

$auth  = apiRequireAuth();
$orgId = $auth['org_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    apiError('Method not allowed. Use GET.', 405);
}

$type = strtolower(trim($_GET['type'] ?? 'summary'));

// ═══════════════════════════════════════════════════════════════════════════════
// type=summary — KPI overview
// ═══════════════════════════════════════════════════════════════════════════════
if ($type === 'summary') {
    try {
        // Total users in this org
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE org_id = ? AND status = 'active'");
        $stmt->execute([$orgId]);
        $totalUsers = (int)$stmt->fetchColumn();

        // Active modules (via subscription_modules → subscriptions)
        $stmt = $pdo->prepare(
            "SELECT COUNT(DISTINCT sm.module_id)
             FROM subscription_modules sm
             JOIN subscriptions s ON s.id = sm.subscription_id
             WHERE s.org_id = ? AND sm.status = 'active' AND s.status IN ('active','trial')"
        );
        $stmt->execute([$orgId]);
        $activeModules = (int)$stmt->fetchColumn();

        // Monthly revenue: paid invoices in current calendar month
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(total), 0)
             FROM invoices
             WHERE org_id = ? AND status = 'paid'
               AND YEAR(paid_at) = YEAR(CURDATE())
               AND MONTH(paid_at) = MONTH(CURDATE())"
        );
        $stmt->execute([$orgId]);
        $monthlyRevenue = (float)$stmt->fetchColumn();

        // Total paid invoices (all time)
        $stmt = $pdo->prepare(
            "SELECT COUNT(*), COALESCE(SUM(total), 0)
             FROM invoices
             WHERE org_id = ? AND status = 'paid'"
        );
        $stmt->execute([$orgId]);
        $invRow         = $stmt->fetch(PDO::FETCH_NUM);
        $totalPaidInvs  = (int)$invRow[0];
        $totalPaidAmt   = (float)$invRow[1];

        // Total invoices (all statuses)
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE org_id = ?");
        $stmt->execute([$orgId]);
        $totalInvoices = (int)$stmt->fetchColumn();

        // CRM contacts count
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM crm_contacts WHERE org_id = ? AND status = 'active'");
        $stmt->execute([$orgId]);
        $totalContacts = (int)$stmt->fetchColumn();

        // Org subscription status
        $stmt = $pdo->prepare(
            "SELECT s.status, s.ends_at, s.trial_ends_at, p.name AS plan_name
             FROM subscriptions s
             LEFT JOIN subscription_plans p ON p.id = s.plan_id
             WHERE s.org_id = ?
             ORDER BY s.id DESC
             LIMIT 1"
        );
        $stmt->execute([$orgId]);
        $subscription = $stmt->fetch() ?: null;

    } catch (Throwable $e) {
        apiError('Failed to generate summary. ' . $e->getMessage(), 500);
    }

    apiJson([
        'success' => true,
        'type'    => 'summary',
        'data'    => [
            'total_users'            => $totalUsers,
            'active_modules'         => $activeModules,
            'total_contacts'         => $totalContacts,
            'monthly_revenue'        => round($monthlyRevenue, 2),
            'total_paid_invoices'    => $totalPaidInvs,
            'total_paid_amount'      => round($totalPaidAmt, 2),
            'total_invoices'         => $totalInvoices,
            'subscription'           => $subscription,
            'generated_at'           => date('Y-m-d H:i:s'),
        ],
    ]);
}

// ═══════════════════════════════════════════════════════════════════════════════
// type=activity — Recent activity log
// ═══════════════════════════════════════════════════════════════════════════════
if ($type === 'activity') {
    $days    = max(1, min(365, (int)($_GET['days'] ?? 30)));
    $page    = max(1, (int)($_GET['page']     ?? 1));
    $perPage = max(1, min(200, (int)($_GET['per_page'] ?? 50)));
    $offset  = ($page - 1) * $perPage;

    try {
        $cStmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM activity_log
             WHERE org_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)"
        );
        $cStmt->execute([$orgId, $days]);
        $total = (int)$cStmt->fetchColumn();

        $dStmt = $pdo->prepare(
            "SELECT a.id, a.user_id, u.name AS user_name, a.action, a.module,
                    a.description, a.ip, a.created_at
             FROM activity_log a
             LEFT JOIN users u ON u.id = a.user_id
             WHERE a.org_id = ? AND a.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             ORDER BY a.created_at DESC
             LIMIT :limit OFFSET :offset"
        );
        $dStmt->bindValue(1, $orgId, PDO::PARAM_INT);
        $dStmt->bindValue(2, $days,  PDO::PARAM_INT);
        $dStmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
        $dStmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
        $dStmt->execute();
        $entries = $dStmt->fetchAll();
    } catch (Throwable $e) {
        apiError('Failed to retrieve activity log.', 500);
    }

    apiJson([
        'success'  => true,
        'type'     => 'activity',
        'days'     => $days,
        'data'     => $entries,
        'total'    => $total,
        'page'     => $page,
        'per_page' => $perPage,
        'pages'    => (int)ceil($total / $perPage),
    ]);
}

// ═══════════════════════════════════════════════════════════════════════════════
// type=modules — Active modules for org
// ═══════════════════════════════════════════════════════════════════════════════
if ($type === 'modules') {
    try {
        $stmt = $pdo->prepare(
            "SELECT m.id, m.slug, m.name, m.description, m.icon, m.color,
                    m.category, m.monthly_price, sm.status AS module_status,
                    s.status AS subscription_status
             FROM subscription_modules sm
             JOIN subscriptions s ON s.id = sm.subscription_id
             JOIN modules m       ON m.id = sm.module_id
             WHERE s.org_id = ? AND sm.status = 'active'
               AND s.status IN ('active','trial')
             ORDER BY m.sort_order, m.name"
        );
        $stmt->execute([$orgId]);
        $modules = $stmt->fetchAll();
    } catch (Throwable $e) {
        apiError('Failed to retrieve modules.', 500);
    }

    apiJson([
        'success' => true,
        'type'    => 'modules',
        'data'    => $modules,
        'total'   => count($modules),
    ]);
}

// Unknown type
apiError("Unknown report type '$type'. Use: summary, activity, modules.", 400);
