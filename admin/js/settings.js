(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('dava-test-connection-form');
    if (!form || typeof DavaTrackingSettings === 'undefined') {
      return;
    }

    var button = document.getElementById('submit');
    var originalLabel = button ? button.value : '';
    var banner = document.getElementById('dava-test-connection-banner');
    var logBody = document.getElementById('dava-tracking-log-body');
    var logEmpty = document.getElementById('dava-tracking-log-empty');
    var logTable = logBody ? logBody.closest('table') : null;

    form.addEventListener('submit', function (event) {
      // Intercepting here is what removes the full-page reload/redirect the
      // admin-post.php fallback does — the form's action/nonce fields are
      // reused as-is, just sent via fetch instead of a real navigation.
      event.preventDefault();

      if (button) {
        button.disabled = true;
        button.value = DavaTrackingSettings.sendingLabel;
      }

      var formData = new FormData(form);
      formData.set('action', DavaTrackingSettings.ajaxAction);

      fetch(DavaTrackingSettings.ajaxUrl, {
        method: 'POST',
        credentials: 'same-origin',
        body: formData,
      })
        .then(function (response) {
          return response.json();
        })
        .then(function (payload) {
          var data = payload && payload.data ? payload.data : {};
          renderBanner(!!payload.success, data.message);
          if (data.entry) {
            prependLogRow(data.entry);
          }
        })
        .catch(function () {
          renderBanner(false, DavaTrackingSettings.networkErrorLabel);
        })
        .finally(function () {
          if (button) {
            button.disabled = false;
            button.value = originalLabel;
          }
        });
    });

    function renderBanner(isSuccess, message) {
      if (!banner || !message) {
        return;
      }
      banner.textContent = message;
      banner.className = 'dava-tracking-banner ' + (isSuccess ? 'is-success' : 'is-error');
      banner.hidden = false;
    }

    function prependLogRow(entry) {
      if (!logBody) {
        return;
      }
      if (logEmpty) {
        logEmpty.hidden = true;
      }
      if (logTable) {
        logTable.hidden = false;
      }

      var row = document.createElement('tr');

      var timeCell = document.createElement('td');
      timeCell.textContent = DavaTrackingSettings.justNowLabel;
      row.appendChild(timeCell);

      var eventCell = document.createElement('td');
      var code = document.createElement('code');
      code.textContent = entry.event_name || '';
      eventCell.appendChild(code);
      row.appendChild(eventCell);

      var statusCell = document.createElement('td');
      var statusSpan = document.createElement('span');
      var status = entry.status || '';
      statusSpan.className = 'dava-tracking-log-status is-' + status;
      statusSpan.textContent = status.charAt(0).toUpperCase() + status.slice(1);
      statusCell.appendChild(statusSpan);
      row.appendChild(statusCell);

      var messageCell = document.createElement('td');
      messageCell.textContent = entry.message || '';
      row.appendChild(messageCell);

      logBody.insertBefore(row, logBody.firstChild);
    }
  });
})();
