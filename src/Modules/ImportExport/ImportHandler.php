<?php
/**
 * Batched CSV import handler.
 *
 * @package UserManagementSuite
 */

namespace Marinski\UserManagementSuite\Modules\ImportExport;

use Marinski\UserManagementSuite\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Drives the three-phase AJAX import:
 *  1. handle_upload()   – saves the uploaded CSV to a temp directory.
 *  2. get_file_info()   – returns headers + row count for the mapping UI.
 *  3. run_batch()       – parses and upserts a slice of rows.
 *  4. cleanup()         – deletes the temp file and transient.
 */
class ImportHandler {

	/**
	 * Transient TTL for import state (4 hours).
	 */
	const TTL = 14400;

	/**
	 * Allowed MIME types for uploaded import files.
	 *
	 * @var array<string,string>
	 */
	const ALLOWED_MIME = array(
		'csv' => 'text/csv',
		'txt' => 'text/plain',
	);

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
	 * Handle a CSV file upload.
	 *
	 * Expects $_FILES['ums_ie_file'] to be set (validated by caller).
	 *
	 * @return array<string,mixed> {token, filename} or {error}.
	 */
	public function handle_upload() {
		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified by the AJAX dispatcher before calling this method. wp_handle_upload validates the file server-side.
		if ( empty( $_FILES['ums_ie_file'] ) ) {
			return array( 'error' => __( 'No file received.', 'user-management-suite' ) );
		}

		$uploaded_file = $_FILES['ums_ie_file'];
		// phpcs:enable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$original_name = sanitize_file_name(
			isset( $uploaded_file['name'] ) ? wp_unslash( $uploaded_file['name'] ) : 'import.csv'
		);
		$ext           = strtolower( pathinfo( $original_name, PATHINFO_EXTENSION ) );

		if ( ! isset( self::ALLOWED_MIME[ $ext ] ) ) {
			return array( 'error' => __( 'Only .csv and .txt files are supported.', 'user-management-suite' ) );
		}

		$token    = wp_generate_password( 24, false );
		$filename = 'import-' . $token . '.' . $ext;

		$this->ensure_upload_dir();
		$dest = $this->temp_path( $filename );

		$result = wp_handle_upload(
			$uploaded_file,
			array(
				'test_form' => false,
				'test_type' => false,
				'mimes'     => self::ALLOWED_MIME,
			)
		);

		if ( isset( $result['error'] ) ) {
			return array( 'error' => $result['error'] );
		}

		// Move from default uploads dir to our protected temp dir.
		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();
		if ( ! $wp_filesystem->move( $result['file'], $dest, true ) ) {
			wp_delete_file( $result['file'] );
			return array( 'error' => __( 'Could not move uploaded file to temp directory.', 'user-management-suite' ) );
		}

		set_transient(
			'ums_ie_import_' . $token,
			array( 'file' => $dest ),
			self::TTL
		);

		return array(
			'token'    => $token,
			'filename' => $original_name,
		);
	}

	/**
	 * Return headers, row count, and delimiter for a previously uploaded file.
	 *
	 * @param string $token Import token from handle_upload().
	 * @return array<string,mixed> {headers, row_count, delimiter, auto_mapping} or {error}.
	 */
	public function get_file_info( $token ) {
		$state = $this->get_state( $token );
		if ( isset( $state['error'] ) ) {
			return $state;
		}

		$file      = $state['file'];
		$delimiter = CsvParser::detect_delimiter( $file );
		$headers   = CsvParser::read_headers( $file, $delimiter );
		$row_count = CsvParser::count_rows( $file, $delimiter );

		// Persist delimiter for batch calls.
		$state['delimiter'] = $delimiter;
		set_transient( 'ums_ie_import_' . $token, $state, self::TTL );

		// Build auto-mapping suggestions.
		$auto_mapping = array();
		foreach ( $headers as $header ) {
			$auto_mapping[ $header ] = Columns::auto_map( $header );
		}

		return array(
			'headers'      => $headers,
			'row_count'    => $row_count,
			'delimiter'    => $delimiter,
			'auto_mapping' => $auto_mapping,
		);
	}

	/**
	 * Process a batch of import rows.
	 *
	 * @param string               $token      Import token.
	 * @param array<string,string> $mapping    CSV header → column key map.
	 * @param array<string,mixed>  $opts       Import options (merge_with, found_action, send_email, default_role).
	 * @param int                  $offset     Zero-based data-row offset.
	 * @return array<string,mixed> {processed, created, updated, skipped, failed, log, done} or {error}.
	 */
	public function run_batch( $token, $mapping, $opts, $offset ) {
		$state = $this->get_state( $token );
		if ( isset( $state['error'] ) ) {
			return $state;
		}

		$file       = $state['file'];
		$delimiter  = isset( $state['delimiter'] ) ? $state['delimiter'] : CsvParser::detect_delimiter( $file );
		$batch_size = (int) $this->settings->get( 'import_export', 'import_batch_size', 50 );
		$headers    = CsvParser::read_headers( $file, $delimiter );
		$total      = CsvParser::count_rows( $file, $delimiter );
		$rows       = CsvParser::read_chunk( $file, $offset, $batch_size, $delimiter, $headers );

		$created = 0;
		$updated = 0;
		$skipped = 0;
		$failed  = 0;
		$log     = array();

		// Suspend cache invalidation for performance on large batches.
		wp_suspend_cache_invalidation( true );

		foreach ( $rows as $index => $raw_row ) {
			$row_num = $offset + $index + 2; // +2 for header row and 1-based counting.

			// Apply column mapping: build a keyed array from raw CSV row.
			$mapped = $this->apply_mapping( $raw_row, $mapping );

			$result = $this->process_row( $mapped, $opts );

			if ( is_wp_error( $result ) ) {
				++$failed;
				$log[] = array(
					'row'    => $row_num,
					'status' => 'failed',
					'msg'    => $result->get_error_message(),
				);
			} elseif ( 'skipped' === $result ) {
				++$skipped;
				$log[] = array(
					'row'    => $row_num,
					'status' => 'skipped',
					'msg'    => __( 'User already exists (skipped).', 'user-management-suite' ),
				);
			} elseif ( 'created' === $result[0] ) {
				++$created;
				$log[] = array(
					'row'    => $row_num,
					'status' => 'created',
					// translators: %d = user ID.
					'msg'    => sprintf( __( 'Created user #%d.', 'user-management-suite' ), $result[1] ),
				);
			} else {
				++$updated;
				$log[] = array(
					'row'    => $row_num,
					'status' => 'updated',
					// translators: %d = user ID.
					'msg'    => sprintf( __( 'Updated user #%d.', 'user-management-suite' ), $result[1] ),
				);
			}
		}

		wp_suspend_cache_invalidation( false );

		$done = ( $offset + $batch_size ) >= $total;

		return array(
			'processed' => count( $rows ),
			'created'   => $created,
			'updated'   => $updated,
			'skipped'   => $skipped,
			'failed'    => $failed,
			'log'       => $log,
			'done'      => $done,
			'total'     => $total,
		);
	}

	/**
	 * Delete the temp file and transient for a completed import.
	 *
	 * @param string $token Import token.
	 * @return void
	 */
	public function cleanup( $token ) {
		$state = get_transient( 'ums_ie_import_' . $token );
		if ( is_array( $state ) && ! empty( $state['file'] ) ) {
			wp_delete_file( $state['file'] );
		}
		delete_transient( 'ums_ie_import_' . $token );
	}

	// -------------------------------------------------------------------------
	// Private: row processing
	// -------------------------------------------------------------------------

	/**
	 * Apply the user-provided mapping to a raw CSV row.
	 *
	 * @param array<string,string> $raw_row Raw row keyed by CSV header string.
	 * @param array<string,string> $mapping CSV header → column key.
	 * @return array<string,string> Keyed by column id.
	 */
	private function apply_mapping( $raw_row, $mapping ) {
		$mapped = array();
		foreach ( $raw_row as $header => $value ) {
			$col_key = isset( $mapping[ $header ] ) ? $mapping[ $header ] : '';
			if ( '' !== $col_key && '__skip' !== $col_key ) {
				$mapped[ $col_key ] = $value;
			}
		}
		return $mapped;
	}

	/**
	 * Create or update one user from a mapped data row.
	 *
	 * @param array<string,string> $data Column-keyed row data.
	 * @param array<string,mixed>  $opts Import options.
	 * @return \WP_Error|string|array<int,mixed> WP_Error, 'skipped', or ['created'|'updated', $user_id].
	 */
	private function process_row( $data, $opts ) {
		$merge_with   = sanitize_key( isset( $opts['merge_with'] ) ? $opts['merge_with'] : 'email' );
		$found_action = sanitize_key( isset( $opts['found_action'] ) ? $opts['found_action'] : 'skip' );
		$send_email   = ! empty( $opts['send_email'] );
		$default_role = sanitize_key( isset( $opts['default_role'] ) ? $opts['default_role'] : get_option( 'default_role', 'subscriber' ) );

		$email    = isset( $data['user_email'] ) ? sanitize_email( $data['user_email'] ) : '';
		$username = isset( $data['user_login'] ) ? sanitize_user( $data['user_login'] ) : '';
		$given_id = isset( $data['ID'] ) ? absint( $data['ID'] ) : 0;

		// Locate existing user.
		$existing_id = 0;

		if ( 'email' === $merge_with && $email ) {
			$existing = get_user_by( 'email', $email );
			if ( $existing ) {
				$existing_id = $existing->ID;
			}
		} elseif ( 'username' === $merge_with && $username ) {
			$existing = get_user_by( 'login', $username );
			if ( $existing ) {
				$existing_id = $existing->ID;
			}
		} elseif ( 'id' === $merge_with && $given_id ) {
			if ( get_userdata( $given_id ) ) {
				$existing_id = $given_id;
			}
		}

		if ( $existing_id ) {
			if ( 'skip' === $found_action ) {
				return 'skipped';
			}
			// Update existing.
			$result = $this->update_user( $existing_id, $data, $default_role );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return array( 'updated', $existing_id );
		}

		// Create new user.
		if ( empty( $email ) ) {
			return new \WP_Error( 'missing_email', __( 'Email is required to create a user.', 'user-management-suite' ) );
		}

		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'invalid_email', __( 'Invalid email address.', 'user-management-suite' ) );
		}

		$result = $this->create_user( $data, $default_role, $send_email );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array( 'created', $result );
	}

	/**
	 * Create a new WP user from a mapped data row.
	 *
	 * @param array<string,string> $data         Column-keyed data.
	 * @param string               $default_role Fallback role.
	 * @param bool                 $send_email   Whether to send the WP new-user email.
	 * @return int|\WP_Error New user ID or error.
	 */
	private function create_user( $data, $default_role, $send_email ) {
		$userdata = $this->build_userdata( $data, $default_role );

		// Generate username if missing.
		if ( empty( $userdata['user_login'] ) ) {
			$base                   = strstr( $userdata['user_email'], '@', true );
			$userdata['user_login'] = sanitize_user( $base, true );
			// Ensure uniqueness.
			$userdata['user_login'] = wp_unique_username( $userdata['user_login'] );
		}

		// Ensure password.
		if ( empty( $userdata['user_pass'] ) ) {
			$userdata['user_pass'] = wp_generate_password( 18 );
		}

		if ( $send_email ) {
			$user_id = wp_insert_user( $userdata );
		} else {
			// wp_insert_user normally sends new-user email — suppress it via filter.
			add_filter( 'wp_send_new_user_notification_to_user', '__return_false' );
			$user_id = wp_insert_user( $userdata );
			remove_filter( 'wp_send_new_user_notification_to_user', '__return_false' );
		}

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$this->save_meta( $user_id, $data );

		return $user_id;
	}

	/**
	 * Update an existing WP user from a mapped data row.
	 *
	 * @param int                  $user_id      User to update.
	 * @param array<string,string> $data         Column-keyed data.
	 * @param string               $default_role Fallback role (only used if roles column not present).
	 * @return true|\WP_Error
	 */
	private function update_user( $user_id, $data, $default_role ) {
		// Never update the currently logged-in user (prevents self-lockout).
		if ( get_current_user_id() === $user_id ) {
			return new \WP_Error(
				'current_user',
				__( 'Cannot update the currently logged-in user via import.', 'user-management-suite' )
			);
		}

		$userdata       = $this->build_userdata( $data, $default_role );
		$userdata['ID'] = $user_id;

		// Don't change password unless the column was explicitly included.
		if ( empty( $data['user_pass'] ) ) {
			unset( $userdata['user_pass'] );
		}

		$result = wp_update_user( $userdata );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->save_meta( $user_id, $data );

		return true;
	}

	/**
	 * Build a userdata array suitable for wp_insert_user / wp_update_user.
	 *
	 * @param array<string,string> $data         Column-keyed data.
	 * @param string               $default_role Fallback role.
	 * @return array<string,mixed>
	 */
	private function build_userdata( $data, $default_role ) {
		$userdata = array();

		$field_map = array(
			'user_login'      => 'user_login',
			'user_email'      => 'user_email',
			'user_url'        => 'user_url',
			'user_registered' => 'user_registered',
			'display_name'    => 'display_name',
			'user_nicename'   => 'user_nicename',
			'description'     => 'description',
			'first_name'      => 'first_name',
			'last_name'       => 'last_name',
			'nickname'        => 'nickname',
			'locale'          => 'locale',
		);

		foreach ( $field_map as $col_key => $wp_key ) {
			if ( isset( $data[ $col_key ] ) && '' !== $data[ $col_key ] ) {
				$userdata[ $wp_key ] = sanitize_text_field( $data[ $col_key ] );
			}
		}

		// Email gets proper sanitization.
		if ( isset( $data['user_email'] ) ) {
			$userdata['user_email'] = sanitize_email( $data['user_email'] );
		}

		// URL.
		if ( isset( $data['user_url'] ) ) {
			$userdata['user_url'] = esc_url_raw( $data['user_url'] );
		}

		// Password: preserve hash if it already looks like a WP/bcrypt hash.
		if ( isset( $data['user_pass'] ) && '' !== $data['user_pass'] ) {
			$pass = $data['user_pass'];
			if ( $this->is_hashed_password( $pass ) ) {
				$userdata['user_pass'] = $pass; // Stored as-is; wp_insert_user will re-hash plain passwords.
			} else {
				$userdata['user_pass'] = $pass; // Plain-text: WP will hash it.
			}
		}

		// Roles: pipe-separated list.
		$valid_roles = array_keys( wp_roles()->get_names() );
		if ( isset( $data['roles'] ) && '' !== $data['roles'] ) {
			$submitted = array_filter( array_map( 'sanitize_key', preg_split( '/[|,]+/', $data['roles'] ) ) );
			$roles     = array_values( array_intersect( $valid_roles, $submitted ) );
		} else {
			$roles = array( $default_role );
		}
		$userdata['role'] = $roles[0]; // wp_insert_user only accepts a single role.

		// Additional roles set after insert via save_meta() path → stored in $data['roles'] for reference.

		return $userdata;
	}

	/**
	 * Save meta fields (including extra roles) for a user.
	 *
	 * @param int                  $user_id User ID.
	 * @param array<string,string> $data    Column-keyed row data.
	 * @return void
	 */
	private function save_meta( $user_id, $data ) {
		$all_cols    = Columns::all();
		$importable  = Columns::importable_keys();
		$valid_roles = array_keys( wp_roles()->get_names() );

		foreach ( $importable as $key ) {
			if ( ! isset( $data[ $key ] ) || '' === $data[ $key ] ) {
				continue;
			}

			$col   = isset( $all_cols[ $key ] ) ? $all_cols[ $key ] : array();
			$group = isset( $col['group'] ) ? $col['group'] : '';
			$value = $data[ $key ];

			// Core WP user fields already handled by build_userdata().
			if ( Columns::GROUP_CORE === $group && 'roles' !== $key ) {
				continue;
			}

			// Extra roles beyond the primary one.
			if ( 'roles' === $key ) {
				$user = get_userdata( $user_id );
				if ( $user ) {
					$submitted = array_filter( array_map( 'sanitize_key', preg_split( '/[|,]+/', $value ) ) );
					$roles     = array_values( array_intersect( $valid_roles, $submitted ) );
					// Reset and apply all roles.
					foreach ( (array) $user->roles as $role ) {
						$user->remove_role( $role );
					}
					foreach ( $roles as $role ) {
						$user->add_role( $role );
					}
				}
				continue;
			}

			// Meta and WooCommerce fields.
			if ( ! empty( $col['meta_key'] ) ) {
				update_user_meta( $user_id, $col['meta_key'], sanitize_text_field( $value ) );
			}
		}
	}

	/**
	 * Heuristic: check if a string looks like a WP or bcrypt password hash.
	 *
	 * @param string $pass Password string.
	 * @return bool
	 */
	private function is_hashed_password( $pass ) {
		// WP phpass ($P$) or bcrypt ($2y$/$2a$) — do not re-hash these on import.
		return (bool) preg_match( '/^\$(?:P\$|2[ay]\$)/', $pass );
	}

	// -------------------------------------------------------------------------
	// Private: helpers
	// -------------------------------------------------------------------------

	/**
	 * Retrieve and validate the import state transient.
	 *
	 * @param string $token Import token.
	 * @return array<string,mixed> State array or {error} array.
	 */
	private function get_state( $token ) {
		$state = get_transient( 'ums_ie_import_' . $token );
		if ( ! is_array( $state ) || empty( $state['file'] ) ) {
			return array( 'error' => __( 'Import session expired or not found. Please re-upload the file.', 'user-management-suite' ) );
		}
		if ( ! file_exists( $state['file'] ) ) {
			return array( 'error' => __( 'Import file not found. Please re-upload.', 'user-management-suite' ) );
		}
		return $state;
	}

	/**
	 * Ensure the upload temp directory exists and is web-protected.
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
	 * Absolute path to the shared temp directory.
	 *
	 * @return string
	 */
	private function temp_dir() {
		$upload = wp_upload_dir();
		return $upload['basedir'] . '/ums-ie';
	}

	/**
	 * Absolute path for an import temp file.
	 *
	 * @param string $filename Filename within the temp dir.
	 * @return string
	 */
	private function temp_path( $filename ) {
		return $this->temp_dir() . '/' . sanitize_file_name( $filename );
	}
}
