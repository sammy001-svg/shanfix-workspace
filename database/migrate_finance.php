<?php
// Migration: Finance module schema additions
// Run once after deployment to update existing databases

require_once __DIR__ . '/../config/database.php';

$steps = [];

// Add to_account_id to fin_transactions if not present
try {
    $pdo->query("SELECT to_account_id FROM fin_transactions LIMIT 1");
    $steps[] = 'to_account_id column already exists — skipped.';
} catch (PDOException $e) {
    $pdo->exec("ALTER TABLE fin_transactions ADD COLUMN to_account_id INT DEFAULT NULL AFTER account_id");
    $steps[] = 'Added to_account_id column to fin_transactions.';
}

// Ensure fin_accounts table has all required columns
try {
    $pdo->query("SELECT currency FROM fin_accounts LIMIT 1");
    $steps[] = 'fin_accounts.currency already exists — skipped.';
} catch (PDOException $e) {
    $pdo->exec("ALTER TABLE fin_accounts ADD COLUMN currency VARCHAR(10) DEFAULT 'KES' AFTER account_no");
    $steps[] = 'Added currency column to fin_accounts.';
}

// Ensure fin_categories table exists
try {
    $pdo->query("SELECT id FROM fin_categories LIMIT 1");
    $steps[] = 'fin_categories already exists — skipped.';
} catch (PDOException $e) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS fin_categories (
        id     INT AUTO_INCREMENT PRIMARY KEY,
        org_id INT NOT NULL,
        name   VARCHAR(100),
        type   ENUM('income','expense') DEFAULT 'expense',
        color  VARCHAR(20)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $steps[] = 'Created fin_categories table.';
}

// Ensure fin_budgets table exists
try {
    $pdo->query("SELECT id FROM fin_budgets LIMIT 1");
    $steps[] = 'fin_budgets already exists — skipped.';
} catch (PDOException $e) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS fin_budgets (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        org_id      INT NOT NULL,
        category_id INT,
        period      VARCHAR(20),
        amount      DECIMAL(15,2),
        spent       DECIMAL(15,2) DEFAULT 0.00,
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $steps[] = 'Created fin_budgets table.';
}

// Ensure mfg_bom has org_id
try {
    $pdo->query("SELECT org_id FROM mfg_bom LIMIT 1");
    $steps[] = 'mfg_bom.org_id already exists — skipped.';
} catch (PDOException $e) {
    $pdo->exec("ALTER TABLE mfg_bom ADD COLUMN org_id INT NOT NULL DEFAULT 0 AFTER id");
    $steps[] = 'Added org_id to mfg_bom.';
}

echo '<pre>';
echo "Finance & Manufacturing Migration Results\n";
echo str_repeat('=', 45) . "\n";
foreach ($steps as $i => $s) {
    echo ($i + 1) . '. ' . $s . "\n";
}
echo "\nAll done.\n";
echo '</pre>';
?>
