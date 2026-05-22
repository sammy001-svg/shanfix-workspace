<?php
$pageTitle = 'Support';
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
requireClientAdmin();

$user  = currentUser();
$orgId = (int)$user['org_id'];
$uid   = (int)$user['id'];

// ── POST: Create ticket ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_ticket') {
    verifyCsrf();
    $subject  = sanitize($_POST['subject']  ?? '');
    $category = $_POST['category'] ?? 'general';
    $priority = $_POST['priority'] ?? 'normal';
    $message  = trim($_POST['message'] ?? '');

    $validCats  = ['billing','technical','general','feature_request','module_request'];
    $validPrios = ['low','normal','high','urgent'];
    if (!in_array($category, $validCats))  $category = 'general';
    if (!in_array($priority, $validPrios)) $priority = 'normal';

    if ($subject && $message) {
        $ticketNo = 'TKT-' . strtoupper(substr(md5(uniqid($orgId, true)), 0, 7));
        $pdo->prepare("INSERT INTO support_tickets (org_id, user_id, ticket_number, subject, category, priority, message)
            VALUES (?,?,?,?,?,?,?)")->execute([$orgId, $uid, $ticketNo, $subject, $category, $priority, $message]);
        $newId = (int)$pdo->lastInsertId();
        logActivity('create', 'support', "Ticket {$ticketNo}: {$subject}");
        setFlash('success', "Ticket <strong>{$ticketNo}</strong> submitted. We'll respond within 24 hours.");
        redirect(APP_URL . '/client/support.php?view=' . $newId);
    }
    setFlash('danger', 'Subject and message are required.');
    redirect(APP_URL . '/client/support.php');
}

// ── POST: Reply to ticket ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reply') {
    verifyCsrf();
    $ticketId = (int)($_POST['ticket_id'] ?? 0);
    $message  = trim($_POST['message'] ?? '');

    $tkRow = $pdo->prepare("SELECT * FROM support_tickets WHERE id=? AND org_id=?");
    $tkRow->execute([$ticketId, $orgId]);
    $tk = $tkRow->fetch();

    if ($tk && $message && !in_array($tk['status'], ['resolved','closed'])) {
        $pdo->prepare("INSERT INTO ticket_replies (ticket_id, user_id, is_admin, message) VALUES (?,?,0,?)")
            ->execute([$ticketId, $uid, $message]);
        $pdo->prepare("UPDATE support_tickets SET status='open', updated_at=NOW() WHERE id=?")->execute([$ticketId]);
        setFlash('success', 'Reply sent.');
    }
    redirect(APP_URL . '/client/support.php?view=' . $ticketId);
}

// ── POST: Close ticket ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'close_ticket') {
    verifyCsrf();
    $ticketId = (int)($_POST['ticket_id'] ?? 0);
    $pdo->prepare("UPDATE support_tickets SET status='closed', closed_at=NOW() WHERE id=? AND org_id=?")
        ->execute([$ticketId, $orgId]);
    setFlash('info', 'Ticket closed. Thank you for using our support.');
    redirect(APP_URL . '/client/support.php');
}

// ── View single ticket ───────────────────────────────────────────
$viewId = (int)($_GET['view'] ?? 0);
$ticket = null;
$replies = [];
if ($viewId) {
    $s = $pdo->prepare("SELECT t.*, u.name as user_name FROM support_tickets t JOIN users u ON t.user_id=u.id WHERE t.id=? AND t.org_id=?");
    $s->execute([$viewId, $orgId]);
    $ticket = $s->fetch();
    if ($ticket) {
        $r = $pdo->prepare("SELECT tr.*, u.name as user_name FROM ticket_replies tr JOIN users u ON tr.user_id=u.id WHERE tr.ticket_id=? ORDER BY tr.created_at ASC");
        $r->execute([$viewId]);
        $replies = $r->fetchAll();
    }
}

// ── Ticket list ──────────────────────────────────────────────────
$statusFilter = $_GET['status'] ?? 'all';
$validStatuses = ['all','open','in_progress','resolved','closed'];
if (!in_array($statusFilter, $validStatuses)) $statusFilter = 'all';

$whereClause = 'org_id = ?';
$params = [$orgId];
if ($statusFilter !== 'all') {
    $whereClause .= ' AND status = ?';
    $params[] = $statusFilter;
}
try {
    $s = $pdo->prepare("SELECT * FROM support_tickets WHERE {$whereClause} ORDER BY updated_at DESC");
    $s->execute($params);
    $tickets = $s->fetchAll();
} catch (Exception $e) { $tickets = []; }

// Counts per status
$counts = [];
try {
    $c = $pdo->prepare("SELECT status, COUNT(*) as cnt FROM support_tickets WHERE org_id=? GROUP BY status");
    $c->execute([$orgId]);
    foreach ($c->fetchAll() as $row) $counts[$row['status']] = $row['cnt'];
} catch (Exception $e) {}
$totalOpen = ($counts['open'] ?? 0) + ($counts['in_progress'] ?? 0);

require_once __DIR__ . '/../includes/header-client.php';

$priorityColors = ['low'=>'secondary','normal'=>'info','high'=>'warning','urgent'=>'danger'];
$statusColors   = ['open'=>'primary','in_progress'=>'warning','resolved'=>'success','closed'=>'secondary'];
$categoryIcons  = ['billing'=>'fa-file-invoice-dollar','technical'=>'fa-wrench','general'=>'fa-comment','feature_request'=>'fa-lightbulb','module_request'=>'fa-puzzle-piece'];
?>

<div class="page-header d-flex align-items-center justify-content-between mb-3">
  <div>
    <h4><i class="fas fa-headset me-2 text-green"></i>Support Center</h4>
    <p class="text-muted mb-0">Submit and track support requests</p>
  </div>
  <?php if (!$ticket): ?>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newTicketModal">
    <i class="fas fa-plus me-2"></i>New Ticket
  </button>
  <?php else: ?>
  <a href="<?= APP_URL ?>/client/support.php" class="btn btn-outline-secondary">
    <i class="fas fa-arrow-left me-2"></i>All Tickets
  </a>
  <?php endif; ?>
</div>

<?php if ($ticket): ?>
<!-- ══════════════════ TICKET THREAD ══════════════════ -->
<div class="row g-4">
  <div class="col-lg-8">
    <!-- Thread header -->
    <div class="card mb-3">
      <div class="card-body">
        <div class="d-flex align-items-start justify-content-between gap-3 mb-3">
          <div>
            <h5 class="fw-700 mb-1"><?= e($ticket['subject']) ?></h5>
            <div class="d-flex flex-wrap gap-2">
              <span class="badge bg-<?= $statusColors[$ticket['status']] ?? 'secondary' ?>">
                <?= ucfirst(str_replace('_', ' ', $ticket['status'])) ?>
              </span>
              <span class="badge bg-<?= $priorityColors[$ticket['priority']] ?? 'secondary' ?>">
                <?= ucfirst($ticket['priority']) ?> Priority
              </span>
              <span class="badge bg-light text-dark border">
                <i class="fas <?= $categoryIcons[$ticket['category']] ?? 'fa-tag' ?> me-1"></i><?= ucfirst(str_replace('_',' ',$ticket['category'])) ?>
              </span>
            </div>
          </div>
          <div class="text-muted small text-end">
            <div class="fw-600"><?= e($ticket['ticket_number']) ?></div>
            <div><?= formatDate($ticket['created_at']) ?></div>
          </div>
        </div>

        <!-- Original message -->
        <div class="p-3 rounded" style="background:#f8fafc;border-left:3px solid var(--navy)">
          <div class="d-flex align-items-center gap-2 mb-2">
            <div class="avatar-sm" style="background:var(--navy);width:28px;height:28px;font-size:.65rem">
              <?= strtoupper(substr($ticket['user_name'],0,2)) ?>
            </div>
            <span class="fw-600 small"><?= e($ticket['user_name']) ?></span>
            <span class="text-muted small ms-auto"><?= formatDateTime($ticket['created_at']) ?></span>
          </div>
          <div class="small" style="white-space:pre-wrap"><?= e($ticket['message']) ?></div>
        </div>
      </div>
    </div>

    <!-- Replies -->
    <?php foreach ($replies as $r): ?>
    <div class="card mb-2" style="<?= $r['is_admin'] ? 'border-left:3px solid #1A8A4E' : '' ?>">
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
    <?php if (!in_array($ticket['status'], ['resolved','closed'])): ?>
    <div class="card">
      <div class="card-header fw-600"><i class="fas fa-reply me-2 text-green"></i>Add Reply</div>
      <div class="card-body">
        <form method="POST">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="reply">
          <input type="hidden" name="ticket_id" value="<?= $ticket['id'] ?>">
          <div class="mb-3">
            <textarea name="message" class="form-control" rows="4"
                      placeholder="Describe your question or provide additional details..." required></textarea>
          </div>
          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane me-2"></i>Send Reply</button>
          </div>
        </form>
      </div>
    </div>
    <?php else: ?>
    <div class="alert alert-secondary text-center">
      <i class="fas fa-lock me-2"></i>This ticket is <?= $ticket['status'] ?>.
      <a href="<?= APP_URL ?>/client/support.php" class="btn btn-sm btn-outline-primary ms-2">Open a New Ticket</a>
    </div>
    <?php endif; ?>
  </div>

  <!-- Sidebar -->
  <div class="col-lg-4">
    <div class="card mb-3">
      <div class="card-header fw-600">Ticket Details</div>
      <div class="card-body">
        <table class="table table-sm table-borderless mb-0 small">
          <tr><td class="text-muted">Number</td><td class="fw-bold"><?= e($ticket['ticket_number']) ?></td></tr>
          <tr><td class="text-muted">Status</td><td><?= statusBadge($ticket['status']) ?></td></tr>
          <tr><td class="text-muted">Priority</td>
              <td><span class="badge bg-<?= $priorityColors[$ticket['priority']] ?>"><?= ucfirst($ticket['priority']) ?></span></td></tr>
          <tr><td class="text-muted">Category</td>
              <td><?= ucfirst(str_replace('_',' ',$ticket['category'])) ?></td></tr>
          <tr><td class="text-muted">Opened</td><td><?= formatDate($ticket['created_at']) ?></td></tr>
          <tr><td class="text-muted">Replies</td><td><?= count($replies) ?></td></tr>
          <?php if ($ticket['closed_at']): ?>
          <tr><td class="text-muted">Closed</td><td><?= formatDate($ticket['closed_at']) ?></td></tr>
          <?php endif; ?>
        </table>
      </div>
    </div>

    <?php if (!in_array($ticket['status'], ['closed'])): ?>
    <div class="card border-warning">
      <div class="card-body">
        <p class="small text-muted mb-2">Is your issue resolved? Close this ticket to let us know.</p>
        <form method="POST">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="close_ticket">
          <input type="hidden" name="ticket_id" value="<?= $ticket['id'] ?>">
          <button type="submit" class="btn btn-outline-secondary btn-sm w-100">
            <i class="fas fa-check me-1"></i>Mark as Resolved & Close
          </button>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <div class="card mt-3">
      <div class="card-body text-center py-3">
        <i class="fas fa-clock text-muted mb-1 d-block fa-lg"></i>
        <div class="small text-muted">Typical response time</div>
        <div class="fw-700">Within 24 hours</div>
        <div class="small text-muted mt-1">Mon–Fri, 8am–6pm EAT</div>
      </div>
    </div>
  </div>
</div>

<?php else: ?>
<!-- ══════════════════ TICKET LIST ══════════════════ -->

<?php if ($totalOpen > 0): ?>
<div class="alert alert-info d-flex align-items-center gap-2 mb-3">
  <i class="fas fa-info-circle flex-shrink-0"></i>
  You have <strong><?= $totalOpen ?> open ticket<?= $totalOpen > 1 ? 's' : '' ?></strong>. We aim to respond within 24 hours.
</div>
<?php endif; ?>

<!-- Status tabs -->
<ul class="nav nav-tabs mb-3">
  <?php
  $tabs = [
    'all'         => ['label' => 'All', 'count' => array_sum($counts)],
    'open'        => ['label' => 'Open', 'count' => $counts['open'] ?? 0],
    'in_progress' => ['label' => 'In Progress', 'count' => $counts['in_progress'] ?? 0],
    'resolved'    => ['label' => 'Resolved', 'count' => $counts['resolved'] ?? 0],
    'closed'      => ['label' => 'Closed', 'count' => $counts['closed'] ?? 0],
  ];
  foreach ($tabs as $key => $tab): ?>
  <li class="nav-item">
    <a href="?status=<?= $key ?>" class="nav-link <?= $statusFilter === $key ? 'active' : '' ?>">
      <?= $tab['label'] ?>
      <?php if ($tab['count'] > 0): ?>
      <span class="badge <?= $statusFilter === $key ? 'bg-primary' : 'bg-secondary' ?> ms-1"><?= $tab['count'] ?></span>
      <?php endif; ?>
    </a>
  </li>
  <?php endforeach; ?>
</ul>

<div class="card">
  <div class="card-body p-0">
    <?php if (empty($tickets)): ?>
    <div class="text-center py-5 text-muted">
      <i class="fas fa-headset fa-3x mb-3 d-block"></i>
      <h5>No tickets found</h5>
      <p class="small">Have a question or need help? Open a support ticket and we'll get back to you.</p>
      <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newTicketModal">
        <i class="fas fa-plus me-2"></i>Open First Ticket
      </button>
    </div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead class="table-light">
          <tr>
            <th>Ticket</th>
            <th>Subject</th>
            <th>Category</th>
            <th>Priority</th>
            <th>Status</th>
            <th>Updated</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($tickets as $tk): ?>
          <tr>
            <td class="fw-bold small"><?= e($tk['ticket_number']) ?></td>
            <td>
              <a href="?view=<?= $tk['id'] ?>" class="fw-600 text-dark text-decoration-none">
                <?= e($tk['subject']) ?>
              </a>
            </td>
            <td>
              <span class="small">
                <i class="fas <?= $categoryIcons[$tk['category']] ?? 'fa-tag' ?> text-muted me-1"></i>
                <?= ucfirst(str_replace('_',' ',$tk['category'])) ?>
              </span>
            </td>
            <td><span class="badge bg-<?= $priorityColors[$tk['priority']] ?? 'secondary' ?>"><?= ucfirst($tk['priority']) ?></span></td>
            <td><span class="badge bg-<?= $statusColors[$tk['status']] ?? 'secondary' ?>"><?= ucfirst(str_replace('_',' ',$tk['status'])) ?></span></td>
            <td class="small text-muted"><?= timeAgo($tk['updated_at']) ?></td>
            <td>
              <a href="?view=<?= $tk['id'] ?>" class="btn btn-xs btn-outline-primary btn-sm">
                <i class="fas fa-eye me-1"></i>View
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

<!-- ── New Ticket Modal ──────────────────────────────────────── -->
<div class="modal fade" id="newTicketModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-ticket-alt me-2 text-green"></i>Open Support Ticket</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="create_ticket">
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label fw-600">Subject <span class="text-danger">*</span></label>
            <input type="text" name="subject" class="form-control" required
                   placeholder="Brief description of your issue" maxlength="255">
          </div>
          <div class="row g-3 mb-3">
            <div class="col-sm-6">
              <label class="form-label fw-600">Category</label>
              <select name="category" class="form-select">
                <option value="general">General Inquiry</option>
                <option value="billing">Billing & Payments</option>
                <option value="technical">Technical Issue</option>
                <option value="module_request">Module Request</option>
                <option value="feature_request">Feature Request</option>
              </select>
            </div>
            <div class="col-sm-6">
              <label class="form-label fw-600">Priority</label>
              <select name="priority" class="form-select">
                <option value="normal" selected>Normal</option>
                <option value="low">Low</option>
                <option value="high">High</option>
                <option value="urgent">Urgent</option>
              </select>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label fw-600">Message <span class="text-danger">*</span></label>
            <textarea name="message" class="form-control" rows="6" required
                      placeholder="Describe your issue in detail. Include any error messages, steps to reproduce, or relevant information."></textarea>
          </div>
          <div class="alert alert-info small mb-0">
            <i class="fas fa-info-circle me-2"></i>
            We respond to all tickets within <strong>24 business hours</strong>.
            For urgent issues, mark priority as <strong>Urgent</strong>.
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane me-2"></i>Submit Ticket</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
