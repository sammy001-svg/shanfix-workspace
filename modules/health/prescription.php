<?php
$moduleSlug  = 'health';
$moduleName  = 'Health & Clinic';
$moduleIcon  = 'fas fa-heartbeat';
$moduleColor = '#e74c3c';
$moduleNav   = [
    ['url'=>'index.php',         'icon'=>'fas fa-tachometer-alt',      'label'=>'Dashboard'],
    ['url'=>'patients.php',      'icon'=>'fas fa-procedures',          'label'=>'Patients'],
    ['url'=>'appointments.php',  'icon'=>'fas fa-calendar-check',      'label'=>'Appointments'],
    ['url'=>'doctors.php',       'icon'=>'fas fa-user-md',             'label'=>'Doctors'],
    ['url'=>'schedule.php',      'icon'=>'fas fa-calendar-alt',        'label'=>'Doctor Schedule'],
    ['url'=>'staff.php',         'icon'=>'fas fa-id-badge',            'label'=>'Clinical Staff'],
    ['url'=>'records.php',       'icon'=>'fas fa-file-medical',        'label'=>'Medical Records'],
    ['url'=>'vitals.php',        'icon'=>'fas fa-heartbeat',           'label'=>'Vital Signs'],
    ['url'=>'lab.php',           'icon'=>'fas fa-flask',               'label'=>'Laboratory'],
    ['url'=>'pharmacy.php',      'icon'=>'fas fa-pills',               'label'=>'Pharmacy'],
    ['url'=>'nursing.php',       'icon'=>'fas fa-user-nurse',          'label'=>'Nursing'],
    ['url'=>'wards.php',         'icon'=>'fas fa-bed',                 'label'=>'Wards & Beds'],
    ['url'=>'admissions.php',    'icon'=>'fas fa-hospital-user',       'label'=>'Admissions (IPD)'],
    ['url'=>'surgery.php',       'icon'=>'fas fa-syringe',             'label'=>'Surgery / Theatre'],
    ['url'=>'emergency.php',     'icon'=>'fas fa-ambulance',           'label'=>'Emergency / Triage'],
    ['url'=>'billing.php',       'icon'=>'fas fa-file-invoice-dollar', 'label'=>'Billing'],
    ['url'=>'timeline.php',      'icon'=>'fas fa-history',             'label'=>'Patient Timeline'],
    ['url'=>'prescription.php',  'icon'=>'fas fa-prescription',        'label'=>'Prescriptions'],
    ['url'=>'telemedicine.php',  'icon'=>'fas fa-video',               'label'=>'Telemedicine'],
    ['url'=>'patient_crm.php',   'icon'=>'fas fa-users',               'label'=>'Patient CRM'],
    ['url'=>'analytics.php',     'icon'=>'fas fa-brain',               'label'=>'AI Analytics'],
    ['url'=>'reports.php',       'icon'=>'fas fa-chart-bar',           'label'=>'Reports'],
    ['url'=>'settings.php',      'icon'=>'fas fa-cog',                 'label'=>'Settings'],
];

// Ensure table exists
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS health_prescriptions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        org_id INT NOT NULL,
        prescription_no VARCHAR(30),
        patient_id INT,
        doctor_id INT,
        prescription_date DATE NOT NULL,
        diagnosis TEXT,
        medicines JSON,
        notes TEXT,
        status ENUM('draft','dispensed','cancelled') DEFAULT 'draft',
        dispensed_by INT,
        dispensed_at DATETIME,
        created_by INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_org (org_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../includes/header-module.php';
    verifyCsrf();denyIfReadOnly($moduleSlug);
    $orgId  = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'save_prescription') {
        $patientId = (int)$_POST['patient_id'];
        $doctorId  = (int)($_POST['doctor_id'] ?? 0);
        $rxDate    = sanitize($_POST['prescription_date'] ?? date('Y-m-d'));
        $diagnosis = sanitize($_POST['diagnosis'] ?? '');
        $notes     = sanitize($_POST['notes'] ?? '');

        // Parse medicines
        $medicines = [];
        $names     = $_POST['med_name']        ?? [];
        $dosages   = $_POST['med_dosage']      ?? [];
        $freqs     = $_POST['med_frequency']   ?? [];
        $durs      = $_POST['med_duration']    ?? [];
        $instrs    = $_POST['med_instructions'] ?? [];
        foreach ($names as $i => $name) {
            if (trim($name)) {
                $medicines[] = [
                    'name'         => sanitize($name),
                    'dosage'       => sanitize($dosages[$i] ?? ''),
                    'frequency'    => sanitize($freqs[$i]   ?? ''),
                    'duration'     => sanitize($durs[$i]    ?? ''),
                    'instructions' => sanitize($instrs[$i]  ?? ''),
                ];
            }
        }

        if (!$patientId || empty($medicines)) {
            setFlash('danger', 'Patient and at least one medicine are required.');
        } else {
            $id = (int)($_POST['edit_id'] ?? 0);
            if ($id) {
                $pdo->prepare("UPDATE health_prescriptions SET patient_id=?,doctor_id=?,prescription_date=?,diagnosis=?,medicines=?,notes=? WHERE id=? AND org_id=?")
                    ->execute([$patientId, $doctorId ?: null, $rxDate, $diagnosis, json_encode($medicines), $notes, $id, $orgId]);
                setFlash('success', 'Prescription updated.');
            } else {
                $rxNo = 'RX-' . date('Y') . '-' . str_pad((int)$pdo->query("SELECT COUNT(*)+1 FROM health_prescriptions WHERE org_id=$orgId")->fetchColumn(), 4, '0', STR_PAD_LEFT);
                $pdo->prepare("INSERT INTO health_prescriptions (org_id,prescription_no,patient_id,doctor_id,prescription_date,diagnosis,medicines,notes,status,created_by) VALUES (?,?,?,?,?,?,?,?,'draft',?)")
                    ->execute([$orgId, $rxNo, $patientId, $doctorId ?: null, $rxDate, $diagnosis, json_encode($medicines), $notes, $user['id']]);
                setFlash('success', 'Prescription created.');
            }
        }
        redirect(APP_URL . '/modules/health/prescription.php');
    }

    if ($action === 'dispense') {
        $id = (int)$_POST['prescription_id'];
        $pdo->prepare("UPDATE health_prescriptions SET status='dispensed', dispensed_by=?, dispensed_at=NOW() WHERE id=? AND org_id=?")
            ->execute([$user['id'], $id, (int)$user['org_id']]);
        setFlash('success', 'Prescription marked as dispensed.');
        redirect(APP_URL . '/modules/health/prescription.php');
    }

    if ($action === 'cancel') {
        $pdo->prepare("UPDATE health_prescriptions SET status='cancelled' WHERE id=? AND org_id=? AND status='draft'")
            ->execute([(int)$_POST['prescription_id'], (int)$user['org_id']]);
        setFlash('success', 'Prescription cancelled.');
        redirect(APP_URL . '/modules/health/prescription.php');
    }

    if ($action === 'export_pdf') {
        $id   = (int)$_POST['prescription_id'];
        $orgId = (int)$user['org_id'];
        $stmt  = $pdo->prepare("SELECT rx.*, CONCAT(p.first_name,' ',p.last_name) AS patient_name, p.patient_no, p.date_of_birth, CONCAT(d.first_name,' ',d.last_name) AS doctor_name, d.specialization FROM health_prescriptions rx LEFT JOIN health_patients p ON rx.patient_id=p.id LEFT JOIN health_doctors d ON rx.doctor_id=d.id WHERE rx.id=? AND rx.org_id=?");
        $stmt->execute([$id, $orgId]);
        $rx = $stmt->fetch();
        if ($rx) {
            require_once __DIR__ . '/../../includes/pdf.php';
            $meds = json_decode($rx['medicines'] ?? '[]', true) ?: [];
            $rows = [];
            foreach ($meds as $m) {
                $rows[] = [$m['name'],$m['dosage'],$m['frequency'],$m['duration'],$m['instructions']];
            }
            generateModuleReportPDF(
                'Prescription — ' . $rx['prescription_no'],
                'Patient: ' . $rx['patient_name'] . ' | Doctor: ' . ($rx['doctor_name'] ?? 'N/A') . ' | Date: ' . formatDate($rx['prescription_date']),
                [['label'=>'Diagnosis','value'=>$rx['diagnosis']??'—'],['label'=>'Patient No','value'=>$rx['patient_no']??'—'],['label'=>'Status','value'=>ucfirst($rx['status'])]],
                [['label'=>'Medicine','width'=>55,'align'=>'L'],['label'=>'Dosage','width'=>28,'align'=>'L'],['label'=>'Frequency','width'=>30,'align'=>'L'],['label'=>'Duration','width'=>28,'align'=>'L'],['label'=>'Instructions','width'=>45,'align'=>'L']],
                $rows,
                'prescription-' . $rx['prescription_no'] . '.pdf',
                [231, 76, 60]
            );
        }
        redirect(APP_URL . '/modules/health/prescription.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$orgId = (int)$user['org_id'];
$today = date('Y-m-d');

$prescriptions = [];
try {
    $stmt = $pdo->prepare("
        SELECT rx.*, CONCAT(p.first_name,' ',p.last_name) AS patient_name,
               CONCAT(d.first_name,' ',d.last_name) AS doctor_name
        FROM health_prescriptions rx
        LEFT JOIN health_patients p ON rx.patient_id=p.id
        LEFT JOIN health_doctors d ON rx.doctor_id=d.id
        WHERE rx.org_id=? ORDER BY rx.prescription_date DESC, rx.id DESC
    ");
    $stmt->execute([$orgId]);
    $prescriptions = $stmt->fetchAll();
} catch (Exception $e) {}

$patients = [];
$doctors  = [];
try {
    $stmt = $pdo->prepare("SELECT id, CONCAT(first_name,' ',last_name) AS name FROM health_patients WHERE org_id=? AND status='active' ORDER BY first_name");
    $stmt->execute([$orgId]); $patients = $stmt->fetchAll();
    $stmt = $pdo->prepare("SELECT id, CONCAT(first_name,' ',last_name) AS name, specialization FROM health_doctors WHERE org_id=? AND status='active' ORDER BY first_name");
    $stmt->execute([$orgId]); $doctors  = $stmt->fetchAll();
} catch (Exception $e) {}

$todayCount   = count(array_filter($prescriptions, fn($r) => $r['prescription_date'] === $today));
$pendingCount = count(array_filter($prescriptions, fn($r) => $r['status'] === 'draft'));
$dispensedCnt = count(array_filter($prescriptions, fn($r) => $r['status'] === 'dispensed' && $r['dispensed_at'] && date('Y-m-d', strtotime($r['dispensed_at'])) === $today));
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-prescription me-2" style="color:<?= $moduleColor ?>"></i>Prescriptions</h4>
    <p class="text-muted mb-0">Manage patient prescriptions and dispensing</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#rxModal">
    <i class="fas fa-plus me-2"></i>New Prescription
  </button>
</div>

<div class="row g-3 mb-4">
  <?php foreach ([
    ["Today's Rx",       $todayCount,   $moduleColor, 'fas fa-prescription'],
    ['Pending Dispense', $pendingCount, '#f39c12',    'fas fa-clock'],
    ['Dispensed Today',  $dispensedCnt, '#27ae60',    'fas fa-check-circle'],
    ['Total',            count($prescriptions), '#95a5a6','fas fa-list'],
  ] as [$l,$v,$c,$i]): ?>
  <div class="col-sm-3">
    <div class="stat-card">
      <div class="stat-icon" style="background:<?= $c ?>20;color:<?= $c ?>"><i class="<?= $i ?>"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $v ?></div><div class="stat-label"><?= $l ?></div></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="card">
  <div class="card-header fw-semibold">Prescription Records</div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0 small" id="rxTable">
        <thead class="table-light">
          <tr><th>Rx No</th><th>Date</th><th>Patient</th><th>Doctor</th><th>Diagnosis</th><th>Medicines</th><th>Status</th><th class="text-end">Actions</th></tr>
        </thead>
        <tbody>
          <?php if (empty($prescriptions)): ?>
          <tr><td colspan="8" class="text-center py-5 text-muted"><i class="fas fa-prescription fa-3x d-block mb-3 opacity-25"></i>No prescriptions yet.</td></tr>
          <?php else: foreach ($prescriptions as $rx):
            $meds = json_decode($rx['medicines'] ?? '[]', true) ?: [];
            $sMap = ['draft'=>'warning','dispensed'=>'success','cancelled'=>'secondary'];
          ?>
          <tr>
            <td class="fw-semibold"><?= e($rx['prescription_no'] ?? '—') ?></td>
            <td><?= formatDate($rx['prescription_date']) ?></td>
            <td><?= e($rx['patient_name'] ?? '—') ?></td>
            <td class="text-muted"><?= e($rx['doctor_name'] ?? '—') ?></td>
            <td><?= e(truncate($rx['diagnosis'] ?? '—', 40)) ?></td>
            <td><?= count($meds) ?> item(s)</td>
            <td><?= statusBadge($rx['status']) ?></td>
            <td class="text-end">
              <button class="btn btn-xs btn-outline-secondary" onclick='editRx(<?= json_encode(['id'=>$rx['id'],'patient_id'=>$rx['patient_id'],'doctor_id'=>$rx['doctor_id'],'prescription_date'=>$rx['prescription_date'],'diagnosis'=>$rx['diagnosis'],'notes'=>$rx['notes'],'medicines'=>$meds]) ?>)'><i class="fas fa-eye"></i></button>
              <?php if ($rx['status'] === 'draft'): ?>
              <form method="POST" class="d-inline">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="dispense">
                <input type="hidden" name="prescription_id" value="<?= $rx['id'] ?>">
                <button class="btn btn-xs btn-success" title="Mark dispensed"><i class="fas fa-check"></i></button>
              </form>
              <?php endif; ?>
              <a href="<?= APP_URL ?>/modules/health/prescription-pdf.php?id=<?= $rx['id'] ?>" target="_blank"
                 class="btn btn-xs btn-outline-danger" title="Professional Print">
                <i class="fas fa-file-prescription"></i>
              </a>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Add/Edit Prescription Modal -->
<div class="modal fade" id="rxModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
        <h5 class="modal-title"><i class="fas fa-prescription me-2"></i>New Prescription</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save_prescription">
        <input type="hidden" name="edit_id" id="editRxId" value="">
        <div class="modal-body row g-3">
          <div class="col-md-4">
            <label class="form-label fw-semibold">Patient <span class="text-danger">*</span></label>
            <select name="patient_id" id="rxPatient" class="form-select" required>
              <option value="">— Select —</option>
              <?php foreach ($patients as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">Doctor</label>
            <select name="doctor_id" id="rxDoctor" class="form-select">
              <option value="">— Select —</option>
              <?php foreach ($doctors as $d): ?><option value="<?= $d['id'] ?>"><?= e($d['name']) ?> <?= $d['specialization'] ? '('.$d['specialization'].')' : '' ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">Date</label>
            <input type="date" name="prescription_date" id="rxDate" class="form-control" value="<?= $today ?>">
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold">Diagnosis / Clinical Notes</label>
            <textarea name="diagnosis" id="rxDiagnosis" class="form-control" rows="2"></textarea>
          </div>

          <!-- Medicines table -->
          <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <label class="form-label fw-semibold mb-0">Medicines <span class="text-danger">*</span></label>
              <button type="button" class="btn btn-sm btn-outline-success" onclick="addMedRow()"><i class="fas fa-plus me-1"></i>Add</button>
            </div>
            <div class="table-responsive">
              <table class="table table-sm align-middle small" id="medTable">
                <thead class="table-light"><tr><th>Medicine Name</th><th>Dosage</th><th>Frequency</th><th>Duration</th><th>Instructions</th><th></th></tr></thead>
                <tbody id="medRows">
                  <tr>
                    <td><input type="text" name="med_name[]" class="form-control form-control-sm" placeholder="e.g. Amoxicillin 500mg" required></td>
                    <td><input type="text" name="med_dosage[]" class="form-control form-control-sm" placeholder="1 tablet"></td>
                    <td><input type="text" name="med_frequency[]" class="form-control form-control-sm" placeholder="3× daily"></td>
                    <td><input type="text" name="med_duration[]" class="form-control form-control-sm" placeholder="5 days"></td>
                    <td><input type="text" name="med_instructions[]" class="form-control form-control-sm" placeholder="After meals"></td>
                    <td><button type="button" class="btn btn-xs btn-outline-danger" onclick="this.closest('tr').remove()"><i class="fas fa-trash"></i></button></td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold">Additional Notes</label>
            <textarea name="notes" id="rxNotes" class="form-control" rows="2"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-2"></i>Save Prescription</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraJs = <<<'JS'
<script>
$("#rxTable").DataTable({pageLength:25, order:[[1,"desc"]]});

function addMedRow() {
  const row = `<tr>
    <td><input type="text" name="med_name[]" class="form-control form-control-sm" placeholder="Medicine name" required></td>
    <td><input type="text" name="med_dosage[]" class="form-control form-control-sm" placeholder="1 tablet"></td>
    <td><input type="text" name="med_frequency[]" class="form-control form-control-sm" placeholder="3× daily"></td>
    <td><input type="text" name="med_duration[]" class="form-control form-control-sm" placeholder="5 days"></td>
    <td><input type="text" name="med_instructions[]" class="form-control form-control-sm" placeholder="After meals"></td>
    <td><button type="button" class="btn btn-xs btn-outline-danger" onclick="this.closest('tr').remove()"><i class="fas fa-trash"></i></button></td>
  </tr>`;
  document.getElementById('medRows').insertAdjacentHTML('beforeend', row);
}

function editRx(rx) {
  document.getElementById('editRxId').value = rx.id;
  document.getElementById('rxPatient').value = rx.patient_id || '';
  document.getElementById('rxDoctor').value = rx.doctor_id || '';
  document.getElementById('rxDate').value = rx.prescription_date || '';
  document.getElementById('rxDiagnosis').value = rx.diagnosis || '';
  document.getElementById('rxNotes').value = rx.notes || '';
  // Fill medicines
  const tbody = document.getElementById('medRows');
  tbody.innerHTML = '';
  (rx.medicines || []).forEach(m => {
    tbody.insertAdjacentHTML('beforeend', `<tr>
      <td><input type="text" name="med_name[]" class="form-control form-control-sm" value="${m.name||''}" required></td>
      <td><input type="text" name="med_dosage[]" class="form-control form-control-sm" value="${m.dosage||''}"></td>
      <td><input type="text" name="med_frequency[]" class="form-control form-control-sm" value="${m.frequency||''}"></td>
      <td><input type="text" name="med_duration[]" class="form-control form-control-sm" value="${m.duration||''}"></td>
      <td><input type="text" name="med_instructions[]" class="form-control form-control-sm" value="${m.instructions||''}"></td>
      <td><button type="button" class="btn btn-xs btn-outline-danger" onclick="this.closest('tr').remove()"><i class="fas fa-trash"></i></button></td>
    </tr>`);
  });
  if (!rx.medicines || !rx.medicines.length) addMedRow();
  new bootstrap.Modal(document.getElementById('rxModal')).show();
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
