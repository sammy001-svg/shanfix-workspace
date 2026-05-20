  </main><!-- /.main-content -->

  <footer class="panel-footer">
    <span>&copy; <?= APP_YEAR ?> <?= APP_NAME ?> v<?= APP_VERSION ?>. All rights reserved.</span>
    <span class="ms-auto">Powered by <strong style="color:var(--green)">Shanfix Technologies</strong></span>
  </footer>
</div><!-- /.main-wrapper -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="<?= APP_URL ?>/assets/js/app.js"></script>
<?php if (isset($extraJs)) echo $extraJs; ?>
</body>
</html>
