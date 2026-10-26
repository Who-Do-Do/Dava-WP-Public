(function (window, document) {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    var field = document.getElementById('dava_session_id');
    if (!field || !window.DavaTracking) {
      return;
    }

    window.DavaTracking.getSessionId(function (sessionId) {
      field.value = sessionId || '';
    });
  });
})(window, document);
