<?php
// ── TOUR: Quotations — build a quote, send it, convert it to a booking ──
require_once __DIR__ . '/_nav.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    require_once __DIR__ . '/_lib.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();
    denyIfReadOnly($moduleSlug);
    $user   = currentUser();
    $orgId  = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    // ── Create / update a quotation with its line items ───────
    if ($action === 'save') {
        $id            = (int)($_POST['id'] ?? 0);
        $customerId    = (int)($_POST['customer_id']  ?? 0) ?: null;
        $customerName  = sanitize($_POST['customer_name']  ?? '');
        $customerPhone = sanitize($_POST['customer_phone'] ?? '');
        $customerEmail = sanitize($_POST['customer_email'] ?? '');
        $packageId     = (int)($_POST['package_id']   ?? 0) ?: null;
        $departureId   = (int)($_POST['departure_id'] ?? 0) ?: null;
        $travelDate    = ($_POST['travel_date'] ?? '') ?: null;
        $adults        = max(0, (int)($_POST['adults']   ?? 1));
        $children      = max(0, (int)($_POST['children'] ?? 0));
        $discount      = max(0, (float)($_POST['discount'] ?? 0));
        $taxRate       = max(0, (float)($_POST['tax_rate'] ?? 0));
        $validUntil    = ($_POST['valid_until'] ?? '') ?: null;
        $status        = sanitize($_POST['status'] ?? 'draft');
        $notes         = sanitize($_POST['notes'] ?? '');
        $terms         = sanitize($_POST['terms'] ?? '');

        if ($customerName === '' || ($adults + $children) < 1) {
            setFlash('danger', 'Customer name and at least one traveller are required.');
            redirect('quotations.php');
        }

        // Rebuild line items from the posted rows
        $descs  = $_POST['item_desc']  ?? [];
        $qtys   = $_POST['item_qty']   ?? [];
        $prices = $_POST['item_price'] ?? [];
        $items    = [];
        $subtotal = 0.0;
        foreach ($descs as $i => $desc) {
            $desc = sanitize((string)$desc);
            if ($desc === '') continue;
            $qty   = max(0, (float)($qtys[$i]   ?? 0));
            $price = max(0, (float)($prices[$i] ?? 0));
            $line  = round($qty * $price, 2);
            $subtotal += $line;
            $items[] = ['desc' => $desc, 'qty' => $qty, 'price' => $price, 'line' => $line];
        }
        if (empty($items)) {
            setFlash('danger', 'Add at least one priced line to the quotation.');
            redirect('quotations.php');
        }
        if ($discount > $subtotal) {
            setFlash('danger', 'Discount cannot exceed the quotation subtotal.');
            redirect('quotations.php');
        }

        $taxable   = $subtotal - $discount;
        $taxAmount = round($taxable * ($taxRate / 100), 2);
        $total     = round($taxable + $taxAmount, 2);

        try {
            $pdo->beginTransaction();

            if ($id === 0) {
                $quoteNo = tourNextNumber($orgId, 'tour_quotations', 'quote_no', tourConf($orgId, 't_quote_prefix'));
                $stmt = $pdo->prepare("
                    INSERT INTO tour_quotations
                        (org_id, quote_no, customer_id, customer_name, customer_phone, customer_email,
                         package_id, departure_id, travel_date, adults, children,
                         subtotal, discount, tax_rate, tax_amount, total_amount,
                         valid_until, status, notes, terms, created_by)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                ");
                $stmt->execute([$orgId, $quoteNo, $customerId, $customerName, $customerPhone, $customerEmail,
                                $packageId, $departureId, $travelDate, $adults, $children,
                                $subtotal, $discount, $taxRate, $taxAmount, $total,
                                $validUntil, $status, $notes, $terms, (int)$user['id']]);
                $id = (int)$pdo->lastInsertId();
                $msg = 'Quotation ' . $quoteNo . ' created.';
                logActivity('create', 'tour', "Created quotation $quoteNo for $customerName");
            } else {
                // Locked once converted — the booking is the source of truth from then on
                $chk = $pdo->prepare("SELECT status FROM tour_quotations WHERE id=? AND org_id=? LIMIT 1");
                $chk->execute([$id, $orgId]);
                if ($chk->fetchColumn() === 'converted') {
                    $pdo->rollBack();
                    setFlash('warning', 'This quotation has already been converted to a booking and can no longer be edited.');
                    redirect('quotations.php');
                }
                $stmt = $pdo->prepare("
                    UPDATE tour_quotations
                    SET customer_id=?, customer_name=?, customer_phone=?, customer_email=?,
                        package_id=?, departure_id=?, travel_date=?, adults=?, children=?,
                        subtotal=?, discount=?, tax_rate=?, tax_amount=?, total_amount=?,
                        valid_until=?, status=?, notes=?, terms=?, updated_at=NOW()
                    WHERE id=? AND org_id=?
                ");
                $stmt->execute([$customerId, $customerName, $customerPhone, $customerEmail,
                                $packageId, $departureId, $travelDate, $adults, $children,
                                $subtotal, $discount, $taxRate, $taxAmount, $total,
                                $validUntil, $status, $notes, $terms, $id, $orgId]);
                $msg = 'Quotation updated.';
                logActivity('update', 'tour', "Updated quotation #$id");
            }

            // Replace the item set wholesale — simpler and always consistent
            $pdo->prepare("DELETE FROM tour_quotation_items WHERE quotation_id=? AND org_id=?")->execute([$id, $orgId]);
            $ins = $pdo->prepare("
                INSERT INTO tour_quotation_items (org_id, quotation_id, description, quantity, unit_price, line_total, sort_order)
                VALUES (?,?,?,?,?,?,?)
            ");
            foreach ($items as $sort => $it) {
                $ins->execute([$orgId, $id, $it['desc'], $it['qty'], $it['price'], $it['line'], $sort]);
            }

            $pdo->commit();
            setFlash('success', $msg);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            setFlash('danger', 'Could not save the quotation. Please try again.');
        }
        redirect('quotations.php');
    }

    // ── Quick status change ───────────────────────────────────
    if ($action === 'set_status') {
        $id     = (int)($_POST['id'] ?? 0);
        $status = sanitize($_POST['status'] ?? '');
        if (!in_array($status, ['draft','sent','accepted','declined','expired'], true)) {
            setFlash('danger', 'Unknown quotation status.');
            redirect('quotations.php');
        }
        $pdo->prepare("UPDATE tour_quotations SET status=?, updated_at=NOW() WHERE id=? AND org_id=? AND status <> 'converted'")
            ->execute([$status, $id, $orgId]);
        setFlash('success', 'Quotation marked as ' . $status . '.');
        logActivity('update', 'tour', "Set quotation #$id to $status");
        redirect('quotations.php');
    }

    // ── Convert an accepted quotation into a booking ──────────
    if ($action === 'convert') {
        $id = (int)($_POST['id'] ?? 0);

        $stmt = $pdo->prepare("SELECT * FROM tour_quotations WHERE id=? AND org_id=? LIMIT 1");
        $stmt->execute([$id, $orgId]);
        $q = $stmt->fetch();

        if (!$q) {
            setFlash('danger', 'Quotation not found.');
            redirect('quotations.php');
        }
        if ($q['status'] === 'converted') {
            setFlash('warning', 'This quotation has already been converted.');
            redirect('quotations.php');
        }
        if (empty($q['package_id'])) {
            setFlash('danger', 'Attach a tour package to this quotation before converting it to a booking.');
            redirect('quotations.php');
        }

        $pax = (int)$q['adults'] + (int)$q['children'];

        // Seat check when the quote is tied to a fixed departure
        if (!empty($q['departure_id'])) {
            $ds = $pdo->prepare("SELECT seats_total, status FROM tour_departures WHERE id=? AND org_id=? LIMIT 1");
            $ds->execute([(int)$q['departure_id'], $orgId]);
            $dep = $ds->fetch();
            if (!$dep) {
                setFlash('danger', 'The departure on this quotation no longer exists.');
                redirect('quotations.php');
            }
            if (in_array($dep['status'], ['cancelled','departed','completed'], true)) {
                setFlash('danger', 'That departure is ' . $dep['status'] . ' and cannot take new bookings.');
                redirect('quotations.php');
            }
            $avail = tourSeatStatus($orgId, (int)$q['departure_id'], (int)$dep['seats_total'])['available'];
            if ($pax > $avail) {
                setFlash('danger', 'Only ' . $avail . ' seat(s) remain on that departure but this quote is for ' . $pax . '.');
                redirect('quotations.php');
            }
        }

        $travelDate = $q['travel_date'] ?: date('Y-m-d');

        try {
            $pdo->beginTransaction();

            $bookingNo = 'BK-' . strtoupper(substr(md5(uniqid((string)microtime(true), true)), 0, 8));
            $stmt = $pdo->prepare("
                INSERT INTO tour_bookings
                    (org_id, booking_no, package_id, departure_id, customer_id, quotation_id,
                     customer_name, customer_phone, customer_email, travel_date,
                     adults, children, total_amount, paid_amount, special_requests, status)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,0,?, 'pending')
            ");
            $stmt->execute([$orgId, $bookingNo, (int)$q['package_id'], $q['departure_id'] ?: null,
                            $q['customer_id'] ?: null, $id,
                            $q['customer_name'], $q['customer_phone'], $q['customer_email'], $travelDate,
                            (int)$q['adults'], (int)$q['children'], (float)$q['total_amount'], $q['notes']]);
            $bookingId = (int)$pdo->lastInsertId();

            $pdo->prepare("UPDATE tour_quotations SET status='converted', booking_id=?, updated_at=NOW() WHERE id=? AND org_id=?")
                ->execute([$bookingId, $id, $orgId]);

            $pdo->commit();
            setFlash('success', 'Quotation ' . $q['quote_no'] . ' converted to booking ' . $bookingNo . '.');
            logActivity('create', 'tour', "Converted quotation {$q['quote_no']} into booking $bookingNo");
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            setFlash('danger', 'Conversion failed. Please try again.');
        }
        redirect('bookings.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT status FROM tour_quotations WHERE id=? AND org_id=? LIMIT 1");
        $stmt->execute([$id, $orgId]);
        if ($stmt->fetchColumn() === 'converted') {
            setFlash('danger', 'A converted quotation cannot be deleted — it is the audit trail for its booking.');
        } else {
            $pdo->prepare("DELETE FROM tour_quotation_items WHERE quotation_id=? AND org_id=?")->execute([$id, $orgId]);
            $pdo->prepare("DELETE FROM tour_quotations WHERE id=? AND org_id=?")->execute([$id, $orgId]);
            setFlash('success', 'Quotation deleted.');
            logActivity('delete', 'tour', "Deleted quotation #$id");
        }
        redirect('quotations.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
require_once __DIR__ . '/_lib.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

// Flag quotes whose validity window has lapsed
try {
    $pdo->prepare("
        UPDATE tour_quotations SET status='expired'
        WHERE org_id=? AND status IN ('draft','sent') AND valid_until IS NOT NULL AND valid_until < CURDATE()
    ")->execute([$orgId]);
} catch (Throwable $e) {}

$filterStatus = sanitize($_GET['status'] ?? '');
$where  = 'q.org_id = ?';
$params = [$orgId];
if (in_array($filterStatus, ['draft','sent','accepted','declined','expired','converted'], true)) {
    $where .= ' AND q.status = ?';
    $params[] = $filterStatus;
}

$quotations = [];
try {
    $stmt = $pdo->prepare("
        SELECT q.*, p.name AS package_name, d.departure_code, b.booking_no
        FROM tour_quotations q
        LEFT JOIN tour_packages p   ON p.id = q.package_id
        LEFT JOIN tour_departures d ON d.id = q.departure_id
        LEFT JOIN tour_bookings b   ON b.id = q.booking_id
        WHERE $where
        ORDER BY q.created_at DESC
    ");
    $stmt->execute($params);
    $quotations = $stmt->fetchAll();
} catch (Throwable $e) {}

// Items grouped per quotation, for the edit modal
$itemsByQuote = [];
if ($quotations) {
    try {
        $ids = array_column($quotations, 'id');
        $in  = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT * FROM tour_quotation_items WHERE org_id=? AND quotation_id IN ($in) ORDER BY sort_order");
        $stmt->execute(array_merge([$orgId], $ids));
        foreach ($stmt->fetchAll() as $it) {
            $itemsByQuote[$it['quotation_id']][] = [
                'description' => $it['description'],
                'quantity'    => (float)$it['quantity'],
                'unit_price'  => (float)$it['unit_price'],
            ];
        }
    } catch (Throwable $e) {}
}

// Selectors
$packages = $departures = $customers = [];
try {
    $stmt = $pdo->prepare("SELECT id, name, price_per_adult, price_per_child, duration_days FROM tour_packages WHERE org_id=? AND status='active' ORDER BY name");
    $stmt->execute([$orgId]);
    $packages = $stmt->fetchAll();

    $stmt = $pdo->prepare("
        SELECT d.id, d.departure_code, d.start_date, d.package_id, d.seats_total, d.price_adult, d.price_child, p.name AS package_name
        FROM tour_departures d JOIN tour_packages p ON p.id = d.package_id
        WHERE d.org_id=? AND d.status IN ('scheduled','guaranteed') AND d.start_date >= CURDATE()
        ORDER BY d.start_date
    ");
    $stmt->execute([$orgId]);
    $departures = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT id, name, phone, email FROM tour_customers WHERE org_id=? AND status='active' ORDER BY name");
    $stmt->execute([$orgId]);
    $customers = $stmt->fetchAll();
} catch (Throwable $e) {}

$packagePricing = [];
foreach ($packages as $p) {
    $packagePricing[$p['id']] = ['adult' => (float)$p['price_per_adult'], 'child' => (float)$p['price_per_child'], 'name' => $p['name']];
}
$departurePricing = [];
foreach ($departures as $d) {
    $departurePricing[$d['id']] = [
        'package_id' => (int)$d['package_id'],
        'adult'      => $d['price_adult'] !== null ? (float)$d['price_adult'] : null,
        'child'      => $d['price_child'] !== null ? (float)$d['price_child'] : null,
        'start'      => $d['start_date'],
    ];
}
$customerBook = [];
foreach ($customers as $c) {
    $customerBook[$c['id']] = ['name' => $c['name'], 'phone' => $c['phone'], 'email' => $c['email']];
}

// Pipeline stats
$statOpen = $statAcceptedValue = $statConverted = 0;
$statOpenValue = 0.0;
foreach ($quotations as $q) {
    if (in_array($q['status'], ['draft','sent'], true)) { $statOpen++; $statOpenValue += (float)$q['total_amount']; }
    if ($q['status'] === 'accepted')  $statAcceptedValue += (float)$q['total_amount'];
    if ($q['status'] === 'converted') $statConverted++;
}
$winRate = count($quotations) > 0 ? round(($statConverted / count($quotations)) * 100) : 0;

$defaultValidity = (int)tourConf($orgId, 't_quote_validity');
$defaultTerms    = tourConf($orgId, 't_quote_terms');
$defaultTaxRate  = (float)tourConf($orgId, 't_tax_rate');
$taxLabel        = tourConf($orgId, 't_tax_label');
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-file-signature me-2" style="color:<?= $moduleColor ?>"></i>Quotations</h4>
    <p class="text-muted mb-0">Price a trip, send it to the client, then convert the accepted quote straight into a booking</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" onclick="openQuote()">
    <i class="fas fa-plus me-1"></i>New Quotation
  </button>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(41,128,185,.15);color:#2980b9"><i class="fas fa-hourglass-half"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $statOpen ?></div><div class="stat-label">Open Quotations</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-sack-dollar"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($statOpenValue) ?></div><div class="stat-label">Pipeline Value</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-thumbs-up"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($statAcceptedValue) ?></div><div class="stat-label">Accepted, Awaiting Booking</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-percent"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $winRate ?>%</div><div class="stat-label">Conversion Rate</div></div>
    </div>
  </div>
</div>

<div class="mb-3 d-flex flex-wrap gap-2">
  <a href="quotations.php" class="btn btn-sm <?= $filterStatus === '' ? 'text-white' : 'btn-outline-secondary' ?>"
     style="<?= $filterStatus === '' ? 'background:' . $moduleColor : '' ?>">All</a>
  <?php foreach (['draft','sent','accepted','declined','expired','converted'] as $st): ?>
  <a href="quotations.php?status=<?= $st ?>" class="btn btn-sm <?= $filterStatus === $st ? 'text-white' : 'btn-outline-secondary' ?>"
     style="<?= $filterStatus === $st ? 'background:' . $moduleColor : '' ?>"><?= tourLabel($st) ?></a>
  <?php endforeach; ?>
</div>

<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0" id="quotesTable">
        <thead class="table-light">
          <tr>
            <th>Quote #</th>
            <th>Client</th>
            <th>Trip</th>
            <th>Travel</th>
            <th class="text-center">Pax</th>
            <th class="text-end">Total</th>
            <th>Valid Until</th>
            <th>Status</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($quotations)): ?>
          <tr><td colspan="9" class="text-center py-5 text-muted"><i class="fas fa-file-circle-question fa-3x mb-3 d-block"></i>No quotations yet.</td></tr>
          <?php else: foreach ($quotations as $q):
              $lapsed = $q['valid_until'] && $q['valid_until'] < date('Y-m-d');
          ?>
          <tr>
            <td><code class="text-dark bg-light px-2 py-1 rounded"><?= e($q['quote_no']) ?></code></td>
            <td>
              <div class="fw-semibold text-dark"><?= e($q['customer_name']) ?></div>
              <div class="small text-muted"><?= e($q['customer_phone'] ?: $q['customer_email'] ?: '—') ?></div>
            </td>
            <td>
              <div class="fw-semibold text-dark"><?= e($q['package_name'] ?: 'Custom itinerary') ?></div>
              <?php if ($q['departure_code']): ?>
              <div class="small text-muted"><i class="fas fa-plane-departure me-1"></i><?= e($q['departure_code']) ?></div>
              <?php endif; ?>
            </td>
            <td><?= $q['travel_date'] ? formatDate($q['travel_date']) : '—' ?></td>
            <td class="text-center">
              <strong><?= (int)$q['adults'] + (int)$q['children'] ?></strong>
              <div class="small text-muted"><?= (int)$q['adults'] ?> A / <?= (int)$q['children'] ?> C</div>
            </td>
            <td class="text-end fw-bold"><?= formatCurrency((float)$q['total_amount']) ?></td>
            <td class="small <?= $lapsed ? 'text-danger' : '' ?>"><?= $q['valid_until'] ? formatDate($q['valid_until']) : '—' ?></td>
            <td>
              <?= statusBadge($q['status']) ?>
              <?php if ($q['booking_no']): ?>
              <div class="small text-muted mt-1"><i class="fas fa-arrow-right me-1"></i><?= e($q['booking_no']) ?></div>
              <?php endif; ?>
            </td>
            <td class="text-end" style="white-space:nowrap">
              <a href="quotation-pdf.php?id=<?= (int)$q['id'] ?>" target="_blank" class="btn btn-sm btn-outline-dark" title="Download PDF">
                <i class="fas fa-file-pdf"></i>
              </a>
              <?php if ($q['status'] !== 'converted'): ?>
              <button class="btn btn-sm btn-outline-secondary ms-1"
                      onclick='editQuote(<?= json_encode($q, JSON_HEX_APOS | JSON_HEX_QUOT) ?>, <?= json_encode($itemsByQuote[$q["id"]] ?? [], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' title="Edit">
                <i class="fas fa-edit"></i>
              </button>
              <div class="btn-group ms-1">
                <button class="btn btn-sm btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown" title="Move stage">
                  <i class="fas fa-flag"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                  <?php foreach (['sent' => 'Mark as Sent', 'accepted' => 'Mark as Accepted', 'declined' => 'Mark as Declined'] as $st => $label): ?>
                  <li>
                    <form method="POST" class="m-0">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="set_status">
                      <input type="hidden" name="id" value="<?= (int)$q['id'] ?>">
                      <input type="hidden" name="status" value="<?= $st ?>">
                      <button type="submit" class="dropdown-item small"><?= $label ?></button>
                    </form>
                  </li>
                  <?php endforeach; ?>
                  <li><hr class="dropdown-divider"></li>
                  <li>
                    <form method="POST" class="m-0" onsubmit="return confirm('Convert this quotation into a confirmed booking?')">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="convert">
                      <input type="hidden" name="id" value="<?= (int)$q['id'] ?>">
                      <button type="submit" class="dropdown-item small fw-semibold text-success">
                        <i class="fas fa-right-left me-1"></i>Convert to Booking
                      </button>
                    </form>
                  </li>
                </ul>
              </div>
              <form method="POST" class="d-inline ms-1" onsubmit="return confirm('Delete this quotation?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$q['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
              </form>
              <?php else: ?>
              <a href="bookings.php" class="btn btn-sm btn-outline-success ms-1" title="View booking"><i class="fas fa-calendar-check"></i></a>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ── Quotation Modal ───────────────────────────────────────── -->
<div class="modal fade" id="quoteModal" tabindex="-1">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="qId" value="">
        <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
          <h5 class="modal-title" id="qTitle"><i class="fas fa-plus me-2"></i>New Quotation</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <!-- Client -->
            <div class="col-md-3">
              <label class="form-label fw-semibold">Existing Client</label>
              <select id="qCustomer" name="customer_id" class="form-select" onchange="fillCustomer()">
                <option value="">-- New / walk-in --</option>
                <?php foreach ($customers as $c): ?>
                <option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Client Name <span class="text-danger">*</span></label>
              <input type="text" name="customer_name" id="qName" class="form-control" required>
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Phone</label>
              <input type="text" name="customer_phone" id="qPhone" class="form-control">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Email</label>
              <input type="email" name="customer_email" id="qEmail" class="form-control">
            </div>

            <!-- Trip -->
            <div class="col-md-3">
              <label class="form-label fw-semibold">Tour Package</label>
              <select name="package_id" id="qPackage" class="form-select" onchange="onPackageChange()">
                <option value="">-- Custom itinerary --</option>
                <?php foreach ($packages as $p): ?>
                <option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Fixed Departure</label>
              <select name="departure_id" id="qDeparture" class="form-select" onchange="onDepartureChange()">
                <option value="">-- Private / flexible date --</option>
                <?php foreach ($departures as $d): ?>
                <option value="<?= (int)$d['id'] ?>" data-package="<?= (int)$d['package_id'] ?>">
                  <?= e($d['departure_code'] . ' — ' . $d['package_name'] . ' (' . formatDate($d['start_date']) . ')') ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label fw-semibold">Travel Date</label>
              <input type="date" name="travel_date" id="qTravel" class="form-control">
            </div>
            <div class="col-md-2">
              <label class="form-label fw-semibold">Adults</label>
              <input type="number" name="adults" id="qAdults" class="form-control" min="0" value="1">
            </div>
            <div class="col-md-2">
              <label class="form-label fw-semibold">Children</label>
              <input type="number" name="children" id="qChildren" class="form-control" min="0" value="0">
            </div>

            <!-- Line items -->
            <div class="col-12">
              <div class="d-flex align-items-center justify-content-between mt-2 mb-2">
                <label class="form-label fw-semibold mb-0">Quotation Lines</label>
                <div>
                  <button type="button" class="btn btn-sm btn-outline-primary" onclick="pricePackageLines()">
                    <i class="fas fa-wand-magic-sparkles me-1"></i>Price From Package
                  </button>
                  <button type="button" class="btn btn-sm btn-outline-secondary ms-1" onclick="addLine()">
                    <i class="fas fa-plus me-1"></i>Add Line
                  </button>
                </div>
              </div>
              <div class="table-responsive border rounded">
                <table class="table table-sm align-middle mb-0">
                  <thead class="table-light">
                    <tr>
                      <th style="width:50%">Description</th>
                      <th style="width:12%" class="text-end">Qty</th>
                      <th style="width:18%" class="text-end">Unit Price</th>
                      <th style="width:15%" class="text-end">Line Total</th>
                      <th style="width:5%"></th>
                    </tr>
                  </thead>
                  <tbody id="qLines"></tbody>
                </table>
              </div>
            </div>

            <!-- Totals -->
            <div class="col-md-7">
              <label class="form-label fw-semibold">Client Notes</label>
              <textarea name="notes" id="qNotes" class="form-control" rows="2" placeholder="What is included, meeting arrangements, anything the client should know"></textarea>
              <label class="form-label fw-semibold mt-3">Terms &amp; Conditions</label>
              <textarea name="terms" id="qTerms" class="form-control" rows="3"></textarea>
            </div>
            <div class="col-md-5">
              <div class="border rounded p-3 bg-light">
                <div class="d-flex justify-content-between mb-2">
                  <span class="text-muted">Subtotal</span>
                  <span class="fw-semibold" id="qSubtotal"><?= CURRENCY ?> 0.00</span>
                </div>
                <div class="row g-2 align-items-center mb-2">
                  <div class="col-6"><label class="form-label small mb-0">Discount (<?= CURRENCY ?>)</label></div>
                  <div class="col-6"><input type="number" step="0.01" min="0" name="discount" id="qDiscount" class="form-control form-control-sm text-end" value="0.00" oninput="recalc()"></div>
                </div>
                <div class="row g-2 align-items-center mb-2">
                  <div class="col-6"><label class="form-label small mb-0"><?= e($taxLabel) ?> (%)</label></div>
                  <div class="col-6"><input type="number" step="0.01" min="0" name="tax_rate" id="qTaxRate" class="form-control form-control-sm text-end" value="<?= $defaultTaxRate ?>" oninput="recalc()"></div>
                </div>
                <div class="d-flex justify-content-between mb-2">
                  <span class="text-muted"><?= e($taxLabel) ?> Amount</span>
                  <span id="qTaxAmount"><?= CURRENCY ?> 0.00</span>
                </div>
                <hr class="my-2">
                <div class="d-flex justify-content-between">
                  <span class="fw-bold">Total</span>
                  <span class="fs-5 fw-bold text-success" id="qTotal"><?= CURRENCY ?> 0.00</span>
                </div>
                <hr class="my-2">
                <div class="row g-2">
                  <div class="col-7">
                    <label class="form-label small mb-0">Valid Until</label>
                    <input type="date" name="valid_until" id="qValid" class="form-control form-control-sm">
                  </div>
                  <div class="col-5">
                    <label class="form-label small mb-0">Status</label>
                    <select name="status" id="qStatus" class="form-select form-select-sm">
                      <option value="draft">Draft</option>
                      <option value="sent">Sent</option>
                      <option value="accepted">Accepted</option>
                      <option value="declined">Declined</option>
                    </select>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save Quotation</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraJs = '<script>
const packagePricing   = ' . json_encode($packagePricing) . ';
const departurePricing = ' . json_encode($departurePricing) . ';
const customerBook     = ' . json_encode($customerBook) . ';
const CUR              = "' . CURRENCY . '";
const defaultValidity  = ' . $defaultValidity . ';
const defaultTerms     = ' . json_encode($defaultTerms) . ';
let quoteModal;

$(document).ready(function(){
  $("#quotesTable").DataTable({pageLength:15, order:[[0,"desc"]]});
  quoteModal = new bootstrap.Modal(document.getElementById("quoteModal"));
});

function money(n) {
  return CUR + " " + (Number(n) || 0).toFixed(2).replace(/\d(?=(\d{3})+\.)/g, "$&,");
}

/* ── Line rows ─────────────────────────────────────────────── */
function lineRow(desc, qty, price) {
  return `<tr>
    <td><input type="text" name="item_desc[]" class="form-control form-control-sm" value="${desc || ""}" placeholder="e.g. 3-day safari, adult"></td>
    <td><input type="number" step="0.01" min="0" name="item_qty[]" class="form-control form-control-sm text-end line-qty" value="${qty != null ? qty : 1}" oninput="recalc()"></td>
    <td><input type="number" step="0.01" min="0" name="item_price[]" class="form-control form-control-sm text-end line-price" value="${price != null ? price : 0}" oninput="recalc()"></td>
    <td class="text-end fw-semibold line-total">${money((qty || 0) * (price || 0))}</td>
    <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger" onclick="$(this).closest(\'tr\').remove();recalc()"><i class="fas fa-times"></i></button></td>
  </tr>`;
}

function addLine(desc, qty, price) {
  $("#qLines").append(lineRow(desc, qty, price));
  recalc();
}

function setLines(items) {
  $("#qLines").empty();
  if (!items || !items.length) { addLine("", 1, 0); return; }
  items.forEach(function(it){ $("#qLines").append(lineRow(it.description, it.quantity, it.unit_price)); });
  recalc();
}

function recalc() {
  let subtotal = 0;
  $("#qLines tr").each(function(){
    const qty   = parseFloat($(this).find(".line-qty").val())   || 0;
    const price = parseFloat($(this).find(".line-price").val()) || 0;
    const line  = qty * price;
    subtotal += line;
    $(this).find(".line-total").text(money(line));
  });
  const discount = parseFloat($("#qDiscount").val()) || 0;
  const taxRate  = parseFloat($("#qTaxRate").val())  || 0;
  const taxable  = Math.max(0, subtotal - discount);
  const tax      = taxable * (taxRate / 100);

  $("#qSubtotal").text(money(subtotal));
  $("#qTaxAmount").text(money(tax));
  $("#qTotal").text(money(taxable + tax));
}

/* ── Prefill helpers ───────────────────────────────────────── */
function fillCustomer() {
  const c = customerBook[$("#qCustomer").val()];
  if (!c) return;
  $("#qName").val(c.name || "");
  $("#qPhone").val(c.phone || "");
  $("#qEmail").val(c.email || "");
}

/* Keep the departure list honest: only show runs of the chosen package */
function onPackageChange() {
  const pkg = $("#qPackage").val();
  $("#qDeparture option").each(function(){
    const dp = $(this).data("package");
    if (!dp) return;
    $(this).prop("hidden", !!pkg && String(dp) !== String(pkg));
  });
  const sel = $("#qDeparture").find("option:selected");
  if (sel.prop("hidden")) $("#qDeparture").val("");
}

function onDepartureChange() {
  const dep = departurePricing[$("#qDeparture").val()];
  if (!dep) return;
  $("#qPackage").val(dep.package_id);
  if (dep.start) $("#qTravel").val(dep.start);
  onPackageChange();
}

/* Build adult/child lines from package (or departure override) pricing */
function pricePackageLines() {
  const pkgId  = $("#qPackage").val();
  const pkg    = packagePricing[pkgId];
  if (!pkg) { alert("Select a tour package first."); return; }

  const dep      = departurePricing[$("#qDeparture").val()];
  const adultFee = dep && dep.adult !== null ? dep.adult : pkg.adult;
  const childFee = dep && dep.child !== null ? dep.child : pkg.child;
  const adults   = parseInt($("#qAdults").val())   || 0;
  const children = parseInt($("#qChildren").val()) || 0;

  $("#qLines").empty();
  if (adults > 0)   $("#qLines").append(lineRow(pkg.name + " — adult", adults, adultFee));
  if (children > 0) $("#qLines").append(lineRow(pkg.name + " — child", children, childFee));
  if (!adults && !children) $("#qLines").append(lineRow(pkg.name, 1, adultFee));
  recalc();
}

/* ── Modal open / edit ─────────────────────────────────────── */
function openQuote() {
  $("#qTitle").html("<i class=\"fas fa-plus me-2\"></i>New Quotation");
  $("#qId,#qCustomer,#qName,#qPhone,#qEmail,#qPackage,#qDeparture,#qTravel,#qNotes").val("");
  $("#qAdults").val("1");
  $("#qChildren").val("0");
  $("#qDiscount").val("0.00");
  $("#qTaxRate").val("' . $defaultTaxRate . '");
  $("#qStatus").val("draft");
  $("#qTerms").val(defaultTerms);

  const valid = new Date();
  valid.setDate(valid.getDate() + defaultValidity);
  $("#qValid").val(valid.toISOString().slice(0, 10));

  $("#qDeparture option").prop("hidden", false);
  setLines([]);
  quoteModal.show();
}

function editQuote(q, items) {
  $("#qTitle").html("<i class=\"fas fa-edit me-2\"></i>Edit " + (q.quote_no || "Quotation"));
  $("#qId").val(q.id);
  $("#qCustomer").val(q.customer_id || "");
  $("#qName").val(q.customer_name || "");
  $("#qPhone").val(q.customer_phone || "");
  $("#qEmail").val(q.customer_email || "");
  $("#qPackage").val(q.package_id || "");
  $("#qDeparture option").prop("hidden", false);
  $("#qDeparture").val(q.departure_id || "");
  $("#qTravel").val(q.travel_date || "");
  $("#qAdults").val(q.adults || 0);
  $("#qChildren").val(q.children || 0);
  $("#qDiscount").val(q.discount || "0.00");
  $("#qTaxRate").val(q.tax_rate || "0");
  $("#qValid").val(q.valid_until || "");
  $("#qStatus").val(q.status === "converted" || q.status === "expired" ? "draft" : (q.status || "draft"));
  $("#qNotes").val(q.notes || "");
  $("#qTerms").val(q.terms || "");
  setLines(items);
  quoteModal.show();
}
</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
