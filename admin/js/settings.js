(function () {
  'use strict';

  var settings = typeof DavaTrackingSettings === 'undefined' ? null : DavaTrackingSettings;

  document.addEventListener('DOMContentLoaded', function () {
    initTabs();
    initDirtyState();
    initLogFilter();
    initTestConnection();
  });

  // Tabs are real links (?tab=...), so they work without JS. With JS the
  // panels are already in the page, so switching is instant and an edit on
  // one tab is not lost by a reload.
  function initTabs() {
    var tabs = document.querySelectorAll('.dava-tracking-tab');
    var savebar = document.getElementById('dava-tracking-savebar');

    tabs.forEach(function (tab) {
      tab.addEventListener('click', function (event) {
        event.preventDefault();
        var name = tab.getAttribute('data-tab');

        tabs.forEach(function (other) {
          var active = other === tab;
          other.classList.toggle('is-active', active);
          if (active) {
            other.setAttribute('aria-current', 'page');
          } else {
            other.removeAttribute('aria-current');
          }
        });
        document.querySelectorAll('.dava-tracking-panel').forEach(function (panel) {
          panel.classList.toggle('is-active', panel.id === 'dava-tracking-panel-' + name);
        });
        // Activity has nothing to save.
        if (savebar) {
          savebar.hidden = name === 'activity';
        }
        if (window.history && window.history.replaceState) {
          window.history.replaceState(null, '', tab.getAttribute('href'));
        }
      });
    });
  }

  // The connection test reads the SAVED options on the server, so while the
  // form has unsaved edits the button would test something other than what
  // the user sees. It is disabled until they save.
  function initDirtyState() {
    var form = document.getElementById('dava-tracking-settings-form');
    var savebar = document.getElementById('dava-tracking-savebar');
    var message = document.getElementById('dava-tracking-savebar-msg');
    var testButton = document.getElementById('dava-tracking-test-button');
    var testResult = document.getElementById('dava-tracking-test-result');
    var testNote = document.getElementById('dava-tracking-test-note');
    if (!form || !savebar || !message) {
      return;
    }

    form.addEventListener('input', function () {
      savebar.classList.add('is-dirty');
      message.textContent = message.getAttribute('data-dirty');
      if (testButton) {
        testButton.disabled = true;
      }
      if (testResult) {
        testResult.hidden = true;
      }
      if (testNote) {
        testNote.hidden = false;
      }
    });
  }

  function initLogFilter() {
    var filter = document.getElementById('dava-tracking-log-filter');
    var body = document.getElementById('dava-tracking-log-body');
    if (!filter || !body) {
      return;
    }

    filter.addEventListener('click', function (event) {
      var button = event.target.closest('button');
      if (!button) {
        return;
      }
      filter.querySelectorAll('button').forEach(function (other) {
        other.setAttribute('aria-pressed', other === button ? 'true' : 'false');
      });
      applyLogFilter();
    });
  }

  function applyLogFilter() {
    var filter = document.getElementById('dava-tracking-log-filter');
    var body = document.getElementById('dava-tracking-log-body');
    var pressed = filter ? filter.querySelector('button[aria-pressed="true"]') : null;
    var onlyErrors = pressed && pressed.getAttribute('data-filter') === 'error';

    body.querySelectorAll('tr').forEach(function (row) {
      row.hidden = onlyErrors && row.getAttribute('data-status') !== 'error';
    });
  }

  function initTestConnection() {
    var form = document.getElementById('dava-tracking-test-form');
    if (!form || !settings) {
      return;
    }

    var button = document.getElementById('dava-tracking-test-button');
    var originalLabel = button ? button.textContent : '';

    form.addEventListener('submit', function (event) {
      // Intercepting here is what removes the full-page reload the
      // admin-post.php fallback does: the form's nonce is reused as-is, just
      // sent through fetch.
      event.preventDefault();

      if (button) {
        button.disabled = true;
        button.textContent = settings.sendingLabel;
      }

      var formData = new FormData(form);
      formData.set('action', settings.ajaxAction);

      fetch(settings.ajaxUrl, {
        method: 'POST',
        credentials: 'same-origin',
        body: formData,
      })
        .then(function (response) {
          return response.json();
        })
        .then(function (payload) {
          var data = payload && payload.data ? payload.data : {};
          renderResult(!!payload.success, data.message);
          renderStatus(!!payload.success, data.status);
          if (data.entry) {
            prependLogRow(data.entry);
          }
        })
        .catch(function () {
          renderResult(false, settings.networkErrorLabel);
        })
        .finally(function () {
          if (button) {
            button.disabled = false;
            button.textContent = originalLabel;
          }
        });
    });
  }

  function renderResult(isSuccess, message) {
    var result = document.getElementById('dava-tracking-test-result');
    if (!result || !message) {
      return;
    }
    result.textContent = message;
    result.className = 'dava-tracking-result ' + (isSuccess ? 'is-ok' : 'is-err');
    result.hidden = false;
  }

  // Keeps the header badge in step with the check that just ran.
  function renderStatus(isSuccess, status) {
    var chip = document.getElementById('dava-tracking-status-chip');
    var line = document.getElementById('dava-tracking-status-line');
    if (!chip || !line) {
      return;
    }

    if (!isSuccess) {
      chip.className = 'dava-tracking-chip is-err';
      chip.textContent = settings.failedLabel;
      line.textContent = settings.failedLineLabel;
      return;
    }

    if (status) {
      var isTest = status.environment === 'test';
      chip.className = 'dava-tracking-chip ' + (isTest ? 'is-warn' : 'is-ok');
      chip.textContent = isTest ? settings.connectedTestLabel : settings.connectedLabel;
      line.textContent = [
        status.key_name,
        (status.environment || settings.unknownLabel) + ' ' + settings.environmentLabel,
        settings.verifiedJustNowLabel,
      ].join(' · ');
    }
  }

  function prependLogRow(entry) {
    var body = document.getElementById('dava-tracking-log-body');
    if (!body) {
      return;
    }

    var wrap = body.closest('.dava-tracking-log-wrap');
    var empty = document.getElementById('dava-tracking-log-empty');
    if (empty) {
      empty.hidden = true;
    }
    if (wrap) {
      wrap.hidden = false;
    }

    var status = entry.status === 'error' ? 'error' : 'success';
    var row = document.createElement('tr');
    row.setAttribute('data-status', status);

    var timeCell = document.createElement('td');
    timeCell.className = 'dava-tracking-log-time';
    timeCell.textContent = settings.justNowLabel;
    row.appendChild(timeCell);

    var eventCell = document.createElement('td');
    var code = document.createElement('code');
    code.textContent = entry.event_name || '';
    eventCell.appendChild(code);
    row.appendChild(eventCell);

    var statusCell = document.createElement('td');
    var chip = document.createElement('span');
    chip.className = 'dava-tracking-chip ' + (status === 'error' ? 'is-err' : 'is-ok');
    chip.textContent = status === 'error' ? settings.errorLabel : settings.successLabel;
    statusCell.appendChild(chip);
    row.appendChild(statusCell);

    var messageCell = document.createElement('td');
    messageCell.textContent = entry.message || '';
    row.appendChild(messageCell);

    body.insertBefore(row, body.firstChild);
    applyLogFilter();
  }
})();
