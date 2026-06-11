<?php
/**
 * Public Parcel Tracking Portal — No login required.
 * Anyone with a tracking number can look up their parcel status.
 */
$trackingNo = strtoupper(trim($_GET['t'] ?? ''));
$courier    = null;
$history    = [];
$error      = '';

if ($trackingNo !== '') {
    require_once __DIR__ . '/config/database.php';
    try {
        $st = $pdo->prepare("
            SELECT c.*, b.name AS branch_name, a.first_name AS agent_first, a.last_name AS agent_last
            FROM couriers c
            LEFT JOIN courier_branches b ON b.id = c.branch_id
            LEFT JOIN courier_agents a ON a.id = c.agent_id
            WHERE c.tracking_id = ?
            ORDER BY c.created_at DESC
            LIMIT 1
        ");
        $st->execute([$trackingNo]);
        $courier = $st->fetch();

        if ($courier) {
            try {
                $ht = $pdo->prepare("
                    SELECT h.stage_code, h.stage_name, h.location, h.notes, h.lat, h.lng, h.created_at
                    FROM courier_tracking_history h
                    WHERE h.courier_id = ?
                    ORDER BY h.created_at ASC
                ");
                $ht->execute([$courier['id']]);
                $history = $ht->fetchAll();
            } catch (Throwable $e) {}
        } else {
            $error = 'No parcel found for tracking number <strong>' . htmlspecialchars($trackingNo, ENT_QUOTES) . '</strong>. Please check the number and try again.';
        }
    } catch (Throwable $e) {
        $error = 'Tracking service is temporarily unavailable. Please try again later.';
    }
}

$stageIcons = [
    'pending'          => ['icon' => 'fas fa-clock',          'color' => '#f39c12'],
    'processing'       => ['icon' => 'fas fa-cog',            'color' => '#17a2b8'],
    'picked_up'        => ['icon' => 'fas fa-box',            'color' => '#0d6efd'],
    'in_transit'       => ['icon' => 'fas fa-truck',          'color' => '#0d6efd'],
    'out_for_delivery' => ['icon' => 'fas fa-motorcycle',     'color' => '#17a2b8'],
    'delivered'        => ['icon' => 'fas fa-check-circle',   'color' => '#198754'],
    'failed_delivery'  => ['icon' => 'fas fa-times-circle',   'color' => '#dc3545'],
    'failed'           => ['icon' => 'fas fa-times-circle',   'color' => '#dc3545'],
    'returned'         => ['icon' => 'fas fa-undo',           'color' => '#6c757d'],
    'cancelled'        => ['icon' => 'fas fa-ban',            'color' => '#6c757d'],
];

$statusLabels = [
    'pending'          => 'Order Pending',
    'processing'       => 'Processing',
    'picked_up'        => 'Picked Up',
    'in_transit'       => 'In Transit',
    'out_for_delivery' => 'Out for Delivery',
    'delivered'        => 'Delivered',
    'failed_delivery'  => 'Delivery Failed',
    'failed'           => 'Delivery Failed',
    'returned'         => 'Returned to Sender',
    'cancelled'        => 'Cancelled',
];

$appName = defined('APP_NAME') ? APP_NAME : 'OrbitDesk';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Track Your Parcel — <?= htmlspecialchars($appName, ENT_QUOTES) ?></title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>
    body { background: #f0f4f8; font-family: 'Segoe UI', sans-serif; }
    .hero {
      background: linear-gradient(135deg, #1565c0 0%, #0d47a1 100%);
      padding: 3rem 1rem 4rem;
      color: #fff;
    }
    .hero h1 { font-size: 2rem; font-weight: 700; }
    .search-card {
      background: #fff;
      border-radius: 16px;
      padding: 2rem;
      box-shadow: 0 8px 32px rgba(0,0,0,.12);
      margin-top: -2.5rem;
    }
    .tracking-card {
      background: #fff;
      border-radius: 16px;
      box-shadow: 0 4px 24px rgba(0,0,0,.08);
      overflow: hidden;
    }
    .tracking-header {
      background: linear-gradient(135deg, #1565c0, #1976d2);
      color: #fff;
      padding: 1.5rem;
    }
    .status-pill {
      display: inline-block;
      padding: .35rem .9rem;
      border-radius: 20px;
      font-size: .8rem;
      font-weight: 600;
      letter-spacing: .5px;
      text-transform: uppercase;
      background: rgba(255,255,255,.2);
      color: #fff;
    }
    .timeline-wrap { padding: 1.5rem; }
    .tl-item { display: flex; gap: 1rem; margin-bottom: 1.5rem; }
    .tl-dot {
      flex-shrink: 0;
      width: 40px;
      height: 40px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      color: #fff;
      font-size: .85rem;
    }
    .tl-line {
      width: 2px;
      background: #dee2e6;
      margin: 4px auto 0;
      flex-grow: 1;
    }
    .tl-connector { display: flex; flex-direction: column; align-items: center; flex-shrink: 0; width: 40px; }
    .tl-body { flex: 1; padding-bottom: 1rem; }
    .tl-stage { font-weight: 700; font-size: .95rem; }
    .tl-meta { font-size: .8rem; color: #6c757d; }
    .info-row { display: flex; flex-wrap: wrap; gap: .5rem 1.5rem; font-size: .88rem; }
    .info-row span { color: #666; }
    .info-row strong { color: #222; }
    .pod-badge { background: #d4edda; color: #155724; border-radius: 8px; padding: .4rem .8rem; font-size: .82rem; }
    .empty-state { text-align: center; padding: 3rem 1rem; color: #888; }
    footer { text-align: center; color: #aaa; font-size: .8rem; padding: 2rem 0; }
  </style>
</head>
<body>

<!-- Hero -->
<div class="hero text-center">
  <div class="container">
    <i class="fas fa-shipping-fast fa-2x mb-2 opacity-75"></i>
    <h1>Track Your Parcel</h1>
    <p class="mb-0 opacity-75">Enter your tracking number to see the latest delivery status</p>
  </div>
</div>

<div class="container" style="max-width:680px">

  <!-- Search Box -->
  <div class="search-card mb-4">
    <form method="GET" action="track.php">
      <label class="form-label fw-bold mb-2" style="color:#1565c0">
        <i class="fas fa-barcode me-1"></i>Tracking Number
      </label>
      <div class="input-group input-group-lg">
        <input type="text" name="t" class="form-control text-uppercase font-monospace"
               placeholder="e.g. CR-2025-0001"
               value="<?= htmlspecialchars($trackingNo, ENT_QUOTES) ?>"
               style="letter-spacing:.1rem">
        <button class="btn text-white fw-bold" style="background:#1565c0" type="submit">
          <i class="fas fa-search me-1"></i>Track
        </button>
      </div>
      <div class="text-muted small mt-2">
        <i class="fas fa-info-circle me-1"></i>
        Tracking numbers are provided at the time of booking. Check your receipt or SMS.
      </div>
    </form>
  </div>

  <?php if ($error): ?>
  <div class="alert alert-danger d-flex align-items-center mb-4" role="alert">
    <i class="fas fa-exclamation-circle me-2 fa-lg"></i>
    <span><?= $error ?></span>
  </div>
  <?php endif; ?>

  <?php if ($courier): ?>
  <?php
    // Build GPS points for map
    $gpsPoints = [];
    foreach ($history as $h) {
        if (!empty($h['lat']) && !empty($h['lng'])) {
            $gpsPoints[] = [
                'lat'  => (float)$h['lat'],
                'lng'  => (float)$h['lng'],
                'name' => htmlspecialchars($h['stage_name'] ?: $h['stage_code'], ENT_QUOTES),
                'loc'  => htmlspecialchars($h['location'] ?? '', ENT_QUOTES),
                'time' => date('d M Y, g:i A', strtotime($h['created_at'])),
            ];
        }
    }
    $status       = $courier['status'] ?? 'pending';
    $stageInfo    = $stageIcons[$status] ?? ['icon' => 'fas fa-circle', 'color' => '#6c757d'];
    $statusLabel  = $statusLabels[$status] ?? ucfirst(str_replace('_', ' ', $status));
    $isDelivered  = $status === 'delivered';
    $isFailed     = in_array($status, ['failed_delivery', 'failed']);
  ?>
  <div class="tracking-card mb-4">

    <!-- Header -->
    <div class="tracking-header">
      <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
          <div class="opacity-75 small mb-1"><i class="fas fa-hashtag me-1"></i>Tracking Number</div>
          <div class="fw-bold fs-5 font-monospace"><?= htmlspecialchars($courier['tracking_id'], ENT_QUOTES) ?></div>
        </div>
        <span class="status-pill"><?= htmlspecialchars($statusLabel, ENT_QUOTES) ?></span>
      </div>
    </div>

    <!-- Summary -->
    <div class="p-3 border-bottom bg-light">
      <div class="row g-3">
        <div class="col-sm-6">
          <div class="opacity-60 small fw-semibold text-uppercase mb-1">From</div>
          <div class="fw-semibold"><?= htmlspecialchars($courier['sender_name'], ENT_QUOTES) ?></div>
          <?php if (!empty($courier['sender_address'])): ?>
          <div class="text-muted small"><?= htmlspecialchars($courier['sender_address'], ENT_QUOTES) ?></div>
          <?php endif; ?>
        </div>
        <div class="col-sm-6">
          <div class="opacity-60 small fw-semibold text-uppercase mb-1">To</div>
          <div class="fw-semibold"><?= htmlspecialchars($courier['receiver_name'], ENT_QUOTES) ?></div>
          <?php if (!empty($courier['receiver_address'])): ?>
          <div class="text-muted small"><?= htmlspecialchars($courier['receiver_address'], ENT_QUOTES) ?></div>
          <?php endif; ?>
        </div>
      </div>

      <hr class="my-2">
      <div class="info-row">
        <?php if (!empty($courier['pickup_date'])): ?>
        <div><span>Pickup: </span><strong><?= date('d M Y', strtotime($courier['pickup_date'])) ?></strong></div>
        <?php endif; ?>
        <?php if (!empty($courier['expected_delivery'])): ?>
        <div><span>Expected: </span><strong><?= date('d M Y', strtotime($courier['expected_delivery'])) ?></strong></div>
        <?php endif; ?>
        <?php if (!empty($courier['actual_delivery'])): ?>
        <div><span>Delivered: </span><strong class="text-success"><?= date('d M Y', strtotime($courier['actual_delivery'])) ?></strong></div>
        <?php endif; ?>
        <?php if (!empty($courier['branch_name'])): ?>
        <div><span>Branch: </span><strong><?= htmlspecialchars($courier['branch_name'], ENT_QUOTES) ?></strong></div>
        <?php endif; ?>
      </div>

      <?php if ($isDelivered && !empty($courier['pod_delivered_at'])): ?>
      <div class="pod-badge mt-2 d-inline-flex align-items-center gap-2">
        <i class="fas fa-file-signature text-success"></i>
        <span>
          <strong>Proof of Delivery captured</strong>
          <?php if (!empty($courier['pod_recipient_name'])): ?>
          — received by <strong><?= htmlspecialchars($courier['pod_recipient_name'], ENT_QUOTES) ?></strong>
          <?php endif; ?>
          on <?= date('d M Y, g:i A', strtotime($courier['pod_delivered_at'])) ?>
        </span>
      </div>
      <?php endif; ?>
    </div>

    <!-- GPS Map -->
    <?php if (!empty($gpsPoints)): ?>
    <script>window._trackPts = <?= json_encode($gpsPoints) ?>;</script>
    <div style="border-top:1px solid #e9ecef">
      <div style="padding:.75rem 1.5rem .5rem;font-weight:700;color:#1565c0;font-size:.9rem">
        <i class="fas fa-map-marked-alt me-2"></i>Live Route Map
      </div>
      <div id="publicMap" style="height:220px;width:100%"></div>
    </div>
    <?php endif; ?>

    <!-- Timeline -->
    <div class="timeline-wrap">
      <div class="fw-bold mb-3" style="color:#1565c0">
        <i class="fas fa-route me-2"></i>Delivery Journey
      </div>
      <?php if (empty($history)): ?>
      <div class="empty-state">
        <i class="fas fa-map-marker-alt fa-2x mb-2 d-block" style="color:#1565c0; opacity:.4"></i>
        <p class="mb-0">Tracking events will appear here once your parcel is picked up.</p>
      </div>
      <?php else: ?>
      <?php foreach ($history as $i => $h):
        $si    = $stageIcons[$h['stage_code']] ?? ['icon' => 'fas fa-circle', 'color' => '#1565c0'];
        $isLast = ($i === count($history) - 1);
      ?>
      <div class="tl-item">
        <div class="tl-connector">
          <div class="tl-dot" style="background:<?= $si['color'] ?>">
            <i class="<?= htmlspecialchars($si['icon'], ENT_QUOTES) ?>"></i>
          </div>
          <?php if (!$isLast): ?><div class="tl-line" style="height:48px"></div><?php endif; ?>
        </div>
        <div class="tl-body">
          <div class="tl-stage"><?= htmlspecialchars($h['stage_name'] ?: strtoupper(str_replace('_',' ',$h['stage_code'])), ENT_QUOTES) ?></div>
          <?php if (!empty($h['location'])): ?>
          <div class="tl-meta"><i class="fas fa-map-pin me-1"></i><?= htmlspecialchars($h['location'], ENT_QUOTES) ?></div>
          <?php endif; ?>
          <?php if (!empty($h['notes'])): ?>
          <div class="tl-meta mt-1"><?= htmlspecialchars($h['notes'], ENT_QUOTES) ?></div>
          <?php endif; ?>
          <div class="tl-meta mt-1"><?= date('d M Y, g:i A', strtotime($h['created_at'])) ?></div>
        </div>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <?php if ($isFailed): ?>
    <div class="mx-3 mb-3 alert alert-danger py-2 small">
      <i class="fas fa-exclamation-triangle me-2"></i>
      Delivery was unsuccessful. Please contact the sender or courier branch for re-delivery arrangements.
    </div>
    <?php endif; ?>

  </div>
  <?php endif; ?>

  <?php if (!$courier && !$error && $trackingNo === ''): ?>
  <div class="text-center py-5 text-muted">
    <i class="fas fa-shipping-fast fa-3x mb-3 d-block" style="color:#1565c0; opacity:.25"></i>
    <p class="mb-0">Enter a tracking number above to get started.</p>
  </div>
  <?php endif; ?>

  <footer><?= htmlspecialchars($appName, ENT_QUOTES) ?> &copy; <?= date('Y') ?> &middot; Parcel Tracking Portal</footer>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php if (!empty($gpsPoints ?? [])): ?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
(function () {
  const pts = window._trackPts;
  if (!pts || !pts.length) return;
  const map = L.map('publicMap', { zoomControl: true, scrollWheelZoom: false });
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© OpenStreetMap contributors', maxZoom: 19
  }).addTo(map);
  const latlngs = pts.map(p => [p.lat, p.lng]);
  if (latlngs.length > 1) {
    L.polyline(latlngs, { color: '#1565c0', weight: 3, opacity: .75, dashArray: '6 4' }).addTo(map);
  }
  pts.forEach((p, i) => {
    const isLast = i === pts.length - 1;
    const color  = isLast ? '#1565c0' : '#90caf9';
    const size   = isLast ? 14 : 9;
    const icon   = L.divIcon({
      html: `<div style="background:${color};width:${size}px;height:${size}px;border-radius:50%;border:2px solid #fff;box-shadow:0 2px 4px rgba(0,0,0,.35)"></div>`,
      className: '', iconSize: [size, size], iconAnchor: [size/2, size/2]
    });
    const m = L.marker([p.lat, p.lng], { icon }).addTo(map)
      .bindPopup(`<strong>${p.name}</strong>${p.loc ? '<br>'+p.loc : ''}<br><small>${p.time}</small>`);
    if (isLast) m.openPopup();
  });
  latlngs.length === 1 ? map.setView(latlngs[0], 13) : map.fitBounds(latlngs, { padding: [24, 24] });
})();
</script>
<?php endif; ?>
</body>
</html>
