<?php
$pageTitle = 'Support Tickets';
require_once __DIR__ . '/../includes/header-admin.php';

// ── POST: Update status ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_status') {
    verifyCsrf();
    $ticketId = (int)$_POST['ticket_id'];
    $status   = $_POST['status'] ?? '';
    $valid    = ['open','in_progress','resolved','closed'];
    if (in_array($status, $valid)) {
        $closedAt = in_array($status, ['resolved','closed']) ? 'NOW()' : 'NULL';
        $adminId  = (int)$user['id'];
        $pdo->prepare("UPDATE support_tickets SET status=?, admin_id=?, closed_at=" . ($closedAt === 'NULL' ? 'NULL' : 'NOW()') . ", updated_at=NOW() WHERE id=?")
            ->execute([$status, $adminId, $ticketId]);
        setFlash('success', 'Ticket status updated.');
    }
    redirect(APP_URL . '/admin/support.php?view=' . $ticketId);
}

// ── POST: Reply ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reply') {
    verifyCsrf();
    $ticketId = (int)$_POST['ticket_id'];
    $message  = trim($_POST['message'] ?? '');
    $newStatus = $_POST['new_status'] ?? '';
    if ($ticketId && $message) {
        $pdo->prepare("INSERT INTO ticket_replies (ticket_id, user_id, is_admin, message) VALUES (?,?,1,?)")
            ->execute([$ticketId, (int)$user['id'], $message]);
        $update = "UPDATE support_tickets SET updated_at=NOW(), admin_id=?";
        $params = [(int)$user['id']];
        if (in_array($newStatus, ['open','in_progress','resolved','closed'])) {
            $update .= ", status=?";
            $params[] = $newStatus;
            if (in_array($newStatus, ['resolved','closed'])) $update .= ", closed_at=NOW()";
        } else {
            $update .= ", status='in_progress'";
        }
        $update .= " WHERE id=?";
        $params[] = $ticketId;
        $pdo->prepare($update)->execute($params);
        setFlash('success', 'Reply sent.');
    }
    redirect(APP_URL . '/admin/support.php?view=' . $ticketId);
}

// ── POST: Delete ticket ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_ticket') {
    verifyCsrf();
    $ticketId = (int)$_POST['ticket_id'];
    $pdo->prepare("DELETE FROM ticket_replies WHERE ticket_id=?")->execute([$ticketId]);
    $pdo->prepare("DELETE FROM support_tickets WHERE id=?")->execute([$ticketId]);
    setFlash('success', 'Ticket deleted.');
    redirect(APP_URL . '/admin/support.php');
}

// ── View single ticket ───────────────────────────────────────────
$viewId = (int)($_GET['view'] ?? 0);
$ticket = null;
$replies = [];
if ($viewId) {
    try {
        $s = $pdo->prepare("
            SELECT t.*, u.name as user_name, u.email as user_email,
                   o.name as org_name
            FROM support_tickets t
            JOIN users u ON t.user_id = u.id
            JOIN organizations o ON t.org_id = o.id
            WHERE t.id = ?
        ");
        $s->execute([$viewId]);
        $ticket = $s->fetch();
        if ($ticket) {
            $r = $pdo->prepare("SELECT tr.*, u.name as user_name FROM ticket_replies tr JOIN users u ON tr.user_id=u.id WHERE tr.ticket_id=? ORDER BY tr.created_at ASC");
            $r->execute([$viewId]);
            $replies = $r->fetchAll();
        }
    } catch (Exception $e) {}
}

// ── Ticket list data ─────────────────────────────────────────────
$statusFilter   = $_GET['status']   ?? 'all';
$priorityFilter = $_GET['priority'] ?? 'all';
$catFilter      = $_GET['category'] ?? 'all';
$search         = trim($_GET['q']   ?? '');

$where  = ['1=1'];
$params = [];

if ($statusFilter !== 'all' && in_array($statusFilter, ['open','in_progress','resolved','closed'])) {
    $where[] = 't.status = ?';
    $params[] = $statusFilter;
}
if ($priorityFilter !== 'all' && in_array($priorityFilter, ['low','normal','high','urgent'])) {
    $where[] = 't.priority = ?';
    $params[] = $priorityFilter;
}
if ($catFilter !== 'all') {
    $where[] = 't.category = ?';
    $params[] = $catFilter;
}
if ($search) {
    $where[] = '(t.subject LIKE ? OR t.ticket_number LIKE ? OR o.name LIKE ?)';
    $s = '%' . $search . '%';
    $params = array_merge($params, [$s, $s, $s]);
}

$whereStr = implode(' AND ', $where);

try {
    $stmt = $pdo->prepare("
        SELECT t.*, u.name as user_name, o.name as org_name,
               (SELECT COUNT(*) FROM ticket_replies WHERE ticket_id=t.id) as reply_count
        FROM support_tickets t
        JOIN users u ON t.user_id = u.id
        JOIN organizations o ON t.org_id = o.id
        WHERE {$whereStr}
        ORDER BY FIELD(t.priority,'urgent','high','normal','low'),
                 FIELD(t.status,'open','in_progress','resolved','closed'),
                 t.updated_at DESC
        LIMIT 200
    ");
    $stmt->execute($params);
    $tickets = $stmt->fetchAll();
} catch (Exception $e) { $tickets = []; }

// Summary counts
$counts = [];
try {
    $c = $pdo->query("SELECT status, COUNT(*) as cnt FROM support_tickets GROUP BY status");
    foreach ($c->fetchAll() as $row) $counts[$row['status']] = $row['cnt'];
} catch (Exception $e) {}
$totalOpen     = ($counts['open'] ?? 0) + ($counts['in_progress'] ?? 0);
$totalResolved = ($counts['resolved'] ?? 0) + ($counts['closed'] ?? 0);

$priorityColors = ['low'=>'secondary','normal'=>'info','high'=>'warning','urgent'=>'danger'];
$statusColors   = ['open'=>'primary','in_progress'=>'warning','resolved'=>'success','closed'=>'secondary'];
$categoryIcons  = ['billing'=>'fa-file-invoice-dollar','technical'=>'fa-wrench','general'=>'fa-comment','feature_request'=>'fa-lightbulb','module_request'=>'fa-puzzle-piece'];
?>

<?php if ($ticket): ?>
<!-- ══════════════════ TICKET THREAD (ADMIN) ══════════════════ -->
<div class="page-header d-flex align-items-center justify-content-between mb-3">
  <div>
    <h4><i class="fas fa-ticket-alt me-2 text-green"></i><?= e($ticket['ticket_number']) ?></h4>
    <p class="text-muted mb-0"><?= e($ticket['org_name']) ?> — <?= e($ticket['user_name']) ?> &lt;<?= e($ticket['user_email']) ?>&gt;</p>
  </div>
  <a href="<?= APP_URL ?>/admin/support.php" class="btn btn-outline-secondary btn-sm">
    <i class="fas fa-arrow-left me-1"></i>All Tickets
  </a>
</div>

<div class="row g-4">
  <div class="col-lg-8">
    <!-- Original message -->
    <div class="card mb-3">
      <div class="card-body">
        <div class="d-flex align-items-start justify-content-between gap-3 mb-3">
          <h5 class="fw-700 mb-0"><?= e($ticket['subject']) ?></h5>
          <div class="d-flex gap-1 flex-wrap">
            <span class="badge bg-<?= $statusColors[$ticket['status']] ?? 'secondary' ?>">
              <?= ucfirst(str_replace('_',' ',$ticket['status'])) ?>
            </span>
            <span class="badge bg-<?= $priorityColors[$ticket['priority']] ?? 'secondary' ?>">
              <?= ucfirst($ticket['priority']) ?>
            </span>
          </div>
        </div>
        <div class="p-3 rounded" style="background:#f8fafc;border-left:3px solid var(--navy)">
          <div class="d-flex align-items-center gap-2 mb-2">
            <div class="avatar-sm" style="background:var(--navy);width:28px;height:28px;font-size:.65rem">
              <?= strtoupper(substr($ticket['user_name'],0,2)) ?>
            </div>
            <span class="fw-600 small"><?= e($ticket['user_name']) ?></span>
            <span class="badge bg-light text-dark border small"><?= e($ticket['org_name']) ?></span>
            <span class="text-muted small ms-auto"><?= formatDateTime($ticket['created_at']) ?></span>
          </div>
          <div class="small" style="white-space:pre-wrap"><?= e($ticket['message']) ?></div>
        </div>
      </div>
    </div>

    <!-- Replies -->
    <?php foreach ($replies as $r): ?>
    <div class="card mb-2" style="<?= $r['is_admin'] ? 'border-left:3px solid #1A8A4E' : 'border-left:3px solid #94a3b8' ?>">
      <div class="card-body py-3">
        <div class="d-flex align-items-center gap-2 mb-2">
          <div class="avatar-sm" style="background:<?= $r['is_admin'] ? '#1A8A4E' : 'var(--navy)' ?>;width:28px;height:28px;font-size:.65rem">
            <?= strtoupper(substr($r['user_name'],0,2)) ?>
          </div>
          <span class="fw-600 small"><?= e($r['user_name']) ?></span>
          <?php if ($r['is_admin']): ?>
          <span class="badge bg-success" style="font-size:.6rem">Support Team</span>
          <?php endif; ?>
          <span class="text-muted small ms-auto"><?= formatDateTime($r['created_at']) ?></span>
        </div>
        <div class="small" style="white-space:pre-wrap"><?= e($r['message']) ?></div>
      </div>
    </div>
    <?php endforeach; ?>

    <!-- Reply form -->
    <div class="card">
      <div class="card-header fw-600"><i class="fas fa-reply me-2 text-green"></i>Reply to Ticket</div>
      <div class="card-body">
        <form method="POST">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="reply">
          <input type="hidden" name="ticket_id" value="<?= $ticket['id'] ?>">
          <div class="mb-3">
            <textarea name="message" class="form-control" rows="5"
                      placeholder="Write your response..." required></textarea>
          </div>
          <div class="d-flex align-items-center gap-3 flex-wrap">
            <div class="d-flex align-items-center gap-2">
              <label class="form-label mb-0 small fw-600">After reply, set status to:</label>
              <select name="new_status" class="form-select form-select-sm" style="width:auto">
                <option value="in_progress" <?= $ticket['status']==='in_progress'?'selected':'' ?>>In Progress</option>
                <option value="open">Open</option>
                <option value="resolved" <?= $ticket['status']==='resolved'?'selected':'' ?>>Resolved</option>
                <option value="closed">Closed</option>
              </select>
            </div>
            <button type="submit" class="btn btn-primary ms-auto">
              <i class="fas fa-paper-plane me-2"></i>Send Reply
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Ticket Sidebar -->
  <div class="col-lg-4">
    <div class="card mb-3">
      <div class="card-header fw-600">Ticket Info</div>
      <div class="card-body">
        <table class="table table-sm table-borderless mb-0 small">
          <tr><td class="text-muted">Number</td><td class="fw-bold"><?= e($ticket['ticket_number']) ?></td></tr>
          <tr><td class="text-muted">Organization</td><td class="fw-bold"><?= e($ticket['org_name']) ?></td></tr>
          <tr><td class="text-muted">Submitted by</td><td><?= e($ticket['user_name']) ?></td></tr>
          <tr><td class="text-muted">Email</td><td><a href="mailto:<?= e($ticket['user_email']) ?>"><?= e($ticket['user_email']) ?></a></td></tr>
          <tr><td class="text-muted">Category</td>
              <td><i class="fas <?= $categoryIcons[$ticket['category']] ?? 'fa-tag' ?> me-1"></i><?= ucfirst(str_replace('_',' ',$ticket['category'])) ?></td></tr>
          <tr><td class="text-muted">Priority</td>
              <td><span class="badge bg-<?= $priorityColors[$ticket['priority']] ?>"><?= ucfirst($ticket['priority']) ?></span></td></tr>
          <tr><td class="text-muted">Replies</td><td><?= count($replies) ?></td></tr>
          <tr><td class="text-muted">Opened</td><td><?= formatDate($ticket['created_at']) ?></td></tr>
          <?php if ($ticket['closed_at']): ?>
          <tr><td class="text-muted">Closed</td><td><?= formatDate($ticket['closed_at']) ?></td></tr>
          <?php endif; ?>
        </table>
      </div>
    </div>

    <!-- Quick status change -->
    <div class="card mb-3">
      <div class="card-header fw-600">Change Status</div>
      <div class="card-body">
        <form method="POST" class="d-flex gap-2">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="update_status">
          <input type="hidden" name="ticket_id" value="<?= $ticket['id'] ?>">
          <select name="status" class="form-select form-select-sm">
            <?php foreach (['open','in_progress','resolved','closed'] as $st): ?>
            <option value="<?= $st ?>" <?= $ticket['status']===$st?'selected':'' ?>><?= ucfirst(str_replace('_',' ',$st)) ?></option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="btn btn-sm btn-outline-primary">Update</button>
        </form>
      </div>
    </div>

    <div class="card border-danger">
      <div class="card-body">
        <form method="POST" onsubmit="return confirm('Permanently delete this ticket and all replies?')">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="delete_ticket">
          <input type="hidden" name="ticket_id" value="<?= $ticket['id'] ?>">
          <button type="submit" class="btn btn-outline-danger btn-sm w-100">
            <i class="fas fa-trash me-1"></i>Delete Ticket
          </button>
        </form>
      </div>
    </div>

    <div class="mt-3">
      <a href="<?= APP_URL ?>/admin/clients.php?view=<?= $ticket['org_id'] ?>" class="btn btn-sm btn-outline-secondary w-100">
        <i class="fas fa-building me-1"></i>View Client Profile
      </a>
    </div>
  </div>
</div>

<?php else: ?>
<!-- ══════════════════ TICKET LIST (ADMIN) ══════════════════ -->
<div class="page-header d-flex align-items-center justify-content-between mb-3">
  <h4><i class="fas fa-headset me-2 text-green"></i>Support Tickets</h4>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="stat-card navy">
      <div class="stat-icon navy-bg"><i class="fas fa-inbox"></i></div>
      <div><div class="stat-value"><?= array_sum($counts) ?></div><div class="stat-label">Total Tickets</div></div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card warning">
      <div class="stat-icon warning-bg"><i class="fas fa-clock"></i></div>
      <div><div class="stat-value"><?= $totalOpen ?></div><div class="stat-label">Open / In Progress</div></div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card green">
      <div class="stat-icon green-bg"><i class="fas fa-check-circle"></i></div>
      <div><div class="stat-value"><?= $totalResolved ?></div><div class="stat-label">Resolved / Closed</div></div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <?php
    $urgentCount = 0;
    try { $urgentCount = (int)$pdo->query("SELECT COUNT(*) FROM support_tickets WHERE priority='urgent' AND status NOT IN ('resolved','closed')")->fetchColumn(); } catch(Exception $e){}
    ?>
    <div class="stat-card <?= $urgentCount > 0 ? 'danger' : '' ?>">
      <div class="stat-icon" style="background:<?= $urgentCount > 0 ? '#fef2f2' : '#f1f5f9' ?>">
        <i class="fas fa-exclamation-circle" style="color:<?= $urgentCount > 0 ? '#ef4444' : '#94a3b8' ?>"></i>
      </div>
      <div><div class="stat-value"><?= $urgentCount ?></div><div class="stat-label">Urgent Open</div></div>
    </div>
  </div>
</div>

<!-- Filters -->
<div class="card mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-4 col-md-3">
        <input type="text" name="q" class="form-control form-control-sm" placeholder="Search tickets or org..."
               value="<?= e($search) ?>">
      </div>
      <div class="col-sm-auto">
        <select name="status" class="form-select form-select-sm">
          <option value="all" <?= $statusFilter==='all'?'selected':'' ?>>All Statuses</option>
          <option value="open" <?= $statusFilter==='open'?'selected':'' ?>>Open</option>
          <option value="in_progress" <?= $statusFilter==='in_progress'?'selected':'' ?>>In Progress</option>
          <option value="resolved" <?= $statusFilter==='resolved'?'selected':'' ?>>Resolved</option>
          <option value="closed" <?= $statusFilter==='closed'?'selected':'' ?>>Closed</option>
        </select>
      </div>
      <div class="col-sm-auto">
        <select name="priority" class="form-select form-select-sm">
          <option value="all" <?= $priorityFilter==='all'?'selected':'' ?>>All Priorities</option>
          <option value="urgent" <?= $priorityFilter==='urgent'?'selected':'' ?>>Urgent</option>
          <option value="high" <?= $priorityFilter==='high'?'selected':'' ?>>High</option>
          <option value="normal" <?= $priorityFilter==='normal'?'selected':'' ?>>Normal</option>
          <option value="low" <?= $priorityFilter==='low'?'selected':'' ?>>Low</option>
        </select>
      </div>
      <div class="col-sm-auto">
        <select name="category" class="form-select form-select-sm">
          <option value="all" <?= $catFilter==='all'?'selected':'' ?>>All Categories</option>
          <option value="billing">Billing</option>
          <option value="technical">Technical</option>
          <option value="general">General</option>
          <option value="module_request">Module Request</option>
          <option value="feature_request">Feature Request</option>
        </select>
      </div>
      <div class="col-sm-auto d-flex gap-1">
        <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-search me-1"></i>Filter</button>
        <a href="<?= APP_URL ?>/admin/support.php" class="btn btn-sm btn-outline-secondary">Clear</a>
      </div>
    </form>
  </div>
</div>

<!-- Tickets table -->
<div class="card">
  <div class="card-body p-0">
    <?php if (empty($tickets)): ?>
    <div class="text-center py-5 text-muted">
      <i class="fas fa-headset fa-3x mb-3 d-block"></i>
      <h5>No tickets found</h5>
      <p class="small">Tickets submitted by clients will appear here.</p>
    </div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Ticket</th>
            <th>Organization</th>
            <th>Subject</th>
            <th>Category</th>
            <th>Priority</th>
            <th>Status</th>
            <th>Replies</th>
            <th>Updated</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($tickets as $tk): ?>
          <tr class="<?= in_array($tk['status'],['open','in_progress']) && $tk['priority']==='urgent' ? 'table-danger-subtle' : '' ?>">
            <td class="fw-bold small"><?= e($tk['ticket_number']) ?></td>
            <td>
              <a href="<?= APP_URL ?>/admin/clients.php?view=<?= $tk['org_id'] ?>" class="text-decoration-none small">
                <?= e($tk['org_name']) ?>
              </a>
            </td>
            <td>
              <a href="?view=<?= $tk['id'] ?>" class="fw-600 text-dark text-decoration-none">
                <?= e($tk['subject']) ?>
              </a>
              <div class="text-muted" style="font-size:.72rem"><?= e($tk['user_name']) ?></div>
            </td>
            <td class="small">
              <i class="fas <?= $categoryIcons[$tk['category']] ?? 'fa-tag' ?> text-muted me-1"></i>
              <?= ucfirst(str_replace('_',' ',$tk['category'])) ?>
            </td>
            <td><span class="badge bg-<?= $priorityColors[$tk['priority']] ?? 'secondary' ?>"><?= ucfirst($tk['priority']) ?></span></td>
            <td><span class="badge bg-<?= $statusColors[$tk['status']] ?? 'secondary' ?>"><?= ucfirst(str_replace('_',' ',$tk['status'])) ?></span></td>
            <td class="text-center">
              <span class="badge bg-light text-dark border"><?= $tk['reply_count'] ?></span>
            </td>
            <td class="small text-muted"><?= timeAgo($tk['updated_at']) ?></td>
            <td>
              <a href="?view=<?= $tk['id'] ?>" class="btn btn-xs btn-primary btn-sm">
                <i class="fas fa-reply me-1"></i>Respond
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
