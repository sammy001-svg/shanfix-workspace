<?php
// ── Reminders & Tasks — in-app reminder system ───────────────────
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
requireClientAdmin();

$user  = currentUser();
$orgId = (int)$user['org_id'];
$userId = (int)$user['id'];

// ── Auto-create reminders table ───────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS org_reminders (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        org_id        INT NOT NULL,
        user_id       INT NOT NULL,
        title         VARCHAR(300) NOT NULL,
        notes         TEXT,
        due_date      DATE,
        due_time      TIME,
        priority      ENUM('low','normal','high','urgent') DEFAULT 'normal',
        status        ENUM('pending','done','snoozed') DEFAULT 'pending',
        snoozed_until DATETIME,
        linked_module VARCHAR(100),
        linked_url    VARCHAR(500),
        created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_org  (org_id),
        INDEX idx_user (user_id),
        INDEX idx_due  (due_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

// ── POST Handler (before any output) ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $title   = sanitize($_POST['title'] ?? '');
        $notes   = sanitize($_POST['notes'] ?? '');
        $dueDate = $_POST['due_date'] ?? null;
        $dueTime = $_POST['due_time'] ?? null;
        $priority = in_array($_POST['priority']??'', ['low','normal','high','urgent']) ? $_POST['priority'] : 'normal';
        $linkedModule = sanitize($_POST['linked_module'] ?? '');
        $linkedUrl    = sanitize($_POST['linked_url'] ?? '');

        if ($title) {
            try {
                $s = $pdo->prepare("INSERT INTO org_reminders (org_id, user_id, title, notes, due_date, due_time, priority, linked_module, linked_url) VALUES (?,?,?,?,?,?,?,?,?)");
                $s->execute([$orgId, $userId, $title, $notes ?: null, $dueDate ?: null, $dueTime ?: null, $priority, $linkedModule ?: null, $linkedUrl ?: null]);
                setFlash('success', 'Reminder added successfully.');
                logActivity('create', 'reminders', "Added reminder: {$title}");
            } catch (Exception $e) {
                setFlash('danger', 'Failed to add reminder.');
            }
        } else {
            setFlash('danger', 'Title is required.');
        }
        redirect(APP_URL . '/client/reminders.php' . (!empty($_GET['tab']) ? '?tab=' . urlencode($_GET['tab']) : ''));
    }

    if ($action === 'mark_done') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            try {
                $s = $pdo->prepare("UPDATE org_reminders SET status='done' WHERE id=? AND org_id=? AND user_id=?");
                $s->execute([$id, $orgId, $userId]);
                setFlash('success', 'Reminder marked as done.');
            } catch (Exception $e) {}
        }
        redirect(APP_URL . '/client/reminders.php' . (!empty($_GET['tab']) ? '?tab=' . urlencode($_GET['tab']) : ''));
    }

    if ($action === 'snooze') {
        $id    = (int)($_POST['id'] ?? 0);
        $hours = (int)($_POST['snooze_hours'] ?? 1);
        if ($id && $hours > 0) {
            try {
                $snoozeUntil = date('Y-m-d H:i:s', strtotime("+{$hours} hours"));
                $newDue      = date('Y-m-d', strtotime("+{$hours} hours"));
                $s = $pdo->prepare("UPDATE org_reminders SET status='snoozed', snoozed_until=?, due_date=? WHERE id=? AND org_id=? AND user_id=?");
                $s->execute([$snoozeUntil, $newDue, $id, $orgId, $userId]);
                setFlash('info', 'Reminder snoozed.');
            } catch (Exception $e) {}
        }
        redirect(APP_URL . '/client/reminders.php' . (!empty($_GET['tab']) ? '?tab=' . urlencode($_GET['tab']) : ''));
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            try {
                $s = $pdo->prepare("DELETE FROM org_reminders WHERE id=? AND org_id=? AND user_id=?");
                $s->execute([$id, $orgId, $userId]);
                setFlash('success', 'Reminder deleted.');
            } catch (Exception $e) {}
        }
        redirect(APP_URL . '/client/reminders.php' . (!empty($_GET['tab']) ? '?tab=' . urlencode($_GET['tab']) : ''));
    }
}

// ── Active tab / filter ───────────────────────────────────────────
$tab = in_array($_GET['tab'] ?? '', ['today','overdue','done']) ? $_GET['tab'] : 'all';

// ── KPI Stats ─────────────────────────────────────────────────────
$statDueToday = $statOverdue = $statDoneWeek = 0;
try {
    $s = $pdo->prepare("SELECT COUNT(*) FROM org_reminders WHERE user_id=? AND status='pending' AND due_date=CURDATE()");
    $s->execute([$userId]); $statDueToday = (int)$s->fetchColumn();
} catch (Exception $e) {}
try {
    $s = $pdo->prepare("SELECT COUNT(*) FROM org_reminders WHERE user_id=? AND status='pending' AND due_date < CURDATE()");
    $s->execute([$userId]); $statOverdue = (int)$s->fetchColumn();
} catch (Exception $e) {}
try {
    $s = $pdo->prepare("SELECT COUNT(*) FROM org_reminders WHERE user_id=? AND status='done' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $s->execute([$userId]); $statDoneWeek = (int)$s->fetchColumn();
} catch (Exception $e) {}

// ── Load reminders ────────────────────────────────────────────────
$reminders = [];
try {
    $whereExtra = '';
    switch ($tab) {
        case 'today':   $whereExtra = "AND due_date = CURDATE()"; break;
        case 'overdue': $whereExtra = "AND due_date < CURDATE() AND status='pending'"; break;
        case 'done':    $whereExtra = "AND status='done'"; break;
        default:        $whereExtra = "AND status IN ('pending','snoozed')"; break;
    }
    $s = $pdo->prepare("SELECT * FROM org_reminders WHERE user_id=? {$whereExtra} ORDER BY ISNULL(due_date) ASC, due_date ASC, priority DESC, created_at DESC LIMIT 100");
    $s->execute([$userId]);
    $reminders = $s->fetchAll();
} catch (Exception $e) {}

// ── Org modules list for linked_module select ─────────────────────
$moduleOptions = [
    'accounting' => 'Accounting',
    'crm'        => 'CRM',
    'sales'      => 'Sales',
    'retail'     => 'Retail',
    'sacco'      => 'SACCO',
    'rental'     => 'Rental',
    'hotel'      => 'Hotel',
    'health'     => 'Health',
    'church'     => 'Church',
    'school'     => 'School',
    'salon'      => 'Salon',
    'courier'    => 'Courier / Delivery',
    'manufacturing' => 'Manufacturing',
];

$priorityColors = ['low'=>'#64748b','normal'=>'#3b82f6','high'=>'#f59e0b','urgent'=>'#ef4444'];
$priorityLabels = ['low'=>'Low','normal'=>'Normal','high'=>'High','urgent'=>'Urgent'];

$pageTitle = 'Reminders & Tasks';
require_once __DIR__ . '/../includes/header-client.php';
?>

<!-- Page header -->
<div class="page-header d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-tasks me-2 text-green"></i>Reminders &amp; Tasks</h4>
    <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0">
      <li class="breadcrumb-item"><a href="<?= APP_URL ?>/client/index.php">Dashboard</a></li>
      <li class="breadcrumb-item active">Reminders</li>
    </ol></nav>
  </div>
</div>

<!-- KPI row -->
<div class="row g-3 mb-4">
  <div class="col-md-4">
    <div class="card border-0 shadow-sm">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="rounded-circle d-flex align-items-center justify-content-center" style="width:44px;height:44px;background:rgba(59,130,246,.12)">
          <i class="fas fa-calendar-day text-primary fa-lg"></i>
        </div>
        <div>
          <div class="fw-bold fs-5"><?= $statDueToday ?></div>
          <div class="text-muted small">Due Today</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card border-0 shadow-sm">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="rounded-circle d-flex align-items-center justify-content-center" style="width:44px;height:44px;background:rgba(239,68,68,.12)">
          <i class="fas fa-exclamation-circle text-danger fa-lg"></i>
        </div>
        <div>
          <div class="fw-bold fs-5 <?= $statOverdue > 0 ? 'text-danger' : '' ?>"><?= $statOverdue ?></div>
          <div class="text-muted small">Overdue</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card border-0 shadow-sm">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="rounded-circle d-flex align-items-center justify-content-center" style="width:44px;height:44px;background:rgba(26,138,78,.12)">
          <i class="fas fa-check-double text-success fa-lg"></i>
        </div>
        <div>
          <div class="fw-bold fs-5"><?= $statDoneWeek ?></div>
          <div class="text-muted small">Done This Week</div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Main layout: form (1/3) + list (2/3) -->
<div class="row g-4">

  <!-- ── Add Reminder Form ──────────────────────────────────────── -->
  <div class="col-lg-4">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-transparent">
        <h6 class="fw-bold mb-0"><i class="fas fa-plus-circle text-success me-2"></i>Add Reminder</h6>
      </div>
      <div class="card-body">
        <form method="POST" action="<?= APP_URL ?>/client/reminders.php?tab=<?= urlencode($tab) ?>">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="add">

          <div class="mb-3">
            <label class="form-label fw-semibold">Title <span class="text-danger">*</span></label>
            <input type="text" name="title" class="form-control" placeholder="What do you need to do?" required maxlength="300">
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold">Notes</label>
            <textarea name="notes" class="form-control" rows="2" placeholder="Optional details…" maxlength="2000"></textarea>
          </div>

          <div class="row g-2 mb-3">
            <div class="col-7">
              <label class="form-label fw-semibold">Due Date</label>
              <input type="date" name="due_date" class="form-control" min="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-5">
              <label class="form-label fw-semibold">Time</label>
              <input type="time" name="due_time" class="form-control">
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold">Priority</label>
            <select name="priority" class="form-select">
              <option value="low">Low</option>
              <option value="normal" selected>Normal</option>
              <option value="high">High</option>
              <option value="urgent">Urgent</option>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold">Linked Module <small class="text-muted">(optional)</small></label>
            <select name="linked_module" class="form-select">
              <option value="">— None —</option>
              <?php foreach ($moduleOptions as $slug => $label): ?>
              <option value="<?= e($slug) ?>"><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold">Link URL <small class="text-muted">(optional)</small></label>
            <input type="url" name="linked_url" class="form-control" placeholder="https://…" maxlength="500">
          </div>

          <button type="submit" class="btn btn-success w-100">
            <i class="fas fa-plus me-2"></i>Add Reminder
          </button>
        </form>
      </div>
    </div>
  </div>

  <!-- ── Reminders List ─────────────────────────────────────────── -->
  <div class="col-lg-8">

    <!-- Filter tabs -->
    <ul class="nav nav-tabs mb-3">
      <li class="nav-item">
        <a class="nav-link <?= $tab === 'all' ? 'active' : '' ?>" href="?tab=all">All</a>
      </li>
      <li class="nav-item">
        <a class="nav-link <?= $tab === 'today' ? 'active' : '' ?>" href="?tab=today">
          Today <?php if ($statDueToday > 0): ?><span class="badge bg-primary ms-1"><?= $statDueToday ?></span><?php endif; ?>
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link <?= $tab === 'overdue' ? 'active' : '' ?>" href="?tab=overdue">
          Overdue <?php if ($statOverdue > 0): ?><span class="badge bg-danger ms-1"><?= $statOverdue ?></span><?php endif; ?>
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link <?= $tab === 'done' ? 'active' : '' ?>" href="?tab=done">Done</a>
      </li>
    </ul>

    <?php if (empty($reminders)): ?>
    <div class="card border-0 shadow-sm">
      <div class="card-body text-center py-5 text-muted">
        <i class="fas fa-check-circle fa-4x mb-3 opacity-25"></i>
        <p class="mb-0">No reminders in this view.<br>
          <?php if ($tab === 'all'): ?>
          Use the form on the left to add your first reminder.
          <?php else: ?>
          <a href="?tab=all">View all reminders →</a>
          <?php endif; ?>
        </p>
      </div>
    </div>
    <?php else: ?>
    <div id="remindersList">
    <?php foreach ($reminders as $rem): ?>
    <?php
      $isOverdue = $rem['due_date'] && $rem['due_date'] < date('Y-m-d') && $rem['status'] === 'pending';
      $isDone    = $rem['status'] === 'done';
      $isSnoozed = $rem['status'] === 'snoozed';
      $pColor    = $priorityColors[$rem['priority']] ?? '#94a3b8';
      $pLabel    = $priorityLabels[$rem['priority']] ?? 'Normal';
      $cardBg    = $isOverdue ? 'border-danger' : '';
    ?>
    <div class="card border-0 shadow-sm mb-3 reminder-card <?= $cardBg ?> <?= $isOverdue ? 'overdue' : '' ?>"
         style="border-left:4px solid <?= $pColor ?> !important;<?= $isOverdue ? 'background:rgba(239,68,68,.04)' : '' ?>">
      <div class="card-body py-2 px-3">
        <div class="d-flex align-items-start gap-2">
          <!-- Priority dot -->
          <div class="flex-shrink-0 mt-1">
            <span class="rounded-circle d-inline-block" style="width:10px;height:10px;background:<?= $pColor ?>;" title="<?= $pLabel ?>"></span>
          </div>
          <!-- Content -->
          <div class="flex-grow-1">
            <div class="d-flex align-items-center gap-2 flex-wrap">
              <span class="fw-semibold <?= $isDone ? 'text-decoration-line-through text-muted' : '' ?>"><?= e($rem['title']) ?></span>
              <span class="badge" style="background:<?= $pColor ?>;font-size:.65rem"><?= $pLabel ?></span>
              <?php if ($isOverdue): ?>
              <span class="badge bg-danger" style="font-size:.65rem"><i class="fas fa-exclamation me-1"></i>Overdue</span>
              <?php elseif ($isSnoozed): ?>
              <span class="badge bg-secondary" style="font-size:.65rem"><i class="fas fa-clock me-1"></i>Snoozed</span>
              <?php elseif ($isDone): ?>
              <span class="badge bg-success" style="font-size:.65rem"><i class="fas fa-check me-1"></i>Done</span>
              <?php endif; ?>
            </div>

            <?php if ($rem['notes']): ?>
            <p class="text-muted small mb-1 mt-1"><?= e(mb_strimwidth($rem['notes'], 0, 120, '…')) ?></p>
            <?php endif; ?>

            <div class="d-flex align-items-center gap-3 flex-wrap mt-1" style="font-size:.75rem;color:#64748b">
              <?php if ($rem['due_date']): ?>
              <span><i class="fas fa-calendar me-1"></i><?= formatDate($rem['due_date']) ?><?= $rem['due_time'] ? ' at ' . date('h:i A', strtotime($rem['due_time'])) : '' ?></span>
              <?php endif; ?>
              <?php if ($rem['linked_module']): ?>
              <span><i class="fas fa-link me-1"></i>
                <?php if ($rem['linked_url']): ?>
                <a href="<?= e($rem['linked_url']) ?>" class="text-primary" target="_blank"><?= e($moduleOptions[$rem['linked_module']] ?? $rem['linked_module']) ?></a>
                <?php else: ?>
                <?= e($moduleOptions[$rem['linked_module']] ?? $rem['linked_module']) ?>
                <?php endif; ?>
              </span>
              <?php endif; ?>
              <span><i class="fas fa-clock me-1"></i><?= timeAgo($rem['created_at']) ?></span>
            </div>
          </div>

          <!-- Actions -->
          <?php if (!$isDone): ?>
          <div class="d-flex gap-1 flex-shrink-0">
            <!-- Mark Done -->
            <form method="POST" class="d-inline">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="mark_done">
              <input type="hidden" name="id" value="<?= $rem['id'] ?>">
              <button type="submit" class="btn btn-sm btn-success" title="Mark Done">
                <i class="fas fa-check"></i>
              </button>
            </form>

            <!-- Snooze dropdown -->
            <div class="dropdown d-inline">
              <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" title="Snooze">
                <i class="fas fa-bell-slash"></i>
              </button>
              <ul class="dropdown-menu dropdown-menu-end">
                <li class="dropdown-header small">Snooze for…</li>
                <?php foreach ([1=>'1 Hour', 24=>'1 Day', 168=>'1 Week'] as $hrs => $lbl): ?>
                <li>
                  <form method="POST" class="d-inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="snooze">
                    <input type="hidden" name="id" value="<?= $rem['id'] ?>">
                    <input type="hidden" name="snooze_hours" value="<?= $hrs ?>">
                    <button type="submit" class="dropdown-item"><?= $lbl ?></button>
                  </form>
                </li>
                <?php endforeach; ?>
              </ul>
            </div>

            <!-- Delete -->
            <form method="POST" class="d-inline" onsubmit="return confirm('Delete this reminder?')">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= $rem['id'] ?>">
              <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                <i class="fas fa-trash"></i>
              </button>
            </form>
          </div>
          <?php else: ?>
          <!-- Delete done item -->
          <form method="POST" class="d-inline" onsubmit="return confirm('Delete this reminder?')">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= $rem['id'] ?>">
            <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
              <i class="fas fa-trash"></i>
            </button>
          </form>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>

  </div>
</div>

<?php
require_once __DIR__ . '/../includes/footer.php';
