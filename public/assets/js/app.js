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
