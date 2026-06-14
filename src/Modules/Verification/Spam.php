<?php
/**
 * Registration spam protection (domain/username/generic-email blocking).
 *
 * @package UserManagementSuite
 */

namespace Marinski\UserManagementSuite\Modules\Verification;

use Marinski\UserManagementSuite\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Validates registrations against allow/block lists.
 */
class Spam {

	/**
	 * Settings repository.
	 *
	 * @var SettingsRepository
	 */
	private $settings;

	/**
	 * Default role-based local-parts blocked when "generic email" is enabled.
	 *
	 * @var string[]
	 */
	private $generic_prefixes = array(
		'admin',
		'administrator',
		'info',
		'sales',
		'support',
		'contact',
		'noreply',
		'no-reply',
		'webmaster',
		'postmaster',
	);

	/**
	 * Constructor.
	 *
	 * @param SettingsRepository $settings Settings repository.
	 */
	public function __construct( SettingsRepository $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Validate a registration; add errors as needed.
	 *
	 * @param \WP_Error $errors      Error object.
	 * @param string    $user_login  Sanitized login.
	 * @param string    $user_email  Email.
	 * @return \WP_Error
	 */
	public function validate( $errors, $user_login, $user_email ) {
		$email  = strtolower( trim( (string) $user_email ) );
		$at     = strrpos( $email, '@' );
		$domain = ( false !== $at ) ? substr( $email, $at + 1 ) : '';
		$local  = ( false !== $at ) ? substr( $email, 0, $at ) : $email;

		$blocked_domains = array_map( 'strtolower', (array) $this->settings->get( 'verification', 'blocked_domains', array() ) );
		$allowed_domains = array_map( 'strtolower', (array) $this->settings->get( 'verification', 'allowed_domains', array() ) );
		$blocked_users   = array_map( 'strtolower', (array) $this->settings->get( 'verification', 'blocked_usernames', array() ) );

		if ( $domain && in_array( $domain, $blocked_domains, true ) ) {
			$errors->add( 'ums_blocked_domain', __( '<strong>Error</strong>: Registrations from this email domain are not allowed.', 'user-management-suite' ) );
		}

		if ( $domain && ! empty( $allowed_domains ) && ! in_array( $domain, $allowed_domains, true ) ) {
			$errors->add( 'ums_domain_not_allowed', __( '<strong>Error</strong>: Please register with an approved email domain.', 'user-management-suite' ) );
		}

		$login_lc = strtolower( (string) $user_login );
		if ( in_array( $login_lc, $blocked_users, true ) ) {
			$errors->add( 'ums_blocked_username', __( '<strong>Error</strong>: This username is not allowed.', 'user-management-suite' ) );
		}

		if ( $this->settings->get( 'verification', 'block_generic_email', false ) ) {
			$prefixes = (array) apply_filters( 'ums_generic_email_prefixes', $this->generic_prefixes );
			if ( in_array( $local, array_map( 'strtolower', $prefixes ), true ) ) {
				$errors->add( 'ums_generic_email', __( '<strong>Error</strong>: Please use a personal email address.', 'user-management-suite' ) );
			}
		}

		return $errors;
	}
}
