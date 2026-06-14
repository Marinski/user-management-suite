<?php
/**
 * User switching module.
 *
 * @package UserManagementSuite
 */

namespace Marinski\UserManagementSuite\Modules\Switching;

use Marinski\UserManagementSuite\Modules\AbstractModule;
use Marinski\UserManagementSuite\Settings\ProvidesSettings;
use Marinski\UserManagementSuite\Settings\SettingsRepository;
use Marinski\UserManagementSuite\Settings\Fields;

defined( 'ABSPATH' ) || exit;

/**
 * Instant switching between user accounts, with a secure switch-back that
 * resumes the original session.
 */
class SwitchingModule extends AbstractModule implements ProvidesSettings {

	const CAP_SWITCH = 'ums_switch_to_user';
	const CAP_OFF    = 'ums_switch_off';

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'switching';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return __( 'User Switching', 'user-management-suite' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function register() {
		add_filter( 'map_meta_cap', array( $this, 'map_meta_cap' ), 10, 4 );

		add_action( 'init', array( $this, 'handle_actions' ) );

		// UI surfaces.
		add_filter( 'user_row_actions', array( $this, 'user_row_action' ), 10, 2 );
		add_filter( 'ms_user_row_actions', array( $this, 'user_row_action' ), 10, 2 );
		add_action( 'edit_user_profile', array( $this, 'profile_button' ) );
		add_action( 'show_user_profile', array( $this, 'profile_button' ) );
		add_action( 'admin_bar_menu', array( $this, 'admin_bar' ), 100 );
		add_action( 'admin_notices', array( $this, 'switched_notice' ) );
		add_action( 'wp_footer', array( $this, 'switched_notice_front' ) );

		// Keep the old-user cookie tidy.
		add_action( 'wp_logout', array( Session::class, 'clear' ) );
	}

	// ---------------------------------------------------------------------
	// Capabilities.
	// ---------------------------------------------------------------------

	/**
	 * Map the plugin's meta capabilities to primitive caps.
	 *
	 * @param string[] $caps    Required primitive caps.
	 * @param string   $cap     Capability being checked.
	 * @param int      $user_id User performing the action.
	 * @param array    $args    Extra args ([0] = target user id for switch).
	 * @return string[]
	 */
	public function map_meta_cap( $caps, $cap, $user_id, $args ) {
		if ( self::CAP_SWITCH === $cap ) {
			$target = isset( $args[0] ) ? (int) $args[0] : 0;

			if ( ! $this->role_allowed( $user_id ) || ! $target || $target === (int) $user_id ) {
				return array( 'do_not_allow' );
			}

			return array( 'edit_users' );
		}

		if ( self::CAP_OFF === $cap ) {
			if ( ! $this->role_allowed( $user_id ) ) {
				return array( 'do_not_allow' );
			}

			return array( 'edit_users' );
		}

		return $caps;
	}

	/**
	 * Whether the given user's roles are permitted to switch (per settings).
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	private function role_allowed( $user_id ) {
		if ( is_multisite() && is_super_admin( $user_id ) ) {
			return true;
		}

		$enabled = (array) $this->settings->get( 'switching', 'enabled_for_roles', array( 'administrator' ) );
		if ( empty( $enabled ) ) {
			return true;
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}

		return (bool) array_intersect( (array) $user->roles, $enabled );
	}

	// ---------------------------------------------------------------------
	// Action routing.
	// ---------------------------------------------------------------------

	/**
	 * Handle switch_to / switch_back / switch_off requests.
	 *
	 * @return void
	 */
	public function handle_actions() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified per-branch below.
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';

		switch ( $action ) {
			case 'ums_switch_to_user':
				$this->do_switch_to();
				break;
			case 'ums_switch_back':
				$this->do_switch_back();
				break;
			case 'ums_switch_off':
				$this->do_switch_off();
				break;
		}
	}

	/**
	 * Switch into another account.
	 *
	 * @return void
	 */
	private function do_switch_to() {
		$target = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;

		check_admin_referer( 'ums_switch_to_user_' . $target );

		if ( ! current_user_can( self::CAP_SWITCH, $target ) ) {
			wp_die( esc_html__( 'You do not have permission to switch to this user.', 'user-management-suite' ) );
		}

		$old_user = wp_get_current_user();
		if ( ! $old_user->exists() ) {
			wp_die( esc_html__( 'You must be logged in to switch users.', 'user-management-suite' ) );
		}

		// Preserve the original user's session for a clean return.
		Session::set( $old_user->ID, wp_get_session_token() );

		$this->login_as( $target );

		/**
		 * Fires after switching into a user.
		 *
		 * @param int $target   New (switched-to) user id.
		 * @param int $old_user Original user id.
		 */
		do_action( 'ums_switched_to_user', $target, $old_user->ID );

		wp_safe_redirect( admin_url() );
		exit;
	}

	/**
	 * Return to the original account.
	 *
	 * @return void
	 */
	private function do_switch_back() {
		check_admin_referer( 'ums_switch_back' );

		$old_user_id = Session::validate();
		if ( ! $old_user_id ) {
			wp_die( esc_html__( 'Could not verify the original user.', 'user-management-suite' ) );
		}

		$old_token = Session::token();

		// Destroy the temporary switched session if one is active.
		if ( is_user_logged_in() ) {
			$current_token = wp_get_session_token();
			if ( $current_token ) {
				$manager = \WP_Session_Tokens::get_instance( get_current_user_id() );
				$manager->destroy( $current_token );
			}
		}

		Session::clear();

		// Restore the original user on their original session token.
		wp_clear_auth_cookie();
		wp_set_current_user( $old_user_id );
		wp_set_auth_cookie( $old_user_id, false, '', $old_token ? $old_token : '' );

		/**
		 * Fires after switching back to the original user.
		 *
		 * @param int $old_user_id Restored user id.
		 */
		do_action( 'ums_switched_back', $old_user_id );

		wp_safe_redirect( admin_url( 'users.php' ) );
		exit;
	}

	/**
	 * Switch off: log out but keep the ability to switch back.
	 *
	 * @return void
	 */
	private function do_switch_off() {
		check_admin_referer( 'ums_switch_off' );

		if ( ! current_user_can( self::CAP_OFF ) ) {
			wp_die( esc_html__( 'You do not have permission to switch off.', 'user-management-suite' ) );
		}

		$old_user = wp_get_current_user();
		if ( ! $old_user->exists() ) {
			wp_die( esc_html__( 'You must be logged in.', 'user-management-suite' ) );
		}

		Session::set( $old_user->ID, wp_get_session_token() );

		wp_clear_auth_cookie();
		wp_set_current_user( 0 );

		/**
		 * Fires after switching off.
		 *
		 * @param int $old_user_id Original user id.
		 */
		do_action( 'ums_switched_off', $old_user->ID );

		wp_safe_redirect( home_url() );
		exit;
	}

	/**
	 * Establish a fresh authenticated session as the given user.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	private function login_as( $user_id ) {
		wp_clear_auth_cookie();
		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, false );
	}

	// ---------------------------------------------------------------------
	// State helpers.
	// ---------------------------------------------------------------------

	/**
	 * The user we can switch back to, if currently switched. Null otherwise.
	 *
	 * @return \WP_User|null
	 */
	public function old_user() {
		$old_user_id = Session::validate();
		if ( ! $old_user_id ) {
			return null;
		}

		// If the cookie points at the current user, we are not in a switched state.
		if ( is_user_logged_in() && get_current_user_id() === (int) $old_user_id ) {
			return null;
		}

		$user = get_userdata( $old_user_id );

		return $user ? $user : null;
	}

	/**
	 * Build a nonce-protected switch URL.
	 *
	 * @param int $target Target user id.
	 * @return string
	 */
	private function switch_url( $target ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'  => 'ums_switch_to_user',
					'user_id' => $target,
				),
				wp_login_url()
			),
			'ums_switch_to_user_' . $target
		);
	}

	/**
	 * Build the switch-back URL.
	 *
	 * @return string
	 */
	private function switch_back_url() {
		return wp_nonce_url(
			add_query_arg( 'action', 'ums_switch_back', wp_login_url() ),
			'ums_switch_back'
		);
	}

	// ---------------------------------------------------------------------
	// UI.
	// ---------------------------------------------------------------------

	/**
	 * Add a "Switch To" row action on the Users table.
	 *
	 * @param array<string,string> $actions Existing actions.
	 * @param \WP_User             $user    Row user.
	 * @return array<string,string>
	 */
	public function user_row_action( $actions, $user ) {
		if ( current_user_can( self::CAP_SWITCH, $user->ID ) ) {
			$actions['ums_switch'] = '<a href="' . esc_url( $this->switch_url( $user->ID ) ) . '">'
				. esc_html__( 'Switch To', 'user-management-suite' ) . '</a>';
		}

		return $actions;
	}

	/**
	 * Add a "Switch To" button on the user edit screen.
	 *
	 * @param \WP_User $user Edited user.
	 * @return void
	 */
	public function profile_button( $user ) {
		if ( ! current_user_can( self::CAP_SWITCH, $user->ID ) ) {
			return;
		}
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th><?php esc_html_e( 'User Switching', 'user-management-suite' ); ?></th>
				<td>
					<a class="button button-secondary" href="<?php echo esc_url( $this->switch_url( $user->ID ) ); ?>">
						<?php
						/* translators: %s: user's display name. */
						printf( esc_html__( 'Switch to %s', 'user-management-suite' ), esc_html( $user->display_name ) );
						?>
					</a>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Add switch-back / switch-off items to the admin bar.
	 *
	 * @param \WP_Admin_Bar $bar Admin bar.
	 * @return void
	 */
	public function admin_bar( $bar ) {
		$old_user = $this->old_user();
		if ( ! $old_user ) {
			// Offer "Switch off" to eligible logged-in users.
			if ( is_user_logged_in() && current_user_can( self::CAP_OFF ) ) {
				$bar->add_node(
					array(
						'parent' => 'user-actions',
						'id'     => 'ums-switch-off',
						'title'  => __( 'Switch off', 'user-management-suite' ),
						'href'   => wp_nonce_url( add_query_arg( 'action', 'ums_switch_off', wp_login_url() ), 'ums_switch_off' ),
					)
				);
			}
			return;
		}

		$bar->add_node(
			array(
				'parent' => 'user-actions',
				'id'     => 'ums-switch-back',
				/* translators: %s: original user's display name. */
				'title'  => sprintf( __( 'Switch back to %s', 'user-management-suite' ), $old_user->display_name ),
				'href'   => $this->switch_back_url(),
			)
		);
	}

	/**
	 * Admin notice shown while switched.
	 *
	 * @return void
	 */
	public function switched_notice() {
		$old_user = $this->old_user();
		if ( ! $old_user ) {
			return;
		}

		$current = wp_get_current_user();
		?>
		<div class="notice notice-info">
			<p>
				<?php
				/* translators: %s: current (switched-to) user's display name. */
				printf( esc_html__( 'You are currently switched to %s.', 'user-management-suite' ), esc_html( $current->display_name ) );
				?>
				<a href="<?php echo esc_url( $this->switch_back_url() ); ?>">
					<?php
					/* translators: %s: original user's display name. */
					printf( esc_html__( 'Switch back to %s', 'user-management-suite' ), esc_html( $old_user->display_name ) );
					?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Front-end switch-back link (footer) while switched.
	 *
	 * @return void
	 */
	public function switched_notice_front() {
		$old_user = $this->old_user();
		if ( ! $old_user ) {
			return;
		}
		?>
		<p style="position:fixed;bottom:0;left:0;right:0;z-index:99999;margin:0;padding:8px 12px;background:#1d2327;color:#fff;text-align:center;font-size:13px;">
			<?php
			/* translators: %s: current user's display name. */
			printf( esc_html__( 'Switched to %s.', 'user-management-suite' ), esc_html( wp_get_current_user()->display_name ) );
			?>
			<a style="color:#72aee6;" href="<?php echo esc_url( $this->switch_back_url() ); ?>">
				<?php esc_html_e( 'Switch back', 'user-management-suite' ); ?>
			</a>
		</p>
		<?php
	}

	// ---------------------------------------------------------------------
	// Settings.
	// ---------------------------------------------------------------------

	/**
	 * {@inheritDoc}
	 */
	public function settings_tab_id() {
		return 'switching';
	}

	/**
	 * {@inheritDoc}
	 */
	public function settings_tab_label() {
		return __( 'Switching', 'user-management-suite' );
	}

	/**
	 * Render the Switching settings tab.
	 *
	 * @param SettingsRepository $settings Settings repository.
	 * @return void
	 */
	public function render_settings_tab( SettingsRepository $settings ) {
		Fields::row_start( __( 'Allowed roles', 'user-management-suite' ) );
		Fields::roles_checklist( 'switching', 'enabled_for_roles', (array) $settings->get( 'switching', 'enabled_for_roles', array( 'administrator' ) ) );
		echo '<p class="description">' . esc_html__( 'Only users with these roles (and the ability to edit users) can switch accounts. Super admins can always switch.', 'user-management-suite' ) . '</p>';
		Fields::row_end();
	}

	/**
	 * Sanitize the Switching settings section.
	 *
	 * @param array<string,mixed> $input   Submitted settings array.
	 * @param array<string,mixed> $current Current stored settings.
	 * @return array<string,mixed>
	 */
	public function sanitize_settings( array $input, array $current ) {
		if ( ! isset( $input['switching'] ) || ! is_array( $input['switching'] ) ) {
			return array();
		}

		$valid     = array_keys( wp_roles()->get_names() );
		$submitted = isset( $input['switching']['enabled_for_roles'] ) ? (array) $input['switching']['enabled_for_roles'] : array();
		$submitted = array_map( 'sanitize_key', $submitted );

		return array(
			'switching' => array(
				'enabled_for_roles' => array_values( array_intersect( $valid, $submitted ) ),
			),
		);
	}
}
