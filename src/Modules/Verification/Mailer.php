<?php
/**
 * Verification email composition + sending.
 *
 * @package UserManagementSuite
 */

namespace Marinski\UserManagementSuite\Modules\Verification;

use Marinski\UserManagementSuite\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Builds and sends the verification email, with token replacement and an
 * optional custom From name/address.
 */
class Mailer {

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
	 * Send the verification email to a user.
	 *
	 * @param \WP_User $user          User.
	 * @param string   $activation_key Plain activation key.
	 * @return bool
	 */
	public function send_verification( $user, $activation_key ) {
		$verify_url = add_query_arg(
			array(
				'ums_verify' => rawurlencode( $activation_key ),
				'uid'        => $user->ID,
			),
			home_url( '/' )
		);

		$tokens = array(
			'{site_name}'    => get_bloginfo( 'name' ),
			'{site_url}'     => home_url( '/' ),
			'{user_name}'    => $user->user_login,
			'{display_name}' => $user->display_name,
			'{user_email}'   => $user->user_email,
			'{verify_url}'   => esc_url_raw( $verify_url ),
		);

		$default_subject = sprintf(
			/* translators: %s: site name. */
			__( 'Please verify your email address for %s', 'user-management-suite' ),
			'{site_name}'
		);

		$default_body = __(
			"Hi {display_name},\n\nThanks for registering at {site_name}. Please confirm your email address by clicking the link below:\n\n{verify_url}\n\nIf you did not create this account, you can ignore this email.",
			'user-management-suite'
		);

		$subject = $this->settings->get( 'verification', 'email_subject', '' );
		$subject = $subject ? $subject : $default_subject;

		$body = $this->settings->get( 'verification', 'email_body', '' );
		$body = $body ? $body : $default_body;

		$subject = strtr( $subject, $tokens );
		$body    = strtr( $body, $tokens );

		$headers = $this->build_headers();

		add_filter( 'wp_mail_from', array( $this, 'mail_from' ) );
		add_filter( 'wp_mail_from_name', array( $this, 'mail_from_name' ) );

		$sent = wp_mail( $user->user_email, $subject, $body, $headers );

		remove_filter( 'wp_mail_from', array( $this, 'mail_from' ) );
		remove_filter( 'wp_mail_from_name', array( $this, 'mail_from_name' ) );

		return $sent;
	}

	/**
	 * Build mail headers.
	 *
	 * @return string[]
	 */
	private function build_headers() {
		return array( 'Content-Type: text/plain; charset=UTF-8' );
	}

	/**
	 * Filter callback: custom From address.
	 *
	 * @param string $from Default address.
	 * @return string
	 */
	public function mail_from( $from ) {
		$email = sanitize_email( (string) $this->settings->get( 'verification', 'mail_from_email', '' ) );

		return is_email( $email ) ? $email : $from;
	}

	/**
	 * Filter callback: custom From name.
	 *
	 * @param string $name Default name.
	 * @return string
	 */
	public function mail_from_name( $name ) {
		$custom = trim( (string) $this->settings->get( 'verification', 'mail_from_name', '' ) );

		return '' !== $custom ? $custom : $name;
	}
}
