=== User Management Suite ===
Contributors: marinski
Tags: users, user registration, email verification, multiple roles, user switching
Requires at least: 6.2
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A complete, modular user-management toolkit: registration tracking, email verification, multiple roles per user, and instant user switching — from one settings page.

== Description ==

User Management Suite brings four common user-management needs together in one
lightweight, modular plugin. Enable only the features you want from a single
tabbed settings page under **Users → User Management**.

**Modules**

* **Registration & Activity Tracking** — record where each user registered from, their last login time and (optional, anonymizable) IP address, with sortable Users-list columns and CSV export.
* **Email Verification** — require new users to verify their email before logging in, with spam/domain blocking and optional Google reCAPTCHA.
* **Multiple Roles** — assign more than one role to a user via a simple checklist on the user editor.
* **User Switching** — switch into any account you can edit and switch back instantly, without sharing passwords.

Each module can be turned on or off independently, so the plugin stays as light
as your site needs.

== External services ==

This plugin can optionally connect to **Google reCAPTCHA** when you enable
reCAPTCHA in the Email Verification module and provide your own site/secret keys.
When enabled, reCAPTCHA is loaded on the configured forms (login, registration,
and/or lost-password) and the visitor's reCAPTCHA token is sent to Google for
verification. No reCAPTCHA requests are made unless you enable the feature and
enter keys.

* Google reCAPTCHA Terms: https://policies.google.com/terms
* Google Privacy Policy: https://policies.google.com/privacy

== Installation ==

1. Upload the `user-management-suite` folder to `/wp-content/plugins/`, or install through the Plugins screen.
2. Activate the plugin.
3. Go to **Users → User Management** and enable the modules you want.

== Frequently Asked Questions ==

= Does enabling a module change anything until I configure it? =
Modules ship with safe defaults. Email Verification is off by default so existing
sign-up flows are never blocked unexpectedly.

= Will uninstalling delete my data? =
Only if you tick "Delete all plugin data when the plugin is uninstalled" on the
General tab. Otherwise your settings and tracked data are preserved.

== Changelog ==

= 1.0.0 =
* Initial release: Registration tracking, Email verification, Multiple roles, and User switching modules.
