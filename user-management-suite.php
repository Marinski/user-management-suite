<?php
/**
 * Plugin Name:       User Management Suite
 * Plugin URI:        https://github.com/Marinski/user-management-suite
 * Description:       A complete, modular user-management toolkit: registration tracking, email verification, multiple roles per user, and instant user switching — all from one settings page.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Marinski
 * Author URI:        https://github.com/Marinski
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       user-management-suite
 * Domain Path:       /languages
 *
 * @package UserManagementSuite
 */

namespace Marinski\UserManagementSuite;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ---------------------------------------------------------------------------
// Constants.
// ---------------------------------------------------------------------------
define( 'UMS_VERSION', '1.0.0' );
define( 'UMS_PLUGIN_FILE', __FILE__ );
define( 'UMS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'UMS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'UMS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// ---------------------------------------------------------------------------
// Autoloader (PSR-4-ish: Marinski\UserManagementSuite\Foo\Bar -> src/Foo/Bar.php).
// ---------------------------------------------------------------------------
spl_autoload_register(
	static function ( $class ) {
		$prefix = __NAMESPACE__ . '\\';
		$len    = strlen( $prefix );

		if ( 0 !== strncmp( $prefix, $class, $len ) ) {
			return;
		}

		$relative = substr( $class, $len );
		$path     = UMS_PLUGIN_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);

// ---------------------------------------------------------------------------
// Public helper functions (available even when their module is switched off,
// so calling code never has to guard for it).
// ---------------------------------------------------------------------------
require_once UMS_PLUGIN_DIR . 'src/functions.php';

// ---------------------------------------------------------------------------
// Lifecycle hooks.
// ---------------------------------------------------------------------------
register_activation_hook( __FILE__, array( Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Deactivator::class, 'deactivate' ) );

// ---------------------------------------------------------------------------
// Boot.
// ---------------------------------------------------------------------------
add_action(
	'plugins_loaded',
	static function () {
		Plugin::instance()->boot();
	}
);
