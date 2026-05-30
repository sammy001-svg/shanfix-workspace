<?php
// ── Bootstrap (no HTML yet — POST handlers must redirect before any output) ──
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
sendSecurityHeaders();
requireSuperAdmin();
$user      = currentUser();
$pageTitle = 'Support Tickets';

// ── Attachment helpers ────────────────────────────────────────────
function adminAttachmentChip(array $att): string {
    $icons = ['pdf'=>'fa-file-pdf','doc'=>'fa-file-word','docx'=>'fa-file-word',
              'xls'=>'fa-file-excel','xlsx'=>'fa-file-excel','zip'=>'fa-file-archive',
              'txt'=>'fa-file-alt','jpg'=>'fa-file-image','jpeg'=>'fa-file-image',
              'png'=>'fa-file-image','gif'=>'fa-file-image','csv'=>'fa-file-csv'];
    $ext  = strtolower(pathinfo($att['original_name'], PATHINFO_EXTENSION));
    $icon = $icons[$ext] ?? 'fa-file';
    $size = $att['file_size'] > 1048576
          ? round($att['file_size']/1048576, 1) . ' MB'
          : round($att['file_size']/1024, 1) . ' KB';
    $url  = APP_URL . '/uploads/tickets/' . rawurlencode($att['filename']);
    return '<a href="' . $url . '" target="_blank" download="' . htmlspecialchars($att['original_name'], ENT_QUOTES) . '"
              class="d-inline-flex align-items-center gap-1 px-2 py-1 rounded text-decoration-none"
              style="background:#f1f5f9;border:1px solid #e2e8f0;font-size:.75rem;color:#0B2D4E;max-width:220px">
             <i class="fas ' . $icon . ' text-muted flex-shrink-0"></i>
             <span class="text-truncate">' . htmlspecialchars($att['original_name'], ENT_QUOTES) . '</span>
             <span class="text-muted flex-shrink-0" style="font-size:.65rem">(' . $size . ')</span>
           </a>';
}

function saveAdminTicketFiles(PDO $pdo, int $ticketId, ?int $replyId, int $orgId, int $userId): void {
    if (empty($_FILES['attachments']['name'][0])) return;
    $uploadDir = __DIR__ . '/../uploads/tickets/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    $allowed = ['jpg','jpeg','png','gif','pdf','doc','docx','txt','zip','xlsx','csv'];
    $maxSize = 5 * 1024 * 1024;
    foreach ($_FILES['attachments']['name'] as $i => $origName) {
        if (!$origName || $_FILES['attachments']['error'][$i] !== UPLOAD_ERR_OK) continue;
        if ($_FILES['attachments']['size'][$i] > $maxSize) continue;
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed)) continue;
        $safe     = preg_replace('/[^a-z0-9._-]/', '_', strtolower(basename($origName)));
        $filename = $ticketId . '_' . ($replyId ?? 0) . '_' . uniqid() . '_' . $safe;
        if (move_uploaded_file($_FILES['attachments']['tmp_name'][$i], $uploadDir . $filename)) {
            $pdo->prepare("INSERT INTO ticket_attachments (ticket_id, reply_id, org_id, uploaded_by, filename, original_name, file_size, mime_type)
                VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$ticketId, $replyId, $orgId, $userId, $filename,
                           $origName, (int)$_FILES['attachments']['size'][$i],
                           $_FILES['attachments']['type'][$i] ?? '']);
        }
    }
}

// ── POST: Update status ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_status') {
    verifyCsrf();
    $ticketId = (int)$_POST['ticket_id'];
    $status   = $_POST['status'] ?? '';
    $valid    = ['open','in_progress','resolved','closed'];
    if (in_array($status, $valid)) {
        try {
            $adminId  = (int)$user['id'];
            $pdo->prepare("UPDATE support_tickets SET status=?, admin_id=?, closed_at=" . (in_array($status, ['resolved','closed']) ? 'NOW()' : 'NULL') . ", updated_at=NOW() WHERE id=?")
                ->execute([$status, $adminId, $ticketId]);
            setFlash('success', 'Ticket status updated.');
        } catch (Throwable $e) {
            error_log('[support status] ' . $e->getMessage());
            setFlash('danger', 'Status update failed.');
        }
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
        $isInternal = isset($_POST['is_internal']) ? 1 : 0;
        try {
            $pdo->prepare("INSERT INTO ticket_replies (ticket_id, user_id, is_admin, is_internal, message) VALUES (?,?,1,?,?)")
                ->execute([$ticketId, (int)$user['id'], $isInternal, $message]);
        } catch (Throwable $e) {
            error_log('[admin reply insert] ' . $e->getMessage());
            setFlash('danger', 'Reply failed — support tables may need migration. Check error log.');
            redirect(APP_URL . '/admin/support.php?view=' . $ticketId);
        }
        $replyId = (int)$pdo->lastInsertId();
        try {
            $tkOrg = $pdo->prepare("SELECT org_id FROM support_tickets WHERE id=?");
            $tkOrg->execute([$ticketId]);
            $tkOrgId = (int)($tkOrg->fetchColumn() ?: 0);
            saveAdminTicketFiles($pdo, $ticketId, $replyId, $tkOrgId, (int)$user['id']);
        } catch (Throwable $e) {}
        // Only update ticket status/timestamp for non-internal replies
        if (!$isInternal) {
            try {
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
            } catch (Throwable $e) {
                error_log('[admin reply update] ' . $e->getMessage());
            }
        }
        setFlash('success', $isInternal ? 'Internal note saved.' : 'Reply sent.');

        // Notify ticket creator when admin posts a visible reply
        if (!$isInternal) {
            try {
                require_once __DIR__ . '/../includes/notifications.php';
                require_once __DIR__ . '/../includes/mailer.php';
                $creatorStmt = $pdo->prepare("
                    SELECT u.id, u.name, u.email, t.ticket_number, t.subject, t.org_id
                    FROM support_tickets t
                    JOIN users u ON t.user_id = u.id
                    WHERE t.id = ?
                ");
                $creatorStmt->execute([$ticketId]);
                $creator = $creatorStmt->fetch();
                if ($creator) {
                    createNotification(
                        (int)$creator['org_id'],
                        (int)$creator['id'],
                        'Reply on ticket #' . $creator['ticket_number'],
                        'Support has responded to: "' . $creator['subject'] . '".',
                        'info',
                        APP_URL . '/client/support.php?view=' . $ticketId
                    );
                    _sendNotifEmail(
                        $creator['email'],
                        $creator['name'],
                        'New reply on ticket #' . $creator['ticket_number'],
                        'Support has responded to your ticket: "' . $creator['subject'] . '". Click below to view the reply.',
                        'info',
                        APP_URL . '/client/support.php?view=' . $ticketId
                    );
                }
            } catch (Throwable $e) {
                error_log('[support reply notify] ' . $e->getMessage());
            }
        }
    }
    redirect(APP_URL . '/admin/support.php?view=' . $ticketId);
}

// ── POST: Delete ticket ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_ticket') {
    verifyCsrf();
    $ticketId = (int)$_POST['ticket_id'];
    try {
        $pdo->prepare("DELETE FROM ticket_replies WHERE ticket_id=?")->execute([$ticketId]);
        $pdo->prepare("DELETE FROM support_tickets WHERE id=?")->execute([$ticketId]);
        setFlash('success', 'Ticket deleted.');
    } catch (Exception $e) {
        error_log('[delete ticket] ' . $e->getMessage());
        setFlash('danger', 'Delete failed.');
    }
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
            $r = $pdo->prepare("SELECT tr.*, u.name as user_name, COALESCE(tr.is_internal,0) as is_internal
                                FROM ticket_replies tr JOIN users u ON tr.user_id=u.id
                                WHERE tr.ticket_id=? ORDER BY tr.created_at ASC");
            $r->execute([$viewId]);
            $replies = $r->fetchAll();
            // Attachments keyed by 'ticket' or 'r{reply_id}'
            $attachments = [];
            try {
                $a = $pdo->prepare("SELECT * FROM ticket_attachments WHERE ticket_id=? ORDER BY created_at");
                $a->execute([$viewId]);
                foreach ($a->fetchAll() as $att) {
                    $key = $att['reply_id'] ? 'r' . $att['reply_id'] : 'ticket';
                    $attachments[$key][] = $att;
                }
            } catch (Exception $e) {}
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
               (SELECT COUNT(*) FROM ticket_replies WHERE ticket_id=t.id) as reply_count,
               (SELECT COALESCE(is_admin,1) FROM ticket_replies WHERE ticket_id=t.id ORDER BY created_at DESC LIMIT 1) as last_reply_is_admin
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

// ── HTML output starts here ───────────────────────────────────────
require_once __DIR__ . '/../includes/header-admin.php';
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
          <?php if (!empty($attachments['ticket'])): ?>
          <div class="mt-2 pt-2 border-top d-flex flex-wrap gap-2">
            <?php foreach ($attachments['ticket'] as $att): echo adminAttachmentChip($att); endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Replies -->
    <?php foreach ($replies as $r):
      $isInternal = (bool)($r['is_internal'] ?? 0);
      $borderColor = $isInternal ? '#f59e0b' : ($r['is_admin'] ? '#1A8A4E' : '#94a3b8');
      $bgColor     = $isInternal ? '#fffbeb' : 'white';
    ?>
    <div class="card mb-2" style="border-left:3px solid <?= $borderColor ?>;background:<?= $bgColor ?>">
      <div class="card-body py-3">
        <div class="d-flex align-items-center gap-2 mb-2">
          <div class="avatar-sm" style="background:<?= $isInternal ? '#f59e0b' : ($r['is_admin'] ? '#1A8A4E' : 'var(--navy)') ?>;width:28px;height:28px;font-size:.65rem">
            <?= strtoupper(substr($r['user_name'],0,2)) ?>
          </div>
          <span class="fw-600 small"><?= e($r['user_name']) ?></span>
          <?php if ($isInternal): ?>
          <span class="badge bg-warning text-dark" style="font-size:.6rem"><i class="fas fa-lock me-1"></i>Internal Note</span>
          <?php elseif ($r['is_admin']): ?>
          <span class="badge bg-success" style="font-size:.6rem">Support Team</span>
          <?php endif; ?>
          <span class="text-muted small ms-auto"><?= formatDateTime($r['created_at']) ?></span>
        </div>
        <div class="small" style="white-space:pre-wrap"><?= e($r['message']) ?></div>
        <?php if (!empty($attachments['r' . $r['id']])): ?>
        <div class="mt-2 pt-2 border-top d-flex flex-wrap gap-2">
          <?php foreach ($attachments['r' . $r['id']] as $att): echo adminAttachmentChip($att); endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>

    <!-- Reply form -->
    <div class="card">
      <div class="card-header fw-600"><i class="fas fa-reply me-2 text-green"></i>Reply to Ticket</div>
      <div class="card-body">
        <form method="POST" enctype="multipart/form-data">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="reply">
          <input type="hidden" name="ticket_id" value="<?= $ticket['id'] ?>">
          <div class="mb-3">
            <textarea name="message" id="replyMessage" class="form-control" rows="5"
                      placeholder="Write your response..." required></textarea>
          </div>
          <div class="mb-3">
            <label class="form-label small fw-600">Attachments <span class="text-muted fw-400">(optional · max 5 MB each)</span></label>
            <input type="file" name="attachments[]" class="form-control form-control-sm" multiple
                   accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.txt,.zip,.xlsx,.csv">
          </div>
          <div class="d-flex align-items-center gap-3 flex-wrap">
            <div class="form-check form-switch mb-0">
              <input class="form-check-input" type="checkbox" name="is_internal" id="isInternal"
                     onchange="document.getElementById('replySubmitBtn').className=this.checked?'btn btn-warning ms-auto':'btn btn-primary ms-auto'">
              <label class="form-check-label small fw-600" for="isInternal">
                <i class="fas fa-lock me-1"></i>Internal note <span class="text-muted fw-400">(hidden from client)</span>
              </label>
            </div>
            <div class="d-flex align-items-center gap-2 ms-auto">
              <label class="form-label mb-0 small fw-600">Status after reply:</label>
              <select name="new_status" id="newStatusSel" class="form-select form-select-sm" style="width:auto">
                <option value="in_progress" <?= $ticket['status']==='in_progress'?'selected':'' ?>>In Progress</option>
                <option value="open">Open</option>
                <option value="resolved" <?= $ticket['status']==='resolved'?'selected':'' ?>>Resolved</option>
                <option value="closed">Closed</option>
              </select>
            </div>
            <button type="submit" id="replySubmitBtn" class="btn btn-primary">
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
              <div class="d-flex align-items-center gap-2 mt-1 flex-wrap">
                <span class="text-muted" style="font-size:.72rem"><?= e($tk['user_name']) ?></span>
                <?php if (($tk['reply_count'] ?? 0) > 0 && ($tk['last_reply_is_admin'] ?? 1) == 0
                          && !in_array($tk['status'], ['resolved','closed'])): ?>
                <span class="badge bg-warning text-dark" style="font-size:.6rem">
                  <i class="fas fa-comment-dots me-1"></i>Client replied
                </span>
                <?php endif; ?>
              </div>
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
