# Spec: Consolidate registration anti-spam into User Management Suite

Status: Implemented (2026-09-01)
Author: Marinski
Date: 2026-09-01
Related: `spec-require-email-verification-before-checkout.md` (same module, previously implemented)

## Background

`wp-content/mu-plugins/ats-anti-spam-registration.php` (v0.1.0, added 2026-08-27) blocks
casino/betting/spam-keyword registrations, disposable-email domains, and blacklisted IPs on
`platform.algotradingspace.com`, and records each registrant's real (Cloudflare-aware) IP. It is
self-contained: no other theme/plugin calls `ats_reg_*()` or reads `ats_blocked_ips`/`ats_reg_ip`
(full-text scan confirmed; the only other matches are unrelated — `ats_registry` in PostSMTP and
`registration` wording in the Astra child `form-login.php`).

All registration happens on the platform (WordPress) anyway, and UMS already owns registration spam
protection (`VerificationModule` + `Spam::validate()`), verification, and registration-source/IP
tracking (`RegistrationModule`). The end goal is **fewer plugins**: absorb the mu-plugin's rules into
UMS so the mu-plugin can be deleted.

### Incidents that frame this work
- 2026-08-31: a customer (Tobias) could not register or check out; no verification email arrived. Root
  cause was compounded of two bugs, one of which lives in this mu-plugin: its `woocommerce_register_post`
  closure used the `registration_errors` argument order `($errors, $username, $email)`, but WooCommerce
  fires that hook as an action with `(username, email, errors)` — so on PHP 8 the `(string) $WP_Error`
  cast threw a fatal and killed every WooCommerce-path registration. That closure was fixed in place
  (arg order + `is_wp_error` guard). **This spec must preserve that fix by moving the logic to the
  correct WooCommerce hook** (`woocommerce_registration_errors`, where the return value is actually used),
  or by not touching the WooCommerce action at all and gating on the filter.

## Goals
- Single source of truth for registration anti-spam inside UMS (settings-driven like the rest of UMS).
- Feature parity with the mu-plugin: keyword blocking, disposable-domain blocking, IP blocking, and
  registration-IP recording.
- Coverage for **both** registration surfaces:
  - wp-login / custom register forms → `registration_errors` (already wired).
  - WooCommerce (checkout account-creation + My Account register) → `woocommerce_registration_errors`
    (currently **not** wired — a gap that let the mu-plugin's inert `woocommerce_register_post` closure
    become a fatal).
- Delete the mu-plugin once parity is verified.
- Keep UMS conventions: settings in the Verification tab, message strings as filters, phpcs clean.

## Non-goals
- Removing/spoofing CleanTalk. CleanTalk is a paid bot-protection service with its own WooCommerce
  integration; it stays. This spec only ensures UMS and CleanTalk coexist (both hook the same filters;
  first-added error wins on `registration_errors` flow, CleanTalk at pri 1, UMS at pri 10).
- Reworking the existing blocked/allowed-domain or username behavior.
- Migrating the ~200 existing `ats_reg_ip` usermeta values (low value; left untouched).

## Current-state parity matrix

| Capability | ATS mu-plugin | UMS `Spam` today | Gap |
|---|---|---|---|
| Block casino/spam keywords in username/email | `ats_reg_bad_keywords()` (username + whole email) | none | Add |
| Block disposable mail domains | `ats_reg_bad_domains()` (exact match list) | `blocked_domains` (exact + `.suffix` via `domain_matches`) — already includes most | Merge |
| Block by IP | `ats_blocked_ips` option (exact match) | none | Add |
| Record registrant IP | `ats_reg_ip` (CF-aware, unconditional on `user_register`) | `_ums_last_login_ip` on **login** only; `Security::client_ip()` reads `REMOTE_ADDR` | Add registrant IP at signup, CF-aware |
| WooCommerce-path gating | intended via `woocommerce_register_post` (inert/crashing) | none | Add `woocommerce_registration_errors` hook |
| Error messages | hardcoded (vague) | `ums_*` filters | Extend filters |

The uncommitted `Spam.php` `domain_matches()` suffix helper (enables `.xyz`, `.click` TLD entries) is
**required** by this work and should be committed as part of it.

## Design

### Where logic lands
- All rule evaluation stays in `src/Modules/Verification/Spam.php`. Extend `Spam::validate()` with:
  - keyword scan (`blocked_keywords`, matched against username and email local-part — see Q2),
  - disposable/extra domain list (merged into `blocked_domains` or a new field — see Q1),
  - IP check (`blocked_ips`, resolved IP passed in), 
  - optional per-rule generic-vs-specific message filters.
- `VerificationModule::register()` adds:
  - `add_filter( 'woocommerce_registration_errors', array( $this, 'registration_errors' ), 10, 3 );`
    — WooCommerce calls this with `($errors, $username, $email)`, which matches the existing
    `registration_errors( $errors, $user_login, $user_email )` signature. If desired, wrap it so the
    WooCommerce surface is distinguishable (Q5) and, optionally, reCAPTCHA can be toggled for it.
- Registration-IP recording lives in `RegistrationModule` (data owner):
  - a `user_register` handler storing `_ums_registration_ip` (respecting `anonymize_ip`), gated by a
    setting (Q4). The existing `_ums_last_login_ip` login behavior is untouched.
- Client IP resolution: keep using `Security::client_ip()` (single surface) and make it
  Cloudflare-aware behind a durable opt-in filter `ums_client_ip_trust_proxy` (default value per Q4),
  so the Spam IP block and the Registration IP record agree with what the mu-plugin captured.

### Settings additions (Verification settings tab; mirror `render_settings_tab` / `sanitize_settings`)
- `blocked_keywords` (textarea, one per line), seeded with the mu-plugin's keyword list.
- `blocked_ips` (textarea, one per line), migrated from the `ats_blocked_ips` option once.
- (Per Q1) either merge ATS disposable domains into `blocked_domains` or add `blocked_disposable_domains`.
- (Per Q4) `track_registration_ip` toggle.

### Upgrade/migration
- On version bump to 1.2.0, a one-time routine reads `ats_blocked_ips` → writes `blocked_ips` setting
  inside `ums_settings`, and merges the ATS disposable-domain list into the chosen field. Follow the
  existing plugin-version-upgrade pattern (see `Activator` / schema-version handling); keep it idempotent.
- Do not delete `ats_blocked_ips` or existing `ats_reg_ip` metas (harmless leftovers; removed only if
  the owner wants a cleanup).

### Hooks/filters API (documents in README)
- `ums_registration_keyword_message`, `ums_registration_ip_message`, `ums_registration_disposable_message`
  (default messaging: keyword/IP stay vague, domain specific — see Q3).
- Existing `ums_*` filters unchanged.

## Incremental steps and verification

### Step 1 — Parity audit artifact
Capture the current ATS rule lists (keywords, disposable domains, IPs) and the UMS settings snapshot into
the doc so nothing is lost during the move.

**Working =** a committed markdown table (or code comment block) enumerating every ATS rule and its new
home in UMS; count of keywords/domains matches the mu-plugin exactly.

### Step 2 — Extend `Spam::validate()` (no hooks yet)
Add keyword / domain-merge / IP rules to `Spam`; new settings-backed rule reads; message filters.
Commit the pre-existing `Spam.php` `domain_matches()` diff as part of this step.

**Working =** `php -l` + `php vendor/bin/phpcs src/Modules/Verification/Spam.php` exit 0; a `wp eval-file`
harness asserting each rule (blocked keyword, blocked domain, `.suffix` TLD, blocked IP, allowed IP,
legit user) returns the expected `ums_*` error codes and no error for the legit case. Clean up harness users.

### Step 3 — Wire the WooCommerce registration surface
Add the `woocommerce_registration_errors` hook calling the same `registration_errors()` path.

**Working =** `wp eval-file` performs a blocked-domain and a legit-email `wc_create_new_customer()` and
confirms the blocked one returns the `ums_*` error (no user created) while the legit one creates the user
(later deleted). Re-run the same harness that failed pre-fix to confirm the PHP-8-`(string) $WP_Error`
fatal is gone and CleanTalk (pri 1 / 999999) + UMS (pri 10) coexist without error.

### Step 4 — Registration-IP recording + CF-aware client IP
Add `_ums_registration_ip` meta at signup in `RegistrationModule`, honoring `anonymize_ip` and a new
`track_registration_ip` toggle; make `Security::client_ip()` trust `CF-Connecting-IP` behind the
`ums_client_ip_trust_proxy` filter.

**Working =** registering a test user writes `_ums_registration_ip` matching the harness-supplied IP;
with `anonymize_ip` on the stored value is anonymized; `ums_client_ip_trust_proxy` off keeps old
`REMOTE_ADDR` behavior. phpcs clean.

### Step 5 — Settings UI + sanitize + migration
Add `blocked_keywords`, `blocked_ips` (and the chosen disposable/merge + `track_registration_ip`) fields
to the Verification tab and `sanitize_settings`; implement the idempotent one-time import of
`ats_blocked_ips` and the disposable-domain merge on version bump.

**Working =** tab renders the new fields; saving persists them (wp eval against settings repo shows
`ums_settings.verification.blocked_keywords` / `blocked_ips` populated); simulation of a fresh upgrade
state imports `ats_blocked_ips` exactly once (re-running leaves no duplicates). phpcs clean.

### Step 6 — Docs
README: new settings, filters, WooCommerce coverage note (incl. the inert/`woocommerce_register_post`
fix rationale), and the mu-plugin departure note. This spec moves to Status: Implemented at the end.

**Working =** README + spec updated; no stale references to the mu-plugin APIs remain in docs.

### Step 7 — Deploy order and mu-plugin removal
1. Commit UMS 1.2.0 (version bump + changelog entry) and deploy.
2. Delete `wp-content/mu-plugins/ats-anti-spam-registration.php`.
3. Grep `wp-content` for any residual `ats_reg_*`, `ats_blocked_ips`, `ats-anti-spam` references.

**Working =** after deploy, live smoke test on **both** surfaces: (a) wp-login/custom register with a
blocked keyword → rejected with the UMS message; (b) a real (non-blocked) brand-new registration through
checkout creates the account, sends the verification email, and the order is gated as specified in the
prior spec; (c) `wc-memberships`/VIP flows unaffected. No PHP warnings after mu-plugin deletion; grep
clean. A deliberately blocked IP returns the blocked message on both surfaces.

## Risks and edge cases
- **Keyword false positives:** ATS scans the full email string; moving to username + email local-part
  (Q2) reduces false blocks (e.g. generic words in the domain half) — confirm list quality via Q2.
- **Proxy-header spoofing:** trusting `CF-Connecting-IP` must be filter-gated; UMS's existing
  `Security::client_ip()` deliberately avoids spoofable headers (Q4).
- **CleanTalk interplay:** both services may independently block; ensure UMS error surfaces before a
  CleanTalk fallthrough and that CleanTalk's WooCommerce integration doesn't double-append confusing text.
- **Checkout UX:** blocking WooCommerce registrations with the vague keyword/IP message could confuse a
  legit buyer on the checkout path; per Q3 decide specific-vs-vague per rule.
- **WooCommerce-only surfaces:** the Cart & Checkout blocks / Store API path also calls
  `wc_create_new_customer()` on order placement, so `woocommerce_registration_errors` covers it — no
  extra guard needed (unlike the checkout *gate*, which only covers the shortcode).

## Files to touch
- `src/Modules/Verification/Spam.php` — new rules, message filters.
- `src/Modules/Verification/VerificationModule.php` — `woocommerce_registration_errors` hook (+ optional
  per-surface wrapper/reCAPTCHA toggle).
- `src/Modules/Registration/RegistrationModule.php` — registrant IP meta at signup.
- `src/User/...` or `src/Support/Security.php` — CF-aware `client_ip` behind filter.
- `src/Modules/Verification/VerificationModule.php` — settings tab + sanitize (new fields).
- Upgrade routine (version 1.2.0) — one-time `ats_blocked_ips` import + disposable merge.
- `README.md`, `readme.txt` (changelog `= 1.2.0 =`), `user-management-suite.php` (version).
- Deployment op: delete `wp-content/mu-plugins/ats-anti-spam-registration.php`.

## Decisions (resolved 2026-09-01)

1. Disposable domains **merge into `blocked_domains`** (one suffix-aware list).
2. Keyword scan matches **username + email local-part** (before `@`), not the whole email.
   Short tokens (< 5 chars, e.g. `bet`, `vip`, `neha`) are ambiguous substrings, so they
   match a *whole* username or whole local part only; tokens of 5+ chars match anywhere.
3. **Vague** generic message for keyword/IP blocks; domains/usernames keep specific messages.
4. Registrant-IP recording **on** behind `track_registration_ip` (default true, respects `anonymize_ip`);
   `Security::client_ip()` trusts `CF-Connecting-IP` behind `ums_client_ip_trust_proxy` (default true).
5. **reCAPTCHA stays wp-login-only**; WooCommerce path gets keyword/domain/IP rules only.
6. Bump to **1.2.0** on the current branch, with a changelog entry.

## Questions

1. **Disposable-domain field.** Merge the ATS disposable-domain list into the existing `blocked_domains`
   field (one list, suffix-aware), or keep a separate "blocked disposable domains" field so operators can
   tell the curated disposable list from site-blocked domains? (Recommended: merge into `blocked_domains`.)
2. **Keyword scanning surface.** Scan the keyword list against username + email *local-part* (before `@`),
   or against username + the whole email string like the mu-plugin does? (Recommended: local-part only,
   to avoid over-blocking legit addresses whose domain half contains a keyword.)
3. **Message wording.** Keep the mu-plugin's vague generic message for keyword/IP blocks
   ("Your account could not be created."), and specifics only for domains — matching UMS's current
   domain/username messages? Or specific messages per rule type? (Recommended: keep keyword/IP vague,
   domains/usernames specific.)
4. **Registrant-IP recording + proxy trust.** Gate `_ums_registration_ip` behind a new
   `track_registration_ip` toggle (default on), and enable Cloudflare-header trust via
   `ums_client_ip_trust_proxy` (default on, since the site is CF-fronted)? Confirm that is acceptable.
5. **WooCommerce reCAPTCHA.** Should the existing reCAPTCHA guards, currently wp-login-only, also apply to
   the WooCommerce registration surface (`woocommerce_registration_errors`)? (Recommended: yes, same
   toggle as `recaptcha_on_register`, so checkout/My-Account registrations are equally protected — or keep
   reCAPTCHA off on checkout to avoid friction for buyers.)
6. **Version.** Bump to 1.2.0 for this change, with a changelog entry? Any release-branch preference
   beyond the current `feature/growth-analytics-and-notification-preferences`?