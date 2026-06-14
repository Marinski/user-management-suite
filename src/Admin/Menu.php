<?php
/**
 * Plugin-list integration (Settings action link).
 *
 * @package UserManagementSuite
 */

namespace Marinski\UserManagementSuite\Admin;

use Marinski\UserManagementSuite\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a "Settings" link on the Plugins screen row.
 */
class Menu {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'plugin_action_links_' . UMS_PLUGIN_BASENAME, array( $this, 'action_links' ) );
	}

	/**
	 * Prepend a Settings link.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public function action_links( $links ) {
		$url      = add_query_arg( 'page', Settings::PAGE_SLUG, admin_url( 'users.php' ) );
		$settings = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'user-management-suite' ) . '</a>';
		array_unshift( $links, $settings );

		return $links;
	}
}
