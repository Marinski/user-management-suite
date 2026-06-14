<?php
/**
 * Batched CSV export handler.
 *
 * @package UserManagementSuite
 */

namespace Marinski\UserManagementSuite\Modules\ImportExport;

use Marinski\UserManagementSuite\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Drives the two-phase AJAX export:
 *  1. init_export()   – counts matching users, creates temp file with header row.
 *  2. run_batch()     – appends a slice of user rows.
 *  3. serve_download()– streams the completed file and removes it.
 */
class ExportHandler {

	/**
	 * Transient TTL for export state (2 hours).
	 */
	const TTL = 7200;

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

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Initialise an export: count users, write header row, persist state.
	 *
	 * @param array<string,mixed> $filters    User query filters (role, date_from, date_to, search).
	 * @param string[]            $columns    Selected column keys.
	 * @param string              $delimiter  CSV delimiter character.
	 * @return array<string,mixed> {token, total, batch_size} or {error}.
	 */
	public function init_export( $filters, $columns, $delimiter ) {
		$all_cols = Columns::all();

		// Intersect requested columns with known ones to prevent arbitrary data leaks.
		$columns = array_values( array_intersect( array_keys( $all_cols ), $columns ) );
		if ( empty( $columns ) ) {
			$columns = Columns::default_keys();
		}

		$query_args = $this->build_query_args( $filters );
		$count_args = array_merge(
			$query_args,
			array(
				'fields' => 'ID',
				'number' => -1,
			)
		);
		$user_ids   = get_users( $count_args );
		$total      = count( $user_ids );

		// Persist the ordered list of IDs so batches are consistent.
		$token = wp_generate_password( 24, false );
		$state = array(
			'ids'       => array_map( 'intval', $user_ids ),
			'columns'   => $columns,
			'delimiter' => $delimiter,
		);
		set_transient( 'ums_ie_export_' . $token, $state, self::TTL );

		// Create temp file and write header row.
		$file = $this->temp_path( $token );
		$this->ensure_upload_dir();
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$fp = fopen( $file, 'w' );
		if ( false === $fp ) {
			return array( 'error' => __( 'Could not create temporary export file.', 'user-management-suite' ) );
		}

		// BOM for Excel UTF-8 compatibility.
		fwrite( $fp, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

		$header = array();
		foreach ( $columns as $key ) {
			$header[] = isset( $all_cols[ $key ]['label'] ) ? $all_cols[ $key ]['label'] : $key;
		}
		fputcsv( $fp, $header, $delimiter ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

		fclose( $fp );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		$batch_size = (int) $this->settings->get( 'import_export', 'export_batch_size', 200 );

		return array(
			'token'      => $token,
			'total'      => $total,
			'batch_size' => $batch_size,
		);
	}

	/**
	 * Append a batch of user rows to the export temp file.
	 *
	 * @param string $token  Export token from init_export().
	 * @param int    $offset Zero-based index into the user ID list.
	 * @return array<string,mixed> {written, done} or {error}.
	 */
	public function run_batch( $token, $offset ) {
		$state = get_transient( 'ums_ie_export_' . $token );
		if ( ! is_array( $state ) ) {
			return array( 'error' => __( 'Export session expired. Please start again.', 'user-management-suite' ) );
		}

		$ids        = $state['ids'];
		$columns    = $state['columns'];
		$delimiter  = $state['delimiter'];
		$batch_size = (int) $this->settings->get( 'import_export', 'export_batch_size', 200 );
		$slice      = array_slice( $ids, $offset, $batch_size );

		$file = $this->temp_path( $token );
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$fp = fopen( $file, 'a' );
		if ( false === $fp ) {
			return array( 'error' => __( 'Could not open temporary export file.', 'user-management-suite' ) );
		}

		$written = 0;
		foreach ( $slice as $user_id ) {
			$user = get_userdata( $user_id );
			if ( ! $user instanceof \WP_User ) {
				continue;
			}
			$row = $this->build_row( $user, $columns );
			fputcsv( $fp, $row, $delimiter ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			++$written;
		}

		fclose( $fp );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		$done = ( $offset + $batch_size ) >= count( $ids );

		return array(
			'written' => $written,
			'done'    => $done,
			'token'   => $token,
		);
	}

	/**
	 * Stream the completed CSV file to the browser and delete it.
	 *
	 * @param string $token Export token.
	 * @return void Exits after streaming.
	 */
	public function serve_download( $token ) {
		$file = $this->temp_path( $token );

		if ( ! file_exists( $file ) ) {
			wp_die( esc_html__( 'Export file not found. Please start a new export.', 'user-management-suite' ) );
		}

		$filename = 'users-export-' . gmdate( 'Y-m-d_H-i-s' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . filesize( $file ) );

		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		readfile( $file );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_readfile

		// Clean up: remove temp file and transient.
		wp_delete_file( $file );
		delete_transient( 'ums_ie_export_' . $token );

		exit;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Build a WP_User_Query args array from the filter form submission.
	 *
	 * @param array<string,mixed> $filters Raw filter values.
	 * @return array<string,mixed>
	 */
	private function build_query_args( $filters ) {
		$args = array( 'number' => -1 );

		// Role filter.
		if ( ! empty( $filters['roles'] ) && is_array( $filters['roles'] ) ) {
			$valid_roles = array_keys( wp_roles()->get_names() );
			$roles       = array_intersect( array_map( 'sanitize_key', $filters['roles'] ), $valid_roles );
			if ( ! empty( $roles ) ) {
				$args['role__in'] = array_values( $roles );
			}
		}

		// Date range.
		$date_query = array();
		if ( ! empty( $filters['date_from'] ) ) {
			$date_query[] = array(
				'column'    => 'user_registered',
				'after'     => sanitize_text_field( $filters['date_from'] ),
				'inclusive' => true,
			);
		}
		if ( ! empty( $filters['date_to'] ) ) {
			$date_query[] = array(
				'column'    => 'user_registered',
				'before'    => sanitize_text_field( $filters['date_to'] ),
				'inclusive' => true,
			);
		}
		if ( ! empty( $date_query ) ) {
			$args['date_query'] = $date_query;
		}

		// User search (email or username).
		if ( ! empty( $filters['search'] ) ) {
			$args['search']         = '*' . sanitize_text_field( $filters['search'] ) . '*';
			$args['search_columns'] = array( 'user_email', 'user_login', 'display_name' );
		}

		// Specific user IDs.
		if ( ! empty( $filters['user_ids'] ) ) {
			$ids = array_filter( array_map( 'absint', preg_split( '/[\s,]+/', $filters['user_ids'] ) ) );
			if ( ! empty( $ids ) ) {
				$args['include'] = $ids;
			}
		}

		return $args;
	}

	/**
	 * Build a single CSV row array for one user.
	 *
	 * @param \WP_User $user    User object.
	 * @param string[] $columns Ordered column keys.
	 * @return string[]
	 */
	private function build_row( $user, $columns ) {
		$all_cols = Columns::all();
		$row      = array();

		foreach ( $columns as $key ) {
			$row[] = $this->cell_value( $user, $key, $all_cols );
		}

		return $row;
	}

	/**
	 * Resolve one cell value.
	 *
	 * @param \WP_User            $user     User.
	 * @param string              $key      Column key.
	 * @param array<string,mixed> $all_cols Full column definitions.
	 * @return string
	 */
	private function cell_value( $user, $key, $all_cols ) {
		$col = isset( $all_cols[ $key ] ) ? $all_cols[ $key ] : array();

		switch ( $key ) {
			case 'ID':
				return (string) $user->ID;

			case 'user_pass':
				return $user->user_pass; // Already hashed, export as-is.

			case 'roles':
				return implode( '|', (array) $user->roles );

			case 'ums_last_login':
				$ts = (int) get_user_meta( $user->ID, '_ums_last_login', true );
				return $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : '';

			case 'woo_total_spent':
				return Columns::woocommerce_active() ? (string) wc_get_customer_total_spent( $user->ID ) : '';

			case 'woo_order_count':
				return Columns::woocommerce_active() ? (string) wc_get_customer_order_count( $user->ID ) : '';

			case 'woo_avg_order':
				if ( ! Columns::woocommerce_active() ) {
					return '';
				}
				$spent = (float) wc_get_customer_total_spent( $user->ID );
				$count = (int) wc_get_customer_order_count( $user->ID );
				return $count ? (string) round( $spent / $count, 2 ) : '0';
		}

		// Core wp_users properties.
		if ( isset( $col['group'] ) && Columns::GROUP_CORE === $col['group'] ) {
			return (string) $user->{ $key };
		}

		// Meta fields (meta, ums, woo billing/shipping).
		if ( ! empty( $col['meta_key'] ) ) {
			return (string) get_user_meta( $user->ID, $col['meta_key'], true );
		}

		return '';
	}

	/**
	 * Ensure the temp directory exists and is protected.
	 *
	 * @return void
	 */
	private function ensure_upload_dir() {
		$dir = $this->temp_dir();

		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$index = $dir . '/index.php';
		if ( ! file_exists( $index ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}

		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions
			file_put_contents( $htaccess, "deny from all\n" );
		}
	}

	/**
	 * Absolute path to the temp upload directory.
	 *
	 * @return string
	 */
	private function temp_dir() {
		$upload = wp_upload_dir();
		return $upload['basedir'] . '/ums-ie';
	}

	/**
	 * Absolute path to a specific export temp file.
	 *
	 * @param string $token Export token.
	 * @return string
	 */
	private function temp_path( $token ) {
		return $this->temp_dir() . '/export-' . sanitize_file_name( $token ) . '.csv';
	}

	/**
	 * Delete export temp files older than TTL (called on init_export to housekeep).
	 *
	 * @return void
	 */
	public function cleanup_old_files() {
		$dir = $this->temp_dir();
		if ( ! is_dir( $dir ) ) {
			return;
		}

		foreach ( glob( $dir . '/export-*.csv' ) as $file ) {
			if ( filemtime( $file ) < ( time() - self::TTL ) ) {
				wp_delete_file( $file );
			}
		}
	}
}
