<?php
/**
 * Activation routine.
 *
 * @package UserManagementSuite
 */

namespace Marinski\UserManagementSuite;

use Marinski\UserManagementSuite\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Runs on plugin activation: seeds default settings.
 */
class Activator {

	/**
	 * Activate.
	 *
	 * @return void
	 */
	public static function activate() {
		if ( false === get_option( SettingsRepository::OPTION_KEY, false ) ) {
			add_option( SettingsRepository::OPTION_KEY, SettingsRepository::defaults() );
		}

		// Per-module activation (cron schedules, tables) is wired in later phases.
		do_action( 'ums_activate' );
	}
}
