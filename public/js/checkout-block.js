(function (window) {
  'use strict';

  // Hands the Dáva browser session id to the WooCommerce Block Checkout as
  // Store API extension data. The value travels inside the checkout request
  // only: no cookie, no extra network call, no new storage key. The PHP side
  // (Dava_Tracking_WooCommerce::capture_block_session_id) validates it and
  // writes it to the order.
  var NAMESPACE = 'dava-tracking';
  var CHECKOUT_STORE = 'wc/store/checkout';

  function checkoutStore() {
    try {
      if (!window.wp || !window.wp.data || typeof window.wp.data.dispatch !== 'function') {
        return null;
      }
      return window.wp.data.dispatch(CHECKOUT_STORE) || null;
    } catch (e) {
      return null;
    }
  }

  // setExtensionData is the stable name; __internalSetExtensionData is the
  // older one, deprecated in WooCommerce 9.9.0. Use whichever exists.
  function setSessionId(sessionId) {
    var store = checkoutStore();
    if (!store) {
      return;
    }

    var data = { session_id: sessionId };
    try {
      if (typeof store.setExtensionData === 'function') {
        store.setExtensionData(NAMESPACE, data);
      } else if (typeof store.__internalSetExtensionData === 'function') {
        store.__internalSetExtensionData(NAMESPACE, data);
      }
    } catch (e) {
      // Never break the checkout over tracking.
    }
  }

  function init() {
    if (!window.DavaTracking || typeof window.DavaTracking.getSessionId !== 'function') {
      return;
    }

    // Same wait as checkout.js: t.js sets window.dava asynchronously, so poll
    // for up to 3 seconds. A visitor who places the order sooner, or whose
    // t.js is blocked, sends no session id and the order is still placed.
    window.DavaTracking.getSessionId(function (sessionId) {
      if (sessionId) {
        setSessionId(sessionId);
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})(window);
