<?php
/**
 * TEMPORARY DIAGNOSTIC — delete this file after debugging.
 * Access: https://orbitdesk.net/client/modules-diag.php
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
requireClientAdmin();

$user  = currentUser();
$orgId = (int)$user['org_id'];

header('Content-Type: text/plain; charset=utf-8');

echo "=== OrbitDesk Module Diagnostic ===\n\n";
echo "PHP version   : " . PHP_VERSION . "\n";
echo "Org ID        : {$orgId}\n";
echo "User          : " . ($user['name'] ?? '?') . "\n";
echo "APP_URL       : " . APP_URL . "\n";
echo "HTTPS detect  : " . ($_SERVER['HTTPS'] ?? 'not set') . "\n";
echo "Request URI   : " . ($_SERVER['REQUEST_URI'] ?? '?') . "\n";
echo "Request method: " . $_SERVER['REQUEST_METHOD'] . "\n\n";

// ── 1. Test POST receipt ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    echo "=== POST DATA RECEIVED ===\n";
    foreach ($_POST as $k => $v) {
        echo "  {$k} = " . ($k === '_token' ? '(csrf)' : $v) . "\n";
    }
    echo "\n";
}

// ── 2. Check tables ───────────────────────────────────────────────
echo "=== TABLE CHECKS ===\n";
$tables = ['modules','invoices','subscriptions','subscription_modules','subscription_plans'];
foreach ($tables as $t) {
    try {
        $r = $pdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
        echo "  {$t}: EXISTS ({$r} rows)\n";
    } catch (Exception $e) {
        echo "  {$t}: MISSING or ERROR — " . $e->getMessage() . "\n";
    }
}

// ── 3. Check invoices.module_id column ────────────────────────────
echo "\n=== INVOICES COLUMN CHECK ===\n";
try {
    $cols = $pdo->query("SHOW COLUMNS FROM invoices")->fetchAll(PDO::FETCH_COLUMN);
    echo "  Columns: " . implode(', ', $cols) . "\n";
    echo "  module_id present: " . (in_array('module_id', $cols) ? "YES" : "NO — this is the bug") . "\n";
} catch (Exception $e) {
    echo "  ERROR: " . $e->getMessage() . "\n";
}

// ── 4. Check subscription ─────────────────────────────────────────
echo "\n=== SUBSCRIPTION ===\n";
try {
    $sub = $pdo->prepare("SELECT id, status, billing_cycle, trial_ends_at, ends_at FROM subscriptions WHERE org_id=? ORDER BY created_at DESC LIMIT 1");
    $sub->execute([$orgId]);
    $s = $sub->fetch();
    if ($s) {
        echo "  ID: {$s['id']}, Status: {$s['status']}, Cycle: {$s['billing_cycle']}\n";
        echo "  Trial ends: " . ($s['trial_ends_at'] ?? 'none') . "\n";
        echo "  Sub ends  : " . ($s['ends_at'] ?? 'none') . "\n";
    } else {
        echo "  NO SUBSCRIPTION FOUND — this is why Add & Pay fails\n";
    }
} catch (Exception $e) {
    echo "  ERROR: " . $e->getMessage() . "\n";
}

// ── 5. Check active modules ───────────────────────────────────────
echo "\n=== ACTIVE MODULES ===\n";
try {
    $am = $pdo->prepare("
        SELECT m.slug, m.name FROM modules m
        INNER JOIN subscription_modules sm ON m.id = sm.module_id
        INNER JOIN subscriptions s ON sm.subscription_id = s.id
        WHERE s.org_id = ? AND s.status IN ('active','trial') AND sm.status='active'
    ");
    $am->execute([$orgId]);
    $rows = $am->fetchAll();
    echo "  Count: " . count($rows) . "\n";
    foreach ($rows as $r) echo "  - {$r['slug']} ({$r['name']})\n";
} catch (Exception $e) {
    echo "  ERROR: " . $e->getMessage() . "\n";
}

// ── 6. List available modules ─────────────────────────────────────
echo "\n=== AVAILABLE MODULES ===\n";
try {
    $ms = $pdo->query("SELECT slug, name, monthly_price FROM modules WHERE status='active' ORDER BY sort_order, name")->fetchAll();
    foreach ($ms as $m) echo "  - {$m['slug']}: {$m['name']} (KES {$m['monthly_price']})\n";
    if (!$ms) echo "  NO MODULES IN DATABASE\n";
} catch (Exception $e) {
    echo "  ERROR: " . $e->getMessage() . "\n";
}

// ── 7. CSRF token state ───────────────────────────────────────────
echo "\n=== SESSION ===\n";
echo "  csrf_token set: " . (empty($_SESSION['csrf_token']) ? "NO" : "YES") . "\n";

// ── 8. Test form ──────────────────────────────────────────────────
echo "\n\n";
?>
<!DOCTYPE html><html><body style="font-family:monospace;padding:20px">
<h3>Test Form Submission</h3>
<p>Click the button — if the page reloads with POST DATA RECEIVED above, forms are working.</p>
<form method="POST">
    <input type="hidden" name="test_action" value="ping">
    <button type="submit" style="padding:10px 20px;background:#0B2D4E;color:white;border:none;cursor:pointer;font-size:16px">
        Click to Test Form POST
    </button>
</form>

<hr>
<h3>Test Module Slug</h3>
<p>Paste a module slug below and submit to see what happens:</p>
<form method="POST">
    <input type="hidden" name="test_action" value="test_slug">
    <input type="text" name="slug" placeholder="e.g. crm" style="padding:8px;font-size:14px">
    <button type="submit" style="padding:8px 16px;background:#1A8A4E;color:white;border:none;cursor:pointer">Test Slug Lookup</button>
</form>
</body></html>
