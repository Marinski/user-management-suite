<?php
/**
 * Secure "previous user" cookie handling for user switching.
 *
 * @package UserManagementSuite
 */

namespace Marinski\UserManagementSuite\Modules\Switching;

defined( 'ABSPATH' ) || exit;

/**
 * Stores the original user's signed re-entry token (using WordPress's own
 * auth-cookie scheme) so a switched session can return to it securely.
 */
class Session {

	const EXPIRY = DAY_IN_SECONDS * 2;

	/**
	 * Cookie name (scoped per install via COOKIEHASH).
	 *
	 * @return string
	 */
	public static function cookie_name() {
		return 'ums_olduser_' . COOKIEHASH;
	}

	/**
	 * Set the old-user cookie from a user id + their session token.
	 *
	 * @param int    $old_user_id Original user id.
	 * @param string $token       Original session token.
	 * @return void
	 */
	public static function set( $old_user_id, $token ) {
		$expiration = time() + self::EXPIRY;
		$cookie     = wp_generate_auth_cookie( $old_user_id, $expiration, 'logged_in', $token );

		self::write_cookie( $cookie, $expiration );
	}

	/**
	 * Clear the old-user cookie.
	 *
	 * @return void
	 */
	public static function clear() {
		self::write_cookie( ' ', time() - YEAR_IN_SECONDS );
		unset( $_COOKIE[ self::cookie_name() ] );
	}

	/**
	 * Get the raw cookie value, if present.
	 *
	 * @return string|false
	 */
	public static function get_raw() {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Auth cookie must not be sanitized; wp_validate_auth_cookie() handles its own integrity checking.
		return isset( $_COOKIE[ self::cookie_name() ] )
			? wp_unslash( $_COOKIE[ self::cookie_name() ] )
			: false;
		// phpcs:enable
	}

	/**
	 * Validate the cookie and return the original user id, or false.
	 *
	 * @return int|false
	 */
	public static function validate() {
		$raw = self::get_raw();
		if ( false === $raw ) {
			return false;
		}

		$user_id = wp_validate_auth_cookie( $raw, 'logged_in' );

		return $user_id ? (int) $user_id : false;
	}

	/**
	 * Extract the original session token stored in the cookie.
	 *
	 * @return string
	 */
	public static function token() {
		$raw = self::get_raw();
		if ( false === $raw ) {
			return '';
		}

		$parsed = wp_parse_auth_cookie( $raw, 'logged_in' );

		return ( $parsed && ! empty( $parsed['token'] ) ) ? $parsed['token'] : '';
	}

	/**
	 * Write the cookie at the standard logged-in cookie paths.
	 *
	 * @param string $value      Cookie value.
	 * @param int    $expiration Expiry timestamp.
	 * @return void
	 */
	private static function write_cookie( $value, $expiration ) {
		$secure  = is_ssl();
		$name    = self::cookie_name();
		$paths   = array_unique( array( COOKIEPATH, SITECOOKIEPATH ) );
		$options = array(
			'expires'  => $expiration,
			'domain'   => COOKIE_DOMAIN,
			'secure'   => $secure,
			'httponly' => true,
			'samesite' => 'Lax',
		);

		foreach ( $paths as $path ) {
			$options['path'] = $path ? $path : '/';
			setcookie( $name, $value, $options );
		}
	}
}
