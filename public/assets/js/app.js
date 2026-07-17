(function () {
  var selectAll = document.getElementById('selectAllOnPage');
  if (selectAll) {
    selectAll.addEventListener('change', function () {
      document.querySelectorAll('.lead-checkbox').forEach(function (cb) {
        cb.checked = selectAll.checked;
      });
    });
  }
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
  });
})();
