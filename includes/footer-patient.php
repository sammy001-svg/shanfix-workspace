  </div><!-- /#patContent -->
</div><!-- /#patMain -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="<?= APP_URL ?>/assets/js/app.js"></script>
<?php if (isset($extraJs)) echo $extraJs; ?>
<script>
// Auto-dismiss alerts after 6 seconds
document.querySelectorAll('.alert.alert-success, .alert.alert-info').forEach(el => {
    setTimeout(() => { if (el.parentNode) { el.style.opacity='0'; el.style.transition='opacity .5s'; setTimeout(()=>el.remove(),500); } }, 6000);
});
// Mobile sidebar close on backdrop click
document.addEventListener('click', function(e) {
    const sidebar = document.getElementById('patSidebar');
    if (sidebar && sidebar.classList.contains('show') && !sidebar.contains(e.target)) {
        sidebar.classList.remove('show');
    }
});
</script>
</body>
</html>
