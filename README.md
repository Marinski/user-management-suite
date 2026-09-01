# User Management Suite

A modular user-management toolkit for WordPress, in a single plugin:

| Module | What it does | Default |
| --- | --- | --- |
| **Registration & Activity Tracking** | Records last login time and (optional, anonymizable) IP; adds sortable Users-list columns and CSV export. | on |
| **Email Verification** | Requires email verification before login; spam/domain blocking; optional Google reCAPTCHA. | off |
| **Multiple Roles** | Assign multiple roles per user via a checklist on the user editor. | on |
| **User Switching** | Switch into any account you can edit, and switch back instantly. | on |
| **Import & Export** | Move users in and out as CSV. | on |
| **Acquisition Tracking** | Records where each user actually came from, captured on the landing page. | off |
| **Insights & Reports** | Growth, acquisition, activation, retention and email-health reports. | off |
| **Notification Preferences** | Per-user control over which emails the site sends, with one-click unsubscribe. | off |

Everything is configured from one tabbed settings page at **Users → User Management**.
Reports live at **Users → Insights**. Each module can be enabled or disabled
independently.

## Requirements

- WordPress 6.2+
- PHP 7.4+

## Getting reporting data

Reports read a rollup table, not the user table, so there is one setup step:

```bash
wp ums attribution backfill   # import existing WooCommerce order attribution (optional)
wp ums stats rebuild --all    # build the rollup from your full signup history
```

After that a nightly job keeps it current. `wp ums attribution status` and
`wp ums stats status` report on both.

## Design notes

A few decisions are load-bearing and worth knowing before changing anything.

**Acquisition is captured on the landing page, not at signup.** By the time
someone submits a registration form the referrer is one of your own pages, so
anything read at that moment records the last internal click. The touch is held
in a first-party cookie until registration. First touch is written once and
never overwritten — overwriting it would turn every returning visitor into a
fresh acquisition.

**Reports never query `wp_users` or `wp_usermeta` at request time.** Everything
goes through `ums_stats_daily`, rebuilt by a nightly job over a trailing window.
The aggregator slices by calendar month because a multi-year rebuild otherwise
holds every date/dimension/value combination in memory at once.

**The notification resolver fails open.** Unknown type, module disabled, no
identifiable recipient: send. A wrongly delivered newsletter is an annoyance; a
suppressed order confirmation is a support ticket.

**Required notifications are enforced in the resolver, not the UI.** A stale or
hand-edited preference row cannot switch off a receipt, `save()` refuses to
store a choice for one, and no gating filter is attached to it at all.

**Unsubscribe never acts on GET.** Mail clients and security scanners prefetch
links, so GET shows a confirmation and only POST acts — either the confirmation
form, or a mailbox provider's RFC 8058 `List-Unsubscribe=One-Click` body.

## Extending

| Hook | Purpose |
| --- | --- |
| `ums_notification_types` | Declare notification types so they appear in preferences. |
| `ums_notification_allowed` | Final say on whether one notification may be sent. |
| `ums_notification_woocommerce_optional` | Which WooCommerce emails a user may switch off. |
| `ums_attribution_channel` | Site-specific source → channel rules. |
| `ums_attribution_has_consent` | Gate acquisition tracking behind a consent platform. |
| `ums_attribution_payload` | Supply attribution from a custom signup flow. |
| `ums_insights_user_converted` | Define what "converted" means for the activation funnel. |
| `ums_insights_tabs` | Add a report tab. |
| `ums_require_verified_checkout` | Opt a checkout out of the verified-email gate (default `true`). Receives the posted checkout data. |
| `ums_unverified_checkout_message` | Message shown to a logged-in unverified buyer at checkout. |
| `ums_unverified_checkout_new_account_message` | Message shown when a checkout would create a new (unverified) account. |
| `ums_unverified_login_message` | Message an unverified user sees at login (already existed, now also reused by the API path). |
| `ums_resend_page_url` | Override the page the resend/verify links point to. |
| `ums_email_verified` | Fired with the user id once their email is verified. |

`ums_notification_allowed( $type_id, $user_id )` is available as a plain
function even when the module is switched off, so adding the check to existing
code can never stop mail that used to go out.

### Verified-email checkout gate

When `verification.require_email_verification` is on, an account cannot place a
WooCommerce order before verifying its email. The gate runs on
`woocommerce_after_checkout_validation`, so it applies to the classic
`[woocommerce_checkout]` checkout (not the Cart & Checkout blocks). Logged-out
visitors are blocked too whenever the checkout will create a WP account
(guest-checkout-disabled sites), and true guest checkout is fail-closed when the
billing email belongs to an existing unverified account.

For a first-time (logged-out) buyer the gate does not just block: because
WooCommerce only creates the customer account *after* order placement, a blocked
checkout would otherwise leave the buyer with no account and no verification
email. Instead it creates the customer up-front via `wc_create_new_customer()`
— which fires `user_register` → `on_register()`, marking the account unverified
and sending the verification link — and *then* blocks the order until that link
is confirmed. The cart is preserved on the same browser session, so the buyer
verifies (auto-login) and completes the checkout. If the billing email already
belongs to an account, no duplicate is created and the existing unverified
account is blocked until verified.

### Resend verification over REST

`POST /wp-json/ums/v1/verification/resend` with `{ "email": "..." }` re-sends the
verification email to an unverified account. Always returns the same generic
success (no account enumeration), rate-limited per (client, email) and per
client. `ums_verification_resend_url()` returns the public resend page URL.

## Development

```bash
composer install      # install dev tooling (PHPCS + WordPress Coding Standards)
composer phpcs        # lint
composer phpcbf       # auto-fix
```

The plugin uses a small PSR-4-style autoloader (`Marinski\UserManagementSuite\` →
`src/`). No runtime Composer dependencies are bundled.

## Architecture

- `user-management-suite.php` — bootstrap: header, constants, autoloader, lifecycle hooks.
- `src/Plugin.php` — container that loads settings, admin, and enabled modules.
- `src/Settings/` — the single `ums_settings` option, the tabbed settings page, and reusable field renderers.
- `src/Modules/<Name>/` — one self-contained, toggleable module per feature area.
- `src/Support/` — shared helpers (security, IP handling, privacy tools, custom table schema).
- `src/Cli/` — WP-CLI commands for the jobs that run over every user.

Integrations with other plugins live in adapter files that no-op when the
dependency is absent (`src/Modules/Notifications/Adapters/`). Core stays generic.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
