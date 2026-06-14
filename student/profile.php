<?php
$pageTitle = 'My Profile';
require_once __DIR__ . '/../includes/header-student.php';

$error   = '';
$success = '';

// ── Load full student record ──────────────────────────────────────
$student = [];
try {
    $s = $pdo->prepare(
        "SELECT s.*, c.name AS class_name, c.curriculum AS class_curriculum
         FROM sch_students s
         LEFT JOIN sch_classes c ON c.id = s.class_id
         WHERE s.id=? AND s.org_id=? LIMIT 1"
    );
    $s->execute([$stuId, $stuOrgId]);
    $student = $s->fetch() ?: [];
} catch (Throwable $e) {}

// ── POST: change password ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current = $_POST['current_password'] ?? '';
    $new     = $_POST['new_password']     ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (!$current || !$new || !$confirm) {
        $error = 'All password fields are required.';
    } elseif (strlen($new) < 6) {
        $error = 'New password must be at least 6 characters.';
    } elseif ($new !== $confirm) {
        $error = 'New passwords do not match.';
    } else {
        // Verify current password
        try {
            $s = $pdo->prepare("SELECT password_hash FROM sch_students WHERE id=? AND org_id=? LIMIT 1");
            $s->execute([$stuId, $stuOrgId]);
            $hash = $s->fetchColumn();
        } catch (Throwable $e) { $hash = null; }

        if (!$hash || !password_verify($current, $hash)) {
            $error = 'Current password is incorrect.';
        } else {
            try {
                $pdo->prepare("UPDATE sch_students SET password_hash=? WHERE id=? AND org_id=?")
                    ->execute([password_hash($new, PASSWORD_BCRYPT), $stuId, $stuOrgId]);
                $success = 'Password updated successfully.';
            } catch (Throwable $e) {
                $error = 'An error occurred. Please try again.';
            }
        }
    }
}

// ── Attendance summary ────────────────────────────────────────────
$attStats = ['present'=>0,'absent'=>0,'late'=>0,'excused'=>0,'total'=>0];
try {
    $s = $pdo->prepare(
        "SELECT status, COUNT(*) AS cnt FROM sch_attendance
         WHERE student_id=? AND org_id=? AND YEAR(att_date)=YEAR(CURDATE())
         GROUP BY status"
    );
    $s->execute([$stuId, $stuOrgId]);
    foreach ($s->fetchAll() as $r) {
        $attStats[$r['status']] = (int)$r['cnt'];
        $attStats['total'] += (int)$r['cnt'];
    }
} catch (Throwable $e) {}
$attRate = $attStats['total'] > 0
    ? round($attStats['present'] / $attStats['total'] * 100, 1)
    : null;

// ── Class assignments (subjects) ──────────────────────────────────
$subjects = [];
try {
    $s = $pdo->prepare(
        "SELECT sub.name, sub.code, CONCAT(t.first_name,' ',t.last_name) AS teacher_name
         FROM sch_class_subjects cs
         JOIN sch_subjects sub ON sub.id = cs.subject_id
         LEFT JOIN sch_teachers t ON t.id = cs.staff_id
         WHERE cs.class_id=? AND cs.org_id=?
         ORDER BY sub.name"
    );
    $s->execute([$stuClassId, $stuOrgId]);
    $subjects = $s->fetchAll();
} catch (Throwable $e) {}

// ── Portal URL ────────────────────────────────────────────────────
$orgSlug = '';
try {
    $s = $pdo->prepare("SELECT slug FROM organizations WHERE id=? LIMIT 1");
    $s->execute([$stuOrgId]);
    $orgSlug = $s->fetchColumn() ?: '';
} catch (Throwable $e) {}
$portalUrl = APP_URL . '/student/login.php' . ($orgSlug ? '?org=' . rawurlencode($orgSlug) : '');

$genderIcon = ($student['gender'] ?? '') === 'female' ? 'fas fa-venus' : 'fas fa-mars';
?>

<h5 class="fw-bold mb-4"><i class="fas fa-user-circle me-2" style="color:var(--stu-blue)"></i>My Profile</h5>

<?php if ($error): ?>
<div class="alert alert-danger border-0 mb-4"><i class="fas fa-exclamation-circle me-2"></i><?= e($error) ?></div>
<?php endif; ?>
<?php if ($success): ?>
<div class="alert alert-success border-0 mb-4"><i class="fas fa-check-circle me-2"></i><?= e($success) ?></div>
<?php endif; ?>

<div class="row g-4">

  <!-- Left: Profile card + Subjects -->
  <div class="col-12 col-lg-4">

    <!-- Profile card -->
    <div class="card border-0 shadow-sm mb-4">
      <div class="card-body text-center py-4">
        <?php if (!empty($student['photo'])): ?>
        <img src="<?= e(APP_URL . '/assets/uploads/students/' . $student['photo']) ?>"
             alt="Photo" class="rounded-circle mb-3 border"
             style="width:90px;height:90px;object-fit:cover">
        <?php else: ?>
        <div class="rounded-circle d-flex align-items-center justify-content-center text-white fw-bold mx-auto mb-3"
             style="width:90px;height:90px;background:var(--stu-blue);font-size:2rem">
          <?= strtoupper(substr($stuName, 0, 1)) ?>
        </div>
        <?php endif; ?>
        <h6 class="fw-bold mb-0" style="color:var(--stu-navy)"><?= e($stuName) ?></h6>
        <div class="text-muted small mb-2">
          <i class="<?= $genderIcon ?> me-1"></i><?= ucfirst($student['gender'] ?? '') ?>
        </div>
        <div class="d-flex justify-content-center gap-2 flex-wrap">
          <span class="badge" style="background:var(--stu-blue)">
            <i class="fas fa-id-badge me-1"></i><?= e($stuAdmNo ?: '—') ?>
          </span>
          <?php if ($stuClassName): ?>
          <span class="badge bg-secondary"><?= e($stuClassName) ?></span>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Info grid -->
    <div class="card border-0 shadow-sm mb-4">
      <div class="card-header"><h6 class="mb-0 fw-bold" style="font-size:.875rem"><i class="fas fa-info-circle me-2" style="color:var(--stu-blue)"></i>Personal Details</h6></div>
      <div class="card-body p-0">
        <?php
        $info = [
            ['fas fa-calendar',         'Date of Birth',  !empty($student['dob']) ? date('d M Y', strtotime($student['dob'])) : '—'],
            ['fas fa-graduation-cap',   'Curriculum',     e($student['class_curriculum'] ?? $student['curriculum'] ?? '—')],
            ['fas fa-map-marker-alt',   'Address',        !empty($student['address']) ? $student['address'] : '—'],
            ['fas fa-flag',             'Nationality',    !empty($student['nationality']) ? $student['nationality'] : '—'],
            ['fas fa-tint',             'Blood Group',    !empty($student['blood_group']) ? $student['blood_group'] : '—'],
            ['fas fa-calendar-check',   'Admitted',       !empty($student['admitted_on']) ? date('d M Y', strtotime($student['admitted_on'])) : '—'],
        ];
        foreach ($info as [$icon, $label, $val]):
        ?>
        <div class="d-flex align-items-start gap-3 px-3 py-2 border-bottom">
          <i class="<?= $icon ?> mt-1 flex-shrink-0" style="color:var(--stu-blue);width:16px;font-size:.8rem"></i>
          <div class="flex-grow-1">
            <div style="font-size:.68rem;color:#9ca3af;text-transform:uppercase;letter-spacing:.3px"><?= $label ?></div>
            <div class="small fw-semibold" style="color:var(--stu-navy)"><?= $val ?></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Subjects -->
    <?php if (!empty($subjects)): ?>
    <div class="card border-0 shadow-sm">
      <div class="card-header"><h6 class="mb-0 fw-bold" style="font-size:.875rem"><i class="fas fa-book me-2" style="color:var(--stu-blue)"></i>My Subjects</h6></div>
      <div class="card-body p-0">
        <?php foreach ($subjects as $sub): ?>
        <div class="d-flex align-items-center justify-content-between px-3 py-2 border-bottom">
          <div>
            <div class="small fw-semibold"><?= e($sub['name']) ?></div>
            <?php if (!empty($sub['code'])): ?>
            <div class="text-muted" style="font-size:.68rem"><?= e($sub['code']) ?></div>
            <?php endif; ?>
          </div>
          <?php if (!empty($sub['teacher_name'])): ?>
          <div class="text-muted" style="font-size:.7rem"><i class="fas fa-user-tie me-1"></i><?= e($sub['teacher_name']) ?></div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

  </div>

  <!-- Right: Stats + Password change + Emergency contacts -->
  <div class="col-12 col-lg-8">

    <!-- Attendance stats -->
    <div class="card border-0 shadow-sm mb-4">
      <div class="card-header"><h6 class="mb-0 fw-bold" style="font-size:.875rem"><i class="fas fa-chart-bar me-2" style="color:var(--stu-blue)"></i>Attendance Summary (<?= date('Y') ?>)</h6></div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-6 col-md-3 text-center">
            <div class="fw-bold fs-4 <?= $attRate!==null?($attRate>=80?'text-success':($attRate>=60?'text-warning':'text-danger')):'' ?>">
              <?= $attRate !== null ? $attRate . '%' : '—' ?>
            </div>
            <div class="text-muted small">Attendance Rate</div>
          </div>
          <?php foreach (['present'=>['text-success','Present'],'absent'=>['text-danger','Absent'],'late'=>['text-warning','Late']] as $st=>[$cls,$lbl]): ?>
          <div class="col-6 col-md-3 text-center">
            <div class="fw-bold fs-4 <?= $cls ?>"><?= $attStats[$st] ?></div>
            <div class="text-muted small"><?= $lbl ?> Days</div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php if ($attRate !== null): ?>
        <div class="mt-3">
          <div class="d-flex justify-content-between small mb-1">
            <span class="text-muted">Attendance rate</span>
            <span class="fw-semibold <?= $attRate>=80?'text-success':($attRate>=60?'text-warning':'text-danger') ?>"><?= $attRate ?>%</span>
          </div>
          <div class="progress" style="height:6px">
            <div class="progress-bar <?= $attRate>=80?'bg-success':($attRate>=60?'bg-warning':'bg-danger') ?>"
                 style="width:<?= $attRate ?>%"></div>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Emergency contacts (if any) -->
    <?php if (!empty($student['emergency_contact']) || !empty($student['parent_name'])): ?>
    <div class="card border-0 shadow-sm mb-4">
      <div class="card-header"><h6 class="mb-0 fw-bold" style="font-size:.875rem"><i class="fas fa-phone me-2" style="color:var(--stu-blue)"></i>Emergency Contacts</h6></div>
      <div class="card-body">
        <div class="row g-3">
          <?php if (!empty($student['parent_name'])): ?>
          <div class="col-sm-6">
            <div class="small text-muted mb-1">Parent / Guardian</div>
            <div class="fw-semibold small"><?= e($student['parent_name']) ?></div>
            <?php if (!empty($student['parent_phone'])): ?>
            <div class="text-muted" style="font-size:.78rem"><i class="fas fa-phone me-1"></i><?= e($student['parent_phone']) ?></div>
            <?php endif; ?>
          </div>
          <?php endif; ?>
          <?php if (!empty($student['emergency_contact'])): ?>
          <div class="col-sm-6">
            <div class="small text-muted mb-1">Emergency Contact</div>
            <div class="fw-semibold small"><?= e($student['emergency_contact']) ?></div>
            <?php if (!empty($student['emergency_phone'])): ?>
            <div class="text-muted" style="font-size:.78rem"><i class="fas fa-phone me-1"></i><?= e($student['emergency_phone']) ?></div>
            <?php endif; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Change password -->
    <div class="card border-0 shadow-sm mb-4">
      <div class="card-header"><h6 class="mb-0 fw-bold" style="font-size:.875rem"><i class="fas fa-lock me-2" style="color:var(--stu-blue)"></i>Change Password</h6></div>
      <div class="card-body">
        <form method="POST" autocomplete="off">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label small fw-semibold">Current Password <span class="text-danger">*</span></label>
              <input type="password" name="current_password" class="form-control form-control-sm" required placeholder="Your current password">
            </div>
            <div class="col-sm-6">
              <label class="form-label small fw-semibold">New Password <span class="text-danger">*</span></label>
              <input type="password" name="new_password" id="newPwd" class="form-control form-control-sm"
                     required minlength="6" placeholder="At least 6 characters"
                     oninput="updateStrength(this.value)">
              <!-- Strength meter -->
              <div class="progress mt-2" style="height:4px">
                <div id="strengthBar" class="progress-bar" style="width:0%;transition:width .3s"></div>
              </div>
              <div id="strengthLabel" class="text-muted mt-1" style="font-size:.68rem"></div>
            </div>
            <div class="col-sm-6">
              <label class="form-label small fw-semibold">Confirm New Password <span class="text-danger">*</span></label>
              <input type="password" name="confirm_password" id="confirmPwd" class="form-control form-control-sm"
                     required placeholder="Repeat new password"
                     oninput="checkMatch()">
              <div id="matchLabel" class="mt-1" style="font-size:.68rem"></div>
            </div>
            <div class="col-12">
              <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" id="showPwd"
                       onchange="['newPwd','confirmPwd'].forEach(id=>{const el=document.getElementById(id);if(el)el.type=this.checked?'text':'password'})">
                <label class="form-check-label small" for="showPwd">Show passwords</label>
              </div>
              <button type="submit" class="btn btn-sm btn-primary px-4">
                <i class="fas fa-save me-1"></i>Update Password
              </button>
            </div>
          </div>
        </form>
      </div>
    </div>

    <!-- Portal info -->
    <div class="card border-0 shadow-sm">
      <div class="card-header"><h6 class="mb-0 fw-bold" style="font-size:.875rem"><i class="fas fa-link me-2" style="color:var(--stu-blue)"></i>Portal Access</h6></div>
      <div class="card-body">
        <div class="mb-2">
          <div class="small text-muted mb-1">Student Portal URL</div>
          <div class="d-flex align-items-center gap-2 flex-wrap">
            <code class="small bg-light px-2 py-1 rounded" style="word-break:break-all"><?= e($portalUrl) ?></code>
            <button class="btn btn-xs btn-outline-secondary btn-sm py-0 px-2"
                    style="font-size:.7rem"
                    onclick="navigator.clipboard.writeText('<?= e($portalUrl) ?>');this.textContent='Copied!'">
              Copy
            </button>
          </div>
        </div>
        <div class="small text-muted">
          <i class="fas fa-info-circle me-1"></i>
          Login using your admission number <strong><?= e($stuAdmNo) ?></strong> and your password.
        </div>
      </div>
    </div>

  </div>
</div>

<?php
$extraJs = <<<'JS'
<script>
function updateStrength(val) {
    const bar   = document.getElementById('strengthBar');
    const label = document.getElementById('strengthLabel');
    let score = 0;
    if (val.length >= 6)  score++;
    if (val.length >= 10) score++;
    if (/[A-Z]/.test(val)) score++;
    if (/[0-9]/.test(val)) score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;
    const levels = [
        [0,  'bg-secondary', ''],
        [20, 'bg-danger',    'Weak'],
        [40, 'bg-warning',   'Fair'],
        [60, 'bg-info',      'Good'],
        [80, 'bg-success',   'Strong'],
        [100,'bg-success',   'Very Strong'],
    ];
    const [pct, cls, text] = levels[Math.min(score, levels.length - 1)];
    bar.style.width   = pct + '%';
    bar.className     = 'progress-bar ' + cls;
    label.textContent = text;
    label.className   = 'mt-1 ' + cls.replace('bg-','text-');
}
function checkMatch() {
    const np  = document.getElementById('newPwd').value;
    const cp  = document.getElementById('confirmPwd').value;
    const lbl = document.getElementById('matchLabel');
    if (!cp) { lbl.textContent = ''; return; }
    lbl.textContent = np === cp ? '✓ Passwords match' : '✗ Passwords do not match';
    lbl.className   = np === cp ? 'mt-1 text-success' : 'mt-1 text-danger';
}
</script>
JS;
require_once __DIR__ . '/../includes/footer-student.php';
