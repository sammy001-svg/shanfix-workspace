<?php
/**
 * _bulk-actions.php — Bulk selection & action bar partial
 *
 * Include on any page that needs Gmail-style bulk row selection.
 *
 * Usage:
 *   include __DIR__ . '/../includes/_bulk-actions.php';
 *
 * Then call initBulkSelect('myTableId') after the table renders.
 * Define $bulkActions array before including to inject action buttons:
 *   $bulkActions = [
 *     ['label' => 'Delete Selected', 'action' => 'bulk_delete', 'class' => 'btn-danger', 'url' => ''],
 *     ['label' => 'Mark Active',     'action' => 'bulk_activate', 'class' => 'btn-success'],
 *   ];
 *
 * JS helpers available globally (defined in footer.php):
 *   initBulkSelect(tableId)  — wire up checkboxes
 *   getBulkSelected()        — returns array of selected IDs
 *   clearBulkSelection()     — uncheck all
 *   bulkPost(action, url)    — POST selected IDs to handler
 *   bulkExportCSV(filename)  — client-side CSV from visible rows
 */
$bulkActions = $bulkActions ?? [];
?>
<style>
.bulk-action-bar {
  position: fixed;
  bottom: 0;
  left: var(--sidebar-w, 260px);
  right: 0;
  z-index: 1040;
  display: none;
  align-items: center;
  gap: .75rem;
  padding: .75rem 1.5rem;
  background: #0B2D4E;
  color: #fff;
  border-top: 2px solid #1A8A4E;
  box-shadow: 0 -4px 20px rgba(0,0,0,.2);
  animation: slideUp .2s ease;
  flex-wrap: wrap;
}
[data-theme="dark"] .bulk-action-bar {
  background: #1e293b;
  border-top-color: #4ade80;
}
@keyframes slideUp {
  from { transform: translateY(100%); opacity: 0; }
  to   { transform: none; opacity: 1; }
}
.bulk-action-bar .bulk-count-badge {
  background: #1A8A4E;
  color: #fff;
  border-radius: 20px;
  padding: 2px 10px;
  font-weight: 700;
  font-size: .82rem;
  white-space: nowrap;
}
.bulk-action-bar .bulk-label {
  font-size: .85rem;
  opacity: .85;
}
@media (max-width: 767px) {
  .bulk-action-bar { left: 0; }
}
/* Checkbox column styling */
.bulk-check, .bulk-master {
  width: 16px;
  height: 16px;
  cursor: pointer;
  accent-color: var(--green, #1A8A4E);
}
</style>

<div id="bulkActionBar" class="bulk-action-bar">
  <span class="bulk-count-badge" id="bulkCount">0</span>
  <span class="bulk-label">item(s) selected</span>
  <div class="d-flex gap-2 flex-wrap ms-2">
    <?php foreach ($bulkActions as $act): ?>
    <button type="button"
            class="btn btn-sm <?= e($act['class'] ?? 'btn-light') ?>"
            onclick="bulkPost(<?= json_encode($act['action']) ?>, <?= json_encode($act['url'] ?? '') ?>)">
      <?php if (!empty($act['icon'])): ?><i class="<?= e($act['icon']) ?> me-1"></i><?php endif; ?>
      <?= e($act['label']) ?>
    </button>
    <?php endforeach; ?>
    <button type="button" class="btn btn-sm btn-outline-light"
            onclick="bulkExportCSV('export.csv')">
      <i class="fas fa-download me-1"></i>Export Selected
    </button>
    <button type="button" class="btn btn-sm btn-outline-light"
            onclick="clearBulkSelection()">
      <i class="fas fa-times me-1"></i>Deselect All
    </button>
  </div>
</div>
