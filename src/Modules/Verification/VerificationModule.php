<?php
/**
 * Email verification module.
 *
 * @package UserManagementSuite
 */

namespace Marinski\UserManagementSuite\Modules\Verification;

use Marinski\UserManagementSuite\Modules\AbstractModule;
use Marinski\UserManagementSuite\Settings\ProvidesSettings;
use Marinski\UserManagementSuite\Settings\SettingsRepository;
use Marinski\UserManagementSuite\Settings\Fields;
use Marinski\UserManagementSuite\Support\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Requires new users to confirm their email before logging in; adds spam
 * protection, optional reCAPTCHA, status column/filter/bulk actions, and an
 * optional auto-delete of long-unverified accounts.
 */
class VerificationModule extends AbstractModule implements ProvidesSettings {

	const META_KEY    = '_ums_activation_key';
	const META_STATUS = '_ums_activation_status';

	const CRON_HOOK    = 'ums_auto_delete_unverified';
	const NONCE_RESEND = 'ums_resend';

	/**
	 * Google reCAPTCHA helper instance.
	 *
	 * @var Recaptcha
	 */
	private $recaptcha;

	/**
	 * Spam helper.
	 *
	 * @var Spam
	 */
	private $spam;

	/**
	 * Mailer.
	 *
	 * @var Mailer
	 */
	private $mailer;

	/**
	 * Constructor.
	 *
	 * @param SettingsRepository $settings Settings repository.
	 */
	public function __construct( SettingsRepository $settings ) {
		parent::__construct( $settings );
		$this->recaptcha = new Recaptcha( $settings );
		$this->spam      = new Spam( $settings );
		$this->mailer    = new Mailer( $settings );
	}

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'verification';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return __( 'Email Verification', 'user-management-suite' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function register() {
		// Verification lifecycle.
		add_action( 'user_register', array( $this, 'on_register' ) );
		add_filter( 'authenticate', array( $this, 'block_unverified' ), 30 );
		add_action( 'init', array( $this, 'maybe_verify' ) );
		add_action( 'init', array( $this, 'maybe_resend' ) );
		add_filter( 'login_message', array( $this, 'login_notice' ) );
		add_shortcode( 'ums_resend_verification', array( $this, 'resend_shortcode' ) );

		// Require a verified email before an account can place its first order.
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'block_unverified_checkout' ), 20, 2 );

		// Resend verification over REST for decoupled front ends (tracker app, etc.).
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		// Surface the reason + resend form on the My Account dashboard.
		add_action( 'woocommerce_account_dashboard', array( $this, 'account_verification_notice' ), 5 );

		// Spam + reCAPTCHA on the registration/login/lost-password forms.
		add_filter( 'registration_errors', array( $this, 'registration_errors' ), 10, 3 );
		// WooCommerce registers through wc_create_new_customer(), which does not
		// run the core `registration_errors` filter. Gate that surface with the
		// same spam rules (verified email + checkout gate already cover the order;
		// reCAPTCHA stays wp-login-only so checkout buyers are not challenged).
		add_filter( 'woocommerce_registration_errors', array( $this, 'woocommerce_registration_errors' ), 10, 3 );
		add_filter( 'wp_authenticate_user', array( $this, 'login_recaptcha' ), 10 );
		add_action( 'lostpassword_post', array( $this, 'lostpassword_recaptcha' ) );

		add_action( 'login_enqueue_scripts', array( $this->recaptcha, 'enqueue' ) );
		add_action( 'register_form', array( $this, 'render_recaptcha_register' ) );
		add_action( 'login_form', array( $this, 'render_recaptcha_login' ) );
		add_action( 'lostpassword_form', array( $this, 'render_recaptcha_lostpassword' ) );

		// Users list integration.
		add_filter( 'manage_users_columns', array( $this, 'add_column' ) );
		add_filter( 'manage_users_custom_column', array( $this, 'render_column' ), 10, 3 );
		add_action( 'restrict_manage_users', array( $this, 'status_filter_ui' ) );
		add_action( 'pre_get_users', array( $this, 'filter_by_status' ) );
		add_filter( 'bulk_actions-users', array( $this, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-users', array( $this, 'handle_bulk' ), 10, 3 );
		add_action( 'admin_notices', array( $this, 'bulk_notice' ) );

		// Auto-delete cron.
		add_action( self::CRON_HOOK, array( $this, 'run_auto_delete' ) );
		$this->maybe_schedule_cron();
	}

	// ---------------------------------------------------------------------
	// Lifecycle.
	// ---------------------------------------------------------------------

	/**
	 * Whether a user's roles exempt them from verification.
	 *
	 * @param \WP_User $user User.
	 * @return bool
	 */
	private function is_excluded( $user ) {
		$exclude = (array) $this->settings->get( 'verification', 'exclude_roles', array( 'administrator' ) );

		return (bool) array_intersect( (array) $user->roles, $exclude );
	}

	/**
	 * On registration: mark unverified and send the verification email.
	 *
	 * @param int $user_id New user id.
	 * @return void
	 */
	public function on_register( $user_id ) {
		if ( ! $this->settings->get( 'verification', 'require_email_verification', true ) ) {
			return;
		}

		$user = get_userdata( $user_id );
		if ( ! $user || $this->is_excluded( $user ) ) {
			return;
		}

		$key = wp_generate_password( 32, false );
		update_user_meta( $user_id, self::META_KEY, $key );
		update_user_meta( $user_id, self::META_STATUS, '0' );

		$this->mailer->send_verification( $user, $key );
	}

	/**
	 * Block unverified users from authenticating.
	 *
	 * @param \WP_User|\WP_Error|null $user Auth result so far.
	 * @return \WP_User|\WP_Error|null
	 */
	public function block_unverified( $user ) {
		if ( ! $this->settings->get( 'verification', 'require_email_verification', true ) ) {
			return $user;
		}

		if ( ! $user instanceof \WP_User ) {
			return $user;
		}

		if ( $this->is_excluded( $user ) ) {
			return $user;
		}

		$status = get_user_meta( $user->ID, self::META_STATUS, true );
		if ( '0' === (string) $status ) {
			$message = __( '<strong>Error</strong>: Your email address has not been verified yet. Please check your inbox for the verification link.', 'user-management-suite' );

			/**
			 * Filters the error shown when an unverified user tries to log in.
			 *
			 * Lets a site point the user at a resend-verification form instead of
			 * leaving them at a dead end.
			 *
			 * @param string   $message Error message (may contain limited HTML).
			 * @param \WP_User $user    The user being blocked.
			 */
			$message = apply_filters( 'ums_unverified_login_message', $message, $user );

			return new \WP_Error( 'ums_unverified', $message );
		}

		return $user;
	}

	/**
	 * Whether a user's email is verified.
	 *
	 * A lenient check: only an explicit '0' means unverified — an account with
	 * no status meta at all (created before verification was enforced, or whose
	 * key was stripped) is treated as verified rather than locked out.
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	public function is_verified( $user_id ) {
		return '0' !== (string) get_user_meta( (int) $user_id, self::META_STATUS, true );
	}

	/**
	 * (Re)issue a verification key and email it to a user.
	 *
	 * Shared by the front-end resend form ({@see maybe_resend()}) and the REST
	 * resend endpoint. Rotating the key invalidates any older, already-sent link.
	 *
	 * @param \WP_User $user User to verify.
	 * @return bool Whether the mail was accepted by the transport.
	 */
	public function resend( \WP_User $user ) {
		if ( ! $this->settings->get( 'verification', 'require_email_verification', true ) ) {
			return false;
		}

		$key = wp_generate_password( 32, false );
		update_user_meta( $user->ID, self::META_KEY, $key );

		return $this->mailer->send_verification( $user, $key );
	}

	/**
	 * Handle a verification link click.
	 *
	 * @return void
	 */
	public function maybe_verify() {
		// Email verification links cannot use nonces — they are session-independent one-time tokens.
		// Security is provided by hash_equals() against the stored random key below.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['ums_verify'] ) || empty( $_GET['uid'] ) ) {
			return;
		}

		$uid = absint( $_GET['uid'] );
		$key = sanitize_text_field( wp_unslash( $_GET['ums_verify'] ) );
		// phpcs:enable

		$stored = (string) get_user_meta( $uid, self::META_KEY, true );

		if ( '' === $stored || ! hash_equals( $stored, $key ) ) {
			// The key is spent. If this account is already verified the link was
			// simply followed twice — by the user, or by a mail client or security
			// scanner that prefetched it. Treat that as success rather than telling
			// a verified user their link is invalid.
			if ( '1' !== (string) get_user_meta( $uid, self::META_STATUS, true ) ) {
				wp_safe_redirect( add_query_arg( 'ums_verified', 'invalid', wp_login_url() ) );
				exit;
			}
		} else {
			update_user_meta( $uid, self::META_STATUS, '1' );
			delete_user_meta( $uid, self::META_KEY );

			do_action( 'ums_email_verified', $uid );
		}

		$auto_login = (bool) $this->settings->get( 'verification', 'auto_login_after_verify', true );

		/**
		 * Filters whether verifying should also log the user in.
		 *
		 * A verification link that logs you in is a login link. Sites that mail
		 * these links in bulk, or store them outside WordPress, will want to turn
		 * this off for those links specifically.
		 *
		 * @param bool $auto_login Whether to log the user in.
		 * @param int  $uid        Verified user id.
		 */
		$auto_login = (bool) apply_filters( 'ums_auto_login_after_verify', $auto_login, $uid );

		if ( $auto_login && ! is_user_logged_in() ) {
			wp_set_current_user( $uid );
			wp_set_auth_cookie( $uid );
		}

		wp_safe_redirect( $this->verified_redirect( $uid ) );
		exit;
	}

	/**
	 * Where to send a user once their email is verified.
	 *
	 * Falls back to the login screen only when the user is not logged in —
	 * showing a password prompt to someone who was just logged in reads as a
	 * failure, which is the opposite of what happened.
	 *
	 * @param int $uid Verified user id.
	 * @return string
	 */
	private function verified_redirect( $uid ) {
		$redirect_id = (int) $this->settings->get( 'verification', 'redirect_page_id', 0 );
		$redirect    = $redirect_id ? get_permalink( $redirect_id ) : '';

		if ( ! $redirect ) {
			$redirect = is_user_logged_in()
				? home_url( '/' )
				: add_query_arg( 'ums_verified', 'success', wp_login_url() );
		}

		/**
		 * Filters where a user lands after verifying their email address.
		 *
		 * @param string $redirect Destination URL.
		 * @param int    $uid      Verified user id.
		 */
		$redirect = (string) apply_filters( 'ums_verified_redirect', $redirect, $uid );

		return $redirect ? $redirect : home_url( '/' );
	}

	/**
	 * Show the outcome of a verification or resend attempt on the login screen.
	 *
	 * Without this the `ums_verified` flag is set on the redirect and then never
	 * rendered, so the user is returned to a bare login form with no indication
	 * that anything happened.
	 *
	 * @param string $message Existing login message markup.
	 * @return string
	 */
	public function login_notice( $message ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display-only flags on a redirect.
		$verified = isset( $_GET['ums_verified'] ) ? sanitize_key( wp_unslash( $_GET['ums_verified'] ) ) : '';
		$resent   = ! empty( $_GET['ums_resent'] );
		// phpcs:enable

		// WordPress styles every `.message` identically, so success and failure
		// read the same. The extra class lets a theme tell them apart.
		if ( 'success' === $verified ) {
			$message .= '<p class="message ums-message-success">' . esc_html__( 'Your email address is verified. You can now log in.', 'user-management-suite' ) . '</p>';
		} elseif ( 'invalid' === $verified ) {
			$message .= '<p class="message ums-message-warning">' . esc_html__( 'That verification link is no longer valid. Request a new one below, or reset your password if you already have an account.', 'user-management-suite' ) . '</p>';
		}

		if ( $resent ) {
			$message .= '<p class="message ums-message-info">' . esc_html__( 'If an account with that email needs verification, a new link has been sent.', 'user-management-suite' ) . '</p>';
		}

		return $message;
	}

	/**
	 * Handle a resend-verification form submission.
	 *
	 * @return void
	 */
	public function maybe_resend() {
		if ( empty( $_POST['ums_resend_email'] ) ) {
			return;
		}

		if ( ! isset( $_POST[ self::NONCE_RESEND ] )
			|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_RESEND ] ) ), self::NONCE_RESEND ) ) {
			return;
		}

		$email = sanitize_email( wp_unslash( $_POST['ums_resend_email'] ) );
		$user  = $email ? get_user_by( 'email', $email ) : false;

		// Always behave the same to avoid disclosing which emails exist.
		if ( $user && '0' === (string) get_user_meta( $user->ID, self::META_STATUS, true ) ) {
			$this->resend( $user );
		}

		wp_safe_redirect( add_query_arg( 'ums_resent', '1', wp_get_referer() ? wp_get_referer() : home_url( '/' ) ) );
		exit;
	}

	/**
	 * Render the resend form via shortcode.
	 *
	 * @return string
	 */
	public function resend_shortcode() {
		// Render nothing for anyone who has nothing to resend. The shortcode
		// commonly sits on the account page, and WooCommerce reuses that page
		// for every account endpoint — so an unconditional form followed a
		// verified, logged-in member around the whole dashboard.
		if ( is_user_logged_in() ) {
			$user = wp_get_current_user();

			if ( $this->is_excluded( $user )
				|| '0' !== (string) get_user_meta( $user->ID, self::META_STATUS, true ) ) {
				return '';
			}
		}

		ob_start();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag.
		if ( isset( $_GET['ums_resent'] ) ) {
			echo '<p class="ums-notice">' . esc_html__( 'If an account with that email needs verification, a new link has been sent.', 'user-management-suite' ) . '</p>';
		}
		?>
		<div class="ums-resend">
			<h3 class="ums-resend__title"><?php esc_html_e( 'Confirm your email address', 'user-management-suite' ); ?></h3>
			<p class="ums-resend__intro"><?php esc_html_e( 'Your account still needs email confirmation. If the link never arrived or has expired, request a new one below.', 'user-management-suite' ); ?></p>
		<form method="post" class="ums-resend-form">
			<?php wp_nonce_field( self::NONCE_RESEND, self::NONCE_RESEND ); ?>
			<p>
				<label for="ums_resend_email"><?php esc_html_e( 'Your email address', 'user-management-suite' ); ?></label><br />
				<input type="email" id="ums_resend_email" name="ums_resend_email" required />
			</p>
			<p><button type="submit"><?php esc_html_e( 'Resend verification email', 'user-management-suite' ); ?></button></p>
		</form>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	// ---------------------------------------------------------------------
	// Resend via REST.
	// ---------------------------------------------------------------------

	/**
	 * Register the resend REST route.
	 *
	 * Consumed by decoupled front ends (e.g. the Account Tracker app) so a user
	 * who hits the "email not verified" wall can re-trigger the email without
	 * leaving the app. Public by design (no logged-in user exists yet).
	 *
	 * @return void
	 */
	public function register_rest_routes() {
		register_rest_route(
			'ums/v1',
			'/verification/resend',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_resend' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'email' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_email',
					),
				),
			)
		);
	}

	/**
	 * POST /ums/v1/verification/resend — (re)send the verification email.
	 *
	 * Response is identical whether or not the email belongs to a verified,
	 * unverified, or non-existent account, so callers cannot enumerate which
	 * addresses hold accounts.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_resend( \WP_REST_Request $request ) {
		if ( ! $this->settings->get( 'verification', 'require_email_verification', true ) ) {
			return new \WP_Error( 'ums_verification_disabled', __( 'Email verification is not required on this site.', 'user-management-suite' ), array( 'status' => 400 ) );
		}

		$email = sanitize_email( (string) $request->get_param( 'email' ) );
		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'ums_invalid_email', __( 'Please provide a valid email address.', 'user-management-suite' ), array( 'status' => 400 ) );
		}

		if ( ! $this->resend_rate_ok( $email ) ) {
			return new \WP_Error( 'ums_resend_rate_limited', __( 'Too many resend requests. Please try again in a few minutes.', 'user-management-suite' ), array( 'status' => 429 ) );
		}

		$user = get_user_by( 'email', $email );
		$sent = false;
		if ( $user && '0' === (string) get_user_meta( $user->ID, self::META_STATUS, true ) ) {
			$sent = (bool) $this->resend( $user );
		}

		// Response is identical whatever the account state. The only signal a
		// caller could otherwise use is timing — a real send round-trips through
		// the mail transport while the no-op branches return instantly. Blunt
		// that side channel with a jittered stall when no mail is dispatched.
		if ( ! $sent ) {
			usleep( wp_rand( 80000, 250000 ) );
		}

		return new \WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'If an account with that email needs verification, a new link has been sent.', 'user-management-suite' ),
			),
			200
		);
	}

	/**
	 * Best-effort real client IP (Cloudflare-aware).
	 *
	 * This site is fronted by Cloudflare, so REMOTE_ADDR is usually the edge
	 * pool, not the visitor. The connecting-IP header is the authoritative value
	 * there. Falls back to REMOTE_ADDR otherwise.
	 *
	 * @return string
	 */
	private function client_ip() {
		if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
		}

		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
	}

	/**
	 * Rate-limit the REST resend endpoint.
	 *
	 * Two independent budgets, both on a 15-minute window, so rotating emails
	 * from one client cannot bypass the mail budget:
	 *  - per (client + email): 3 requests.
	 *  - per client: 20 requests aggregate.
	 *
	 * @param string $email Sanitized email.
	 * @return bool Whether the request is within the limits.
	 */
	private function resend_rate_ok( $email ) {
		$ip         = $this->client_ip();
		$email      = strtolower( $email );
		$key        = 'ums_resend_' . md5( $ip . '|' . $email );
		$per_ip_key = 'ums_resend_ip_' . md5( $ip );

		$per_pair = (int) get_transient( $key );
		$per_ip   = (int) get_transient( $per_ip_key );

		if ( $per_pair >= 3 || $per_ip >= 20 ) {
			return false;
		}

		set_transient( $key, $per_pair + 1, 15 * MINUTE_IN_SECONDS );
		set_transient( $per_ip_key, $per_ip + 1, 15 * MINUTE_IN_SECONDS );

		return true;
	}

	// ---------------------------------------------------------------------
	// Checkout gate.
	// ---------------------------------------------------------------------

	/**
	 * Require a verified email before an account can place an order.
	 *
	 * Runs on woocommerce_after_checkout_validation — before the order or account
	 * is created — and aborts placement via the shared WP_Error.
	 *
	 * Note: this action only fires for the core/WooCommerce-shortcode checkout,
	 * not the Cart & Checkout blocks / Store API. The store currently uses the
	 * shortcode checkout; migrating to the blocks checkout would bypass this
	 * gate until an equivalent Store API guard is added.
	 *
	 * @param array     $data   Posted checkout data.
	 * @param \WP_Error $errors Validation errors collector.
	 * @return void
	 */
	public function block_unverified_checkout( $data, $errors ) {
		if ( ! $this->settings->get( 'verification', 'require_email_verification', true ) ) {
			return;
		}

		$required = apply_filters( 'ums_require_verified_checkout', true, $data );
		if ( ! $required ) {
			return;
		}

		$user = wp_get_current_user();

		// Returning / logged-in user: block until their email is verified.
		if ( $user && $user->exists() ) {
			if ( $this->is_excluded( $user ) || $this->is_verified( $user->ID ) ) {
				return;
			}

			$message = apply_filters( 'ums_unverified_checkout_message', $this->checkout_not_verified_message(), $user->ID );
			$errors->add( 'ums_email_unverified_checkout', $message );

			return;
		}

		// Logged-out visitor. With guest checkout disabled this checkout will
		// create a WP account mid-flow (is_registration_required() is true) — and
		// that account starts unverified, reproducing the "paid but locked out"
		// incident. Enforce register-then-verify even though no checkbox is posted.
		$creates_account = ! empty( $data['createaccount'] );
		if ( ! $creates_account && function_exists( 'WC' ) ) {
			$checkout = WC()->checkout();
			if ( $checkout && $checkout->is_registration_required() ) {
				$creates_account = true;
			}
		}

		if ( $creates_account ) {
			$this->create_unverified_customer( $data, $errors );

			return;
		}

		// Genuine guest checkout (guest mode enabled): still fail closed when the
		// posted billing email belongs to an existing unverified account.
		$email = isset( $data['billing_email'] ) ? sanitize_email( (string) $data['billing_email'] ) : '';
		$guest = $email ? get_user_by( 'email', $email ) : false;
		if ( $guest && ! $this->is_excluded( $guest ) && ! $this->is_verified( $guest->ID ) ) {
			$message = apply_filters( 'ums_unverified_checkout_message', $this->checkout_not_verified_message(), $guest->ID );
			$errors->add( 'ums_email_unverified_checkout', $message );
		}
	}

	/**
	 * Message shown to a logged-in buyer whose email is not verified.
	 *
	 * @return string
	 */
	private function checkout_not_verified_message() {
		$url  = ums_verification_resend_url();
		$link = $url ? '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Verify your email', 'user-management-suite' ) . '</a>' : '';

		// phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment -- translators note below.
		/* translators: 1: verification page link. */
		$message = __( 'Your email address has not been verified yet, so you cannot complete this order yet. Check your inbox (and spam folder) for the verification email we sent when you registered, or request a new one here: %1$s.', 'user-management-suite' );

		return sprintf( $message, $link );
	}

	/**
	 * Create the account that a logged-out buyer's checkout would otherwise
	 * create mid-flow, so the verification email actually goes out.
	 *
	 * On this store guest checkout is off and registration is required, so the
	 * first-time checkout was expected to create the customer. WooCommerce only
	 * creates the customer after the order is placed — an order this gate
	 * prevents. The guest was told to "confirm the email we send you" when no
	 * email had been sent (no account, no user_register). Creating the customer
	 * up-front fires user_register -> on_register(), which marks them unverified
	 * and emails the verification link; the order is still blocked until that
	 * link is clicked. The cart survives on the same browser session, so the
	 * buyer can complete after verifying.
	 *
	 * @param array     $data   Posted checkout data.
	 * @param \WP_Error $errors Validation errors collector.
	 * @return void
	 */
	private function create_unverified_customer( $data, $errors ) {
		$data  = (array) $data;
		$email = isset( $data['billing_email'] ) ? sanitize_email( (string) $data['billing_email'] ) : '';

		if ( ! is_email( $email ) ) {
			$errors->add(
				'ums_email_unverified_checkout_new',
				__( 'Please enter a valid billing email address so we can create and verify your account.', 'user-management-suite' )
			);

			return;
		}

		// The email already belongs to an account — never create a duplicate.
		// Fail closed unless that account is verified.
		$existing = get_user_by( 'email', $email );
		if ( $existing ) {
			if ( ! $this->is_excluded( $existing ) && ! $this->is_verified( $existing->ID ) ) {
				$message = apply_filters( 'ums_unverified_checkout_message', $this->checkout_not_verified_message(), $existing->ID );
				$errors->add( 'ums_email_unverified_checkout', $message );
			}

			return;
		}

		if ( ! function_exists( 'wc_create_new_customer' ) ) {
			$message = apply_filters( 'ums_unverified_checkout_new_account_message', $this->checkout_new_account_message() );
			$errors->add( 'ums_email_unverified_checkout_new', $message );

			return;
		}

		$username = '';
		$password = '';
		if ( isset( $data['account_username'] ) && '' !== $data['account_username'] ) {
			$username = sanitize_user( (string) $data['account_username'] );
		}
		if ( isset( $data['account_password'] ) && '' !== $data['account_password'] ) {
			$password = (string) $data['account_password'];
		}

		$customer_id = wc_create_new_customer( $email, $username, $password );

		if ( is_wp_error( $customer_id ) ) {
			$errors->add( 'ums_customer_create_failed', $customer_id->get_error_message() );

			return;
		}

		/**
		 * Filters the message attached to a first-time checkout. By this point
		 * the account exists and the verification email has been sent.
		 *
		 * @param string $message     Message.
		 * @param int    $customer_id Created customer id.
		 * @param string $email       Billing / account email.
		 */
		$message = apply_filters( 'ums_unverified_checkout_new_account_message', $this->checkout_new_account_message(), $customer_id, $email );
		$errors->add( 'ums_email_unverified_checkout_new', $message );
	}

	/**
	 * Message shown when a checkout would create a new (unverified) account.
	 *
	 * @return string
	 */
	private function checkout_new_account_message() {
		$url  = ums_verification_resend_url();
		$link = $url ? '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Resend the verification email', 'user-management-suite' ) . '</a>' : '';

		/* translators: 1: verification page link. */
		$message = __( "We've just created your account and sent a verification link to your email address. Click the link in that email (check your spam folder too) to confirm your address, then return here and complete your order. If it didn't arrive: %1\$s.", 'user-management-suite' );

		return sprintf( $message, $link );
	}

	// ---------------------------------------------------------------------
	// Account dashboard.
	// ---------------------------------------------------------------------

	/**
	 * Show a notice + the resend form on My Account for unverified users.
	 *
	 * @return void
	 */
	public function account_verification_notice() {
		$user = wp_get_current_user();
		if ( ! $user || ! $user->exists() || $this->is_excluded( $user ) || $this->is_verified( $user->ID ) ) {
			return;
		}

		echo '<div class="woocommerce-info">';
		echo '<p>' . esc_html__( 'Your email address has not been verified yet. Check your inbox (and spam folder) for the verification email, or request a new link below.', 'user-management-suite' ) . '</p>';
		echo $this->resend_shortcode(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Shortcode output is fully escaped in the form template.
		echo '</div>';
	}

	// ---------------------------------------------------------------------
	// Spam + reCAPTCHA.
	// ---------------------------------------------------------------------

	/**
	 * Validate registrations (spam + reCAPTCHA).
	 *
	 * @param \WP_Error $errors     Errors.
	 * @param string    $user_login Login.
	 * @param string    $user_email Email.
	 * @return \WP_Error
	 */
	public function registration_errors( $errors, $user_login, $user_email ) {
		$errors = $this->spam->validate( $errors, $user_login, $user_email );

		if ( $this->recaptcha->guards( 'register' ) && ! $this->recaptcha->verify() ) {
			$errors->add( 'ums_recaptcha', __( '<strong>Error</strong>: Please complete the reCAPTCHA challenge.', 'user-management-suite' ) );
		}

		return $errors;
	}

	/**
	 * Gate WooCommerce registrations with the spam rules.
	 *
	 * Runs inside wc_create_new_customer(), which calls this filter with
	 * after `woocommerce_register_post`; a WP_Error here stops the customer from
	 * being created. ReCAPTCHA is intentionally not enforced on this surface so
	 * checkout / My Account buyers are not challenged.
	 *
	 * @param \WP_Error $errors      Error collector.
	 * @param string    $username    Submitted username.
	 * @param string    $user_email  Submitted email.
	 * @return \WP_Error
	 */
	public function woocommerce_registration_errors( $errors, $username, $user_email ) {
		return $this->spam->validate( $errors, $username, $user_email );
	}

	/**
	 * Verify reCAPTCHA on login.
	 *
	 * @param \WP_User|\WP_Error $user Auth result.
	 * @return \WP_User|\WP_Error
	 */
	public function login_recaptcha( $user ) {
		if ( $user instanceof \WP_User && $this->recaptcha->guards( 'login' ) && ! $this->recaptcha->verify() ) {
			return new \WP_Error( 'ums_recaptcha', __( '<strong>Error</strong>: Please complete the reCAPTCHA challenge.', 'user-management-suite' ) );
		}

		return $user;
	}

	/**
	 * Verify reCAPTCHA on lost-password.
	 *
	 * @param \WP_Error $errors Errors.
	 * @return void
	 */
	public function lostpassword_recaptcha( $errors ) {
		if ( $this->recaptcha->guards( 'lostpassword' ) && ! $this->recaptcha->verify() ) {
			$errors->add( 'ums_recaptcha', __( '<strong>Error</strong>: Please complete the reCAPTCHA challenge.', 'user-management-suite' ) );
		}
	}

	/**
	 * Render reCAPTCHA on the registration form.
	 *
	 * @return void
	 */
	public function render_recaptcha_register() {
		if ( $this->recaptcha->guards( 'register' ) ) {
			$this->recaptcha->render();
		}
	}

	/**
	 * Render reCAPTCHA on the login form.
	 *
	 * @return void
	 */
	public function render_recaptcha_login() {
		if ( $this->recaptcha->guards( 'login' ) ) {
			$this->recaptcha->render();
		}
	}

	/**
	 * Render reCAPTCHA on the lost-password form.
	 *
	 * @return void
	 */
	public function render_recaptcha_lostpassword() {
		if ( $this->recaptcha->guards( 'lostpassword' ) ) {
			$this->recaptcha->render();
		}
	}

	// ---------------------------------------------------------------------
	// Users list integration.
	// ---------------------------------------------------------------------

	/**
	 * Add a "Verified" column.
	 *
	 * @param array<string,string> $columns Columns.
	 * @return array<string,string>
	 */
	public function add_column( $columns ) {
		$columns['ums_verified'] = __( 'Verified', 'user-management-suite' );

		return $columns;
	}

	/**
	 * Render the Verified column.
	 *
	 * @param string $output      Default.
	 * @param string $column_name Column id.
	 * @param int    $user_id     User id.
	 * @return string
	 */
	public function render_column( $output, $column_name, $user_id ) {
		if ( 'ums_verified' !== $column_name ) {
			return $output;
		}

		$status = get_user_meta( $user_id, self::META_STATUS, true );

		if ( '1' === (string) $status ) {
			return '<span style="color:#008a20;">' . esc_html__( 'Verified', 'user-management-suite' ) . '</span>';
		}
		if ( '0' === (string) $status ) {
			return '<span style="color:#b32d2e;">' . esc_html__( 'Unverified', 'user-management-suite' ) . '</span>';
		}

		return '&mdash;';
	}

	/**
	 * Output the status filter dropdown above the Users table.
	 *
	 * @param string $which Top or bottom.
	 * @return void
	 */
	public function status_filter_ui( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		$value = isset( $_GET['ums_vstatus'] ) ? sanitize_key( wp_unslash( $_GET['ums_vstatus'] ) ) : '';
		?>
		<label class="screen-reader-text" for="ums_vstatus"><?php esc_html_e( 'Filter by verification status', 'user-management-suite' ); ?></label>
		<select name="ums_vstatus" id="ums_vstatus" style="float:none;margin:0 6px;">
			<option value=""><?php esc_html_e( 'All verification statuses', 'user-management-suite' ); ?></option>
			<option value="verified" <?php selected( $value, 'verified' ); ?>><?php esc_html_e( 'Verified', 'user-management-suite' ); ?></option>
			<option value="unverified" <?php selected( $value, 'unverified' ); ?>><?php esc_html_e( 'Unverified', 'user-management-suite' ); ?></option>
		</select>
		<?php
		submit_button( __( 'Filter', 'user-management-suite' ), '', 'ums_filter', false );
	}

	/**
	 * Apply the verification-status filter to the Users query.
	 *
	 * @param \WP_User_Query $query Query.
	 * @return void
	 */
	public function filter_by_status( $query ) {
		global $pagenow;
		if ( ! is_admin() || 'users.php' !== $pagenow ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		$value = isset( $_GET['ums_vstatus'] ) ? sanitize_key( wp_unslash( $_GET['ums_vstatus'] ) ) : '';
		if ( 'verified' !== $value && 'unverified' !== $value ) {
			return;
		}

		$query->set(
			'meta_query',
			array(
				array(
					'key'   => self::META_STATUS,
					'value' => 'verified' === $value ? '1' : '0',
				),
			)
		);
	}

	/**
	 * Add bulk verify/unverify actions.
	 *
	 * @param array<string,string> $actions Actions.
	 * @return array<string,string>
	 */
	public function bulk_actions( $actions ) {
		$actions['ums_verify']   = __( 'Mark verified', 'user-management-suite' );
		$actions['ums_unverify'] = __( 'Mark unverified', 'user-management-suite' );

		return $actions;
	}

	/**
	 * Process bulk verify/unverify.
	 *
	 * @param string $redirect Redirect URL.
	 * @param string $action   Action name.
	 * @param int[]  $user_ids Selected user ids.
	 * @return string
	 */
	public function handle_bulk( $redirect, $action, $user_ids ) {
		if ( 'ums_verify' !== $action && 'ums_unverify' !== $action ) {
			return $redirect;
		}

		if ( ! current_user_can( 'edit_users' ) ) {
			return $redirect;
		}

		$status = ( 'ums_verify' === $action ) ? '1' : '0';
		$count  = 0;
		foreach ( (array) $user_ids as $uid ) {
			update_user_meta( (int) $uid, self::META_STATUS, $status );
			if ( '1' === $status ) {
				delete_user_meta( (int) $uid, self::META_KEY );
			}
			++$count;
		}

		return add_query_arg( 'ums_bulk', $count, $redirect );
	}

	/**
	 * Show a notice after a bulk action.
	 *
	 * @return void
	 */
	public function bulk_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only count.
		if ( empty( $_GET['ums_bulk'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only count.
		$count = absint( $_GET['ums_bulk'] );
		echo '<div class="notice notice-success is-dismissible"><p>'
			. esc_html(
				sprintf(
					/* translators: %d: number of users updated. */
					_n( 'Updated verification status for %d user.', 'Updated verification status for %d users.', $count, 'user-management-suite' ),
					$count
				)
			)
			. '</p></div>';
	}

	// ---------------------------------------------------------------------
	// Auto-delete cron.
	// ---------------------------------------------------------------------

	/**
	 * Schedule the daily auto-delete event when enabled.
	 *
	 * @return void
	 */
	private function maybe_schedule_cron() {
		$days = (int) $this->settings->get( 'verification', 'auto_delete_days', 0 );

		if ( $days > 0 && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		} elseif ( $days <= 0 && wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
	}

	/**
	 * Delete users who have stayed unverified beyond the configured window.
	 *
	 * @return void
	 */
	public function run_auto_delete() {
		$days = (int) $this->settings->get( 'verification', 'auto_delete_days', 0 );
		if ( $days <= 0 ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/user.php';

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		$users = get_users(
			array(
				// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Required for filtering unverified users; no WP API alternative without a custom table.
				'meta_key'   => self::META_STATUS,
				'meta_value' => '0',
				// phpcs:enable
				'date_query' => array(
					array(
						'before' => $cutoff,
						'column' => 'user_registered',
					),
				),
				'fields'     => 'ID',
				'number'     => 200,
			)
		);

		foreach ( $users as $uid ) {
			$user = get_userdata( $uid );
			if ( $user && ! $this->is_excluded( $user ) ) {
				wp_delete_user( $uid );
			}
		}
	}

	// ---------------------------------------------------------------------
	// Settings.
	// ---------------------------------------------------------------------

	/**
	 * {@inheritDoc}
	 */
	public function settings_tab_id() {
		return 'verification';
	}

	/**
	 * {@inheritDoc}
	 */
	public function settings_tab_label() {
		return __( 'Verification', 'user-management-suite' );
	}

	/**
	 * Render the Verification settings tab.
	 *
	 * @param SettingsRepository $settings Settings repository.
	 * @return void
	 */
	public function render_settings_tab( SettingsRepository $settings ) {
		$v = $settings->get( 'verification' );

		echo '<tr><th colspan="2"><h3>' . esc_html__( 'Email verification', 'user-management-suite' ) . '</h3></th></tr>';

		Fields::row_start( __( 'Require verification', 'user-management-suite' ) );
		Fields::checkbox( 'verification', 'require_email_verification', $v['require_email_verification'], __( 'New users must verify their email before they can log in', 'user-management-suite' ) );
		echo '<br />';
		Fields::checkbox( 'verification', 'auto_login_after_verify', $v['auto_login_after_verify'], __( 'Log users in automatically after they verify', 'user-management-suite' ) );
		Fields::row_end();

		Fields::row_start( __( 'Exclude roles', 'user-management-suite' ) );
		Fields::roles_checklist( 'verification', 'exclude_roles', (array) $v['exclude_roles'] );
		echo '<p class="description">' . esc_html__( 'Users with these roles are never required to verify.', 'user-management-suite' ) . '</p>';
		Fields::row_end();

		Fields::row_start( __( 'Redirect after verification', 'user-management-suite' ) );
		Fields::page_select( 'verification', 'redirect_page_id', (int) $v['redirect_page_id'] );
		Fields::row_end();

		echo '<tr><th colspan="2"><h3>' . esc_html__( 'Email sender & content', 'user-management-suite' ) . '</h3></th></tr>';
		Fields::row_start( __( 'From name', 'user-management-suite' ) );
		Fields::text( 'verification', 'mail_from_name', isset( $v['mail_from_name'] ) ? $v['mail_from_name'] : '' );
		Fields::row_end();
		Fields::row_start( __( 'From email', 'user-management-suite' ) );
		Fields::text( 'verification', 'mail_from_email', isset( $v['mail_from_email'] ) ? $v['mail_from_email'] : '', '', 'email' );
		Fields::row_end();
		Fields::row_start( __( 'Email subject', 'user-management-suite' ) );
		Fields::text( 'verification', 'email_subject', isset( $v['email_subject'] ) ? $v['email_subject'] : '' );
		Fields::row_end();
		Fields::row_start( __( 'Email body', 'user-management-suite' ) );
		Fields::textarea( 'verification', 'email_body', isset( $v['email_body'] ) ? $v['email_body'] : '', __( 'Tokens: {site_name} {site_url} {user_name} {display_name} {user_email} {verify_url}', 'user-management-suite' ) );
		Fields::row_end();

		echo '<tr><th colspan="2"><h3>' . esc_html__( 'Spam protection', 'user-management-suite' ) . '</h3></th></tr>';
		Fields::row_start( __( 'Blocked email domains', 'user-management-suite' ) );
		Fields::textarea( 'verification', 'blocked_domains', Security::array_to_lines( $v['blocked_domains'] ), __( 'One domain per line, e.g. spam.com. A leading dot blocks a suffix: .xyz blocks the whole TLD.', 'user-management-suite' ) );
		Fields::row_end();
		Fields::row_start( __( 'Allowed email domains', 'user-management-suite' ) );
		Fields::textarea( 'verification', 'allowed_domains', Security::array_to_lines( $v['allowed_domains'] ), __( 'If set, only these domains may register. One per line.', 'user-management-suite' ) );
		Fields::row_end();
		Fields::row_start( __( 'Blocked usernames', 'user-management-suite' ) );
		Fields::textarea( 'verification', 'blocked_usernames', Security::array_to_lines( $v['blocked_usernames'] ), __( 'One username per line.', 'user-management-suite' ) );
		Fields::row_end();
		Fields::row_start( __( 'Blocked keywords', 'user-management-suite' ) );
		Fields::textarea( 'verification', 'blocked_keywords', Security::array_to_lines( $v['blocked_keywords'] ), __( 'One keyword per line. Any registration whose username or email local part contains a keyword is rejected. Seed from the legacy ATS casino/betting list.', 'user-management-suite' ) );
		Fields::row_end();
		Fields::row_start( __( 'Blocked IP addresses', 'user-management-suite' ) );
		Fields::textarea( 'verification', 'blocked_ips', Security::array_to_lines( $v['blocked_ips'] ), __( 'One IP per line. Registrations from these addresses are rejected (real client IP behind Cloudflare is used).', 'user-management-suite' ) );
		Fields::row_end();
		Fields::row_start( __( 'Generic emails', 'user-management-suite' ) );
		Fields::checkbox( 'verification', 'block_generic_email', $v['block_generic_email'], __( 'Block role-based addresses (admin@, info@, support@, …)', 'user-management-suite' ) );
		Fields::row_end();

		echo '<tr><th colspan="2"><h3>' . esc_html__( 'Google reCAPTCHA', 'user-management-suite' ) . '</h3></th></tr>';
		echo '<tr><td colspan="2"><p class="description">' . esc_html__( 'Optional. When configured, tokens are sent to Google for verification. See the readme for details.', 'user-management-suite' ) . '</p></td></tr>';
		Fields::row_start( __( 'Version', 'user-management-suite' ) );
		Fields::select(
			'verification',
			'recaptcha_version',
			$v['recaptcha_version'],
			array(
				''             => __( 'Disabled', 'user-management-suite' ),
				'v2'           => __( 'v2 Checkbox', 'user-management-suite' ),
				'v2_invisible' => __( 'v2 Invisible', 'user-management-suite' ),
				'v3'           => __( 'v3', 'user-management-suite' ),
			)
		);
		Fields::row_end();
		Fields::row_start( __( 'Site key', 'user-management-suite' ) );
		Fields::text( 'verification', 'recaptcha_site_key', $v['recaptcha_site_key'] );
		Fields::row_end();
		Fields::row_start( __( 'Secret key', 'user-management-suite' ) );
		Fields::text( 'verification', 'recaptcha_secret_key', $v['recaptcha_secret_key'] );
		Fields::row_end();
		Fields::row_start( __( 'Protect forms', 'user-management-suite' ) );
		Fields::checkbox( 'verification', 'recaptcha_on_register', $v['recaptcha_on_register'], __( 'Registration form', 'user-management-suite' ) );
		echo '<br />';
		Fields::checkbox( 'verification', 'recaptcha_on_login', $v['recaptcha_on_login'], __( 'Login form', 'user-management-suite' ) );
		echo '<br />';
		Fields::checkbox( 'verification', 'recaptcha_on_lostpassword', $v['recaptcha_on_lostpassword'], __( 'Lost-password form', 'user-management-suite' ) );
		Fields::row_end();

		echo '<tr><th colspan="2"><h3>' . esc_html__( 'Unverified accounts', 'user-management-suite' ) . '</h3></th></tr>';
		Fields::row_start( __( 'Auto-delete after', 'user-management-suite' ) );
		Fields::text( 'verification', 'auto_delete_days', (int) $v['auto_delete_days'], '', 'number' );
		echo '<p class="description">' . esc_html__( 'Days. Set 0 to disable automatic deletion of unverified accounts.', 'user-management-suite' ) . '</p>';
		Fields::row_end();
	}

	/**
	 * Sanitize the Verification settings section.
	 *
	 * @param array<string,mixed> $input   Submitted settings array.
	 * @param array<string,mixed> $current Current stored settings.
	 * @return array<string,mixed>
	 */
	public function sanitize_settings( array $input, array $current ) {
		if ( ! isset( $input['verification'] ) || ! is_array( $input['verification'] ) ) {
			return array();
		}

		$in    = $input['verification'];
		$valid = array_keys( wp_roles()->get_names() );

		$exclude = isset( $in['exclude_roles'] ) ? array_map( 'sanitize_key', (array) $in['exclude_roles'] ) : array();

		return array(
			'verification' => array(
				'require_email_verification' => ! empty( $in['require_email_verification'] ),
				'auto_login_after_verify'    => ! empty( $in['auto_login_after_verify'] ),
				'redirect_page_id'           => isset( $in['redirect_page_id'] ) ? absint( $in['redirect_page_id'] ) : 0,
				'exclude_roles'              => array_values( array_intersect( $valid, $exclude ) ),
				'mail_from_name'             => isset( $in['mail_from_name'] ) ? sanitize_text_field( $in['mail_from_name'] ) : '',
				'mail_from_email'            => isset( $in['mail_from_email'] ) ? sanitize_email( $in['mail_from_email'] ) : '',
				'email_subject'              => isset( $in['email_subject'] ) ? sanitize_text_field( $in['email_subject'] ) : '',
				'email_body'                 => isset( $in['email_body'] ) ? sanitize_textarea_field( $in['email_body'] ) : '',
				'blocked_domains'            => isset( $in['blocked_domains'] ) ? Security::lines_to_array( $in['blocked_domains'] ) : array(),
				'allowed_domains'            => isset( $in['allowed_domains'] ) ? Security::lines_to_array( $in['allowed_domains'] ) : array(),
				'blocked_usernames'          => isset( $in['blocked_usernames'] ) ? Security::lines_to_array( $in['blocked_usernames'], 'sanitize_user' ) : array(),
				'blocked_keywords'           => isset( $in['blocked_keywords'] ) ? Security::lines_to_array( $in['blocked_keywords'], 'sanitize_text_field' ) : array(),
				'blocked_ips'                => isset( $in['blocked_ips'] ) ? Security::lines_to_array( $in['blocked_ips'] ) : array(),
				'block_generic_email'        => ! empty( $in['block_generic_email'] ),
				'recaptcha_version'          => isset( $in['recaptcha_version'] ) && in_array( $in['recaptcha_version'], array( 'v2', 'v2_invisible', 'v3' ), true ) ? $in['recaptcha_version'] : '',
				'recaptcha_site_key'         => isset( $in['recaptcha_site_key'] ) ? sanitize_text_field( $in['recaptcha_site_key'] ) : '',
				'recaptcha_secret_key'       => isset( $in['recaptcha_secret_key'] ) ? sanitize_text_field( $in['recaptcha_secret_key'] ) : '',
				'recaptcha_on_login'         => ! empty( $in['recaptcha_on_login'] ),
				'recaptcha_on_register'      => ! empty( $in['recaptcha_on_register'] ),
				'recaptcha_on_lostpassword'  => ! empty( $in['recaptcha_on_lostpassword'] ),
				'auto_delete_days'           => isset( $in['auto_delete_days'] ) ? absint( $in['auto_delete_days'] ) : 0,
			),
		);
	}
}
