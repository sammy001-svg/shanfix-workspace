<?php
$pageTitle = 'My Profile';
require_once __DIR__ . '/../includes/header-teacher.php';

// ── POST: change password ────────────────────────────────────────
$saveMsg = null; $saveErr = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (!$current || !$new || !$confirm) {
            $saveErr = 'All password fields are required.';
        } elseif (strlen($new) < 8) {
            $saveErr = 'New password must be at least 8 characters.';
        } elseif ($new !== $confirm) {
            $saveErr = 'New passwords do not match.';
        } else {
            try {
                $s = $pdo->prepare("SELECT password_hash FROM sch_teachers WHERE id=? AND org_id=? LIMIT 1");
                $s->execute([$tchId, $tchOrgId]);
                $row = $s->fetch();
                if (!$row || !$row['password_hash']) {
                    $saveErr = 'No password is set on your account. Ask your administrator.';
                } elseif (!password_verify($current, $row['password_hash'])) {
                    $saveErr = 'Current password is incorrect.';
                } else {
                    $pdo->prepare("UPDATE sch_teachers SET password_hash=? WHERE id=? AND org_id=?")
                        ->execute([password_hash($new, PASSWORD_BCRYPT), $tchId, $tchOrgId]);
                    $saveMsg = 'Password changed successfully.';
                }
            } catch (Throwable $e) {
                $saveErr = 'Could not update password. Please try again.';
            }
        }
    }
}

// ── Load full teacher record ─────────────────────────────────────
$teacher = $teacherRecord; // loaded by header-teacher.php

// ── Load class and subject assignments ──────────────────────────
$assignments = [];
try {
    $s = $pdo->prepare(
        "SELECT c.name AS class_name, sub.name AS subject_name, sub.code AS subject_code,
                COUNT(st.id) AS student_count
         FROM sch_class_subjects cs
         JOIN sch_classes c ON c.id = cs.class_id
         JOIN sch_subjects sub ON sub.id = cs.subject_id
         LEFT JOIN sch_students st ON st.class_id = cs.class_id AND st.org_id=? AND st.status='active'
         WHERE cs.org_id=? AND cs.staff_id=?
         GROUP BY cs.class_id, cs.subject_id ORDER BY c.name, sub.name"
    );
    $s->execute([$tchOrgId, $tchOrgId, $tchId]);
    $assignments = $s->fetchAll();
} catch (Throwable $e) {}

// ── Attendance stats I've marked (last 30 days) ──────────────────
$attStats = ['days' => 0, 'students' => 0];
try {
    $s = $pdo->prepare(
        "SELECT COUNT(DISTINCT att_date) AS days, COUNT(*) AS records
         FROM sch_attendance WHERE org_id=? AND marked_by=? AND att_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)"
    );
    $s->execute([$tchOrgId, $tchId]);
    $r = $s->fetch();
    $attStats = ['days' => (int)($r['days'] ?? 0), 'students' => (int)($r['records'] ?? 0)];
} catch (Throwable $e) {}

// ── Homework stats ───────────────────────────────────────────────
$hwStats = [];
try {
    $s = $pdo->prepare(
        "SELECT status, COUNT(*) AS cnt FROM sch_homework WHERE org_id=? AND teacher_id=? GROUP BY status"
    );
    $s->execute([$tchOrgId, $tchId]);
    foreach ($s->fetchAll() as $r) $hwStats[$r['status']] = (int)$r['cnt'];
} catch (Throwable $e) {}

$orgSlug = $_SESSION['tch_org_slug'] ?? '';
?>

<?php if ($saveMsg): ?>
<div class="alert alert-success border-0"><i class="fas fa-check-circle me-2"></i><?= e($saveMsg) ?></div>
<?php endif; ?>
<?php if ($saveErr): ?>
<div class="alert alert-danger border-0"><i class="fas fa-exclamation-circle me-2"></i><?= e($saveErr) ?></div>
<?php endif; ?>

<h5 class="fw-bold mb-4"><i class="fas fa-user-circle me-2" style="color:var(--tch-green)"></i>My Profile</h5>

<div class="row g-4">
  <!-- Profile info -->
  <div class="col-lg-4">
    <div class="card border-0 shadow-sm">
      <div class="card-body text-center py-4">
        <?php if (!empty($teacher['photo'])): ?>
        <img src="<?= APP_URL . '/' . e($teacher['photo']) ?>" alt=""
             class="rounded-circle mb-3" width="80" height="80" style="object-fit:cover;border:3px solid var(--tch-green)">
        <?php else: ?>
        <div class="rounded-circle d-inline-flex align-items-center justify-content-center text-white fw-bold mb-3"
             style="width:80px;height:80px;background:var(--tch-green);font-size:1.8rem">
          <?= strtoupper(substr($tchName, 0, 1)) ?>
        </div>
        <?php endif; ?>
        <h5 class="fw-bold mb-1"><?= e($tchName) ?></h5>
        <?php if (!empty($teacher['specialization'])): ?>
        <div class="text-muted small mb-1"><?= e($teacher['specialization']) ?></div>
        <?php endif; ?>
        <?php if (!empty($teacher['employee_id'])): ?>
        <div class="badge bg-light text-dark border"><?= e($teacher['employee_id']) ?></div>
        <?php endif; ?>
      </div>
      <div class="card-body border-top pt-3">
        <table class="table table-sm mb-0">
          <?php $rows = [
            ['fas fa-envelope',      'Email',        $teacher['email'] ?? null],
            ['fas fa-phone',         'Phone',        $teacher['phone'] ?? null],
            ['fas fa-briefcase',     'Contract',     ucfirst($teacher['contract_type'] ?? '')],
            ['fas fa-calendar-alt',  'Joined',       !empty($teacher['join_date']) ? date('d M Y', strtotime($teacher['join_date'])) : null],
            ['fas fa-book',          'Curriculum',   $teacher['curriculum'] ?? null],
            ['fas fa-circle',        'Status',       ucfirst(str_replace('-',' ',$teacher['status'] ?? ''))],
          ]; foreach ($rows as [$ico, $label, $val]): if (!$val) continue; ?>
          <tr>
            <td class="text-muted small" style="width:40px"><i class="fas <?= $ico ?>"></i></td>
            <td class="text-muted small"><?= $label ?></td>
            <td class="fw-semibold small"><?= e($val) ?></td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>
    </div>

    <!-- Activity summary -->
    <div class="card border-0 shadow-sm mt-3">
      <div class="card-header"><h6 class="mb-0 fw-bold small"><i class="fas fa-chart-bar me-1 text-muted"></i>My Activity</h6></div>
      <div class="card-body p-0">
        <?php foreach ([
          ['Attendance Days (30d)',  $attStats['days'],             'fa-clipboard-check', '#27ae60'],
          ['Students Marked (30d)', $attStats['students'],          'fa-users',           '#3498db'],
          ['Active Homework',       $hwStats['active'] ?? 0,       'fa-book-open',       '#f39c12'],
          ['Closed Homework',       $hwStats['closed'] ?? 0,       'fa-lock',            '#6c757d'],
          ['Class Assignments',     count($assignments),            'fa-chalkboard',      '#9b59b6'],
        ] as [$label, $val, $icon, $color]): ?>
        <div class="d-flex align-items-center gap-3 px-3 py-2 border-bottom">
          <i class="fas <?= $icon ?>" style="color:<?= $color ?>;width:16px;text-align:center"></i>
          <div class="small flex-grow-1 text-muted"><?= $label ?></div>
          <div class="fw-bold small" style="color:<?= $color ?>"><?= $val ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- Right panel -->
  <div class="col-lg-8">

    <!-- Assignments -->
    <div class="card border-0 shadow-sm mb-4">
      <div class="card-header"><h6 class="mb-0 fw-bold"><i class="fas fa-chalkboard me-2" style="color:var(--tch-green)"></i>My Class &amp; Subject Assignments</h6></div>
      <div class="card-body p-0">
        <?php if (empty($assignments)): ?>
        <div class="text-center py-4 text-muted small">
          <i class="fas fa-chalkboard fa-2x d-block mb-2 opacity-25"></i>No class assignments yet. Contact your school administrator.
        </div>
        <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm mb-0">
            <thead class="table-light">
              <tr><th>Class</th><th>Subject</th><th class="text-center">Students</th></tr>
            </thead>
            <tbody>
              <?php foreach ($assignments as $a): ?>
              <tr>
                <td class="fw-semibold small"><?= e($a['class_name']) ?></td>
                <td class="small">
                  <?= e($a['subject_name']) ?>
                  <?php if (!empty($a['subject_code'])): ?>
                  <span class="text-muted ms-1" style="font-size:.7rem"><?= e($a['subject_code']) ?></span>
                  <?php endif; ?>
                </td>
                <td class="text-center small"><?= $a['student_count'] ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Change password -->
    <div class="card border-0 shadow-sm">
      <div class="card-header">
        <h6 class="mb-0 fw-bold"><i class="fas fa-lock me-2" style="color:var(--tch-green)"></i>Change Password</h6>
      </div>
      <div class="card-body">
        <form method="POST">
          <input type="hidden" name="action" value="change_password">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label fw-semibold small">Current Password <span class="text-danger">*</span></label>
              <div class="input-group">
                <input type="password" name="current_password" id="pwCurrent" class="form-control" required
                       placeholder="Your current password">
                <button type="button" class="btn btn-outline-secondary" onclick="toggleField('pwCurrent','eyeCurrent')">
                  <i class="fas fa-eye" id="eyeCurrent"></i>
                </button>
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold small">New Password <span class="text-danger">*</span></label>
              <div class="input-group">
                <input type="password" name="new_password" id="pwNew" class="form-control"
                       minlength="8" required placeholder="Minimum 8 characters">
                <button type="button" class="btn btn-outline-secondary" onclick="toggleField('pwNew','eyeNew')">
                  <i class="fas fa-eye" id="eyeNew"></i>
                </button>
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold small">Confirm New Password <span class="text-danger">*</span></label>
              <div class="input-group">
                <input type="password" name="confirm_password" id="pwConfirm" class="form-control"
                       required placeholder="Repeat new password">
                <button type="button" class="btn btn-outline-secondary" onclick="toggleField('pwConfirm','eyeConfirm')">
                  <i class="fas fa-eye" id="eyeConfirm"></i>
                </button>
              </div>
            </div>
          </div>
          <!-- Strength indicator -->
          <div class="mt-2" id="pwStrengthWrap" style="display:none">
            <div class="d-flex align-items-center gap-2">
              <div class="progress flex-grow-1" style="height:4px">
                <div class="progress-bar" id="pwStrengthBar" style="width:0%"></div>
              </div>
              <span class="small" id="pwStrengthLabel" style="min-width:50px"></span>
            </div>
          </div>
          <div class="mt-3">
            <button type="submit" class="btn btn-success px-4">
              <i class="fas fa-save me-1"></i>Change Password
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Portal info -->
    <?php if ($orgSlug): ?>
    <div class="card border-0 shadow-sm mt-4">
      <div class="card-header"><h6 class="mb-0 fw-bold"><i class="fas fa-link me-2 text-muted"></i>Your Portal Link</h6></div>
      <div class="card-body">
        <p class="small text-muted mb-2">Share this link with your school colleagues to access the teacher portal:</p>
        <code class="small d-block p-2 bg-light rounded"><?= APP_URL ?>/teacher/login.php?org=<?= e($orgSlug) ?></code>
        <button class="btn btn-sm btn-outline-secondary mt-2"
                onclick="navigator.clipboard.writeText('<?= APP_URL ?>/teacher/login.php?org=<?= e($orgSlug) ?>');this.textContent='Copied!'">
          <i class="fas fa-copy me-1"></i>Copy Link
        </button>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php
$extraJs = '<script>
function toggleField(inputId, iconId) {
    const f = document.getElementById(inputId);
    const i = document.getElementById(iconId);
    const show = f.type === "password";
    f.type = show ? "text" : "password";
    i.className = show ? "fas fa-eye-slash" : "fas fa-eye";
}
document.getElementById("pwNew")?.addEventListener("input", function(){
    const v = this.value;
    const wrap = document.getElementById("pwStrengthWrap");
    const bar  = document.getElementById("pwStrengthBar");
    const lbl  = document.getElementById("pwStrengthLabel");
    if (!v) { wrap.style.display="none"; return; }
    wrap.style.display = "";
    let score = 0;
    if (v.length >= 8)  score++;
    if (v.length >= 12) score++;
    if (/[A-Z]/.test(v)) score++;
    if (/[0-9]/.test(v)) score++;
    if (/[^A-Za-z0-9]/.test(v)) score++;
    const levels = [
        {pct:20, color:"#e74c3c", label:"Weak"},
        {pct:40, color:"#e67e22", label:"Fair"},
        {pct:60, color:"#f39c12", label:"OK"},
        {pct:80, color:"#27ae60", label:"Strong"},
        {pct:100,color:"#1A8A4E", label:"Very Strong"},
    ];
    const lvl = levels[Math.min(score, 4)];
    bar.style.width  = lvl.pct + "%";
    bar.style.background = lvl.color;
    lbl.textContent  = lvl.label;
    lbl.style.color  = lvl.color;
});
</script>';
require_once __DIR__ . '/../includes/footer-teacher.php';
?>
