<?php
/**
 * Public helper functions.
 *
 * @package UserManagementSuite
 */

use Marinski\UserManagementSuite\Modules\Notifications\Preferences;
use Marinski\UserManagementSuite\Modules\Notifications\Registry;

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'ums_notification_allowed' ) ) {
	/**
	 * Whether a notification may be sent to a user.
	 *
	 * The gate any plugin can call before sending its own mail:
	 *
	 *     if ( ums_notification_allowed( 'my_weekly_digest', $user_id ) ) { ... }
	 *
	 * Always returns true when the type is unknown or the module is switched off,
	 * so adding this check can never stop mail that used to go out.
	 *
	 * @param string $type_id Notification type id.
	 * @param int    $user_id User id.
	 * @return bool
	 */
	function ums_notification_allowed( $type_id, $user_id ) {
		if ( ! class_exists( Preferences::class ) ) {
			return true;
		}

		return Preferences::allowed( $type_id, $user_id );
	}
}

if ( ! function_exists( 'ums_notification_types' ) ) {
	/**
	 * Every registered notification type, keyed by id.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	function ums_notification_types() {
		if ( ! class_exists( Registry::class ) ) {
			return array();
		}

		return Registry::types();
	}
}

if ( ! function_exists( 'ums_verification_resend_url' ) ) {
	/**
	 * Public URL where a user can (re)send the verification email.
	 *
	 * Prefers the site's canonical "confirm your email" page — it holds the
	 * [ums_resend_verification] shortcode and renders for logged-out users.
	 * Overridable with the `ums_resend_page_url` filter. Fails closed to the
	 * site home when no page exists.
	 *
	 * @return string
	 */
	function ums_verification_resend_url() {
		$url = (string) apply_filters( 'ums_resend_page_url', '' );
		if ( '' !== $url ) {
			return esc_url_raw( $url );
		}

		foreach ( array( 'confirm-your-email', 'verify-email' ) as $slug ) {
			$page = get_page_by_path( $slug );
			if ( $page ) {
				$pid = (int) $page->ID;

				// Site is multilingual (Polylang): resolve the default-language
				// page to its translated sibling so users stay in their locale.
				if ( function_exists( 'pll_get_post' ) && function_exists( 'pll_current_language' ) ) {
					$translated = pll_get_post( $pid, (string) pll_current_language() );
					if ( $translated ) {
						$pid = (int) $translated;
					}
				}

				return (string) get_permalink( $pid );
			}
		}

		return home_url( '/' );
	}
}
