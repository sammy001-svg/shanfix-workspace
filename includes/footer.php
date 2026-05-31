  </main><!-- /.main-content -->

  <footer class="panel-footer">
    <span>&copy; <?= APP_YEAR ?> <?= APP_NAME ?> v<?= APP_VERSION ?>. All rights reserved.</span>
    <span class="ms-auto">Powered by <strong style="color:var(--green)">OrbitDesk Technologies</strong></span>
  </footer>
</div><!-- /.main-wrapper -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
// Global fix for DataTables column count errors caused by colspan empty rows
(function() {
    $('table').each(function() {
        var $table = $(this);
        var $tbody = $table.find('tbody');
        var $trs = $tbody.children('tr');
        if ($trs.length === 1) {
            var $td = $trs.children('td');
            if ($td.length === 1 && $td.attr('colspan')) {
                var emptyHtml = $td.html();
                $tbody.empty();
                $table.on('preInit.dt', function(e, settings) {
                    settings.oLanguage.sEmptyTable = emptyHtml;
                });
            }
        }
    });
})();
</script>
<script src="<?= APP_URL ?>/assets/js/app.js"></script>
<?php if (isset($extraJs)) echo $extraJs; ?>

<!-- ── Dark Mode ─────────────────────────────────────────────── -->
<script>
function toggleDarkMode() {
  const html = document.documentElement;
  const isDark = html.getAttribute('data-theme') === 'dark';
  const theme  = isDark ? 'light' : 'dark';
  html.setAttribute('data-theme', theme);
  localStorage.setItem('odTheme', theme);
  document.querySelectorAll('.dm-icon').forEach(el => {
    el.className = 'dm-icon fas ' + (theme === 'dark' ? 'fa-sun' : 'fa-moon') + ' me-2';
  });
  document.querySelectorAll('.dm-label').forEach(el => {
    el.textContent = theme === 'dark' ? 'Light Mode' : 'Dark Mode';
  });
}
// Apply saved theme immediately (also applied in <head> to prevent FOUC)
(function(){
  const t = localStorage.getItem('odTheme');
  if (t) document.documentElement.setAttribute('data-theme', t);
})();
</script>

<!-- ── Bulk Selection ────────────────────────────────────────── -->
<script>
(function() {
  // Bulk action bar
  const barEl = document.getElementById('bulkActionBar');
  if (!barEl) return;

  window.initBulkSelect = function(tableId) {
    const table = document.getElementById(tableId);
    if (!table) return;
    // Add master checkbox to <thead> first <th>
    const th = table.querySelector('thead tr th:first-child');
    if (th && !th.querySelector('.bulk-master')) {
      th.insertAdjacentHTML('afterbegin', '<input type="checkbox" class="bulk-master form-check-input me-1" onclick="toggleAllBulk(this)">');
    }
    // Add row checkboxes
    table.querySelectorAll('tbody tr').forEach(tr => {
      const td = tr.querySelector('td:first-child');
      const id = tr.dataset.id || tr.querySelector('[data-id]')?.dataset.id || '';
      if (td && !td.querySelector('.bulk-check')) {
        td.insertAdjacentHTML('afterbegin', `<input type="checkbox" class="bulk-check form-check-input me-1" value="${id}" onchange="updateBulkBar()">`);
      }
    });
  };

  window.toggleAllBulk = function(master) {
    document.querySelectorAll('.bulk-check').forEach(cb => cb.checked = master.checked);
    updateBulkBar();
  };

  window.updateBulkBar = function() {
    const checked = document.querySelectorAll('.bulk-check:checked');
    const countEl = document.getElementById('bulkCount');
    if (countEl) countEl.textContent = checked.length;
    if (barEl) barEl.style.display = checked.length > 0 ? 'flex' : 'none';
  };

  window.getBulkSelected = function() {
    return Array.from(document.querySelectorAll('.bulk-check:checked')).map(cb => cb.value);
  };

  window.clearBulkSelection = function() {
    document.querySelectorAll('.bulk-check,.bulk-master').forEach(cb => cb.checked = false);
    updateBulkBar();
  };

  window.bulkPost = function(action, url) {
    const ids = getBulkSelected();
    if (!ids.length) { alert('Select items first.'); return; }
    if (!confirm('Apply to ' + ids.length + ' selected item(s)?')) return;
    const form = document.createElement('form');
    form.method = 'POST'; form.action = url || window.location.href;
    const tok = document.querySelector('input[name="_token"]');
    if (tok) form.appendChild(tok.cloneNode());
    const actInput = document.createElement('input');
    actInput.type = 'hidden'; actInput.name = 'action'; actInput.value = action;
    form.appendChild(actInput);
    ids.forEach(id => {
      const inp = document.createElement('input');
      inp.type = 'hidden'; inp.name = 'bulk_ids[]'; inp.value = id;
      form.appendChild(inp);
    });
    document.body.appendChild(form); form.submit();
  };

  window.bulkExportCSV = function(filename, columns) {
    const rows = [];
    if (columns) rows.push(columns);
    document.querySelectorAll('.bulk-check:checked').forEach(cb => {
      const tr = cb.closest('tr');
      if (!tr) return;
      rows.push(Array.from(tr.querySelectorAll('td')).slice(1).map(td => '"' + td.innerText.trim().replace(/"/g, '""') + '"'));
    });
    const csv = rows.map(r => r.join(',')).join('\n');
    const a = document.createElement('a');
    a.href = 'data:text/csv;charset=utf-8,' + encodeURIComponent(csv);
    a.download = filename || 'export.csv';
    a.click();
  };
})();
</script>

<!-- PWA: Service Worker registration + install prompt ───────────────────── -->
<div id="pwaInstallBanner" style="display:none;position:fixed;bottom:16px;right:16px;z-index:9999;
     background:#0B2D4E;color:white;border-radius:14px;padding:14px 18px;box-shadow:0 8px 24px rgba(0,0,0,.3);
     max-width:320px;font-family:Segoe UI,Arial,sans-serif;font-size:.875rem">
  <div class="d-flex align-items-center gap-2 mb-2">
    <img src="<?= APP_URL ?>/api/pwa-icon.php?size=192" width="36" height="36" style="border-radius:8px" alt="">
    <strong><?= APP_NAME ?></strong>
  </div>
  <p style="margin:0 0 10px;opacity:.85">Install <?= APP_NAME ?> on your device for faster access and offline support.</p>
  <div class="d-flex gap-2">
    <button id="pwaBtnInstall" style="background:#1A8A4E;color:white;border:none;padding:7px 16px;border-radius:8px;font-weight:700;cursor:pointer;font-size:.82rem">
      Install App
    </button>
    <button id="pwaBtnDismiss" style="background:rgba(255,255,255,.15);color:white;border:none;padding:7px 12px;border-radius:8px;cursor:pointer;font-size:.82rem">
      Not now
    </button>
  </div>
</div>
<script>
(function () {
    // Register service worker
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('/sw.js', { scope: '/' })
            .catch(e => console.warn('[SW] Registration failed:', e));
    }

    // Capture install prompt
    let _deferredPrompt = null;
    const banner = document.getElementById('pwaInstallBanner');
    const btnInstall  = document.getElementById('pwaBtnInstall');
    const btnDismiss  = document.getElementById('pwaBtnDismiss');

    window.addEventListener('beforeinstallprompt', e => {
        e.preventDefault();
        _deferredPrompt = e;
        // Show banner after 10 s if not already installed
        if (!localStorage.getItem('pwaInstallDismissed')) {
            setTimeout(() => { if (banner) banner.style.display = 'block'; }, 10000);
        }
    });

    if (btnInstall) btnInstall.addEventListener('click', () => {
        banner.style.display = 'none';
        if (_deferredPrompt) {
            _deferredPrompt.prompt();
            _deferredPrompt.userChoice.then(() => { _deferredPrompt = null; });
        }
    });

    if (btnDismiss) btnDismiss.addEventListener('click', () => {
        banner.style.display = 'none';
        localStorage.setItem('pwaInstallDismissed', '1');
    });

    // Update theme-color meta to match org branding
    const tmEl = document.getElementById('pwaThemeColor');
    const rootColor = getComputedStyle(document.documentElement).getPropertyValue('--green').trim();
    if (tmEl && rootColor) tmEl.setAttribute('content', rootColor);
})();
</script>
</body>
</html>
