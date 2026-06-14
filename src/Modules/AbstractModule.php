<?php
/**
 * Shared base for feature modules.
 *
 * @package UserManagementSuite
 */

namespace Marinski\UserManagementSuite\Modules;

use Marinski\UserManagementSuite\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Provides settings access and the enabled-check shared by all modules.
 */
abstract class AbstractModule implements ModuleInterface {

	/**
	 * Settings repository.
	 *
	 * @var SettingsRepository
	 */
	protected $settings;

	/**
	 * Constructor.
	 *
	 * @param SettingsRepository $settings Settings repository.
	 */
	public function __construct( SettingsRepository $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Default enabled-check reads the "modules" section by module id.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		return $this->settings->is_module_enabled( $this->id() );
	}
}
