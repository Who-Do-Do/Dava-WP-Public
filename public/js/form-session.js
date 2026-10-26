(function (window, document) {
  'use strict';

  // Covers Contact Form 7, WPForms, Gravity Forms and Elementor Pro Forms
  // markup. Each plugin's own field system doesn't know about this hidden
  // field — the PHP side reads it straight off $_POST, not off the plugin's
  // parsed field list.
  var FORM_SELECTORS = ['.wpcf7-form', '.wpforms-form', '.gform_wrapper form', '.elementor-form'];

  function injectHiddenField(form, sessionId) {
    var input = form.querySelector('input[name="dava_session_id"]');
    if (!input) {
      input = document.createElement('input');
      input.type = 'hidden';
      input.name = 'dava_session_id';
      form.appendChild(input);
    }
    input.value = sessionId || '';
  }

  function attach() {
    var forms = document.querySelectorAll(FORM_SELECTORS.join(','));
    // [dava_session_field] shortcode output — for forms outside the 4 built-in
    // integrations, where there's no plugin-specific selector to auto-inject
    // into. Filled directly, wherever it appears in the page.
    var manualFields = document.querySelectorAll('input[name="dava_session_id"]');
    if (!forms.length && !manualFields.length) {
      return;
    }

    window.DavaTracking.getSessionId(function (sessionId) {
      forms.forEach(function (form) {
        injectHiddenField(form, sessionId);
      });
      manualFields.forEach(function (input) {
        input.value = sessionId || '';
      });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', attach);
  } else {
    attach();
  }
})(window, document);
