=== User Management Suite ===
Contributors: marinski
Tags: users, user registration, email verification, notification preferences, user analytics
Requires at least: 6.2
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A modular user-management toolkit: registration tracking, email verification, multiple roles, user switching, acquisition reporting and notification preferences.

== Description ==

User Management Suite brings common user-management needs together in one
modular plugin. Enable only the features you want from a single tabbed settings
page under **Users → User Management**.

**Modules**

* **Registration & Activity Tracking** — record each user's last login time and (optional, anonymizable) IP address, with sortable Users-list columns and CSV export.
* **Email Verification** — require new users to verify their email before logging in, with spam/domain blocking and optional Google reCAPTCHA.
* **Multiple Roles** — assign more than one role to a user via a simple checklist on the user editor.
* **User Switching** — switch into any account you can edit and switch back instantly, without sharing passwords.
* **Import & Export** — move users in and out as CSV.
* **Acquisition Tracking** — record where each user actually came from, captured on the landing page rather than at signup.
* **Insights & Reports** — growth, acquisition, activation, retention and email-health reports built from a nightly rollup.
* **Notification Preferences** — let users choose which emails they receive, with RFC 8058 one-click unsubscribe.

Each module can be turned on or off independently, so the plugin stays as light
as your site needs. The three newest modules are off by default.

**Why acquisition tracking is a separate module**

Most "registration source" tracking reads the referrer at the moment someone
submits the signup form — which, by then, is one of your own pages. This module
captures campaign parameters and the referring site on the landing page instead,
holds them in a first-party cookie, and attaches them to the account only if the
visitor registers. If your store runs WooCommerce, existing order-attribution
data can be imported so the reports have history from day one.

**Notification preferences, honestly scoped**

Order, payment and account emails stay required — they are the record of a
transaction the user entered into, and letting someone silence their own
receipts creates support tickets rather than satisfaction. Advance reminders and
anything a plugin registers as optional can be switched off, individually or
through a one-click unsubscribe link that works without logging in.

== External services ==

This plugin can optionally connect to **Google reCAPTCHA** when you enable
reCAPTCHA in the Email Verification module and provide your own site/secret keys.
When enabled, reCAPTCHA is loaded on the configured forms (login, registration,
and/or lost-password) and the visitor's reCAPTCHA token is sent to Google for
verification. No reCAPTCHA requests are made unless you enable the feature and
enter keys.

* Google reCAPTCHA Terms: https://policies.google.com/terms
* Google Privacy Policy: https://policies.google.com/privacy

No other external service is contacted. Reporting data stays in your own
database.

== Installation ==

1. Upload the `user-management-suite` folder to `/wp-content/plugins/`, or install through the Plugins screen.
2. Activate the plugin.
3. Go to **Users → User Management** and enable the modules you want.
4. If you enable Insights, build the reporting data once with WP-CLI:
   `wp ums attribution backfill` then `wp ums stats rebuild --all`.

== Frequently Asked Questions ==

= Does enabling a module change anything until I configure it? =
Modules ship with safe defaults. Email Verification, Acquisition Tracking,
Insights and Notification Preferences are all off by default, so existing
sign-up and email flows are never changed unexpectedly.

= Can a user switch off their order confirmation emails? =
No. Order, payment and account emails are marked required and cannot be
disabled by the recipient. That rule is enforced when the decision is made, not
just hidden in the interface, so a stale or hand-edited preference cannot
suppress a receipt. Sites that need different rules can use the
`ums_notification_woocommerce_optional` filter.

= Do the reports slow down my site? =
No. Every report reads a pre-built summary table, never the user table directly.
The summary is refreshed by a nightly job over a trailing window; full history
rebuilds are a WP-CLI command so no web request has to hold them open.

= Why does my acquisition data say "not recorded" for older users? =
Acquisition can only be captured from the moment the module is enabled. If your
site runs WooCommerce, `wp ums attribution backfill` imports the order
attribution WooCommerce has already been collecting, which usually covers most
existing customers.

= Will uninstalling delete my data? =
Only if you tick "Delete all plugin data when the plugin is uninstalled" on the
General tab. Otherwise your settings and tracked data are preserved.

== Privacy ==

The plugin registers exporters and erasers with WordPress's personal-data tools,
covering registration and login data, acquisition records, notification
preferences and the send log. Notification opt-outs are deliberately retained on
erasure: deleting them would silently resubscribe someone who asked not to be
contacted.

Acquisition tracking can be gated behind a consent platform via the
`ums_attribution_has_consent` filter. When consent is required and nothing
answers that filter, no tracking happens at all.

== Changelog ==

= 1.1.1 =
* Fixed: a social sign-in bounces the visitor through the identity provider, so the referrer on the return leg was the provider's domain. `accounts.google.com` reduces to "google" and was landing in organic search, crediting SEO for people who clicked "Sign in with Google". Identity-provider and payment-return hosts are now ignored as sources, filterable via `ums_attribution_auth_providers`.
* Fixed: visitors with no referrer and no campaign parameters produced no record at all, so direct traffic was reported as "not recorded". Direct traffic is now stored as direct, keeping it distinct from missing data.

= 1.1.0 =
* New: Acquisition Tracking module — landing-page capture of campaign parameters and referrer, first and last touch per user, channel classification, REST endpoint for decoupled front ends, and an importer for existing WooCommerce order attribution.
* New: Insights & Reports module — growth, acquisition, activation funnel, retention and email-health reports, built from a nightly rollup table with CSV export and a dashboard widget.
* New: Notification Preferences module — a registry of every email the site can send, per-user preferences, RFC 8058 one-click unsubscribe, a `[ums_preferences]` shortcode, a REST API, and an optional send log.
* New: WP-CLI commands `wp ums attribution` and `wp ums stats`.
* Changed: the registration-source setting is superseded by Acquisition Tracking, which records the real source rather than the last internal click.
* Privacy: exporters and erasers extended to cover all new data.

= 1.0.0 =
* Initial release: Registration tracking, Email verification, Multiple roles, and User switching modules.
