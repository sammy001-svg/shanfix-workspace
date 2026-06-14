<?php
$pageTitle = 'My Patients';
require_once __DIR__ . '/../includes/header-doctor.php';

$search = sanitize($_GET['q'] ?? '');

$patients = [];
try {
    // Patients this doctor has had appointments with OR written records for
    $params = [$docId, $docOrgId, $docId, $docOrgId];
    $searchCond = '';
    if ($search) {
        $searchCond = " AND (p.first_name LIKE ? OR p.last_name LIKE ? OR p.patient_no LIKE ? OR p.phone LIKE ?)";
        $like = "%$search%";
        $params = array_merge([$docId, $docOrgId, $docId, $docOrgId], [$like, $like, $like, $like]);
    }

    $s = $pdo->prepare("
        SELECT DISTINCT p.id, p.first_name, p.last_name, p.patient_no, p.phone, p.date_of_birth, p.gender,
               p.blood_group, p.status,
               (SELECT MAX(a2.appointment_date) FROM health_appointments a2
                WHERE a2.patient_id=p.id AND a2.doctor_id=? AND a2.org_id=?) AS last_visit
        FROM health_patients p
        WHERE p.org_id=?
          AND (
            p.id IN (SELECT patient_id FROM health_appointments WHERE doctor_id=? AND org_id=p.org_id)
            OR p.id IN (SELECT patient_id FROM health_records WHERE doctor_id=? AND org_id=p.org_id)
          )
          $searchCond
        ORDER BY last_visit DESC, p.last_name ASC
        LIMIT 100
    ");

    // Rebuild params for the simplified version
    $params2 = [$docId, $docOrgId, $docOrgId, $docId, $docOrgId, $docId, $docOrgId];
    if ($search) {
        $like = "%$search%";
        $params2 = array_merge($params2, [$like, $like, $like, $like]);
    }
    $s->execute($params2);
    $patients = $s->fetchAll();
} catch (Throwable $e) {}
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
  <div>
    <h5 class="fw-bold mb-0"><i class="fas fa-procedures me-2" style="color:var(--doc-blue)"></i>My Patients</h5>
    <p class="text-muted small mb-0">Patients you have seen or treated</p>
  </div>
</div>

<!-- Search -->
<div class="card border-0 shadow-sm mb-3">
  <div class="card-body py-2 px-3">
    <form class="d-flex gap-2">
      <input type="text" name="q" class="form-control form-control-sm" placeholder="Search by name, patient #, or phone..."
             value="<?= e($search) ?>" style="max-width:360px">
      <button class="btn btn-sm btn-primary">Search</button>
      <?php if ($search): ?><a href="patients.php" class="btn btn-sm btn-outline-secondary">Clear</a><?php endif; ?>
    </form>
  </div>
</div>

<?php if (empty($patients)): ?>
<div class="card border-0 shadow-sm">
  <div class="card-body text-center py-5 text-muted">
    <i class="fas fa-procedures fa-3x mb-3 d-block opacity-25"></i>
    <p><?= $search ? 'No patients match your search.' : 'You have no patient history yet.' ?></p>
  </div>
</div>
<?php else: ?>
<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead class="table-light">
          <tr><th>Patient</th><th>Age / Gender</th><th>Blood Group</th><th>Last Visit</th><th>Status</th><th>Actions</th></tr>
        </thead>
        <tbody>
          <?php foreach ($patients as $p):
            $dob = $p['date_of_birth'] ? date_diff(date_create($p['date_of_birth']), date_create('today'))->y : null;
          ?>
          <tr>
            <td>
              <div class="fw-semibold"><?= e($p['first_name'].' '.$p['last_name']) ?></div>
              <div class="text-muted small">#<?= e($p['patient_no'] ?? $p['id']) ?> <?= $p['phone'] ? '· '.e($p['phone']) : '' ?></div>
            </td>
            <td class="small"><?= $dob !== null ? $dob.' yrs' : '—' ?> <?= $p['gender'] ? '/ '.ucfirst($p['gender']) : '' ?></td>
            <td class="small fw-semibold"><?= $p['blood_group'] ? e($p['blood_group']) : '—' ?></td>
            <td class="small"><?= $p['last_visit'] ? date('d M Y', strtotime($p['last_visit'])) : '—' ?></td>
            <td><span class="badge bg-<?= $p['status']==='active' ? 'success' : 'secondary' ?>"><?= ucfirst($p['status'] ?? 'active') ?></span></td>
            <td>
              <div class="d-flex gap-1">
                <a href="<?= APP_URL ?>/doctor/records.php?patient_id=<?= $p['id'] ?>"
                   class="btn btn-xs btn-outline-success" title="View/Write Records">
                  <i class="fas fa-file-medical"></i>
                </a>
                <a href="<?= APP_URL ?>/doctor/prescriptions.php?new=1&patient_id=<?= $p['id'] ?>"
                   class="btn btn-xs btn-outline-primary" title="New Prescription">
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
    <span class="text-muted small"><?= count($patients) ?> patient<?= count($patients)!==1?'s':'' ?></span>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer-doctor.php'; ?>
