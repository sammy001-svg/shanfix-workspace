<?php
/**
 * Cron: School Automation Rules Runner
 * Runs all enabled school automation rules for all active organizations.
 *
 * Recommended schedule (cPanel): 0 7 * * *  (daily at 07:00)
 *
 * Usage:  php /path/to/public_html/cron/school-automation.php
 */
define('CRON_MODE', true);
chdir(dirname(__DIR__));

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../modules/school/automation.php';

// Only include the helpers from automation.php — not the HTML output.
// Since automation.php starts with require_once, we use functions defined there.
// This cron script calls the already-loaded sch_runRule() + sch_getRecipients() helpers.

echo "[" . date('Y-m-d H:i:s') . "] School automation cron started.\n";

try {
    // Load all active orgs that have the school module enabled
    $orgs = $pdo->query(
        "SELECT DISTINCT s.org_id
         FROM subscriptions s
         JOIN subscription_modules sm ON sm.subscription_id = s.id
         JOIN modules m ON m.id = sm.module_id
         WHERE m.slug = 'school' AND s.status = 'active'"
    )->fetchAll(PDO::FETCH_COLUMN);

    foreach ($orgs as $orgId) {
        $orgId = (int)$orgId;

        // Load all enabled rules for this org
        $stmt = $pdo->prepare(
            "SELECT * FROM sch_auto_rules WHERE org_id=? AND enabled=1 ORDER BY id ASC"
        );
        $stmt->execute([$orgId]);
        $rules = $stmt->fetchAll();

        foreach ($rules as $rule) {
            echo "  Org #$orgId → Rule #{$rule['id']} \"{$rule['name']}\" ({$rule['event_type']})... ";
            $res = sch_runRule($rule, $pdo, $orgId);
            echo "total={$res['total']}, sent={$res['sent']}, queued={$res['queued']}, failed={$res['failed']}\n";
        }
    }
} catch (Throwable $e) {
    echo "[ERROR] " . $e->getMessage() . "\n";
    error_log('[cron/school-automation] ' . $e->getMessage());
}

echo "[" . date('Y-m-d H:i:s') . "] Done.\n";
