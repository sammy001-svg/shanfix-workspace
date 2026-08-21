<?php
require_once __DIR__ . '/_nav.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/notifications.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireLogin();

$user  = currentUser();
$orgId = (int)$user['org_id'];
$tab   = in_array($_GET['tab'] ?? '', ['rules','log']) ? $_GET['tab'] : 'rules';

// ── Helpers ────────────────────────────────────────────────────────────
function sch_logMessage(PDO $pdo, int $orgId, ?int $ruleId, string $channel,
    string $recipName, string $recipAddr, string $subject, string $message, string $status, string $err = ''): void
{
    try {
        $pdo->prepare(
            "INSERT INTO sch_message_log (org_id,rule_id,channel,recipient_name,recipient_addr,subject,message,status,error_msg)
             VALUES (?,?,?,?,?,?,?,?,?)"
        )->execute([$orgId,$ruleId,$channel,$recipName,$recipAddr,$subject,$message,$status,$err?:null]);
    } catch (Throwable $e) { error_log('[sch_message_log] '.$e->getMessage()); }
}

function sch_applyTemplate(string $tpl, array $vars): string {
    $keys = array_map(fn($k) => '{'.$k.'}', array_keys($vars));
    return str_replace($keys, array_values($vars), $tpl);
}

function sch_getRecipients(array $rule, PDO $pdo, int $orgId): array {
    $rows = [];
    try {
        switch ($rule['event_type']) {

            case 'fee_due_soon':
                $days = max(1,(int)$rule['days_offset']);
                $stmt = $pdo->prepare(
                    "SELECT CONCAT(st.first_name,' ',st.last_name) AS student_name,
                            st.parent_name, st.parent_phone AS phone, st.parent_email AS email,
                            c.name AS class,
                            f.amount, f.balance, f.due_date, f.fee_type
                     FROM sch_fees f
                     JOIN sch_students st ON st.id=f.student_id
                     LEFT JOIN sch_classes c ON c.id=st.class_id
                     WHERE f.org_id=? AND f.status IN ('unpaid','partial')
                       AND f.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)"
                );
                $stmt->execute([$orgId,$days]);
                foreach ($stmt->fetchAll() as $r) {
                    $rows[] = [
                        'name'         => $r['parent_name'] ?: $r['student_name'],
                        'phone'        => $r['phone'],
                        'email'        => $r['email'],
                        'student_name' => $r['student_name'],
                        'parent_name'  => $r['parent_name'],
                        'class'        => $r['class'] ?? '—',
                        'amount'       => number_format((float)$r['balance'], 2),
                        'due_date'     => date('d M Y', strtotime($r['due_date'])),
                    ];
                }
                break;

            case 'fee_overdue':
                $stmt = $pdo->prepare(
                    "SELECT CONCAT(st.first_name,' ',st.last_name) AS student_name,
                            st.parent_name, st.parent_phone AS phone, st.parent_email AS email,
                            c.name AS class, f.balance, f.due_date
                     FROM sch_fees f
                     JOIN sch_students st ON st.id=f.student_id
                     LEFT JOIN sch_classes c ON c.id=st.class_id
                     WHERE f.org_id=? AND f.status IN ('unpaid','partial') AND f.due_date < CURDATE()"
                );
                $stmt->execute([$orgId]);
                foreach ($stmt->fetchAll() as $r) {
                    $rows[] = [
                        'name'         => $r['parent_name'] ?: $r['student_name'],
                        'phone'        => $r['phone'],
                        'email'        => $r['email'],
                        'student_name' => $r['student_name'],
                        'parent_name'  => $r['parent_name'],
                        'class'        => $r['class'] ?? '—',
                        'amount'       => number_format((float)$r['balance'], 2),
                        'due_date'     => date('d M Y', strtotime($r['due_date'])),
                    ];
                }
                break;

            case 'attendance_absent':
                $stmt = $pdo->prepare(
                    "SELECT CONCAT(st.first_name,' ',st.last_name) AS student_name,
                            st.parent_name, st.parent_phone AS phone, st.parent_email AS email,
                            c.name AS class
                     FROM sch_attendance a
                     JOIN sch_students st ON st.id=a.student_id
                     LEFT JOIN sch_classes c ON c.id=st.class_id
                     WHERE a.org_id=? AND a.att_date=CURDATE() AND a.status='absent'"
                );
                $stmt->execute([$orgId]);
                foreach ($stmt->fetchAll() as $r) {
                    $rows[] = [
                        'name'         => $r['parent_name'] ?: $r['student_name'],
                        'phone'        => $r['phone'],
                        'email'        => $r['email'],
                        'student_name' => $r['student_name'],
                        'parent_name'  => $r['parent_name'],
                        'class'        => $r['class'] ?? '—',
                        'amount'       => '',
                        'due_date'     => date('d M Y'),
                    ];
                }
                break;

            case 'birthday':
                $days = max(0,(int)$rule['days_offset']);
                $stmt = $pdo->prepare(
                    "SELECT CONCAT(first_name,' ',last_name) AS student_name,
                            parent_name, parent_phone AS phone, parent_email AS email,
                            (SELECT name FROM sch_classes WHERE id=st.class_id) AS class
                     FROM sch_students st
                     WHERE org_id=? AND status='active'
                       AND DATE_FORMAT(dob,'%m-%d') = DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL ? DAY),'%m-%d')"
                );
                $stmt->execute([$orgId,$days]);
                foreach ($stmt->fetchAll() as $r) {
                    $rows[] = [
                        'name'         => $r['parent_name'] ?: $r['student_name'],
                        'phone'        => $r['phone'],
                        'email'        => $r['email'],
                        'student_name' => $r['student_name'],
                        'parent_name'  => $r['parent_name'],
                        'class'        => $r['class'] ?? '—',
                        'amount'       => '',
                        'due_date'     => '',
                    ];
                }
                break;

            case 'exam_result':
            case 'custom':
            default:
                // Send to all active parents
                $stmt = $pdo->prepare(
                    "SELECT CONCAT(first_name,' ',last_name) AS student_name,
                            parent_name, parent_phone AS phone, parent_email AS email,
                            (SELECT name FROM sch_classes WHERE id=st.class_id) AS class
                     FROM sch_students st WHERE org_id=? AND status='active'"
                );
                $stmt->execute([$orgId]);
                foreach ($stmt->fetchAll() as $r) {
                    $rows[] = [
                        'name'         => $r['parent_name'] ?: $r['student_name'],
                        'phone'        => $r['phone'],
                        'email'        => $r['email'],
                        'student_name' => $r['student_name'],
                        'parent_name'  => $r['parent_name'],
                        'class'        => $r['class'] ?? '—',
                        'amount'       => '',
                        'due_date'     => '',
                    ];
                }
                break;
        }
    } catch (Throwable $e) { error_log('[sch_getRecipients] '.$e->getMessage()); }
    return $rows;
}

function sch_runRule(array $rule, PDO $pdo, int $orgId): array {
    $sent = $failed = $queued = 0;
    $subject   = $rule['subject'] ?: APP_NAME . ' — School Notification';
    $recipients = sch_getRecipients($rule, $pdo, $orgId);

    // Load school name
    $schoolName = APP_NAME;
    try {
        $s = $pdo->prepare("SELECT name FROM organizations WHERE id=? LIMIT 1");
        $s->execute([$orgId]); $schoolName = $s->fetchColumn() ?: APP_NAME;
    } catch (Throwable $e) {}

    foreach ($recipients as $r) {
        $vars = [
            'student_name' => $r['student_name'],
            'parent_name'  => $r['parent_name'] ?: $r['name'],
            'class'        => $r['class'],
            'amount'       => $r['amount'],
            'due_date'     => $r['due_date'],
            'school_name'  => $schoolName,
            'date'         => date('d M Y'),
        ];
        $body = sch_applyTemplate($rule['template'], $vars);

        // Email
        if (in_array($rule['channel'], ['email','both']) && !empty($r['email'])) {
            try {
                require_once __DIR__ . '/../../includes/mailer.php';
                $html = "<div style='font-family:system-ui,sans-serif;max-width:600px;margin:0 auto'>"
                      . "<div style='background:#0B2D4E;padding:20px;border-radius:8px 8px 0 0'>"
                      . "<h2 style='color:#fff;margin:0;font-size:1.1rem'>".htmlspecialchars($subject)."</h2></div>"
                      . "<div style='background:#fff;padding:24px;border:1px solid #e2e8f0;border-top:none;border-radius:0 0 8px 8px'>"
                      . "<p>Dear ".htmlspecialchars($r['name']).",</p>"
                      . "<p>".nl2br(htmlspecialchars($body))."</p>"
                      . "<hr style='border:none;border-top:1px solid #e2e8f0;margin:16px 0'>"
                      . "<p style='font-size:.75rem;color:#94a3b8'>".htmlspecialchars($schoolName)." — automated notification.</p>"
                      . "</div></div>";
                mailer()->send($r['email'], $subject . ' — ' . $schoolName, $html);
                sch_logMessage($pdo, $orgId, $rule['id'], 'email', $r['name'], $r['email'], $subject, $body, 'sent');
                $sent++;
            } catch (Throwable $e) {
                sch_logMessage($pdo, $orgId, $rule['id'], 'email', $r['name'], $r['email'], $subject, $body, 'failed', $e->getMessage());
                $failed++;
            }
        }

        // SMS
        if (in_array($rule['channel'], ['sms','both']) && !empty($r['phone'])) {
            notifySms($r['phone'], mb_substr($body, 0, 160), $orgId, 'auto_rule_'.$rule['id']);
            sch_logMessage($pdo, $orgId, $rule['id'], 'sms', $r['name'], $r['phone'], '', $body, 'queued');
            $queued++;
        }
    }

    // Update last_run
    try {
        $pdo->prepare("UPDATE sch_auto_rules SET last_run=NOW() WHERE id=? AND org_id=?")->execute([$rule['id'],$orgId]);
    } catch (Throwable $e) {}

    return ['total' => count($recipients), 'sent' => $sent, 'failed' => $failed, 'queued' => $queued];
}

// ── POST Handlers ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf(); denyIfReadOnly($moduleSlug);
    $action = $_POST['action'] ?? '';

    if ($action === 'save_rule') {
        $id        = (int)($_POST['id'] ?? 0);
        $name      = sanitize($_POST['name']       ?? '');
        $evType    = sanitize($_POST['event_type'] ?? 'fee_due_soon');
        $channel   = in_array($_POST['channel']??'',['email','sms','both'])?$_POST['channel']:'email';
        $recips    = sanitize($_POST['recipients'] ?? 'parents');
        $days      = (int)($_POST['days_offset']   ?? 3);
        $subject   = sanitize($_POST['subject']    ?? '');
        $template  = sanitize($_POST['template']   ?? '');
        $enabled   = (int)($_POST['enabled']       ?? 1);

        if (!$name || !$template) { setFlash('danger','Name and message template are required.'); redirect('automation.php'); }
        try {
            if ($id > 0) {
                requireOrgOwnership('sch_auto_rules', $id, $orgId);
                $pdo->prepare("UPDATE sch_auto_rules SET name=?,event_type=?,channel=?,recipients=?,days_offset=?,subject=?,template=?,enabled=? WHERE id=? AND org_id=?")
                    ->execute([$name,$evType,$channel,$recips,$days,$subject,$template,$enabled,$id,$orgId]);
                setFlash('success','Rule updated.');
            } else {
                $pdo->prepare("INSERT INTO sch_auto_rules (org_id,name,event_type,channel,recipients,days_offset,subject,template,enabled) VALUES (?,?,?,?,?,?,?,?,?)")
                    ->execute([$orgId,$name,$evType,$channel,$recips,$days,$subject,$template,$enabled]);
                setFlash('success',"Rule '$name' created.");
            }
            logActivity($id>0?'update':'create','school',"Auto rule: $name ($evType)");
        } catch (Throwable $e) {
            error_log('[school/automation save] '.$e->getMessage());
            setFlash('danger','Could not save. Run database/school_automation_migration.sql first.');
        }
        redirect('automation.php');
    }

    if ($action === 'delete_rule') {
        $id = (int)($_POST['id'] ?? 0);
        requireOrgOwnership('sch_auto_rules', $id, $orgId);
        $pdo->prepare("DELETE FROM sch_auto_rules WHERE id=? AND org_id=?")->execute([$id,$orgId]);
        setFlash('success','Rule deleted.'); redirect('automation.php');
    }

    if ($action === 'toggle_rule') {
        $id  = (int)($_POST['id']      ?? 0);
        $val = (int)($_POST['enabled'] ?? 0);
        requireOrgOwnership('sch_auto_rules', $id, $orgId);
        $pdo->prepare("UPDATE sch_auto_rules SET enabled=? WHERE id=? AND org_id=?")->execute([$val,$id,$orgId]);
        setFlash('success','Rule '.($val?'enabled':'disabled').'.');
        redirect('automation.php');
    }

    if ($action === 'run_rule') {
        $id = (int)($_POST['id'] ?? 0);
        requireOrgOwnership('sch_auto_rules', $id, $orgId);
        $r = $pdo->prepare("SELECT * FROM sch_auto_rules WHERE id=? AND org_id=?");
        $r->execute([$id,$orgId]);
        $rule = $r->fetch();
        if ($rule) {
            $res = sch_runRule($rule, $pdo, $orgId);
            $msg = "Rule ran: {$res['total']} recipient(s)";
            if ($res['sent'])   $msg .= ", {$res['sent']} email(s) sent";
            if ($res['queued']) $msg .= ", {$res['queued']} SMS queued";
            if ($res['failed']) $msg .= ", {$res['failed']} failed";
            setFlash($res['failed'] ? 'warning' : 'success', $msg.'.');
        } else {
            setFlash('danger','Rule not found.');
        }
        redirect('automation.php');
    }

    if ($action === 'clear_log') {
        $pdo->prepare("DELETE FROM sch_message_log WHERE org_id=?")->execute([$orgId]);
        setFlash('success','Message log cleared.'); redirect('automation.php?tab=log');
    }
}

// ── AJAX ───────────────────────────────────────────────────────────────
if (isset($_GET['fetch_rule'])) {
    $r = $pdo->prepare("SELECT * FROM sch_auto_rules WHERE id=? AND org_id=?");
    $r->execute([(int)$_GET['fetch_rule'],$orgId]);
    header('Content-Type: application/json'); echo json_encode($r->fetch()?:[]); exit;
}

// ── Summary stats ──────────────────────────────────────────────────────
$statTotal = $statEnabled = $statLogSent = $statLogFailed = 0;
try {
    $s = $pdo->prepare("SELECT COUNT(*) AS total, SUM(enabled=1) AS enabled FROM sch_auto_rules WHERE org_id=?");
    $s->execute([$orgId]); $row=$s->fetch();
    $statTotal   = (int)($row['total']??0);
    $statEnabled = (int)($row['enabled']??0);

    $s = $pdo->prepare("SELECT SUM(status='sent') AS sent, SUM(status='failed') AS failed FROM sch_message_log WHERE org_id=?");
    $s->execute([$orgId]); $row=$s->fetch();
    $statLogSent   = (int)($row['sent']??0);
    $statLogFailed = (int)($row['failed']??0);
} catch (Throwable $e) {}

// ── Load rules ─────────────────────────────────────────────────────────
$rules = [];
try {
    $s = $pdo->prepare("SELECT r.*, (SELECT COUNT(*) FROM sch_message_log WHERE rule_id=r.id) AS log_count FROM sch_auto_rules r WHERE r.org_id=? ORDER BY r.enabled DESC, r.name ASC");
    $s->execute([$orgId]); $rules = $s->fetchAll();
} catch (Throwable $e) {}

// ── Load log ───────────────────────────────────────────────────────────
$logRows = [];
if ($tab === 'log') {
    $fStatus  = sanitize($_GET['status'] ?? '');
    $fChannel = sanitize($_GET['channel'] ?? '');
    $logWhere = 'org_id=?'; $logParams = [$orgId];
    if ($fStatus)  { $logWhere .= ' AND status=?';  $logParams[] = $fStatus; }
    if ($fChannel) { $logWhere .= ' AND channel=?'; $logParams[] = $fChannel; }
    try {
        $s = $pdo->prepare("SELECT * FROM sch_message_log WHERE $logWhere ORDER BY sent_at DESC LIMIT 300");
        $s->execute($logParams); $logRows = $s->fetchAll();
    } catch (Throwable $e) {}
}

$eventLabels = [
    'fee_due_soon'   => 'Fee Due Soon',
    'fee_overdue'    => 'Fee Overdue',
    'attendance_absent' => 'Attendance Absent',
    'exam_result'    => 'Exam Result',
    'birthday'       => 'Birthday',
    'custom'         => 'Custom',
];

require_once __DIR__ . '/../../includes/header-module.php';
$fStatus  = $fStatus  ?? '';
$fChannel = $fChannel ?? '';
?>
<?= flashAlert() ?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-robot me-2" style="color:<?= $moduleColor ?>"></i>Communication Automation</h4>
    <p class="text-muted mb-0">Set up automatic SMS &amp; email alerts triggered by school events</p>
  </div>
  <?php if ($tab === 'rules'): ?>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#ruleModal" onclick="openAddRule()">
    <i class="fas fa-plus me-2"></i>New Rule
  </button>
  <?php endif; ?>
</div>

<!-- Summary Cards -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-lg-3">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="rounded-circle text-white d-flex align-items-center justify-content-center flex-shrink-0" style="width:48px;height:48px;background:#0B2D4E">
          <i class="fas fa-list"></i>
        </div>
        <div><div class="fs-4 fw-bold"><?= $statTotal ?></div><div class="text-muted small">Total Rules</div></div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="rounded-circle text-white d-flex align-items-center justify-content-center flex-shrink-0" style="width:48px;height:48px;background:#1A8A4E">
          <i class="fas fa-toggle-on"></i>
        </div>
        <div><div class="fs-4 fw-bold"><?= $statEnabled ?></div><div class="text-muted small">Active Rules</div></div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="rounded-circle text-white d-flex align-items-center justify-content-center flex-shrink-0" style="width:48px;height:48px;background:#1A8A4E">
          <i class="fas fa-paper-plane"></i>
        </div>
        <div><div class="fs-4 fw-bold"><?= number_format($statLogSent) ?></div><div class="text-muted small">Messages Sent</div></div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="rounded-circle text-white d-flex align-items-center justify-content-center flex-shrink-0" style="width:48px;height:48px;background:<?= $statLogFailed>0?'#dc3545':'#6c757d' ?>">
          <i class="fas fa-exclamation-circle"></i>
        </div>
        <div><div class="fs-4 fw-bold <?= $statLogFailed>0?'text-danger':'' ?>"><?= number_format($statLogFailed) ?></div><div class="text-muted small">Failed</div></div>
      </div>
    </div>
  </div>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link <?= $tab==='rules'?'active':'' ?>" href="automation.php?tab=rules"><i class="fas fa-cog me-1"></i>Rules</a></li>
  <li class="nav-item"><a class="nav-link <?= $tab==='log'?'active':'' ?>"   href="automation.php?tab=log"><i class="fas fa-history me-1"></i>Send Log</a></li>
</ul>

<!-- Cron hint -->
<div class="alert alert-info border-0 d-flex align-items-start gap-2 mb-3 py-2">
  <i class="fas fa-info-circle mt-1 flex-shrink-0"></i>
  <div class="small">
    <strong>Scheduling:</strong> Click <em>Run Now</em> to trigger a rule manually. For automatic daily execution, add a cron job in cPanel:
    <code class="ms-1">0 7 * * * php <?= rtrim(APP_URL,'/')  ?>/cron/school-automation.php</code>
  </div>
</div>

<?php if ($tab === 'rules'): ?>
<!-- ══════════════════════ RULES TAB ══════════════════════ -->
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0 fw-bold"><i class="fas fa-cog me-2 text-success"></i>Automation Rules</h6>
    <span class="badge bg-secondary"><?= count($rules) ?> rule<?= count($rules)!=1?'s':'' ?></span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead class="table-light">
          <tr>
            <th style="width:36px"></th>
            <th>Rule Name</th>
            <th>Trigger</th>
            <th>Channel</th>
            <th>Recipients</th>
            <th>Last Run</th>
            <th>Log</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($rules)): ?>
          <tr><td colspan="8" class="text-center text-muted py-5">
            <i class="fas fa-robot fa-2x d-block mb-2"></i>No rules yet. Create your first automation rule.
          </td></tr>
          <?php else: foreach ($rules as $rule):
            $chColors = ['email'=>'info','sms'=>'success','both'=>'primary'];
            $cc = $chColors[$rule['channel']] ?? 'secondary';
          ?>
          <tr class="<?= !$rule['enabled'] ? 'opacity-50' : '' ?>">
            <td>
              <form method="POST" class="d-inline"><?= csrfField() ?>
                <input type="hidden" name="action"  value="toggle_rule">
                <input type="hidden" name="id"      value="<?= $rule['id'] ?>">
                <input type="hidden" name="enabled" value="<?= $rule['enabled'] ? 0 : 1 ?>">
                <button type="submit" class="btn btn-sm border-0 p-0" title="<?= $rule['enabled']?'Disable':'Enable' ?>">
                  <i class="fas fa-toggle-<?= $rule['enabled']?'on text-success':'off text-secondary' ?> fa-lg"></i>
                </button>
              </form>
            </td>
            <td>
              <div class="fw-semibold text-dark"><?= e($rule['name']) ?></div>
              <?php if ($rule['subject']): ?>
              <small class="text-muted"><?= e(mb_strimwidth($rule['subject'],0,50,'...')) ?></small>
              <?php endif; ?>
            </td>
            <td>
              <span class="badge bg-light text-dark border"><?= $eventLabels[$rule['event_type']] ?? $rule['event_type'] ?></span>
              <?php if (in_array($rule['event_type'],['fee_due_soon','birthday'])): ?>
              <small class="text-muted d-block"><?= abs((int)$rule['days_offset']) ?> day<?= abs((int)$rule['days_offset'])!=1?'s':'' ?> before</small>
              <?php endif; ?>
            </td>
            <td><span class="badge bg-<?= $cc ?>"><?= strtoupper($rule['channel']) ?></span></td>
            <td><?= ucwords(str_replace('_',' ',$rule['recipients'])) ?></td>
            <td>
              <?= $rule['last_run']
                  ? '<small>'.date('d M Y H:i', strtotime($rule['last_run'])).'</small>'
                  : '<small class="text-muted">Never</small>' ?>
            </td>
            <td>
              <a href="automation.php?tab=log" class="badge bg-light text-dark border text-decoration-none">
                <?= $rule['log_count'] ?> msg<?= $rule['log_count']!=1?'s':'' ?>
              </a>
            </td>
            <td class="text-center">
              <div class="btn-group btn-group-sm">
                <button class="btn btn-outline-primary"  onclick="openEditRule(<?= $rule['id'] ?>)" title="Edit"><i class="fas fa-edit"></i></button>
                <form method="POST" class="d-inline">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="run_rule">
                  <input type="hidden" name="id"     value="<?= $rule['id'] ?>">
                  <button type="submit" class="btn btn-outline-success" title="Run Now" onclick="return confirm('Run rule \'<?= e(addslashes($rule['name'])) ?>\' now and send messages?')">
                    <i class="fas fa-play"></i>
                  </button>
                </form>
                <button class="btn btn-outline-danger" onclick="delRule(<?= $rule['id'] ?>, '<?= e(addslashes($rule['name'])) ?>')" title="Delete"><i class="fas fa-trash"></i></button>
              </div>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php else: ?>
<!-- ══════════════════════ LOG TAB ═══════════════════════ -->
<div class="card mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <input type="hidden" name="tab" value="log">
      <div class="col-sm-3">
        <select name="status" class="form-select form-select-sm">
          <option value="">All Statuses</option>
          <option value="sent"   <?= $fStatus==='sent'?'selected':'' ?>>Sent</option>
          <option value="failed" <?= $fStatus==='failed'?'selected':'' ?>>Failed</option>
          <option value="queued" <?= $fStatus==='queued'?'selected':'' ?>>Queued (SMS)</option>
        </select>
      </div>
      <div class="col-sm-3">
        <select name="channel" class="form-select form-select-sm">
          <option value="">All Channels</option>
          <option value="email" <?= $fChannel==='email'?'selected':'' ?>>Email</option>
          <option value="sms"   <?= $fChannel==='sms'?'selected':'' ?>>SMS</option>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button>
        <a href="automation.php?tab=log" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
      </div>
      <div class="col-auto ms-auto">
        <form method="POST" class="d-inline" onsubmit="return confirm('Clear entire message log for this organisation?')">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="clear_log">
          <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash me-1"></i>Clear Log</button>
        </form>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0 fw-bold"><i class="fas fa-history me-2 text-success"></i>Message Send Log</h6>
    <span class="badge bg-secondary"><?= count($logRows) ?> entr<?= count($logRows)!=1?'ies':'y' ?></span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Recipient</th>
            <th>Address</th>
            <th>Channel</th>
            <th>Subject / Preview</th>
            <th>Status</th>
            <th>Sent At</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($logRows)): ?>
          <tr><td colspan="6" class="text-center text-muted py-5">
            <i class="fas fa-history fa-2x d-block mb-2"></i>No messages logged yet.
          </td></tr>
          <?php else: foreach ($logRows as $log):
            $sc = ['sent'=>'success','failed'=>'danger','queued'=>'warning'][$log['status']] ?? 'secondary';
          ?>
          <tr>
            <td class="fw-semibold"><?= e($log['recipient_name'] ?: '—') ?></td>
            <td><small class="text-muted"><?= e($log['recipient_addr'] ?: '—') ?></small></td>
            <td><span class="badge bg-<?= $log['channel']==='email'?'info':'success' ?>"><?= strtoupper($log['channel']) ?></span></td>
            <td>
              <?php if ($log['subject']): ?>
              <div class="small fw-semibold"><?= e($log['subject']) ?></div>
              <?php endif; ?>
              <small class="text-muted"><?= e(mb_strimwidth($log['message'],0,60,'...')) ?></small>
              <?php if ($log['error_msg']): ?>
              <div class="small text-danger mt-1"><i class="fas fa-exclamation-triangle me-1"></i><?= e(mb_strimwidth($log['error_msg'],0,80,'...')) ?></div>
              <?php endif; ?>
            </td>
            <td><span class="badge bg-<?= $sc ?>"><?= ucfirst($log['status']) ?></span></td>
            <td><small><?= date('d M Y H:i', strtotime($log['sent_at'])) ?></small></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Rule Modal -->
<div class="modal fade" id="ruleModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST"><?= csrfField() ?>
      <input type="hidden" name="action" value="save_rule">
      <input type="hidden" name="id" id="ruleId" value="0">
      <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
        <h5 class="modal-title" id="ruleTitle"><i class="fas fa-robot me-2"></i>New Automation Rule</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-12">
            <label class="form-label fw-semibold">Rule Name <span class="text-danger">*</span></label>
            <input type="text" name="name" id="ruleName" class="form-control" required placeholder="e.g. Fee Reminder — 3 Days Before Due">
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">Trigger Event</label>
            <select name="event_type" id="ruleEvent" class="form-select" onchange="updateDaysLabel(this)">
              <?php foreach ($eventLabels as $k=>$v): ?>
              <option value="<?= $k ?>"><?= $v ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">Channel</label>
            <select name="channel" id="ruleChannel" class="form-select">
              <option value="email">Email only</option>
              <option value="sms">SMS only</option>
              <option value="both">Email + SMS</option>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold" id="daysLabel">Days Before Event</label>
            <input type="number" name="days_offset" id="ruleDays" class="form-control" min="0" max="90" value="3">
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Recipients</label>
            <select name="recipients" id="ruleRecips" class="form-select">
              <option value="parents">All Parents</option>
              <option value="students">All Students</option>
              <option value="staff">All Staff</option>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Status</label>
            <select name="enabled" id="ruleEnabled" class="form-select">
              <option value="1">Enabled</option>
              <option value="0">Disabled</option>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold">Email Subject</label>
            <input type="text" name="subject" id="ruleSubject" class="form-control" placeholder="e.g. Fee Payment Reminder — {school_name}">
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold">Message Template <span class="text-danger">*</span></label>
            <textarea name="template" id="ruleTemplate" class="form-control" rows="5" required
              placeholder="Dear {parent_name},&#10;&#10;This is a reminder that {student_name} in {class} has a fee balance of {amount} due on {due_date}.&#10;&#10;Please make payment at your earliest convenience.&#10;&#10;Regards,&#10;{school_name}"></textarea>
            <div class="form-text">
              Available variables:
              <code>{student_name}</code> <code>{parent_name}</code> <code>{class}</code>
              <code>{amount}</code> <code>{due_date}</code> <code>{school_name}</code> <code>{date}</code>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save Rule</button>
      </div>
      </form>
    </div>
  </div>
</div>

<form method="POST" id="delRuleForm" style="display:none"><?= csrfField() ?><input type="hidden" name="action" value="delete_rule"><input type="hidden" name="id" id="delRuleId"></form>

<?php ob_start(); ?>
<script>
function openAddRule() {
  document.getElementById('ruleTitle').innerHTML = '<i class="fas fa-robot me-2"></i>New Automation Rule';
  document.getElementById('ruleId').value       = '0';
  document.getElementById('ruleName').value     = '';
  document.getElementById('ruleEvent').value    = 'fee_due_soon';
  document.getElementById('ruleChannel').value  = 'email';
  document.getElementById('ruleDays').value     = '3';
  document.getElementById('ruleRecips').value   = 'parents';
  document.getElementById('ruleEnabled').value  = '1';
  document.getElementById('ruleSubject').value  = '';
  document.getElementById('ruleTemplate').value = '';
  updateDaysLabel(document.getElementById('ruleEvent'));
}
function openEditRule(id) {
  fetch('automation.php?fetch_rule='+id).then(r=>r.json()).then(d=>{
    if(!d.id) return;
    document.getElementById('ruleTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Rule';
    document.getElementById('ruleId').value       = d.id;
    document.getElementById('ruleName').value     = d.name||'';
    document.getElementById('ruleEvent').value    = d.event_type||'fee_due_soon';
    document.getElementById('ruleChannel').value  = d.channel||'email';
    document.getElementById('ruleDays').value     = d.days_offset||'3';
    document.getElementById('ruleRecips').value   = d.recipients||'parents';
    document.getElementById('ruleEnabled').value  = d.enabled||'1';
    document.getElementById('ruleSubject').value  = d.subject||'';
    document.getElementById('ruleTemplate').value = d.template||'';
    updateDaysLabel(document.getElementById('ruleEvent'));
    new bootstrap.Modal(document.getElementById('ruleModal')).show();
  });
}
function updateDaysLabel(sel) {
  const noOffset = ['fee_overdue','attendance_absent','exam_result','custom'];
  const lbl = document.getElementById('daysLabel');
  const inp = document.getElementById('ruleDays');
  if (noOffset.includes(sel.value)) {
    lbl.textContent = 'Days Offset (N/A)';
    inp.value = '0'; inp.disabled = true;
  } else {
    lbl.textContent = sel.value === 'birthday' ? 'Days Before Birthday' : 'Days Before Due Date';
    inp.disabled = false;
  }
}
function delRule(id, name) {
  if (confirm('Delete rule "'+name+'"? Its log entries will remain.')) {
    document.getElementById('delRuleId').value = id;
    document.getElementById('delRuleForm').submit();
  }
}
</script>
<?php
$extraJs = ob_get_clean();
require_once __DIR__ . '/../../includes/footer.php';
?>
