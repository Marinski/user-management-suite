<?php
/**
 * Implemented by modules that contribute a settings tab.
 *
 * @package UserManagementSuite
 */

namespace Marinski\UserManagementSuite\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * A module that exposes its own tab on the unified settings page.
 */
interface ProvidesSettings {

	/**
	 * Tab id (used in the URL: ?page=...&tab=<id>). Usually the module id.
	 *
	 * @return string
	 */
	public function settings_tab_id();

	/**
	 * Tab label.
	 *
	 * @return string
	 */
	public function settings_tab_label();

	/**
	 * Render the tab body (inside the shared <form>).
	 *
	 * @param SettingsRepository $settings Settings repository.
	 * @return void
	 */
	public function render_settings_tab( SettingsRepository $settings );

	/**
	 * Sanitize this module's settings section(s) from submitted input.
	 *
	 * @param array<string,mixed> $input   Raw submitted ums_settings array.
	 * @param array<string,mixed> $current Current (defaults-merged) settings.
	 * @return array<string,mixed> The sanitized section(s) to merge back in.
	 */
	public function sanitize_settings( array $input, array $current );
}
