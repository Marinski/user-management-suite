<?php
/**
 * Unified, tabbed settings page.
 *
 * @package UserManagementSuite
 */

namespace Marinski\UserManagementSuite\Settings;

use Marinski\UserManagementSuite\Modules\ModuleInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the single options page (Users -> User Management) with one tab
 * per built-in section plus one tab per module that implements ProvidesSettings.
 */
class Settings {

	const PAGE_SLUG    = 'user-management-suite';
	const OPTION_GROUP = 'ums_settings_group';
	const CAPABILITY   = 'manage_options';

	/**
	 * Settings repository.
	 *
	 * @var SettingsRepository
	 */
	private $settings;

	/**
	 * All module instances (enabled or not).
	 *
	 * @var ModuleInterface[]
	 */
	private $modules;

	/**
	 * Constructor.
	 *
	 * @param SettingsRepository $settings Settings repository.
	 * @param ModuleInterface[]  $modules  Module instances.
	 */
	public function __construct( SettingsRepository $settings, array $modules ) {
		$this->settings = $settings;
		$this->modules  = $modules;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_init', array( $this, 'register_setting' ) );
	}

	/**
	 * Add the submenu page under Users.
	 *
	 * @return void
	 */
	public function add_page() {
		add_submenu_page(
			'users.php',
			__( 'User Management Suite', 'user-management-suite' ),
			__( 'User Management', 'user-management-suite' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Register the single option + sanitize callback.
	 *
	 * @return void
	 */
	public function register_setting() {
		register_setting(
			self::OPTION_GROUP,
			SettingsRepository::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => SettingsRepository::defaults(),
			)
		);
	}

	/**
	 * Tabs: id => label. Built-ins plus module-provided.
	 *
	 * @return array<string,string>
	 */
	private function tabs() {
		$tabs = array( 'general' => __( 'General', 'user-management-suite' ) );

		foreach ( $this->modules as $module ) {
			if ( $module instanceof ProvidesSettings ) {
				$tabs[ $module->settings_tab_id() ] = $module->settings_tab_label();
			}
		}

		$tabs['tools']  = __( 'Tools', 'user-management-suite' );
		$tabs['status'] = __( 'Status', 'user-management-suite' );

		return $tabs;
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'user-management-suite' ) );
		}

		$tabs = $this->tabs();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab switch.
		$current = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';
		if ( ! isset( $tabs[ $current ] ) ) {
			$current = 'general';
		}
		?>
		<div class="wrap ums-settings">
			<h1><?php esc_html_e( 'User Management Suite', 'user-management-suite' ); ?></h1>

			<h2 class="nav-tab-wrapper">
				<?php foreach ( $tabs as $id => $label ) : ?>
					<a class="nav-tab <?php echo $id === $current ? 'nav-tab-active' : ''; ?>"
						href="
						<?php
						echo esc_url(
							add_query_arg(
								array(
									'page' => self::PAGE_SLUG,
									'tab'  => $id,
								),
								admin_url( 'users.php' )
							)
						);
						?>
								">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</h2>

			<?php if ( 'status' === $current ) : ?>
				<?php $this->render_status_tab(); ?>
			<?php else : ?>
				<form action="options.php" method="post">
					<?php settings_fields( self::OPTION_GROUP ); ?>
					<table class="form-table" role="presentation"><tbody>
						<?php $this->render_tab_body( $current ); ?>
					</tbody></table>
					<?php submit_button(); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the body of a given tab.
	 *
	 * @param string $tab Tab id.
	 * @return void
	 */
	private function render_tab_body( $tab ) {
		if ( 'general' === $tab ) {
			$this->render_general_tab();
			return;
		}

		if ( 'tools' === $tab ) {
			/**
			 * Modules hook here to add tool rows (e.g. CSV export, legacy import).
			 *
			 * @param SettingsRepository $settings Settings repository.
			 */
			do_action( 'ums_render_tools_tab', $this->settings );
			return;
		}

		foreach ( $this->modules as $module ) {
			if ( $module instanceof ProvidesSettings && $module->settings_tab_id() === $tab ) {
				$module->render_settings_tab( $this->settings );
				return;
			}
		}
	}

	/**
	 * Render the General tab (module toggles + global options).
	 *
	 * @return void
	 */
	private function render_general_tab() {
		echo '<tr><th scope="row">' . esc_html__( 'Modules', 'user-management-suite' ) . '</th><td><fieldset>';
		foreach ( $this->modules as $module ) {
			Fields::checkbox(
				'modules',
				$module->id(),
				$this->settings->is_module_enabled( $module->id() ),
				$module->label()
			);
			echo '<br />';
		}
		echo '</fieldset><p class="description">' . esc_html__( 'Enable only the features you need.', 'user-management-suite' ) . '</p></td></tr>';

		Fields::row_start( __( 'Data cleanup', 'user-management-suite' ) );
		Fields::checkbox(
			'general',
			'delete_data_on_uninstall',
			$this->settings->get( 'general', 'delete_data_on_uninstall', false ),
			__( 'Delete all plugin data when the plugin is uninstalled', 'user-management-suite' ),
			__( 'When unchecked, your settings and tracked user data are preserved after uninstall.', 'user-management-suite' )
		);
		Fields::row_end();
	}

	/**
	 * Render the read-only Status tab.
	 *
	 * @return void
	 */
	private function render_status_tab() {
		echo '<table class="widefat striped" style="max-width:640px;margin-top:1em;"><tbody>';
		$rows = array(
			__( 'Plugin version', 'user-management-suite' ) => UMS_VERSION,
			__( 'WordPress version', 'user-management-suite' ) => get_bloginfo( 'version' ),
			__( 'PHP version', 'user-management-suite' ) => PHP_VERSION,
			__( 'Multisite', 'user-management-suite' )   => is_multisite() ? __( 'Yes', 'user-management-suite' ) : __( 'No', 'user-management-suite' ),
		);
		foreach ( $rows as $label => $value ) {
			echo '<tr><td><strong>' . esc_html( $label ) . '</strong></td><td>' . esc_html( $value ) . '</td></tr>';
		}
		foreach ( $this->modules as $module ) {
			echo '<tr><td>' . esc_html( $module->label() ) . '</td><td>'
				. ( $module->is_enabled() ? esc_html__( 'Enabled', 'user-management-suite' ) : esc_html__( 'Disabled', 'user-management-suite' ) )
				. '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Sanitize callback for the whole option. Merges the submitted tab's
	 * sections onto the existing stored settings so other tabs are preserved.
	 *
	 * @param mixed $input Raw submitted value.
	 * @return array<string,mixed>
	 */
	public function sanitize( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$current = $this->settings->all();
		$out     = $current;

		// Module toggles (General tab).
		if ( isset( $input['modules'] ) && is_array( $input['modules'] ) ) {
			foreach ( array_keys( $current['modules'] ) as $mid ) {
				$out['modules'][ $mid ] = ! empty( $input['modules'][ $mid ] );
			}
		}

		// Global options (General tab).
		if ( isset( $input['general'] ) && is_array( $input['general'] ) ) {
			$out['general']['delete_data_on_uninstall'] = ! empty( $input['general']['delete_data_on_uninstall'] );
		}

		// Each module sanitizes its own section(s) when present in $input.
		foreach ( $this->modules as $module ) {
			if ( $module instanceof ProvidesSettings ) {
				$sections = $module->sanitize_settings( $input, $current );
				foreach ( (array) $sections as $section_key => $section_value ) {
					$out[ $section_key ] = $section_value;
				}
			}
		}

		add_settings_error( SettingsRepository::OPTION_KEY, 'ums_saved', __( 'Settings saved.', 'user-management-suite' ), 'updated' );

		return $out;
	}
}
