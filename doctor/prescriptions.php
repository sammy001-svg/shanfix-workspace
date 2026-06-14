<?php
$pageTitle = 'Prescriptions';
require_once __DIR__ . '/../includes/header-doctor.php';

$prePatientId = (int)($_GET['patient_id'] ?? 0);
$showNew      = isset($_GET['new']);

// Handle POST: save prescription
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save_prescription') {
        $patientId = (int)($_POST['patient_id'] ?? 0);
        $rxDate    = $_POST['prescription_date'] ?? date('Y-m-d');
        $diagnosis = sanitize($_POST['diagnosis'] ?? '');
        $notes     = sanitize($_POST['notes'] ?? '');

        $names  = $_POST['med_name']        ?? [];
        $doses  = $_POST['med_dosage']      ?? [];
        $freqs  = $_POST['med_frequency']   ?? [];
        $durs   = $_POST['med_duration']    ?? [];
        $instrs = $_POST['med_instructions'] ?? [];
        $medicines = [];
        foreach ($names as $i => $name) {
            if (trim($name)) {
                $medicines[] = [
                    'name'         => sanitize($name),
                    'dosage'       => sanitize($doses[$i] ?? ''),
                    'frequency'    => sanitize($freqs[$i] ?? ''),
                    'duration'     => sanitize($durs[$i]  ?? ''),
                    'instructions' => sanitize($instrs[$i] ?? ''),
                ];
            }
        }

        if (!$patientId || empty($medicines)) {
            setFlash('error', 'Patient and at least one medicine are required.');
        } else {
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS health_prescriptions (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    org_id INT NOT NULL, prescription_no VARCHAR(30),
                    patient_id INT, doctor_id INT, prescription_date DATE NOT NULL,
                    diagnosis TEXT, medicines JSON, notes TEXT,
                    status ENUM('draft','dispensed','cancelled') DEFAULT 'draft',
                    created_by INT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_org (org_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                $rxNo = 'RX-' . date('Ym') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
                $pdo->prepare("INSERT INTO health_prescriptions (org_id,prescription_no,patient_id,doctor_id,prescription_date,diagnosis,medicines,notes,created_by)
                               VALUES (?,?,?,?,?,?,?,?,?)")
                    ->execute([$docOrgId, $rxNo, $patientId, $docId, $rxDate, $diagnosis, json_encode($medicines), $notes, $docId]);
                $newRxId = (int)$pdo->lastInsertId();

                setFlash('success', 'Prescription '.$rxNo.' saved.');
                redirect(APP_URL . '/doctor/prescriptions.php?print=' . $newRxId);
            } catch (Throwable $err) {
                setFlash('error', 'Could not save prescription.');
            }
        }
        redirect(APP_URL . '/doctor/prescriptions.php');
    }
}

// Load prescription list for this doctor
$prescriptions = [];
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS health_prescriptions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        org_id INT NOT NULL, prescription_no VARCHAR(30),
        patient_id INT, doctor_id INT, prescription_date DATE NOT NULL,
        diagnosis TEXT, medicines JSON, notes TEXT,
        status ENUM('draft','dispensed','cancelled') DEFAULT 'draft',
        created_by INT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_org (org_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $where  = "rx.doctor_id=? AND rx.org_id=?";
    $params = [$docId, $docOrgId];
    if ($prePatientId) { $where .= " AND rx.patient_id=?"; $params[] = $prePatientId; }

    $s = $pdo->prepare("
        SELECT rx.*, CONCAT(p.first_name,' ',p.last_name) AS patient_name, p.patient_no
        FROM health_prescriptions rx
        JOIN health_patients p ON p.id=rx.patient_id
        WHERE $where
        ORDER BY rx.prescription_date DESC, rx.id DESC
        LIMIT 100
    ");
    $s->execute($params);
    $prescriptions = $s->fetchAll();
} catch (Throwable $e) {}

// Load doctor's patients for the form
$myPatients = [];
try {
    $s = $pdo->prepare("
        SELECT DISTINCT p.id, CONCAT(p.first_name,' ',p.last_name) AS name, p.patient_no
        FROM health_patients p
        WHERE p.org_id=? AND (
            p.id IN (SELECT patient_id FROM health_appointments WHERE doctor_id=? AND org_id=p.org_id)
            OR p.id IN (SELECT patient_id FROM health_records WHERE doctor_id=? AND org_id=p.org_id)
        )
        ORDER BY p.first_name
    ");
    $s->execute([$docOrgId, $docId, $docId]);
    $myPatients = $s->fetchAll();
} catch (Throwable $e) {}

$statusColors = ['draft'=>'secondary','dispensed'=>'success','cancelled'=>'danger'];
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
  <div>
    <h5 class="fw-bold mb-0"><i class="fas fa-prescription me-2" style="color:var(--doc-blue)"></i>Prescriptions</h5>
    <p class="text-muted small mb-0">Write and manage your prescriptions</p>
  </div>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#newRxModal">
    <i class="fas fa-plus me-1"></i>New Prescription
  </button>
</div>

<!-- Prescription list -->
<?php if (empty($prescriptions)): ?>
<div class="card border-0 shadow-sm">
  <div class="card-body text-center py-5 text-muted">
    <i class="fas fa-prescription fa-3x mb-3 d-block opacity-25"></i>
    <p>No prescriptions found. Write your first prescription above.</p>
  </div>
</div>
<?php else: ?>
<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead class="table-light">
          <tr><th>Rx No</th><th>Patient</th><th>Date</th><th>Diagnosis</th><th>Medicines</th><th>Status</th><th>Actions</th></tr>
        </thead>
        <tbody>
          <?php foreach ($prescriptions as $rx):
            $meds = json_decode($rx['medicines'] ?? '[]', true) ?: [];
            $bg   = $statusColors[$rx['status']] ?? 'secondary';
          ?>
          <tr>
            <td class="fw-semibold font-monospace small"><?= e($rx['prescription_no'] ?? '#'.$rx['id']) ?></td>
            <td>
              <div class="fw-semibold"><?= e($rx['patient_name']) ?></div>
              <div class="text-muted small">#<?= e($rx['patient_no'] ?? $rx['patient_id']) ?></div>
            </td>
            <td class="small"><?= date('d M Y', strtotime($rx['prescription_date'])) ?></td>
            <td class="small"><?= e(mb_substr($rx['diagnosis'] ?? '—', 0, 50)) ?></td>
            <td class="small"><?= count($meds) ?> item<?= count($meds)!==1?'s':'' ?></td>
            <td><span class="badge bg-<?= $bg ?>"><?= ucfirst($rx['status']) ?></span></td>
            <td>
              <a href="<?= APP_URL ?>/modules/health/prescription-pdf.php?id=<?= $rx['id'] ?>" target="_blank"
                 class="btn btn-xs btn-outline-secondary" title="Print Prescription">
                <i class="fas fa-print"></i>
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- New Prescription Modal -->
<div class="modal fade <?= $showNew ? 'show' : '' ?>" id="newRxModal" tabindex="-1" <?= $showNew ? 'style="display:block"' : '' ?>>
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="action" value="save_prescription">
        <div class="modal-header">
          <h5 class="modal-title fw-bold"><i class="fas fa-prescription me-2" style="color:var(--doc-blue)"></i>Write Prescription</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3 mb-3">
            <div class="col-md-5">
              <label class="form-label small fw-semibold">Patient <span class="text-danger">*</span></label>
              <select name="patient_id" class="form-select form-select-sm" required>
                <option value="">— Select patient —</option>
                <?php foreach ($myPatients as $mp): ?>
                <option value="<?= $mp['id'] ?>" <?= $prePatientId==$mp['id']?'selected':'' ?>>
                  <?= e($mp['name']) ?> (#<?= e($mp['patient_no'] ?? $mp['id']) ?>)
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label small fw-semibold">Date</label>
              <input type="date" name="prescription_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label small fw-semibold">Diagnosis / Indication</label>
              <input type="text" name="diagnosis" class="form-control form-control-sm" placeholder="e.g., Malaria, Hypertension...">
            </div>
          </div>

          <!-- Medicine rows -->
          <div class="fw-semibold small mb-2">Medicines <span class="text-danger">*</span></div>
          <div id="medRows">
            <div class="med-row row g-2 mb-2 align-items-end">
              <div class="col-md-3">
                <label class="form-label small text-muted mb-1">Medicine Name</label>
                <input type="text" name="med_name[]" class="form-control form-control-sm" placeholder="e.g., Amoxicillin" required>
              </div>
              <div class="col-md-2">
                <label class="form-label small text-muted mb-1">Dosage</label>
                <input type="text" name="med_dosage[]" class="form-control form-control-sm" placeholder="500mg">
              </div>
              <div class="col-md-2">
                <label class="form-label small text-muted mb-1">Frequency</label>
                <input type="text" name="med_frequency[]" class="form-control form-control-sm" placeholder="TDS">
              </div>
              <div class="col-md-2">
                <label class="form-label small text-muted mb-1">Duration</label>
                <input type="text" name="med_duration[]" class="form-control form-control-sm" placeholder="5 days">
              </div>
              <div class="col-md-2">
                <label class="form-label small text-muted mb-1">Instructions</label>
                <input type="text" name="med_instructions[]" class="form-control form-control-sm" placeholder="After meals">
              </div>
              <div class="col-md-1 d-flex align-items-end">
                <button type="button" class="btn btn-sm btn-outline-danger w-100 remove-row" title="Remove" style="display:none">
                  <i class="fas fa-trash"></i>
                </button>
              </div>
            </div>
          </div>
          <button type="button" class="btn btn-sm btn-outline-primary mt-1" id="addMedRow">
            <i class="fas fa-plus me-1"></i>Add Medicine
          </button>

          <div class="mt-3">
            <label class="form-label small fw-semibold">Additional Notes / Instructions</label>
            <textarea name="notes" class="form-control form-control-sm" rows="2"
                      placeholder="Special instructions, dietary advice, follow-up notes..."></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-save me-1"></i>Save &amp; Print</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php if ($showNew): ?><div class="modal-backdrop fade show"></div><?php endif; ?>

<?php
$extraJs = '<script>
document.getElementById("addMedRow").addEventListener("click", function() {
  const container = document.getElementById("medRows");
  const first = container.querySelector(".med-row");
  const clone  = first.cloneNode(true);
  clone.querySelectorAll("input").forEach(i => i.value = "");
  const removeBtn = clone.querySelector(".remove-row");
  removeBtn.style.display = "";
  removeBtn.addEventListener("click", function() { clone.remove(); });
  container.appendChild(clone);
});
document.querySelectorAll(".remove-row").forEach(btn => {
  btn.addEventListener("click", function() { this.closest(".med-row").remove(); });
});
</script>';
?>
<?php require_once __DIR__ . '/../includes/footer-doctor.php'; ?>
