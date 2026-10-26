<?php
/**
 * Module #2 — Contact Form.
 *
 * Threads the browser dava_session_id into a form submission (hidden field,
 * public/js/form-session.js) and, on the form plugin's own SERVER-side
 * success hook, sends a form_submit event to Dáva. The server hook only
 * fires on a genuinely successful submission — solving the AJAX / no
 * thank-you-page gap that browser autocapture misses.
 *
 * Each plugin integration is registered only when that plugin is actually
 * active, so sites without Contact Form 7 / WPForms / Gravity Forms /
 * Elementor Pro Forms pay nothing for this module.
 *
 * A specific form can be opted out entirely via the "Excluded forms" setting
 * (Dava_Tracking_Settings::is_form_excluded(), checked in send_form_event())
 * — e.g. a newsletter signup with no conversion value — without disabling
 * tracking for every other form on the site.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Dava_Tracking_Contact_Form {

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
            self::$instance->register_hooks();
        }
        return self::$instance;
    }

    private function register_hooks() {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_session_field_script']);

        if (class_exists('WPCF7_ContactForm')) {
            add_action('wpcf7_mail_sent', [$this, 'handle_cf7_submission']);
        }

        if (function_exists('wpforms')) {
            add_action('wpforms_process_complete', [$this, 'handle_wpforms_submission'], 10, 4);
        }

        if (class_exists('GFForms')) {
            add_action('gform_after_submission', [$this, 'handle_gravity_submission'], 10, 2);
        }

        if (defined('ELEMENTOR_PRO_VERSION')) {
            add_action('elementor_pro/forms/new_record', [$this, 'handle_elementor_submission'], 10, 2);
        }
    }

    public function enqueue_session_field_script() {
        $options = Dava_Tracking_Settings::get_options();
        if (empty($options['enabled'])) {
            return;
        }

        wp_enqueue_script(
            'dava-tracking-form-session',
            DAVA_TRACKING_PLUGIN_URL . 'public/js/form-session.js',
            [Dava_Tracking_Listener::SESSION_HELPER_HANDLE],
            DAVA_TRACKING_VERSION,
            true
        );
    }

    /* ---------------------------------------------------------------
     * Contact Form 7
     * ------------------------------------------------------------- */

    public function handle_cf7_submission($contact_form) {
        $submission = WPCF7_Submission::get_instance();
        if (!$submission) {
            return;
        }

        $posted = $submission->get_posted_data();
        $timestamp = $submission->get_meta('timestamp');

        $this->send_form_event([
            'event_id' => 'cf7_' . $contact_form->id() . '_' . ($timestamp ?: time()),
            'transaction_id' => (string) $contact_form->id(),
            'email' => $this->find_email_in_cf7_tags($contact_form, $posted),
            'properties' => [
                'form_plugin' => 'contact-form-7',
                'form_id' => $contact_form->id(),
                'form_title' => $contact_form->title(),
            ],
        ]);
    }

    private function find_email_in_cf7_tags($contact_form, array $posted) {
        $tags = $contact_form->scan_form_tags();

        foreach ($tags as $tag) {
            if (Dava_Tracking_Settings::field_matches_email_hint($tag->name) && !empty($posted[$tag->name])) {
                return $posted[$tag->name];
            }
        }

        // basetype strips CF7's required-field "*" suffix (e.g. "email*" -> "email").
        foreach ($tags as $tag) {
            if ($tag->basetype === 'email' && !empty($posted[$tag->name])) {
                return $posted[$tag->name];
            }
        }
        return '';
    }

    /* ---------------------------------------------------------------
     * WPForms
     * ------------------------------------------------------------- */

    public function handle_wpforms_submission($fields, $entry, $form_data, $entry_id) {
        $this->send_form_event([
            'event_id' => 'wpforms_' . $entry_id,
            'transaction_id' => (string) $entry_id,
            'email' => $this->find_email_in_wpforms_fields($fields),
            'properties' => [
                'form_plugin' => 'wpforms',
                'form_id' => $form_data['id'] ?? null,
                'form_title' => $form_data['settings']['form_title'] ?? '',
            ],
        ]);
    }

    private function find_email_in_wpforms_fields(array $fields) {
        foreach ($fields as $field) {
            $identifier = trim(($field['name'] ?? '') . ' ' . ($field['label'] ?? '') . ' ' . ($field['id'] ?? ''));
            if (Dava_Tracking_Settings::field_matches_email_hint($identifier) && !empty($field['value'])) {
                return $field['value'];
            }
        }

        foreach ($fields as $field) {
            if (($field['type'] ?? '') === 'email' && !empty($field['value'])) {
                return $field['value'];
            }
        }
        return '';
    }

    /* ---------------------------------------------------------------
     * Gravity Forms
     * ------------------------------------------------------------- */

    public function handle_gravity_submission($entry, $form) {
        $this->send_form_event([
            'event_id' => 'gform_' . $entry['id'],
            'transaction_id' => (string) $entry['id'],
            'email' => $this->find_email_in_gravity_fields($form, $entry),
            'properties' => [
                'form_plugin' => 'gravity-forms',
                'form_id' => $form['id'],
                'form_title' => $form['title'] ?? '',
            ],
        ]);
    }

    private function find_email_in_gravity_fields($form, $entry) {
        foreach ($form['fields'] as $field) {
            $identifier = trim(($field->label ?? '') . ' ' . ($field->id ?? ''));
            if (Dava_Tracking_Settings::field_matches_email_hint($identifier) && !empty($entry[$field->id])) {
                return $entry[$field->id];
            }
        }

        foreach ($form['fields'] as $field) {
            if ($field->type === 'email' && !empty($entry[$field->id])) {
                return $entry[$field->id];
            }
        }
        return '';
    }

    /* ---------------------------------------------------------------
     * Elementor Pro Forms
     * ------------------------------------------------------------- */

    /**
     * @param \ElementorPro\Modules\Forms\Classes\Form_Record $record
     * @param \ElementorPro\Modules\Forms\Classes\Ajax_Handler $handler
     */
    public function handle_elementor_submission($record, $handler) {
        $fields = $record->get('fields');
        $unique_id = method_exists($record, 'get_unique_id') ? $record->get_unique_id() : uniqid('elementor_', true);

        $this->send_form_event([
            'event_id' => 'elementor_' . $unique_id,
            'transaction_id' => (string) $unique_id,
            'email' => $this->find_email_in_elementor_fields($fields),
            'properties' => [
                'form_plugin' => 'elementor-pro',
                'form_id' => $record->get_form_settings('id'),
                'form_title' => $record->get_form_settings('form_name'),
            ],
        ]);
    }

    private function find_email_in_elementor_fields(array $fields) {
        foreach ($fields as $field) {
            $identifier = trim(($field['title'] ?? '') . ' ' . ($field['id'] ?? ''));
            if (Dava_Tracking_Settings::field_matches_email_hint($identifier) && !empty($field['value'])) {
                return $field['value'];
            }
        }

        foreach ($fields as $field) {
            if (($field['type'] ?? '') === 'email' && !empty($field['value'])) {
                return $field['value'];
            }
        }
        return '';
    }

    /* ---------------------------------------------------------------
     * Shared send path
     * ------------------------------------------------------------- */

    /**
     * dava_session_id is read from the raw POST body — the hidden field
     * form-session.js injects is not a field any of the four plugins know
     * about, so it never appears in their own $fields/$entry structures.
     *
     * Dáva's API requires at least one identity signal (hashed_email here);
     * a submission whose form has no email field cannot be attributed, so
     * it is skipped rather than sent as a request guaranteed to fail.
     *
     * event_type/event_name are deliberately NOT "form_submit" — the browser
     * snippet already auto-captures every native <form> submit as
     * event_type=form_submit the moment the user clicks Submit, regardless
     * of whether the form plugin actually accepts it (spam-blocked, mail
     * delivery failure, etc. all still fire the browser event). This hook
     * only runs on a plugin-confirmed SUCCESS, so it is a distinct, stricter
     * signal — "conversion" / "form_submit_confirmed" — not a duplicate of
     * the browser's "an attempt happened" signal.
     */
    private function send_form_event(array $args) {
        if (Dava_Tracking_Settings::is_form_excluded($args['properties']['form_plugin'] ?? '', $args['properties']['form_id'] ?? null)) {
            return;
        }

        if (empty($args['email'])) {
            return;
        }

        $options = Dava_Tracking_Settings::get_options();
        if (!empty($options['require_consent']) && !Dava_Tracking_Settings::has_visitor_consented()) {
            return;
        }

        $event = [
            'event_type' => 'conversion',
            'event_name' => 'form_submit_confirmed',
            'event_id' => $args['event_id'],
            'transaction_id' => $args['transaction_id'],
            'hashed_email' => 'sha256:' . hash('sha256', strtolower(trim($args['email']))),
            'properties' => $args['properties'],
        ];

        $session_id = isset($_POST['dava_session_id']) ? sanitize_text_field(wp_unslash($_POST['dava_session_id'])) : '';
        if ($session_id !== '') {
            $event['dava_session_id'] = $session_id;
        }

        Dava_Tracking_Event_Sender::instance()->send_event($event);
    }
}
