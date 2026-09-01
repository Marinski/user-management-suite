# Spec — Require email verification before first checkout, with a real reason + verify path at login

Status: Implemented
Owner: Algo Trading Space
Scope: `user-management-suite`, `wp-app-bridge`, WooCommerce checkout
Related incident: user `323882` (leejs101@gmail.com) paid for VIP Club – 3 Months (order `480589`) while their email was still unverified, then could not log in to the Account Tracker ("password is incorrect" despite a correct password).

---

## 1. Context

Investigation established the following facts about how things work today:

- **Email verification is login-only.** `user-management-suite` (UMS) marks every registered user `_ums_activation_status = 0` on `user_register` (`VerificationModule::on_register`) and emails a verify link. The block is enforced **only** on the `authenticate` filter (`VerificationModule::block_unverified`, priority 30). It never hooks checkout, order creation, or payment.
- **Anyone can therefore pay while unverified.** Orders complete because the payment gateway (Stripe) succeeds — verification is never consulted. When the tracker app later calls `wp_authenticate` through `wp-app-bridge`, the UMS block turns a correct password + paid customer into the generic `Invalid email or password.`
- **The bridge collapses all auth errors.** `wp-app-bridge` `AuthController::handle_authenticate` (and the legacy `ats-tracker-auth`) return `invalid_credentials` for *any* `WP_Error` from `wp_authenticate`, so the app can't tell "wrong password" from "email not verified."
- UMS already ships a **resend + verify UI**: the `[ums_resend_verification]` shortcode (works logged-out), the resend form handler (`maybe_resend`), the one-time verify link (`maybe_verify` → `?ums_verify=<key>&uid=<id>`, flips status to `1`, deletes the key, optional auto-login), and a post-verify redirect to a page (`redirect_page_id = 479672` "Email Verified").
- Settings relevant: `verification.require_email_verification = true`, `verification.exclude_roles = [administrator]`, `verification.auto_login_after_verify = true`, `recaptcha_on_login = false` (so the API auth path is not reCAPTCHA-blocked).
- WooCommerce: guest checkout is **disabled** (`woocommerce_enable_guest_checkout = no`); "create account during checkout" and "sign-up/login from checkout" are **enabled**. Every order is therefore placed by a WP user.
- Tracker authentication is fully owned by `wp-app-bridge` (`ats-tracker/v1/authenticate`, `/check-vip/{email}`). The legacy `ats-tracker-auth` plugin is inactive.

## 2. Goals

1. **Require a verified email before the first checkout (order placement).** Prevent new/returning unverified accounts from completing an order, so the "paid but can never log in" class of bug stops occurring.
2. **Return the real reason when login fails.** When the cause is an unverified email, the tracker app must be able to show "your email isn't verified yet" instead of "Invalid email or password."
3. **Provide a UI path to verify.** A user who hits the unverified wall can re-trigger the verification email and complete verification — both from the website (resend page) and, if desired, inline from the app (REST resend endpoint) — then log in.

## 3. Non-goals

- Rebuilding the UMS verification email/link flow itself (it works).
- Changing the VIP/tracker entitlement logic (already fixed separately).
- Building the tracker-side UI that renders the new error payload (out of this repo, but the payload contract is defined here so the app can consume it).
- Retroactive messaging on historical orders (optional backfill discussed in §8).

## 4. Design

### 4.1 Checkout gate — verified email required before order placement

Hook: `woocommerce_after_checkout_validation` (fires inside `WC_Checkout::validate_checkout()`, before account creation, order creation, and payment; receives `$data, WP_Error $errors`). Adding an error to `$errors` aborts placement and re-renders checkout with the notice.

Implementation home: `user-management-suite` `VerificationModule` (new method `block_unverified_checkout( $data, $errors )`, registered alongside `block_unverified`).

Rules:
- Bail when `require_email_verification` is off, or the logged-in user is in `exclude_roles` (reuse `is_excluded()`).
- **Logged-in buyer:** if `_ums_activation_status === '0'` → `$errors->add( 'ums_email_unverified_checkout', $message )`.
- **Not logged in + "create account during checkout"** → policy per Question 1 (recommended: also block with a register-then-verify message, since guest checkout is disabled anyway).
- Provide a filter `ums_require_verified_checkout` (default `true`) so free/$0 coupon orders or other flows can be exempted if desired (Question 2).
- The message text (translatable, filterable via `ums_unverified_checkout_message`) must include:
  - the real reason ("Your email address has not been verified yet"),
  - a link to the resend page (§4.3, renders `[ums_resend_verification]`),
  - a "check your spam folder" hint,
  - a support/help link.

### 4.2 Login error mapping — real reason in the API contract

Home: `wp-app-bridge/includes/Rest/AuthController.php::handle_authenticate`.

After `wp_authenticate()` returns a `WP_Error`, branch on `$error->get_error_code()`:

| Auth error code | HTTP | API `error` | Notes |
|---|---|---|---|
| `ums_unverified` | `403` | `email_not_verified` | New code; sets `verification_required: true` |
| anything else (e.g. `incorrect_password`) | `401` | `invalid_credentials` | current behavior unchanged |

For `email_not_verified`, the payload adds (alongside the standard `success:false`, `error`, `message`):
- `message` — human-readable, real reason + instructions (filterable via `wpab_email_not_verified_message`; default explains verification, mentions spam, and points to the resend page).
- `verification_required` — `true` (machine-readable flag so the app can branch).
- `resend_url` — URL of the UMS resend REST endpoint (§4.3).
- `verify_resend_ui_url` — web URL of the resend page (§4.3) for an out-of-app path.
- Keeps `incorrect_password`/unknown errors on the old `invalid_credentials` shape so existing behavior is preserved for the wrong-password case.

Rollout note: this adds a new error code the tracker app can choose to surface. Compatibility per Question 5.

### 4.3 UI path to verify

1. **REST resend endpoint (in-app + web reuse).** New `POST /wp-json/ums/v1/verification/resend` with `{ "email": "..." }`:
   - Reuses UMS `Mailer` + key rotation (refactor `maybe_resend` into a shared `resend( WP_User $user )`).
   - Rate limit: e.g. 3 per 15 min per IP + per email (transients). Return HTTP 429 when exceeded.
   - **Anti-enumeration:** always returns the same generic success (`{"success":true,"message":"If an account with that email needs verification, a new link has been sent."}`) whether or not the email exists / is unverified.
   - Guards: `require_email_verification` on; validate email; never leak status.
   - Registration host: a small `src/Rest/VerificationController.php` in UMS (mirrors `Modules/Notifications/RestController.php`), registered on `rest_api_init` from within `VerificationModule::register()`.
   - Existing form submit path (`maybe_resend`) keeps working; both call the shared resend.
2. **Frontend resend page.** A WP page (e.g. `/verify-email/`) rendering `[ums_resend_verification]` plus friendly copy and a support link. Works while logged out. This is the URL used in §4.1 and §4.2 payloads. The existing "Email Verified" page (`479672`) stays as the post-verify redirect landing, optionally adding a CTA ("Go to the Account Tracker" / "Log in").
3. **Surface the reason on the account page.** On My Account, show the `[ums_resend_verification]` shortcode and a notice to a logged-in, verified-needed user (the shortcode already auto-renders for logged-in unverified users — verify it renders on the dashboard and add a small heading/notice if needed).

## 5. Changes by file

| File | Change |
|---|---|
| `user-management-suite/src/Modules/Verification/VerificationModule.php` | Add `register_rest_route` hook; add `block_unverified_checkout`; extract shared `resend()`, have `maybe_resend` + REST controller call it; expose helper `is_verified( $user_id )`. |
| `user-management-suite/src/Rest/VerificationController.php` *(new)* | `POST /ums/v1/verification/resend` route, rate limiting, anti-enumeration response. |
| `wp-app-bridge/includes/Rest/AuthController.php` | Map `ums_unverified` → `403 email_not_verified` payload with `resend_url` + `verify_resend_ui_url`; keep old shape otherwise. |
| `user-management-suite/src/Settings/` *(optional)* | Add message strings as settings if we want them editable in admin rather than code/filter defaults. |
| WordPress content | Create `/verify-email/` page with `[ums_resend_verification]`; optionally add CTA + account-dashboard notice; optionally enhance "Email Verified" (479672). |

## 6. Incremental implementation steps & how to know each works

> No timing. Each step is independently verifiable; do not proceed until its check passes.

### Step 1 — Shared resend in UMS
Extract the resend logic and add `is_verified()` helper; keep form submit working.

**Verify:**
- `wp eval` on an unverified user: hot re-register a key via the helper, confirm `_ums_activation_key` changed and a `wp_mail` record exists (email log, or SMTP log), status is still `0`.
- Manually POST the old form path (or run `maybe_resend` with a mock `$_POST`) → same behavior, no regression.

### Step 2 — REST resend endpoint
Add `VerificationController` route; registered only when verification module enabled.

**Verify:**
- `curl -s -X POST <site>/wp-json/ums/v1/verification/resend -H 'Content-Type: application/json' -d '{"email":"<unverified@example.com>"}'` → `{"success":true,...}` and a verification email is sent (new key meta + mail log).
- Same call for a non-existent email → identical generic success (no 404, no "does not exist" difference).
- 4th rapid call from one IP → HTTP 429.

### Step 3 — Bridge login error mapping
Map `ums_unverified` → `403 email_not_verified` payload with `resend_url`, `verify_resend_ui_url`, `verification_required`.

**Verify (use a throwaway unverified test account):**
- `POST /wp-json/ats-tracker/v1/authenticate` with correct password on an **unverified** account → `403`, `error:"email_not_verified"`, `verification_required:true`, `message` mentions verification, `resend_url` + `verify_resend_ui_url` populated.
- Same endpoint with a **wrong** password → unchanged `401 invalid_credentials`.
- Same endpoint on a **verified** account with correct password → `200 success` (no regression).
- `check-vip/{email}` unaffected.
- Confirm the WP web login still shows the clear UMS message for unverified users (already does via `block_unverified`; assert no change).

### Step 4 — Checkout gate
Add `block_unverified_checkout` to `woocommerce_after_checkout_validation`.

**Verify (test unverified logged-in account with an item in cart):**
- Attempt checkout → order NOT created; checkout re-renders with the verification error notice containing the reason + resend link; no new `shop_order` row.
- Verify the same account's email → attempt checkout again → order created + completes normally.
- Confirmed verified account → checkout unaffected.
- Excluded role (admin) unverified → checkout NOT blocked.
- With `ums_require_verified_checkout` filter to `false` → unverified checkout is allowed.
- Create-account-at-checkout sub-flow behaves per Question 1 decision.

### Step 5 — Frontend verify page + account UI
Create `/verify-email/` page (shortcode + copy); optional account-dashboard notice/CTA; optional CTA on "Email Verified" page.

**Verify:**
- Visiting `/verify-email/` logged out renders the resend form; submitting resends and shows the "a new link has been sent" notice.
- Logged-in unverified user sees the notice/shortcode on My Account.
- Following the emailed verify link flips `_ums_activation_status` to `1`, deletes the key, auto-logs-in, redirects to the "Email Verified" page (479672).

### Step 6 — Optional backfill / self-heal sweep
One-off command (not a cron): for every `wc-completed` order with `_customer_user` set, if that user's `_ums_activation_status === '0'`, set to `1` (they demonstrably transacted). Log the count.

**Verify:**
- Dry-run counts affected users; run → `SELECT` confirms no remaining `0` among paying customers; spot-check one user's auth endpoint returns 200 with correct password.

### Step 7 — Re-run the full scenario end-to-end
With a fresh scratch account: register → unverified → try checkout (blocked, message + link) → resend via endpoint AND via page → click link → verified → checkout succeeds → tracker `authenticate` succeeds → `check-vip` true. Walk the whole loop once against live endpoints.

## 7. Security & behavioral notes

- Resend endpoint is anti-enumeration by construction (same response for all inputs) and rate-limited per IP + per email.
- Verify link remains a high-entropy one-time key, `hash_equals`-compared (unchanged).
- New `email_not_verified` error never includes the user's real email beyond what the client already sent, nor exposes whether an email has an account (enumeration) beyond the intended "you must verify" signal for the *authenticated* login attempt.
- The checkout gate respects `require_email_verification` and `exclude_roles` so staff/whitelisted roles are unaffected.
- Message strings are translatable + filterable so the site can localize without code edits.

## 8. Open decisions (drive the spec to final)

Decisions below change the concrete implementation; the plan is written against the recommended option for each. **Implementation resolutions (2026-08-31):**

1. **Create-account-at-checkout policy.** ⮕ **Chose (a) Strict block + auto-create.** Implemented in `block_unverified_checkout()`. Importantly, on this site guest checkout is disabled, so the `createaccount` checkbox is never posted — the gate therefore also blocks when `WC()->checkout()->is_registration_required()` is true (i.e. whenever a WP account will be created mid-checkout), closing the "paid while unverified" incident class for new buyers. True guest checkout (if ever enabled) is fail-closed on the billing email: an existing unverified account's email is still blocked. **Revision (2026-09-01):** a pure block was a dead-end — WooCommerce only creates the account after order placement, so blocked guests never got an account and never received the promised "check your email" message. The creates-account branch now calls `create_unverified_customer()`, which actually creates the customer up-front via `wc_create_new_customer()` (fires `user_register` → `on_register()` → verification email sent), then blocks the order until verified. The cart survives on the browser session, so the buyer verifies and completes.
2. **Free / $0 orders.** ⮕ Filter `ums_require_verified_checkout` (default `true` — uniform rule, no exemption). Can be switched off per checkout by a callback inspecting `$data`.
3. **Verify/resend UI target URLs.** ⮕ Reused the existing `confirm-your-email` page (holds `[ums_resend_verification]`). `ums_verification_resend_url()` (functions.php) resolves it, Polylang-aware, and it is surfaced as `verify_resend_ui_url` in API payloads plus the checkout/login messages. The REST endpoint enables an inline resend button if the app wants it.
4. **Rollout for the new error code.** ⮕ Bridge returns the new `email_not_verified` code (403) alongside the existing `invalid_credentials` (401) for wrong password. Tracker app should be updated to surface `email_not_verified`; wrong-password behaviour is unchanged.
5. **Backfill sweep (Step 6).** ⮕ **Not run.** Left as an optional one-off (`ums_*` status sweep for `wc-completed` customers). Recommend the site owner runs it (or auto-verify on completion) before rollout to unblock existing paying-but-unverified customers.
6. **Message strings.** ⮕ Filters, not DB settings: `ums_unverified_login_message`, `ums_unverified_checkout_message`, `ums_unverified_checkout_new_account_message`, `wpab_email_not_verified_message`, `ums_resend_page_url`.

## 9. References

- `user-management-suite/src/Modules/Verification/VerificationModule.php` — `on_register`, `block_unverified`, `maybe_verify`, `maybe_resend`, `resend_shortcode`, `is_excluded`.
- `user-management-suite/src/Modules/Verification/Mailer.php` — `send_verification`.
- `user-management-suite/src/Modules/Verification/Recaptcha.php` — `guards()` (login off).
- `wp-app-bridge/includes/Rest/AuthController.php` — `handle_authenticate` (error mapping), `handle_check`.
- `wp-app-bridge/includes/Entities/Presets.php` — tracker access rules (unchanged by this spec).
- WooCommerce `woocommerce/includes/class-wc-checkout.php` — `validate_checkout` → `do_action('woocommerce_after_checkout_validation', $data, $errors)` (line 1056); `process_checkout` ordering (lines 1348+).