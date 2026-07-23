// Chart.js rendering for reports.php's Daily Activity (line: emails sent /
// opened) and Weekly (mixed bar+line: volume against open rate) charts.
// Reads the JSON data blocks the page already embedded, same pattern as
// analytics_charts.js.
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

  document.addEventListener('DOMContentLoaded', function () {
    if (typeof Chart === 'undefined') {
      return;
    }

    var daily = readJson('chartdata-daily');
    var dailyEl = document.getElementById('chartDaily');
    if (daily && dailyEl) {
      new Chart(dailyEl, {
        type: 'line',
        data: {
          labels: daily.map(function (r) { return r.date; }),
          datasets: [
            { label: 'Emails sent', data: daily.map(function (r) { return Number(r.emails_sent); }), borderColor: '#0d6efd', backgroundColor: '#0d6efd', tension: 0.2 },
            { label: 'Opened', data: daily.map(function (r) { return Number(r.opened); }), borderColor: '#198754', backgroundColor: '#198754', tension: 0.2 },
          ],
        },
        options: { maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } }, scales: { y: { beginAtZero: true } } },
      });
    }

    var weekly = readJson('chartdata-weekly');
    var weeklyEl = document.getElementById('chartWeekly');
    if (weekly && weeklyEl) {
      new Chart(weeklyEl, {
        data: {
          labels: weekly.map(function (r) { return r.week_start; }),
          datasets: [
            { type: 'bar', label: 'Emails sent', data: weekly.map(function (r) { return Number(r.emails_sent); }), backgroundColor: '#0d6efd', yAxisID: 'y' },
            { type: 'line', label: 'Open rate %', data: weekly.map(function (r) { return Number(r.open_rate) * 100; }), borderColor: '#fd7e14', backgroundColor: '#fd7e14', yAxisID: 'y1', tension: 0.2 },
          ],
        },
        options: {
          maintainAspectRatio: false,
          plugins: { legend: { position: 'bottom' } },
          scales: {
            y: { beginAtZero: true, position: 'left', title: { display: true, text: 'Emails sent' } },
            y1: { beginAtZero: true, position: 'right', title: { display: true, text: 'Open rate %' }, grid: { drawOnChartArea: false } },
          },
        },
      });
    }
  });
})();
