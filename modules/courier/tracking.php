<?php
$moduleSlug  = 'courier';
$moduleName  = 'Courier Management';
$moduleIcon  = 'fas fa-shipping-fast';
$moduleColor = '#1565c0';
$moduleNav   = [
    ['url' => 'index.php',      'icon' => 'fas fa-tachometer-alt',  'label' => 'Dashboard'],
    ['url' => 'couriers.php',   'icon' => 'fas fa-box',             'label' => 'Couriers'],
    ['url' => 'tracking.php',   'icon' => 'fas fa-map-marker-alt',  'label' => 'Tracking'],
    ['url' => 'manifest.php',   'icon' => 'fas fa-clipboard-list',  'label' => 'Manifests'],
    ['url' => 'delivery.php',   'icon' => 'fas fa-truck',           'label' => 'Deliveries'],
    ['url' => 'routes.php',     'icon' => 'fas fa-route',           'label' => 'Routes'],
    ['url' => 'payments.php',   'icon' => 'fas fa-credit-card',     'label' => 'Payments'],
    ['url' => 'agents.php',     'icon' => 'fas fa-user-tie',        'label' => 'Agents'],
    ['url' => 'agreements.php', 'icon' => 'fas fa-file-contract',   'label' => 'Agreements'],
    ['url' => 'setup.php',      'icon' => 'fas fa-cog',             'label' => 'Setup'],
    ['url' => 'reports.php',    'icon' => 'fas fa-chart-bar',       'label' => 'Reports'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();denyIfReadOnly($moduleSlug);
    $user  = currentUser();
    $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'add_tracking') {
        $courierId = (int)$_POST['courier_id'];
        $stageCode = sanitize($_POST['stage_code'] ?? '');
        $stageName = sanitize($_POST['stage_name'] ?? $stageCode);
        $location  = sanitize($_POST['location'] ?? '');
        $notes     = sanitize($_POST['notes'] ?? '');
        $lat       = !empty($_POST['lat']) && is_numeric($_POST['lat']) ? round((float)$_POST['lat'], 6) : null;
        $lng       = !empty($_POST['lng']) && is_numeric($_POST['lng']) ? round((float)$_POST['lng'], 6) : null;

        // Insert tracking history
        $pdo->prepare("INSERT INTO courier_tracking_history (org_id, courier_id, stage_code, stage_name, location, notes, lat, lng, created_by)
            VALUES (?,?,?,?,?,?,?,?,?)")->execute([$orgId, $courierId, $stageCode, $stageName, $location, $notes, $lat, $lng, $user['id']]);

        // Update courier status
        $pdo->prepare("UPDATE couriers SET status=? WHERE id=? AND org_id=?")->execute([$stageCode, $courierId, $orgId]);

        // Check if stage is final (delivered) — set actual delivery date
        if (in_array($stageCode, ['delivered'])) {
            $pdo->prepare("UPDATE couriers SET actual_delivery=CURDATE() WHERE id=? AND org_id=? AND actual_delivery IS NULL")
                ->execute([$courierId, $orgId]);
        }

        logActivity('update', 'courier', "Tracking update: Stage=$stageCode, Courier #$courierId");
        setFlash('success', 'Tracking update added successfully.');
        redirect('tracking.php?courier_id=' . $courierId);
    }

    if ($action === 'delete_tracking') {
        $hid = (int)$_POST['history_id'];
        $pdo->prepare("DELETE FROM courier_tracking_history WHERE id=? AND org_id=?")->execute([$hid, $orgId]);
        setFlash('success', 'Tracking entry removed.');
        $cid = (int)($_POST['courier_id'] ?? 0);
        redirect('tracking.php?courier_id=' . $cid);
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

// Idempotent: add GPS coordinate columns to tracking history
foreach ([['lat', 'DECIMAL(9,6) DEFAULT NULL'], ['lng', 'DECIMAL(9,6) DEFAULT NULL']] as [$col, $def]) {
    try { $pdo->exec("ALTER TABLE courier_tracking_history ADD COLUMN {$col} {$def}"); } catch (Throwable $e) {}
}

$gpsPoints = [];

// Load tracking stages for dropdown
$stages = [];
try {
    $st = $pdo->prepare("SELECT * FROM courier_tracking_stages WHERE org_id=? AND status='active' ORDER BY sort_order ASC");
    $st->execute([$orgId]);
    $stages = $st->fetchAll();
} catch (Exception $e) {}

// Default stages if none configured
if (empty($stages)) {
    $stages = [
        ['stage_code' => 'pending',          'stage_name' => 'Order Pending',          'color' => '#ffc107', 'icon' => 'fas fa-clock'],
        ['stage_code' => 'processing',        'stage_name' => 'Processing',              'color' => '#17a2b8', 'icon' => 'fas fa-cog'],
        ['stage_code' => 'picked_up',         'stage_name' => 'Picked Up',               'color' => '#0d6efd', 'icon' => 'fas fa-box'],
        ['stage_code' => 'in_transit',        'stage_name' => 'In Transit',              'color' => '#0d6efd', 'icon' => 'fas fa-truck'],
        ['stage_code' => 'out_for_delivery',  'stage_name' => 'Out for Delivery',        'color' => '#17a2b8', 'icon' => 'fas fa-motorcycle'],
        ['stage_code' => 'delivered',         'stage_name' => 'Delivered',               'color' => '#198754', 'icon' => 'fas fa-check-circle'],
        ['stage_code' => 'failed',            'stage_name' => 'Delivery Failed',         'color' => '#dc3545', 'icon' => 'fas fa-times-circle'],
        ['stage_code' => 'returned',          'stage_name' => 'Returned to Sender',      'color' => '#6c757d', 'icon' => 'fas fa-undo'],
    ];
}

// Selected courier
$selectedCourierId = (int)($_GET['courier_id'] ?? 0);
$courier = null;
$history = [];

if ($selectedCourierId > 0) {
    try {
        $stmt = $pdo->prepare("SELECT c.*, st.name AS service_name, b.name AS branch_name, a.name AS agent_name
            FROM couriers c
            LEFT JOIN courier_service_types st ON c.service_type_id = st.id
            LEFT JOIN courier_branches b ON c.branch_id = b.id
            LEFT JOIN courier_agents a ON c.agent_id = a.id
            WHERE c.id=? AND c.org_id=?");
        $stmt->execute([$selectedCourierId, $orgId]);
        $courier = $stmt->fetch();
    } catch (Exception $e) {}

    try {
        $stmt = $pdo->prepare("SELECT h.*, u.name AS updated_by_name
            FROM courier_tracking_history h
            LEFT JOIN users u ON h.created_by = u.id
            WHERE h.courier_id=? AND h.org_id=? ORDER BY h.created_at DESC");
        $stmt->execute([$selectedCourierId, $orgId]);
        $history = $stmt->fetchAll();
        // Build GPS points oldest-first for polyline route
        foreach (array_reverse($history) as $h) {
            if (!empty($h['lat']) && !empty($h['lng'])) {
                $gpsPoints[] = [
                    'lat'  => (float)$h['lat'],
                    'lng'  => (float)$h['lng'],
                    'name' => htmlspecialchars($h['stage_name'] ?: $h['stage_code'], ENT_QUOTES),
                    'loc'  => htmlspecialchars($h['location'] ?? '', ENT_QUOTES),
                    'time' => date('d M Y H:i', strtotime($h['created_at'])),
                ];
            }
        }
    } catch (Exception $e) {}
}

// Search recent couriers for the search panel
$recentSearch = trim($_GET['search'] ?? '');
$searchResults = [];
if ($recentSearch !== '') {
    try {
        $stmt = $pdo->prepare("SELECT id, tracking_id, sender_name, receiver_name, status FROM couriers WHERE org_id=? AND (tracking_id LIKE ? OR sender_name LIKE ? OR receiver_name LIKE ?) ORDER BY created_at DESC LIMIT 15");
        $s = "%$recentSearch%";
        $stmt->execute([$orgId, $s, $s, $s]);
        $searchResults = $stmt->fetchAll();
    } catch (Exception $e) {}
}

$statusColors = ['pending'=>'warning','processing'=>'info','picked_up'=>'primary','in_transit'=>'primary','out_for_delivery'=>'info','delivered'=>'success','failed'=>'danger','returned'=>'secondary','cancelled'=>'dark'];
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-map-marker-alt me-2" style="color:<?= $moduleColor ?>"></i>Real-Time Parcel Tracking</h4>
    <p class="text-muted mb-0">Monitor delivery stages, update tracking events, and view complete courier journey timelines</p>
  </div>
</div>

<div class="row g-3">
  <!-- Search / Select Panel -->
  <div class="col-lg-4">
    <div class="card">
      <div class="card-header"><h6 class="mb-0 fw-bold"><i class="fas fa-search me-2"></i>Find Courier</h6></div>
      <div class="card-body">
        <form method="GET">
          <input type="hidden" name="courier_id" value="0">
          <div class="input-group mb-3">
            <input type="text" name="search" class="form-control" placeholder="Tracking ID or name..." value="<?= e($recentSearch) ?>">
            <button class="btn btn-primary" type="submit"><i class="fas fa-search"></i></button>
          </div>
        </form>
        <?php if (!empty($searchResults)): ?>
        <div class="list-group list-group-flush">
          <?php foreach ($searchResults as $sr):
            $sc = $statusColors[$sr['status']] ?? 'secondary';
          ?>
          <a href="tracking.php?courier_id=<?= $sr['id'] ?>" class="list-group-item list-group-item-action <?= $selectedCourierId === (int)$sr['id'] ? 'active' : '' ?>">
            <div class="d-flex justify-content-between">
              <span class="font-monospace fw-bold small"><?= e($sr['tracking_id']) ?></span>
              <span class="badge bg-<?= $sc ?> text-white small"><?= strtoupper(str_replace('_',' ',$sr['status'])) ?></span>
            </div>
            <small><?= e($sr['sender_name']) ?> → <?= e($sr['receiver_name']) ?></small>
          </a>
          <?php endforeach; ?>
        </div>
        <?php elseif ($recentSearch !== ''): ?>
        <p class="text-muted small text-center">No couriers found for "<?= e($recentSearch) ?>".</p>
        <?php else: ?>
        <p class="text-muted small">Enter a tracking ID, sender, or receiver name to search.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Tracking Timeline Panel -->
  <div class="col-lg-8">
    <?php if ($courier): ?>

    <!-- Courier Info Card -->
    <div class="card mb-3">
      <div class="card-header d-flex align-items-center justify-content-between" style="background:<?= $moduleColor ?>;color:white">
        <div>
          <h6 class="mb-0 fw-bold"><i class="fas fa-box me-2"></i><?= e($courier['tracking_id']) ?></h6>
          <small><?= e($courier['sender_name']) ?> → <?= e($courier['receiver_name']) ?></small>
        </div>
        <?php $sc = $statusColors[$courier['status']] ?? 'secondary'; ?>
        <span class="badge bg-white text-dark"><?= strtoupper(str_replace('_',' ',$courier['status'])) ?></span>
      </div>
      <div class="card-body">
        <div class="row g-2 small">
          <div class="col-sm-4"><strong>Service:</strong> <?= e($courier['service_name'] ?? '—') ?></div>
          <div class="col-sm-4"><strong>Branch:</strong> <?= e($courier['branch_name'] ?? '—') ?></div>
          <div class="col-sm-4"><strong>Agent:</strong> <?= e($courier['agent_name'] ?? 'Unassigned') ?></div>
          <div class="col-sm-4"><strong>Pickup:</strong> <?= $courier['pickup_date'] ? formatDate($courier['pickup_date']) : '—' ?></div>
          <div class="col-sm-4"><strong>Expected:</strong> <?= $courier['expected_delivery'] ? formatDate($courier['expected_delivery']) : '—' ?></div>
          <div class="col-sm-4"><strong>Delivered:</strong> <?= $courier['actual_delivery'] ? formatDate($courier['actual_delivery']) : '—' ?></div>
        </div>
      </div>
    </div>

    <!-- GPS Map Panel -->
    <?php if (!empty($gpsPoints)): ?>
    <script>window._gpsPoints = <?= json_encode($gpsPoints) ?>;</script>
    <div class="card mb-3" id="mapCard">
      <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-bold"><i class="fas fa-map me-2 text-success"></i>GPS Route Map</h6>
        <small class="text-muted"><?= count($gpsPoints) ?> GPS point<?= count($gpsPoints) !== 1 ? 's' : '' ?> recorded &nbsp;
          <i class="fas fa-sync-alt text-muted" id="mapRefreshIcon" title="Auto-refreshes every 45 s"></i>
        </small>
      </div>
      <div class="card-body p-0">
        <div id="courierMap" style="height:280px;width:100%"></div>
      </div>
    </div>
    <?php else: ?>
    <div class="alert alert-light border mb-3 py-2 small">
      <i class="fas fa-map-marker-alt me-2 text-muted"></i>
      No GPS coordinates recorded yet. Use <strong>📍 Capture GPS</strong> when adding a tracking update.
    </div>
    <?php endif; ?>

    <!-- Add Tracking Update -->
    <div class="card mb-3">
      <div class="card-header"><h6 class="mb-0 fw-bold"><i class="fas fa-plus-circle me-2 text-success"></i>Add Tracking Update</h6></div>
      <div class="card-body">
        <form method="POST">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="add_tracking">
          <input type="hidden" name="courier_id" value="<?= $courier['id'] ?>">
          <div class="row g-2">
            <div class="col-md-5">
              <label class="form-label fw-semibold small">Tracking Stage <span class="text-danger">*</span></label>
              <select name="stage_code" id="stageCode" class="form-select form-select-sm" required onchange="fillStageName(this)">
                <option value="">Select stage...</option>
                <?php foreach ($stages as $stg): ?>
                <option value="<?= e($stg['stage_code']) ?>" data-name="<?= e($stg['stage_name']) ?>"><?= e($stg['stage_name']) ?></option>
                <?php endforeach; ?>
              </select>
              <input type="hidden" name="stage_name" id="stageName">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold small">Current Location</label>
              <input type="text" name="location" class="form-control form-control-sm" placeholder="e.g. Harare Sorting Hub">
            </div>
            <div class="col-md-3 d-flex align-items-end">
              <button type="submit" class="btn btn-sm text-white w-100" style="background:<?= $moduleColor ?>"><i class="fas fa-plus me-1"></i>Add Update</button>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold small">Notes</label>
              <input type="text" name="notes" class="form-control form-control-sm" placeholder="Optional delivery notes...">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold small">GPS Coordinates <span class="text-muted">(optional)</span></label>
              <div class="input-group input-group-sm">
                <input type="number" name="lat" id="trackLat" class="form-control form-control-sm" placeholder="Latitude" step="0.000001" min="-90" max="90" style="max-width:150px">
                <input type="number" name="lng" id="trackLng" class="form-control form-control-sm" placeholder="Longitude" step="0.000001" min="-180" max="180" style="max-width:150px">
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="captureGPS()" title="Use device GPS">
                  <i class="fas fa-crosshairs me-1"></i>Capture GPS
                </button>
                <span class="input-group-text small text-muted" id="gpsStatus" style="font-size:.75rem;min-width:110px"></span>
              </div>
            </div>
          </div>
        </form>
      </div>
    </div>

    <!-- Tracking Timeline -->
    <div class="card">
      <div class="card-header"><h6 class="mb-0 fw-bold"><i class="fas fa-route me-2 text-primary"></i>Tracking Timeline</h6></div>
      <div class="card-body">
        <?php if (empty($history)): ?>
        <p class="text-muted text-center py-3"><i class="fas fa-map-marker-alt fa-2x mb-2 d-block"></i>No tracking events recorded yet.</p>
        <?php else: ?>
        <div class="timeline">
          <?php
          $stageIconMap = [];
          foreach ($stages as $stg) $stageIconMap[$stg['stage_code']] = ['icon' => $stg['icon'] ?? 'fas fa-circle', 'color' => $stg['color'] ?? '#007bff'];
          foreach ($history as $i => $h):
            $ico   = $stageIconMap[$h['stage_code']]['icon']  ?? 'fas fa-circle';
            $color = $stageIconMap[$h['stage_code']]['color'] ?? '#1565c0';
          ?>
          <div class="d-flex mb-3">
            <div class="me-3 text-center" style="width:40px;flex-shrink:0">
              <div class="rounded-circle d-flex align-items-center justify-content-center text-white" style="width:36px;height:36px;background:<?= $color ?>">
                <i class="<?= e($ico) ?> small"></i>
              </div>
              <?php if ($i < count($history) - 1): ?><div style="width:2px;height:calc(100% + 12px);background:#dee2e6;margin:4px auto 0"></div><?php endif; ?>
            </div>
            <div class="flex-grow-1 pb-3 border-bottom">
              <div class="d-flex justify-content-between align-items-start">
                <div>
                  <div class="fw-bold text-dark"><?= e($h['stage_name'] ?: strtoupper(str_replace('_',' ',$h['stage_code']))) ?></div>
                  <?php if ($h['location']): ?><small class="text-muted"><i class="fas fa-map-pin me-1"></i><?= e($h['location']) ?></small><?php endif; ?>
                  <?php if ($h['notes']): ?><div class="text-muted small mt-1"><?= e($h['notes']) ?></div><?php endif; ?>
                  <small class="text-muted">Updated by: <?= e($h['updated_by_name'] ?? 'System') ?></small>
                </div>
                <div class="text-end">
                  <small class="text-muted"><?= date('d M Y H:i', strtotime($h['created_at'])) ?></small>
                  <form method="POST" class="mt-1" onsubmit="return confirm('Remove this tracking entry?')">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="delete_tracking">
                    <input type="hidden" name="history_id" value="<?= $h['id'] ?>">
                    <input type="hidden" name="courier_id" value="<?= $courier['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-1"><i class="fas fa-times small"></i></button>
                  </form>
                </div>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <?php else: ?>
    <div class="card">
      <div class="card-body text-center py-5 text-muted">
        <i class="fas fa-shipping-fast fa-3x mb-3 d-block" style="color:<?= $moduleColor ?>"></i>
        <h5>Select a Courier to Track</h5>
        <p>Search for a courier by tracking ID, sender, or receiver name in the panel on the left.</p>
        <a href="couriers.php" class="btn btn-outline-primary btn-sm"><i class="fas fa-box me-1"></i>View All Couriers</a>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php
$extraJs = <<<'JS'
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
function fillStageName(sel) {
  const opt = sel.options[sel.selectedIndex];
  document.getElementById('stageName').value = opt.getAttribute('data-name') || opt.value;
}

function captureGPS() {
  const status = document.getElementById('gpsStatus');
  if (!navigator.geolocation) { status.textContent = 'GPS not supported'; return; }
  status.textContent = 'Locating…';
  navigator.geolocation.getCurrentPosition(
    pos => {
      document.getElementById('trackLat').value = pos.coords.latitude.toFixed(6);
      document.getElementById('trackLng').value = pos.coords.longitude.toFixed(6);
      status.textContent = '✓ Captured';
      status.classList.remove('text-muted');
      status.classList.add('text-success');
    },
    err => {
      status.textContent = 'GPS denied';
      status.classList.add('text-danger');
    },
    { enableHighAccuracy: true, timeout: 10000 }
  );
}

// Initialize Leaflet GPS map
(function () {
  const pts = window._gpsPoints;
  if (!pts || !pts.length) return;

  const map = L.map('courierMap', { zoomControl: true });
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
    maxZoom: 19
  }).addTo(map);

  const latlngs = pts.map(p => [p.lat, p.lng]);

  // Draw route polyline
  if (latlngs.length > 1) {
    L.polyline(latlngs, { color: '#1565c0', weight: 3, opacity: 0.75, dashArray: '6 4' }).addTo(map);
  }

  // Add markers
  pts.forEach((p, i) => {
    const isLast = (i === pts.length - 1);
    const color  = isLast ? '#1565c0' : '#64b5f6';
    const size   = isLast ? 14 : 10;
    const icon   = L.divIcon({
      html: `<div style="background:${color};width:${size}px;height:${size}px;border-radius:50%;border:2px solid #fff;box-shadow:0 2px 5px rgba(0,0,0,.4)"></div>`,
      className: '', iconSize: [size, size], iconAnchor: [size/2, size/2]
    });
    const popup = `<strong>${p.name}</strong>${p.loc ? '<br><i class="fas fa-map-pin"></i> ' + p.loc : ''}<br><small>${p.time}</small>`;
    const m = L.marker([p.lat, p.lng], { icon }).addTo(map).bindPopup(popup);
    if (isLast) m.openPopup();
  });

  if (latlngs.length === 1) {
    map.setView(latlngs[0], 13);
  } else {
    map.fitBounds(latlngs, { padding: [30, 30] });
  }

  // Auto-refresh the map data every 45 seconds
  const courierId = new URLSearchParams(window.location.search).get('courier_id');
  if (courierId) {
    setInterval(() => {
      const icon = document.getElementById('mapRefreshIcon');
      if (icon) { icon.classList.add('fa-spin'); }
      fetch('tracking.php?courier_id=' + courierId, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(() => { if (icon) icon.classList.remove('fa-spin'); })
        .catch(() => { if (icon) icon.classList.remove('fa-spin'); });
    }, 45000);
  }
})();
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
