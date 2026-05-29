<?php
/**
 * Org Login Portal Banner
 * Inject after flashAlert() in header-module.php and header-client.php.
 * Only visible to client_admin role.
 */
if (($user['role'] ?? '') !== 'client_admin') return;

$__slug = null;
try {
    $__r = $pdo->prepare("SELECT slug FROM organizations WHERE id=? LIMIT 1");
    $__r->execute([(int)$user['org_id']]);
    $__slug = $__r->fetchColumn() ?: null;
} catch (Exception $e) {}

if (!$__slug) return;

$__portalUrl = APP_URL . '/auth/org-login.php?org=' . rawurlencode($__slug);
$__bannerId  = 'orgBanner_' . (int)$user['org_id'];
?>
<div class="org-portal-banner" id="<?= $__bannerId ?>" style="display:none">
  <div class="opb-inner">
    <div class="opb-left">
      <div class="opb-icon-wrap">
        <i class="fas fa-link"></i>
      </div>
      <div class="opb-text">
        <div class="opb-title">Team Login Portal</div>
        <div class="opb-sub">Share this link with your staff so they can sign in directly to your workspace</div>
      </div>
    </div>
    <div class="opb-right">
      <div class="opb-url-box">
        <span class="opb-url-text" id="<?= $__bannerId ?>_url"><?= htmlspecialchars($__portalUrl, ENT_QUOTES) ?></span>
        <button class="opb-btn opb-btn-copy" id="<?= $__bannerId ?>_copy" onclick="copyPortalUrl('<?= $__bannerId ?>')" title="Copy URL">
          <i class="fas fa-copy" id="<?= $__bannerId ?>_copyIcon"></i>
          <span id="<?= $__bannerId ?>_copyText">Copy</span>
        </button>
        <a href="<?= htmlspecialchars($__portalUrl, ENT_QUOTES) ?>" target="_blank" class="opb-btn opb-btn-open" title="Open portal in new tab">
          <i class="fas fa-external-link-alt"></i>
          <span>Open</span>
        </a>
      </div>
    </div>
    <button class="opb-dismiss" onclick="dismissPortalBanner('<?= $__bannerId ?>')" title="Dismiss" aria-label="Dismiss portal banner">
      <i class="fas fa-times"></i>
    </button>
  </div>
</div>

<style>
.org-portal-banner{
  background:linear-gradient(135deg,#f0fdf4 0%,#eff6ff 100%);
  border:1px solid #bbf7d0;border-left:4px solid #1A8A4E;
  border-radius:10px;padding:0;margin-bottom:20px;
  box-shadow:0 2px 8px rgba(26,138,78,.08);
}
.opb-inner{
  display:flex;align-items:center;gap:16px;padding:12px 16px;
  flex-wrap:wrap;
}
.opb-left{display:flex;align-items:center;gap:12px;flex:1;min-width:200px;}
.opb-icon-wrap{
  width:36px;height:36px;border-radius:9px;flex-shrink:0;
  background:linear-gradient(135deg,#1A8A4E,#22a860);
  display:flex;align-items:center;justify-content:center;
  color:#fff;font-size:.85rem;
  box-shadow:0 2px 8px rgba(26,138,78,.25);
}
.opb-title{font-size:.83rem;font-weight:700;color:#0B2D4E;line-height:1.2;}
.opb-sub{font-size:.73rem;color:#64748b;line-height:1.4;margin-top:1px;}

.opb-right{flex:0 0 auto;}
.opb-url-box{
  display:flex;align-items:center;gap:6px;
  background:#fff;border:1.5px solid #d1fae5;border-radius:8px;
  padding:6px 8px 6px 12px;
}
.opb-url-text{
  font-family:ui-monospace,SFMono-Regular,'Courier New',monospace;
  font-size:.73rem;color:#0B2D4E;white-space:nowrap;
  overflow:hidden;text-overflow:ellipsis;max-width:280px;
  display:inline-block;vertical-align:middle;
}
.opb-btn{
  display:inline-flex;align-items:center;gap:5px;
  padding:5px 10px;border-radius:6px;font-size:.73rem;font-weight:600;
  cursor:pointer;transition:all .15s;border:none;white-space:nowrap;
  text-decoration:none;
}
.opb-btn-copy{background:#1A8A4E;color:#fff;}
.opb-btn-copy:hover{background:#146038;}
.opb-btn-copy.copied{background:#0B2D4E;}
.opb-btn-open{background:#f1f5f9;color:#0B2D4E;border:1px solid #e2e8f0;}
.opb-btn-open:hover{background:#e2e8f0;color:#0B2D4E;}

.opb-dismiss{
  background:none;border:none;color:#94a3b8;cursor:pointer;
  padding:4px 6px;border-radius:4px;font-size:.8rem;
  transition:color .15s,background .15s;flex-shrink:0;
  align-self:flex-start;margin-top:2px;
}
.opb-dismiss:hover{color:#0B2D4E;background:rgba(0,0,0,.05);}

@media(max-width:640px){
  .opb-inner{gap:10px;padding:10px 12px;}
  .opb-sub{display:none;}
  .opb-url-text{max-width:140px;}
  .opb-btn span{display:none;}
}
</style>

<script>
(function() {
  var id = '<?= $__bannerId ?>';
  if (!localStorage.getItem('opb_dismissed_' + id)) {
    var el = document.getElementById(id);
    if (el) el.style.display = '';
  }
})();

function copyPortalUrl(id) {
  var url  = document.getElementById(id + '_url').textContent.trim();
  var btn  = document.getElementById(id + '_copy');
  var ico  = document.getElementById(id + '_copyIcon');
  var txt  = document.getElementById(id + '_copyText');
  navigator.clipboard.writeText(url).then(function() {
    btn.classList.add('copied');
    ico.className = 'fas fa-check';
    txt.textContent = 'Copied!';
    setTimeout(function() {
      btn.classList.remove('copied');
      ico.className = 'fas fa-copy';
      txt.textContent = 'Copy';
    }, 2000);
  }).catch(function() {
    // Fallback for older browsers
    var ta = document.createElement('textarea');
    ta.value = url; ta.style.position = 'fixed'; ta.style.opacity = '0';
    document.body.appendChild(ta); ta.select();
    try { document.execCommand('copy'); } catch(e) {}
    document.body.removeChild(ta);
    txt.textContent = 'Copied!';
    setTimeout(function(){ txt.textContent = 'Copy'; }, 2000);
  });
}

function dismissPortalBanner(id) {
  localStorage.setItem('opb_dismissed_' + id, '1');
  var el = document.getElementById(id);
  if (el) { el.style.transition = 'opacity .3s'; el.style.opacity = '0'; setTimeout(function(){ el.style.display = 'none'; }, 300); }
}
</script>
