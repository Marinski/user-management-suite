<?php
/**
 * Contract every feature module must implement.
 *
 * @package UserManagementSuite
 */

namespace Marinski\UserManagementSuite\Modules;

defined( 'ABSPATH' ) || exit;

/**
 * A self-contained, toggleable feature area.
 */
interface ModuleInterface {

	/**
	 * Unique, stable module id (matches the key under the "modules" setting).
	 *
	 * @return string
	 */
	public function id();

	/**
	 * Human-readable label for the settings UI.
	 *
	 * @return string
	 */
	public function label();

	/**
	 * Whether this module is enabled in settings.
	 *
	 * @return bool
	 */
	public function is_enabled();

	/**
	 * Register the module's WordPress hooks. Only called when enabled.
	 *
	 * @return void
	 */
	public function register();
}
