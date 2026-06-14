<?php
/**
 * Shared security / sanitization helpers.
 *
 * @package UserManagementSuite
 */

namespace Marinski\UserManagementSuite\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Small reusable helpers for input handling and client IP retrieval.
 */
class Security {

	/**
	 * Get the client IP address, optionally anonymized.
	 *
	 * Only reads REMOTE_ADDR (the connecting peer) to avoid trusting spoofable
	 * proxy headers. Sites behind a trusted proxy can filter `ums_client_ip`.
	 *
	 * @param bool $anonymize Whether to mask the last octet / segment.
	 * @return string
	 */
	public static function client_ip( $anonymize = false ) {
		$ip = '';

		if ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		$ip = (string) apply_filters( 'ums_client_ip', $ip );

		if ( '' === $ip || false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return '';
		}

		if ( $anonymize ) {
			$ip = self::anonymize_ip( $ip );
		}

		return $ip;
	}

	/**
	 * Mask the host portion of an IP for privacy (GDPR-friendly).
	 *
	 * @param string $ip IP address.
	 * @return string
	 */
	public static function anonymize_ip( $ip ) {
		if ( false !== strpos( $ip, ':' ) ) {
			// IPv6: keep first 3 hextets.
			$parts = explode( ':', $ip );
			$parts = array_slice( $parts, 0, 3 );

			return implode( ':', $parts ) . '::';
		}

		// IPv4: zero the last octet.
		$parts = explode( '.', $ip );
		if ( 4 === count( $parts ) ) {
			$parts[3] = '0';

			return implode( '.', $parts );
		}

		return $ip;
	}

	/**
	 * Normalize a textarea of one-value-per-line into a clean array.
	 *
	 * @param string $raw       Raw textarea value.
	 * @param string $sanitizer Callback name applied to each line.
	 * @return string[]
	 */
	public static function lines_to_array( $raw, $sanitizer = 'sanitize_text_field' ) {
		$lines = preg_split( '/\r\n|\r|\n/', (string) $raw );
		$out   = array();

		foreach ( (array) $lines as $line ) {
			$line = is_callable( $sanitizer ) ? call_user_func( $sanitizer, $line ) : sanitize_text_field( $line );
			$line = trim( $line );
			if ( '' !== $line ) {
				$out[] = $line;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Convert an array back into a textarea string (one item per line).
	 *
	 * @param mixed $value Array (or anything else).
	 * @return string
	 */
	public static function array_to_lines( $value ) {
		return is_array( $value ) ? implode( "\n", $value ) : '';
	}
}
