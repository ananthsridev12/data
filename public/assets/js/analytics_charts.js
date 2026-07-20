// Chart.js rendering for analytics.php -- reads the per-section JSON data
// blocks the page already embedded (chartdata-overall, chartdata-<key>)
// rather than fetching anything separately. Per-section bar charts are
// built lazily on first "Chart" tab view (Chart.js needs a visible,
// sized canvas to lay out correctly, and most sections are never opened
// in a given visit), each canvas only ever initialized once.
(function () {
  function readJson(id) {
    var el = document.getElementById(id);
    if (!el) {
      return null;
    }
    try {
      return JSON.parse(el.textContent);
    } catch (e) {
      return null;
    }
  }

  function renderOverallCharts() {
    var overall = readJson('chartdata-overall');
    if (!overall || typeof Chart === 'undefined') {
      return;
    }

    var importedEl = document.getElementById('chartOverallImported');
    if (importedEl) {
      new Chart(importedEl, {
        type: 'doughnut',
        data: {
          labels: ['Imported to Saleshandy', 'Not imported'],
          datasets: [{
            data: [overall.imported, overall.not_imported],
            backgroundColor: ['#198754', '#adb5bd'],
          }],
        },
        options: { maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } },
      });
    }

    var emailEl = document.getElementById('chartOverallEmail');
    if (emailEl) {
      new Chart(emailEl, {
        type: 'doughnut',
        data: {
          labels: ['Email sent', 'Email not sent'],
          datasets: [{
            data: [overall.email_sent, overall.email_not_sent],
            backgroundColor: ['#0d6efd', '#adb5bd'],
          }],
        },
        options: { maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } },
      });
    }
  }

  var renderedSections = {};

  function renderSectionChart(key) {
    if (renderedSections[key] || typeof Chart === 'undefined') {
      return;
    }
    var rows = readJson('chartdata-' + key);
    var canvas = document.getElementById('canvas-' + key);
    if (!rows || !canvas) {
      return;
    }
    renderedSections[key] = true;

    new Chart(canvas, {
      type: 'bar',
      data: {
        labels: rows.map(function (r) { return r.grp; }),
        datasets: [
          { label: 'Imported to Saleshandy', data: rows.map(function (r) { return Number(r.imported); }), backgroundColor: '#198754' },
          { label: 'Not imported', data: rows.map(function (r) { return Number(r.not_imported); }), backgroundColor: '#adb5bd' },
        ],
      },
      options: {
        indexAxis: 'y',
        maintainAspectRatio: false,
        responsive: true,
        scales: { x: { stacked: true, beginAtZero: true }, y: { stacked: true } },
        plugins: { legend: { position: 'bottom' } },
      },
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    renderOverallCharts();

    document.querySelectorAll('[data-bs-toggle="tab"][data-chart-key]').forEach(function (tabButton) {
      tabButton.addEventListener('shown.bs.tab', function () {
        renderSectionChart(tabButton.getAttribute('data-chart-key'));
      });
    });
  });
})();
