  </div><!-- /#tchContent -->
</div><!-- /#tchMain -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="<?= APP_URL ?>/assets/js/app.js"></script>
<?php if (isset($extraJs)) echo $extraJs; ?>
<script>
document.querySelectorAll('.alert.alert-success,.alert.alert-info').forEach(el => {
    setTimeout(() => { el.style.opacity='0'; el.style.transition='opacity .5s'; setTimeout(()=>el.remove(),500); }, 6000);
});
document.addEventListener('click', function(e) {
    const sb = document.getElementById('tchSidebar');
    if (sb && sb.classList.contains('show') && !sb.contains(e.target)) sb.classList.remove('show');
});
</script>
</body>
</html>
