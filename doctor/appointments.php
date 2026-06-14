<?php
$pageTitle = 'My Appointments';
require_once __DIR__ . '/../includes/header-doctor.php';

$filterDate   = $_GET['date']   ?? '';
$filterStatus = $_GET['status'] ?? '';

$validStatuses = ['scheduled','completed','cancelled','no_show'];
if ($filterStatus && !in_array($filterStatus, $validStatuses)) $filterStatus = '';

$appointments = [];
try {
    $where  = "a.doctor_id=? AND a.org_id=?";
    $params = [$docId, $docOrgId];
    if ($filterDate)   { $where .= " AND DATE(a.appointment_date)=?"; $params[] = $filterDate; }
    if ($filterStatus) { $where .= " AND a.status=?"; $params[] = $filterStatus; }
    $s = $pdo->prepare("
        SELECT a.*, CONCAT(p.first_name,' ',p.last_name) AS patient_name, p.patient_no, p.phone AS patient_phone
        FROM health_appointments a
        JOIN health_patients p ON p.id=a.patient_id
        WHERE $where
        ORDER BY a.appointment_date DESC
        LIMIT 200
    ");
    $s->execute($params);
    $appointments = $s->fetchAll();
} catch (Throwable $e) {}

$statusColors = ['scheduled'=>'primary','completed'=>'success','cancelled'=>'secondary','no_show'=>'warning'];
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
  <div>
    <h5 class="fw-bold mb-0"><i class="fas fa-calendar-check me-2" style="color:var(--doc-blue)"></i>My Appointments</h5>
    <p class="text-muted small mb-0">All appointments assigned to you</p>
  </div>
</div>

<!-- Filters -->
<div class="card border-0 shadow-sm mb-3">
  <div class="card-body py-2 px-3">
    <form class="row g-2 align-items-end">
      <div class="col-sm-4">
        <label class="form-label small mb-1">Date</label>
        <input type="date" name="date" class="form-control form-control-sm" value="<?= e($filterDate) ?>">
      </div>
      <div class="col-sm-4">
        <label class="form-label small mb-1">Status</label>
        <select name="status" class="form-select form-select-sm">
          <option value="">All statuses</option>
          <?php foreach ($validStatuses as $st): ?>
          <option value="<?= $st ?>" <?= $filterStatus===$st?'selected':'' ?>><?= ucfirst(str_replace('_',' ',$st)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-4 d-flex gap-2">
        <button class="btn btn-sm btn-primary">Filter</button>
        <a href="appointments.php" class="btn btn-sm btn-outline-secondary">Reset</a>
      </div>
    </form>
  </div>
</div>

<!-- Table -->
<?php if (empty($appointments)): ?>
<div class="card border-0 shadow-sm">
  <div class="card-body text-center py-5 text-muted">
    <i class="fas fa-calendar-times fa-3x mb-3 d-block opacity-25"></i>
    <p>No appointments found<?= $filterDate ? ' for '.date('d M Y', strtotime($filterDate)) : '' ?>.</p>
  </div>
</div>
<?php else: ?>
<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead class="table-light">
          <tr><th>Patient</th><th>Date &amp; Time</th><th>Reason</th><th>Type</th><th>Status</th><th>Actions</th></tr>
        </thead>
        <tbody>
          <?php foreach ($appointments as $a):
            $bg = $statusColors[$a['status']] ?? 'secondary';
          ?>
          <tr>
            <td>
              <div class="fw-semibold"><?= e($a['patient_name']) ?></div>
              <div class="text-muted small">#<?= e($a['patient_no'] ?? $a['patient_id']) ?> <?= $a['patient_phone'] ? '· '.e($a['patient_phone']) : '' ?></div>
            </td>
            <td>
              <div class="fw-semibold small"><?= date('d M Y', strtotime($a['appointment_date'])) ?></div>
              <div class="text-muted small"><?= date('H:i', strtotime($a['appointment_date'])) ?></div>
            </td>
            <td class="small"><?= e($a['reason'] ?? '—') ?></td>
            <td class="small"><?= e(ucfirst($a['appointment_type'] ?? 'Outpatient')) ?></td>
            <td><span class="badge bg-<?= $bg ?>"><?= ucfirst(str_replace('_',' ',$a['status'])) ?></span></td>
            <td>
              <div class="d-flex gap-1">
                <a href="<?= APP_URL ?>/doctor/records.php?patient_id=<?= $a['patient_id'] ?>&appt_id=<?= $a['id'] ?>"
                   class="btn btn-xs btn-outline-success" title="Write Record">
                  <i class="fas fa-file-medical"></i>
                </a>
                <a href="<?= APP_URL ?>/doctor/prescriptions.php?new=1&patient_id=<?= $a['patient_id'] ?>"
                   class="btn btn-xs btn-outline-primary" title="Prescribe">
                  <i class="fas fa-prescription"></i>
                </a>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="card-footer bg-white border-0 py-2 px-3">
    <span class="text-muted small"><?= count($appointments) ?> appointment<?= count($appointments)!==1?'s':'' ?> found</span>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer-doctor.php'; ?>
