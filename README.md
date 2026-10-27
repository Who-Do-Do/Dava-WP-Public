# Dáva Tracking for WordPress

Installs the [Dáva](https://davazmysel.com) browser listener on your WordPress site and sends confirmed server-side conversion events (paid WooCommerce orders and successful form submissions), each joined to the visitor's browser session.

## What it does

- Adds Dáva's tracking script to every page, server-side, so it keeps working when a tag manager strips the `data-site` attribute.
- Sends a confirmed conversion event when a WooCommerce order is actually paid, or when a form is actually submitted successfully (not only attempted), carrying the visitor's session id.
- Supports WooCommerce (Classic and Block Checkout), Contact Form 7, WPForms, Gravity Forms and Elementor Pro Forms. Each one is detected automatically.
- Settings page with a Test Connection button and a log of the last 20 send attempts.

## Requirements

- WordPress 6.0 or newer, PHP 8.0 or newer.
- WooCommerce 9.9 or newer for the Block Checkout capture. On an older WooCommerce only the Classic checkout captures the session.
- A Dáva workspace: you need its **Site ID** and an **API key**.

## Install

1. Download [`dava-tracking.zip`](https://github.com/Who-Do-Do/Dava-WP-Public/releases/latest/download/dava-tracking.zip).
2. In WordPress admin go to **Plugins, Add New, Upload Plugin**, choose the zip and activate it.
3. Go to **Settings, Dáva Tracking** and enter your **Site ID** and **API key** from the Dáva dashboard.
4. Turn on **Load the browser listener on every page** and save.
5. On the same **Connect** tab click **Test connection**. This only checks the connection. It creates no event and no session.

If you use a cookie banner, enable **Require cookie consent before tracking** on the Connect tab. See `readme.txt` for the details.

## Updates

The plugin checks this repository's GitHub Releases for new versions. In **Plugins** you can click **Enable auto-updates** for Dáva Tracking, or update it by hand when WordPress shows the update notice.

## Release (maintainers)

1. Bump `Version` in `dava-tracking.php`, `DAVA_TRACKING_VERSION` and `Stable tag` in `readme.txt` to the same value, and add a changelog entry.
2. Run `scripts/zip-plugin.sh`. It stops if the three versions differ and writes `build/dava-tracking.zip`.
3. Commit, push to `main`, then create a release tagged `vX.Y.Z` and attach the zip.

The asset must be named exactly `dava-tracking.zip`. The update checker only installs an asset with that name, and takes the version from the tag.

## License

GPL v2 or later.
