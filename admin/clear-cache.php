<?php
/**
 * OPCache Reset — clears the PHP bytecode cache so updated files take effect.
 * Visit: https://orbitdesk.net/admin/clear-cache.php
 * DELETE this file immediately after running.
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
requireSuperAdmin();

$cleared  = false;
$message  = '';
$fileInfo = [];

if (function_exists('opcache_reset')) {
    $cleared = opcache_reset();
    $message = $cleared
        ? '✅  opcache_reset() succeeded — all cached PHP bytecode cleared.'
        : '⚠️  opcache_reset() returned false — cache may not have cleared. Try restarting PHP-FPM from cPanel.';
} else {
    $message = '⚠️  OPCache extension not loaded on this server. No cache to clear — your files should be executing as-is.';
}

// Also check the current live invoice query (prove which code is running)
$queryCheck = '(not run)';
try {
    // Look for the explicit CAST pattern — only present in the FIXED version
    $code = file_get_contents(__DIR__ . '/invoices.php');
    if (strpos($code, 'CAST(i.amount AS DECIMAL') !== false) {
        $queryCheck = '✅  admin/invoices.php contains explicit CAST — FIXED version is on disk.';
    } else {
        $queryCheck = '❌  admin/invoices.php does NOT contain explicit CAST — OLD version is on disk. Re-upload the file.';
    }
} catch (Throwable $e) {
    $queryCheck = 'Could not read invoices.php: ' . $e->getMessage();
}

$billingCheck = '(not run)';
try {
    $code = file_get_contents(__DIR__ . '/../client/billing.php');
    if (strpos($code, 'CAST(i.amount AS DECIMAL') !== false) {
        $billingCheck = '✅  client/billing.php contains explicit CAST — correct version on disk.';
    } else {
        $billingCheck = '❌  client/billing.php does NOT contain explicit CAST — wrong version on disk.';
    }
} catch (Throwable $e) {
    $billingCheck = 'Could not read billing.php: ' . $e->getMessage();
}

// Live query test
$liveAmount = null;
try {
    $r = $pdo->query("SELECT CAST(amount AS DECIMAL(12,2)) AS amount FROM invoices ORDER BY created_at DESC LIMIT 1")->fetch();
    $liveAmount = $r ? $r['amount'] : null;
} catch (Throwable $e) {}

header('Content-Type: text/plain; charset=utf-8');
echo "=== CACHE RESET & VERSION CHECK ===\n\n";
echo "OPCache: $message\n\n";
echo "File checks:\n";
echo "  $queryCheck\n";
echo "  $billingCheck\n\n";
echo "Live DB check (most recent invoice amount): " . ($liveAmount ?? 'none') . "\n\n";
echo "Next steps:\n";
echo "  1. If OPCache cleared successfully, hard-refresh the admin invoices page.\n";
echo "  2. If either file check shows ❌, re-upload that file from your local git repo.\n";
echo "  3. If DB amount looks wrong, run diag-invoices.php again.\n";
echo "  4. DELETE this file: admin/clear-cache.php\n";
echo "\n=== DONE ===\n";
