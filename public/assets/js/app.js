// Bootstrap tooltips (the small (i) info icons next to buttons) are
// opt-in and need explicit initialization -- one global init covers
// every page that uses them, no per-page script needed.
(function () {
  document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
    new bootstrap.Tooltip(el);
  });
})();

// Role Groups' "Pick from existing titles" checkbox dropdown -- checking
// a title appends it into that form's Keywords field (comma-separated);
// unchecking removes it. Selector matches by name prefix since each
// instance (the Add form, each Edit modal) uses a unique
// render_multiselect_filter() name to avoid id collisions across modals,
// but they all feed the same "keywords" field in their own form.
(function () {
  document.querySelectorAll('input[name^="title_picker"]').forEach(function (cb) {
    cb.addEventListener('change', function () {
      var form = cb.closest('form');
      var field = form ? form.querySelector('[name="keywords"]') : null;
      if (!field) return;

      var current = field.value.split(',').map(function (s) { return s.trim(); }).filter(function (s) { return s !== ''; });
      var title = cb.value;
      var idx = current.findIndex(function (s) { return s.toLowerCase() === title.toLowerCase(); });

      if (cb.checked && idx === -1) {
        current.push(title);
      } else if (!cb.checked && idx !== -1) {
        current.splice(idx, 1);
      }
      field.value = current.join(', ');
    });
  });
})();

// "Select all" checkbox in a table header -- scoped to just that table's
// own rows, since campaign_leads.php can render more than one of these
// tables at once (one per Wave status group).
(function () {
  document.querySelectorAll('.selectAllInTable').forEach(function (selectAll) {
    var table = selectAll.closest('table');
    if (!table) return;
    selectAll.addEventListener('change', function () {
      table.querySelectorAll('.lead-checkbox').forEach(function (cb) {
        cb.checked = selectAll.checked;
      });
    });
  });
})();

(function () {
  if (typeof window.SH_IMPORT_BATCH_ID === 'undefined') {
    return;
  }

  var bar = document.getElementById('importProgressBar');
  var statusText = document.getElementById('importStatusText');
  var doneBox = document.getElementById('importDone');

  function tick() {
    var body = new URLSearchParams();
    body.set('batch_id', String(window.SH_IMPORT_BATCH_ID));
    body.set('csrf_token', window.SH_IMPORT_CSRF);

    fetch('import_process.php', { method: 'POST', body: body })
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (data.error) {
          statusText.textContent = 'Error: ' + data.error;
          return;
        }

        var total = data.total_rows || 1;
        var pct = Math.min(100, Math.round((data.next_offset / total) * 100));
        bar.style.width = pct + '%';
        bar.textContent = pct + '%';
        statusText.textContent =
          data.next_offset + ' / ' + data.total_rows + ' rows processed -- ' +
          data.inserted_count + ' new, ' + data.updated_count + ' updated, ' +
          data.skipped_count + ' skipped, ' + data.error_count + ' errors.';

        if (data.done) {
          bar.classList.remove('progress-bar-animated', 'progress-bar-striped');
          doneBox.classList.remove('d-none');
        } else {
          setTimeout(tick, 150);
        }
      })
      .catch(function (err) {
        statusText.textContent = 'Network error, retrying...';
        setTimeout(tick, 2000);
      });
  }

  bar.classList.add('progress-bar-animated', 'progress-bar-striped');
  tick();
})();

// Live Saleshandy sequence status badges on campaigns.php -- rendered as
// "Checking..." placeholders so the page doesn't block on a Saleshandy
// round-trip; patched in place once campaign_saleshandy_status.php resolves.
(function () {
  var badges = document.querySelectorAll('.sequence-status-badge[data-sequence-id]');
  if (!badges.length) return;

  fetch('campaign_saleshandy_status.php')
    .then(function (res) { return res.json(); })
    .then(function (active) {
      badges.forEach(function (badge) {
        var id = badge.getAttribute('data-sequence-id');
        if (!Object.prototype.hasOwnProperty.call(active, id)) {
          badge.className = 'badge bg-light text-muted border';
          badge.title = 'Could not reach Saleshandy to check live status.';
          badge.textContent = 'Status unknown';
        } else if (active[id]) {
          badge.className = 'badge bg-success';
          badge.textContent = 'Sequence active';
        } else {
          badge.className = 'badge bg-danger';
          badge.title = "This sequence is paused/archived on Saleshandy -- pushing here won't do anything until it's reactivated there.";
          badge.textContent = 'Sequence paused';
        }
      });
    })
    .catch(function () {
      badges.forEach(function (badge) {
        badge.className = 'badge bg-light text-muted border';
        badge.title = 'Could not reach Saleshandy to check live status.';
        badge.textContent = 'Status unknown';
      });
    });
})();

// Dark/light theme toggle -- persists to localStorage under the same
// 'theme' key the pre-paint <script> in render_header() reads, so the
// choice survives a reload without a flash of the old theme.
(function () {
  var btn = document.getElementById('themeToggle');
  if (!btn) return;

  var label = btn.querySelector('.theme-toggle-label');

  function currentTheme() {
    return document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'dark' : 'light';
  }

  function render() {
    var theme = currentTheme();
    if (label) {
      label.textContent = theme === 'dark' ? 'Switch to light theme' : 'Switch to dark theme';
    }
  }

  btn.addEventListener('click', function () {
    var next = currentTheme() === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-bs-theme', next);
    localStorage.setItem('theme', next);
    render();
  });

  render();
})();

// Sidebar collapse toggle (desktop: shrinks the sidebar to width 0 and
// shows a small reopen button) -- persists to localStorage under
// 'sidebarCollapsed' so the state survives a reload. On mobile the
// sidebar is off-canvas by default regardless of this preference (see
// the separate mobile open/close handler below), so this toggle just
// mirrors that same collapsed state there too without doing any harm.
(function () {
  var toggle = document.getElementById('sidebarToggle');
  var openBtn = document.getElementById('sidebarOpenBtn');

  function setCollapsed(collapsed) {
    document.documentElement.classList.toggle('sidebar-collapsed', collapsed);
    localStorage.setItem('sidebarCollapsed', collapsed ? '1' : '0');
  }

  if (toggle) {
    toggle.addEventListener('click', function () {
      setCollapsed(true);
    });
  }
  if (openBtn) {
    openBtn.addEventListener('click', function () {
      if (window.matchMedia && window.matchMedia('(max-width: 991.98px)').matches) {
        var sidebar = document.getElementById('appSidebar');
        var backdrop = document.getElementById('sidebarBackdrop');
        if (sidebar) sidebar.classList.add('mobile-open');
        if (backdrop) backdrop.classList.add('show');
      } else {
        setCollapsed(false);
      }
    });
  }
})();

// Mobile off-canvas sidebar: tapping the backdrop, or a nav link inside
// the sidebar, closes it again -- otherwise it stays pinned open over
// the page content after navigating, hiding whatever loaded underneath.
(function () {
  var sidebar = document.getElementById('appSidebar');
  var backdrop = document.getElementById('sidebarBackdrop');
  if (!sidebar || !backdrop) return;

  function close() {
    sidebar.classList.remove('mobile-open');
    backdrop.classList.remove('show');
  }

  backdrop.addEventListener('click', close);
  sidebar.querySelectorAll('.sidebar-link').forEach(function (link) {
    link.addEventListener('click', close);
  });
})();

// Multi-select checkbox filter dropdowns (render_multiselect_filter() in helpers.php).
(function () {
  function updateLabel(toggle) {
    var menu = toggle.nextElementSibling;
    if (!menu) return;
    var checked = menu.querySelectorAll('input[type=checkbox]:checked').length;
    var base = toggle.getAttribute('data-base-label');
    if (base === null) {
      base = toggle.textContent.replace(/\s*\(.*\)\s*$/, '').trim();
      toggle.setAttribute('data-base-label', base);
    }
    toggle.textContent = base + (checked > 0 ? ' (' + checked + ')' : ' (all)');
  }

  document.querySelectorAll('.multiselect-filter').forEach(function (widget) {
    var toggle = widget.querySelector('.ms-toggle');
    var menu = widget.querySelector('.ms-menu');
    if (!toggle || !menu) return;

    menu.addEventListener('change', function (e) {
      if (e.target.matches('input[type=checkbox]')) {
        updateLabel(toggle);
      }
    });

    var search = menu.querySelector('.ms-search');
    if (search) {
      search.addEventListener('click', function (e) { e.stopPropagation(); });
      search.addEventListener('input', function () {
        var term = search.value.trim().toLowerCase();
        menu.querySelectorAll('.ms-option').forEach(function (opt) {
          var text = opt.textContent.trim().toLowerCase();
          opt.style.display = text.indexOf(term) === -1 ? 'none' : '';
        });
      });
    }

    var clearBtn = menu.querySelector('.ms-clear');
    if (clearBtn) {
      clearBtn.addEventListener('click', function () {
        menu.querySelectorAll('input[type=checkbox]:checked').forEach(function (cb) { cb.checked = false; });
        updateLabel(toggle);
      });
    }

    var selectAllBtn = menu.querySelector('.ms-select-all');
    if (selectAllBtn) {
      selectAllBtn.addEventListener('click', function () {
        // Only the currently visible options -- if a search term is
        // narrowing the list, "Select all" means "all of these", not
        // every hidden option too.
        menu.querySelectorAll('.ms-option').forEach(function (opt) {
          if (opt.style.display !== 'none') {
            var cb = opt.querySelector('input[type=checkbox]');
            if (cb) cb.checked = true;
          }
        });
        updateLabel(toggle);
      });
    }
  });
})();
