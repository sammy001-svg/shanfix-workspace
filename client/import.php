<?php
// ── Bootstrap ────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
requireClientAdmin();

$user  = currentUser();
$orgId = (int)$user['org_id'];

// ── Import type definitions ──────────────────────────────────────
$importTypes = [
    'contacts'  => [
        'label'   => 'Contacts / CRM',
        'icon'    => 'fa-address-book',
        'color'   => '#3f51b5',
        'bg'      => '#e8eaf6',
        'table'   => 'crm_contacts',
        'desc'    => 'Import CRM contacts with name, email, phone, type and company.',
        'columns' => ['first_name','last_name','email','phone','type','company'],
    ],
    'products'  => [
        'label'   => 'Products / Inventory',
        'icon'    => 'fa-boxes',
        'color'   => '#ff9800',
        'bg'      => '#fff3e0',
        'table'   => 'pos_products',
        'desc'    => 'Import products with SKU, category, pricing and stock quantity.',
        'columns' => ['name','sku','category','price','cost','qty'],
    ],
    'members'   => [
        'label'   => 'Members (SACCO)',
        'icon'    => 'fa-users',
        'color'   => '#1A8A4E',
        'bg'      => '#e8f5e9',
        'table'   => 'sacco_members',
        'desc'    => 'Import SACCO members with ID number and membership type.',
        'columns' => ['first_name','last_name','email','phone','id_number','member_type'],
    ],
    'customers' => [
        'label'   => 'Customers',
        'icon'    => 'fa-user-tag',
        'color'   => '#00bcd4',
        'bg'      => '#e0f7fa',
        'table'   => 'retail_customers',
        'desc'    => 'Import customers with contact info, type and address.',
        'columns' => ['name','phone','email','customer_type','address'],
    ],
    'employees' => [
        'label'   => 'Employees (HRM)',
        'icon'    => 'fa-id-badge',
        'color'   => '#9c27b0',
        'bg'      => '#f3e5f5',
        'table'   => 'hrm_employees',
        'desc'    => 'Import employees with department, position and salary.',
        'columns' => ['first_name','last_name','email','phone','department','position','salary'],
    ],
];

// ── Template download ────────────────────────────────────────────
if (isset($_GET['template'])) {
    $key = $_GET['template'];
    if (!array_key_exists($key, $importTypes)) { http_response_code(400); exit('Invalid template.'); }
    $def = $importTypes[$key];
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $key . '-template.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out, $def['columns']);
    fclose($out);
    exit;
}

// ── CSV Upload handler ────────────────────────────────────────────
$importResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'upload') {
    verifyCsrf();
    $key = sanitize($_GET['import'] ?? '');

    if (!array_key_exists($key, $importTypes)) {
        setFlash('danger', 'Invalid import type.');
        redirect(APP_URL . '/client/import.php');
    }

    $def = $importTypes[$key];

    if (empty($_FILES['csv_file']['tmp_name']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        setFlash('danger', 'No file uploaded or upload error.');
        redirect(APP_URL . '/client/import.php#import-' . $key);
    }

    $ext = strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION));
    if ($ext !== 'csv') {
        setFlash('danger', 'Only CSV files are accepted.');
        redirect(APP_URL . '/client/import.php#import-' . $key);
    }

    $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
    if (!$handle) {
        setFlash('danger', 'Could not read uploaded file.');
        redirect(APP_URL . '/client/import.php#import-' . $key);
    }

    $cols    = $def['columns'];
    $table   = $def['table'];
    $rowNum  = 0;
    $imported = 0;
    $failed   = 0;
    $errors   = [];

    // Skip header row
    fgetcsv($handle);

    $colPlaceholders = implode(',', array_fill(0, count($cols) + 1, '?'));
    $colNames        = implode(',', array_map(fn($c) => "`$c`", $cols));
    $sql             = "INSERT INTO `$table` (org_id, $colNames) VALUES ($colPlaceholders)";

    while (($row = fgetcsv($handle)) !== false && $rowNum < 500) {
        $rowNum++;
        // Pad row to expected column count
        while (count($row) < count($cols)) $row[] = '';
        $values = array_map('trim', array_slice($row, 0, count($cols)));

        // Basic validation: first column must not be empty
        if (empty($values[0])) { $failed++; $errors[] = "Row $rowNum: first column is empty."; continue; }

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge([$orgId], $values));
            $imported++;
        } catch (Exception $e) {
            $failed++;
            $errors[] = "Row $rowNum: " . $e->getMessage();
        }
    }
    fclose($handle);

    // Log to import_logs if table exists
    try {
        $pdo->prepare("INSERT INTO import_logs (org_id, user_id, import_type, rows_imported, rows_failed, created_at) VALUES (?,?,?,?,?,NOW())")
            ->execute([$orgId, (int)$user['id'], $key, $imported, $failed]);
    } catch (Exception $e) { /* table not present, skip */ }

    logActivity('import', $key, "Imported $imported rows, $failed failed from $key CSV.");

    $importResult = compact('key', 'imported', 'failed', 'errors');
}

// ── Page ─────────────────────────────────────────────────────────
$pageTitle = 'Bulk Data Import';
require_once __DIR__ . '/../includes/header-client.php';
?>

<div class="page-header d-flex align-items-center justify-content-between flex-wrap gap-2">
  <div>
    <h4><i class="fas fa-file-import me-2 text-green"></i>Bulk Data Import</h4>
    <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0">
      <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
      <li class="breadcrumb-item active">Import</li>
    </ol></nav>
  </div>
</div>

<?= flashAlert() ?>

<?php if ($importResult): ?>
<div class="alert alert-<?= $importResult['failed'] === 0 ? 'success' : ($importResult['imported'] === 0 ? 'danger' : 'warning') ?> alert-dismissible fade show">
  <i class="fas fa-<?= $importResult['failed'] === 0 ? 'check-circle' : 'exclamation-triangle' ?> me-2"></i>
  <strong>Import Complete:</strong>
  <?= $importResult['imported'] ?> rows imported successfully<?= $importResult['failed'] > 0 ? ', ' . $importResult['failed'] . ' failed' : '' ?>.
  <?php if (!empty($importResult['errors'])): ?>
  <details class="mt-2"><summary class="small">Show errors (<?= count($importResult['errors']) ?>)</summary>
    <ul class="small mt-1 mb-0"><?php foreach (array_slice($importResult['errors'], 0, 20) as $e): ?><li><?= e($e) ?></li><?php endforeach; ?></ul>
  </details>
  <?php endif; ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="alert alert-info d-flex align-items-start gap-2 mb-4" role="alert">
  <i class="fas fa-info-circle mt-1"></i>
  <div>
    <strong>Import Guidelines:</strong> Download a template CSV, fill in your data, then upload. Maximum <strong>500 rows per file</strong>.
    Duplicate detection is not performed — ensure your data is clean before importing.
  </div>
</div>

<div class="row g-4">
  <?php foreach ($importTypes as $key => $def): ?>
  <div class="col-md-6 col-xl-4" id="import-<?= $key ?>">
    <div class="card shadow-sm border-0 h-100">
      <div class="card-header d-flex align-items-center gap-3" style="background:<?= $def['bg'] ?>">
        <div class="rounded-3 p-2" style="background:<?= $def['color'] ?>20">
          <i class="fas <?= $def['icon'] ?> fa-lg" style="color:<?= $def['color'] ?>"></i>
        </div>
        <div>
          <h6 class="mb-0 fw-bold" style="color:<?= $def['color'] ?>"><?= e($def['label']) ?></h6>
          <small class="text-muted"><?= count($def['columns']) ?> columns expected</small>
        </div>
      </div>
      <div class="card-body">
        <p class="text-muted small mb-2"><?= e($def['desc']) ?></p>
        <div class="mb-3">
          <span class="text-muted small fw-semibold">Columns:</span><br>
          <?php foreach ($def['columns'] as $col): ?>
            <span class="badge bg-light text-dark border me-1 mb-1 font-monospace small"><?= e($col) ?></span>
          <?php endforeach; ?>
        </div>
        <div class="d-flex align-items-center gap-2 mb-3">
          <i class="fas fa-exclamation-circle text-warning small"></i>
          <small class="text-muted">Max 500 rows per upload</small>
        </div>
        <a href="?template=<?= $key ?>" class="btn btn-sm btn-outline-secondary mb-3 w-100">
          <i class="fas fa-file-csv me-1"></i>Download Template
        </a>
        <form method="POST" action="?import=<?= $key ?>&action=upload" enctype="multipart/form-data">
          <?= csrfField() ?>
          <div class="mb-2">
            <label class="form-label small fw-semibold">Upload CSV File</label>
            <input type="file" name="csv_file" class="form-control form-control-sm" accept=".csv" required>
          </div>
          <button type="submit" class="btn btn-sm w-100 text-white" style="background:<?= $def['color'] ?>">
            <i class="fas fa-upload me-1"></i>Import <?= e($def['label']) ?>
          </button>
        </form>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php
$extraJs = <<<'JS'
<script>
// Scroll to the card that was just imported if result is on page
document.addEventListener('DOMContentLoaded', function() {
    const hash = window.location.hash;
    if (hash && hash.startsWith('#import-')) {
        const el = document.querySelector(hash);
        if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
});
</script>
JS;
require_once __DIR__ . '/../includes/footer.php';
