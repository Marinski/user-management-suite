<?php
/**
 * Main plugin container.
 *
 * @package UserManagementSuite
 */

namespace Marinski\UserManagementSuite;

use Marinski\UserManagementSuite\Settings\SettingsRepository;
use Marinski\UserManagementSuite\Settings\Settings;
use Marinski\UserManagementSuite\Admin\Menu;
use Marinski\UserManagementSuite\Admin\Assets;
use Marinski\UserManagementSuite\Admin\LegacyImporter;
use Marinski\UserManagementSuite\Admin\SpamExporter;
use Marinski\UserManagementSuite\Modules\ModuleInterface;
use Marinski\UserManagementSuite\Modules\Registration\RegistrationModule;
use Marinski\UserManagementSuite\Modules\Roles\RolesModule;
use Marinski\UserManagementSuite\Modules\Switching\SwitchingModule;
use Marinski\UserManagementSuite\Modules\Verification\VerificationModule;
use Marinski\UserManagementSuite\Modules\ImportExport\ImportExportModule;
use Marinski\UserManagementSuite\Modules\Attribution\AttributionModule;
use Marinski\UserManagementSuite\Modules\Insights\InsightsModule;
use Marinski\UserManagementSuite\Modules\Notifications\NotificationsModule;
use Marinski\UserManagementSuite\Support\Privacy;
use Marinski\UserManagementSuite\Support\Schema;
use Marinski\UserManagementSuite\Cli\Commands;

defined( 'ABSPATH' ) || exit;

/**
 * Bootstraps the plugin and wires together settings, admin, and modules.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Settings repository.
	 *
	 * @var SettingsRepository
	 */
	private $settings;

	/**
	 * Registered modules, keyed by module id.
	 *
	 * @var ModuleInterface[]
	 */
	private $modules = array();

	/**
	 * Whether boot() has already run.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Get the singleton instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Private constructor (use instance()).
	 */
	private function __construct() {
		$this->settings = new SettingsRepository();
	}

	/**
	 * Expose the settings repository.
	 *
	 * @return SettingsRepository
	 */
	public function settings() {
		return $this->settings;
	}

	/**
	 * Get all registered modules.
	 *
	 * @return ModuleInterface[]
	 */
	public function modules() {
		return $this->modules;
	}

	/**
	 * Boot the plugin: load translations, register settings/admin, and enabled modules.
	 *
	 * @return void
	 */
	public function boot() {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		load_plugin_textdomain( 'user-management-suite', false, dirname( UMS_PLUGIN_BASENAME ) . '/languages' );

		// Cheap no-op once the tables match the shipped schema version.
		Schema::maybe_upgrade();

		Commands::register();

		$this->register_modules();

		// Privacy export/erase/policy hooks — needed on both admin and CLI/cron contexts.
		( new Privacy( $this->settings ) )->register();

		// Admin-only surfaces.
		if ( is_admin() ) {
			( new Settings( $this->settings, $this->modules ) )->register();
			( new Menu() )->register();
			( new Assets() )->register();
			( new LegacyImporter() )->register();
			( new SpamExporter() )->register();
		}

		// Register each module's hooks (modules decide front vs admin internally).
		foreach ( $this->modules as $module ) {
			if ( $module->is_enabled() ) {
				$module->register();
			}
		}

		do_action( 'ums_booted', $this );
	}

	/**
	 * Instantiate the available modules.
	 *
	 * @return void
	 */
	private function register_modules() {
		$modules = array(
			new RegistrationModule( $this->settings ),
			new VerificationModule( $this->settings ),
			new RolesModule( $this->settings ),
			new SwitchingModule( $this->settings ),
			new ImportExportModule( $this->settings ),
			new AttributionModule( $this->settings ),
			new InsightsModule( $this->settings ),
			new NotificationsModule( $this->settings ),
		);

		/**
		 * Filter the list of module instances before they are keyed and booted.
		 *
		 * @param ModuleInterface[] $modules Module instances.
		 */
		$modules = apply_filters( 'ums_modules', $modules );

		foreach ( $modules as $module ) {
			if ( $module instanceof ModuleInterface ) {
				$this->modules[ $module->id() ] = $module;
			}
		}
	}
}
