/* ================================================================
   SHANFIX WORKSPACE — App JavaScript
   ================================================================ */

document.addEventListener('DOMContentLoaded', () => {

  // ── Sidebar toggle ──────────────────────────────────────────────
  const MOBILE_BP = 991; // must match mobile.css breakpoint
  const sidebarToggle = document.getElementById('sidebarToggle');
  const sidebarOverlay = document.getElementById('sidebarOverlay');

  if (sidebarToggle) {
    sidebarToggle.addEventListener('click', () => {
      if (window.innerWidth <= MOBILE_BP) {
        document.body.classList.toggle('sidebar-open');
        const sidebar = document.getElementById('sidebar');
        if (sidebar) sidebar.classList.toggle('active');
        if (sidebarOverlay) sidebarOverlay.classList.toggle('active');
      } else {
        document.body.classList.toggle('sidebar-collapsed');
        localStorage.setItem('sidebarCollapsed', document.body.classList.contains('sidebar-collapsed'));
      }
    });

    // Restore collapsed state on desktop
    if (localStorage.getItem('sidebarCollapsed') === 'true' && window.innerWidth > MOBILE_BP) {
      document.body.classList.add('sidebar-collapsed');
    }
  }

  // Close sidebar when overlay is clicked
  if (sidebarOverlay) {
    sidebarOverlay.addEventListener('click', () => {
      document.body.classList.remove('sidebar-open');
      const sidebar = document.getElementById('sidebar');
      if (sidebar) sidebar.classList.remove('active');
      sidebarOverlay.classList.remove('active');
    });
  }

  // Close sidebar on outside click (fallback)
  document.addEventListener('click', (e) => {
    if (window.innerWidth <= MOBILE_BP && document.body.classList.contains('sidebar-open')) {
      const sidebar = document.getElementById('sidebar');
      if (sidebar && !sidebar.contains(e.target) && e.target !== sidebarToggle) {
        document.body.classList.remove('sidebar-open');
        sidebar.classList.remove('active');
        if (sidebarOverlay) sidebarOverlay.classList.remove('active');
      }
    }
  });

  // ── DataTables init ─────────────────────────────────────────────
  if (typeof $.fn.DataTable !== 'undefined') {
    $('.data-table').DataTable({
      responsive: true,
      pageLength: 25,
      language: {
        searchPlaceholder: 'Search records...',
        search: '',
        lengthMenu: 'Show _MENU_ entries',
        info: 'Showing _START_ to _END_ of _TOTAL_ entries',
        emptyTable: 'No records found',
        zeroRecords: 'No matching records found'
      },
      dom: "<'row'<'col-sm-6'l><'col-sm-6'f>>" +
           "<'row'<'col-12'tr>>" +
           "<'row mt-2'<'col-sm-5'i><'col-sm-7'p>>",
    });
  }

  // ── Auto-dismiss alerts ──────────────────────────────────────────
  document.querySelectorAll('.alert:not(.alert-permanent)').forEach(el => {
    setTimeout(() => {
      el.style.transition = 'opacity .5s';
      el.style.opacity = '0';
      setTimeout(() => el.remove(), 500);
    }, 5000);
  });

  // ── Confirm delete ───────────────────────────────────────────────
  document.querySelectorAll('[data-confirm]').forEach(btn => {
    btn.addEventListener('click', (e) => {
      e.preventDefault();
      const msg  = btn.dataset.confirm || 'Are you sure you want to delete this record?';
      const href = btn.href || btn.dataset.href;
      if (typeof Swal !== 'undefined') {
        Swal.fire({
          title: 'Confirm Action',
          text: msg,
          icon: 'warning',
          showCancelButton: true,
          confirmButtonColor: '#ef4444',
          cancelButtonColor: '#64748b',
          confirmButtonText: 'Yes, proceed!',
          cancelButtonText: 'Cancel'
        }).then(result => {
          if (result.isConfirmed && href) window.location.href = href;
        });
      } else {
        if (confirm(msg) && href) window.location.href = href;
      }
    });
  });

  // ── Counter animation ────────────────────────────────────────────
  const animateCounter = (el) => {
    const target   = parseInt(el.dataset.target || el.innerText.replace(/\D/g, ''));
    const prefix   = el.dataset.prefix || '';
    const suffix   = el.dataset.suffix || '';
    const duration = 1200;
    const step     = target / (duration / 16);
    let current    = 0;
    const timer = setInterval(() => {
      current = Math.min(current + step, target);
      el.textContent = prefix + Math.floor(current).toLocaleString() + suffix;
      if (current >= target) clearInterval(timer);
    }, 16);
  };

  const observer = new IntersectionObserver((entries) => {
    entries.forEach(e => {
      if (e.isIntersecting) {
        animateCounter(e.target);
        observer.unobserve(e.target);
      }
    });
  }, { threshold: 0.5 });

  document.querySelectorAll('[data-counter]').forEach(el => observer.observe(el));

  // ── Landing page: smooth scroll ──────────────────────────────────
  document.querySelectorAll('a[href^="#"]').forEach(a => {
    a.addEventListener('click', (e) => {
      const target = document.querySelector(a.getAttribute('href'));
      if (target) {
        e.preventDefault();
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
    });
  });

  // ── Sticky nav color change on scroll ────────────────────────────
  const landingNav = document.querySelector('.landing-nav');
  if (landingNav) {
    window.addEventListener('scroll', () => {
      landingNav.style.boxShadow = window.scrollY > 50 ? '0 4px 20px rgba(0,0,0,.3)' : 'none';
    });
  }

  // ── Module checkbox selection (subscribe page) ───────────────────
  document.querySelectorAll('.module-select-card').forEach(card => {
    card.addEventListener('click', () => {
      const cb = card.querySelector('input[type="checkbox"]');
      if (cb) {
        cb.checked = !cb.checked;
        card.classList.toggle('selected', cb.checked);
        updateTotalPrice();
      }
    });
  });

  function updateTotalPrice() {
    let total = 0;
    document.querySelectorAll('.module-select-card.selected').forEach(card => {
      total += parseFloat(card.dataset.price || 0);
    });
    const el = document.getElementById('totalPrice');
    if (el) el.textContent = 'KES ' + total.toLocaleString() + '/month';
  }

  // ── Toast notifications ──────────────────────────────────────────
  window.showToast = (message, type = 'success') => {
    if (typeof Swal !== 'undefined') {
      Swal.fire({ toast: true, position: 'top-end', icon: type, title: message, showConfirmButton: false, timer: 3500, timerProgressBar: true });
    }
  };

  // ── Form loading state ───────────────────────────────────────────
  document.querySelectorAll('form[data-loading]').forEach(form => {
    form.addEventListener('submit', () => {
      const btn = form.querySelector('[type="submit"]');
      if (btn) {
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Processing...';
        btn.disabled = true;
      }
    });
  });

  // ── Print ────────────────────────────────────────────────────────
  document.querySelectorAll('[data-print]').forEach(btn => {
    btn.addEventListener('click', () => window.print());
  });

  // ── Fade-in on scroll ────────────────────────────────────────────
  const fadeObserver = new IntersectionObserver((entries) => {
    entries.forEach(e => {
      if (e.isIntersecting) {
        e.target.style.opacity = '1';
        e.target.style.transform = 'translateY(0)';
        fadeObserver.unobserve(e.target);
      }
    });
  }, { threshold: 0.1 });

  document.querySelectorAll('.fade-in').forEach(el => {
    el.style.opacity = '0';
    el.style.transform = 'translateY(24px)';
    el.style.transition = 'opacity .6s ease, transform .6s ease';
    fadeObserver.observe(el);
  });

});
