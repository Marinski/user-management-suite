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
