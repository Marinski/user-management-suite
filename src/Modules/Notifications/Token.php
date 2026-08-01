<?php
/**
 * Signed unsubscribe tokens.
 *
 * @package UserManagementSuite
 */

namespace Marinski\UserManagementSuite\Modules\Notifications;

defined( 'ABSPATH' ) || exit;

/**
 * Mints and verifies the tokens that let someone manage their preferences from
 * an email without logging in.
 *
 * Requiring a login to unsubscribe is how senders end up in spam folders: the
 * recipient who cannot find the off switch presses "report spam" instead, and
 * that costs the whole sending domain.
 */
class Token {

	/** Tokens older than this stop working. */
	const LIFETIME = 12 * MONTH_IN_SECONDS;

	/**
	 * Build a token for a user.
	 *
	 * @param int    $user_id User id.
	 * @param string $scope   Category to act on, or '' for everything optional.
	 * @return string Empty string when the user does not exist.
	 */
	public static function create( $user_id, $scope = '' ) {
		$user = get_userdata( (int) $user_id );

		if ( ! $user ) {
			return '';
		}

		$expires = time() + self::LIFETIME;
		$payload = implode( '|', array( (int) $user_id, sanitize_key( $scope ), $expires ) );

		return self::encode( $payload ) . '.' . self::sign( $payload, $user );
	}

	/**
	 * Verify a token and return what it authorises.
	 *
	 * @param string $token Token from the request.
	 * @return array{user_id:int,scope:string}|null Null when invalid or expired.
	 */
	public static function verify( $token ) {
		$token = (string) $token;

		if ( '' === $token || strlen( $token ) > 512 || false === strpos( $token, '.' ) ) {
			return null;
		}

		list( $encoded, $signature ) = explode( '.', $token, 2 );

		$payload = self::decode( $encoded );

		if ( '' === $payload ) {
			return null;
		}

		$parts = explode( '|', $payload );

		if ( 3 !== count( $parts ) ) {
			return null;
		}

		list( $user_id, $scope, $expires ) = $parts;

		$user = get_userdata( (int) $user_id );

		if ( ! $user ) {
			return null;
		}

		if ( ! hash_equals( self::sign( $payload, $user ), (string) $signature ) ) {
			return null;
		}

		if ( (int) $expires < time() ) {
			return null;
		}

		return array(
			'user_id' => (int) $user_id,
			'scope'   => sanitize_key( $scope ),
		);
	}

	/**
	 * The signature for a payload.
	 *
	 * The user's current email address is part of the signed material, so a
	 * token stops working once the address it was sent to is no longer theirs.
	 *
	 * @param string   $payload Payload string.
	 * @param \WP_User $user    User the token belongs to.
	 * @return string
	 */
	private static function sign( $payload, \WP_User $user ) {
		return hash_hmac( 'sha256', $payload . '|' . $user->user_email, wp_salt( 'auth' ) );
	}

	/**
	 * URL-safe base64.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function encode( $value ) {
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Transport encoding, not obfuscation.
	}

	/**
	 * Decode URL-safe base64.
	 *
	 * @param string $value Encoded value.
	 * @return string
	 */
	private static function decode( $value ) {
		$decoded = base64_decode( strtr( $value, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Transport encoding, not obfuscation.

		return is_string( $decoded ) ? $decoded : '';
	}
}
