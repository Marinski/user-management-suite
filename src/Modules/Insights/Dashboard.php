<?php
/**
 * The Insights admin screen.
 *
 * @package UserManagementSuite
 */

namespace Marinski\UserManagementSuite\Modules\Insights;

use Marinski\UserManagementSuite\Modules\Attribution\ChannelMap;
use Marinski\UserManagementSuite\Modules\Insights\Reports\AcquisitionReport;
use Marinski\UserManagementSuite\Modules\Insights\Reports\EmailHealthReport;
use Marinski\UserManagementSuite\Modules\Insights\Reports\FunnelReport;
use Marinski\UserManagementSuite\Modules\Insights\Reports\GrowthReport;
use Marinski\UserManagementSuite\Modules\Insights\Reports\RetentionReport;

defined( 'ABSPATH' ) || exit;

/**
 * Page shell: menu registration, range picker, tab routing and CSV export.
 */
class Dashboard {

	const PAGE_SLUG = 'ums-insights';
	const EXPORT_ACTION = 'ums_insights_export';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_post_' . self::EXPORT_ACTION, array( $this, 'handle_export' ) );
		add_action( 'wp_dashboard_setup', array( $this, 'add_widget' ) );
	}

	/**
	 * Capability required to view reports.
	 *
	 * @return string
	 */
	public static function capability() {
		/**
		 * Filters the capability required to view the Insights reports.
		 *
		 * Defaults to manage_options because acquisition data is closer to
		 * business intelligence than to routine user administration.
		 *
		 * @param string $capability Capability name.
		 */
		return (string) apply_filters( 'ums_insights_capability', 'manage_options' );
	}

	/**
	 * Report tabs: id => label.
	 *
	 * @return array<string,string>
	 */
	public static function tabs() {
		$tabs = array(
			'growth'      => __( 'Growth', 'user-management-suite' ),
			'acquisition' => __( 'Acquisition', 'user-management-suite' ),
			'funnel'      => __( 'Activation', 'user-management-suite' ),
			'retention'   => __( 'Retention', 'user-management-suite' ),
			'email'       => __( 'Email health', 'user-management-suite' ),
		);

		/**
		 * Filters the Insights report tabs.
		 *
		 * @param array<string,string> $tabs Tab id => label.
		 */
		return apply_filters( 'ums_insights_tabs', $tabs );
	}

	/**
	 * Add the submenu page.
	 *
	 * @return void
	 */
	public function add_page() {
		add_submenu_page(
			'users.php',
			__( 'User Insights', 'user-management-suite' ),
			__( 'Insights', 'user-management-suite' ),
			self::capability(),
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Enqueue the report stylesheet on this screen only.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue( $hook ) {
		if ( ( 'users_page_' . self::PAGE_SLUG ) !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'ums-insights',
			UMS_PLUGIN_URL . 'assets/admin/css/insights.css',
			array(),
			UMS_VERSION
		);
	}

	/**
	 * Read the current range from the query string.
	 *
	 * @return Range
	 */
	private function current_range() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only report filter.
		$request = wp_unslash( $_GET );

		return Range::from_request( is_array( $request ) ? $request : array() );
	}

	/**
	 * Render the reports page.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to view these reports.', 'user-management-suite' ) );
		}

		$tabs = self::tabs();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab switch.
		$current = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'growth';

		if ( ! isset( $tabs[ $current ] ) ) {
			$current = 'growth';
		}

		$range = $this->current_range();
		?>
		<div class="wrap ums-insights">
			<h1><?php esc_html_e( 'User Insights', 'user-management-suite' ); ?></h1>

			<?php $this->maybe_render_no_data_notice(); ?>

			<h2 class="nav-tab-wrapper">
				<?php foreach ( $tabs as $id => $label ) : ?>
					<a class="nav-tab <?php echo $id === $current ? 'nav-tab-active' : ''; ?>"
						href="<?php echo esc_url( $this->tab_url( $id, $range ) ); ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</h2>

			<?php $this->render_range_picker( $current, $range ); ?>

			<?php
			/**
			 * Renders the body of an Insights tab.
			 *
			 * @param Range $range Selected range.
			 */
			do_action( 'ums_insights_render_' . $current, $range );
			?>

			<p class="ums-footnote">
				<?php
				$last = (int) get_option( 'ums_stats_last_build', 0 );

				if ( $last ) {
					printf(
						/* translators: %s: date and time of the last rollup build. */
						esc_html__( 'Figures come from the nightly rollup, last built %s.', 'user-management-suite' ),
						esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last ) )
					);
				} else {
					esc_html_e( 'The rollup has not been built yet. Run: wp ums stats rebuild --all', 'user-management-suite' );
				}
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Warn when the rollup table is empty, with the command that fixes it.
	 *
	 * @return void
	 */
	private function maybe_render_no_data_notice() {
		if ( StatsStore::row_count() > 0 ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>'
			. esc_html__( 'No rollup data yet. Build it with WP-CLI:', 'user-management-suite' )
			. ' <code>wp ums attribution backfill</code> ' . esc_html__( 'then', 'user-management-suite' )
			. ' <code>wp ums stats rebuild --all</code></p></div>';
	}

	/**
	 * URL for a tab, preserving the selected range.
	 *
	 * @param string $tab   Tab id.
	 * @param Range  $range Current range.
	 * @return string
	 */
	private function tab_url( $tab, Range $range ) {
		return add_query_arg(
			array(
				'page'  => self::PAGE_SLUG,
				'tab'   => $tab,
				'range' => $range->preset,
				'from'  => $range->from,
				'to'    => $range->to,
			),
			admin_url( 'users.php' )
		);
	}

	/**
	 * Render the range selector and export button.
	 *
	 * @param string $tab   Current tab.
	 * @param Range  $range Current range.
	 * @return void
	 */
	private function render_range_picker( $tab, Range $range ) {
		?>
		<form method="get" class="ums-range-picker">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
			<input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>" />

			<label for="ums-range"><?php esc_html_e( 'Period', 'user-management-suite' ); ?></label>
			<select name="range" id="ums-range">
				<?php foreach ( Range::presets() as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $range->preset, $key ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<label for="ums-from" class="screen-reader-text"><?php esc_html_e( 'From', 'user-management-suite' ); ?></label>
			<input type="date" id="ums-from" name="from" value="<?php echo esc_attr( $range->from ); ?>" />

			<label for="ums-to" class="screen-reader-text"><?php esc_html_e( 'To', 'user-management-suite' ); ?></label>
			<input type="date" id="ums-to" name="to" value="<?php echo esc_attr( $range->to ); ?>" />

			<?php submit_button( __( 'Apply', 'user-management-suite' ), 'secondary', '', false ); ?>

			<span class="ums-range-summary">
				<?php
				printf(
					/* translators: 1: range label, 2: start date, 3: end date. */
					esc_html__( '%1$s — %2$s to %3$s', 'user-management-suite' ),
					esc_html( $range->label() ),
					esc_html( $range->format( $range->from ) ),
					esc_html( $range->format( $range->to ) )
				);
				?>
			</span>

			<a class="button button-secondary ums-export"
				href="<?php echo esc_url( $this->export_url( $tab, $range ) ); ?>">
				<?php esc_html_e( 'Export CSV', 'user-management-suite' ); ?>
			</a>
		</form>
		<?php
	}

	/**
	 * Nonce-protected export URL for the current view.
	 *
	 * @param string $tab   Report id.
	 * @param Range  $range Current range.
	 * @return string
	 */
	private function export_url( $tab, Range $range ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::EXPORT_ACTION,
					'report' => $tab,
					'range'  => $range->preset,
					'from'   => $range->from,
					'to'     => $range->to,
				),
				admin_url( 'admin-post.php' )
			),
			self::EXPORT_ACTION
		);
	}

	/**
	 * Stream the current report as CSV.
	 *
	 * @return void
	 */
	public function handle_export() {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to export these reports.', 'user-management-suite' ) );
		}

		check_admin_referer( self::EXPORT_ACTION );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified above.
		$request = wp_unslash( $_GET );
		$request = is_array( $request ) ? $request : array();
		$range   = Range::from_request( $request );
		$report  = isset( $request['report'] ) ? sanitize_key( (string) $request['report'] ) : 'growth';

		$exporters = array(
			'growth'      => array( GrowthReport::class, 'csv_rows' ),
			'acquisition' => array( AcquisitionReport::class, 'csv_rows' ),
			'funnel'      => array( FunnelReport::class, 'csv_rows' ),
			'retention'   => array( RetentionReport::class, 'csv_rows' ),
			'email'       => array( EmailHealthReport::class, 'csv_rows' ),
		);

		$callback = isset( $exporters[ $report ] ) ? $exporters[ $report ] : $exporters['growth'];
		$rows     = call_user_func( $callback, $range );

		$filename = sprintf( 'ums-%s-%s-to-%s.csv', $report, $range->from, $range->to );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );

		$handle = fopen( 'php://output', 'w' );

		foreach ( $rows as $row ) {
			fputcsv( $handle, $row );
		}

		fclose( $handle );
		exit;
	}

	/**
	 * Register the at-a-glance dashboard widget.
	 *
	 * @return void
	 */
	public function add_widget() {
		if ( ! current_user_can( self::capability() ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'ums_insights_widget',
			__( 'User growth', 'user-management-suite' ),
			array( $this, 'render_widget' )
		);
	}

	/**
	 * Render the dashboard widget: last 30 days at a glance.
	 *
	 * @return void
	 */
	public function render_widget() {
		$range = Range::from_request( array( 'range' => '30' ) );

		$current  = StatsStore::total( StatsStore::METRIC_SIGNUPS, $range->from, $range->to );
		$previous = StatsStore::total( StatsStore::METRIC_SIGNUPS, $range->prev_from, $range->prev_to );
		$channels = StatsStore::totals( StatsStore::METRIC_SIGNUPS, StatsStore::DIM_CHANNEL, $range->from, $range->to, 3 );

		echo '<p class="ums-widget-headline"><strong>' . esc_html( number_format_i18n( $current ) ) . '</strong> '
			. esc_html__( 'signups in the last 30 days', 'user-management-suite' ) . ' '
			. wp_kses_post( GrowthReport::delta_html( $current, $previous ) ) . '</p>';

		if ( array() !== $channels ) {
			echo '<ul class="ums-widget-list">';
			foreach ( $channels as $channel => $count ) {
				echo '<li><span>' . esc_html( ChannelMap::label( $channel ) ) . '</span><strong>'
					. esc_html( number_format_i18n( $count ) ) . '</strong></li>';
			}
			echo '</ul>';
		}

		echo '<p><a href="' . esc_url( add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'users.php' ) ) ) . '">'
			. esc_html__( 'View full reports', 'user-management-suite' ) . '</a></p>';
	}
}
