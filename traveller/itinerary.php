<?php
/**
 * Traveller Portal — day-by-day itinerary
 */
$pageTitle = 'Itinerary';
require_once __DIR__ . '/../includes/header-traveller.php';

// Booking-specific days win; otherwise fall back to the package template
$days = [];
try {
    $stmt = $pdo->prepare("
        SELECT * FROM tour_itineraries
        WHERE org_id = ? AND booking_id = ?
        ORDER BY day_number, sort_order
    ");
    $stmt->execute([$trvOrgId, $trvBid]);
    $days = $stmt->fetchAll();

    if (empty($days) && !empty($trip['package_id'])) {
        $stmt = $pdo->prepare("
            SELECT * FROM tour_itineraries
            WHERE org_id = ? AND package_id = ? AND booking_id IS NULL
            ORDER BY day_number, sort_order
        ");
        $stmt->execute([$trvOrgId, (int)$trip['package_id']]);
        $days = $stmt->fetchAll();
    }
} catch (Throwable $e) {}

$startDate = $trip['departure_start'] ?: $trip['travel_date'];

$mealIcons = ['breakfast' => 'fa-mug-saucer', 'lunch' => 'fa-bowl-food', 'dinner' => 'fa-utensils'];
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
  <div>
    <h5 class="fw-bold mb-1"><?= e($trip['package_name'] ?: 'Your itinerary') ?></h5>
    <div class="text-muted small">
      <?php if ($startDate): ?>
      <i class="fas fa-calendar-day me-1"></i>Departing <?= formatDate($startDate) ?>
      <?php endif; ?>
      <?php if (!empty($trip['duration_days'])): ?>
      &nbsp;&middot;&nbsp;<?= (int)$trip['duration_days'] ?> days
      <?php endif; ?>
    </div>
  </div>
  <button class="btn btn-sm btn-outline-primary" onclick="window.print()">
    <i class="fas fa-print me-1"></i>Print itinerary
  </button>
</div>

<?php if (!empty($trip['package_desc'])): ?>
<div class="trv-card mb-4">
  <div class="small text-muted" style="white-space:pre-line"><?= e($trip['package_desc']) ?></div>
</div>
<?php endif; ?>

<?php if (empty($days)): ?>
<div class="trv-card text-center py-5">
  <i class="fas fa-route fa-3x mb-3 text-muted d-block"></i>
  <h6 class="fw-bold">Your itinerary is being finalised</h6>
  <p class="text-muted small mb-0">
    <?= e($operatorName) ?> will publish the day-by-day plan here before you travel.
  </p>
</div>
<?php else: ?>

<div class="position-relative ps-4" style="border-left:2px solid #e2e8f0">
  <?php foreach ($days as $d):
      $dayDate = $startDate ? date('Y-m-d', strtotime($startDate . ' +' . max(0, (int)$d['day_number'] - 1) . ' days')) : null;
      $meals   = array_filter(explode(',', (string)$d['meals_included']));
  ?>
  <div class="mb-4 position-relative">
    <div class="rounded-circle d-flex align-items-center justify-content-center text-white fw-bold position-absolute"
         style="width:32px;height:32px;background:var(--trv-blue);font-size:.78rem;left:-33px;top:8px">
      <?= (int)$d['day_number'] ?>
    </div>

    <div class="trv-card">
      <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
        <div>
          <h6 class="fw-bold mb-1"><?= e($d['title']) ?></h6>
          <div class="small text-muted d-flex flex-wrap gap-3">
            <?php if (!empty($d['location'])): ?>
            <span><i class="fas fa-location-dot me-1"></i><?= e($d['location']) ?></span>
            <?php endif; ?>
            <?php if (!empty($d['start_time'])): ?>
            <span>
              <i class="fas fa-clock me-1"></i><?= substr((string)$d['start_time'], 0, 5) ?><?= !empty($d['end_time']) ? ' – ' . substr((string)$d['end_time'], 0, 5) : '' ?>
            </span>
            <?php endif; ?>
            <?php if (!empty($d['activity_type'])): ?>
            <span><i class="fas fa-tag me-1"></i><?= e($d['activity_type']) ?></span>
            <?php endif; ?>
          </div>
        </div>
        <?php if ($dayDate): ?>
        <span class="badge rounded-pill" style="background:#eff6fb;color:#2980b9;font-weight:600">
          <?= formatDate($dayDate) ?>
        </span>
        <?php endif; ?>
      </div>

      <?php if (!empty($d['description'])): ?>
      <p class="small text-muted mb-3" style="white-space:pre-line"><?= e($d['description']) ?></p>
      <?php endif; ?>

      <?php if (!empty($d['activities'])): ?>
      <div class="mb-3">
        <div class="small fw-semibold mb-1"><i class="fas fa-list-check me-1" style="color:var(--trv-blue)"></i>Activities</div>
        <div class="small text-muted" style="white-space:pre-line"><?= e($d['activities']) ?></div>
      </div>
      <?php endif; ?>

      <div class="d-flex flex-wrap gap-3 small pt-2" style="border-top:1px solid #f1f3f5">
        <?php if (!empty($d['accommodation'])): ?>
        <span><i class="fas fa-bed me-1 text-muted"></i><?= e($d['accommodation']) ?></span>
        <?php endif; ?>
        <?php if (!empty($d['transport'])): ?>
        <span><i class="fas fa-van-shuttle me-1 text-muted"></i><?= e($d['transport']) ?></span>
        <?php endif; ?>
        <?php if ($meals): ?>
        <span>
          <?php foreach ($meals as $m): $m = trim($m); ?>
          <i class="fas <?= $mealIcons[$m] ?? 'fa-utensils' ?> me-1 text-muted" title="<?= e(ucfirst($m)) ?>"></i>
          <?php endforeach; ?>
          <span class="text-muted"><?= e(implode(', ', array_map('ucfirst', array_map('trim', $meals)))) ?></span>
        </span>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php endif; ?>

<?php
$extraJs = '<style media="print">
  #trvSidebar, #trvTopbar, footer, .btn { display: none !important; }
  #trvMain { margin-left: 0 !important; }
  #trvContent { padding: 0 !important; }
  body { background: #fff; }
  .trv-card { box-shadow: none; border: 1px solid #dde3ea; break-inside: avoid; }
</style>';
require_once __DIR__ . '/../includes/footer-traveller.php';
?>
