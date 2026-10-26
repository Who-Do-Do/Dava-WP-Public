(function (window) {
  'use strict';

  /**
   * window.dava is set up asynchronously by the listener script (t.js), so a
   * form/checkout script reading window.dava.sessionId on page load can lose
   * the race. Poll briefly instead of reading it once.
   */
  function getSessionId(callback, maxWaitMs) {
    var waited = 0;
    var interval = 100;
    maxWaitMs = maxWaitMs || 3000;

    function poll() {
      if (window.dava && window.dava.sessionId) {
        callback(window.dava.sessionId);
        return;
      }
      waited += interval;
      if (waited >= maxWaitMs) {
        callback(null);
        return;
      }
      setTimeout(poll, interval);
    }

    poll();
  }

  window.DavaTracking = window.DavaTracking || {};
  window.DavaTracking.getSessionId = getSessionId;
})(window);
