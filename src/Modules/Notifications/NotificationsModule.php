<?php
/**
 * Notification preferences module.
 *
 * @package UserManagementSuite
 */

namespace Marinski\UserManagementSuite\Modules\Notifications;

use Marinski\UserManagementSuite\Modules\AbstractModule;
use Marinski\UserManagementSuite\Modules\Notifications\Adapters\CoreAdapter;
use Marinski\UserManagementSuite\Modules\Notifications\Adapters\WooCommerceAdapter;
use Marinski\UserManagementSuite\Settings\Fields;
use Marinski\UserManagementSuite\Settings\ProvidesSettings;
use Marinski\UserManagementSuite\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Gives users a say in what this site emails them, and gives the site a record
 * of what it offered.
 */
class NotificationsModule extends AbstractModule implements ProvidesSettings {

	const NONCE_PROFILE = 'ums_notify_profile';

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'notifications';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return __( 'Notification Preferences', 'user-management-suite' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function register() {
		if ( $this->settings->get( 'notifications', 'enable_core', true ) ) {
			( new CoreAdapter() )->register();
		}

		if ( $this->settings->get( 'notifications', 'enable_woocommerce', true ) ) {
			( new WooCommerceAdapter() )->register();
		}

		if ( is_admin() ) {
			add_action( 'show_user_profile', array( $this, 'render_profile_panel' ) );
			add_action( 'edit_user_profile', array( $this, 'render_profile_panel' ) );
			add_action( 'personal_options_update', array( $this, 'save_profile_panel' ) );
			add_action( 'edit_user_profile_update', array( $this, 'save_profile_panel' ) );

			add_filter( 'manage_users_columns', array( $this, 'add_column' ) );
			add_filter( 'manage_users_custom_column', array( $this, 'render_column' ), 10, 3 );
		}
	}

	/**
	 * Render the preference panel on a user profile.
	 *
	 * @param \WP_User $user User being edited.
	 * @return void
	 */
	public function render_profile_panel( $user ) {
		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}

		$grouped = Registry::grouped();

		if ( array() === $grouped ) {
			return;
		}

		$stored = Preferences::stored( $user->ID );

		wp_nonce_field( self::NONCE_PROFILE, self::NONCE_PROFILE );
		?>
		<h2><?php esc_html_e( 'User Management — Notifications', 'user-management-suite' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Everything this site can send. Items marked required are account or transaction records and cannot be switched off.', 'user-management-suite' ); ?>
		</p>
		<table class="form-table" role="presentation">
			<?php foreach ( $grouped as $group => $categories ) : ?>
				<tr>
					<th scope="row"><?php echo esc_html( $group ); ?></th>
					<td>
						<?php foreach ( $categories as $category => $types ) : ?>
							<p><strong><?php echo esc_html( Registry::category_label( $category ) ); ?></strong></p>
							<fieldset style="margin-bottom:12px;">
								<?php foreach ( $types as $type ) : ?>
									<?php
									$allowed = Preferences::allowed( $type['id'], $user->ID, $stored );
									$locked  = ! $type['user_optout_allowed'];
									?>
									<label style="display:block;margin-bottom:4px;">
										<input type="checkbox"
											name="ums_notify[<?php echo esc_attr( $type['id'] ); ?>]"
											value="1"
											<?php checked( $allowed ); ?>
											<?php disabled( $locked ); ?> />
										<?php echo esc_html( $type['label'] ); ?>
										<?php if ( $locked ) : ?>
											<span class="description">— <?php esc_html_e( 'required', 'user-management-suite' ); ?></span>
										<?php endif; ?>
									</label>
								<?php endforeach; ?>
							</fieldset>
						<?php endforeach; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</table>
		<?php
	}

	/**
	 * Save the preference panel.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	public function save_profile_panel( $user_id ) {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		if ( ! isset( $_POST[ self::NONCE_PROFILE ] )
			|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_PROFILE ] ) ), self::NONCE_PROFILE ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		$submitted = isset( $_POST['ums_notify'] ) ? wp_unslash( $_POST['ums_notify'] ) : array();
		$submitted = is_array( $submitted ) ? $submitted : array();

		$prefs = array();

		/*
		 * Unchecked boxes are absent from the POST body, so the stored value has to
		 * be derived from the full list of optional types rather than from what was
		 * submitted — otherwise switching something off would simply be forgotten.
		 */
		foreach ( Registry::types() as $id => $type ) {
			if ( ! $type['user_optout_allowed'] ) {
				continue;
			}

			$prefs[ $id ] = ! empty( $submitted[ $id ] );
		}

		Preferences::save( $user_id, $prefs );
	}

	/**
	 * Add the notification summary column.
	 *
	 * @param array<string,string> $columns Columns.
	 * @return array<string,string>
	 */
	public function add_column( $columns ) {
		$columns['ums_notifications'] = __( 'Emails', 'user-management-suite' );

		return $columns;
	}

	/**
	 * Render the notification summary column.
	 *
	 * @param string $output      Default output.
	 * @param string $column_name Column id.
	 * @param int    $user_id     User id.
	 * @return string
	 */
	public function render_column( $output, $column_name, $user_id ) {
		if ( 'ums_notifications' !== $column_name ) {
			return $output;
		}

		$summary = Preferences::summary( $user_id );

		if ( 0 === $summary['on'] && 0 === $summary['off'] ) {
			return '&mdash;';
		}

		if ( 0 === $summary['off'] ) {
			return esc_html__( 'All opted in', 'user-management-suite' );
		}

		return esc_html(
			sprintf(
				/* translators: 1: number opted out, 2: number of optional notification types. */
				__( '%1$d of %2$d off', 'user-management-suite' ),
				$summary['off'],
				$summary['on'] + $summary['off']
			)
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function settings_tab_id() {
		return 'notifications';
	}

	/**
	 * {@inheritDoc}
	 */
	public function settings_tab_label() {
		return __( 'Notifications', 'user-management-suite' );
	}

	/**
	 * Render the Notifications settings tab.
	 *
	 * @param SettingsRepository $settings Settings repository.
	 * @return void
	 */
	public function render_settings_tab( SettingsRepository $settings ) {
		Fields::row_start( __( 'Sources', 'user-management-suite' ) );
		Fields::checkbox(
			'notifications',
			'enable_core',
			$settings->get( 'notifications', 'enable_core', true ),
			__( 'List the notifications WordPress itself sends', 'user-management-suite' )
		);
		echo '<br />';
		Fields::checkbox(
			'notifications',
			'enable_woocommerce',
			$settings->get( 'notifications', 'enable_woocommerce', true ),
			__( 'List WooCommerce customer emails', 'user-management-suite' ),
			WooCommerceAdapter::available()
				? ''
				: __( 'WooCommerce is not active, so this has no effect.', 'user-management-suite' )
		);
		Fields::row_end();

		Fields::row_start( __( 'Registered types', 'user-management-suite' ) );
		$this->render_type_summary();
		Fields::row_end();
	}

	/**
	 * Show what is currently registered, so the admin can see the effect of the
	 * toggles above without leaving the page.
	 *
	 * @return void
	 */
	private function render_type_summary() {
		Registry::flush();
		$types = Registry::types();

		if ( array() === $types ) {
			echo '<p>' . esc_html__( 'Nothing is registered yet.', 'user-management-suite' ) . '</p>';

			return;
		}

		$optional = 0;

		foreach ( $types as $type ) {
			if ( $type['user_optout_allowed'] ) {
				++$optional;
			}
		}

		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: total notification types, 2: number users can switch off. */
				__( '%1$d notification types registered, %2$d of which a user may switch off.', 'user-management-suite' ),
				count( $types ),
				$optional
			)
		) . '</p>';

		echo '<p class="description">'
			. esc_html__( 'Order, payment and account mail stays required: it is the record of a transaction the user entered into. Use the ums_notification_woocommerce_optional filter to change which shop emails are optional.', 'user-management-suite' )
			. '</p>';
	}

	/**
	 * {@inheritDoc}
	 */
	public function sanitize_settings( array $input, array $current ) {
		if ( ! isset( $input['notifications'] ) || ! is_array( $input['notifications'] ) ) {
			return array();
		}

		$in       = $input['notifications'];
		$existing = isset( $current['notifications'] ) && is_array( $current['notifications'] )
			? $current['notifications']
			: array();

		return array(
			'notifications' => array_merge(
				$existing,
				array(
					'enable_core'        => ! empty( $in['enable_core'] ),
					'enable_woocommerce' => ! empty( $in['enable_woocommerce'] ),
				)
			),
		);
	}
}
