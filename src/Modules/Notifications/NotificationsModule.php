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

		( new Unsubscribe() )->register();
		( new RestController() )->register();
		( new Shortcode() )->register();

		if ( $this->settings->get( 'notifications', 'log_sends', true ) ) {
			( new Log() )->register();
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
		$this->render_recent_sends( (int) $user->ID );
	}

	/**
	 * Show what has actually gone out to this user.
	 *
	 * The preference list says what should happen; this says what did. Support
	 * questions are almost always about the second one.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	private function render_recent_sends( $user_id ) {
		if ( ! Log::enabled() ) {
			return;
		}

		$entries = Log::for_user( $user_id, 15 );

		if ( array() === $entries ) {
			return;
		}

		$types = Registry::types();

		echo '<table class="widefat striped" style="max-width:640px;margin-bottom:20px;">';
		echo '<thead><tr><th>' . esc_html__( 'Recently sent', 'user-management-suite' ) . '</th>'
			. '<th>' . esc_html__( 'When', 'user-management-suite' ) . '</th>'
			. '<th>' . esc_html__( 'Result', 'user-management-suite' ) . '</th></tr></thead><tbody>';

		foreach ( $entries as $entry ) {
			$label = isset( $types[ $entry->type ] ) ? $types[ $entry->type ]['label'] : $entry->type;
			$stamp = strtotime( $entry->sent_at . ' UTC' );

			echo '<tr><td>' . esc_html( $label ) . '</td><td>'
				. esc_html( $stamp ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $stamp ) : $entry->sent_at )
				. '</td><td>' . esc_html( $entry->status ) . '</td></tr>';
		}

		echo '</tbody></table>';
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

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Verified above; only read as booleans below, against a fixed list of registered type ids.
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

		Fields::row_start( __( 'Preference page', 'user-management-suite' ) );
		Fields::page_select( 'notifications', 'preferences_page', (int) $settings->get( 'notifications', 'preferences_page', 0 ) );
		echo '<p class="description">'
			. esc_html__( 'A page containing the [ums_preferences] shortcode. Unsubscribe pages link to it so people can choose individually instead of switching everything off.', 'user-management-suite' )
			. '</p>';
		Fields::row_end();

		Fields::row_start( __( 'Send log', 'user-management-suite' ) );
		Fields::checkbox(
			'notifications',
			'log_sends',
			$settings->get( 'notifications', 'log_sends', true ),
			__( 'Record which notifications were sent to whom', 'user-management-suite' ),
			__( 'Stores only the recipient, type, time and outcome — never message content.', 'user-management-suite' )
		);
		echo '<p>';
		Fields::text( 'notifications', 'log_retention_days', $settings->get( 'notifications', 'log_retention_days', 90 ), '90', 'number' );
		echo ' <span class="description">' . esc_html__( 'days to keep entries (0 keeps them indefinitely).', 'user-management-suite' ) . '</span></p>';
		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: %s: number of log entries. */
				__( 'Currently holding %s entries.', 'user-management-suite' ),
				number_format_i18n( Log::count() )
			)
		) . '</p>';
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
	 * Sanitize this module's settings section.
	 *
	 * @param array<string,mixed> $input   Raw submitted ums_settings array.
	 * @param array<string,mixed> $current Current (defaults-merged) settings.
	 * @return array<string,mixed>
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
					'log_sends'          => ! empty( $in['log_sends'] ),
					'log_retention_days' => max( 0, min( 3650, (int) ( $in['log_retention_days'] ?? 90 ) ) ),
					'preferences_page'   => max( 0, (int) ( $in['preferences_page'] ?? 0 ) ),
				)
			),
		);
	}
}
