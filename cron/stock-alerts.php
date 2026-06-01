<?php
/**
 * Cron: Low Stock SMS + Email Alerts (POS / Retail modules)
 * Schedule: Daily at 7:00 AM
 * cPanel: 0 7 * * * php /home/USERNAME/public_html/shanfix/cron/stock-alerts.php >> /home/USERNAME/logs/shanfix-cron.log 2>&1
 *
 * Sends one alert per org per day when any product stock <= reorder_level.
 * Deduped per org per day — one consolidated message rather than per-product.
 * Covers both POS (pos_products) and Retail (retail_products) modules.
 */
define('CRON_RUN', true);
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/notifications.php';

$alertsSent = 0;
$errors     = 0;
$today      = date('Y-m-d');
$eventType  = 'low_stock_alert';

echo date('Y-m-d H:i:s') . " — stock-alerts.php starting...\n";

function stockAlreadySent(PDO $pdo, int $orgId): bool {
    try {
        $s = $pdo->prepare("SELECT id FROM scheduled_email_log WHERE event_type='low_stock_alert' AND reference_id=? AND period_date=?");
        $s->execute([$orgId, date('Y-m-d')]);
        return (bool)$s->fetch();
    } catch (Exception $e) { return false; }
}
function stockMarkSent(PDO $pdo, int $orgId): void {
    try {
        $pdo->prepare("INSERT IGNORE INTO scheduled_email_log (event_type,reference_id,period_date) VALUES ('low_stock_alert',?,?)")
            ->execute([$orgId, date('Y-m-d')]);
    } catch (Exception $e) {}
}

// ── Collect low-stock items per org (POS) ────────────────────────
$lowStockByOrg = [];

// POS products
try {
    $stmt = $pdo->prepare("
        SELECT p.org_id, p.name AS product_name, p.sku, p.stock_quantity AS qty,
               COALESCE(p.reorder_level, 5) AS reorder_level,
               'POS' AS module
        FROM pos_products p
        WHERE p.stock_quantity <= COALESCE(p.reorder_level, 5)
          AND p.status = 'active'
        ORDER BY p.org_id, p.stock_quantity ASC
        LIMIT 1000
    ");
    $stmt->execute();
    foreach ($stmt->fetchAll() as $row) {
        $lowStockByOrg[$row['org_id']][] = $row;
    }
} catch (Throwable $e) {
    echo "  [WARN] POS query failed: " . $e->getMessage() . "\n";
}

// Retail products
try {
    $stmt = $pdo->prepare("
        SELECT p.org_id, p.name AS product_name, p.sku, p.stock_quantity AS qty,
               COALESCE(p.reorder_level, 5) AS reorder_level,
               'Retail' AS module
        FROM retail_products p
        WHERE p.stock_quantity <= COALESCE(p.reorder_level, 5)
          AND p.status = 'active'
        ORDER BY p.org_id, p.stock_quantity ASC
        LIMIT 1000
    ");
    $stmt->execute();
    foreach ($stmt->fetchAll() as $row) {
        $lowStockByOrg[$row['org_id']][] = $row;
    }
} catch (Throwable $e) {
    echo "  [WARN] Retail query failed: " . $e->getMessage() . "\n";
}

echo "  Found low-stock items in " . count($lowStockByOrg) . " org(s).\n";

if (empty($lowStockByOrg)) {
    echo date('Y-m-d H:i:s') . " — No low-stock items today. Done.\n";
    exit(0);
}

// ── Fetch org details for alert recipients ────────────────────────
$orgIds  = array_keys($lowStockByOrg);
$placeholders = implode(',', array_fill(0, count($orgIds), '?'));

$orgDetails = [];
try {
    // Fetch org name + email of the client_admin user for each org
    $stmt = $pdo->prepare("
        SELECT o.id AS org_id, o.name AS org_name,
               u.email AS admin_email, u.phone AS admin_phone, u.name AS admin_name
        FROM organizations o
        JOIN users u ON u.org_id = o.id AND u.role = 'client_admin' AND u.status = 'active'
        WHERE o.id IN ($placeholders)
        GROUP BY o.id
        ORDER BY u.created_at ASC
    ");
    $stmt->execute($orgIds);
    foreach ($stmt->fetchAll() as $row) {
        $orgDetails[$row['org_id']] = $row;
    }
} catch (Throwable $e) {
    echo "  [ERROR] Org details query failed: " . $e->getMessage() . "\n";
    exit(1);
}

// ── Send one consolidated alert per org ───────────────────────────
foreach ($lowStockByOrg as $orgId => $items) {
    $orgId = (int)$orgId;

    if (stockAlreadySent($pdo, $orgId)) {
        echo "  [SKIP] org#{$orgId} already alerted today\n";
        continue;
    }

    $org      = $orgDetails[$orgId] ?? null;
    $orgName  = $org['org_name'] ?? "Org #{$orgId}";
    $adminName = $org['admin_name'] ?? 'Admin';
    $count    = count($items);

    // Build product list for email
    $tableRows = '';
    $smsList   = [];
    foreach (array_slice($items, 0, 20) as $item) {
        $tableRows .= "<tr>
            <td style='padding:7px 10px;border:1px solid #eee'>" . htmlspecialchars($item['product_name']) . "</td>
            <td style='padding:7px 10px;border:1px solid #eee;text-align:center'>" . htmlspecialchars($item['sku'] ?? '—') . "</td>
            <td style='padding:7px 10px;border:1px solid #eee;text-align:center;color:#e74c3c;font-weight:700'>" . $item['qty'] . "</td>
            <td style='padding:7px 10px;border:1px solid #eee;text-align:center'>" . $item['reorder_level'] . "</td>
            <td style='padding:7px 10px;border:1px solid #eee;text-align:center'>" . $item['module'] . "</td>
        </tr>";
        $smsList[] = $item['product_name'] . " (qty:" . $item['qty'] . ")";
    }
    if (count($items) > 20) {
        $tableRows .= "<tr><td colspan='5' style='padding:7px 10px;text-align:center;color:#94a3b8'>… and " . (count($items) - 20) . " more items</td></tr>";
    }

    $emailOk = false;

    // ── Email ──────────────────────────────────────────────────────
    if (!empty($org['admin_email'])) {
        $body = "
        <div style='font-family:Segoe UI,Arial,sans-serif;max-width:620px;margin:0 auto;background:#f0f4f8;padding:24px'>
          <div style='background:#0B2D4E;padding:20px 28px;border-radius:12px 12px 0 0;text-align:center'>
            <span style='color:white;font-size:1.2rem;font-weight:800'>" . APP_NAME . "</span>
            <div style='color:rgba(255,255,255,.6);font-size:.8rem;margin-top:4px'>{$orgName} — Stock Alert</div>
          </div>
          <div style='background:white;padding:32px;border-radius:0 0 12px 12px'>
            <p>Dear <strong>" . htmlspecialchars($adminName) . "</strong>,</p>
            <div style='background:#fef2f2;border-left:4px solid #ef4444;padding:16px 20px;border-radius:0 8px 8px 0;margin-bottom:20px'>
              <p style='color:#991b1b;font-weight:700;margin:0 0 4px'>⚠ Low Stock Alert — {$count} Product(s)</p>
              <p style='color:#7f1d1d;font-size:.9rem;margin:0'>The following products are at or below their reorder level as of " . date('d M Y') . ".</p>
            </div>
            <table style='width:100%;border-collapse:collapse;margin-bottom:20px;font-size:.85rem'>
              <thead>
                <tr style='background:#f8f9fa'>
                  <th style='padding:8px 10px;border:1px solid #eee;text-align:left'>Product</th>
                  <th style='padding:8px 10px;border:1px solid #eee;text-align:center'>SKU</th>
                  <th style='padding:8px 10px;border:1px solid #eee;text-align:center'>Stock</th>
                  <th style='padding:8px 10px;border:1px solid #eee;text-align:center'>Reorder At</th>
                  <th style='padding:8px 10px;border:1px solid #eee;text-align:center'>Module</th>
                </tr>
              </thead>
              <tbody>{$tableRows}</tbody>
            </table>
            <p style='color:#64748b;font-size:.85rem'>Please reorder these items to avoid stockouts. Log in to your inventory system to create a purchase order.</p>
            <div style='text-align:center;margin:20px 0'>
              <a href='" . APP_URL . "/modules/pos/stock.php'
                 style='background:#0B2D4E;color:white;padding:11px 26px;border-radius:50px;text-decoration:none;font-weight:700;font-size:.85rem;display:inline-block'>
                View Inventory →
              </a>
            </div>
            <hr style='border:none;border-top:1px solid #f1f5f9;margin:24px 0'>
            <p style='color:#94a3b8;font-size:.72rem;text-align:center'>&copy; " . date('Y') . " " . APP_NAME . "</p>
          </div>
        </div>";

        try {
            $emailOk = mailer()->send(
                $org['admin_email'],
                "⚠ Low Stock Alert: {$count} product(s) need reordering — {$orgName}",
                $body
            );
            if ($emailOk) $alertsSent++;
        } catch (Throwable $e) {
            error_log("[stock-alerts] Email failed org#{$orgId}: " . $e->getMessage());
            $errors++;
        }
    }

    // ── SMS ────────────────────────────────────────────────────────
    if (!empty($org['admin_phone'])) {
        $preview = implode(', ', array_slice($smsList, 0, 3));
        $more    = $count > 3 ? " + " . ($count - 3) . " more" : '';
        $sms = "Low Stock Alert ({$orgName}): {$count} product(s) need reordering — {$preview}{$more}. Login to reorder.";
        notifySms($org['admin_phone'], $sms, $orgId, 'low_stock_alert');
    }

    stockMarkSent($pdo, $orgId);

    notifyOrg(
        $orgId,
        "Low Stock: {$count} product(s) need reordering",
        implode(', ', array_slice($smsList, 0, 5)) . ($count > 5 ? '…' : ''),
        'warning',
        APP_URL . '/modules/pos/stock.php'
    );

    $status = ($emailOk || !empty($org['admin_phone'])) ? 'OK' : 'FAIL';
    echo "  [{$status}] org#{$orgId} ({$orgName}) — {$count} low-stock items\n";

    try {
        $pdo->prepare("INSERT INTO activity_log (action,module,description,ip) VALUES ('cron_alert','pos',?,?)")
            ->execute(["Low stock alert: {$count} item(s) in org#{$orgId}", 'cron']);
    } catch (Throwable $e) {}
}

echo "\n" . date('Y-m-d H:i:s') . " — Done. Alerts sent: {$alertsSent}, Errors: {$errors}\n";
