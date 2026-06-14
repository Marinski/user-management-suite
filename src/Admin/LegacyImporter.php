<?php
/**
 * One-click importer for data from the legacy user-management plugins.
 *
 * @package UserManagementSuite
 */

namespace Marinski\UserManagementSuite\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Copies user meta written by the older plugins into this plugin's own keys,
 * without overwriting existing values or deleting the originals.
 */
class LegacyImporter {

	const NONCE  = 'ums_import_legacy';
	const ACTION = 'ums_import_legacy';

	/**
	 * Legacy meta key => new meta key.
	 *
	 * @var array<string,string>
	 */
	private $map = array(
		// academy-user-registration.
		'registration_url'       => '_ums_registration_url',
		'registration_source'    => '_ums_registration_source',
		'last_login'             => '_ums_last_login',
		'_bbp_author_ip'         => '_ums_last_login_ip',
		// user-verification (PickPlugins).
		'user_activation_status' => '_ums_activation_status',
		'user_activation_key'    => '_ums_activation_key',
	);

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'ums_render_tools_tab', array( $this, 'render_tools' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Render the import control on the Tools tab.
	 *
	 * @return void
	 */
	public function render_tools() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag.
		if ( isset( $_GET['ums_imported'] ) ) {
			echo '<tr><td colspan="2"><div class="notice notice-success inline"><p>'
				. esc_html(
					sprintf(
						/* translators: %d: number of meta values imported. */
						__( 'Legacy import complete. %d values imported.', 'user-management-suite' ),
						absint( $_GET['ums_imported'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					)
				)
				. '</p></div></td></tr>';
		}
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Import from legacy plugins', 'user-management-suite' ); ?></th>
			<td>
				<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
					<?php wp_nonce_field( self::NONCE, self::NONCE ); ?>
					<p class="description">
						<?php esc_html_e( 'Copies registration source, last login, IP and verification status from Academy User Registration and User Verification into this plugin. Existing values are kept; nothing is deleted.', 'user-management-suite' ); ?>
					</p>
					<?php submit_button( __( 'Run import', 'user-management-suite' ), 'secondary', 'submit', false ); ?>
				</form>
			</td>
		</tr>
		<?php
	}

	/**
	 * Handle the import request.
	 *
	 * @return void
	 */
	public function handle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to run the import.', 'user-management-suite' ) );
		}

		if ( ! isset( $_POST[ self::NONCE ] )
			|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) ) {
			wp_die( esc_html__( 'Security check failed.', 'user-management-suite' ) );
		}

		$imported = $this->import();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => 'user-management-suite',
					'tab'          => 'tools',
					'ums_imported' => $imported,
				),
				admin_url( 'users.php' )
			)
		);
		exit;
	}

	/**
	 * Perform the copy. Returns the number of values imported.
	 *
	 * @return int
	 */
	private function import() {
		global $wpdb;
		$count = 0;

		foreach ( $this->map as $legacy => $target ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Admin-triggered one-time migration; no suitable API for bulk read.
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s", $legacy )
			);

			if ( empty( $rows ) ) {
				continue;
			}

			foreach ( $rows as $row ) {
				$user_id = (int) $row->user_id;
				$value   = $row->meta_value;

				// Do not overwrite an existing value.
				$existing = get_user_meta( $user_id, $target, true );
				if ( '' !== $existing && null !== $existing && false !== $existing ) {
					continue;
				}

				// Normalize verification status to '0'/'1' strings.
				if ( '_ums_activation_status' === $target ) {
					$value = ( '1' === (string) $value || 1 === $value ) ? '1' : '0';
				}

				update_user_meta( $user_id, $target, $value );
				++$count;
			}
		}

		return $count;
	}
}
