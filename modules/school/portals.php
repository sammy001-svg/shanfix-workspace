<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireLogin();
require_once __DIR__ . '/_nav.php';

$user  = currentUser();
$orgId = (int)$user['org_id'];

// Ensure migration columns exist (safe no-op if already present)
try { $pdo->exec("ALTER TABLE sch_teachers ADD COLUMN IF NOT EXISTS password_hash VARCHAR(255) DEFAULT NULL"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE sch_teachers ADD COLUMN IF NOT EXISTS last_login DATETIME DEFAULT NULL"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE sch_teachers ADD COLUMN IF NOT EXISTS portal_enabled TINYINT(1) NOT NULL DEFAULT 1"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE sch_parents ADD COLUMN IF NOT EXISTS portal_enabled TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE sch_parents ADD COLUMN IF NOT EXISTS parent_pin VARCHAR(255) DEFAULT NULL"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE sch_parents ADD COLUMN IF NOT EXISTS last_login DATETIME DEFAULT NULL"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE sch_students ADD COLUMN IF NOT EXISTS password_hash VARCHAR(255) DEFAULT NULL"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE sch_students ADD COLUMN IF NOT EXISTS portal_enabled TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE sch_students ADD COLUMN IF NOT EXISTS last_login DATETIME DEFAULT NULL"); } catch (Throwable $e) {}

// ── POST handlers ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    // ── Teacher portal: set password ──────────────────────────────
    if ($action === 'teacher_set_password') {
        $tchId    = (int)($_POST['teacher_id'] ?? 0);
        $password = $_POST['new_password'] ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';
        requireOrgOwnership('sch_teachers', $tchId, $orgId);
        if (strlen($password) < 8) {
            setFlash('danger', 'Password must be at least 8 characters.');
        } elseif ($password !== $confirm) {
            setFlash('danger', 'Passwords do not match.');
        } else {
            $pdo->prepare("UPDATE sch_teachers SET password_hash=?, portal_enabled=1 WHERE id=? AND org_id=?")
                ->execute([password_hash($password, PASSWORD_BCRYPT), $tchId, $orgId]);
            setFlash('success', 'Teacher password set. Portal access enabled.');
        }
        redirect('portals.php?tab=teachers');
    }

    // ── Teacher portal: toggle access ─────────────────────────────
    if ($action === 'teacher_toggle') {
        $tchId  = (int)($_POST['teacher_id'] ?? 0);
        $enable = (int)(bool)($_POST['enable'] ?? 0);
        requireOrgOwnership('sch_teachers', $tchId, $orgId);
        $pdo->prepare("UPDATE sch_teachers SET portal_enabled=? WHERE id=? AND org_id=?")
            ->execute([$enable, $tchId, $orgId]);
        setFlash('success', 'Teacher portal access ' . ($enable ? 'enabled' : 'disabled') . '.');
        redirect('portals.php?tab=teachers');
    }

    // ── Teacher portal: revoke (clear password + disable) ─────────
    if ($action === 'teacher_revoke') {
        $tchId = (int)($_POST['teacher_id'] ?? 0);
        requireOrgOwnership('sch_teachers', $tchId, $orgId);
        $pdo->prepare("UPDATE sch_teachers SET password_hash=NULL, portal_enabled=0, last_login=NULL WHERE id=? AND org_id=?")
            ->execute([$tchId, $orgId]);
        setFlash('success', 'Teacher portal access revoked and password cleared.');
        redirect('portals.php?tab=teachers');
    }

    // ── Parent portal: set PIN ────────────────────────────────────
    if ($action === 'parent_set_pin') {
        $parId = (int)($_POST['parent_id'] ?? 0);
        $pin   = trim($_POST['parent_pin'] ?? '');
        requireOrgOwnership('sch_parents', $parId, $orgId);
        if (!ctype_digit($pin) || strlen($pin) < 4 || strlen($pin) > 8) {
            setFlash('danger', 'PIN must be 4–8 digits (numbers only).');
        } else {
            $pdo->prepare("UPDATE sch_parents SET parent_pin=?, portal_enabled=1 WHERE id=? AND org_id=?")
                ->execute([password_hash($pin, PASSWORD_BCRYPT), $parId, $orgId]);
            setFlash('success', 'Parent portal PIN set. Parent can now sign in to the portal.');
        }
        redirect('portals.php?tab=parents');
    }

    // ── Parent portal: revoke PIN ─────────────────────────────────
    if ($action === 'parent_revoke_pin') {
        $parId = (int)($_POST['parent_id'] ?? 0);
        requireOrgOwnership('sch_parents', $parId, $orgId);
        $pdo->prepare("UPDATE sch_parents SET parent_pin=NULL, portal_enabled=0 WHERE id=? AND org_id=?")
            ->execute([$parId, $orgId]);
        setFlash('success', 'Parent portal access revoked.');
        redirect('portals.php?tab=parents');
    }

    // ── Student portal: set password ──────────────────────────────
    if ($action === 'student_set_password') {
        $stuId   = (int)($_POST['student_id'] ?? 0);
        $password = $_POST['new_password'] ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';
        requireOrgOwnership('sch_students', $stuId, $orgId);
        if (strlen($password) < 6) {
            setFlash('danger', 'Password must be at least 6 characters.');
        } elseif ($password !== $confirm) {
            setFlash('danger', 'Passwords do not match.');
        } else {
            $pdo->prepare("UPDATE sch_students SET password_hash=?, portal_enabled=1 WHERE id=? AND org_id=?")
                ->execute([password_hash($password, PASSWORD_BCRYPT), $stuId, $orgId]);
            setFlash('success', 'Student password set. Portal access enabled.');
        }
        redirect('portals.php?tab=students');
    }

    // ── Student portal: revoke ────────────────────────────────────
    if ($action === 'student_revoke') {
        $stuId = (int)($_POST['student_id'] ?? 0);
        requireOrgOwnership('sch_students', $stuId, $orgId);
        $pdo->prepare("UPDATE sch_students SET password_hash=NULL, portal_enabled=0, last_login=NULL WHERE id=? AND org_id=?")
            ->execute([$stuId, $orgId]);
        setFlash('success', 'Student portal access revoked.');
        redirect('portals.php?tab=students');
    }
}

// ── Load data ─────────────────────────────────────────────────────
$teachers = [];
try {
    $s = $pdo->prepare(
        "SELECT id, first_name, last_name, email, employee_id, status,
                portal_enabled, last_login,
                CASE WHEN password_hash IS NOT NULL THEN 1 ELSE 0 END AS has_password
         FROM sch_teachers WHERE org_id=? AND status='active'
         ORDER BY first_name, last_name"
    );
    $s->execute([$orgId]);
    $teachers = $s->fetchAll();
} catch (Throwable $e) {}

$parents = [];
try {
    $s = $pdo->prepare(
        "SELECT p.id, p.first_name, p.last_name, p.email, p.phone,
                p.portal_enabled, p.last_login,
                CASE WHEN p.parent_pin IS NOT NULL THEN 1 ELSE 0 END AS has_pin,
                GROUP_CONCAT(s.first_name, ' ', s.last_name SEPARATOR ', ') AS children
         FROM sch_parents p
         LEFT JOIN sch_student_parents sp ON sp.parent_id = p.id
         LEFT JOIN sch_students s ON s.id = sp.student_id AND s.org_id = p.org_id
         WHERE p.org_id=?
         GROUP BY p.id ORDER BY p.first_name, p.last_name"
    );
    $s->execute([$orgId]);
    $parents = $s->fetchAll();
} catch (Throwable $e) {}

$students = [];
try {
    $s = $pdo->prepare(
        "SELECT s.id, s.admission_no, s.first_name, s.last_name, s.gender,
                s.portal_enabled, s.last_login,
                CASE WHEN s.password_hash IS NOT NULL THEN 1 ELSE 0 END AS has_password,
                c.name AS class_name
         FROM sch_students s
         LEFT JOIN sch_classes c ON c.id = s.class_id
         WHERE s.org_id=? AND s.status='active'
         ORDER BY c.name, s.first_name, s.last_name"
    );
    $s->execute([$orgId]);
    $students = $s->fetchAll();
} catch (Throwable $e) {}

// Stats
$tchTotal     = count($teachers);
$tchEnabled   = count(array_filter($teachers, fn($t) => $t['portal_enabled'] && $t['has_password']));
$tchNoPass    = count(array_filter($teachers, fn($t) => !$t['has_password']));
$parTotal     = count($parents);
$parEnabled   = count(array_filter($parents, fn($p) => $p['portal_enabled'] && $p['has_pin']));
$stuTotal     = count($students);
$stuEnabled   = count(array_filter($students, fn($s) => $s['portal_enabled'] && $s['has_password']));
$stuNoPass    = count(array_filter($students, fn($s) => !$s['has_password']));

// Org slug for portal URLs
$orgSlug = '';
try {
    $s = $pdo->prepare("SELECT slug FROM organizations WHERE id=? LIMIT 1");
    $s->execute([$orgId]);
    $orgSlug = $s->fetchColumn() ?: '';
} catch (Throwable $e) {}

$activeTab = in_array($_GET['tab'] ?? '', ['teachers','parents','students']) ? $_GET['tab'] : 'teachers';

require_once __DIR__ . '/../../includes/header-module.php';
?>
<?= flashAlert() ?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-key me-2" style="color:<?= $moduleColor ?>"></i>Portal Access Management</h4>
    <p class="text-muted mb-0">Manage teacher and parent portal access — set passwords, PINs and enable/disable accounts</p>
  </div>
</div>

<!-- KPI strip -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-chalkboard-teacher"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $tchEnabled ?> / <?= $tchTotal ?></div><div class="stat-label">Teachers with Portal Access</div></div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="stat-icon <?= $tchNoPass > 0 ? 'warning-bg' : 'navy-bg' ?>"><i class="fas fa-lock"></i></div>
      <div class="stat-body"><div class="stat-value <?= $tchNoPass > 0 ? 'text-warning' : '' ?>"><?= $tchNoPass ?></div><div class="stat-label">Teachers Without Password</div></div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-users"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $parEnabled ?> / <?= $parTotal ?></div><div class="stat-label">Parents with Portal Access</div></div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-user-graduate"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $stuEnabled ?> / <?= $stuTotal ?></div><div class="stat-label">Students with Portal Access</div></div>
    </div>
  </div>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-4">
  <li class="nav-item">
    <a href="?tab=teachers" class="nav-link <?= $activeTab==='teachers' ? 'active' : '' ?>">
      <i class="fas fa-chalkboard-teacher me-1"></i>Teacher Portal
      <span class="badge bg-<?= $tchNoPass>0?'warning':'success' ?> ms-1"><?= $tchEnabled ?>/<?= $tchTotal ?></span>
    </a>
  </li>
  <li class="nav-item">
    <a href="?tab=parents" class="nav-link <?= $activeTab==='parents' ? 'active' : '' ?>">
      <i class="fas fa-users me-1"></i>Parent Portal
      <span class="badge bg-success ms-1"><?= $parEnabled ?>/<?= $parTotal ?></span>
    </a>
  </li>
  <li class="nav-item">
    <a href="?tab=students" class="nav-link <?= $activeTab==='students' ? 'active' : '' ?>">
      <i class="fas fa-user-graduate me-1"></i>Student Portal
      <span class="badge bg-<?= $stuNoPass>0?'warning':'success' ?> ms-1"><?= $stuEnabled ?>/<?= $stuTotal ?></span>
    </a>
  </li>
</ul>

<?php if ($activeTab === 'teachers'): ?>
<!-- ── Teacher Portal Tab ─────────────────────────────────────── -->

<?php if ($orgSlug): ?>
<div class="alert alert-info border-0 mb-3">
  <i class="fas fa-link me-2"></i>
  <strong>Teacher login URL:</strong>
  <code class="ms-2"><?= APP_URL ?>/teacher/login.php?org=<?= e($orgSlug) ?></code>
  <button class="btn btn-sm btn-outline-secondary ms-2" onclick="navigator.clipboard.writeText('<?= APP_URL ?>/teacher/login.php?org=<?= e($orgSlug) ?>');this.textContent='Copied!'">Copy</button>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0 fw-bold"><i class="fas fa-chalkboard-teacher me-2" style="color:<?= $moduleColor ?>"></i>Active Teaching Staff (<?= $tchTotal ?>)</h6>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0 align-middle">
        <thead class="table-light">
          <tr>
            <th>Teacher</th>
            <th>Email</th>
            <th class="text-center">Password Set</th>
            <th class="text-center">Portal Access</th>
            <th class="text-center d-none d-md-table-cell">Last Login</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($teachers)): ?>
          <tr><td colspan="6" class="text-center text-muted py-5">No active teachers found.</td></tr>
          <?php else: foreach ($teachers as $t): ?>
          <tr>
            <td>
              <div class="fw-semibold small"><?= e($t['first_name'] . ' ' . $t['last_name']) ?></div>
              <div class="text-muted" style="font-size:.7rem"><?= e($t['employee_id'] ?? '') ?></div>
            </td>
            <td class="small text-muted"><?= e($t['email'] ?? '—') ?></td>
            <td class="text-center">
              <?php if ($t['has_password']): ?>
              <span class="badge bg-success"><i class="fas fa-check me-1"></i>Yes</span>
              <?php else: ?>
              <span class="badge bg-danger"><i class="fas fa-times me-1"></i>No</span>
              <?php endif; ?>
            </td>
            <td class="text-center">
              <?php if ($t['portal_enabled'] && $t['has_password']): ?>
              <span class="badge bg-success">Active</span>
              <?php elseif ($t['has_password']): ?>
              <span class="badge bg-warning text-dark">Disabled</span>
              <?php else: ?>
              <span class="badge bg-secondary">No Password</span>
              <?php endif; ?>
            </td>
            <td class="text-center small text-muted d-none d-md-table-cell">
              <?= $t['last_login'] ? date('d M Y H:i', strtotime($t['last_login'])) : '&mdash;' ?>
            </td>
            <td class="text-center">
              <div class="btn-group btn-group-sm">
                <button class="btn btn-outline-primary" title="Set/Reset Password"
                        onclick="openSetPassword(<?= $t['id'] ?>, '<?= e($t['first_name'] . ' ' . $t['last_name']) ?>')"
                        data-bs-toggle="modal" data-bs-target="#pwModal">
                  <i class="fas fa-key"></i>
                </button>
                <?php if ($t['has_password']): ?>
                <form method="POST" class="d-inline">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="teacher_toggle">
                  <input type="hidden" name="teacher_id" value="<?= $t['id'] ?>">
                  <input type="hidden" name="enable" value="<?= $t['portal_enabled'] ? '0' : '1' ?>">
                  <button type="submit" class="btn btn-outline-<?= $t['portal_enabled'] ? 'warning' : 'success' ?>"
                          title="<?= $t['portal_enabled'] ? 'Disable Access' : 'Enable Access' ?>">
                    <i class="fas fa-<?= $t['portal_enabled'] ? 'lock' : 'unlock' ?>"></i>
                  </button>
                </form>
                <form method="POST" class="d-inline"
                      onsubmit="return confirm('Revoke portal access for <?= e($t['first_name']) ?>? Their password will be cleared.')">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="teacher_revoke">
                  <input type="hidden" name="teacher_id" value="<?= $t['id'] ?>">
                  <button type="submit" class="btn btn-outline-danger" title="Revoke Access">
                    <i class="fas fa-user-slash"></i>
                  </button>
                </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Set Password Modal -->
<div class="modal fade" id="pwModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="teacher_set_password">
        <input type="hidden" name="teacher_id" id="pwTeacherId">
        <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
          <h5 class="modal-title"><i class="fas fa-key me-2"></i>Set Teacher Password</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="text-muted small mb-3">Setting a password for <strong id="pwTeacherName"></strong>. They will use their email address and this password to log in to the teacher portal.</p>
          <div class="mb-3">
            <label class="form-label fw-semibold small">New Password <span class="text-danger">*</span></label>
            <input type="password" name="new_password" id="pwNew" class="form-control" minlength="8" required placeholder="Minimum 8 characters">
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold small">Confirm Password <span class="text-danger">*</span></label>
            <input type="password" name="confirm_password" id="pwConfirm" class="form-control" required placeholder="Repeat the password">
          </div>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="pwShow" onchange="togglePwVis()">
            <label class="form-check-label small" for="pwShow">Show passwords</label>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success btn-sm"><i class="fas fa-save me-1"></i>Set Password &amp; Enable Access</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php elseif ($activeTab === 'parents'): ?>
<!-- ── Parent Portal Tab ──────────────────────────────────────── -->

<?php if ($orgSlug): ?>
<div class="alert alert-info border-0 mb-3">
  <i class="fas fa-link me-2"></i>
  <strong>Parent login URL:</strong>
  <code class="ms-2"><?= APP_URL ?>/parent/login.php?org=<?= e($orgSlug) ?></code>
  <button class="btn btn-sm btn-outline-secondary ms-2" onclick="navigator.clipboard.writeText('<?= APP_URL ?>/parent/login.php?org=<?= e($orgSlug) ?>');this.textContent='Copied!'">Copy</button>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0 fw-bold"><i class="fas fa-users me-2" style="color:<?= $moduleColor ?>"></i>Parents / Guardians (<?= $parTotal ?>)</h6>
    <a href="parents.php" class="btn btn-sm btn-outline-secondary">Manage Parents</a>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0 align-middle">
        <thead class="table-light">
          <tr>
            <th>Parent</th>
            <th>Email / Phone</th>
            <th class="d-none d-md-table-cell">Children</th>
            <th class="text-center">PIN Set</th>
            <th class="text-center">Portal Access</th>
            <th class="text-center d-none d-md-table-cell">Last Login</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($parents)): ?>
          <tr><td colspan="7" class="text-center text-muted py-5">No parents added yet. Add parents in the <a href="parents.php">Parents</a> section.</td></tr>
          <?php else: foreach ($parents as $p): ?>
          <tr>
            <td>
              <div class="fw-semibold small"><?= e($p['first_name'] . ' ' . $p['last_name']) ?></div>
            </td>
            <td class="small">
              <?= e($p['email'] ?? '—') ?>
              <?php if ($p['phone']): ?><br><span class="text-muted"><?= e($p['phone']) ?></span><?php endif; ?>
            </td>
            <td class="small text-muted d-none d-md-table-cell">
              <?= e($p['children'] ?? '—') ?>
            </td>
            <td class="text-center">
              <?php if ($p['has_pin']): ?>
              <span class="badge bg-success"><i class="fas fa-check me-1"></i>Yes</span>
              <?php else: ?>
              <span class="badge bg-secondary">No PIN</span>
              <?php endif; ?>
            </td>
            <td class="text-center">
              <?php if ($p['portal_enabled'] && $p['has_pin']): ?>
              <span class="badge bg-success">Active</span>
              <?php else: ?>
              <span class="badge bg-secondary">Inactive</span>
              <?php endif; ?>
            </td>
            <td class="text-center small text-muted d-none d-md-table-cell">
              <?= $p['last_login'] ? date('d M Y H:i', strtotime($p['last_login'])) : '&mdash;' ?>
            </td>
            <td class="text-center">
              <div class="btn-group btn-group-sm">
                <button class="btn btn-outline-primary" title="Set/Reset PIN"
                        onclick="openSetPin(<?= $p['id'] ?>, '<?= e($p['first_name'] . ' ' . $p['last_name']) ?>')"
                        data-bs-toggle="modal" data-bs-target="#pinModal">
                  <i class="fas fa-key"></i>
                </button>
                <?php if ($p['has_pin']): ?>
                <form method="POST" class="d-inline"
                      onsubmit="return confirm('Revoke portal access for <?= e($p['first_name']) ?>? Their PIN will be cleared.')">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="parent_revoke_pin">
                  <input type="hidden" name="parent_id" value="<?= $p['id'] ?>">
                  <button type="submit" class="btn btn-outline-danger" title="Revoke Access">
                    <i class="fas fa-user-slash"></i>
                  </button>
                </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Set PIN Modal -->
<div class="modal fade" id="pinModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="parent_set_pin">
        <input type="hidden" name="parent_id" id="pinParentId">
        <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
          <h5 class="modal-title"><i class="fas fa-key me-2"></i>Set Parent Portal PIN</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="text-muted small mb-3">
            Setting a PIN for <strong id="pinParentName"></strong>. They will use the student's admission number and this PIN to log in to the parent portal.
          </p>
          <div class="mb-3">
            <label class="form-label fw-semibold small">PIN (4–8 digits) <span class="text-danger">*</span></label>
            <input type="text" name="parent_pin" id="pinInput" class="form-control"
                   pattern="[0-9]{4,8}" maxlength="8" inputmode="numeric"
                   placeholder="e.g. 1234" required>
            <div class="form-text">Numbers only, 4–8 digits.</div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success btn-sm"><i class="fas fa-save me-1"></i>Set PIN &amp; Enable Access</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php elseif ($activeTab === 'students'): ?>
<!-- ── Student Portal Tab ─────────────────────────────────────── -->

<?php if ($orgSlug): ?>
<div class="alert alert-info border-0 mb-3">
  <i class="fas fa-link me-2"></i>
  <strong>Student login URL:</strong>
  <code class="ms-2"><?= APP_URL ?>/student/login.php?org=<?= e($orgSlug) ?></code>
  <button class="btn btn-sm btn-outline-secondary ms-2" onclick="navigator.clipboard.writeText('<?= APP_URL ?>/student/login.php?org=<?= e($orgSlug) ?>');this.textContent='Copied!'">Copy</button>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
    <h6 class="mb-0 fw-bold"><i class="fas fa-user-graduate me-2" style="color:<?= $moduleColor ?>"></i>Active Students (<?= $stuTotal ?>)</h6>
    <?php if ($stuNoPass > 0): ?>
    <span class="badge bg-warning text-dark"><?= $stuNoPass ?> student<?= $stuNoPass!==1?'s':'' ?> without password</span>
    <?php endif; ?>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0 align-middle">
        <thead class="table-light">
          <tr>
            <th>Student</th>
            <th>Admission No</th>
            <th class="d-none d-md-table-cell">Class</th>
            <th class="text-center">Password</th>
            <th class="text-center">Portal Access</th>
            <th class="text-center d-none d-lg-table-cell">Last Login</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($students)): ?>
          <tr><td colspan="7" class="text-center text-muted py-5">No active students found.</td></tr>
          <?php else: foreach ($students as $stu): ?>
          <tr>
            <td>
              <div class="fw-semibold small"><?= e($stu['first_name'] . ' ' . $stu['last_name']) ?></div>
              <div class="text-muted" style="font-size:.7rem"><?= ucfirst($stu['gender'] ?? '') ?></div>
            </td>
            <td class="small fw-semibold" style="font-family:monospace"><?= e($stu['admission_no'] ?? '—') ?></td>
            <td class="small text-muted d-none d-md-table-cell"><?= e($stu['class_name'] ?? '—') ?></td>
            <td class="text-center">
              <?php if ($stu['has_password']): ?>
              <span class="badge bg-success"><i class="fas fa-check me-1"></i>Set</span>
              <?php else: ?>
              <span class="badge bg-secondary">Not set</span>
              <?php endif; ?>
            </td>
            <td class="text-center">
              <?php if ($stu['portal_enabled'] && $stu['has_password']): ?>
              <span class="badge bg-success">Active</span>
              <?php elseif ($stu['has_password']): ?>
              <span class="badge bg-warning text-dark">Disabled</span>
              <?php else: ?>
              <span class="badge bg-secondary">No Password</span>
              <?php endif; ?>
            </td>
            <td class="text-center small text-muted d-none d-lg-table-cell">
              <?= $stu['last_login'] ? date('d M Y H:i', strtotime($stu['last_login'])) : '&mdash;' ?>
            </td>
            <td class="text-center">
              <div class="btn-group btn-group-sm">
                <button class="btn btn-outline-primary" title="Set/Reset Password"
                        onclick="openStuPassword(<?= $stu['id'] ?>, '<?= e($stu['first_name'] . ' ' . $stu['last_name']) ?>', '<?= e($stu['admission_no']) ?>')"
                        data-bs-toggle="modal" data-bs-target="#stuPwModal">
                  <i class="fas fa-key"></i>
                </button>
                <?php if ($stu['has_password']): ?>
                <form method="POST" class="d-inline"
                      onsubmit="return confirm('Revoke portal access for <?= e($stu['first_name']) ?>? Their password will be cleared.')">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="student_revoke">
                  <input type="hidden" name="student_id" value="<?= $stu['id'] ?>">
                  <button type="submit" class="btn btn-outline-danger" title="Revoke Access">
                    <i class="fas fa-user-slash"></i>
                  </button>
                </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Student Set Password Modal -->
<div class="modal fade" id="stuPwModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="student_set_password">
        <input type="hidden" name="student_id" id="stuPwId">
        <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
          <h5 class="modal-title"><i class="fas fa-key me-2"></i>Set Student Password</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="text-muted small mb-1">Setting a password for <strong id="stuPwName"></strong>.</p>
          <p class="text-muted small mb-3">
            They will log in using admission number <strong id="stuPwAdmNo" class="font-monospace"></strong> and this password.
          </p>
          <div class="mb-3">
            <label class="form-label fw-semibold small">New Password <span class="text-danger">*</span></label>
            <input type="password" name="new_password" id="stuPwNew" class="form-control" minlength="6" required placeholder="Minimum 6 characters">
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold small">Confirm Password <span class="text-danger">*</span></label>
            <input type="password" name="confirm_password" id="stuPwConfirm" class="form-control" required placeholder="Repeat the password">
          </div>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="stuPwShow" onchange="toggleStuPwVis()">
            <label class="form-check-label small" for="stuPwShow">Show passwords</label>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success btn-sm"><i class="fas fa-save me-1"></i>Set Password &amp; Enable Access</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php endif; ?>

<?php ob_start(); ?>
<script>
function openSetPassword(id, name) {
    document.getElementById('pwTeacherId').value = id;
    document.getElementById('pwTeacherName').textContent = name;
    document.getElementById('pwNew').value = '';
    document.getElementById('pwConfirm').value = '';
}
function togglePwVis() {
    const show = document.getElementById('pwShow').checked;
    ['pwNew','pwConfirm'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.type = show ? 'text' : 'password';
    });
}
function openSetPin(id, name) {
    document.getElementById('pinParentId').value = id;
    document.getElementById('pinParentName').textContent = name;
    document.getElementById('pinInput').value = '';
}
function openStuPassword(id, name, admNo) {
    document.getElementById('stuPwId').value = id;
    document.getElementById('stuPwName').textContent = name;
    document.getElementById('stuPwAdmNo').textContent = admNo;
    document.getElementById('stuPwNew').value = '';
    document.getElementById('stuPwConfirm').value = '';
}
function toggleStuPwVis() {
    const show = document.getElementById('stuPwShow').checked;
    ['stuPwNew','stuPwConfirm'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.type = show ? 'text' : 'password';
    });
}
</script>
<?php $extraJs = ob_get_clean();
require_once __DIR__ . '/../../includes/footer.php'; ?>
