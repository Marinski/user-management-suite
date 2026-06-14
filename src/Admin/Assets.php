<?php
/**
 * Admin asset loading (scoped to the plugin's own screens).
 *
 * @package UserManagementSuite
 */

namespace Marinski\UserManagementSuite\Admin;

use Marinski\UserManagementSuite\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues admin CSS/JS only where needed.
 */
class Assets {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueue assets on the settings page and the Users screens.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue( $hook ) {
		$is_settings = ( 'users_page_' . Settings::PAGE_SLUG ) === $hook;
		$is_users    = in_array( $hook, array( 'users.php', 'user-edit.php', 'profile.php', 'user-new.php' ), true );

		if ( ! $is_settings && ! $is_users ) {
			return;
		}

		$css = UMS_PLUGIN_DIR . 'assets/admin/css/admin.css';
		if ( is_readable( $css ) ) {
			wp_enqueue_style(
				'ums-admin',
				UMS_PLUGIN_URL . 'assets/admin/css/admin.css',
				array(),
				UMS_VERSION
			);
		}
	}
}
