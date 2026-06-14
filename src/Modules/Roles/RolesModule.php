<?php
/**
 * Multiple roles module.
 *
 * @package UserManagementSuite
 */

namespace Marinski\UserManagementSuite\Modules\Roles;

use Marinski\UserManagementSuite\Modules\AbstractModule;
use Marinski\UserManagementSuite\Settings\ProvidesSettings;
use Marinski\UserManagementSuite\Settings\SettingsRepository;
use Marinski\UserManagementSuite\Settings\Fields;

defined( 'ABSPATH' ) || exit;

/**
 * Lets administrators assign multiple roles to a user via a checklist that
 * replaces WordPress's single-role dropdown.
 */
class RolesModule extends AbstractModule implements ProvidesSettings {

	const NONCE = 'ums_roles_nonce';
	const FIELD = 'ums_roles';

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'roles';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return __( 'Multiple Roles', 'user-management-suite' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function register() {
		add_action( 'show_user_profile', array( $this, 'render_checklist' ) );
		add_action( 'edit_user_profile', array( $this, 'render_checklist' ) );
		add_action( 'user_new_form', array( $this, 'render_checklist' ) );

		add_action( 'profile_update', array( $this, 'save_roles' ) );
		add_action( 'user_register', array( $this, 'save_roles' ) );

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Capability required to manage roles.
	 *
	 * @return bool
	 */
	private function can_manage() {
		return current_user_can( 'promote_users' );
	}

	/**
	 * Roles that may be assigned, honoring the settings restriction.
	 *
	 * @return array<string,string> slug => display name.
	 */
	private function assignable_roles() {
		$all      = wp_roles()->get_names();
		$restrict = (array) $this->settings->get( 'roles', 'assignable_roles', array() );

		if ( empty( $restrict ) ) {
			return $all;
		}

		return array_intersect_key( $all, array_flip( $restrict ) );
	}

	/**
	 * Render the role checklist on user screens.
	 *
	 * @param \WP_User|string $user User object (edit/profile) or context string (add-new).
	 * @return void
	 */
	public function render_checklist( $user ) {
		if ( ! $this->can_manage() ) {
			return;
		}

		$current_roles = ( $user instanceof \WP_User ) ? (array) $user->roles : array();
		$roles         = $this->assignable_roles();
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th><label><?php esc_html_e( 'Roles', 'user-management-suite' ); ?></label></th>
				<td>
					<?php wp_nonce_field( self::NONCE, self::NONCE ); ?>
					<fieldset class="ums-roles-checklist">
						<?php foreach ( $roles as $slug => $name ) : ?>
							<label style="display:block;margin-bottom:4px;">
								<input type="checkbox" name="<?php echo esc_attr( self::FIELD ); ?>[]"
									value="<?php echo esc_attr( $slug ); ?>"
									<?php checked( in_array( $slug, $current_roles, true ) ); ?> />
								<?php echo esc_html( $name ); ?>
							</label>
						<?php endforeach; ?>
					</fieldset>
					<p class="description"><?php esc_html_e( 'Assign one or more roles. This replaces the default single-role selector.', 'user-management-suite' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Persist the selected roles for a user.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	public function save_roles( $user_id ) {
		if ( ! $this->can_manage() ) {
			return;
		}

		if ( ! isset( $_POST[ self::NONCE ] )
			|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) ) {
			return;
		}

		// The role field must be present (even if empty) to act.
		if ( ! isset( $_POST[ self::FIELD ] ) ) {
			return;
		}

		$submitted = array_map( 'sanitize_key', (array) wp_unslash( $_POST[ self::FIELD ] ) );
		$allowed   = array_keys( $this->assignable_roles() );
		$new_roles = array_values( array_intersect( $allowed, $submitted ) );

		// Never leave a user role-less.
		if ( empty( $new_roles ) ) {
			return;
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		// Guard: do not remove the administrator role from the last administrator.
		if ( in_array( 'administrator', (array) $user->roles, true )
			&& ! in_array( 'administrator', $new_roles, true )
			&& $this->is_last_administrator( $user_id ) ) {
			return;
		}

		// Replace the user's roles with the new set.
		foreach ( (array) $user->roles as $role ) {
			$user->remove_role( $role );
		}
		foreach ( $new_roles as $role ) {
			$user->add_role( $role );
		}
	}

	/**
	 * Whether the given user is the only administrator on the site.
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	private function is_last_administrator( $user_id ) {
		$admins = get_users(
			array(
				'role'   => 'administrator',
				'fields' => 'ID',
				'number' => 2,
			)
		);

		return count( $admins ) <= 1 && in_array( (int) $user_id, array_map( 'intval', $admins ), true );
	}

	/**
	 * Enqueue the script that hides the native role dropdown on user screens.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue( $hook ) {
		if ( ! in_array( $hook, array( 'user-edit.php', 'profile.php', 'user-new.php' ), true ) ) {
			return;
		}

		if ( ! $this->can_manage() ) {
			return;
		}

		wp_enqueue_script(
			'ums-roles',
			UMS_PLUGIN_URL . 'assets/admin/js/roles.js',
			array(),
			UMS_VERSION,
			true
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function settings_tab_id() {
		return 'roles';
	}

	/**
	 * {@inheritDoc}
	 */
	public function settings_tab_label() {
		return __( 'Roles', 'user-management-suite' );
	}

	/**
	 * Render the Roles settings tab.
	 *
	 * @param SettingsRepository $settings Settings repository.
	 * @return void
	 */
	public function render_settings_tab( SettingsRepository $settings ) {
		Fields::row_start( __( 'Assignable roles', 'user-management-suite' ) );
		Fields::roles_checklist( 'roles', 'assignable_roles', (array) $settings->get( 'roles', 'assignable_roles', array() ) );
		echo '<p class="description">' . esc_html__( 'Limit which roles can be assigned via the checklist. Leave all unchecked to allow every role.', 'user-management-suite' ) . '</p>';
		Fields::row_end();
	}

	/**
	 * Sanitize the Roles settings section.
	 *
	 * @param array<string,mixed> $input   Submitted settings array.
	 * @param array<string,mixed> $current Current stored settings.
	 * @return array<string,mixed>
	 */
	public function sanitize_settings( array $input, array $current ) {
		if ( ! isset( $input['roles'] ) || ! is_array( $input['roles'] ) ) {
			return array();
		}

		$valid     = array_keys( wp_roles()->get_names() );
		$submitted = isset( $input['roles']['assignable_roles'] ) ? (array) $input['roles']['assignable_roles'] : array();
		$submitted = array_map( 'sanitize_key', $submitted );

		return array(
			'roles' => array(
				'assignable_roles' => array_values( array_intersect( $valid, $submitted ) ),
			),
		);
	}
}
