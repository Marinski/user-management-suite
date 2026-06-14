<?php
/**
 * Deactivation routine.
 *
 * @package UserManagementSuite
 */

namespace Marinski\UserManagementSuite;

defined( 'ABSPATH' ) || exit;

/**
 * Runs on plugin deactivation: clears scheduled events. Does not delete data.
 */
class Deactivator {

	/**
	 * Deactivate.
	 *
	 * @return void
	 */
	public static function deactivate() {
		// Clear scheduled events owned by the plugin.
		wp_clear_scheduled_hook( 'ums_auto_delete_unverified' );

		do_action( 'ums_deactivate' );
	}
}
