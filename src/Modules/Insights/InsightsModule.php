<?php
/**
 * Insights module: rollup maintenance and the reporting screens.
 *
 * @package UserManagementSuite
 */

namespace Marinski\UserManagementSuite\Modules\Insights;

use Marinski\UserManagementSuite\Modules\AbstractModule;
use Marinski\UserManagementSuite\Modules\Insights\Reports\AcquisitionReport;
use Marinski\UserManagementSuite\Modules\Insights\Reports\EmailHealthReport;
use Marinski\UserManagementSuite\Modules\Insights\Reports\FunnelReport;
use Marinski\UserManagementSuite\Modules\Insights\Reports\GrowthReport;
use Marinski\UserManagementSuite\Modules\Insights\Reports\RetentionReport;
use Marinski\UserManagementSuite\Settings\Fields;
use Marinski\UserManagementSuite\Settings\ProvidesSettings;
use Marinski\UserManagementSuite\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the reporting screens and the nightly job that feeds them.
 */
class InsightsModule extends AbstractModule implements ProvidesSettings {

	const CRON_HOOK      = 'ums_daily_rollup';
	const REBUILD_ACTION = 'ums_rebuild_stats';

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'insights';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return __( 'Insights & Reports', 'user-management-suite' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function register() {
		add_action( self::CRON_HOOK, array( $this, 'run_rollup' ) );
		$this->maybe_schedule();

		add_action( 'ums_insights_render_growth', array( GrowthReport::class, 'render' ) );
		add_action( 'ums_insights_render_acquisition', array( AcquisitionReport::class, 'render' ) );
		add_action( 'ums_insights_render_funnel', array( FunnelReport::class, 'render' ) );
		add_action( 'ums_insights_render_retention', array( RetentionReport::class, 'render' ) );
		add_action( 'ums_insights_render_email', array( EmailHealthReport::class, 'render' ) );

		if ( is_admin() ) {
			( new Dashboard() )->register();

			add_action( 'ums_render_tools_tab', array( $this, 'render_tools' ) );
			add_action( 'admin_post_' . self::REBUILD_ACTION, array( $this, 'handle_rebuild' ) );
		}
	}

	/**
	 * Make sure the nightly event exists (or does not, when switched off).
	 *
	 * @return void
	 */
	private function maybe_schedule() {
		$wanted    = (bool) $this->settings->get( 'insights', 'nightly_rebuild', true );
		$scheduled = wp_next_scheduled( self::CRON_HOOK );

		if ( $wanted && ! $scheduled ) {
			// Just after midnight site time, when the previous day is complete.
			$next = strtotime( 'tomorrow 00:20', (int) current_time( 'timestamp' ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- Local midnight is the intent.
			wp_schedule_event( $next ? $next : time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );

			return;
		}

		if ( ! $wanted && $scheduled ) {
			wp_unschedule_event( $scheduled, self::CRON_HOOK );
		}
	}

	/**
	 * Nightly job: rebuild a trailing window rather than the whole history.
	 *
	 * The window matters because late-arriving data (a backfill, a corrected
	 * timezone, a user created by an importer) only ever lands in recent days.
	 *
	 * @return void
	 */
	public function run_rollup() {
		$days = max( 1, (int) $this->settings->get( 'insights', 'rebuild_days', 7 ) );
		$to   = wp_date( 'Y-m-d' );
		$from = wp_date( 'Y-m-d', strtotime( $to . ' -' . ( $days - 1 ) . ' days' ) );

		Aggregator::rebuild( $from, $to );

		// Must run daily to exist at all: last-login is one moving value per user,
		// so a day not sampled is a day that can never be recovered.
		Aggregator::snapshot_active( $to );
	}

	/**
	 * Add a rebuild button to the Tools tab.
	 *
	 * @return void
	 */
	public function render_tools() {
		$url = wp_nonce_url(
			add_query_arg( 'action', self::REBUILD_ACTION, admin_url( 'admin-post.php' ) ),
			self::REBUILD_ACTION
		);

		Fields::row_start( __( 'Insights rollup', 'user-management-suite' ) );
		echo '<a class="button button-secondary" href="' . esc_url( $url ) . '">'
			. esc_html__( 'Rebuild the last 30 days', 'user-management-suite' ) . '</a>';
		echo '<p class="description">'
			. esc_html__( 'Rebuilding the full history can take minutes on a large site, so it is a WP-CLI job:', 'user-management-suite' )
			. ' <code>wp ums stats rebuild --all</code></p>';

		$last = (int) get_option( 'ums_stats_last_build', 0 );
		if ( $last ) {
			echo '<p class="description">' . esc_html(
				sprintf(
					/* translators: %s: date and time. */
					__( 'Last built %s.', 'user-management-suite' ),
					wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last )
				)
			) . '</p>';
		}
		Fields::row_end();
	}

	/**
	 * Handle the manual rebuild button.
	 *
	 * @return void
	 */
	public function handle_rebuild() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'user-management-suite' ) );
		}

		check_admin_referer( self::REBUILD_ACTION );

		$to   = wp_date( 'Y-m-d' );
		$from = wp_date( 'Y-m-d', strtotime( $to . ' -29 days' ) );

		$result = Aggregator::rebuild( $from, $to );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => 'user-management-suite',
					'tab'       => 'tools',
					'ums_built' => (int) $result['rows'],
				),
				admin_url( 'users.php' )
			)
		);
		exit;
	}

	/**
	 * {@inheritDoc}
	 */
	public function settings_tab_id() {
		return 'insights';
	}

	/**
	 * {@inheritDoc}
	 */
	public function settings_tab_label() {
		return __( 'Insights', 'user-management-suite' );
	}

	/**
	 * Render the Insights settings tab.
	 *
	 * @param SettingsRepository $settings Settings repository.
	 * @return void
	 */
	public function render_settings_tab( SettingsRepository $settings ) {
		Fields::row_start( __( 'Nightly rollup', 'user-management-suite' ) );
		Fields::checkbox(
			'insights',
			'nightly_rebuild',
			$settings->get( 'insights', 'nightly_rebuild', true ),
			__( 'Rebuild recent reporting data every night', 'user-management-suite' ),
			__( 'Reports read a pre-built summary table, never the user table directly. Without this job the figures stop moving.', 'user-management-suite' )
		);
		Fields::row_end();

		Fields::row_start( __( 'Rebuild window', 'user-management-suite' ) );
		Fields::text( 'insights', 'rebuild_days', $settings->get( 'insights', 'rebuild_days', 7 ), '7', 'number' );
		echo '<p class="description">'
			. esc_html__( 'How many trailing days the nightly job recalculates. Seven is enough to absorb late-arriving data without re-reading the whole history.', 'user-management-suite' )
			. '</p>';
		Fields::row_end();

		$next = wp_next_scheduled( self::CRON_HOOK );
		Fields::row_start( __( 'Next run', 'user-management-suite' ) );
		echo '<p>' . esc_html(
			$next
				? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next )
				: __( 'Not scheduled.', 'user-management-suite' )
		) . '</p>';
		Fields::row_end();
	}

	/**
	 * Sanitize this module's settings section.
	 *
	 * @param array<string,mixed> $input   Raw submitted ums_settings array.
	 * @param array<string,mixed> $current Current (defaults-merged) settings.
	 * @return array<string,mixed>
	 */
	public function sanitize_settings( array $input, array $current ) {
		if ( ! isset( $input['insights'] ) || ! is_array( $input['insights'] ) ) {
			return array();
		}

		$in = $input['insights'];

		return array(
			'insights' => array(
				'nightly_rebuild' => ! empty( $in['nightly_rebuild'] ),
				'rebuild_days'    => max( 1, min( 90, (int) ( $in['rebuild_days'] ?? 7 ) ) ),
			),
		);
	}
}
