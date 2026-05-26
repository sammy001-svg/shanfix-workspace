<?php
$pageTitle = 'Global Search';
require_once __DIR__ . '/../includes/header-client.php';

$q      = trim($_GET['q'] ?? '');
$orgId  = (int)$user['org_id'];
$mods   = getOrgModules($orgId);
$modSlugs = array_column($mods, 'slug');
$results  = [];

// ── Module search definitions ────────────────────────────────────
// Each entry: slug => [table, [columns...], label_col, sub_col, link_pattern, icon, color]
//   label_col  = column to use as primary display label (can be 'CONCAT(...)' alias)
//   sub_col    = column(s) for secondary info
//   link       = URL pattern; use {id} for the record's id
//   icon       = Font Awesome class
//   color      = hex or CSS color

$moduleSearchMap = [
    'accounting' => [
        [
            'table'    => 'acc_invoices',
            'columns'  => ['invoice_number', 'notes'],
            'label'    => 'invoice_number',
            'sub'      => 'notes',
            'link'     => APP_URL . '/modules/accounting/invoices.php',
            'icon'     => 'fas fa-file-invoice',
            'color'    => '#1A8A4E',
            'section'  => 'Invoices',
        ],
        [
            'table'    => 'acc_expenses',
            'columns'  => ['description', 'category'],
            'label'    => 'description',
            'sub'      => 'category',
            'link'     => APP_URL . '/modules/accounting/expenses.php',
            'icon'     => 'fas fa-receipt',
            'color'    => '#1A8A4E',
            'section'  => 'Expenses',
        ],
    ],
    'crm' => [
        [
            'table'    => 'crm_contacts',
            'columns'  => ['name', 'email', 'phone', 'company'],
            'label'    => 'name',
            'sub'      => 'email',
            'link'     => APP_URL . '/modules/crm/contacts.php',
            'icon'     => 'fas fa-address-book',
            'color'    => '#2980b9',
            'section'  => 'Contacts',
        ],
    ],
    'sales' => [
        [
            'table'    => 'sales_customers',
            'columns'  => ['name', 'email', 'phone'],
            'label'    => 'name',
            'sub'      => 'phone',
            'link'     => APP_URL . '/modules/sales/customers.php',
            'icon'     => 'fas fa-users',
            'color'    => '#8e44ad',
            'section'  => 'Customers',
        ],
        [
            'table'    => 'sales_orders',
            'columns'  => ['order_no'],
            'label'    => 'order_no',
            'sub'      => 'status',
            'link'     => APP_URL . '/modules/sales/orders.php',
            'icon'     => 'fas fa-shopping-cart',
            'color'    => '#8e44ad',
            'section'  => 'Orders',
        ],
    ],
    'hrm' => [
        [
            'table'    => 'hrm_employees',
            'columns'  => ['first_name', 'last_name', 'employee_no', 'email', 'phone', 'position'],
            'label'    => "CONCAT(first_name,' ',last_name)",
            'label_alias' => 'full_name',
            'sub'      => 'position',
            'link'     => APP_URL . '/modules/hrm/employees.php',
            'icon'     => 'fas fa-id-badge',
            'color'    => '#2c3e50',
            'section'  => 'Employees',
        ],
    ],
    'pos' => [
        [
            'table'    => 'pos_products',
            'columns'  => ['name', 'sku', 'barcode'],
            'label'    => 'name',
            'sub'      => 'sku',
            'link'     => APP_URL . '/modules/pos/products.php',
            'icon'     => 'fas fa-barcode',
            'color'    => '#e67e22',
            'section'  => 'Products',
        ],
        [
            'table'    => 'pos_sales',
            'columns'  => ['receipt_no', 'customer_name'],
            'label'    => 'receipt_no',
            'sub'      => 'customer_name',
            'link'     => APP_URL . '/modules/pos/sales.php',
            'icon'     => 'fas fa-cash-register',
            'color'    => '#e67e22',
            'section'  => 'Sales',
        ],
    ],
    'school' => [
        [
            'table'    => 'sch_students',
            'columns'  => ['first_name', 'last_name', 'admission_no', 'parent_name'],
            'label'    => "CONCAT(first_name,' ',last_name)",
            'label_alias' => 'full_name',
            'sub'      => 'admission_no',
            'link'     => APP_URL . '/modules/school/students.php',
            'icon'     => 'fas fa-user-graduate',
            'color'    => '#16a085',
            'section'  => 'Students',
        ],
    ],
    'health' => [
        [
            'table'    => 'health_patients',
            'columns'  => ['name', 'phone', 'email', 'id_number'],
            'label'    => 'name',
            'sub'      => 'phone',
            'link'     => APP_URL . '/modules/health/patients.php',
            'icon'     => 'fas fa-heartbeat',
            'color'    => '#c0392b',
            'section'  => 'Patients',
        ],
    ],
    'sacco' => [
        [
            'table'    => 'sacco_members',
            'columns'  => ['name', 'phone', 'email', 'member_no'],
            'label'    => 'name',
            'sub'      => 'member_no',
            'link'     => APP_URL . '/modules/sacco/members.php',
            'icon'     => 'fas fa-piggy-bank',
            'color'    => '#27ae60',
            'section'  => 'Members',
        ],
    ],
    'hotel' => [
        [
            'table'    => 'hotel_guests',
            'columns'  => ['first_name', 'last_name', 'phone', 'email'],
            'label'    => "CONCAT(first_name,' ',last_name)",
            'label_alias' => 'full_name',
            'sub'      => 'phone',
            'link'     => APP_URL . '/modules/hotel/guests.php',
            'icon'     => 'fas fa-concierge-bell',
            'color'    => '#d35400',
            'section'  => 'Guests',
        ],
        [
            'table'    => 'hotel_bookings',
            'columns'  => ['booking_no'],
            'label'    => 'booking_no',
            'sub'      => 'status',
            'link'     => APP_URL . '/modules/hotel/bookings.php',
            'icon'     => 'fas fa-bed',
            'color'    => '#d35400',
            'section'  => 'Bookings',
        ],
    ],
    'rental' => [
        [
            'table'    => 'rental_tenants',
            'columns'  => ['name', 'phone', 'email', 'id_number'],
            'label'    => 'name',
            'sub'      => 'phone',
            'link'     => APP_URL . '/modules/rental/tenants.php',
            'icon'     => 'fas fa-home',
            'color'    => '#7f8c8d',
            'section'  => 'Tenants',
        ],
    ],
    'church' => [
        [
            'table'    => 'church_members',
            'columns'  => ['name', 'phone', 'email'],
            'label'    => 'name',
            'sub'      => 'phone',
            'link'     => APP_URL . '/modules/church/members.php',
            'icon'     => 'fas fa-church',
            'color'    => '#6c5ce7',
            'section'  => 'Members',
        ],
    ],
    'finance' => [
        [
            'table'    => 'fin_transactions',
            'columns'  => ['description', 'reference'],
            'label'    => 'description',
            'sub'      => 'reference',
            'link'     => APP_URL . '/modules/finance/transactions.php',
            'icon'     => 'fas fa-money-bill-wave',
            'color'    => '#00b894',
            'section'  => 'Transactions',
        ],
    ],
    'manufacturing' => [
        [
            'table'    => 'mfg_products',
            'columns'  => ['name', 'code'],
            'label'    => 'name',
            'sub'      => 'code',
            'link'     => APP_URL . '/modules/manufacturing/products.php',
            'icon'     => 'fas fa-industry',
            'color'    => '#636e72',
            'section'  => 'Products',
        ],
        [
            'table'    => 'mfg_raw_materials',
            'columns'  => ['name', 'code'],
            'label'    => 'name',
            'sub'      => 'code',
            'link'     => APP_URL . '/modules/manufacturing/raw-materials.php',
            'icon'     => 'fas fa-cogs',
            'color'    => '#636e72',
            'section'  => 'Raw Materials',
        ],
    ],
    'retail' => [
        [
            'table'    => 'retail_products',
            'columns'  => ['name', 'sku', 'barcode'],
            'label'    => 'name',
            'sub'      => 'sku',
            'link'     => APP_URL . '/modules/retail/products.php',
            'icon'     => 'fas fa-store',
            'color'    => '#fdcb6e',
            'section'  => 'Products',
        ],
        [
            'table'    => 'retail_suppliers',
            'columns'  => ['name'],
            'label'    => 'name',
            'sub'      => 'phone',
            'link'     => APP_URL . '/modules/retail/suppliers.php',
            'icon'     => 'fas fa-truck',
            'color'    => '#fdcb6e',
            'section'  => 'Suppliers',
        ],
    ],
    'shopping-mall' => [
        [
            'table'    => 'mall_tenants',
            'columns'  => ['business_name', 'contact_person', 'phone'],
            'label'    => 'business_name',
            'sub'      => 'contact_person',
            'link'     => APP_URL . '/modules/shopping-mall/tenants.php',
            'icon'     => 'fas fa-building',
            'color'    => '#a29bfe',
            'section'  => 'Tenants',
        ],
    ],
];

// ── Module meta (icon & color for section headers) ────────────────
$moduleMeta = [];
foreach ($mods as $m) {
    $moduleMeta[$m['slug']] = ['name' => $m['name'], 'icon' => $m['icon']];
}

$totalCount = 0;

// ── Run searches ─────────────────────────────────────────────────
if (strlen($q) >= 2) {
    $like = '%' . $q . '%';

    foreach ($moduleSearchMap as $slug => $searches) {
        if (!in_array($slug, $modSlugs)) continue;

        $moduleItems = [];

        foreach ($searches as $def) {
            // Build WHERE clause for LIKE across columns
            $conditions = [];
            foreach ($def['columns'] as $col) {
                $conditions[] = "`{$col}` LIKE ?";
            }
            $whereClause = '(' . implode(' OR ', $conditions) . ')';
            $params = array_fill(0, count($def['columns']), $like);

            // Label select
            $labelExpr  = $def['label'];
            $labelAlias = $def['label_alias'] ?? null;
            if ($labelAlias) {
                $selectLabel = "{$labelExpr} AS {$labelAlias}";
                $labelKey    = $labelAlias;
            } else {
                $selectLabel = $labelExpr;
                $labelKey    = $def['label'];
            }

            // Sub select
            $subCol = $def['sub'];

            $sql = "SELECT id, {$selectLabel}, `{$subCol}` AS _sub
                    FROM `{$def['table']}`
                    WHERE org_id = ? AND {$whereClause}
                    LIMIT 5";

            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute(array_merge([$orgId], $params));
                $rows = $stmt->fetchAll();

                foreach ($rows as $row) {
                    $moduleItems[] = [
                        'label'   => $row[$labelKey] ?? $row[$labelExpr] ?? '—',
                        'sub'     => $row['_sub'] ?? '',
                        'link'    => $def['link'] . '?id=' . (int)$row['id'],
                        'icon'    => $def['icon'],
                        'color'   => $def['color'],
                        'section' => $def['section'],
                    ];
                }
            } catch (PDOException $e) {
                // Table may not exist for this org's setup — skip silently
                error_log('[Search] ' . $def['table'] . ': ' . $e->getMessage());
            }
        }

        if (!empty($moduleItems)) {
            $totalCount += count($moduleItems);
            $results[$slug] = [
                'module' => $moduleMeta[$slug] ?? ['name' => ucfirst($slug), 'icon' => 'fas fa-puzzle-piece'],
                'items'  => $moduleItems,
            ];
        }
    }
}
?>

<?php
// ── JSON mode for live header search dropdown ────────────────────
if (!empty($_GET['json'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache');
    $flat = [];
    foreach ($results as $slug => $group) {
        foreach ($group['items'] as $item) {
            $flat[] = [
                'title'    => $item['label'],
                'subtitle' => $item['sub'] ?? '',
                'type'     => $item['section'],
                'url'      => $item['link'],
                'icon'     => $item['icon'],
            ];
        }
    }
    echo json_encode(['success' => true, 'results' => $flat, 'total' => count($flat)]);
    exit;
}
?>

<div class="page-header">
  <div>
    <h4><i class="fas fa-search me-2 text-primary"></i>Global Search</h4>
    <p class="text-muted mb-0">Search across all your active modules</p>
  </div>
</div>

<!-- Search form -->
<div class="card mb-4">
  <div class="card-body py-4">
    <form method="GET" action="">
      <div class="input-group input-group-lg">
        <span class="input-group-text bg-white border-end-0">
          <i class="fas fa-search text-muted"></i>
        </span>
        <input type="text"
               name="q"
               class="form-control border-start-0 ps-0"
               placeholder="Search contacts, invoices, products, employees…"
               value="<?= e($q) ?>"
               autofocus
               autocomplete="off">
        <button class="btn btn-primary px-4" type="submit">Search</button>
        <?php if ($q): ?>
        <a href="?" class="btn btn-outline-secondary">Clear</a>
        <?php endif; ?>
      </div>
      <?php if (strlen($q) > 0 && strlen($q) < 2): ?>
      <small class="text-danger mt-1 d-block">Please enter at least 2 characters.</small>
      <?php endif; ?>
    </form>
  </div>
</div>

<?php if (strlen($q) >= 2): ?>

<!-- Result summary -->
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <p class="mb-0 text-muted">
    <?php if ($totalCount === 0): ?>
      No results found for <strong>"<?= e($q) ?>"</strong>
    <?php else: ?>
      Found <strong><?= $totalCount ?></strong> result<?= $totalCount !== 1 ? 's' : '' ?> for <strong>"<?= e($q) ?>"</strong>
      across <strong><?= count($results) ?></strong> module<?= count($results) !== 1 ? 's' : '' ?>
    <?php endif; ?>
  </p>
  <?php if ($totalCount > 0): ?>
  <span class="badge bg-primary rounded-pill"><?= $totalCount ?> total</span>
  <?php endif; ?>
</div>

<?php if (empty($results)): ?>
<!-- No results state -->
<div class="card">
  <div class="card-body text-center py-5">
    <i class="fas fa-search fa-3x text-muted mb-3 d-block"></i>
    <h5 class="text-muted">No results found</h5>
    <p class="text-muted mb-3">Your search <strong>"<?= e($q) ?>"</strong> did not match any records.</p>
    <ul class="list-unstyled text-muted small">
      <li><i class="fas fa-lightbulb text-warning me-1"></i> Try different or fewer keywords</li>
      <li><i class="fas fa-lightbulb text-warning me-1"></i> Check spelling</li>
      <li><i class="fas fa-lightbulb text-warning me-1"></i> Search by phone, email, or reference number</li>
    </ul>
    <a href="?" class="btn btn-outline-primary mt-2">Clear Search</a>
  </div>
</div>

<?php else: ?>
<!-- Results grouped by module -->
<div class="row g-4">
  <?php foreach ($results as $slug => $group): ?>
  <div class="col-12">
    <div class="card">
      <!-- Module section header -->
      <div class="card-header d-flex align-items-center gap-2 py-2" style="border-left:4px solid <?= e($group['items'][0]['color']) ?>">
        <i class="<?= e($group['module']['icon']) ?>" style="color:<?= e($group['items'][0]['color']) ?>"></i>
        <strong><?= e($group['module']['name']) ?></strong>
        <span class="badge ms-1" style="background:<?= e($group['items'][0]['color']) ?>"><?= count($group['items']) ?></span>
        <a href="<?= APP_URL ?>/modules/<?= urlencode($slug) ?>/index.php" class="ms-auto btn btn-sm btn-outline-secondary py-0">
          Open module <i class="fas fa-arrow-right ms-1"></i>
        </a>
      </div>

      <!-- Results list -->
      <div class="list-group list-group-flush">
        <?php
        // Group items by section within the module
        $bySection = [];
        foreach ($group['items'] as $item) {
            $bySection[$item['section']][] = $item;
        }
        foreach ($bySection as $section => $items): ?>
        <?php if (count($bySection) > 1): ?>
        <div class="list-group-item py-1 px-3 bg-light">
          <small class="text-muted fw-600 text-uppercase" style="font-size:.7rem;"><?= e($section) ?></small>
        </div>
        <?php endif; ?>
        <?php foreach ($items as $item): ?>
        <a href="<?= e($item['link']) ?>" class="list-group-item list-group-item-action d-flex align-items-center gap-3 py-2 px-3">
          <div class="flex-shrink-0 text-center" style="width:32px">
            <i class="<?= e($item['icon']) ?>" style="color:<?= e($item['color']) ?>"></i>
          </div>
          <div class="flex-grow-1 min-w-0">
            <div class="fw-600 text-truncate" style="font-size:.875rem;">
              <?php
              // Highlight the match in label
              $label = e($item['label']);
              $highlighted = preg_replace(
                  '/(' . preg_quote(e($q), '/') . ')/i',
                  '<mark class="px-0 bg-warning bg-opacity-50">$1</mark>',
                  $label
              );
              echo $highlighted;
              ?>
            </div>
            <?php if (!empty($item['sub'])): ?>
            <div class="text-muted text-truncate" style="font-size:.78rem;"><?= e($item['sub']) ?></div>
            <?php endif; ?>
          </div>
          <div class="flex-shrink-0">
            <i class="fas fa-chevron-right text-muted small"></i>
          </div>
        </a>
        <?php endforeach; ?>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php elseif ($q === ''): ?>
<!-- Empty state — show prompt -->
<div class="card">
  <div class="card-body text-center py-5">
    <i class="fas fa-search fa-4x text-muted mb-3 d-block" style="opacity:.3"></i>
    <h5 class="text-muted">Search your workspace</h5>
    <p class="text-muted mb-4">Find anything across all your active modules — contacts, invoices, employees, products, and more.</p>
    <?php if (!empty($mods)): ?>
    <div class="d-flex flex-wrap justify-content-center gap-2">
      <?php foreach ($mods as $m): ?>
      <span class="badge bg-light text-dark border">
        <i class="<?= e($m['icon']) ?> me-1"></i><?= e($m['name']) ?>
      </span>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
