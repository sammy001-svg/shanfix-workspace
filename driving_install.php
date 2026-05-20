<?php
/**
 * Driving School Module Installer
 * Run once via browser: http://yoursite/driving_install.php
 * DELETE this file immediately after running.
 */
require_once __DIR__ . '/config/database.php';
$results = [];
$sql = file_get_contents(__DIR__ . '/database/driving_migration.sql');

// Split on semicolons and run each statement
$statements = array_filter(array_map('trim', explode(';', $sql)));
foreach ($statements as $stmt) {
    if (empty($stmt) || strpos($stmt, '--') === 0) continue;
    // Grab first keyword line for label
    preg_match('/(?:CREATE TABLE IF NOT EXISTS|INSERT IGNORE INTO modules)[^\w]*(\w+)/i', $stmt, $m);
    $label = $m[1] ?? substr($stmt, 0, 40);
    try {
        $pdo->exec($stmt);
        $results[] = ['ok' => true, 'msg' => "OK: $label"];
    } catch (Exception $e) {
        $results[] = ['ok' => false, 'msg' => "FAIL $label: " . $e->getMessage()];
    }
}

// Verify
try {
    $row = $pdo->query("SELECT id,slug,name,status FROM modules WHERE slug='driving'")->fetch();
    $results[] = $row
        ? ['ok' => true,  'msg' => "✅ Module verified: id={$row['id']}, status={$row['status']}"]
        : ['ok' => false, 'msg' => '❌ Module NOT found — check DB'];
} catch (Exception $e) {
    $results[] = ['ok' => false, 'msg' => 'Verification error: ' . $e->getMessage()];
}

$allOk = !in_array(false, array_column($results, 'ok'));
?>
<!DOCTYPE html><html><head><title>Driving School Installer</title>
<style>body{font-family:sans-serif;max-width:700px;margin:40px auto;padding:0 20px;background:#f5f7fa}
h2{color:#1a237e}.result{padding:8px 14px;margin:5px 0;border-radius:5px;font-size:13px}
.ok{background:#d4edda;color:#155724;border-left:4px solid #28a745}
.err{background:#f8d7da;color:#721c24;border-left:4px solid #dc3545}
.banner{padding:15px;border-radius:8px;margin-bottom:18px;font-weight:bold;font-size:16px}
.banner.ok{background:#d4edda;color:#155724}.banner.err{background:#f8d7da;color:#721c24}
.warn{background:#fff3cd;color:#856404;padding:12px;border-radius:6px;margin-top:18px;font-size:13px}
</style></head><body>
<h2>🚗 Driving School Module Installer</h2>
<div class="banner <?= $allOk ? 'ok' : 'err' ?>">
  <?= $allOk ? '✅ Installation complete! Driving School module is now live.' : '⚠️ Some steps had errors — check below.' ?>
</div>
<?php foreach ($results as $r): ?>
<div class="result <?= $r['ok'] ? 'ok' : 'err' ?>"><?= htmlspecialchars($r['msg']) ?></div>
<?php endforeach; ?>
<div class="warn">⚠️ <strong>Security:</strong> Delete this file immediately after installation.<br>Path: <code><?= __FILE__ ?></code></div>
</body></html>
