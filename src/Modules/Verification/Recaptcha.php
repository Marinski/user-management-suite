<?php
/**
 * Google reCAPTCHA integration (optional, opt-in external service).
 *
 * @package UserManagementSuite
 */

namespace Marinski\UserManagementSuite\Modules\Verification;

use Marinski\UserManagementSuite\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Renders reCAPTCHA widgets and verifies tokens with Google's siteverify API.
 *
 * External service: when enabled, this contacts https://www.google.com/recaptcha/.
 */
class Recaptcha {

	const FIELD        = 'g-recaptcha-response';
	const VERIFY_URL   = 'https://www.google.com/recaptcha/api/siteverify';
	const V3_THRESHOLD = 0.5;

	/**
	 * Settings repository.
	 *
	 * @var SettingsRepository
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param SettingsRepository $settings Settings repository.
	 */
	public function __construct( SettingsRepository $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Whether reCAPTCHA is configured and active.
	 *
	 * @return bool
	 */
	public function is_active() {
		$version = (string) $this->settings->get( 'verification', 'recaptcha_version', '' );
		$site    = (string) $this->settings->get( 'verification', 'recaptcha_site_key', '' );
		$secret  = (string) $this->settings->get( 'verification', 'recaptcha_secret_key', '' );

		return ( '' !== $version && '' !== $site && '' !== $secret );
	}

	/**
	 * Selected version.
	 *
	 * @return string
	 */
	private function version() {
		return (string) $this->settings->get( 'verification', 'recaptcha_version', '' );
	}

	/**
	 * Site key.
	 *
	 * @return string
	 */
	private function site_key() {
		return (string) $this->settings->get( 'verification', 'recaptcha_site_key', '' );
	}

	/**
	 * Whether reCAPTCHA should guard the given context.
	 *
	 * @param string $context login|register|lostpassword.
	 * @return bool
	 */
	public function guards( $context ) {
		if ( ! $this->is_active() ) {
			return false;
		}

		$map = array(
			'login'        => 'recaptcha_on_login',
			'register'     => 'recaptcha_on_register',
			'lostpassword' => 'recaptcha_on_lostpassword',
		);

		return isset( $map[ $context ] ) && $this->settings->get( 'verification', $map[ $context ], false );
	}

	/**
	 * Enqueue the Google API + helper script on the login screen.
	 *
	 * @return void
	 */
	public function enqueue() {
		if ( ! $this->is_active() ) {
			return;
		}

		$version = $this->version();

		if ( 'v3' === $version ) {
			wp_enqueue_script(
				'ums-recaptcha-api',
				'https://www.google.com/recaptcha/api.js?render=' . rawurlencode( $this->site_key() ),
				array(),
				null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Google CDN.
				true
			);
		} else {
			wp_enqueue_script(
				'ums-recaptcha-api',
				'https://www.google.com/recaptcha/api.js',
				array(),
				null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Google CDN.
				true
			);
		}

		wp_enqueue_script(
			'ums-recaptcha',
			UMS_PLUGIN_URL . 'assets/front/js/recaptcha.js',
			array( 'ums-recaptcha-api' ),
			UMS_VERSION,
			true
		);

		wp_localize_script(
			'ums-recaptcha',
			'umsRecaptcha',
			array(
				'version' => $version,
				'siteKey' => $this->site_key(),
				'field'   => self::FIELD,
			)
		);
	}

	/**
	 * Render the widget markup for a form.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! $this->is_active() ) {
			return;
		}

		$version = $this->version();

		if ( 'v3' === $version ) {
			// Hidden field populated by recaptcha.js.
			echo '<input type="hidden" name="' . esc_attr( self::FIELD ) . '" value="" class="ums-recaptcha-token" />';
			return;
		}

		$attrs = 'class="g-recaptcha" data-sitekey="' . esc_attr( $this->site_key() ) . '"';
		if ( 'v2_invisible' === $version ) {
			$attrs .= ' data-size="invisible"';
		}

		echo '<div style="margin:10px 0;"><div ' . $attrs . '></div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $attrs built from escaped parts.
	}

	/**
	 * Verify the submitted token. Returns true when valid (or not required).
	 *
	 * @return bool
	 */
	public function verify() {
		if ( ! $this->is_active() ) {
			return true;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- reCAPTCHA token IS the verification; form has its own nonce.
		$token = isset( $_POST[ self::FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::FIELD ] ) ) : '';
		if ( '' === $token ) {
			return false;
		}

		$response = wp_remote_post(
			self::VERIFY_URL,
			array(
				'timeout' => 10,
				'body'    => array(
					'secret'   => (string) $this->settings->get( 'verification', 'recaptcha_secret_key', '' ),
					'response' => $token,
					'remoteip' => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			// Fail open on transient network errors to avoid locking users out.
			return true;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['success'] ) ) {
			return false;
		}

		if ( 'v3' === $this->version() && isset( $data['score'] ) ) {
			$threshold = (float) apply_filters( 'ums_recaptcha_v3_threshold', self::V3_THRESHOLD );

			return (float) $data['score'] >= $threshold;
		}

		return true;
	}
}
