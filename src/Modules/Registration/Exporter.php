<?php
/**
 * CSV export of users with tracked registration/activity data.
 *
 * @package UserManagementSuite
 */

namespace Marinski\UserManagementSuite\Modules\Registration;

use Marinski\UserManagementSuite\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the export controls on the Tools tab and streams the CSV.
 */
class Exporter {

	const NONCE  = 'ums_export_users';
	const ACTION = 'ums_export_users';

	/**
	 * Settings repository.
	 *
	 * @var SettingsRepository
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param SettingsRepository $settings Settings repository.
	 */
	public function __construct( SettingsRepository $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Columns available for export: key => label.
	 *
	 * @return array<string,string>
	 */
	private function columns() {
		return array(
			'ID'                  => __( 'User ID', 'user-management-suite' ),
			'user_login'          => __( 'Username', 'user-management-suite' ),
			'user_email'          => __( 'Email', 'user-management-suite' ),
			'display_name'        => __( 'Display name', 'user-management-suite' ),
			'roles'               => __( 'Roles', 'user-management-suite' ),
			'user_registered'     => __( 'Registered', 'user-management-suite' ),
			'registration_source' => __( 'Reg. source', 'user-management-suite' ),
			'registration_url'    => __( 'Reg. URL', 'user-management-suite' ),
			'last_login'          => __( 'Last login', 'user-management-suite' ),
			'last_login_ip'       => __( 'Last login IP', 'user-management-suite' ),
		);
	}

	/**
	 * Render the export form on the Tools tab.
	 *
	 * @return void
	 */
	public function render_tools() {
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Export users (CSV)', 'user-management-suite' ); ?></th>
			<td>
				<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
					<?php wp_nonce_field( self::NONCE, self::NONCE ); ?>
					<fieldset>
						<?php foreach ( $this->columns() as $key => $label ) : ?>
							<label style="display:inline-block;min-width:160px;">
								<input type="checkbox" name="ums_columns[]" value="<?php echo esc_attr( $key ); ?>" checked />
								<?php echo esc_html( $label ); ?>
							</label>
						<?php endforeach; ?>
					</fieldset>
					<p class="description"><?php esc_html_e( 'Optionally limit to specific user IDs (comma-separated). Leave blank to export all users.', 'user-management-suite' ); ?></p>
					<p><input type="text" class="regular-text" name="ums_user_ids" placeholder="e.g. 12, 34, 56" /></p>
					<?php submit_button( __( 'Download CSV', 'user-management-suite' ), 'secondary', 'submit', false ); ?>
				</form>
			</td>
		</tr>
		<?php
	}

	/**
	 * Handle the export request and stream a CSV download.
	 *
	 * @return void
	 */
	public function handle_export() {
		if ( ! current_user_can( 'list_users' ) ) {
			wp_die( esc_html__( 'You do not have permission to export users.', 'user-management-suite' ) );
		}

		if ( ! isset( $_POST[ self::NONCE ] )
			|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) ) {
			wp_die( esc_html__( 'Security check failed.', 'user-management-suite' ) );
		}

		// Selected columns (intersect with allowed set to be safe).
		$allowed  = $this->columns();
		$selected = isset( $_POST['ums_columns'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['ums_columns'] ) ) : array();
		$selected = array_values( array_intersect( array_keys( $allowed ), $selected ) );
		if ( empty( $selected ) ) {
			$selected = array_keys( $allowed );
		}

		// Optional ID filter.
		$args = array(
			'fields' => 'all',
			'number' => -1,
		);
		if ( ! empty( $_POST['ums_user_ids'] ) ) {
			$raw = sanitize_text_field( wp_unslash( $_POST['ums_user_ids'] ) );
			$ids = array_filter( array_map( 'absint', preg_split( '/[\s,]+/', $raw ) ) );
			if ( ! empty( $ids ) ) {
				$args['include'] = $ids;
			}
		}

		$users = get_users( $args );

		$this->stream_csv( $users, $selected, $allowed );
	}

	/**
	 * Stream the CSV to the browser and exit.
	 *
	 * @param \WP_User[]           $users    Users.
	 * @param string[]             $selected Selected column keys.
	 * @param array<string,string> $labels   key => label map.
	 * @return void
	 */
	private function stream_csv( $users, $selected, $labels ) {
		$filename = 'users-export-' . gmdate( 'Y-m-d_H-i-s' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );

		$out = fopen( 'php://output', 'w' );

		// Header row.
		$header = array();
		foreach ( $selected as $key ) {
			$header[] = $labels[ $key ];
		}
		fputcsv( $out, $header );

		$anonymize = (bool) $this->settings->get( 'registration', 'anonymize_ip', false );

		foreach ( $users as $user ) {
			$row = array();
			foreach ( $selected as $key ) {
				$row[] = $this->cell_value( $user, $key, $anonymize );
			}
			fputcsv( $out, $row );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- php://output stream cannot use WP_Filesystem.
		fclose( $out );
		exit;
	}

	/**
	 * Resolve a single cell value for a user/column.
	 *
	 * @param \WP_User $user      User.
	 * @param string   $key       Column key.
	 * @param bool     $anonymize Whether IPs are anonymized (no-op; stored already).
	 * @return string
	 */
	private function cell_value( $user, $key, $anonymize ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $anonymize reserved for future IP-masking column support.
		switch ( $key ) {
			case 'ID':
				return (string) $user->ID;
			case 'user_login':
				return $user->user_login;
			case 'user_email':
				return $user->user_email;
			case 'display_name':
				return $user->display_name;
			case 'roles':
				return implode( '|', (array) $user->roles );
			case 'user_registered':
				return $user->user_registered;
			case 'registration_source':
				return (string) get_user_meta( $user->ID, RegistrationModule::META_SOURCE, true );
			case 'registration_url':
				return (string) get_user_meta( $user->ID, RegistrationModule::META_URL, true );
			case 'last_login':
				$ts = (int) get_user_meta( $user->ID, RegistrationModule::META_LAST_LOGIN, true );
				return $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : '';
			case 'last_login_ip':
				return (string) get_user_meta( $user->ID, RegistrationModule::META_LAST_IP, true );
			default:
				return '';
		}
	}
}
