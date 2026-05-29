<?php
/**
 * Cron: Welcome Emails for New Organisations
 * Schedule: Daily at 8:30 AM
 * cPanel: 30 8 * * * php /home/USERNAME/public_html/shanfix/cron/welcome-emails.php >> /home/USERNAME/logs/shanfix-cron.log 2>&1
 */
define('CRON_RUN', true);
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/notifications.php';

$sent   = 0;
$errors = 0;

echo date('Y-m-d H:i:s') . " — welcome-emails.php starting...\n";

// ── Dedup helpers ─────────────────────────────────────────────────
function welAlreadySent(PDO $pdo, int $orgId): bool
{
    try {
        $s = $pdo->prepare("SELECT id FROM scheduled_email_log WHERE event_type='org_welcome' AND reference_id=?");
        $s->execute([$orgId]);
        return (bool)$s->fetch();
    } catch (Exception $e) {
        return false;
    }
}

function welMarkSent(PDO $pdo, int $orgId): void
{
    try {
        $pdo->prepare("INSERT IGNORE INTO scheduled_email_log (event_type, reference_id, period_date) VALUES ('org_welcome',?,?)")
            ->execute([$orgId, date('Y-m-d')]);
    } catch (Exception $e) {}
}

// ── Email body builder ────────────────────────────────────────────
function buildWelcomeEmailBody(array $org, string $adminName): string
{
    $dashUrl  = APP_URL . '/client/index.php';
    $loginUrl = APP_URL . '/auth/org-login.php?org=' . urlencode($org['slug'] ?? '');
    $orgName  = htmlspecialchars($org['name'], ENT_QUOTES);
    $name     = htmlspecialchars($adminName, ENT_QUOTES);
    $appName  = APP_NAME;
    $year     = date('Y');

    return "
    <div style='font-family:Segoe UI,Arial,sans-serif;max-width:600px;margin:0 auto;background:#f0f4f8;padding:24px'>

      <!-- Header brand bar -->
      <div style='background:#0B2D4E;padding:20px 28px;border-radius:12px 12px 0 0;text-align:center'>
        <span style='color:white;font-size:1.3rem;font-weight:800;letter-spacing:-.5px'>{$appName}</span>
      </div>

      <!-- Body -->
      <div style='background:white;padding:36px 32px;border-radius:0 0 12px 12px'>

        <!-- Hero -->
        <div style='text-align:center;margin-bottom:28px'>
          <div style='display:inline-flex;align-items:center;justify-content:center;width:64px;height:64px;background:linear-gradient(135deg,#0B2D4E,#1A8A4E);border-radius:50%;margin-bottom:12px'>
            <span style='font-size:1.8rem'>&#127881;</span>
          </div>
          <h1 style='color:#0B2D4E;font-size:1.5rem;font-weight:800;margin:0 0 6px'>Welcome aboard, {$name}!</h1>
          <p style='color:#64748b;margin:0;font-size:.95rem'><strong>{$orgName}</strong> is now live on {$appName}.</p>
        </div>

        <p style='color:#374151;margin:0 0 20px'>
          We're thrilled to have you with us. Your workspace is ready — here's a quick checklist to get you up and running in minutes:
        </p>

        <!-- Getting-started checklist -->
        <div style='background:#f8fafc;border-radius:10px;padding:20px 24px;margin-bottom:24px'>
          <p style='color:#0B2D4E;font-weight:700;font-size:.9rem;margin:0 0 14px;text-transform:uppercase;letter-spacing:.05em'>Getting Started</p>

          <div style='display:flex;align-items:flex-start;gap:12px;margin-bottom:12px'>
            <div style='width:24px;height:24px;background:#E6F5EE;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:1px'>
              <span style='color:#1A8A4E;font-size:.75rem;font-weight:700'>1</span>
            </div>
            <div>
              <strong style='color:#1E293B;font-size:.9rem'>Add your team members</strong>
              <div style='color:#64748b;font-size:.82rem;margin-top:2px'>Invite colleagues via Team &rarr; Users and assign them roles.</div>
            </div>
          </div>

          <div style='display:flex;align-items:flex-start;gap:12px;margin-bottom:12px'>
            <div style='width:24px;height:24px;background:#E6F5EE;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:1px'>
              <span style='color:#1A8A4E;font-size:.75rem;font-weight:700'>2</span>
            </div>
            <div>
              <strong style='color:#1E293B;font-size:.9rem'>Set up your first module</strong>
              <div style='color:#64748b;font-size:.82rem;margin-top:2px'>Go to My Modules and activate the tools that fit your business.</div>
            </div>
          </div>

          <div style='display:flex;align-items:flex-start;gap:12px'>
            <div style='width:24px;height:24px;background:#E6F5EE;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:1px'>
              <span style='color:#1A8A4E;font-size:.75rem;font-weight:700'>3</span>
            </div>
            <div>
              <strong style='color:#1E293B;font-size:.9rem'>Customize your login portal URL</strong>
              <div style='color:#64748b;font-size:.82rem;margin-top:2px'>Your unique portal is at <a href='{$loginUrl}' style='color:#1A8A4E'>{$loginUrl}</a> — share it with your team.</div>
            </div>
          </div>
        </div>

        <!-- CTA -->
        <div style='text-align:center;margin:28px 0'>
          <a href='{$dashUrl}'
             style='background:#1A8A4E;color:white;padding:13px 32px;border-radius:50px;text-decoration:none;font-weight:700;font-size:1rem;display:inline-block;box-shadow:0 4px 12px rgba(26,138,78,.25)'>
            Go to My Dashboard &rarr;
          </a>
        </div>

        <!-- Login URL reminder -->
        <div style='background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:14px 18px;margin-bottom:24px'>
          <p style='color:#1d4ed8;font-size:.82rem;margin:0'>
            <strong>Your login portal URL:</strong><br>
            <a href='{$loginUrl}' style='color:#1d4ed8;word-break:break-all'>{$loginUrl}</a>
          </p>
        </div>

        <!-- Support -->
        <p style='color:#64748b;font-size:.85rem;margin:0 0 4px'>
          Need help? Our support team is ready for you.
        </p>
        <p style='color:#64748b;font-size:.85rem;margin:0'>
          <a href='" . APP_URL . "/client/support.php' style='color:#1A8A4E'>Open a Support Ticket</a>
          &nbsp;&bull;&nbsp;
          <a href='" . APP_URL . "' style='color:#1A8A4E'>Visit Website</a>
        </p>

        <hr style='border:none;border-top:1px solid #f1f5f9;margin:24px 0'>
        <p style='color:#94a3b8;font-size:.75rem;margin:0;text-align:center'>
          &copy; {$year} {$appName} &bull; You are receiving this because you registered an account.
        </p>
      </div>
    </div>";
}

// ── Query new organisations (last 24 h, no welcome yet) ───────────
try {
    $stmt = $pdo->prepare("
        SELECT o.*, u.name AS admin_name, u.email AS admin_email
        FROM organizations o
        JOIN users u ON u.org_id = o.id AND u.role = 'client_admin'
        WHERE o.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
          AND o.id NOT IN (
              SELECT reference_id FROM scheduled_email_log WHERE event_type='org_welcome'
          )
        LIMIT 200
    ");
    $stmt->execute();
    $newOrgs = $stmt->fetchAll();
} catch (Exception $e) {
    error_log('[welcome-emails] Query failed: ' . $e->getMessage());
    $newOrgs = [];
}

echo "  Found " . count($newOrgs) . " new org(s) to welcome.\n";

foreach ($newOrgs as $org) {
    $orgId     = (int)$org['id'];
    $adminName = $org['admin_name'];
    $adminEmail = $org['admin_email'];

    // Double-check dedup (race condition safety)
    if (welAlreadySent($pdo, $orgId)) {
        echo "  [SKIP] org#$orgId ({$org['name']}) welcome already sent\n";
        continue;
    }

    $subject = "Welcome to " . APP_NAME . " — Your workspace is ready!";

    try {
        $body = buildWelcomeEmailBody($org, $adminName);
        $ok   = mailer()->send($adminEmail, $subject, $body);
    } catch (Exception $e) {
        error_log("[welcome-emails] Email build/send failed for org#{$orgId}: " . $e->getMessage());
        $ok = false;
    }

    if ($ok) {
        $sent++;
        welMarkSent($pdo, $orgId);
        echo "  [OK] Welcome sent to {$adminEmail} for org#$orgId ({$org['name']})\n";
    } else {
        $errors++;
        error_log("[welcome-emails] Failed for org#{$orgId} to {$adminEmail}");
        echo "  [FAIL] org#$orgId ({$org['name']}) — email failed\n";
    }

    // In-app notification for all org users
    notifyOrg(
        $orgId,
        'Welcome to ' . APP_NAME . '!',
        'Your workspace is set up and ready. Start by exploring your modules and inviting your team.',
        'success',
        APP_URL . '/client/index.php'
    );

    // Activity log
    try {
        $pdo->prepare(
            "INSERT INTO activity_log (action, module, description, ip) VALUES ('cron_email','onboarding',?,?)"
        )->execute([
            ($ok ? 'Welcome email sent' : 'Welcome email FAILED') . " — Org #{$orgId} ({$org['name']}) to {$adminEmail}",
            'cron',
        ]);
    } catch (Exception $e) {}
}

echo date('Y-m-d H:i:s')
    . " — Done. New orgs: " . count($newOrgs)
    . ", Sent: {$sent}, Errors: {$errors}\n";
