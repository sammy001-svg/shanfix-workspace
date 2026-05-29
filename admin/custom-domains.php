<?php
$pageTitle = 'Custom Domains';
require_once __DIR__ . '/../includes/header-admin.php';

// Ensure custom_domain column exists
try {
    $pdo->exec("ALTER TABLE organizations ADD COLUMN IF NOT EXISTS custom_domain VARCHAR(255) DEFAULT NULL, ADD UNIQUE KEY IF NOT EXISTS uq_custom_domain (custom_domain)");
} catch (Exception $e) {}

// ── Actions ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_domain') {
        $orgId  = (int)$_POST['org_id'];
        $domain = strtolower(trim(sanitize($_POST['custom_domain'] ?? '')));

        // Validate: must be a valid hostname or empty
        if ($domain && !preg_match('/^[a-z0-9][a-z0-9\.\-]{1,250}[a-z0-9]$/', $domain)) {
            setFlash('danger', 'Invalid domain format. Use format: yourdomain.com');
        } else {
            // Check no duplicate
            if ($domain) {
                $dup = $pdo->prepare("SELECT id FROM organizations WHERE custom_domain=? AND id!=?");
                $dup->execute([$domain, $orgId]);
                if ($dup->fetch()) {
                    setFlash('danger', "Domain '{$domain}' is already assigned to another organization.");
                    redirect(APP_URL . '/admin/custom-domains.php');
                }
            }
            $pdo->prepare("UPDATE organizations SET custom_domain=? WHERE id=?")
                ->execute([$domain ?: null, $orgId]);
            setFlash('success', $domain ? "Custom domain '{$domain}' assigned." : "Custom domain removed.");
            logActivity('custom_domain', 'admin', "Updated custom domain for org #{$orgId}: " . ($domain ?: 'removed'));
        }
        redirect(APP_URL . '/admin/custom-domains.php');
    }
}

// Fetch all orgs with their custom domain info
$orgs = $pdo->query("SELECT id, name, slug, email, custom_domain, status FROM organizations ORDER BY name")->fetchAll();
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-globe me-2 text-green"></i>Custom Domains</h4>
    <p class="text-muted mb-0">Map custom domains or subdomains to client organizations</p>
  </div>
</div>

<!-- Instructions -->
<div class="alert alert-info d-flex gap-3 mb-4">
  <i class="fas fa-info-circle flex-shrink-0 fs-5 mt-1"></i>
  <div>
    <div class="fw-semibold mb-1">How Custom Domains Work</div>
    <ol class="mb-0 small">
      <li>Client points their domain DNS CNAME record to <code><?= APP_URL ? parse_url(APP_URL, PHP_URL_HOST) : 'orbitdesk.co' ?></code></li>
      <li>Enter the custom domain below for their organization</li>
      <li>OrbitDesk detects the domain and loads the org's branded login portal automatically</li>
      <li>DNS propagation may take up to 48 hours</li>
    </ol>
  </div>
</div>

<!-- Domains Table -->
<div class="card">
  <div class="card-header"><h6 class="mb-0 fw-semibold">Organization Domains</h6></div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0" id="domainsTable">
        <thead class="table-light">
          <tr>
            <th>Organization</th>
            <th>Default Slug URL</th>
            <th>Custom Domain</th>
            <th>DNS Status</th>
            <th>Org Status</th>
            <th class="text-end">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($orgs as $org):
            $portalUrl    = APP_URL . '/auth/org-login.php?org=' . rawurlencode($org['slug'] ?? '');
            $customDomain = $org['custom_domain'] ?? '';
            $dnsOk        = false;
            $dnsIp        = '';
            if ($customDomain) {
                $dnsIp = gethostbyname($customDomain);
                $dnsOk = $dnsIp !== $customDomain; // resolved = different from input
            }
          ?>
          <tr>
            <td>
              <div class="fw-semibold"><?= e($org['name']) ?></div>
              <div class="text-muted small"><?= e($org['email'] ?? '') ?></div>
            </td>
            <td>
              <div class="d-flex align-items-center gap-2">
                <code style="font-size:.72rem"><?= e($org['slug'] ?? '—') ?></code>
                <a href="<?= e($portalUrl) ?>" target="_blank" class="btn btn-xs btn-outline-secondary" title="Open portal">
                  <i class="fas fa-external-link-alt"></i>
                </a>
              </div>
            </td>
            <td>
              <?php if ($customDomain): ?>
              <code class="text-primary"><?= e($customDomain) ?></code>
              <?php else: ?>
              <span class="text-muted small">Not set</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($customDomain): ?>
              <span class="badge bg-<?= $dnsOk ? 'success' : 'warning' ?>">
                <i class="fas fa-<?= $dnsOk ? 'check' : 'clock' ?> me-1"></i>
                <?= $dnsOk ? 'Resolved (' . e($dnsIp) . ')' : 'Pending / Unresolved' ?>
              </span>
              <?php else: ?>
              <span class="text-muted small">—</span>
              <?php endif; ?>
            </td>
            <td><?= statusBadge($org['status']) ?></td>
            <td class="text-end">
              <button class="btn btn-sm btn-outline-primary"
                      onclick='editDomain(<?= json_encode(["id"=>$org["id"],"name"=>$org["name"],"custom_domain"=>$customDomain]) ?>)'>
                <i class="fas fa-edit me-1"></i>Edit Domain
              </button>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Edit Domain Modal -->
<div class="modal fade" id="domainModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header" style="background:var(--navy);color:#fff">
        <h5 class="modal-title" id="domainModalTitle"><i class="fas fa-globe me-2"></i>Set Custom Domain</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save_domain">
        <input type="hidden" name="org_id" id="editOrgId">
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label fw-semibold">Organization</label>
            <div class="form-control bg-light" id="editOrgName" readonly></div>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Custom Domain</label>
            <input type="text" name="custom_domain" id="editDomainInput" class="form-control"
                   placeholder="e.g. erp.acmecorp.com" maxlength="255">
            <div class="form-text">Leave empty to remove the custom domain. Do not include https:// or trailing slash.</div>
          </div>
          <div class="alert alert-warning p-2 small mb-0">
            <i class="fas fa-exclamation-triangle me-1"></i>
            DNS CNAME must point to <strong><?= APP_URL ? parse_url(APP_URL, PHP_URL_HOST) : 'your-server.com' ?></strong> before activating.
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Save Domain</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php $extraJs = <<<'JS'
<script>
$("#domainsTable").DataTable({pageLength:25, order:[[0,"asc"]]});

function editDomain(data) {
  document.getElementById('editOrgId').value = data.id;
  document.getElementById('editOrgName').textContent = data.name;
  document.getElementById('editDomainInput').value = data.custom_domain || '';
  document.getElementById('domainModalTitle').innerHTML = '<i class="fas fa-globe me-2"></i>Domain for: ' + data.name;
  new bootstrap.Modal(document.getElementById('domainModal')).show();
}
</script>
JS;
require_once __DIR__ . '/../includes/footer.php';
?>
