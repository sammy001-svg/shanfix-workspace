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
</body>
</html>
