=== Dáva Tracking ===
Contributors: dava
Tags: analytics, tracking, conversion tracking, woocommerce, contact form 7
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 1.1.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Install the Dáva browser listener and send confirmed server-side conversion events — no code required.

== Description ==

Dáva Tracking automates the two-step hybrid recipe that gives Dáva full visitor-journey attribution:

1. **Browser listener** — injects Dáva's tracking script into every page, server-side (`wp_enqueue_script`), so it works even when Google Tag Manager strips the `data-site` attribute from a Custom HTML tag.
2. **Confirmed server-side events** — when a form is actually submitted successfully (not just attempted) or a WooCommerce order is actually paid, the plugin sends a confirmed conversion event to Dáva, carrying the visitor's browser session ID so it joins the rest of their journey.

**Supported integrations**

* Contact Form 7
* WPForms
* Gravity Forms
* Elementor Pro Forms
* WooCommerce

Each integration is detected automatically — nothing to configure per-plugin. The settings page shows which of the above are active on your site.

**Built-in diagnostics**

* Test connection (Connect tab) — calls a dedicated connectivity-check endpoint (no event or session created) so you know immediately whether the Site ID, API key, and API base URL actually work together.
* Recent activity log (Activity tab) — the last 20 send attempts (success and failure, with the real error message), so you never have to guess why an event didn't show up in Dáva.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/dava-tracking`, or install the zip directly through the WordPress admin (**Plugins → Add New → Upload Plugin**).
2. Activate the plugin through the **Plugins** menu.
3. Go to **Settings → Dáva Tracking**.
4. Enter your **Site ID** (Dáva dashboard → Settings → Workspace → Settings & Integrations) and **API key** (Dáva dashboard → API Keys).
5. Turn on **Load the browser listener on every page**, then click **Save changes**.
6. On the same **Connect** tab click **Test connection** to confirm the connection works — this only checks connectivity, it does not create an event or session.

That's it — no code editing required. If your site uses Contact Form 7, WPForms, Gravity Forms, Elementor Pro Forms, or WooCommerce, confirmed conversion events start sending automatically once tracking is enabled.

== Frequently Asked Questions ==

= Where do I find my Site ID and API key? =

Both are in your Dáva dashboard. Site ID is under **Settings → Workspace → Settings & Integrations**. API keys are managed under **API Keys** in the same dashboard.

= Does this replace the WooCommerce webhook integration? =

No, they're complementary. The native WooCommerce webhook (configured in WooCommerce's own Settings → Advanced → Webhooks) sends confirmed order events, and carries the browser session ID only when the order already holds it. This plugin captures the browser session ID at checkout, on both the classic checkout and the Block Checkout. If you run both, each order is one conversion in Dáva: both deliveries use the WooCommerce order number as their key, so they merge instead of double-counting. This merge needs the matching Dáva server update to be live for your workspace; until then the two deliveries can show as two rows. A Dáva workspace connects one WooCommerce store.

= Does it work with the WooCommerce Block Checkout? =

Yes. The plugin passes the browser session ID to the Block Checkout through WooCommerce's Store API extension data, so the order is stored with the same session meta as on the classic checkout. This needs WooCommerce 9.9 or newer; on an older WooCommerce only the classic checkout captures the session (Tools → Site Health shows a note). If the visitor places the order within about 3 seconds of the page loading, or the Dáva script is blocked, the order is still placed but carries no session ID.

= I use a cookie consent banner. Will this plugin track visitors before they consent? =

Only if you leave the consent setting off. Enable **Require cookie consent before tracking** on the Connect tab, and your consent banner must set a `dava-consent` cookie to `"true"` and call `window.dava.checkConsent()` when the visitor accepts. Dáva does not integrate with any specific consent management platform automatically.

= My form plugin isn't listed — will it still work? =

The browser listener tracks page views and click-based goals regardless of which form plugin you use. Confirmed server-side form-submission events are currently only supported for Contact Form 7, WPForms, Gravity Forms, and Elementor Pro Forms.

= Does clicking Test Connection show up in my real analytics? =

No. Test Connection calls a dedicated verify-only endpoint (`GET /v1/verify`) that creates no event and no session — it never appears in the Sessions list, Event Inspector, or any funnel/goal.

= Can I have this plugin update itself automatically? =

Yes. Go to **Plugins**, find Dáva Tracking, and click **Enable auto-updates** — this is a standard WordPress feature and works the same way as it does for any wordpress.org plugin. It's off by default; turn it on if you don't want to click Update by hand.

== Changelog ==

= 1.1.3 =
* Fix: the Activity log no longer loses lines when two things are logged at the same moment (for example two orders sent by WP-Cron, or an order and a form submission). Before, one of the two lines could be dropped, so an order that did arrive could be missing from the list. Every line is now kept, up to the usual 20 most recent.

= 1.1.2 =
* Fix: Place Order no longer waits for Dáva. A paid WooCommerce order is now queued when its status changes and sent a moment later by WP-Cron, so a slow or unresponsive Dáva API can no longer hold up checkout (it used to add up to 5 seconds). This applies to both the Block and the classic checkout.
* Change: an order is marked as sent only after Dáva accepted it. Until then it shows in the Retry queue on the Activity tab, and failed sends keep the existing retry schedule.
* Change: events sent while a visitor waits (form submissions) now give up after 2 seconds instead of 5. Orders, retries and **Resend past orders** run with nobody waiting and keep the 5 second limit.
* Fix: the retry queue now also runs on requests that never send an event. Before, its WP-Cron hook was only registered after something sent an event, so queued retries could wait.
* Change: **Resend past orders** still sends right away and now also counts orders that are already queued ("already queued").
* Upgrade note: sending happens on the next WP-Cron run. On a site with `DISABLE_WP_CRON` set, make sure a system cron calls `wp-cron.php`, or orders stay in the Retry queue until it runs.

= 1.1.1 =
* Fix: the active tab no longer shows stray blue lines at its edges after a click.
* Change: **Advanced** is its own tab again instead of a collapsing card, so the tabs are Connect, Integrations, Advanced and Activity. Settings are unchanged.
* Change: the Retry queue on the Activity tab lists the events waiting to be resent (event, order number, attempt), and Recent activity has a **Refresh** button that updates the page in place.
* Change: the Open Dáva dashboard button lines up with the top of the header, and corners are 2px tighter.

= 1.1.0 =
* Add: the WooCommerce conversion event now carries the order's products (name, SKU, quantity, total), coupon codes, discount, tax and shipping totals, payment method and billing country, plus the order-received page URL (without the order key). No customer name, address or phone is sent.
* Change: the settings page is redesigned with three tabs, Connect, Integrations and Activity. The connection status sits in the header, the connection test sits next to the credentials, and the form options only show when a supported form plugin is installed. Geist is bundled with the plugin (SIL Open Font License), so the page makes no request to a font service.
* Change: **Send test event** is now **Test connection** and sits next to the credentials on the Connect tab. It calls a connectivity check and creates no event, so the old name was misleading. It tests the saved settings, so it is disabled while the form has unsaved changes.
* Change: the Preview of **Resend past orders** now says why orders would be skipped (total of 0, no billing email, no consent, already sent).
* Change: the Activity tab shows a next step under the two failures a site owner can fix (a domain not registered in Dáva, an invalid or revoked API key).
* Upgrade note: the old tab links `tab=connection`, `tab=advanced` and `tab=diagnostics` still open the right tab. Settings are unchanged.

= 1.0.0 =
* First public release, published from the Dava-WP-Public repository. Updates are delivered from this repository's GitHub Releases.
* Add: WooCommerce Block Checkout support. The browser session ID is captured through Store API extension data (namespace `dava-tracking`), together with the consent state, and stored on the order under the same keys as the classic checkout. Previously Block Checkout orders carried no session ID. Needs WooCommerce 9.9 or newer for the Block path.
* Fix: a session ID that is not a valid Dáva session id (wrong shape, not a string) is now dropped instead of stored. Dáva rejects the whole event for a malformed session ID, so one bad value used to lose the conversion. Applies to the classic checkout, the Block Checkout, and orders stored by earlier versions.
* Add: declares compatibility with High-Performance Order Storage and the Cart and Checkout Blocks, and a `WC requires at least` header.
* Add: a Site Health note when WooCommerce is older than the Block Checkout capture needs.
* Docs: the FAQ on running the plugin together with the WooCommerce webhook now states that both deliveries merge into one conversion per order once the matching Dáva server update is live, and that a workspace connects one WooCommerce store.
* Upgrade note: the event key is unchanged (`transaction_id` is still the WooCommerce order number). An order already marked as sent by an earlier version is not sent again, and events waiting in the retry queue keep the same key.

= 0.6.0 =
* Fix: Contact Form and WooCommerce conversion events now respect "Require cookie consent before tracking" — previously this setting only gated the browser listener script, and server-side confirmed events (form submits, paid orders) were sent regardless of visitor consent.
* Add: "Open Dáva Dashboard" link on the settings page sidebar.
* Add: consent gate now recognizes the standard WP Consent API (Complianz, CookieYes, Real Cookie Banner, and other providers) automatically — sites using one of those no longer need to build a custom integration with Dáva's own cookie.
* Add: Diagnostics tab shows how many events are pending retry and when the next attempt runs.
* Fix: a rare race condition where two near-simultaneous order-status changes (e.g. a payment gateway retrying its webhook) could both pass the already-sent check before either saved it, sending the same order twice.
* Add: Elementor Pro Forms support — confirmed conversion events on successful submission, same as Contact Form 7 / WPForms / Gravity Forms.
* Add: a "Dáva Tracking" test on Tools → Site Health → Status, showing connection state without needing to know to check the Diagnostics tab first.
* Add: an admin notice when tracking is enabled but the Site ID or API key is missing, so an incomplete setup no longer fails silently.
* Add: `[dava_session_field]` shortcode — drops the same session-id hidden field the 4 built-in form integrations inject automatically into any custom or unsupported form.
* Add: `dava_tracking_event_before_send` filter and `dava_tracking_event_sent` action for custom integrations, without forking the plugin.
* Add: Debug mode (Advanced tab) — writes full request/response payloads to the PHP error log while troubleshooting, off by default.
* Add: a "Backfill WooCommerce orders" tool (Diagnostics tab) to resend conversion events for paid orders from the last 30 days that never got one — for a site that enables tracking after already having orders.
* Add: "Email field hint" setting — matches a form field by name/label/ID before falling back to auto-detecting the first email-type field, for forms with more than one email field.
* Add: Backfill tool has a Preview button — classifies orders (would send / already sent / no consent / no email) without sending anything, before committing to a real run.
* Add: registers with WordPress's Tools → Erase Personal Data flow — an erasure request now removes the session-id and consent data this plugin stored on that person's WooCommerce orders.
* Add: a "Reset to defaults" button (Advanced tab) reverts API base URL, Browser listener URL, Require cookie consent, Debug mode, and Email field hint back to defaults without touching Site ID or API key.
* Add: "Excluded forms" setting — skip specific forms entirely (e.g. a newsletter signup with no conversion value) by plugin + form ID, without turning off tracking for every form on the site.
* Add: "View this visitor's journey in Dáva" link on the WooCommerce order edit screen, once a confirmed event has been sent for that order — jumps straight to that visitor's session in the Dáva dashboard.
* Add: the settings page header now shows which Dáva API key and environment (test/production) Test Connection last verified, with a warning color on "test" — catches a test key left on a live site (or vice versa) at a glance.

= 0.5.0 =
* Add: failed event sends (network errors, 5xx responses) now retry automatically via WP-Cron, up to 3 attempts — config errors (bad API key, 4xx) are never retried since they'd fail the same way every time.
* Add: uninstall cleanup — deleting the plugin now removes its options and any pending retry schedule instead of leaving them in the database.
* Docs: noted that WordPress's native "Enable auto-updates" toggle (Plugins page) already works for this plugin — no separate setting needed.

= 0.4.0 =
* Add: automatic update checks against the plugin's private GitHub repository — WordPress now shows a native "Update available" notice on the Plugins page instead of requiring a manual delete-and-reupload of a new zip.

= 0.3.0 =
* Fix: default API base URL corrected to `api-v2.davazmysel.com` (the previous default, `api.davazmysel.com`, 404s — confirmed against production).
* Change: Test Connection now calls a dedicated `GET /v1/verify` endpoint instead of sending a real event — a passing test no longer leaves a phantom entry in the Dáva Sessions list.
* Add: Plugin URI / Author URI link to https://davazmysel.com in the plugin header.

= 0.2.0 =
* Fix: default API base URL and browser listener URL now point to the correct, distinct production hosts (previously both pointed at the wrong host, which meant the listener script would fail to load and server events would fail to send).
* Add: automatic `subdomain` field on outgoing events, required for correct domain attribution when calling the Dáva API host directly.
* Add: optional cookie-consent gate (`data-require-consent`), matching the Dáva dashboard's own snippet behavior.
* Add: WooCommerce module — session capture into order meta at checkout, confirmed order event on payment, using a dedup key that merges with the native WooCommerce webhook instead of double-counting.
* Add: Test Connection diagnostics and a recent-activity log.
* Add: tabbed settings UI (Connection / Advanced / Diagnostics) with a sidebar showing which supported plugins are detected on the site.

= 0.1.0 =
* Initial release: browser listener injection, admin settings, Contact Form module (Contact Form 7, WPForms, Gravity Forms).
