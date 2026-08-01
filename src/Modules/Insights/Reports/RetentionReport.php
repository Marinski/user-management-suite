<?php
/**
 * Retention: who is still here.
 *
 * @package UserManagementSuite
 */

namespace Marinski\UserManagementSuite\Modules\Insights\Reports;

use Marinski\UserManagementSuite\Modules\Insights\Aggregator;
use Marinski\UserManagementSuite\Modules\Insights\Chart;
use Marinski\UserManagementSuite\Modules\Insights\Range;
use Marinski\UserManagementSuite\Modules\Insights\StatsStore;

defined( 'ABSPATH' ) || exit;

/**
 * Active-user trend plus cohort survival by signup month.
 *
 * Deliberately not a full retention grid. WordPress stores one last-login value
 * per user, not a login history, so month-by-month return rates cannot be
 * reconstructed — only "when was this person last seen". Drawing a proper grid
 * from that data would mean inventing most of it.
 */
class RetentionReport {

	/**
	 * Render the report body.
	 *
	 * @param Range $range Selected range.
	 * @return void
	 */
	public static function render( Range $range ) {
		$windows = self::latest_active();

		GrowthReport::render_cards(
			array(
				array(
					'label' => __( 'Active today', 'user-management-suite' ),
					'value' => isset( $windows['d1'] ) ? number_format_i18n( $windows['d1'] ) : '—',
					'note'  => __( 'signed in within 24 hours', 'user-management-suite' ),
				),
				array(
					'label' => __( 'Active this week', 'user-management-suite' ),
					'value' => isset( $windows['d7'] ) ? number_format_i18n( $windows['d7'] ) : '—',
					'note'  => __( 'signed in within 7 days', 'user-management-suite' ),
				),
				array(
					'label' => __( 'Active this month', 'user-management-suite' ),
					'value' => isset( $windows['d30'] ) ? number_format_i18n( $windows['d30'] ) : '—',
					'note'  => __( 'signed in within 30 days', 'user-management-suite' ),
				),
				array(
					'label' => __( 'Active this quarter', 'user-management-suite' ),
					'value' => isset( $windows['d90'] ) ? number_format_i18n( $windows['d90'] ) : '—',
					'note'  => __( 'signed in within 90 days', 'user-management-suite' ),
				),
			)
		);

		echo '<div class="ums-panel"><h2>' . esc_html__( 'Monthly active users', 'user-management-suite' ) . '</h2>';

		$series = $range->fill( StatsStore::series( StatsStore::METRIC_ACTIVE, StatsStore::DIM_WINDOW, $range->from, $range->to, 'd30' ) );
		$series = array_filter( $series );

		if ( array() === $series ) {
			echo '<p class="ums-chart-empty">'
				. esc_html__( 'No snapshots yet. This series is built one day at a time by the nightly job — last-login is a single moving value, so past days cannot be reconstructed after the fact.', 'user-management-suite' )
				. '</p>';
		} else {
			Chart::output(
				Chart::line(
					$range->group( $series ),
					array(
						'label' => __( 'Monthly active users', 'user-management-suite' ),
						'color' => '#8c5e00',
					)
				)
			);
		}

		echo '</div>';

		self::render_cohorts();
	}

	/**
	 * The most recent active snapshot.
	 *
	 * @return array<string,int>
	 */
	private static function latest_active() {
		$bounds = StatsStore::date_bounds();

		if ( '' === $bounds['last'] ) {
			return array();
		}

		$from = wp_date( 'Y-m-d', strtotime( $bounds['last'] . ' -7 days' ) );

		return StatsStore::totals( StatsStore::METRIC_ACTIVE, StatsStore::DIM_WINDOW, $from, $bounds['last'] );
	}

	/**
	 * Cohort survival: of the people who joined in month M, how many have ever
	 * signed in, and how many are still active.
	 *
	 * @return void
	 */
	private static function render_cohorts() {
		$earliest = Aggregator::earliest_signup_date();
		$start    = wp_date( 'Y-m-01', max( strtotime( $earliest ), strtotime( '-24 months' ) ) );
		$end      = wp_date( 'Y-m-d' );

		$signups = self::monthly( StatsStore::METRIC_SIGNUPS, $start, $end );
		$active  = self::monthly( StatsStore::METRIC_FIRST_LOGIN, $start, $end );

		echo '<div class="ums-panel"><h2>' . esc_html__( 'Cohort survival by signup month', 'user-management-suite' ) . '</h2>';
		echo '<p class="description">'
			. esc_html__( 'WordPress keeps one last-login value per user rather than a login history, so this shows how many of each cohort have ever signed in — not a month-by-month return rate, which the stored data cannot support.', 'user-management-suite' )
			. '</p>';

		if ( array() === $signups ) {
			echo '<p class="ums-chart-empty">' . esc_html__( 'No cohorts yet.', 'user-management-suite' ) . '</p></div>';

			return;
		}

		echo '<table class="widefat striped"><thead><tr>'
			. '<th>' . esc_html__( 'Signup month', 'user-management-suite' ) . '</th>'
			. '<th class="ums-num">' . esc_html__( 'Joined', 'user-management-suite' ) . '</th>'
			. '<th class="ums-num">' . esc_html__( 'Ever signed in', 'user-management-suite' ) . '</th>'
			. '<th class="ums-num">' . esc_html__( 'Rate', 'user-management-suite' ) . '</th>'
			. '</tr></thead><tbody>';

		krsort( $signups );

		foreach ( $signups as $month => $count ) {
			$seen = isset( $active[ $month ] ) ? (int) $active[ $month ] : 0;
			$rate = $count > 0 ? ( $seen / $count ) * 100 : 0;
			$time = strtotime( $month . '-01' );

			printf(
				'<tr><td>%1$s</td><td class="ums-num">%2$s</td><td class="ums-num">%3$s</td><td class="ums-num">%4$s%%</td></tr>',
				esc_html( $time ? wp_date( 'F Y', $time ) : $month ),
				esc_html( number_format_i18n( $count ) ),
				esc_html( number_format_i18n( $seen ) ),
				esc_html( number_format_i18n( round( $rate, 1 ), 1 ) )
			);
		}

		echo '</tbody></table></div>';
	}

	/**
	 * Monthly totals for a metric.
	 *
	 * @param string $metric Metric.
	 * @param string $from   Start date.
	 * @param string $to     End date.
	 * @return array<string,int> Y-m => count.
	 */
	private static function monthly( $metric, $from, $to ) {
		$series = StatsStore::series( $metric, StatsStore::DIM_TOTAL, $from, $to );
		$out    = array();

		foreach ( $series as $date => $value ) {
			$month = substr( (string) $date, 0, 7 );

			if ( ! isset( $out[ $month ] ) ) {
				$out[ $month ] = 0;
			}

			$out[ $month ] += (int) $value;
		}

		return $out;
	}

	/**
	 * Rows for the CSV export.
	 *
	 * @param Range $range Selected range.
	 * @return array<int,array<int,string|int>>
	 */
	public static function csv_rows( Range $range ) {
		$rows = array( array( 'date', 'active_30d' ) );

		foreach ( StatsStore::series( StatsStore::METRIC_ACTIVE, StatsStore::DIM_WINDOW, $range->from, $range->to, 'd30' ) as $date => $value ) {
			$rows[] = array( $date, $value );
		}

		return $rows;
	}
}
