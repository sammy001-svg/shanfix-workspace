<?php
$pageTitle = 'My Profile';
require_once __DIR__ . '/../includes/header-parent.php';

// ── POST: Change PIN ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_pin') {
    verifyCsrf();
    $currentPin = $_POST['current_pin'] ?? '';
    $newPin     = $_POST['new_pin']     ?? '';
    $confirmPin = $_POST['confirm_pin'] ?? '';

    try {
        $s = $pdo->prepare("SELECT parent_pin FROM sch_parents WHERE id=? AND org_id=? LIMIT 1");
        $s->execute([$parId, $parOrgId]);
        $storedHash = ($s->fetchColumn()) ?: '';

        if (!$storedHash || !password_verify($currentPin, $storedHash)) {
            setFlash('error', 'Current PIN is incorrect.');
        } elseif (strlen($newPin) < 4) {
            setFlash('error', 'New PIN must be at least 4 characters.');
        } elseif ($newPin !== $confirmPin) {
            setFlash('error', 'New PIN and confirmation do not match.');
        } else {
            $u = $pdo->prepare("UPDATE sch_parents SET parent_pin=? WHERE id=? AND org_id=?");
            $u->execute([password_hash($newPin, PASSWORD_BCRYPT), $parId, $parOrgId]);
            setFlash('success', 'PIN updated successfully.');
        }
    } catch (Throwable $e) {
        setFlash('error', 'An error occurred. Please try again.');
    }
    redirect(APP_URL . '/parent/profile.php');
}

// ── Load parent record ────────────────────────────────────────
$parent = [];
try {
    $s = $pdo->prepare(
        "SELECT first_name, last_name, email, phone, relationship,
                occupation, address, national_id, portal_enabled, last_login
         FROM sch_parents WHERE id=? AND org_id=? LIMIT 1"
    );
    $s->execute([$parId, $parOrgId]);
    $parent = $s->fetch() ?: [];
} catch (Throwable $e) {}

// ── Load all linked children with full details ────────────────
$children = [];
if (!empty($parSids)) {
    try {
        $in = implode(',', array_fill(0, count($parSids), '?'));
        $s  = $pdo->prepare(
            "SELECT s.id, s.first_name, s.last_name, s.admission_no, s.status,
                    s.gender, s.dob, s.portal_enabled AS stu_portal_enabled,
                    c.name AS class_name
             FROM sch_students s
             LEFT JOIN sch_classes c ON s.class_id = c.id
             WHERE s.id IN ($in) AND s.org_id=?
             ORDER BY s.first_name"
        );
        $s->execute(array_merge($parSids, [$parOrgId]));
        $children = $s->fetchAll();
    } catch (Throwable $e) {}
}

$fullName     = trim(($parent['first_name'] ?? '') . ' ' . ($parent['last_name'] ?? '')) ?: $parName;
$relationship = ucfirst($parent['relationship'] ?? '');
$initials     = strtoupper(substr($fullName, 0, 1));
?>

<div class="d-flex align-items-center mb-4">
  <h5 class="fw-bold mb-0"><i class="fas fa-user-circle me-2" style="color:var(--par-green)"></i>My Profile</h5>
</div>

<div class="row g-4">

  <!-- Left: parent details + children -->
  <div class="col-lg-8">

    <!-- Identity card -->
    <div class="card border-0 shadow-sm mb-4">
      <div class="card-body">
        <div class="d-flex align-items-center gap-4 mb-4">
          <div class="rounded-circle d-flex align-items-center justify-content-center text-white fw-bold flex-shrink-0"
               style="width:72px;height:72px;background:var(--par-green);font-size:1.8rem">
            <?= $initials ?>
          </div>
          <div>
            <h5 class="fw-bold mb-1" style="color:var(--par-navy)"><?= e($fullName) ?></h5>
            <div class="d-flex flex-wrap gap-2 align-items-center">
              <?php if ($relationship): ?>
              <span class="badge" style="background:#d1fae5;color:#065f46"><?= e($relationship) ?></span>
              <?php endif; ?>
              <?php if (!empty($parent['occupation'])): ?>
              <span class="text-muted small"><?= e($parent['occupation']) ?></span>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <div class="row g-3">
          <?php
          $detailFields = [
              ['icon'=>'fas fa-envelope',       'label'=>'Email',       'value'=>$parent['email']       ?? null],
              ['icon'=>'fas fa-phone',           'label'=>'Phone',       'value'=>$parent['phone']       ?? null],
              ['icon'=>'fas fa-id-card',         'label'=>'National ID', 'value'=>$parent['national_id'] ?? null],
              ['icon'=>'fas fa-map-marker-alt',  'label'=>'Address',     'value'=>$parent['address']     ?? null],
          ];
          foreach ($detailFields as $f): if (empty($f['value'])) continue; ?>
          <div class="col-md-6">
            <div class="d-flex gap-2 align-items-start">
              <i class="<?= $f['icon'] ?> mt-1 text-muted" style="font-size:.85rem;width:16px"></i>
              <div>
                <div class="text-muted" style="font-size:.7rem;text-transform:uppercase;letter-spacing:.5px">
                  <?= $f['label'] ?>
                </div>
                <div class="small fw-semibold"><?= e($f['value']) ?></div>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
          <?php if (!empty($parent['last_login'])): ?>
          <div class="col-md-6">
            <div class="d-flex gap-2 align-items-start">
              <i class="fas fa-clock mt-1 text-muted" style="font-size:.85rem;width:16px"></i>
              <div>
                <div class="text-muted" style="font-size:.7rem;text-transform:uppercase;letter-spacing:.5px">Last Login</div>
                <div class="small fw-semibold"><?= date('d M Y, H:i', strtotime($parent['last_login'])) ?></div>
              </div>
            </div>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Linked children -->
    <div class="card border-0 shadow-sm">
      <div class="card-header">
        <h6 class="mb-0 fw-bold">
          <i class="fas fa-child me-2"></i>Linked Children
          <span class="badge bg-secondary bg-opacity-25 text-secondary ms-1"><?= count($children) ?></span>
        </h6>
      </div>
      <div class="card-body p-0">
        <?php if (empty($children)): ?>
        <div class="text-center py-4 text-muted small">
          <i class="fas fa-child d-block fa-2x mb-1 opacity-25"></i>No children linked to this account.
        </div>
        <?php else: ?>
        <div class="table-responsive">
          <table class="table mb-0 align-middle">
            <thead class="table-light">
              <tr>
                <th>Name</th>
                <th>Adm No</th>
                <th>Class</th>
                <th class="text-center">Enrolment</th>
                <th class="text-center">Portal</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($children as $ch): ?>
              <tr class="<?= $ch['id'] == $parActive ? 'table-success' : '' ?>">
                <td>
                  <div class="fw-semibold small"><?= e($ch['first_name'] . ' ' . $ch['last_name']) ?></div>
                  <?php if (!empty($ch['gender'])): ?>
                  <div class="text-muted" style="font-size:.72rem"><?= ucfirst($ch['gender']) ?></div>
                  <?php endif; ?>
                </td>
                <td class="small text-muted"><?= e($ch['admission_no'] ?? '—') ?></td>
                <td class="small"><?= e($ch['class_name'] ?? '—') ?></td>
                <td class="text-center">
                  <span class="badge <?= $ch['status'] === 'active' ? 'bg-success' : 'bg-secondary' ?> bg-opacity-75">
                    <?= ucfirst($ch['status'] ?? 'unknown') ?>
                  </span>
                </td>
                <td class="text-center">
                  <?php if ($ch['stu_portal_enabled']): ?>
                  <i class="fas fa-check-circle text-success" title="Portal access enabled"></i>
                  <?php else: ?>
                  <i class="fas fa-times-circle text-muted" title="No portal access"></i>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($ch['id'] != $parActive): ?>
                  <a href="?sid=<?= $ch['id'] ?>" class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:.75rem">
                    Switch
                  </a>
                  <?php else: ?>
                  <span class="text-success" style="font-size:.75rem"><i class="fas fa-star"></i> Active</span>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>

  </div><!-- /col-lg-8 -->

  <!-- Right: Change PIN -->
  <div class="col-lg-4">
    <div class="card border-0 shadow-sm">
      <div class="card-header">
        <h6 class="mb-0 fw-bold"><i class="fas fa-lock me-2"></i>Change PIN</h6>
      </div>
      <div class="card-body">
        <p class="text-muted small mb-3">
          Your PIN is used to log in to the parent portal. Keep it private and do not share it with anyone.
        </p>
        <form method="POST" novalidate>
          <?= csrfField() ?>
          <input type="hidden" name="action" value="change_pin">

          <div class="mb-3">
            <label class="form-label small fw-semibold">Current PIN</label>
            <div class="input-group input-group-sm">
              <span class="input-group-text"><i class="fas fa-lock"></i></span>
              <input type="password" class="form-control" name="current_pin" id="currentPin"
                     placeholder="Your current PIN" autocomplete="current-password" required>
              <button type="button" class="btn btn-outline-secondary" onclick="toggleVis('currentPin','eyeC')">
                <i id="eyeC" class="fas fa-eye"></i>
              </button>
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label small fw-semibold">New PIN</label>
            <div class="input-group input-group-sm">
              <span class="input-group-text"><i class="fas fa-key"></i></span>
              <input type="password" class="form-control" name="new_pin" id="newPin"
                     placeholder="Min 4 characters" minlength="4" autocomplete="new-password"
                     required oninput="checkMatch()">
              <button type="button" class="btn btn-outline-secondary" onclick="toggleVis('newPin','eyeN')">
                <i id="eyeN" class="fas fa-eye"></i>
              </button>
            </div>
            <div id="pinStrength" class="form-text"></div>
          </div>

          <div class="mb-4">
            <label class="form-label small fw-semibold">Confirm New PIN</label>
            <div class="input-group input-group-sm">
              <span class="input-group-text"><i class="fas fa-key"></i></span>
              <input type="password" class="form-control" name="confirm_pin" id="confirmPin"
                     placeholder="Repeat new PIN" minlength="4" autocomplete="new-password"
                     required oninput="checkMatch()">
            </div>
            <div id="pinMatch" class="form-text"></div>
          </div>

          <button type="submit" class="btn btn-sm w-100 fw-semibold"
                  style="background:var(--par-green);color:#fff">
            <i class="fas fa-save me-1"></i>Update PIN
          </button>
        </form>
      </div>
    </div>

    <!-- Portal URL hint -->
    <div class="card border-0 shadow-sm mt-3">
      <div class="card-body">
        <div class="text-muted small fw-semibold mb-1"><i class="fas fa-link me-1"></i>Your Portal URL</div>
        <input type="text" class="form-control form-control-sm bg-light" readonly
               value="<?= e(APP_URL . '/parent/login.php' . ($orgSlug ? '?org=' . rawurlencode($orgSlug) : '')) ?>"
               onclick="this.select()" title="Click to select">
        <div class="form-text">Share this link with no one — it contains your organisation identifier.</div>
      </div>
    </div>
  </div>

</div><!-- /row -->

<script>
function toggleVis(fieldId, eyeId) {
    const f = document.getElementById(fieldId);
    const e = document.getElementById(eyeId);
    if (f.type === 'password') {
        f.type = 'text';
        e.classList.replace('fa-eye', 'fa-eye-slash');
    } else {
        f.type = 'password';
        e.classList.replace('fa-eye-slash', 'fa-eye');
    }
}
function checkMatch() {
    const n   = document.getElementById('newPin').value;
    const c   = document.getElementById('confirmPin').value;
    const str = document.getElementById('pinStrength');
    const msg = document.getElementById('pinMatch');

    if (n.length === 0) {
        str.textContent = '';
    } else if (n.length < 4) {
        str.className = 'form-text text-danger'; str.textContent = 'Too short (min 4 characters)';
    } else if (n.length < 6) {
        str.className = 'form-text text-warning'; str.textContent = 'Acceptable';
    } else {
        str.className = 'form-text text-success'; str.textContent = 'Strong';
    }

    if (c.length === 0) { msg.textContent = ''; return; }
    if (n === c) {
        msg.className = 'form-text text-success'; msg.textContent = '✓ PINs match';
    } else {
        msg.className = 'form-text text-danger'; msg.textContent = 'PINs do not match';
    }
}
</script>

<?php require_once __DIR__ . '/../includes/footer-parent.php'; ?>
