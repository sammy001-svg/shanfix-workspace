<?php
/**
 * TEMPORARY DIAGNOSTIC — delete after debugging.
 * Access: https://orbitdesk.net/client/modules-diag.php
 */
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
requireClientAdmin();

$user  = currentUser();
$orgId = (int)$user['org_id'];

// ── If testing add_module, run it and show result ─────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['test'] ?? '') === 'add') {
    $slug = trim($_POST['slug'] ?? '');
    $sub  = null;
    try {
        $sub = $pdo->prepare("SELECT * FROM subscriptions WHERE org_id=? ORDER BY created_at DESC LIMIT 1");
        $sub->execute([$orgId]);
        $sub = $sub->fetch() ?: null;
    } catch (Exception $e) { echo "SUB ERROR: " . $e->getMessage(); exit; }

    if (!$sub) { echo "FAIL: No subscription found for org $orgId"; exit; }

    $mod = null;
    try {
        $s = $pdo->prepare("SELECT * FROM modules WHERE slug=? AND status='active'");
        $s->execute([$slug]);
        $mod = $s->fetch() ?: null;
    } catch (Exception $e) { echo "MOD ERROR: " . $e->getMessage(); exit; }

    if (!$mod) { echo "FAIL: Module '$slug' not found"; exit; }

    $price     = (float)$mod['monthly_price'];
    $tax       = round($price * 0.16, 2);
    $total     = $price + $tax;
    $invoiceNo = 'TEST-' . strtoupper(substr(md5(uniqid()), 0, 6));
    $dueDate   = date('Y-m-d', strtotime('+7 days'));

    try {
        $pdo->prepare("
            INSERT INTO invoices (org_id, subscription_id, module_id, invoice_number, amount, tax, total, status, due_date, notes)
            VALUES (?,?,?,?,?,?,?,'sent',?,?)
        ")->execute([$orgId, $sub['id'], $mod['id'], $invoiceNo, $price, $tax, $total, $dueDate, "Test: {$mod['name']}"]);
        $iid = (int)$pdo->lastInsertId();
        echo "SUCCESS: Invoice $invoiceNo created (ID=$iid). Module '{$mod['name']}' invoice ready.";
        // Clean up test invoice
        $pdo->prepare("DELETE FROM invoices WHERE id=?")->execute([$iid]);
        echo "\n(Test invoice deleted — this was just a test)";
    } catch (Exception $e) {
        echo "INSERT ERROR: " . $e->getMessage();
    }
    exit;
}

// ── If testing deactivate, run it and show result ─────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['test'] ?? '') === 'deactivate') {
    $slug = trim($_POST['slug'] ?? '');
    try {
        $stmt = $pdo->prepare("
            UPDATE subscription_modules sm
            INNER JOIN subscriptions s ON sm.subscription_id = s.id
            INNER JOIN modules m ON sm.module_id = m.id
            SET sm.status = 'inactive'
            WHERE s.org_id = ? AND m.slug = ?
        ");
        $stmt->execute([$orgId, $slug]);
        $rows = $stmt->rowCount();
        echo "DEACTIVATE: $rows row(s) updated for slug='$slug'";
        if ($rows > 0) {
            // Re-activate for testing purposes
            $stmt2 = $pdo->prepare("
                UPDATE subscription_modules sm
                INNER JOIN subscriptions s ON sm.subscription_id = s.id
                INNER JOIN modules m ON sm.module_id = m.id
                SET sm.status = 'active'
                WHERE s.org_id = ? AND m.slug = ?
            ");
            $stmt2->execute([$orgId, $slug]);
            echo "\n(Re-activated for testing purposes)";
        }
    } catch (Exception $e) {
        echo "DEACTIVATE ERROR: " . $e->getMessage();
    }
    exit;
}

ob_end_clean();
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Module Diagnostic</title>
<style>
body { font-family: monospace; padding: 20px; background: #f0f4f8; }
.box { background: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,.1); }
h2 { color: #0B2D4E; }
button { padding: 10px 20px; background: #0B2D4E; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; margin-right: 10px; }
button.green { background: #1A8A4E; }
button.red { background: #dc2626; }
input[type=text] { padding: 8px 12px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px; width: 200px; margin-right: 10px; }
pre { background: #f8fafc; border: 1px solid #e2e8f0; padding: 12px; border-radius: 6px; }
</style>
</head>
<body>

<div class="box">
<h2>OrbitDesk Module Diagnostic</h2>
<pre>
PHP version : <?= PHP_VERSION ?>

APP_URL     : <?= APP_URL ?>

HTTPS       : <?= ($_SERVER['HTTPS'] ?? 'not set') ?>

Org ID      : <?= $orgId ?>

User        : <?= htmlspecialchars($user['name'] ?? '?') ?>

CSRF set    : <?= empty($_SESSION['csrf_token']) ? 'NO' : 'YES' ?>

</pre>
</div>

<?php
// Show active/inactive modules
$actSlug = [];
try {
    $r = $pdo->prepare("SELECT m.slug FROM modules m JOIN subscription_modules sm ON m.id=sm.module_id JOIN subscriptions s ON sm.subscription_id=s.id WHERE s.org_id=? AND sm.status='active'");
    $r->execute([$orgId]);
    $actSlug = array_column($r->fetchAll(), 'slug');
} catch (Exception $e) {}

$allMods = [];
try { $allMods = $pdo->query("SELECT slug, name FROM modules WHERE status='active' ORDER BY name")->fetchAll(); } catch (Exception $e) {}
?>

<div class="box">
<h2>Test 1: Does Add &amp; Pay work?</h2>
<p>Select an <strong>inactive</strong> module and click Test. Should say <strong>SUCCESS</strong>.</p>
<form method="POST" id="testAddForm">
  <input type="hidden" name="test" value="add">
  <select name="slug" id="slugSelect" style="padding:8px;font-size:14px;margin-right:10px">
    <?php foreach ($allMods as $m): ?>
    <option value="<?= htmlspecialchars($m['slug']) ?>" <?= in_array($m['slug'], $actSlug) ? '' : 'selected' ?>>
      <?= htmlspecialchars($m['name']) ?> (<?= in_array($m['slug'], $actSlug) ? 'ACTIVE' : 'inactive' ?>)
    </option>
    <?php endforeach; ?>
  </select>
  <button type="submit" class="green">Test Add &amp; Pay</button>
</form>
<pre id="addResult" style="margin-top:12px;min-height:40px"></pre>
</div>

<div class="box">
<h2>Test 2: Does Deactivate work?</h2>
<p>Select an <strong>active</strong> module and click Test. Should say <strong>DEACTIVATE: 1 row(s) updated</strong>.</p>
<form method="POST" id="testDeactivateForm">
  <input type="hidden" name="test" value="deactivate">
  <select name="slug" id="deactSelect" style="padding:8px;font-size:14px;margin-right:10px">
    <?php foreach ($allMods as $m): ?>
    <option value="<?= htmlspecialchars($m['slug']) ?>" <?= in_array($m['slug'], $actSlug) ? 'selected' : '' ?>>
      <?= htmlspecialchars($m['name']) ?> (<?= in_array($m['slug'], $actSlug) ? 'ACTIVE' : 'inactive' ?>)
    </option>
    <?php endforeach; ?>
  </select>
  <button type="submit" class="red">Test Deactivate</button>
</form>
<pre id="deactResult" style="margin-top:12px;min-height:40px"></pre>
</div>

<div class="box">
<h2>Test 3: Does form POST reach PHP at all?</h2>
<p>Click this button. If you see POST RECEIVED below, forms work on your server.</p>
<form method="POST">
  <input type="hidden" name="test" value="ping">
  <button type="submit">Click to Test POST</button>
</form>
<?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['test'] ?? '') === 'ping'): ?>
<pre style="color:green;font-weight:bold">✓ POST RECEIVED — form submission works!</pre>
<?php else: ?>
<pre id="pingResult">Waiting for click…</pre>
<?php endif; ?>
</div>

<div class="box">
<h2>Test 4: CSRF token match</h2>
<?php
$token = $_SESSION['csrf_token'] ?? '';
?>
<p>The CSRF token in this session is: <code><?= substr($token, 0, 8) ?>...</code></p>
<form method="POST">
  <input type="hidden" name="test" value="csrf_test">
  <input type="hidden" name="_token" value="<?= htmlspecialchars($token) ?>">
  <button type="submit">Test CSRF Verification</button>
</form>
<?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['test'] ?? '') === 'csrf_test'): ?>
<?php
$posted  = $_POST['_token'] ?? '';
$session = $_SESSION['csrf_token'] ?? '';
$match   = hash_equals($session, $posted);
?>
<pre style="color:<?= $match ? 'green' : 'red' ?>;font-weight:bold">
<?= $match ? '✓ CSRF MATCH — token verification works!' : '✗ CSRF MISMATCH — this is why forms fail!' ?>
Posted : <?= substr($posted, 0, 16) ?>...
Session: <?= substr($session, 0, 16) ?>...
</pre>
<?php endif; ?>
</div>

<script>
// Make the add/deactivate tests use fetch so we see the result inline
document.getElementById('testAddForm').addEventListener('submit', function(e) {
  e.preventDefault();
  var fd = new FormData(this);
  document.getElementById('addResult').textContent = 'Testing...';
  fetch('', {method:'POST', body:fd})
    .then(r => r.text())
    .then(t => { document.getElementById('addResult').textContent = t; })
    .catch(err => { document.getElementById('addResult').textContent = 'FETCH ERROR: ' + err; });
});

document.getElementById('testDeactivateForm').addEventListener('submit', function(e) {
  e.preventDefault();
  var fd = new FormData(this);
  document.getElementById('deactResult').textContent = 'Testing...';
  fetch('', {method:'POST', body:fd})
    .then(r => r.text())
    .then(t => { document.getElementById('deactResult').textContent = t; })
    .catch(err => { document.getElementById('deactResult').textContent = 'FETCH ERROR: ' + err; });
});
</script>

</body>
</html>
