<?php
/**
 * Activation routine.
 *
 * @package UserManagementSuite
 */

namespace Marinski\UserManagementSuite;

use Marinski\UserManagementSuite\Settings\SettingsRepository;
use Marinski\UserManagementSuite\Support\Schema;

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

		Schema::install();

		do_action( 'ums_activate' );
	}
}
