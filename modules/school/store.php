<?php
require_once __DIR__ . '/_nav.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireLogin();

$user  = currentUser();
$orgId = (int)$user['org_id'];
$tab   = in_array($_GET['tab'] ?? '', ['products','sales','categories']) ? $_GET['tab'] : 'products';

// ── Sale number generator ──────────────────────────────────────────────
function generateStoreSaleNo(PDO $pdo, int $orgId): string {
    $pre  = 'STR-' . date('Ymd') . '-';
    $stmt = $pdo->prepare("SELECT sale_no FROM sch_store_sales WHERE org_id=? AND sale_no LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$orgId, $pre . '%']);
    $last = $stmt->fetchColumn();
    $seq  = $last ? ((int)substr($last, -4) + 1) : 1;
    return $pre . str_pad($seq, 4, '0', STR_PAD_LEFT);
}

// ── POST Handlers ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf(); denyIfReadOnly($moduleSlug);
    $action = $_POST['action'] ?? '';

    // ── Categories ─────────────────────────────────────────────────
    if ($action === 'save_category') {
        $id   = (int)($_POST['id'] ?? 0);
        $name = sanitize($_POST['name'] ?? '');
        if (!$name) { setFlash('danger','Category name required.'); redirect('store.php?tab=categories'); }
        try {
            if ($id > 0) {
                requireOrgOwnership('sch_store_categories', $id, $orgId);
                $pdo->prepare("UPDATE sch_store_categories SET name=? WHERE id=? AND org_id=?")->execute([$name,$id,$orgId]);
                setFlash('success','Category updated.');
            } else {
                $pdo->prepare("INSERT INTO sch_store_categories (org_id,name) VALUES (?,?)")->execute([$orgId,$name]);
                setFlash('success',"Category '$name' added.");
            }
        } catch (Throwable $e) {
            error_log('[school/store category] '.$e->getMessage());
            setFlash('danger','Could not save. Run database/school_scholarships_store_migration.sql first.');
        }
        redirect('store.php?tab=categories');
    }
    if ($action === 'delete_category') {
        $id = (int)($_POST['id'] ?? 0);
        requireOrgOwnership('sch_store_categories', $id, $orgId);
        $pdo->prepare("DELETE FROM sch_store_categories WHERE id=? AND org_id=?")->execute([$id,$orgId]);
        setFlash('success','Category deleted.'); redirect('store.php?tab=categories');
    }

    // ── Products ───────────────────────────────────────────────────
    if ($action === 'save_product') {
        $id          = (int)($_POST['id'] ?? 0);
        $catId       = (int)($_POST['category_id'] ?? 0) ?: null;
        $name        = sanitize($_POST['name']        ?? '');
        $description = sanitize($_POST['description'] ?? '');
        $unit        = sanitize($_POST['unit']        ?? 'piece');
        $price       = max(0,(float)($_POST['price']  ?? 0));
        $stock       = max(0,(int)($_POST['stock_qty']    ?? 0));
        $reorder     = max(0,(int)($_POST['reorder_level']?? 5));
        $status      = in_array($_POST['status']??'',['active','inactive'])?$_POST['status']:'active';

        if (!$name) { setFlash('danger','Product name required.'); redirect('store.php?tab=products'); }
        try {
            if ($id > 0) {
                requireOrgOwnership('sch_store_products', $id, $orgId);
                $pdo->prepare("UPDATE sch_store_products SET category_id=?,name=?,description=?,unit=?,price=?,stock_qty=?,reorder_level=?,status=? WHERE id=? AND org_id=?")
                    ->execute([$catId,$name,$description,$unit,$price,$stock,$reorder,$status,$id,$orgId]);
                setFlash('success','Product updated.');
            } else {
                $pdo->prepare("INSERT INTO sch_store_products (org_id,category_id,name,description,unit,price,stock_qty,reorder_level,status) VALUES (?,?,?,?,?,?,?,?,?)")
                    ->execute([$orgId,$catId,$name,$description,$unit,$price,$stock,$reorder,$status]);
                setFlash('success',"Product '$name' added.");
            }
            logActivity($id>0?'update':'create','school',"Store product: $name");
        } catch (Throwable $e) {
            error_log('[school/store product] '.$e->getMessage());
            setFlash('danger','Could not save product.');
        }
        redirect('store.php?tab=products');
    }
    if ($action === 'delete_product') {
        $id = (int)($_POST['id'] ?? 0);
        requireOrgOwnership('sch_store_products', $id, $orgId);
        $pdo->prepare("DELETE FROM sch_store_products WHERE id=? AND org_id=?")->execute([$id,$orgId]);
        setFlash('success','Product removed.'); redirect('store.php?tab=products');
    }

    // ── Restock ────────────────────────────────────────────────────
    if ($action === 'restock') {
        $id  = (int)($_POST['id']  ?? 0);
        $qty = max(0,(int)($_POST['restock_qty'] ?? 0));
        requireOrgOwnership('sch_store_products', $id, $orgId);
        $pdo->prepare("UPDATE sch_store_products SET stock_qty = stock_qty + ? WHERE id=? AND org_id=?")->execute([$qty,$id,$orgId]);
        setFlash('success',"Stock updated (+$qty units)."); redirect('store.php?tab=products');
    }

    // ── New Sale ───────────────────────────────────────────────────
    if ($action === 'save_sale') {
        $studentId    = (int)($_POST['student_id']   ?? 0) ?: null;
        $customerName = sanitize($_POST['customer_name'] ?? '');
        $payMethod    = in_array($_POST['payment_method']??'',['cash','mpesa','card','credit','scholarship'])?$_POST['payment_method']:'cash';
        $discount     = max(0,(float)($_POST['discount'] ?? 0));
        $notes        = sanitize($_POST['notes'] ?? '');
        $itemsJson    = $_POST['items_json'] ?? '[]';
        $items        = json_decode($itemsJson, true);

        if (!is_array($items) || empty($items)) {
            setFlash('danger','Add at least one item to the sale.'); redirect('store.php?tab=sales');
        }
        if (!$customerName && !$studentId) {
            setFlash('danger','Customer name or student is required.'); redirect('store.php?tab=sales');
        }

        // Resolve customer name from student if blank
        if ($studentId && !$customerName) {
            $sn = $pdo->prepare("SELECT CONCAT(first_name,' ',last_name) FROM sch_students WHERE id=? AND org_id=?");
            $sn->execute([$studentId,$orgId]);
            $customerName = $sn->fetchColumn() ?: '';
        }

        try {
            $pdo->beginTransaction();

            $subtotal = 0;
            $lineRows = [];
            foreach ($items as $item) {
                $prodId    = (int)($item['product_id'] ?? 0);
                $qty       = max(1,(int)($item['qty'] ?? 1));
                if (!$prodId) continue;
                $p = $pdo->prepare("SELECT price, stock_qty, name FROM sch_store_products WHERE id=? AND org_id=? AND status='active'");
                $p->execute([$prodId,$orgId]);
                $prod = $p->fetch();
                if (!$prod) { $pdo->rollBack(); setFlash('danger','A product was not found.'); redirect('store.php?tab=sales'); }
                if ($prod['stock_qty'] < $qty) {
                    $pdo->rollBack();
                    setFlash('danger',"Insufficient stock for '{$prod['name']}' (available: {$prod['stock_qty']}).");
                    redirect('store.php?tab=sales');
                }
                $unitPrice = (float)$prod['price'];
                $lineSub   = round($unitPrice * $qty, 2);
                $subtotal += $lineSub;
                $lineRows[] = [$prodId,$qty,$unitPrice,$lineSub];
            }
            $total   = max(0, round($subtotal - $discount, 2));
            $saleNo  = generateStoreSaleNo($pdo, $orgId);

            $pdo->prepare("INSERT INTO sch_store_sales (org_id,sale_no,student_id,customer_name,subtotal,discount,total,payment_method,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute([$orgId,$saleNo,$studentId,$customerName,$subtotal,$discount,$total,$payMethod,$notes,$user['id']]);
            $saleId = (int)$pdo->lastInsertId();

            foreach ($lineRows as [$prodId,$qty,$unitPrice,$lineSub]) {
                $pdo->prepare("INSERT INTO sch_store_sale_items (sale_id,product_id,qty,unit_price,subtotal) VALUES (?,?,?,?,?)")
                    ->execute([$saleId,$prodId,$qty,$unitPrice,$lineSub]);
                $pdo->prepare("UPDATE sch_store_products SET stock_qty = stock_qty - ? WHERE id=? AND org_id=?")->execute([$qty,$prodId,$orgId]);
            }

            $pdo->commit();
            logActivity('create','school',"Store sale: $saleNo — total $total");
            setFlash('success',"Sale $saleNo processed successfully (Total: ".number_format($total,2).").");
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[school/store sale] '.$e->getMessage());
            setFlash('danger','Could not process sale.');
        }
        redirect('store.php?tab=sales');
    }
}

// ── AJAX ───────────────────────────────────────────────────────────────
if (isset($_GET['fetch_product'])) {
    $r = $pdo->prepare("SELECT * FROM sch_store_products WHERE id=? AND org_id=?");
    $r->execute([(int)$_GET['fetch_product'],$orgId]);
    header('Content-Type: application/json'); echo json_encode($r->fetch()?:[]); exit;
}
if (isset($_GET['fetch_sale'])) {
    $r = $pdo->prepare(
        "SELECT s.*, GROUP_CONCAT(p.name,'×',si.qty ORDER BY si.id SEPARATOR ', ') AS items_summary
         FROM sch_store_sales s
         LEFT JOIN sch_store_sale_items si ON si.sale_id=s.id
         LEFT JOIN sch_store_products p ON p.id=si.product_id
         WHERE s.id=? AND s.org_id=? GROUP BY s.id"
    );
    $r->execute([(int)$_GET['fetch_sale'],$orgId]);
    header('Content-Type: application/json'); echo json_encode($r->fetch()?:[]); exit;
}
if (isset($_GET['products_json'])) {
    // Returns all active products as JSON for the sale modal's product picker
    $r = $pdo->prepare("SELECT id, name, price, stock_qty, unit FROM sch_store_products WHERE org_id=? AND status='active' ORDER BY name");
    $r->execute([$orgId]);
    header('Content-Type: application/json'); echo json_encode($r->fetchAll()); exit;
}

// ── Summary stats ──────────────────────────────────────────────────────
$statProducts = $statLowStock = $statTodaySales = $statRevenue = 0;
try {
    $r = $pdo->prepare("SELECT COUNT(*) AS total, SUM(stock_qty <= reorder_level AND status='active') AS low FROM sch_store_products WHERE org_id=?");
    $r->execute([$orgId]); $row = $r->fetch();
    $statProducts = (int)($row['total'] ?? 0);
    $statLowStock = (int)($row['low']   ?? 0);

    $r = $pdo->prepare("SELECT COUNT(*) AS cnt, COALESCE(SUM(total),0) AS rev FROM sch_store_sales WHERE org_id=? AND DATE(created_at)=CURDATE()");
    $r->execute([$orgId]); $row = $r->fetch();
    $statTodaySales = (int)($row['cnt'] ?? 0);
    $statRevenue    = (float)($row['rev'] ?? 0);
} catch (Throwable $e) {}

// ── Load data by tab ───────────────────────────────────────────────────
$categories = [];
try {
    $r = $pdo->prepare("SELECT c.*, COUNT(p.id) AS product_count FROM sch_store_categories c LEFT JOIN sch_store_products p ON p.category_id=c.id AND p.org_id=c.org_id WHERE c.org_id=? GROUP BY c.id ORDER BY c.name");
    $r->execute([$orgId]); $categories = $r->fetchAll();
} catch (Throwable $e) {}

$products = [];
if ($tab === 'products') {
    $fCat    = (int)($_GET['category_id'] ?? 0);
    $fSearch = sanitize($_GET['q'] ?? '');
    $where   = 'p.org_id=?'; $params = [$orgId];
    if ($fCat)    { $where .= ' AND p.category_id=?'; $params[] = $fCat; }
    if ($fSearch) { $where .= ' AND p.name LIKE ?';   $params[] = "%$fSearch%"; }
    try {
        $r = $pdo->prepare("SELECT p.*, c.name AS category_name FROM sch_store_products p LEFT JOIN sch_store_categories c ON c.id=p.category_id WHERE $where ORDER BY p.name ASC");
        $r->execute($params); $products = $r->fetchAll();
    } catch (Throwable $e) {}
}

$sales = [];
if ($tab === 'sales') {
    $fSearch = sanitize($_GET['q'] ?? '');
    $where   = 's.org_id=?'; $params = [$orgId];
    if ($fSearch) {
        $where .= ' AND (s.sale_no LIKE ? OR s.customer_name LIKE ?)';
        $q = "%$fSearch%"; array_push($params,$q,$q);
    }
    try {
        $r = $pdo->prepare(
            "SELECT s.*, COUNT(si.id) AS line_count
             FROM sch_store_sales s
             LEFT JOIN sch_store_sale_items si ON si.sale_id=s.id
             WHERE $where GROUP BY s.id
             ORDER BY s.created_at DESC LIMIT 200"
        );
        $r->execute($params); $sales = $r->fetchAll();
    } catch (Throwable $e) {}
}

$students = [];
try {
    $r = $pdo->prepare("SELECT id, CONCAT(first_name,' ',last_name) AS name, admission_no FROM sch_students WHERE org_id=? AND status='active' ORDER BY first_name");
    $r->execute([$orgId]); $students = $r->fetchAll();
} catch (Throwable $e) {}

require_once __DIR__ . '/../../includes/header-module.php';
$fCat    = $fCat    ?? 0;
$fSearch = $fSearch ?? '';
?>
<?= flashAlert() ?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-store me-2" style="color:<?= $moduleColor ?>"></i>School Store</h4>
    <p class="text-muted mb-0">Manage textbooks, uniforms, stationery and student purchases</p>
  </div>
  <div class="d-flex gap-2">
    <?php if ($tab === 'products'): ?>
    <button class="btn btn-outline-success btn-sm" data-bs-toggle="modal" data-bs-target="#productModal" onclick="openAddProduct()">
      <i class="fas fa-plus me-1"></i>Add Product
    </button>
    <?php endif; ?>
    <button class="btn text-white btn-sm" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#saleModal" onclick="openNewSale()">
      <i class="fas fa-shopping-cart me-1"></i>New Sale
    </button>
  </div>
</div>

<!-- Summary Cards -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-lg-3">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="rounded-circle text-white d-flex align-items-center justify-content-center flex-shrink-0" style="width:48px;height:48px;background:#0B2D4E">
          <i class="fas fa-boxes"></i>
        </div>
        <div><div class="fs-4 fw-bold"><?= $statProducts ?></div><div class="text-muted small">Products</div></div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="rounded-circle text-white d-flex align-items-center justify-content-center flex-shrink-0" style="width:48px;height:48px;background:<?= $statLowStock > 0 ? '#dc3545' : '#6c757d' ?>">
          <i class="fas fa-exclamation-triangle"></i>
        </div>
        <div><div class="fs-4 fw-bold <?= $statLowStock > 0 ? 'text-danger' : '' ?>"><?= $statLowStock ?></div><div class="text-muted small">Low Stock</div></div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="rounded-circle text-white d-flex align-items-center justify-content-center flex-shrink-0" style="width:48px;height:48px;background:#1A8A4E">
          <i class="fas fa-shopping-bag"></i>
        </div>
        <div><div class="fs-4 fw-bold"><?= $statTodaySales ?></div><div class="text-muted small">Today's Sales</div></div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="rounded-circle text-white d-flex align-items-center justify-content-center flex-shrink-0" style="width:48px;height:48px;background:#1A8A4E">
          <i class="fas fa-cash-register"></i>
        </div>
        <div><div class="fs-4 fw-bold"><?= number_format($statRevenue, 2) ?></div><div class="text-muted small">Today's Revenue</div></div>
      </div>
    </div>
  </div>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link <?= $tab==='products'?'active':'' ?>"   href="store.php?tab=products"><i class="fas fa-boxes me-1"></i>Products</a></li>
  <li class="nav-item"><a class="nav-link <?= $tab==='sales'?'active':'' ?>"      href="store.php?tab=sales"><i class="fas fa-receipt me-1"></i>Sales History</a></li>
  <li class="nav-item"><a class="nav-link <?= $tab==='categories'?'active':'' ?>" href="store.php?tab=categories"><i class="fas fa-tags me-1"></i>Categories</a></li>
</ul>

<?php if ($tab === 'products'): ?>
<!-- ════════════════════ PRODUCTS TAB ════════════════════ -->
<div class="card mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <input type="hidden" name="tab" value="products">
      <div class="col-sm-4">
        <input type="text" name="q" class="form-control form-control-sm" placeholder="Search product name..." value="<?= e($fSearch) ?>">
      </div>
      <div class="col-sm-3">
        <select name="category_id" class="form-select form-select-sm">
          <option value="">All Categories</option>
          <?php foreach ($categories as $cat): ?>
          <option value="<?= $cat['id'] ?>" <?= $fCat==$cat['id']?'selected':'' ?>><?= e($cat['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button>
        <a href="store.php?tab=products" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0 fw-bold"><i class="fas fa-boxes me-2 text-success"></i>Product Inventory</h6>
    <span class="badge bg-secondary"><?= count($products) ?> product<?= count($products)!=1?'s':'' ?></span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Product</th>
            <th>Category</th>
            <th>Unit</th>
            <th>Price</th>
            <th>Stock</th>
            <th>Reorder At</th>
            <th>Status</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($products)): ?>
          <tr><td colspan="8" class="text-center text-muted py-5"><i class="fas fa-boxes fa-2x d-block mb-2"></i>No products found.</td></tr>
          <?php else: foreach ($products as $p):
            $lowStock = $p['stock_qty'] <= $p['reorder_level'] && $p['status']==='active';
          ?>
          <tr <?= $lowStock ? 'class="table-warning"' : '' ?>>
            <td>
              <div class="fw-semibold text-dark"><?= e($p['name']) ?></div>
              <?php if ($p['description']): ?>
              <small class="text-muted"><?= e(mb_strimwidth($p['description'],0,50,'...')) ?></small>
              <?php endif; ?>
            </td>
            <td><span class="badge bg-light text-dark border"><?= e($p['category_name'] ?: 'Uncategorised') ?></span></td>
            <td><?= e($p['unit']) ?></td>
            <td class="fw-semibold"><?= number_format((float)$p['price'],2) ?></td>
            <td>
              <span class="fw-semibold <?= $lowStock ? 'text-danger' : 'text-success' ?>">
                <?= number_format($p['stock_qty']) ?>
                <?= $lowStock ? '<i class="fas fa-exclamation-triangle ms-1 text-warning" title="Low stock"></i>' : '' ?>
              </span>
            </td>
            <td><?= $p['reorder_level'] ?></td>
            <td><?= $p['status']==='active' ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>' ?></td>
            <td class="text-center">
              <div class="btn-group btn-group-sm">
                <button class="btn btn-outline-primary" onclick="openEditProduct(<?= $p['id'] ?>)" title="Edit"><i class="fas fa-edit"></i></button>
                <button class="btn btn-outline-success" onclick="openRestock(<?= $p['id'] ?>, '<?= e($p['name']) ?>')" title="Restock"><i class="fas fa-plus-circle"></i></button>
                <button class="btn btn-outline-danger"  onclick="delProduct(<?= $p['id'] ?>, '<?= e($p['name']) ?>')" title="Delete"><i class="fas fa-trash"></i></button>
              </div>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php elseif ($tab === 'sales'): ?>
<!-- ════════════════════ SALES TAB ════════════════════════ -->
<div class="card mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <input type="hidden" name="tab" value="sales">
      <div class="col-sm-5">
        <input type="text" name="q" class="form-control form-control-sm" placeholder="Search sale no or customer..." value="<?= e($fSearch) ?>">
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-search me-1"></i>Search</button>
        <a href="store.php?tab=sales" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0 fw-bold"><i class="fas fa-receipt me-2 text-success"></i>Sales History</h6>
    <span class="badge bg-secondary"><?= count($sales) ?> sale<?= count($sales)!=1?'s':'' ?></span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Sale No.</th>
            <th>Customer</th>
            <th>Items</th>
            <th>Subtotal</th>
            <th>Discount</th>
            <th>Total</th>
            <th>Payment</th>
            <th>Date</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($sales)): ?>
          <tr><td colspan="8" class="text-center text-muted py-5"><i class="fas fa-receipt fa-2x d-block mb-2"></i>No sales recorded yet.</td></tr>
          <?php else: foreach ($sales as $sale):
            $pmColors = ['cash'=>'success','mpesa'=>'success','card'=>'info','credit'=>'warning','scholarship'=>'primary'];
            $pc = $pmColors[$sale['payment_method']] ?? 'secondary';
          ?>
          <tr>
            <td class="fw-semibold text-dark"><?= e($sale['sale_no']) ?></td>
            <td><?= e($sale['customer_name'] ?: '—') ?></td>
            <td><span class="badge bg-light text-dark border"><?= $sale['line_count'] ?> item<?= $sale['line_count']!=1?'s':'' ?></span></td>
            <td><?= number_format((float)$sale['subtotal'],2) ?></td>
            <td><?= $sale['discount']>0 ? '<span class="text-danger">-'.number_format((float)$sale['discount'],2).'</span>' : '—' ?></td>
            <td class="fw-bold text-dark"><?= number_format((float)$sale['total'],2) ?></td>
            <td><span class="badge bg-<?= $pc ?>"><?= ucfirst($sale['payment_method']) ?></span></td>
            <td><small><?= date('d M Y H:i', strtotime($sale['created_at'])) ?></small></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php else: ?>
<!-- ════════════════════ CATEGORIES TAB ══════════════════ -->
<div class="row g-3">
  <div class="col-md-4">
    <div class="card">
      <div class="card-header fw-bold"><i class="fas fa-tags me-2 text-success"></i>Add / Edit Category</div>
      <div class="card-body">
        <form method="POST" id="catForm"><?= csrfField() ?>
        <input type="hidden" name="action" value="save_category">
        <input type="hidden" name="id" id="catId" value="0">
        <div class="mb-3">
          <label class="form-label fw-semibold">Category Name <span class="text-danger">*</span></label>
          <input type="text" name="name" id="catName" class="form-control" required placeholder="e.g. Textbooks">
        </div>
        <div class="d-flex gap-2">
          <button type="submit" class="btn btn-sm text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save</button>
          <button type="button" class="btn btn-sm btn-outline-secondary" onclick="resetCat()">Clear</button>
        </div>
        </form>
      </div>
    </div>
  </div>
  <div class="col-md-8">
    <div class="card">
      <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-bold"><i class="fas fa-tags me-2 text-success"></i>Categories</h6>
        <span class="badge bg-secondary"><?= count($categories) ?></span>
      </div>
      <div class="card-body p-0">
        <table class="table table-hover mb-0">
          <thead class="table-light"><tr><th>Name</th><th>Products</th><th class="text-center">Actions</th></tr></thead>
          <tbody>
            <?php if (empty($categories)): ?>
            <tr><td colspan="3" class="text-center text-muted py-4">No categories yet.</td></tr>
            <?php else: foreach ($categories as $cat): ?>
            <tr>
              <td class="fw-semibold"><?= e($cat['name']) ?></td>
              <td><span class="badge bg-light text-dark border"><?= $cat['product_count'] ?></span></td>
              <td class="text-center">
                <div class="btn-group btn-group-sm">
                  <button class="btn btn-outline-primary" onclick="editCat(<?= $cat['id'] ?>, '<?= e(addslashes($cat['name'])) ?>')" title="Edit"><i class="fas fa-edit"></i></button>
                  <button class="btn btn-outline-danger"  onclick="delCat(<?= $cat['id'] ?>, '<?= e($cat['name']) ?>')" title="Delete"><i class="fas fa-trash"></i></button>
                </div>
              </td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ── Product Modal ──────────────────────────────────────────────── -->
<div class="modal fade" id="productModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST"><?= csrfField() ?>
      <input type="hidden" name="action" value="save_product">
      <input type="hidden" name="id" id="prodId" value="0">
      <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
        <h5 class="modal-title" id="prodTitle"><i class="fas fa-box me-2"></i>Add Product</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-12">
            <label class="form-label fw-semibold">Product Name <span class="text-danger">*</span></label>
            <input type="text" name="name" id="prodName" class="form-control" required placeholder="e.g. Mathematics Textbook Grade 9">
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Category</label>
            <select name="category_id" id="prodCat" class="form-select">
              <option value="">-- Uncategorised --</option>
              <?php foreach ($categories as $cat): ?>
              <option value="<?= $cat['id'] ?>"><?= e($cat['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Unit</label>
            <select name="unit" id="prodUnit" class="form-select">
              <?php foreach (['piece','pair','set','book','box','ream','bottle','litre','kg'] as $u): ?>
              <option value="<?= $u ?>"><?= ucfirst($u) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">Selling Price</label>
            <input type="number" name="price" id="prodPrice" class="form-control" step="0.01" min="0" placeholder="0.00">
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">Stock Qty</label>
            <input type="number" name="stock_qty" id="prodStock" class="form-control" min="0" value="0">
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">Reorder Level</label>
            <input type="number" name="reorder_level" id="prodReorder" class="form-control" min="0" value="5">
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold">Description</label>
            <textarea name="description" id="prodDesc" class="form-control" rows="2" placeholder="Optional details..."></textarea>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Status</label>
            <select name="status" id="prodStatus" class="form-select">
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
            </select>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save Product</button>
      </div>
      </form>
    </div>
  </div>
</div>

<!-- ── Restock Modal ──────────────────────────────────────────────── -->
<div class="modal fade" id="restockModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <form method="POST"><?= csrfField() ?>
      <input type="hidden" name="action" value="restock">
      <input type="hidden" name="id" id="rsId">
      <div class="modal-header text-white" style="background:#1A8A4E">
        <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>Restock Product</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="mb-2 small">Adding stock to: <strong id="rsProductName"></strong></p>
        <label class="form-label fw-semibold">Quantity to Add</label>
        <input type="number" name="restock_qty" id="rsQty" class="form-control" min="1" value="1" required>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-success btn-sm"><i class="fas fa-check me-1"></i>Add Stock</button>
      </div>
      </form>
    </div>
  </div>
</div>

<!-- ── Sale Modal ─────────────────────────────────────────────────── -->
<div class="modal fade" id="saleModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST" id="saleForm"><?= csrfField() ?>
      <input type="hidden" name="action" value="save_sale">
      <input type="hidden" name="items_json" id="saleItemsJson">
      <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
        <h5 class="modal-title"><i class="fas fa-shopping-cart me-2"></i>New Sale</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <!-- Customer -->
        <div class="row g-2 mb-3">
          <div class="col-md-6">
            <label class="form-label fw-semibold small">Student (optional)</label>
            <select name="student_id" id="saleStudent" class="form-select form-select-sm" onchange="autoFillCustomer(this)">
              <option value="">-- Walk-in customer --</option>
              <?php foreach ($students as $st): ?>
              <option value="<?= $st['id'] ?>" data-name="<?= e($st['name']) ?>"><?= e($st['name']) ?> (<?= e($st['admission_no']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold small">Customer Name <span class="text-danger">*</span></label>
            <input type="text" name="customer_name" id="saleCustomer" class="form-control form-control-sm" placeholder="Name of buyer" required>
          </div>
        </div>

        <!-- Line Items -->
        <div class="table-responsive mb-2">
          <table class="table table-bordered table-sm mb-0" id="saleItemsTable">
            <thead class="table-light">
              <tr>
                <th style="min-width:220px">Product</th>
                <th style="width:80px">Qty</th>
                <th style="width:110px">Unit Price</th>
                <th style="width:110px">Subtotal</th>
                <th style="width:40px"></th>
              </tr>
            </thead>
            <tbody id="saleItemsBody">
              <!-- rows injected by JS -->
            </tbody>
          </table>
        </div>
        <button type="button" class="btn btn-sm btn-outline-success mb-3" onclick="addSaleRow()">
          <i class="fas fa-plus me-1"></i>Add Item
        </button>

        <!-- Totals -->
        <div class="row g-2 justify-content-end">
          <div class="col-md-5">
            <div class="card bg-light border-0">
              <div class="card-body p-3">
                <div class="d-flex justify-content-between mb-1"><span class="small">Subtotal</span><strong id="saleSubtotal">0.00</strong></div>
                <div class="d-flex justify-content-between mb-2 align-items-center">
                  <span class="small">Discount</span>
                  <input type="number" name="discount" id="saleDiscount" class="form-control form-control-sm w-50 text-end" min="0" step="0.01" value="0" oninput="recalcTotal()">
                </div>
                <hr class="my-1">
                <div class="d-flex justify-content-between"><span class="fw-bold">Total</span><strong class="text-success fs-5" id="saleTotal">0.00</strong></div>
              </div>
            </div>
          </div>
        </div>

        <!-- Payment + notes -->
        <div class="row g-2 mt-2">
          <div class="col-md-4">
            <label class="form-label small fw-semibold">Payment Method</label>
            <select name="payment_method" id="salePayMethod" class="form-select form-select-sm">
              <option value="cash">Cash</option>
              <option value="mpesa">M-Pesa</option>
              <option value="card">Card</option>
              <option value="credit">Credit / Account</option>
              <option value="scholarship">Scholarship</option>
            </select>
          </div>
          <div class="col-md-8">
            <label class="form-label small fw-semibold">Notes</label>
            <input type="text" name="notes" id="saleNotes" class="form-control form-control-sm" placeholder="Optional note...">
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-check me-1"></i>Process Sale</button>
      </div>
      </form>
    </div>
  </div>
</div>

<!-- Delete forms -->
<form method="POST" id="delProdForm" style="display:none"><?= csrfField() ?><input type="hidden" name="action" value="delete_product"><input type="hidden" name="id" id="delProdId"></form>
<form method="POST" id="delCatForm"  style="display:none"><?= csrfField() ?><input type="hidden" name="action" value="delete_category"><input type="hidden" name="id" id="delCatId"></form>

<?php ob_start(); ?>
<script>
// ─── Product catalog (cached from server) ──────────────────────────────
let productCatalog = [];
fetch('store.php?products_json=1').then(r=>r.json()).then(d=>{ productCatalog = d; });

// ── Product modal ──────────────────────────────────────────────────────
function openAddProduct() {
  document.getElementById('prodTitle').innerHTML = '<i class="fas fa-box me-2"></i>Add Product';
  ['prodId'].forEach(id => document.getElementById(id).value = '0');
  ['prodName','prodDesc'].forEach(id => document.getElementById(id).value = '');
  document.getElementById('prodCat').value    = '';
  document.getElementById('prodUnit').value   = 'piece';
  document.getElementById('prodPrice').value  = '';
  document.getElementById('prodStock').value  = '0';
  document.getElementById('prodReorder').value= '5';
  document.getElementById('prodStatus').value = 'active';
}
function openEditProduct(id) {
  fetch('store.php?fetch_product='+id).then(r=>r.json()).then(d=>{
    if(!d.id) return;
    document.getElementById('prodTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Product';
    document.getElementById('prodId').value      = d.id;
    document.getElementById('prodName').value    = d.name||'';
    document.getElementById('prodCat').value     = d.category_id||'';
    document.getElementById('prodUnit').value    = d.unit||'piece';
    document.getElementById('prodPrice').value   = d.price||'';
    document.getElementById('prodStock').value   = d.stock_qty||'0';
    document.getElementById('prodReorder').value = d.reorder_level||'5';
    document.getElementById('prodDesc').value    = d.description||'';
    document.getElementById('prodStatus').value  = d.status||'active';
    new bootstrap.Modal(document.getElementById('productModal')).show();
  });
}
function delProduct(id,name){
  if(confirm('Remove "'+name+'" from the store? Stock history will be lost.')){
    document.getElementById('delProdId').value=id; document.getElementById('delProdForm').submit();
  }
}

// ── Restock modal ──────────────────────────────────────────────────────
function openRestock(id, name) {
  document.getElementById('rsId').value = id;
  document.getElementById('rsProductName').textContent = name;
  document.getElementById('rsQty').value = 1;
  new bootstrap.Modal(document.getElementById('restockModal')).show();
}

// ── Categories ──────────────────────────────────────────────────────────
function editCat(id,name){ document.getElementById('catId').value=id; document.getElementById('catName').value=name; }
function resetCat(){ document.getElementById('catId').value='0'; document.getElementById('catName').value=''; }
function delCat(id,name){
  if(confirm('Delete category "'+name+'"? Products will become uncategorised.')){
    document.getElementById('delCatId').value=id; document.getElementById('delCatForm').submit();
  }
}

// ── Sale modal ─────────────────────────────────────────────────────────
function openNewSale(){
  document.getElementById('saleStudent').value   = '';
  document.getElementById('saleCustomer').value  = '';
  document.getElementById('saleDiscount').value  = '0';
  document.getElementById('salePayMethod').value = 'cash';
  document.getElementById('saleNotes').value     = '';
  document.getElementById('saleItemsBody').innerHTML = '';
  recalcTotal();
  addSaleRow();
}
function autoFillCustomer(sel){
  const opt = sel.options[sel.selectedIndex];
  if(opt.value) document.getElementById('saleCustomer').value = opt.getAttribute('data-name')||'';
}
function addSaleRow(){
  const tbody = document.getElementById('saleItemsBody');
  const idx   = tbody.rows.length;
  const opts  = productCatalog.map(p=>`<option value="${p.id}" data-price="${p.price}" data-stock="${p.stock_qty}">${p.name} (Stock: ${p.stock_qty})</option>`).join('');
  const row   = document.createElement('tr');
  row.innerHTML = `
    <td><select class="form-select form-select-sm prod-sel" onchange="updateRowPrice(this)" required><option value="">-- Select product --</option>${opts}</select></td>
    <td><input type="number" class="form-control form-control-sm row-qty" min="1" value="1" oninput="updateRowSub(this.closest('tr'))"></td>
    <td><input type="number" class="form-control form-control-sm row-price text-end" step="0.01" min="0" value="0.00" oninput="updateRowSub(this.closest('tr'))"></td>
    <td><input type="text"   class="form-control form-control-sm row-sub text-end fw-bold" readonly value="0.00"></td>
    <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove();recalcTotal()"><i class="fas fa-times"></i></button></td>`;
  tbody.appendChild(row);
}
function updateRowPrice(sel){
  const row   = sel.closest('tr');
  const price = sel.options[sel.selectedIndex].getAttribute('data-price')||'0';
  row.querySelector('.row-price').value = parseFloat(price).toFixed(2);
  updateRowSub(row);
}
function updateRowSub(row){
  const qty   = parseFloat(row.querySelector('.row-qty').value)||0;
  const price = parseFloat(row.querySelector('.row-price').value)||0;
  const sub   = (qty*price).toFixed(2);
  row.querySelector('.row-sub').value = sub;
  recalcTotal();
}
function recalcTotal(){
  let sub = 0;
  document.querySelectorAll('.row-sub').forEach(el => sub += parseFloat(el.value)||0);
  const disc  = parseFloat(document.getElementById('saleDiscount').value)||0;
  const total = Math.max(0, sub-disc);
  document.getElementById('saleSubtotal').textContent = sub.toFixed(2);
  document.getElementById('saleTotal').textContent    = total.toFixed(2);
}

// ── Intercept form submit — encode items to JSON ────────────────────────
document.getElementById('saleForm').addEventListener('submit', function(e){
  const rows  = document.querySelectorAll('#saleItemsBody tr');
  const items = [];
  let valid   = true;
  rows.forEach(row => {
    const prodId = row.querySelector('.prod-sel').value;
    const qty    = parseInt(row.querySelector('.row-qty').value)||0;
    if (!prodId || qty < 1) { valid = false; return; }
    items.push({ product_id: parseInt(prodId), qty: qty });
  });
  if(!items.length){ e.preventDefault(); alert('Add at least one item to the sale.'); return; }
  if(!valid){ e.preventDefault(); alert('Please select a product and valid quantity for every row.'); return; }
  document.getElementById('saleItemsJson').value = JSON.stringify(items);
});
</script>
<?php
$extraJs = ob_get_clean();
require_once __DIR__ . '/../../includes/footer.php';
?>
