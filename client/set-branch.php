<?php
/**
 * Branch context switcher — called by the header branch selector form.
 * Sets $_SESSION['active_branch_id'] then redirects back to the referring page.
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
requireClientAdmin();

$user  = currentUser();
$orgId = (int)$user['org_id'];
$bid   = (int)($_POST['branch_id'] ?? $_GET['branch_id'] ?? 0);

// Admins may switch to any branch or "all" (0)
// Staff locked to their own branch — ignore request
if ($user['role'] === 'client_admin') {
    if ($bid > 0) {
        // Verify branch belongs to this org
        try {
            $s = $pdo->prepare("SELECT id FROM org_branches WHERE id=? AND org_id=? AND status='active' LIMIT 1");
            $s->execute([$bid, $orgId]);
            if ($s->fetch()) {
                $_SESSION['active_branch_id'] = $bid;
            }
        } catch (Throwable $e) {}
    } else {
        $_SESSION['active_branch_id'] = 0; // all branches
    }
}

$ref = $_SERVER['HTTP_REFERER'] ?? (APP_URL . '/client/index.php');
// Sanitise ref — only allow same-origin
if (!str_starts_with($ref, APP_URL)) {
    $ref = APP_URL . '/client/index.php';
}
redirect($ref);
