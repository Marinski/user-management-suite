<?php
/**
 * Registration & activity tracking module.
 *
 * @package UserManagementSuite
 */

namespace Marinski\UserManagementSuite\Modules\Registration;

use Marinski\UserManagementSuite\Modules\AbstractModule;
use Marinski\UserManagementSuite\Settings\ProvidesSettings;
use Marinski\UserManagementSuite\Settings\SettingsRepository;
use Marinski\UserManagementSuite\Settings\Fields;
use Marinski\UserManagementSuite\Support\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Records registration source/URL, last login time, and IP; adds profile
 * panels, sortable Users-list columns, and a CSV export tool.
 */
class RegistrationModule extends AbstractModule implements ProvidesSettings {

	const META_URL        = '_ums_registration_url';
	const META_SOURCE     = '_ums_registration_source';
	const META_LAST_LOGIN = '_ums_last_login';
	const META_LAST_IP    = '_ums_last_login_ip';
	const META_REG_IP     = '_ums_registration_ip';

	const NONCE_PROFILE = 'ums_reg_profile';

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'registration';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return __( 'Registration & Activity Tracking', 'user-management-suite' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function register() {
		// Capture data.
		add_action( 'user_register', array( $this, 'capture_registration' ) );
		add_action( 'user_register', array( $this, 'record_registration_ip' ), 20 );
		add_action( 'wp_login', array( $this, 'record_login' ), 10, 2 );

		// Profile screens.
		add_action( 'show_user_profile', array( $this, 'render_profile_fields' ) );
		add_action( 'edit_user_profile', array( $this, 'render_profile_fields' ) );
		add_action( 'personal_options_update', array( $this, 'save_profile_fields' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_profile_fields' ) );

		// Users list columns.
		if ( $this->settings->get( 'registration', 'show_columns', true ) ) {
			add_filter( 'manage_users_columns', array( $this, 'add_columns' ) );
			add_filter( 'manage_users_custom_column', array( $this, 'render_column' ), 10, 3 );
			add_filter( 'manage_users_sortable_columns', array( $this, 'sortable_columns' ) );
			add_action( 'pre_get_users', array( $this, 'sort_columns' ) );
		}

		// Tools tab: CSV export.
		$exporter = new Exporter( $this->settings );
		add_action( 'ums_render_tools_tab', array( $exporter, 'render_tools' ) );
		add_action( 'admin_post_ums_export_users', array( $exporter, 'handle_export' ) );
	}

	/**
	 * Capture the registration referrer/source on signup.
	 *
	 * @param int $user_id New user id.
	 * @return void
	 */
	public function capture_registration( $user_id ) {
		if ( ! $this->settings->get( 'registration', 'track_source', true ) ) {
			return;
		}

		/*
		 * wp_get_referer() at signup returns the previous page on this site, so
		 * this records the last internal click, not where the visitor came from.
		 * The Acquisition module captures the real thing on the landing page; when
		 * it is running, leave the weaker signal alone rather than storing two
		 * answers to the same question.
		 */
		if ( $this->settings->is_module_enabled( 'attribution' ) ) {
			return;
		}

		$referer = wp_get_referer();
		if ( ! $referer && isset( $_SERVER['HTTP_REFERER'] ) ) {
			$referer = esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) );
		}

		if ( $referer ) {
			update_user_meta( $user_id, self::META_URL, esc_url_raw( $referer ) );

			$host = wp_parse_url( $referer, PHP_URL_HOST );
			if ( $host ) {
				update_user_meta( $user_id, self::META_SOURCE, sanitize_text_field( $host ) );
			}
		}
	}

	/**
	 * Record the real client IP used to register an account.
	 *
	 * Independent of source tracking (which may be superseded by Acquisition).
	 * Uses the same resolved client IP as the anti-spam IP block; the stored
	 * value is anonymized when `anonymize_ip` is on (the block itself always
	 * compares the raw resolved IP).
	 *
	 * @param int $user_id New user id.
	 * @return void
	 */
	public function record_registration_ip( $user_id ) {
		if ( ! $this->settings->get( 'registration', 'track_registration_ip', true ) ) {
			return;
		}

		$ip = Security::client_ip( (bool) $this->settings->get( 'registration', 'anonymize_ip', false ) );
		if ( '' !== $ip ) {
			update_user_meta( (int) $user_id, self::META_REG_IP, $ip );
		}
	}

	/**
	 * Record last-login time and IP.
	 *
	 * @param string        $user_login Username.
	 * @param \WP_User|null $user       User object.
	 * @return void
	 */
	public function record_login( $user_login, $user = null ) {
		if ( ! $user instanceof \WP_User ) {
			$user = get_user_by( 'login', $user_login );
		}
		if ( ! $user instanceof \WP_User ) {
			return;
		}

		if ( $this->settings->get( 'registration', 'track_last_login', true ) ) {
			update_user_meta( $user->ID, self::META_LAST_LOGIN, time() );
		}

		if ( $this->settings->get( 'registration', 'track_ip', true ) ) {
			$ip = Security::client_ip( (bool) $this->settings->get( 'registration', 'anonymize_ip', false ) );
			if ( '' !== $ip ) {
				update_user_meta( $user->ID, self::META_LAST_IP, $ip );
			}
		}
	}

	/**
	 * Render the activity panel on the user profile screen.
	 *
	 * @param \WP_User $user User being edited.
	 * @return void
	 */
	public function render_profile_fields( $user ) {
		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}

		$last_login = (int) get_user_meta( $user->ID, self::META_LAST_LOGIN, true );
		$last_ip    = (string) get_user_meta( $user->ID, self::META_LAST_IP, true );
		$source     = (string) get_user_meta( $user->ID, self::META_SOURCE, true );
		$url        = (string) get_user_meta( $user->ID, self::META_URL, true );

		wp_nonce_field( self::NONCE_PROFILE, self::NONCE_PROFILE );
		?>
		<h2><?php esc_html_e( 'User Management — Activity', 'user-management-suite' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><?php esc_html_e( 'Registered', 'user-management-suite' ); ?></th>
				<td><?php echo esc_html( $this->format_date( $user->user_registered ) ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Last login', 'user-management-suite' ); ?></th>
				<td><?php echo $last_login ? esc_html( $this->format_timestamp( $last_login ) ) : esc_html__( 'Never', 'user-management-suite' ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Last login IP', 'user-management-suite' ); ?></th>
				<td><?php echo $last_ip ? esc_html( $last_ip ) : '&mdash;'; ?></td>
			</tr>
			<tr>
				<th><label for="ums_reg_source"><?php esc_html_e( 'Registration source', 'user-management-suite' ); ?></label></th>
				<td><input type="text" class="regular-text" id="ums_reg_source" name="ums_reg_source" value="<?php echo esc_attr( $source ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="ums_reg_url"><?php esc_html_e( 'Registration URL', 'user-management-suite' ); ?></label></th>
				<td><input type="url" class="regular-text" id="ums_reg_url" name="ums_reg_url" value="<?php echo esc_attr( $url ); ?>" /></td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Save the editable registration fields from the profile screen.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	public function save_profile_fields( $user_id ) {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		if ( ! isset( $_POST[ self::NONCE_PROFILE ] )
			|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_PROFILE ] ) ), self::NONCE_PROFILE ) ) {
			return;
		}

		if ( isset( $_POST['ums_reg_source'] ) ) {
			update_user_meta( $user_id, self::META_SOURCE, sanitize_text_field( wp_unslash( $_POST['ums_reg_source'] ) ) );
		}

		if ( isset( $_POST['ums_reg_url'] ) ) {
			update_user_meta( $user_id, self::META_URL, esc_url_raw( wp_unslash( $_POST['ums_reg_url'] ) ) );
		}
	}

	/**
	 * Add custom columns to the Users list table.
	 *
	 * @param array<string,string> $columns Existing columns.
	 * @return array<string,string>
	 */
	public function add_columns( $columns ) {
		$columns['ums_last_login'] = __( 'Last login', 'user-management-suite' );
		$columns['ums_reg_source'] = __( 'Reg. source', 'user-management-suite' );

		return $columns;
	}

	/**
	 * Render a custom column's value.
	 *
	 * @param string $output      Default output.
	 * @param string $column_name Column id.
	 * @param int    $user_id     User id.
	 * @return string
	 */
	public function render_column( $output, $column_name, $user_id ) {
		if ( 'ums_last_login' === $column_name ) {
			$ts = (int) get_user_meta( $user_id, self::META_LAST_LOGIN, true );

			return $ts ? esc_html( $this->format_timestamp( $ts ) ) : esc_html__( 'Never', 'user-management-suite' );
		}

		if ( 'ums_reg_source' === $column_name ) {
			$source = (string) get_user_meta( $user_id, self::META_SOURCE, true );

			return $source ? esc_html( $source ) : '&mdash;';
		}

		return $output;
	}

	/**
	 * Mark the last-login column sortable.
	 *
	 * @param array<string,string> $columns Sortable columns.
	 * @return array<string,string>
	 */
	public function sortable_columns( $columns ) {
		$columns['ums_last_login'] = 'ums_last_login';

		return $columns;
	}

	/**
	 * Apply last-login sorting to the Users query.
	 *
	 * @param \WP_User_Query $query User query.
	 * @return void
	 */
	public function sort_columns( $query ) {
		if ( ! is_admin() ) {
			return;
		}

		$orderby = $query->get( 'orderby' );
		if ( 'ums_last_login' !== $orderby ) {
			return;
		}

		$query->set( 'meta_key', self::META_LAST_LOGIN );
		$query->set( 'orderby', 'meta_value_num' );
	}

	/**
	 * Format a MySQL datetime using site locale.
	 *
	 * @param string $mysql_date MySQL date string.
	 * @return string
	 */
	private function format_date( $mysql_date ) {
		$ts = strtotime( $mysql_date );

		return $ts ? $this->format_timestamp( $ts ) : $mysql_date;
	}

	/**
	 * Format a unix timestamp using site date/time format + timezone.
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return string
	 */
	private function format_timestamp( $timestamp ) {
		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}

	/**
	 * {@inheritDoc}
	 */
	public function settings_tab_id() {
		return 'registration';
	}

	/**
	 * {@inheritDoc}
	 */
	public function settings_tab_label() {
		return __( 'Registration', 'user-management-suite' );
	}

	/**
	 * Render the Registration settings tab.
	 *
	 * @param SettingsRepository $settings Settings repository.
	 * @return void
	 */
	public function render_settings_tab( SettingsRepository $settings ) {
		Fields::row_start( __( 'Tracking', 'user-management-suite' ) );
		Fields::checkbox( 'registration', 'track_source', $settings->get( 'registration', 'track_source', true ), __( 'Record the registration source (referrer) of new users', 'user-management-suite' ) );

		if ( $settings->is_module_enabled( 'attribution' ) ) {
			echo '<p class="description">'
				. esc_html__( 'Superseded by the Acquisition module, which captures the real source on the landing page. This setting no longer writes anything.', 'user-management-suite' )
				. '</p>';
		} else {
			echo '<p class="description">'
				. esc_html__( 'This only sees the previous page on your own site, so it usually records an internal link. Enable the Acquisition module for real source data.', 'user-management-suite' )
				. '</p>';
		}

		echo '<br />';
		Fields::checkbox( 'registration', 'track_last_login', $settings->get( 'registration', 'track_last_login', true ), __( 'Record each user\'s last login time', 'user-management-suite' ) );
		echo '<br />';
		Fields::checkbox( 'registration', 'track_ip', $settings->get( 'registration', 'track_ip', true ), __( 'Record the IP address used at last login', 'user-management-suite' ) );
		echo '<br />';
		Fields::checkbox( 'registration', 'track_registration_ip', $settings->get( 'registration', 'track_registration_ip', true ), __( 'Record the IP address used to register', 'user-management-suite' ) );
		echo '<br />';
		Fields::checkbox( 'registration', 'anonymize_ip', $settings->get( 'registration', 'anonymize_ip', false ), __( 'Anonymize stored IP addresses (recommended for GDPR)', 'user-management-suite' ) );
		Fields::row_end();

		Fields::row_start( __( 'Users list', 'user-management-suite' ) );
		Fields::checkbox( 'registration', 'show_columns', $settings->get( 'registration', 'show_columns', true ), __( 'Show "Last login" and "Reg. source" columns on the Users screen', 'user-management-suite' ) );
		Fields::row_end();
	}

	/**
	 * Sanitize the Registration settings section.
	 *
	 * @param array<string,mixed> $input   Submitted settings array.
	 * @param array<string,mixed> $current Current stored settings.
	 * @return array<string,mixed>
	 */
	public function sanitize_settings( array $input, array $current ) {
		if ( ! isset( $input['registration'] ) || ! is_array( $input['registration'] ) ) {
			return array();
		}

		$in = $input['registration'];

		return array(
			'registration' => array(
				'track_source'          => ! empty( $in['track_source'] ),
				'track_last_login'      => ! empty( $in['track_last_login'] ),
				'track_ip'              => ! empty( $in['track_ip'] ),
				'track_registration_ip' => ! empty( $in['track_registration_ip'] ),
				'anonymize_ip'          => ! empty( $in['anonymize_ip'] ),
				'show_columns'          => ! empty( $in['show_columns'] ),
			),
		);
	}
}
