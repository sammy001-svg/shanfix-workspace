<?php
/**
 * Traveller Portal — trip overview
 */
$pageTitle = 'My Trip';
require_once __DIR__ . '/../includes/header-traveller.php';

// Next itinerary highlights (first three days)
$highlights = [];
try {
    $stmt = $pdo->prepare("
        SELECT day_number, title, location
        FROM tour_itineraries
        WHERE org_id = ? AND (booking_id = ? OR (booking_id IS NULL AND package_id = ?))
        ORDER BY day_number, sort_order
        LIMIT 3
    ");
    $stmt->execute([$trvOrgId, $trvBid, (int)($trip['package_id'] ?? 0)]);
    $highlights = $stmt->fetchAll();
} catch (Throwable $e) {}

// Latest receipts
$recentPayments = [];
try {
    $stmt = $pdo->prepare("
        SELECT receipt_no, amount, payment_type, payment_mode, payment_date
        FROM tour_payments
        WHERE org_id = ? AND booking_id = ?
        ORDER BY payment_date DESC, id DESC
        LIMIT 4
    ");
    $stmt->execute([$trvOrgId, $trvBid]);
    $recentPayments = $stmt->fetchAll();
} catch (Throwable $e) {}

$paidPercent = $tripTotal > 0 ? min(100, (int)round(($tripPaid / $tripTotal) * 100)) : 0;
$pax         = (int)($trip['adults'] ?? 0) + (int)($trip['children'] ?? 0);

$startDate = $trip['departure_start'] ?: $trip['travel_date'];
$endDate   = $trip['departure_end'] ?: null;
if (!$endDate && !empty($trip['duration_days']) && $startDate) {
    $endDate = date('Y-m-d', strtotime($startDate . ' +' . max(0, (int)$trip['duration_days'] - 1) . ' days'));
}
?>

<!-- ── Hero ─────────────────────────────────────────────────── -->
<div class="trv-hero mb-4">
  <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
    <div>
      <div class="small text-uppercase" style="letter-spacing:1px;opacity:.75">
        <?= e($trip['dest_name'] ? $trip['dest_name'] . ($trip['dest_country'] ? ', ' . $trip['dest_country'] : '') : 'Your journey') ?>
      </div>
      <h3 class="mb-2 mt-1 fw-bold"><?= e($trip['package_name'] ?: 'Your trip') ?></h3>
      <div class="d-flex flex-wrap gap-3 small" style="opacity:.9">
        <span><i class="fas fa-hashtag me-1"></i><?= e($trip['booking_no']) ?></span>
        <?php if ($startDate): ?>
        <span><i class="fas fa-calendar-day me-1"></i><?= formatDate($startDate) ?><?= $endDate ? ' &rarr; ' . formatDate($endDate) : '' ?></span>
        <?php endif; ?>
        <span><i class="fas fa-users me-1"></i><?= $pax ?> traveller<?= $pax === 1 ? '' : 's' ?></span>
        <?php if (!empty($trip['departure_code'])): ?>
        <span><i class="fas fa-plane-departure me-1"></i><?= e($trip['departure_code']) ?></span>
        <?php endif; ?>
      </div>
    </div>
    <div class="text-end">
      <?= statusBadge($trip['status']) ?>
      <?php if ($daysToGo !== null && $daysToGo > 0 && $trip['status'] !== 'cancelled'): ?>
      <div class="mt-2">
        <div class="display-6 fw-bold lh-1"><?= $daysToGo ?></div>
        <div class="small" style="opacity:.8">day<?= $daysToGo === 1 ? '' : 's' ?> to go</div>
      </div>
      <?php elseif ($daysToGo === 0): ?>
      <div class="mt-2 fw-bold">Departing today &#9992;</div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if ($trip['status'] === 'cancelled'): ?>
<div class="alert alert-secondary d-flex align-items-center">
  <i class="fas fa-circle-info me-2"></i>
  <div>This booking has been cancelled. Please contact <?= e($operatorName) ?> if that is unexpected.</div>
</div>
<?php endif; ?>

<!-- ── Money ────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
  <div class="col-md-4">
    <div class="trv-card">
      <div class="d-flex align-items-center gap-3">
        <div class="trv-stat-icon" style="background:#eff6fb;color:#2980b9"><i class="fas fa-file-invoice-dollar"></i></div>
        <div>
          <div class="text-muted small">Trip Total</div>
          <div class="fs-5 fw-bold"><?= formatCurrency($tripTotal) ?></div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="trv-card">
      <div class="d-flex align-items-center gap-3">
        <div class="trv-stat-icon" style="background:#dcfce7;color:#15803d"><i class="fas fa-circle-check"></i></div>
        <div>
          <div class="text-muted small">Paid So Far</div>
          <div class="fs-5 fw-bold text-success"><?= formatCurrency($tripPaid) ?></div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="trv-card">
      <div class="d-flex align-items-center gap-3">
        <div class="trv-stat-icon" style="background:<?= $tripBalance > 0 ? '#fee2e2;color:#dc2626' : '#dcfce7;color:#15803d' ?>">
          <i class="fas <?= $tripBalance > 0 ? 'fa-hourglass-half' : 'fa-check-double' ?>"></i>
        </div>
        <div>
          <div class="text-muted small">Balance Due</div>
          <div class="fs-5 fw-bold <?= $tripBalance > 0 ? 'text-danger' : 'text-success' ?>">
            <?= $tripBalance > 0 ? formatCurrency($tripBalance) : 'Settled' ?>
          </div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-12">
    <div class="trv-card">
      <div class="d-flex justify-content-between small mb-2">
        <span class="fw-semibold">Payment progress</span>
        <span class="text-muted"><?= $paidPercent ?>% settled</span>
      </div>
      <div class="progress" style="height:8px">
        <div class="progress-bar <?= $paidPercent >= 100 ? 'bg-success' : '' ?>"
             style="width:<?= $paidPercent ?>%;<?= $paidPercent < 100 ? 'background:#2980b9' : '' ?>"></div>
      </div>
      <?php if ($tripBalance > 0): ?>
      <div class="small text-muted mt-2">
        <i class="fas fa-circle-info me-1"></i>Your balance is due before travel.
        <a href="payments.php" class="text-decoration-none">View payment details</a>.
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="row g-3">
  <!-- ── Travel details ─────────────────────────────────────── -->
  <div class="col-lg-7">
    <div class="trv-card">
      <h6 class="fw-bold mb-3"><i class="fas fa-map-location-dot me-2" style="color:var(--trv-blue)"></i>Travel Details</h6>
      <div class="row g-3 small">
        <div class="col-sm-6">
          <div class="text-muted">Departure date</div>
          <div class="fw-semibold"><?= $startDate ? formatDate($startDate) : 'To be confirmed' ?></div>
        </div>
        <div class="col-sm-6">
          <div class="text-muted">Return date</div>
          <div class="fw-semibold"><?= $endDate ? formatDate($endDate) : 'To be confirmed' ?></div>
        </div>
        <div class="col-sm-6">
          <div class="text-muted">Travellers</div>
          <div class="fw-semibold"><?= (int)$trip['adults'] ?> adult<?= (int)$trip['adults'] === 1 ? '' : 's' ?><?= (int)$trip['children'] > 0 ? ', ' . (int)$trip['children'] . ' child' . ((int)$trip['children'] === 1 ? '' : 'ren') : '' ?></div>
        </div>
        <div class="col-sm-6">
          <div class="text-muted">Duration</div>
          <div class="fw-semibold"><?= !empty($trip['duration_days']) ? (int)$trip['duration_days'] . ' days' : '—' ?></div>
        </div>
        <div class="col-12">
          <div class="text-muted">Meeting point</div>
          <div class="fw-semibold"><?= e($trip['meeting_point'] ?: 'Your operator will confirm this before departure.') ?></div>
        </div>
        <?php if (!empty($trip['special_requests'])): ?>
        <div class="col-12">
          <div class="text-muted">Your requests on file</div>
          <div class="fw-semibold"><?= e($trip['special_requests']) ?></div>
        </div>
        <?php endif; ?>
      </div>

      <?php if (!empty($trip['includes']) || !empty($trip['excludes'])): ?>
      <hr class="my-3">
      <div class="row g-3 small">
        <?php if (!empty($trip['includes'])): ?>
        <div class="col-sm-6">
          <div class="fw-semibold text-success mb-1"><i class="fas fa-check me-1"></i>What's included</div>
          <div class="text-muted" style="white-space:pre-line"><?= e($trip['includes']) ?></div>
        </div>
        <?php endif; ?>
        <?php if (!empty($trip['excludes'])): ?>
        <div class="col-sm-6">
          <div class="fw-semibold text-danger mb-1"><i class="fas fa-xmark me-1"></i>Not included</div>
          <div class="text-muted" style="white-space:pre-line"><?= e($trip['excludes']) ?></div>
        </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── Crew + next steps ──────────────────────────────────── -->
  <div class="col-lg-5">
    <div class="trv-card mb-3">
      <h6 class="fw-bold mb-3"><i class="fas fa-user-tie me-2" style="color:var(--trv-blue)"></i>Your Crew</h6>
      <?php if (!empty($trip['guide_name'])): ?>
      <div class="d-flex align-items-center gap-3 mb-3">
        <div class="trv-stat-icon" style="background:#eff6fb;color:#2980b9"><i class="fas fa-hiking"></i></div>
        <div class="small">
          <div class="fw-semibold"><?= e($trip['guide_name']) ?></div>
          <div class="text-muted">Tour guide<?= !empty($trip['guide_languages']) ? ' · ' . e($trip['guide_languages']) : '' ?></div>
          <?php if (!empty($trip['guide_phone'])): ?>
          <a href="tel:<?= e($trip['guide_phone']) ?>" class="text-decoration-none"><?= e($trip['guide_phone']) ?></a>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
      <?php if (!empty($trip['vehicle_name'])): ?>
      <div class="d-flex align-items-center gap-3">
        <div class="trv-stat-icon" style="background:#eff6fb;color:#2980b9"><i class="fas fa-bus"></i></div>
        <div class="small">
          <div class="fw-semibold"><?= e($trip['vehicle_name']) ?></div>
          <div class="text-muted">Transport<?= !empty($trip['vehicle_reg']) ? ' · ' . e($trip['vehicle_reg']) : '' ?></div>
        </div>
      </div>
      <?php endif; ?>
      <?php if (empty($trip['guide_name']) && empty($trip['vehicle_name'])): ?>
      <p class="text-muted small mb-0">Your guide and transport will be assigned closer to departure.</p>
      <?php endif; ?>
    </div>

    <div class="trv-card">
      <h6 class="fw-bold mb-3"><i class="fas fa-route me-2" style="color:var(--trv-blue)"></i>Itinerary Preview</h6>
      <?php if (empty($highlights)): ?>
      <p class="text-muted small mb-0">Your day-by-day plan will appear here once your operator publishes it.</p>
      <?php else: ?>
      <?php foreach ($highlights as $h): ?>
      <div class="d-flex gap-3 mb-3">
        <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0 fw-bold text-white"
             style="width:30px;height:30px;background:var(--trv-blue);font-size:.75rem">
          <?= (int)$h['day_number'] ?>
        </div>
        <div class="small">
          <div class="fw-semibold"><?= e($h['title']) ?></div>
          <?php if (!empty($h['location'])): ?>
          <div class="text-muted"><i class="fas fa-location-dot me-1"></i><?= e($h['location']) ?></div>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
      <a href="itinerary.php" class="btn btn-sm btn-outline-primary w-100">View full itinerary</a>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if (!empty($recentPayments)): ?>
<div class="trv-card mt-3">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h6 class="fw-bold mb-0"><i class="fas fa-receipt me-2" style="color:var(--trv-blue)"></i>Recent Payments</h6>
    <a href="payments.php" class="small text-decoration-none">See all</a>
  </div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light">
        <tr><th>Receipt</th><th>Date</th><th>Method</th><th class="text-end">Amount</th></tr>
      </thead>
      <tbody>
        <?php foreach ($recentPayments as $p): ?>
        <tr>
          <td><code class="bg-light px-1 rounded"><?= e($p['receipt_no']) ?></code></td>
          <td class="small"><?= formatDate($p['payment_date']) ?></td>
          <td class="small text-muted"><?= e(ucwords(str_replace('_', ' ', $p['payment_mode']))) ?></td>
          <td class="text-end fw-semibold <?= $p['payment_type'] === 'refund' ? 'text-danger' : '' ?>">
            <?= $p['payment_type'] === 'refund' ? '−' : '' ?><?= formatCurrency((float)$p['amount']) ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer-traveller.php'; ?>
