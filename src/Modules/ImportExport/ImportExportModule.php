<?php
/**
 * Import / Export module.
 *
 * @package UserManagementSuite
 */

namespace Marinski\UserManagementSuite\Modules\ImportExport;

use Marinski\UserManagementSuite\Modules\AbstractModule;
use Marinski\UserManagementSuite\Settings\ProvidesSettings;
use Marinski\UserManagementSuite\Settings\SettingsRepository;
use Marinski\UserManagementSuite\Settings\Fields;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a dedicated Import / Export admin page under Users, with:
 *  - Export: role/date/email filters, column picker, AJAX batch export.
 *  - Import: CSV upload, field mapping, AJAX batch import, results log.
 *  - WooCommerce billing/shipping support (when WC active).
 */
class ImportExportModule extends AbstractModule implements ProvidesSettings {

	const PAGE_SLUG   = 'ums-import-export';
	const NONCE_IE    = 'ums_ie_action';
	const AJAX_PREFIX = 'ums_ie_';

	/**
	 * Export handler instance.
	 *
	 * @var ExportHandler
	 */
	private $exporter;

	/**
	 * Import handler instance.
	 *
	 * @var ImportHandler
	 */
	private $importer;

	/**
	 * Constructor.
	 *
	 * @param SettingsRepository $settings Settings repository.
	 */
	public function __construct( SettingsRepository $settings ) {
		parent::__construct( $settings );
		$this->exporter = new ExportHandler( $settings );
		$this->importer = new ImportHandler( $settings );
	}

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'import_export';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return __( 'Import / Export', 'user-management-suite' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function register() {
		// Admin page.
		add_action( 'admin_menu', array( $this, 'add_page' ) );

		// Asset enqueueing.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );

		// AJAX handlers.
		add_action( 'wp_ajax_ums_ie_export_init', array( $this, 'ajax_export_init' ) );
		add_action( 'wp_ajax_ums_ie_export_batch', array( $this, 'ajax_export_batch' ) );
		add_action( 'wp_ajax_ums_ie_import_upload', array( $this, 'ajax_import_upload' ) );
		add_action( 'wp_ajax_ums_ie_import_info', array( $this, 'ajax_import_info' ) );
		add_action( 'wp_ajax_ums_ie_import_batch', array( $this, 'ajax_import_batch' ) );
		add_action( 'wp_ajax_ums_ie_import_done', array( $this, 'ajax_import_done' ) );

		// File download.
		add_action( 'admin_post_ums_ie_download', array( $this, 'handle_download' ) );

		// Tools tab link.
		add_action( 'ums_render_tools_tab', array( $this, 'render_tools_row' ) );
	}

	// -------------------------------------------------------------------------
	// Admin menu & assets
	// -------------------------------------------------------------------------

	/**
	 * Register the submenu page under Users.
	 *
	 * @return void
	 */
	public function add_page() {
		add_submenu_page(
			'users.php',
			__( 'Import / Export Users', 'user-management-suite' ),
			__( 'Import / Export', 'user-management-suite' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue JS and CSS on the Import/Export admin page.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue( $hook ) {
		if ( false === strpos( $hook, self::PAGE_SLUG ) ) {
			return;
		}

		wp_enqueue_style(
			'ums-import-export',
			UMS_PLUGIN_URL . 'assets/admin/css/ums-import-export.css',
			array(),
			UMS_VERSION
		);

		wp_enqueue_script(
			'ums-import-export',
			UMS_PLUGIN_URL . 'assets/admin/js/ums-import-export.js',
			array( 'jquery' ),
			UMS_VERSION,
			true
		);

		wp_localize_script(
			'ums-import-export',
			'umsIE',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( self::NONCE_IE ),
				'downloadUrl' => esc_url( admin_url( 'admin-post.php' ) ),
				'i18n'        => array(
					'exporting'    => __( 'Exporting…', 'user-management-suite' ),
					'exported'     => __( 'Export complete.', 'user-management-suite' ),
					'downloading'  => __( 'Preparing download…', 'user-management-suite' ),
					'uploading'    => __( 'Uploading file…', 'user-management-suite' ),
					'mapping'      => __( 'Mapping columns…', 'user-management-suite' ),
					'importing'    => __( 'Importing…', 'user-management-suite' ),
					'imported'     => __( 'Import complete.', 'user-management-suite' ),
					'errorGeneric' => __( 'An error occurred. Please try again.', 'user-management-suite' ),
					'confirmReset' => __( 'Start a new import? The current progress will be lost.', 'user-management-suite' ),
					/* translators: %d = number of rows */
					'rowCount'     => __( '%d rows found.', 'user-management-suite' ),
					/* translators: %1$d = processed, %2$d = total */
					'progress'     => __( '%1$d / %2$d processed', 'user-management-suite' ),
				),
				'columns'     => $this->columns_for_js(),
				'roles'       => $this->roles_for_js(),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Page rendering
	// -------------------------------------------------------------------------

	/**
	 * Render the full Import / Export admin page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'user-management-suite' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab switch.
		$tab = isset( $_GET['ie_tab'] ) ? sanitize_key( wp_unslash( $_GET['ie_tab'] ) ) : 'export';
		if ( ! in_array( $tab, array( 'export', 'import' ), true ) ) {
			$tab = 'export';
		}

		$tabs = array(
			'export' => __( 'Export', 'user-management-suite' ),
			'import' => __( 'Import', 'user-management-suite' ),
		);
		?>
		<div class="wrap ums-ie-wrap">
			<h1><?php esc_html_e( 'Import / Export Users', 'user-management-suite' ); ?></h1>

			<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Import/Export tabs', 'user-management-suite' ); ?>">
				<?php foreach ( $tabs as $id => $label ) : ?>
					<a class="nav-tab<?php echo $id === $tab ? ' nav-tab-active' : ''; ?>"
						href="
						<?php
						echo esc_url(
							add_query_arg(
								array(
									'page'   => self::PAGE_SLUG,
									'ie_tab' => $id,
								),
								admin_url( 'users.php' )
							)
						);
						?>
								">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="ums-ie-tab-content">
				<?php if ( 'export' === $tab ) : ?>
					<?php $this->render_export_tab(); ?>
				<?php else : ?>
					<?php $this->render_import_tab(); ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the Export tab.
	 *
	 * @return void
	 */
	private function render_export_tab() {
		$roles = wp_roles()->get_names();
		?>
		<div class="ums-ie-section">
			<h2><?php esc_html_e( 'Filter users', 'user-management-suite' ); ?></h2>
			<table class="form-table ums-ie-filter-table" role="presentation">
				<tr>
					<th scope="row"><label for="ums-ie-roles"><?php esc_html_e( 'User roles', 'user-management-suite' ); ?></label></th>
					<td>
						<select id="ums-ie-roles" name="ums_ie_roles[]" multiple style="min-width:220px;height:120px;">
							<?php foreach ( $roles as $slug => $name ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $name ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Hold Ctrl/Cmd to select multiple. Leave blank for all roles.', 'user-management-suite' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Registered date', 'user-management-suite' ); ?></th>
					<td>
						<label>
							<?php esc_html_e( 'From', 'user-management-suite' ); ?>
							<input type="date" id="ums-ie-date-from" name="ums_ie_date_from" />
						</label>
						&nbsp;
						<label>
							<?php esc_html_e( 'To', 'user-management-suite' ); ?>
							<input type="date" id="ums-ie-date-to" name="ums_ie_date_to" />
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ums-ie-search"><?php esc_html_e( 'Search', 'user-management-suite' ); ?></label></th>
					<td>
						<input type="text" id="ums-ie-search" name="ums_ie_search" class="regular-text"
								placeholder="<?php esc_attr_e( 'Email, username, or display name…', 'user-management-suite' ); ?>" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ums-ie-user-ids"><?php esc_html_e( 'Specific user IDs', 'user-management-suite' ); ?></label></th>
					<td>
						<input type="text" id="ums-ie-user-ids" name="ums_ie_user_ids" class="regular-text"
								placeholder="<?php esc_attr_e( 'e.g. 12, 34, 56', 'user-management-suite' ); ?>" />
						<p class="description"><?php esc_html_e( 'Comma-separated IDs. Leave blank for all matched users.', 'user-management-suite' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ums-ie-delimiter"><?php esc_html_e( 'CSV delimiter', 'user-management-suite' ); ?></label></th>
					<td>
						<select id="ums-ie-delimiter" name="ums_ie_delimiter">
							<option value=","><?php esc_html_e( 'Comma (,)', 'user-management-suite' ); ?></option>
							<option value=";"><?php esc_html_e( 'Semicolon (;)', 'user-management-suite' ); ?></option>
							<option value="&#9;"><?php esc_html_e( 'Tab', 'user-management-suite' ); ?></option>
							<option value="|"><?php esc_html_e( 'Pipe (|)', 'user-management-suite' ); ?></option>
						</select>
					</td>
				</tr>
			</table>
		</div>

		<div class="ums-ie-section">
			<h2><?php esc_html_e( 'Select columns', 'user-management-suite' ); ?></h2>
			<div id="ums-ie-columns-wrap">
				<?php foreach ( Columns::grouped() as $group_label => $cols ) : ?>
					<fieldset class="ums-ie-col-group">
						<legend><?php echo esc_html( $group_label ); ?></legend>
						<?php foreach ( $cols as $key => $col ) : ?>
							<label class="ums-ie-col-label">
								<input type="checkbox" class="ums-ie-col-cb" value="<?php echo esc_attr( $key ); ?>"
										<?php checked( ! empty( $col['default'] ) ); ?> />
								<?php echo esc_html( $col['label'] ); ?>
							</label>
						<?php endforeach; ?>
					</fieldset>
				<?php endforeach; ?>
			</div>
			<p>
				<button type="button" id="ums-ie-select-all" class="button button-link"><?php esc_html_e( 'Select all', 'user-management-suite' ); ?></button>
				&nbsp;|&nbsp;
				<button type="button" id="ums-ie-select-none" class="button button-link"><?php esc_html_e( 'Deselect all', 'user-management-suite' ); ?></button>
			</p>
		</div>

		<div class="ums-ie-section">
			<button type="button" id="ums-ie-export-btn" class="button button-primary button-large">
				<?php esc_html_e( 'Export CSV', 'user-management-suite' ); ?>
			</button>
			<span id="ums-ie-export-status" class="ums-ie-status" style="display:none;"></span>
			<div id="ums-ie-export-progress" class="ums-ie-progress" style="display:none;">
				<div class="ums-ie-progress-bar"><div id="ums-ie-export-bar" class="ums-ie-progress-fill"></div></div>
				<span id="ums-ie-export-count"></span>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the Import tab.
	 *
	 * @return void
	 */
	private function render_import_tab() {
		$roles        = wp_roles()->get_names();
		$default_role = get_option( 'default_role', 'subscriber' );
		?>
		<div class="ums-ie-steps">
			<!-- Step 1: Upload -->
			<div class="ums-ie-step active" id="ums-ie-step-1">
				<h2><?php esc_html_e( 'Step 1 — Upload CSV file', 'user-management-suite' ); ?></h2>
				<div id="ums-ie-dropzone" class="ums-ie-dropzone">
					<p><?php esc_html_e( 'Drag & drop a CSV file here, or click to browse.', 'user-management-suite' ); ?></p>
					<input type="file" id="ums-ie-file-input" accept=".csv,.txt" style="display:none;" />
					<button type="button" class="button" id="ums-ie-browse-btn">
						<?php esc_html_e( 'Choose file', 'user-management-suite' ); ?>
					</button>
					<span id="ums-ie-file-name"></span>
				</div>
				<p class="description"><?php esc_html_e( 'Accepted formats: .csv, .txt. Delimiter is auto-detected (comma, semicolon, tab, pipe).', 'user-management-suite' ); ?></p>
				<div id="ums-ie-upload-status" class="ums-ie-status" style="display:none;"></div>
				<div class="ums-ie-step-actions">
					<button type="button" id="ums-ie-upload-btn" class="button button-primary" disabled>
						<?php esc_html_e( 'Upload & continue', 'user-management-suite' ); ?>
					</button>
				</div>
			</div>

			<!-- Step 2: Field mapping -->
			<div class="ums-ie-step" id="ums-ie-step-2" style="display:none;">
				<h2><?php esc_html_e( 'Step 2 — Map columns', 'user-management-suite' ); ?></h2>
				<p id="ums-ie-row-count" class="description"></p>
				<table class="widefat ums-ie-mapping-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'CSV column header', 'user-management-suite' ); ?></th>
							<th><?php esc_html_e( 'Map to WordPress field', 'user-management-suite' ); ?></th>
						</tr>
					</thead>
					<tbody id="ums-ie-mapping-rows">
						<!-- Populated by JS -->
					</tbody>
				</table>
				<div class="ums-ie-step-actions">
					<button type="button" id="ums-ie-back-1" class="button"><?php esc_html_e( '&larr; Back', 'user-management-suite' ); ?></button>
					<button type="button" id="ums-ie-to-step-3" class="button button-primary"><?php esc_html_e( 'Continue &rarr;', 'user-management-suite' ); ?></button>
				</div>
			</div>

			<!-- Step 3: Import options -->
			<div class="ums-ie-step" id="ums-ie-step-3" style="display:none;">
				<h2><?php esc_html_e( 'Step 3 — Import options', 'user-management-suite' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ums-ie-merge-with"><?php esc_html_e( 'Identify existing users by', 'user-management-suite' ); ?></label></th>
						<td>
							<select id="ums-ie-merge-with" name="ums_ie_merge_with">
								<option value="email"><?php esc_html_e( 'Email address', 'user-management-suite' ); ?></option>
								<option value="username"><?php esc_html_e( 'Username', 'user-management-suite' ); ?></option>
								<option value="id"><?php esc_html_e( 'User ID', 'user-management-suite' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ums-ie-found-action"><?php esc_html_e( 'If user already exists', 'user-management-suite' ); ?></label></th>
						<td>
							<select id="ums-ie-found-action" name="ums_ie_found_action">
								<option value="skip"><?php esc_html_e( 'Skip (keep existing)', 'user-management-suite' ); ?></option>
								<option value="update"><?php esc_html_e( 'Update existing user', 'user-management-suite' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ums-ie-default-role"><?php esc_html_e( 'Default role for new users', 'user-management-suite' ); ?></label></th>
						<td>
							<select id="ums-ie-default-role" name="ums_ie_default_role">
								<?php foreach ( $roles as $slug => $name ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $slug, $default_role ); ?>>
										<?php echo esc_html( $name ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Used when the CSV has no Roles column or the column is empty.', 'user-management-suite' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Welcome email', 'user-management-suite' ); ?></th>
						<td>
							<label>
								<input type="checkbox" id="ums-ie-send-email" name="ums_ie_send_email" value="1" />
								<?php esc_html_e( 'Send WordPress new-user notification to imported users', 'user-management-suite' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Leave unchecked for silent imports.', 'user-management-suite' ); ?></p>
						</td>
					</tr>
				</table>
				<div class="ums-ie-step-actions">
					<button type="button" id="ums-ie-back-2" class="button"><?php esc_html_e( '&larr; Back', 'user-management-suite' ); ?></button>
					<button type="button" id="ums-ie-start-import" class="button button-primary">
						<?php esc_html_e( 'Start import', 'user-management-suite' ); ?>
					</button>
				</div>
			</div>

			<!-- Step 4: Progress -->
			<div class="ums-ie-step" id="ums-ie-step-4" style="display:none;">
				<h2><?php esc_html_e( 'Step 4 — Importing…', 'user-management-suite' ); ?></h2>
				<div class="ums-ie-progress">
					<div class="ums-ie-progress-bar"><div id="ums-ie-import-bar" class="ums-ie-progress-fill"></div></div>
					<span id="ums-ie-import-count"></span>
				</div>
				<div id="ums-ie-import-status" class="ums-ie-status" style="display:none;"></div>
				<div id="ums-ie-import-log" class="ums-ie-log" style="display:none;"></div>
			</div>

			<!-- Step 5: Results -->
			<div class="ums-ie-step" id="ums-ie-step-5" style="display:none;">
				<h2><?php esc_html_e( 'Import complete', 'user-management-suite' ); ?></h2>
				<table class="widefat striped" id="ums-ie-results-table" style="max-width:400px;">
					<tbody>
						<tr><td><?php esc_html_e( 'Created', 'user-management-suite' ); ?></td><td id="ums-ie-res-created">0</td></tr>
						<tr><td><?php esc_html_e( 'Updated', 'user-management-suite' ); ?></td><td id="ums-ie-res-updated">0</td></tr>
						<tr><td><?php esc_html_e( 'Skipped', 'user-management-suite' ); ?></td><td id="ums-ie-res-skipped">0</td></tr>
						<tr><td><?php esc_html_e( 'Failed', 'user-management-suite' ); ?></td><td id="ums-ie-res-failed">0</td></tr>
					</tbody>
				</table>
				<p id="ums-ie-view-log-wrap" style="display:none;">
					<button type="button" id="ums-ie-view-log" class="button"><?php esc_html_e( 'View row-level log', 'user-management-suite' ); ?></button>
				</p>
				<div id="ums-ie-full-log" style="display:none;"></div>
				<p>
					<button type="button" id="ums-ie-new-import" class="button button-secondary">
						<?php esc_html_e( 'Start another import', 'user-management-suite' ); ?>
					</button>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Add a link row in the Tools tab pointing to the dedicated IE page.
	 *
	 * @param SettingsRepository $settings Settings repository.
	 * @return void
	 */
	public function render_tools_row( $settings ) {
		$url = add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'users.php' ) );
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Import / Export', 'user-management-suite' ); ?></th>
			<td>
				<a href="<?php echo esc_url( $url ); ?>" class="button">
					<?php esc_html_e( 'Open Import / Export', 'user-management-suite' ); ?>
				</a>
				<p class="description">
					<?php esc_html_e( 'Full CSV import and export with field mapping, batch processing, and WooCommerce support.', 'user-management-suite' ); ?>
				</p>
			</td>
		</tr>
		<?php
	}

	// -------------------------------------------------------------------------
	// AJAX handlers
	// -------------------------------------------------------------------------

	/**
	 * AJAX: initialise an export, return token + total count.
	 *
	 * @return void
	 */
	public function ajax_export_init() {
		$this->verify_ajax_nonce();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$filters   = array(
			'roles'     => isset( $_POST['roles'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['roles'] ) ) : array(),
			'date_from' => isset( $_POST['date_from'] ) ? sanitize_text_field( wp_unslash( $_POST['date_from'] ) ) : '',
			'date_to'   => isset( $_POST['date_to'] ) ? sanitize_text_field( wp_unslash( $_POST['date_to'] ) ) : '',
			'search'    => isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '',
			'user_ids'  => isset( $_POST['user_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['user_ids'] ) ) : '',
		);
		$columns   = isset( $_POST['columns'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['columns'] ) ) : array();
		$delimiter = isset( $_POST['delimiter'] ) ? $this->sanitize_delimiter( sanitize_text_field( wp_unslash( $_POST['delimiter'] ) ) ) : ',';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$this->exporter->cleanup_old_files();
		$result = $this->exporter->init_export( $filters, $columns, $delimiter );

		if ( isset( $result['error'] ) ) {
			wp_send_json_error( $result['error'] );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: process one export batch.
	 *
	 * @return void
	 */
	public function ajax_export_batch() {
		$this->verify_ajax_nonce();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$token  = isset( $_POST['token'] ) ? sanitize_key( wp_unslash( $_POST['token'] ) ) : '';
		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( empty( $token ) ) {
			wp_send_json_error( __( 'Missing export token.', 'user-management-suite' ) );
		}

		$result = $this->exporter->run_batch( $token, $offset );

		if ( isset( $result['error'] ) ) {
			wp_send_json_error( $result['error'] );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: handle CSV file upload for import.
	 *
	 * @return void
	 */
	public function ajax_import_upload() {
		$this->verify_ajax_nonce();

		$result = $this->importer->handle_upload();

		if ( isset( $result['error'] ) ) {
			wp_send_json_error( $result['error'] );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: return header info for the uploaded file (for mapping UI).
	 *
	 * @return void
	 */
	public function ajax_import_info() {
		$this->verify_ajax_nonce();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$token = isset( $_POST['token'] ) ? sanitize_key( wp_unslash( $_POST['token'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( empty( $token ) ) {
			wp_send_json_error( __( 'Missing import token.', 'user-management-suite' ) );
		}

		$result = $this->importer->get_file_info( $token );

		if ( isset( $result['error'] ) ) {
			wp_send_json_error( $result['error'] );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: process one import batch.
	 *
	 * @return void
	 */
	public function ajax_import_batch() {
		$this->verify_ajax_nonce();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$token  = isset( $_POST['token'] ) ? sanitize_key( wp_unslash( $_POST['token'] ) ) : '';
		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized key-by-key below.
		$raw_mapping = isset( $_POST['mapping'] ) && is_array( $_POST['mapping'] ) ? (array) $_POST['mapping'] : array();
		// Mapping keys (CSV headers) can contain spaces; sanitize keys and values individually.
		$mapping = array();
		foreach ( $raw_mapping as $header => $col_key ) {
			$mapping[ sanitize_text_field( wp_unslash( (string) $header ) ) ] = sanitize_key( wp_unslash( (string) $col_key ) );
		}
		$opts = array(
			'merge_with'   => isset( $_POST['merge_with'] ) ? sanitize_key( wp_unslash( $_POST['merge_with'] ) ) : 'email',
			'found_action' => isset( $_POST['found_action'] ) ? sanitize_key( wp_unslash( $_POST['found_action'] ) ) : 'skip',
			'default_role' => isset( $_POST['default_role'] ) ? sanitize_key( wp_unslash( $_POST['default_role'] ) ) : get_option( 'default_role', 'subscriber' ),
			'send_email'   => ! empty( $_POST['send_email'] ),
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( empty( $token ) ) {
			wp_send_json_error( __( 'Missing import token.', 'user-management-suite' ) );
		}

		$result = $this->importer->run_batch( $token, $mapping, $opts, $offset );

		if ( isset( $result['error'] ) ) {
			wp_send_json_error( $result['error'] );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: clean up after a completed import.
	 *
	 * @return void
	 */
	public function ajax_import_done() {
		$this->verify_ajax_nonce();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$token = isset( $_POST['token'] ) ? sanitize_key( wp_unslash( $_POST['token'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( $token ) {
			$this->importer->cleanup( $token );
		}

		wp_send_json_success();
	}

	/**
	 * Admin-post handler: stream the completed export CSV to the browser.
	 *
	 * @return void
	 */
	public function handle_download() {
		if ( ! current_user_can( 'manage_options' )
			|| ! isset( $_GET['_wpnonce'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), self::NONCE_IE ) ) {
			wp_die( esc_html__( 'Security check failed.', 'user-management-suite' ) );
		}

		$token = isset( $_GET['token'] ) ? sanitize_key( wp_unslash( $_GET['token'] ) ) : '';

		if ( empty( $token ) ) {
			wp_die( esc_html__( 'Missing export token.', 'user-management-suite' ) );
		}

		$this->exporter->serve_download( $token );
	}

	// -------------------------------------------------------------------------
	// ProvidesSettings
	// -------------------------------------------------------------------------

	/**
	 * Settings tab ID.
	 *
	 * @return string
	 */
	public function settings_tab_id() {
		return 'import_export';
	}

	/**
	 * Settings tab label.
	 *
	 * @return string
	 */
	public function settings_tab_label() {
		return __( 'Import / Export', 'user-management-suite' );
	}

	/**
	 * Render the Import / Export settings tab.
	 *
	 * @param SettingsRepository $settings Settings repository.
	 * @return void
	 */
	public function render_settings_tab( SettingsRepository $settings ) {
		Fields::row_start( __( 'Export batch size', 'user-management-suite' ) );
		?>
		<input type="number" min="50" max="1000" step="50"
				name="<?php echo esc_attr( Fields::name( 'import_export', 'export_batch_size' ) ); ?>"
				value="<?php echo esc_attr( (int) $settings->get( 'import_export', 'export_batch_size', 200 ) ); ?>" />
		<p class="description"><?php esc_html_e( 'Number of users written per AJAX call during export (50–1000).', 'user-management-suite' ); ?></p>
		<?php
		Fields::row_end();

		Fields::row_start( __( 'Import batch size', 'user-management-suite' ) );
		?>
		<input type="number" min="10" max="500" step="10"
				name="<?php echo esc_attr( Fields::name( 'import_export', 'import_batch_size' ) ); ?>"
				value="<?php echo esc_attr( (int) $settings->get( 'import_export', 'import_batch_size', 50 ) ); ?>" />
		<p class="description"><?php esc_html_e( 'Number of CSV rows processed per AJAX call during import (10–500). Lower is safer for slow hosts.', 'user-management-suite' ); ?></p>
		<?php
		Fields::row_end();
	}

	/**
	 * Sanitize the Import / Export settings section.
	 *
	 * @param array<string,mixed> $input   Submitted settings array.
	 * @param array<string,mixed> $current Current stored settings.
	 * @return array<string,mixed>
	 */
	public function sanitize_settings( array $input, array $current ) {
		if ( ! isset( $input['import_export'] ) || ! is_array( $input['import_export'] ) ) {
			return array();
		}

		$raw = $input['import_export'];

		return array(
			'import_export' => array(
				'export_batch_size' => max( 50, min( 1000, absint( $raw['export_batch_size'] ?? 200 ) ) ),
				'import_batch_size' => max( 10, min( 500, absint( $raw['import_batch_size'] ?? 50 ) ) ),
			),
		);
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Verify the shared AJAX nonce, die on failure.
	 *
	 * @return void
	 */
	private function verify_ajax_nonce() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'user-management-suite' ), 403 );
		}

		$nonce = isset( $_POST['nonce'] ) ? sanitize_key( wp_unslash( $_POST['nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! wp_verify_nonce( $nonce, self::NONCE_IE ) ) {
			wp_send_json_error( __( 'Security check failed.', 'user-management-suite' ), 403 );
		}
	}

	/**
	 * Sanitize a CSV delimiter to one of the allowed characters.
	 *
	 * @param string $raw Raw delimiter value.
	 * @return string
	 */
	private function sanitize_delimiter( $raw ) {
		$allowed = array( ',', ';', "\t", '|' );
		return in_array( $raw, $allowed, true ) ? $raw : ',';
	}

	/**
	 * Build a column list for JS localization (key, label, importable).
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function columns_for_js() {
		$out = array();
		foreach ( Columns::all() as $key => $col ) {
			$out[ $key ] = array(
				'label'      => $col['label'],
				'importable' => ! empty( $col['importable'] ),
			);
		}
		return $out;
	}

	/**
	 * Build a roles list for JS localization.
	 *
	 * @return array<string,string>
	 */
	private function roles_for_js() {
		return wp_roles()->get_names();
	}
}
