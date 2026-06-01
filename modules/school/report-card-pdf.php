<?php
/**
 * School Module — Student Report Card (print-friendly HTML)
 * Access via: staff/admin with school module access, OR parent portal session.
 * GET: ?student_id=X&exam_id=Y
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

$studentId = (int)($_GET['student_id'] ?? 0);
$examId    = (int)($_GET['exam_id']    ?? 0);
$orgId     = 0;

// ── Dual auth: staff session OR parent portal session ─────────────
if (!empty($_SESSION['user_id'])) {
    requireModuleAccess('school');
    $staffUser = currentUser();
    $orgId = (int)$staffUser['org_id'];
} elseif (!empty($_SESSION['par_id'])) {
    $orgId   = (int)$_SESSION['par_org_id'];
    $parSids = $_SESSION['par_sids'] ?? [];
    if (!in_array($studentId, $parSids, true)) {
        http_response_code(403); exit('Access denied.');
    }
} else {
    redirect(APP_URL . '/auth/login.php');
}

if (!$studentId || !$examId || !$orgId) {
    exit('Missing required parameters.');
}

// ── Load data ─────────────────────────────────────────────────────
$student = [];
try {
    $s = $pdo->prepare(
        "SELECT s.*, c.name AS class_name, c.curriculum AS class_curriculum
         FROM sch_students s
         LEFT JOIN sch_classes c ON s.class_id = c.id
         WHERE s.id=? AND s.org_id=? LIMIT 1"
    );
    $s->execute([$studentId, $orgId]);
    $student = $s->fetch() ?: [];
} catch (Throwable $e) {}

$exam = [];
try {
    $s = $pdo->prepare("SELECT * FROM sch_exams WHERE id=? AND org_id=? LIMIT 1");
    $s->execute([$examId, $orgId]);
    $exam = $s->fetch() ?: [];
} catch (Throwable $e) {}

if (!$student || !$exam) { exit('Student or exam not found.'); }

// ── Results ───────────────────────────────────────────────────────
$results = [];
try {
    $s = $pdo->prepare(
        "SELECT r.marks, r.max_marks, r.grade, r.predicted_grade, r.teacher_comment, r.remarks,
                sub.name AS subject_name, sub.code AS subject_code
         FROM sch_results r
         JOIN sch_subjects sub ON r.subject_id = sub.id
         WHERE r.student_id=? AND r.exam_id=? AND r.org_id=?
         ORDER BY sub.name ASC"
    );
    $s->execute([$studentId, $examId, $orgId]);
    $results = $s->fetchAll();
} catch (Throwable $e) {}

// ── Position in class ─────────────────────────────────────────────
$position = null;
$classId  = (int)($student['class_id'] ?? 0);
if ($classId) {
    try {
        $s = $pdo->prepare(
            "SELECT student_id, SUM(marks) AS total
             FROM sch_results WHERE exam_id=? AND org_id=? AND class_id=?
             GROUP BY student_id ORDER BY total DESC"
        );
        $s->execute([$examId, $orgId, $classId]);
        $ranks = $s->fetchAll(PDO::FETCH_COLUMN|PDO::FETCH_UNIQUE, 0);
        $pos   = array_search($studentId, array_keys($ranks));
        if ($pos !== false) $position = $pos + 1;
    } catch (Throwable $e) {}
}

// ── Org info ──────────────────────────────────────────────────────
$orgInfo = [];
try {
    $s = $pdo->prepare("SELECT name, logo, address FROM organizations WHERE id=? LIMIT 1");
    $s->execute([$orgId]);
    $orgInfo = $s->fetch() ?: [];
} catch (Throwable $e) {}

// ── Totals ────────────────────────────────────────────────────────
$totalMarks = $maxMarks = 0;
foreach ($results as $r) { $totalMarks += $r['marks']; $maxMarks += $r['max_marks']; }
$overallPct  = $maxMarks > 0 ? round($totalMarks / $maxMarks * 100, 1) : 0;

$gradeColors = ['A'=>'#1A8A4E','B'=>'#3498db','C'=>'#f39c12','D'=>'#e67e22','E'=>'#e74c3c','F'=>'#c0392b'];
function ordinal(int $n): string {
    $s=['th','st','nd','rd']; $v=$n%100;
    return $n.(isset($s[$v-10])||isset($s[$v-11])?'th':($s[$v%10]??'th'));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Report Card — <?= e(($student['first_name']??'') . ' ' . ($student['last_name']??'')) ?></title>
<style>
@media print {
  body { margin: 0; }
  .no-print { display: none !important; }
  .card { box-shadow: none !important; border: 1px solid #ddd !important; }
}
body { font-family: Arial, Helvetica, sans-serif; background: #f4f6f9; color: #222; font-size: 13px; }
.rc-container { max-width: 780px; margin: 20px auto; background: #fff; border: 1px solid #ddd; border-radius: 8px; overflow: hidden; }

/* Header */
.rc-header { background: #0B2D4E; color: #fff; padding: 20px 28px; display: flex; align-items: center; gap: 20px; }
.rc-school-logo { width: 60px; height: 60px; border-radius: 10px; background: rgba(255,255,255,.15); display: flex; align-items: center; justify-content: center; font-size: 1.5rem; flex-shrink: 0; overflow: hidden; }
.rc-school-logo img { width: 100%; height: 100%; object-fit: cover; }
.rc-school-name { font-size: 1.2rem; font-weight: 700; margin-bottom: 2px; }
.rc-school-sub  { font-size: .8rem; opacity: .65; }
.rc-card-title  { margin-left: auto; text-align: right; }
.rc-card-title .title { font-size: 1.3rem; font-weight: 800; letter-spacing: .05em; }
.rc-card-title .sub   { font-size: .75rem; opacity: .65; }

/* Student info strip */
.rc-student-strip { background: #f8fafc; border-bottom: 1px solid #e2e8f0; padding: 14px 28px; display: flex; gap: 24px; flex-wrap: wrap; }
.rc-info-item { display: flex; flex-direction: column; }
.rc-info-label { font-size: .68rem; color: #94a3b8; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; }
.rc-info-val   { font-size: .88rem; font-weight: 700; color: #0B2D4E; }

/* Summary row */
.rc-summary { display: flex; border-bottom: 1px solid #e2e8f0; }
.rc-summary-cell { flex: 1; text-align: center; padding: 14px 8px; border-right: 1px solid #e2e8f0; }
.rc-summary-cell:last-child { border-right: none; }
.rc-summary-cell .sv { font-size: 1.4rem; font-weight: 800; color: #0B2D4E; }
.rc-summary-cell .sl { font-size: .7rem; color: #94a3b8; font-weight: 600; text-transform: uppercase; }

/* Results table */
.rc-body { padding: 20px 28px; }
.rc-table { width: 100%; border-collapse: collapse; }
.rc-table th { background: #0B2D4E; color: #fff; padding: 8px 10px; font-size: .75rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; }
.rc-table td { padding: 8px 10px; border-bottom: 1px solid #f1f5f9; font-size: .82rem; vertical-align: middle; }
.rc-table tr:last-child td { border-bottom: none; }
.rc-table tr:nth-child(even) td { background: #fafafa; }
.rc-table .grade-badge { display: inline-block; padding: 2px 8px; border-radius: 12px; color: #fff; font-weight: 700; font-size: .75rem; }
.rc-total-row td { font-weight: 700; background: #f0fdf4 !important; border-top: 2px solid #1A8A4E; }

/* Progress bar */
.mini-bar { height: 6px; background: #e2e8f0; border-radius: 3px; overflow: hidden; margin-top: 3px; }
.mini-bar-fill { height: 100%; border-radius: 3px; }

/* Footer */
.rc-footer { padding: 16px 28px 24px; border-top: 1px solid #e2e8f0; }
.sig-row { display: flex; gap: 24px; margin-top: 28px; }
.sig-cell { flex: 1; text-align: center; }
.sig-line { border-bottom: 1px solid #94a3b8; margin-bottom: 6px; height: 36px; }
.sig-label { font-size: .72rem; color: #94a3b8; }
.rc-watermark { text-align: center; font-size: .68rem; color: #cbd5e1; margin-top: 12px; }
</style>
</head>
<body>

<!-- Print & Back buttons -->
<div class="no-print" style="text-align:center;padding:12px;background:#f1f5f9;border-bottom:1px solid #e2e8f0">
  <button onclick="window.print()" style="background:#1A8A4E;color:#fff;border:none;padding:8px 20px;border-radius:6px;font-weight:700;cursor:pointer;margin-right:8px">
    <span style="margin-right:4px">🖨</span> Print / Save PDF
  </button>
  <button onclick="history.back()" style="background:#fff;color:#0B2D4E;border:1px solid #cbd5e1;padding:8px 20px;border-radius:6px;font-weight:600;cursor:pointer">
    ← Back
  </button>
</div>

<div class="rc-container">
  <!-- Header -->
  <div class="rc-header">
    <div class="rc-school-logo">
      <?php if (!empty($orgInfo['logo'])): ?>
        <img src="<?= APP_URL . '/' . e($orgInfo['logo']) ?>" alt="Logo">
      <?php else: ?>
        🏫
      <?php endif; ?>
    </div>
    <div>
      <div class="rc-school-name"><?= e($orgInfo['name'] ?? APP_NAME) ?></div>
      <div class="rc-school-sub"><?= e($orgInfo['address'] ?? '') ?></div>
    </div>
    <div class="rc-card-title">
      <div class="title">REPORT CARD</div>
      <div class="sub"><?= e($exam['name'] ?? '') ?></div>
      <div class="sub"><?= date('d M Y', strtotime($exam['end_date'] ?? 'now')) ?></div>
    </div>
  </div>

  <!-- Student strip -->
  <div class="rc-student-strip">
    <?php foreach ([
      ['Student',     ($student['first_name']??'') . ' ' . ($student['last_name']??'')],
      ['Adm. No',     $student['admission_no'] ?? '—'],
      ['Class',       $student['class_name'] ?? '—'],
      ['Curriculum',  $student['class_curriculum'] ?? $student['curriculum'] ?? '—'],
      ['Gender',      ucfirst($student['gender'] ?? '—')],
      ['Academic Year', $exam['academic_year'] ?? '—'],
    ] as [$lbl, $val]): ?>
    <div class="rc-info-item">
      <span class="rc-info-label"><?= $lbl ?></span>
      <span class="rc-info-val"><?= e($val) ?></span>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Summary row -->
  <div class="rc-summary">
    <div class="rc-summary-cell">
      <div class="sv"><?= $totalMarks ?>/<?= $maxMarks ?></div>
      <div class="sl">Total Marks</div>
    </div>
    <div class="rc-summary-cell">
      <div class="sv" style="color:<?= $overallPct>=80?'#1A8A4E':($overallPct>=60?'#f39c12':'#e74c3c') ?>"><?= $overallPct ?>%</div>
      <div class="sl">Overall %</div>
    </div>
    <div class="rc-summary-cell">
      <div class="sv"><?= count($results) ?></div>
      <div class="sl">Subjects</div>
    </div>
    <?php if ($position !== null): ?>
    <div class="rc-summary-cell">
      <div class="sv"><?= ordinal($position) ?></div>
      <div class="sl">Class Position</div>
    </div>
    <?php endif; ?>
    <div class="rc-summary-cell">
      <div class="sv"><?= e($exam['name'] ?? '—') ?></div>
      <div class="sl">Exam</div>
    </div>
  </div>

  <!-- Results table -->
  <div class="rc-body">
    <?php if (empty($results)): ?>
    <p style="text-align:center;color:#94a3b8;padding:24px">No results recorded for this exam.</p>
    <?php else: ?>
    <table class="rc-table">
      <thead>
        <tr>
          <th style="text-align:left;width:34%">Subject</th>
          <th style="text-align:center">Marks</th>
          <th style="text-align:center;width:120px">Performance</th>
          <th style="text-align:center">Grade</th>
          <th style="text-align:left">Teacher Remark</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($results as $r):
          $pct   = $r['max_marks'] > 0 ? round($r['marks'] / $r['max_marks'] * 100) : 0;
          $grade = strtoupper($r['grade'][0] ?? '');
          $gc    = $gradeColors[$grade] ?? '#6c757d';
        ?>
        <tr>
          <td>
            <strong><?= e($r['subject_name']) ?></strong>
            <?php if (!empty($r['subject_code'])): ?>
            <span style="color:#94a3b8;font-size:.7rem"> (<?= e($r['subject_code']) ?>)</span>
            <?php endif; ?>
          </td>
          <td style="text-align:center"><?= $r['marks'] ?><span style="color:#94a3b8">/<?= $r['max_marks'] ?></span></td>
          <td style="text-align:center">
            <div><?= $pct ?>%</div>
            <div class="mini-bar"><div class="mini-bar-fill" style="width:<?= $pct ?>%;background:<?= $gc ?>"></div></div>
          </td>
          <td style="text-align:center">
            <span class="grade-badge" style="background:<?= $gc ?>"><?= e($r['grade']) ?></span>
          </td>
          <td style="color:#475569"><?= e($r['teacher_comment'] ?? $r['remarks'] ?? '—') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr class="rc-total-row">
          <td>TOTAL</td>
          <td style="text-align:center"><?= $totalMarks ?> / <?= $maxMarks ?></td>
          <td style="text-align:center"><?= $overallPct ?>%</td>
          <td style="text-align:center">—</td>
          <td></td>
        </tr>
      </tfoot>
    </table>
    <?php endif; ?>

    <!-- Signature section -->
    <div class="rc-footer">
      <div class="sig-row">
        <div class="sig-cell"><div class="sig-line"></div><div class="sig-label">Class Teacher</div></div>
        <div class="sig-cell"><div class="sig-line"></div><div class="sig-label">Head of Department</div></div>
        <div class="sig-cell"><div class="sig-line"></div><div class="sig-label">Principal / Director</div></div>
        <div class="sig-cell"><div class="sig-line"></div><div class="sig-label">Parent / Guardian</div></div>
      </div>
      <div class="rc-watermark">
        Generated <?= date('d M Y, h:i A') ?> &mdash; <?= APP_NAME ?> School Management System
      </div>
    </div>
  </div>
</div>

<script>
// Auto-print if opened with ?autoprint=1 (e.g. from email link)
if (new URLSearchParams(location.search).get('autoprint') === '1') {
    window.addEventListener('load', () => setTimeout(() => window.print(), 400));
}
</script>
</body>
</html>
