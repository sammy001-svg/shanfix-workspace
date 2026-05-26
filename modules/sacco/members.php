<?php
// ── SACCO: Members Management ──────────────────────────────────
$moduleSlug  = 'sacco';
$moduleName  = 'SACCO Management';
$moduleIcon  = 'fas fa-piggy-bank';
$moduleColor = '#8e44ad';
$moduleNav   = [
    ['url' => 'index.php',        'icon' => 'fas fa-tachometer-alt',   'label' => 'Dashboard'],
    ['url' => 'members.php',      'icon' => 'fas fa-users',            'label' => 'Members'],
    ['url' => 'savings.php',      'icon' => 'fas fa-piggy-bank',       'label' => 'Savings'],
    ['url' => 'loans.php',        'icon' => 'fas fa-hand-holding-usd', 'label' => 'Loans'],
    ['url' => 'shares.php',       'icon' => 'fas fa-certificate',      'label' => 'Shares'],
    ['url' => 'repayments.php',   'icon' => 'fas fa-undo',             'label' => 'Repayments'],
    ['url' => 'dividends.php',    'icon' => 'fas fa-percentage',       'label' => 'Dividends'],
    ['url' => 'statements.php',   'icon' => 'fas fa-file-invoice',     'label' => 'Statements'],
    ['url' => 'reports.php',      'icon' => 'fas fa-chart-bar',        'label' => 'Reports'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();
    $user  = currentUser();
    $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $id          = (int)($_POST['id'] ?? 0);
        $memberNo    = sanitize($_POST['member_no']    ?? '');
        $firstName   = sanitize($_POST['first_name']   ?? '');
        $lastName    = sanitize($_POST['last_name']    ?? '');
        $idNumber    = sanitize($_POST['id_number']    ?? '');
        $phone       = sanitize($_POST['phone']       ?? '');
        $email       = sanitize($_POST['email']       ?? '');
        $occupation  = sanitize($_POST['occupation']  ?? '');
        $address     = sanitize($_POST['address']     ?? '');
        $shares      = (int)($_POST['shares']         ?? 0);
        $shareValue  = (float)($_POST['share_value']   ?? 0.00);
        $joinedAt    = $_POST['joined_at']             ?? date('Y-m-d');
        $status      = sanitize($_POST['status']      ?? 'active');

        if (empty($firstName) || empty($lastName) || empty($phone)) {
            setFlash('danger', 'First Name, Last Name, and Phone are required.');
            redirect('members.php');
        }

        if ($action === 'add') {
            // Generate Member No if empty
            if (empty($memberNo)) {
                $stmt = $pdo->prepare("SELECT COALESCE(MAX(id), 0) FROM sacco_members WHERE org_id = ?");
                $stmt->execute([$orgId]);
                $maxId = (int)$stmt->fetchColumn();
                $memberNo = 'MEM-' . str_pad($maxId + 1, 4, '0', STR_PAD_LEFT);
            }

            $stmt = $pdo->prepare("INSERT INTO sacco_members (org_id, member_no, first_name, last_name, id_number, phone, email, occupation, address, shares, share_value, total_savings, status, joined_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,0.00,?,?)");
            $stmt->execute([$orgId, $memberNo, $firstName, $lastName, $idNumber, $phone, $email, $occupation, $address, $shares, $shareValue, $status, $joinedAt]);
            setFlash('success', 'Member added successfully.');
            logActivity('create', 'sacco', "Added member $firstName $lastName ($memberNo)");
        } else {
            $stmt = $pdo->prepare("UPDATE sacco_members SET member_no=?, first_name=?, last_name=?, id_number=?, phone=?, email=?, occupation=?, address=?, shares=?, share_value=?, status=?, joined_at=? WHERE id=? AND org_id=?");
            $stmt->execute([$memberNo, $firstName, $lastName, $idNumber, $phone, $email, $occupation, $address, $shares, $shareValue, $status, $joinedAt, $id, $orgId]);
            setFlash('success', 'Member updated successfully.');
            logActivity('update', 'sacco', "Updated member $firstName $lastName ($memberNo)");
        }
        redirect('members.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        
        // Safety lock: Check active loans or positive savings balance
        $stmt = $pdo->prepare("SELECT total_savings, first_name, last_name FROM sacco_members WHERE id=? AND org_id=?");
        $stmt->execute([$id, $orgId]);
        $member = $stmt->fetch();

        if ($member) {
            $savings = (float)$member['total_savings'];
            $activeLoansCount = countRows('sacco_loans', "member_id = ? AND org_id = ? AND status IN ('active', 'pending', 'approved')", [$id, $orgId]);
            
            if ($savings > 0) {
                setFlash('danger', 'Cannot delete member with positive savings balance (' . formatCurrency($savings) . ').');
            } elseif ($activeLoansCount > 0) {
                setFlash('danger', 'Cannot delete member with active or pending loans.');
            } else {
                $stmt = $pdo->prepare("DELETE FROM sacco_members WHERE id=? AND org_id=?");
                $stmt->execute([$id, $orgId]);
                setFlash('success', 'Member deleted successfully.');
                logActivity('delete', 'sacco', "Deleted member {$member['first_name']} {$member['last_name']}");
            }
        }
        redirect('members.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

// Filter parameters
$filterStatus = $_GET['status'] ?? '';
$where = "org_id = ?";
$params = [$orgId];

if ($filterStatus !== '') {
    $where .= " AND status = ?";
    $params[] = $filterStatus;
}

$members = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM sacco_members WHERE $where ORDER BY created_at DESC");
    $stmt->execute($params);
    $members = $stmt->fetchAll();
} catch (Exception $e) {}

// Quick Stat Metrics
$totalMembersCount = countRows('sacco_members', 'org_id = ?', [$orgId]);
$activeMembers     = countRows('sacco_members', 'org_id = ? AND status = ?', [$orgId, 'active']);
$totalShares = 0;
$totalSavings = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(shares * share_value),0), COALESCE(SUM(total_savings),0) FROM sacco_members WHERE org_id=?");
    $stmt->execute([$orgId]);
    $row = $stmt->fetch(PDO::FETCH_NUM);
    $totalShares  = (float)$row[0];
    $totalSavings = (float)$row[1];
} catch (Exception $e) {}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-users me-2" style="color:<?= $moduleColor ?>"></i>Sacco Members</h4>
    <p class="text-muted mb-0">Manage Sacco membership, shares, and savings portfolio</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#memberModal" onclick="openAddModal()">
    <i class="fas fa-plus me-2"></i>Add Member
  </button>
</div>

<!-- Stats widgets -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon purple-bg" style="background:rgba(142,68,173,0.15);color:#8e44ad"><i class="fas fa-users"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalMembersCount ?></div><div class="stat-label">Total Members</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-user-check"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $activeMembers ?></div><div class="stat-label">Active Members</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-chart-pie"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalShares) ?></div><div class="stat-label">Total Share Capital</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-piggy-bank"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalSavings) ?></div><div class="stat-label">Total Member Savings</div></div>
    </div>
  </div>
</div>

<!-- Filter card -->
<div class="card mb-4 border-0 shadow-sm">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-3">
        <label class="form-label small fw-semibold mb-1">Filter Status</label>
        <select name="status" class="form-select form-select-sm">
          <option value="">All Statuses</option>
          <option value="active" <?= $filterStatus==='active'?'selected':'' ?>>Active</option>
          <option value="inactive" <?= $filterStatus==='inactive'?'selected':'' ?>>Inactive</option>
          <option value="suspended" <?= $filterStatus==='suspended'?'selected':'' ?>>Suspended</option>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-filter me-1"></i>Filter</button>
        <a href="members.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
      </div>
    </form>
  </div>
</div>

<!-- Members Table Card -->
<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0" id="membersTable">
        <thead class="table-light">
          <tr>
            <th>Member #</th>
            <th>Name</th>
            <th>Contact Details</th>
            <th>Shares Capital</th>
            <th>Savings Balance</th>
            <th>Status</th>
            <th>Date Joined</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($members)): ?>
          <tr>
            <td colspan="8" class="text-center py-5 text-muted">
              <i class="fas fa-users fa-3x mb-3 d-block"></i>No members found.
            </td>
          </tr>
          <?php else: foreach ($members as $m): ?>
          <tr>
            <td class="fw-bold text-dark"><?= e($m['member_no'] ?? '#'.$m['id']) ?></td>
            <td>
              <div class="fw-semibold text-dark"><?= e($m['first_name'] . ' ' . $m['last_name']) ?></div>
              <div class="small text-muted"><?= e($m['occupation'] ?: 'Not Specified') ?></div>
            </td>
            <td>
              <div class="small"><i class="fas fa-phone me-1 text-muted"></i><?= e($m['phone']) ?></div>
              <div class="small text-muted"><i class="fas fa-envelope me-1"></i><?= e($m['email'] ?: '—') ?></div>
            </td>
            <td>
              <div class="fw-bold"><?= formatCurrency((float)($m['shares'] * $m['share_value'])) ?></div>
              <div class="small text-muted"><?= $m['shares'] ?> shares</div>
            </td>
            <td class="fw-bold text-success"><?= formatCurrency((float)($m['total_savings'] ?? 0)) ?></td>
            <td><?= statusBadge($m['status'] ?? 'active') ?></td>
            <td><?= formatDate($m['joined_at'] ?? $m['created_at'] ?? '') ?></td>
            <td class="text-end" style="white-space:nowrap">
              <button class="btn btn-sm btn-outline-primary" onclick="viewMember(<?= e(json_encode($m)) ?>)" title="View Member Details">
                <i class="fas fa-eye"></i>
              </button>
              <button class="btn btn-sm btn-outline-secondary ms-1" onclick="editMember(<?= e(json_encode($m)) ?>)" title="Edit Member">
                <i class="fas fa-edit"></i>
              </button>
              <form method="POST" class="d-inline ms-1" onsubmit="return confirm('Are you sure you want to delete this member?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $m['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete Member">
                  <i class="fas fa-trash"></i>
                </button>
              </form>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Add / Edit Member Modal -->
<div class="modal fade" id="memberModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" id="modalAction" value="add">
        <input type="hidden" name="id" id="memberId" value="">
        <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
          <h5 class="modal-title" id="modalTitle"><i class="fas fa-user-plus me-2"></i>Add Member</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Member Number</label>
              <input type="text" name="member_no" id="memberNo" class="form-control" placeholder="Auto-generated if empty">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">National ID / Passport Number</label>
              <input type="text" name="id_number" id="memberIdNum" class="form-control" placeholder="e.g. 12345678">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">First Name <span class="text-danger">*</span></label>
              <input type="text" name="first_name" id="memberFirstName" class="form-control" required placeholder="e.g. John">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Last Name <span class="text-danger">*</span></label>
              <input type="text" name="last_name" id="memberLastName" class="form-control" required placeholder="e.g. Doe">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Phone <span class="text-danger">*</span></label>
              <input type="text" name="phone" id="memberPhone" class="form-control" required placeholder="e.g. 0712345678">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Email</label>
              <input type="email" name="email" id="memberEmail" class="form-control" placeholder="e.g. john@example.com">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Occupation</label>
              <input type="text" name="occupation" id="memberOccupation" class="form-control" placeholder="e.g. Teacher, Business owner">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Date Joined</label>
              <input type="date" name="joined_at" id="memberJoinedAt" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Shares Capital count</label>
              <input type="number" name="shares" id="memberShares" class="form-control" min="0" value="0">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Share Unit Value (<?= CURRENCY ?>)</label>
              <input type="number" step="0.01" name="share_value" id="memberShareVal" class="form-control" min="0" value="20.00">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Member Status</label>
              <select name="status" id="memberStatus" class="form-select">
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
                <option value="suspended">Suspended</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Physical Address</label>
              <textarea name="address" id="memberAddress" class="form-control" rows="2" placeholder="Street, City, Postal Address"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save Member</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- View Member Details Modal -->
<div class="modal fade" id="viewMemberModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
        <h5 class="modal-title"><i class="fas fa-id-card me-2"></i>Member Profile Details</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-4">
          <!-- Member profile summary -->
          <div class="col-md-4 text-center border-end">
            <div class="rounded-circle d-inline-flex align-items-center justify-content-center bg-light text-secondary mb-3" style="width:90px;height:90px;font-size:2.5rem">
              <i class="fas fa-user"></i>
            </div>
            <h5 class="mb-1 text-dark" id="viewFullName">John Doe</h5>
            <span class="badge text-white mb-3" id="viewBadgeStatus" style="background:<?= $moduleColor ?>">ACTIVE</span>
            <hr>
            <div class="text-start mt-2">
              <div class="small text-muted">MEMBER NUMBER</div>
              <div class="fw-bold mb-2 text-dark" id="viewMemberNo">MEM-0001</div>
              
              <div class="small text-muted">NATIONAL ID / PASSPORT</div>
              <div class="fw-bold mb-2 text-dark" id="viewIdNum">12345678</div>
              
              <div class="small text-muted">DATE JOINED</div>
              <div class="fw-bold text-dark" id="viewJoinedAt">01 Jan 2026</div>
            </div>
          </div>
          <!-- Financial details -->
          <div class="col-md-8">
            <h6 class="border-bottom pb-2 fw-semibold text-dark"><i class="fas fa-wallet me-2" style="color:<?= $moduleColor ?>"></i>Financial Overview</h6>
            <div class="row g-2 mb-4">
              <div class="col-sm-6">
                <div class="p-3 bg-light rounded text-center">
                  <div class="small text-muted">Shares Value</div>
                  <h4 class="fw-bold text-dark mb-0" id="viewSharesVal">KES 0.00</h4>
                  <small class="text-muted" id="viewSharesCount">0 units</small>
                </div>
              </div>
              <div class="col-sm-6">
                <div class="p-3 bg-light rounded text-center">
                  <div class="small text-muted">Savings Balance</div>
                  <h4 class="fw-bold text-success mb-0" id="viewSavingsBal">KES 0.00</h4>
                </div>
              </div>
            </div>

            <h6 class="border-bottom pb-2 fw-semibold text-dark"><i class="fas fa-phone-alt me-2" style="color:<?= $moduleColor ?>"></i>Contact & Personal Info</h6>
            <table class="table table-sm table-borderless">
              <tr>
                <td class="text-muted" style="width:30%">Phone Number</td>
                <td class="fw-semibold text-dark" id="viewPhone">0712345678</td>
              </tr>
              <tr>
                <td class="text-muted">Email Address</td>
                <td class="fw-semibold text-dark" id="viewEmail">john@example.com</td>
              </tr>
              <tr>
                <td class="text-muted">Occupation</td>
                <td class="fw-semibold text-dark" id="viewOccupation">Teacher</td>
              </tr>
              <tr>
                <td class="text-muted">Physical Address</td>
                <td class="fw-semibold text-dark" id="viewAddress">123 Main St, Nairobi</td>
              </tr>
            </table>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<?php
$extraJs = '<script>
$(document).ready(function(){
  $("#membersTable").DataTable({pageLength:10,order:[[0,"desc"]]});
});

function openAddModal() {
  $("#modalAction").val("add");
  $("#modalTitle").html("<i class=\"fas fa-user-plus me-2\"></i>Add Member");
  $("#memberId").val("");
  $("#memberNo").val("");
  $("#memberIdNum").val("");
  $("#memberFirstName").val("");
  $("#memberLastName").val("");
  $("#memberPhone").val("");
  $("#memberEmail").val("");
  $("#memberOccupation").val("");
  $("#memberShares").val("0");
  $("#memberShareVal").val("20.00");
  $("#memberStatus").val("active");
  $("#memberAddress").val("");
}

function editMember(m) {
  $("#modalAction").val("edit");
  $("#modalTitle").html("<i class=\"fas fa-user-edit me-2\"></i>Edit Member");
  $("#memberId").val(m.id);
  $("#memberNo").val(m.member_no || "");
  $("#memberIdNum").val(m.id_number || "");
  $("#memberFirstName").val(m.first_name || "");
  $("#memberLastName").val(m.last_name || "");
  $("#memberPhone").val(m.phone || "");
  $("#memberEmail").val(m.email || "");
  $("#memberOccupation").val(m.occupation || "");
  $("#memberShares").val(m.shares || 0);
  $("#memberShareVal").val(m.share_value || "20.00");
  $("#memberStatus").val(m.status || "active");
  $("#memberAddress").val(m.address || "");
  $("#memberJoinedAt").val(m.joined_at || "");
  new bootstrap.Modal(document.getElementById("memberModal")).show();
}

function viewMember(m) {
  $("#viewFullName").text(m.first_name + " " + m.last_name);
  $("#viewBadgeStatus").text(m.status.toUpperCase());
  $("#viewMemberNo").text(m.member_no || ("#" + m.id));
  $("#viewIdNum").text(m.id_number || "—");
  
  var months = ["Jan","Feb","Mar","Apr","May","Jun","Jul","Aug","Sep","Oct","Nov","Dec"];
  var d = new Date(m.joined_at || m.created_at);
  var joinedFormatted = d.getDate() + " " + months[d.getMonth()] + " " + d.getFullYear();
  $("#viewJoinedAt").text(joinedFormatted);

  var totalShares = parseFloat(m.shares || 0) * parseFloat(m.share_value || 0);
  $("#viewSharesVal").text(formatMoney(totalShares));
  $("#viewSharesCount").text((m.shares || 0) + " units @ KES " + parseFloat(m.share_value || 0).toFixed(2));
  $("#viewSavingsBal").text(formatMoney(parseFloat(m.total_savings || 0)));

  $("#viewPhone").text(m.phone || "—");
  $("#viewEmail").text(m.email || "—");
  $("#viewOccupation").text(m.occupation || "—");
  $("#viewAddress").text(m.address || "—");

  new bootstrap.Modal(document.getElementById("viewMemberModal")).show();
}

function formatMoney(amount) {
  return "KES " + amount.toFixed(2).replace(/\d(?=(\d{3})+\.)/g, "$&,");
}
</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
