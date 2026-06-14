<?php
/**
 * Spam-user exporter: Tools-tab exports for bbPress spam topics.
 *
 * @package UserManagementSuite
 */

namespace Marinski\UserManagementSuite\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Exports users who authored bbPress spam topics, and users sharing their IPs.
 * Replaces the standalone academy-export-spam-users plugin.
 */
class SpamExporter {

	const NONCE_SPAM  = 'ums_spam_export';
	const NONCE_IP    = 'ums_spam_ip_export';
	const ACTION_SPAM = 'ums_spam_export';
	const ACTION_IP   = 'ums_spam_ip_export';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'ums_render_tools_tab', array( $this, 'render_tools' ) );
		add_action( 'admin_post_' . self::ACTION_SPAM, array( $this, 'handle_spam_export' ) );
		add_action( 'admin_post_' . self::ACTION_IP, array( $this, 'handle_ip_export' ) );
	}

	/**
	 * Render the Tools tab section.
	 *
	 * @return void
	 */
	public function render_tools() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag set by our own redirect.
		$exported = isset( $_GET['ums_spam_exported'] ) ? absint( $_GET['ums_spam_exported'] ) : null;
		if ( null !== $exported ) {
			echo '<tr><td colspan="2"><div class="notice notice-success inline"><p>'
				. esc_html(
					sprintf(
						/* translators: %d: number of rows exported. */
						__( 'Exported %d spam user records.', 'user-management-suite' ),
						$exported
					)
				)
				. '</p></div></td></tr>';
		}
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Spam user exports (bbPress)', 'user-management-suite' ); ?></th>
			<td>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:8px;">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SPAM ); ?>" />
					<?php wp_nonce_field( self::NONCE_SPAM, self::NONCE_SPAM ); ?>
					<?php submit_button( __( 'Export spam users', 'user-management-suite' ), 'secondary', 'submit', false ); ?>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_IP ); ?>" />
					<?php wp_nonce_field( self::NONCE_IP, self::NONCE_IP ); ?>
					<?php submit_button( __( 'Export IP-related users', 'user-management-suite' ), 'secondary', 'submit', false ); ?>
				</form>
				<p class="description">
					<?php esc_html_e( 'Export users who authored bbPress spam topics (with spam-topic count and last known IP), or export non-spam users who share an IP with a spam author.', 'user-management-suite' ); ?>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Stream a CSV of users who authored bbPress spam topics.
	 *
	 * @return void Exits after streaming.
	 */
	public function handle_spam_export() {
		$this->check_permissions( self::NONCE_SPAM );

		global $wpdb;

		// GROUP BY in SQL replaces the PHP-level dedup loop in the original plugin.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- admin-only one-shot export; no user input in SQL.
		$rows = $wpdb->get_results(
			"SELECT
				p.post_author              AS user_id,
				u.user_email               AS email,
				u.user_login               AS username,
				MAX( pm.meta_value )       AS ip_address,
				COUNT( p.ID )              AS spam_count
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->users}    u  ON u.ID      = p.post_author
			LEFT  JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
			                                AND pm.meta_key = '_bbp_author_ip'
			WHERE p.post_status = 'spam'
			  AND p.post_type   = 'topic'
			GROUP BY p.post_author, u.user_email, u.user_login
			ORDER BY spam_count DESC"
		);

		$data = array_map(
			static function ( $r ) {
				return array( $r->user_id, $r->email, $r->username, $r->ip_address, $r->spam_count );
			},
			$rows
		);

		$this->stream_csv(
			'spam-users-' . gmdate( 'Y-m-d_H-i-s' ) . '.csv',
			array( 'User ID', 'Email', 'Username', 'IP', 'Spam count' ),
			$data
		);
	}

	/**
	 * Stream a CSV of non-spam users who share an IP with a spam author.
	 *
	 * @return void Exits after streaming.
	 */
	public function handle_ip_export() {
		$this->check_permissions( self::NONCE_IP );

		global $wpdb;

		// Subquery avoids building a dynamic IN-list, fixing the SQL-injection risk
		// in the original plugin's implode("','", $spam_ips) approach.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- admin-only one-shot export; no user input in SQL.
		$rows = $wpdb->get_results(
			"SELECT DISTINCT
				u.ID                    AS user_id,
				u.user_email            AS email,
				u.user_login            AS username,
				c.comment_author_IP     AS ip
			FROM {$wpdb->comments} c
			INNER JOIN {$wpdb->users} u ON u.ID = c.user_id
			WHERE c.comment_approved = '1'
			  AND u.ID IS NOT NULL
			  AND c.comment_author_IP IN (
				  SELECT DISTINCT pm.meta_value
				  FROM {$wpdb->posts} p
				  INNER JOIN {$wpdb->postmeta} pm
				         ON pm.post_id  = p.ID
				         AND pm.meta_key = '_bbp_author_ip'
				  WHERE p.post_status = 'spam'
				    AND p.post_type   = 'topic'
				    AND pm.meta_value != ''
			  )
			ORDER BY u.user_login"
		);

		$data = array_map(
			static function ( $r ) {
				return array( $r->user_id, $r->email, $r->username, $r->ip );
			},
			$rows
		);

		$this->stream_csv(
			'spam-ip-related-users-' . gmdate( 'Y-m-d_H-i-s' ) . '.csv',
			array( 'User ID', 'Email', 'Username', 'IP' ),
			$data
		);
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Capability + nonce gate; wp_die()s on failure.
	 *
	 * @param string $nonce_action Nonce action name (doubles as the POST field name).
	 * @return void
	 */
	private function check_permissions( $nonce_action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to run this export.', 'user-management-suite' ) );
		}

		$nonce = isset( $_POST[ $nonce_action ] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified on the next line.
			? sanitize_key( wp_unslash( $_POST[ $nonce_action ] ) )
			: '';

		if ( ! wp_verify_nonce( $nonce, $nonce_action ) ) {
			wp_die( esc_html__( 'Security check failed.', 'user-management-suite' ) );
		}
	}

	/**
	 * Set download headers, write UTF-8 BOM + CSV rows, and exit.
	 *
	 * @param string   $filename Suggested download filename.
	 * @param string[] $headers  Column header labels.
	 * @param array[]  $rows     Data rows (each a numerically-indexed array).
	 * @return void Exits after streaming.
	 */
	private function stream_csv( $filename, array $headers, array $rows ) {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$fp = fopen( 'php://output', 'w' );

		// BOM for Excel UTF-8 compatibility.
		fwrite( $fp, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

		fputcsv( $fp, $headers ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		foreach ( $rows as $row ) {
			fputcsv( $fp, $row ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		}

		fclose( $fp );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		exit;
	}
}
