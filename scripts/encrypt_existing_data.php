<?php
/**
 * scripts/encrypt_existing_data.php
 * ──────────────────────────────────────────────────────────────────
 * One-time migration to encrypt plaintext PII already in the database.
 * Run ONCE from the command line or browser, then DELETE this file.
 *
 * Usage (CLI):
 *   php scripts/encrypt_existing_data.php
 *
 * Usage (browser, only accessible locally):
 *   http://localhost/shanfix-workspace/scripts/encrypt_existing_data.php
 *
 * IMPORTANT:
 *   1. Set ENCRYPTION_KEY / APP_ENCRYPT_KEY before running.
 *   2. Back up the database BEFORE running.
 *   3. DELETE this file after successful migration.
 * ──────────────────────────────────────────────────────────────────
 */

// Safety: only allow CLI or localhost
if (PHP_SAPI !== 'cli') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($ip, ['127.0.0.1', '::1', 'localhost'])) {
        http_response_code(403);
        die('<h1>403 Forbidden</h1><p>This script can only be run from localhost.</p>');
    }
}

define('SHANFIX_ENCRYPT_MIGRATION', true);
require_once __DIR__ . '/../config/database.php';

// Verify key is set
if (ENCRYPTION_KEY === 'CHANGE_ME_BEFORE_GO_LIVE_USE_64CHAR_HEX') {
    die("❌ ERROR: ENCRYPTION_KEY is not set. Set APP_ENCRYPT_KEY env variable first.\n");
}

$isCli = PHP_SAPI === 'cli';
$nl    = $isCli ? "\n" : "<br>\n";
$bold  = $isCli ? '' : '<b>';
$ebold = $isCli ? '' : '</b>';

function log_msg(string $msg, string $level = 'info'): void {
    global $isCli, $nl, $bold, $ebold;
    $prefix = ['info' => '  ✓', 'warn' => '  ⚠', 'error' => '  ✗', 'head' => '▶'][$level] ?? '  ·';
    echo $prefix . ' ' . $msg . $nl;
}

function encrypt_column(PDO $pdo, string $table, string $column, string $idCol = 'id'): array {
    $stats = ['total' => 0, 'encrypted' => 0, 'skipped' => 0, 'errors' => 0];
    try {
        $rows = $pdo->query("SELECT {$idCol}, `{$column}` FROM `{$table}` WHERE `{$column}` IS NOT NULL AND `{$column}` != ''")->fetchAll();
    } catch (PDOException $e) {
        log_msg("Table/column not found: {$table}.{$column} — " . $e->getMessage(), 'warn');
        return $stats;
    }

    $stats['total'] = count($rows);
    $update = $pdo->prepare("UPDATE `{$table}` SET `{$column}`=? WHERE `{$idCol}`=?");

    foreach ($rows as $row) {
        $val = $row[$column];
        if (isEncrypted($val)) {
            $stats['skipped']++;
            continue;
        }
        try {
            $encrypted = encrypt($val);
            $update->execute([$encrypted, $row[$idCol]]);
            $stats['encrypted']++;
        } catch (Exception $e) {
            $stats['errors']++;
            log_msg("Error encrypting {$table}#{$row[$idCol]}.{$column}: " . $e->getMessage(), 'error');
        }
    }
    return $stats;
}

// ── Tables & columns to encrypt ───────────────────────────────
// Format: [table, [columns...], id_column]
$targets = [
    // HRM
    ['hrm_employees',     ['phone', 'email', 'national_id'],          'id'],
    ['hrm_employees',     ['emergency_contact_phone'],                 'id'],

    // Health / Clinic
    ['health_patients',   ['phone', 'email', 'id_number'],            'id'],
    ['health_patients',   ['next_of_kin_phone'],                       'id'],

    // SACCO / Microfinance
    ['sacco_members',     ['phone', 'email', 'id_number'],            'id'],
    ['sacco_members',     ['next_of_kin_phone'],                       'id'],
    ['fin_members',       ['phone', 'email', 'id_number'],            'id'],

    // CRM
    ['crm_contacts',      ['phone', 'email'],                         'id'],
    ['crm_customers',     ['phone', 'email'],                         'id'],

    // Hotel
    ['hotel_guests',      ['phone', 'email', 'id_number'],            'id'],
    ['hotel_guests',      ['passport_number'],                         'id'],

    // Rental
    ['rental_tenants',    ['phone', 'email', 'id_number'],            'id'],

    // Church
    ['church_members',    ['phone', 'email', 'id_number'],            'id'],

    // Salon
    ['salon_clients',     ['phone', 'email'],                         'id'],

    // Car Yard
    ['caryard_customers', ['phone', 'email', 'id_number'],            'id'],

    // Sales / Retail
    ['sales_customers',   ['phone', 'email'],                         'id'],
    ['retail_customers',  ['phone', 'email'],                         'id'],

    // Tour
    ['tour_customers',    ['phone', 'email', 'passport_no'],          'id'],
];

if (!$isCli) {
    echo '<html><head><title>PII Encryption Migration</title></head><body>';
    echo '<h2>🔐 Shanfix PII Encryption Migration</h2>';
    echo '<pre style="font-family:monospace;background:#1a1a2e;color:#00ff88;padding:20px;border-radius:8px">';
}

log_msg('Starting PII encryption migration...', 'head');
log_msg('Encryption key fingerprint: ' . substr(md5(ENCRYPTION_KEY), 0, 8) . '...', 'info');
log_msg('', 'info');

$totalAll = $encAll = $skipAll = $errAll = 0;

foreach ($targets as [$table, $columns, $idCol]) {
    log_msg("Processing: {$table}", 'head');
    foreach ($columns as $col) {
        $stats = encrypt_column($pdo, $table, $col, $idCol);
        $totalAll += $stats['total'];
        $encAll   += $stats['encrypted'];
        $skipAll  += $stats['skipped'];
        $errAll   += $stats['errors'];
        log_msg(
            "{$col}: {$stats['encrypted']} encrypted, {$stats['skipped']} already encrypted, {$stats['errors']} errors (of {$stats['total']} rows)",
            $stats['errors'] > 0 ? 'error' : 'info'
        );
    }
}

log_msg('', 'info');
log_msg('══════════════════════════════════════════════', 'head');
log_msg("TOTAL rows processed : {$totalAll}", 'head');
log_msg("Encrypted            : {$encAll}", 'head');
log_msg("Already encrypted    : {$skipAll}", 'head');
log_msg("Errors               : {$errAll}", $errAll > 0 ? 'error' : 'head');
log_msg('', 'info');

if ($errAll === 0) {
    log_msg('✅ Migration complete. DELETE this file now!', 'head');
} else {
    log_msg("⚠️  Migration completed with {$errAll} errors. Review logs above.", 'warn');
}

if (!$isCli) {
    echo '</pre>';
    echo '<p style="color:red;font-weight:bold">⚠️ DELETE this file immediately after migration!</p>';
    echo '</body></html>';
}
