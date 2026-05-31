<?php
/**
 * INVOICE DIAGNOSTIC — shows raw query results from multiple query styles.
 * Visit: https://orbitdesk.net/admin/diag-invoices.php
 * DELETE after use.
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
requireSuperAdmin();

header('Content-Type: text/plain; charset=utf-8');

echo "=== INVOICE DIAGNOSTIC ===\n\n";
echo "PHP version: " . PHP_VERSION . "\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n\n";

// ── 1. What does the invoices table actually look like? ──────────────────────
echo "--- DESCRIBE invoices ---\n";
try {
    foreach ($pdo->query("DESCRIBE invoices")->fetchAll() as $col) {
        echo "  {$col['Field']}  TYPE={$col['Type']}  NULL={$col['Null']}  DEFAULT=" . ($col['Default'] ?? 'NULL') . "\n";
    }
} catch (Throwable $e) { echo "ERROR: " . $e->getMessage() . "\n"; }

echo "\n--- DESCRIBE organizations ---\n";
try {
    foreach ($pdo->query("DESCRIBE organizations")->fetchAll() as $col) {
        echo "  {$col['Field']}  TYPE={$col['Type']}\n";
    }
} catch (Throwable $e) { echo "ERROR: " . $e->getMessage() . "\n"; }

// ── 2. Last 5 invoices — raw (no JOIN, no CAST) ─────────────────────────────
echo "\n--- LAST 5 INVOICES: SELECT * FROM invoices (no join, no cast) ---\n";
try {
    $rows = $pdo->query("SELECT * FROM invoices ORDER BY created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        echo "  #{$r['id']} {$r['invoice_number']}  amount={$r['amount']}  tax={$r['tax']}  total={$r['total']}  status={$r['status']}\n";
        echo "    (PHP types: amount=" . gettype($r['amount']) . " tax=" . gettype($r['tax']) . " total=" . gettype($r['total']) . ")\n";
    }
} catch (Throwable $e) { echo "ERROR: " . $e->getMessage() . "\n"; }

// ── 3. Last 5 invoices — explicit columns, no JOIN ──────────────────────────
echo "\n--- LAST 5 INVOICES: explicit i.amount (no join) ---\n";
try {
    $rows = $pdo->query("SELECT i.id, i.invoice_number, i.amount, i.tax, i.total, i.status FROM invoices i ORDER BY i.created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        echo "  #{$r['id']} {$r['invoice_number']}  amount={$r['amount']}  tax={$r['tax']}  total={$r['total']}\n";
    }
} catch (Throwable $e) { echo "ERROR: " . $e->getMessage() . "\n"; }

// ── 4. Last 5 invoices — SELECT i.*, JOIN organizations ─────────────────────
echo "\n--- LAST 5 INVOICES: SELECT i.* JOIN organizations ---\n";
try {
    $rows = $pdo->query("SELECT i.*, o.name AS org_name, o.email AS org_email FROM invoices i JOIN organizations o ON i.org_id = o.id ORDER BY i.created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        echo "  #{$r['id']} {$r['invoice_number']}  amount={$r['amount']}  tax={$r['tax']}  total={$r['total']}\n";
        echo "    ALL KEYS: " . implode(', ', array_keys($r)) . "\n";
    }
} catch (Throwable $e) { echo "ERROR: " . $e->getMessage() . "\n"; }

// ── 5. Last 5 invoices — CAST + explicit, JOIN organizations ────────────────
echo "\n--- LAST 5 INVOICES: CAST explicit JOIN organizations ---\n";
try {
    $rows = $pdo->query("
        SELECT i.id, i.invoice_number,
               CAST(i.amount AS DECIMAL(12,2)) AS amount,
               CAST(i.tax    AS DECIMAL(12,2)) AS tax,
               CAST(i.total  AS DECIMAL(12,2)) AS total,
               i.status, o.name AS org_name
        FROM invoices i
        JOIN organizations o ON i.org_id = o.id
        ORDER BY i.created_at DESC LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        echo "  #{$r['id']} {$r['invoice_number']}  amount={$r['amount']}  tax={$r['tax']}  total={$r['total']}\n";
    }
} catch (Throwable $e) { echo "ERROR: " . $e->getMessage() . "\n"; }

// ── 6. Last 5 invoices — explicit, JOIN modules ──────────────────────────────
echo "\n--- LAST 5 INVOICES: explicit JOIN modules (client billing style) ---\n";
try {
    $rows = $pdo->query("
        SELECT i.id, i.invoice_number,
               CAST(i.amount AS DECIMAL(12,2)) AS amount,
               CAST(i.tax    AS DECIMAL(12,2)) AS tax,
               CAST(i.total  AS DECIMAL(12,2)) AS total,
               i.status, m.name AS module_name
        FROM invoices i
        LEFT JOIN modules m ON i.module_id = m.id
        ORDER BY i.created_at DESC LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        echo "  #{$r['id']} {$r['invoice_number']}  amount={$r['amount']}  tax={$r['tax']}  total={$r['total']}\n";
    }
} catch (Throwable $e) { echo "ERROR: " . $e->getMessage() . "\n"; }

// ── 7. Check organizations.wallet_balance ────────────────────────────────────
echo "\n--- ORGANIZATIONS wallet_balance ---\n";
try {
    foreach ($pdo->query("SELECT id, name, wallet_balance FROM organizations ORDER BY id LIMIT 10")->fetchAll() as $o) {
        echo "  Org#{$o['id']} {$o['name']}  wallet_balance={$o['wallet_balance']}\n";
    }
} catch (Throwable $e) { echo "ERROR: " . $e->getMessage() . "\n"; }

// ── 8. Does SELECT i.* return DIFFERENT amounts than i.amount? ───────────────
echo "\n--- COLLISION TEST: does SELECT i.* match i.amount? ---\n";
try {
    $r_star = $pdo->query("SELECT i.*, o.name AS org_name FROM invoices i JOIN organizations o ON i.org_id = o.id ORDER BY i.created_at DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $r_expl = $pdo->query("SELECT i.id, i.amount FROM invoices i ORDER BY i.created_at DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($r_star && $r_expl) {
        $diff = abs((float)$r_star['amount'] - (float)$r_expl['amount']);
        echo "  i.* amount = {$r_star['amount']}\n";
        echo "  explicit amount = {$r_expl['amount']}\n";
        echo "  difference = $diff\n";
        echo "  COLLISION: " . ($diff > 1 ? "YES — THIS IS THE BUG" : "NO — values match") . "\n";
    }
} catch (Throwable $e) { echo "ERROR: " . $e->getMessage() . "\n"; }

echo "\n=== END DIAGNOSTIC ===\n";
