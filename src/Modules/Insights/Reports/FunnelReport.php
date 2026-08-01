<?php
/**
 * Activation funnel: how far a signup cohort actually gets.
 *
 * @package UserManagementSuite
 */

namespace Marinski\UserManagementSuite\Modules\Insights\Reports;

use Marinski\UserManagementSuite\Modules\Attribution\ChannelMap;
use Marinski\UserManagementSuite\Modules\Insights\Chart;
use Marinski\UserManagementSuite\Modules\Insights\Range;
use Marinski\UserManagementSuite\Modules\Insights\StatsStore;

defined( 'ABSPATH' ) || exit;

/**
 * Registered, verified, signed in, converted — by signup cohort.
 */
class FunnelReport {

	/**
	 * Render the report body.
	 *
	 * @param Range $range Selected range.
	 * @return void
	 */
	public static function render( Range $range ) {
		$signups   = StatsStore::total( StatsStore::METRIC_SIGNUPS, $range->from, $range->to );
		$verified  = StatsStore::total( StatsStore::METRIC_VERIFIED, $range->from, $range->to );
		$logged_in = StatsStore::total( StatsStore::METRIC_FIRST_LOGIN, $range->from, $range->to );
		$converted = StatsStore::total( StatsStore::METRIC_CONVERTED, $range->from, $range->to );

		echo '<div class="notice notice-info inline"><p>'
			. esc_html__( 'Each step is counted against the day the user signed up, not the day they took the step. Verification state and last-login carry no timestamp of their own, so a cohort reading is the only honest one: of the people who joined in this period, how many have since got this far.', 'user-management-suite' )
			. '</p></div>';

		$steps = array(
			array(
				'label' => __( 'Registered', 'user-management-suite' ),
				'value' => $signups,
			),
			array(
				'label' => __( 'Verified email', 'user-management-suite' ),
				'value' => $verified,
			),
			array(
				'label' => __( 'Signed in at least once', 'user-management-suite' ),
				'value' => $logged_in,
			),
		);

		// Only shown when something has answered the conversion filter; an empty
		// step would read as "nobody converted" rather than "nothing measured".
		if ( $converted > 0 ) {
			$steps[] = array(
				'label' => __( 'Converted', 'user-management-suite' ),
				'value' => $converted,
			);
		}

		self::render_funnel( $steps, $signups );

		self::render_dropoff( $signups, $verified, $logged_in );

		echo '<div class="ums-columns">';

		echo '<div class="ums-panel"><h2>' . esc_html__( 'Verification rate by channel', 'user-management-suite' ) . '</h2>';
		echo '<p class="description">'
			. esc_html__( 'Channels that send people who never confirm their address are worth less than their signup count suggests.', 'user-management-suite' )
			. '</p>';
		self::render_rate_table(
			StatsStore::totals( StatsStore::METRIC_SIGNUPS, StatsStore::DIM_CHANNEL, $range->from, $range->to ),
			StatsStore::totals( StatsStore::METRIC_VERIFIED, StatsStore::DIM_CHANNEL, $range->from, $range->to ),
			__( 'Channel', 'user-management-suite' ),
			true
		);
		echo '</div>';

		echo '<div class="ums-panel"><h2>' . esc_html__( 'Verification rate by source', 'user-management-suite' ) . '</h2>';
		self::render_rate_table(
			StatsStore::totals( StatsStore::METRIC_SIGNUPS, StatsStore::DIM_SOURCE, $range->from, $range->to, 15 ),
			StatsStore::totals( StatsStore::METRIC_VERIFIED, StatsStore::DIM_SOURCE, $range->from, $range->to ),
			__( 'Source', 'user-management-suite' ),
			false
		);
		echo '</div>';

		echo '</div>';

		echo '<div class="ums-panel"><h2>' . esc_html__( 'Verified signups over time', 'user-management-suite' ) . '</h2>';
		$series = $range->group( $range->fill( StatsStore::series( StatsStore::METRIC_VERIFIED, StatsStore::DIM_TOTAL, $range->from, $range->to ) ) );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Chart::line() escapes its own output.
		echo Chart::line( $series, array( 'label' => __( 'Verified signups over time', 'user-management-suite' ), 'color' => '#007017' ) );
		echo '</div>';
	}

	/**
	 * Render the funnel bars.
	 *
	 * @param array<int,array<string,mixed>> $steps Steps.
	 * @param int                            $top   Value of the first step.
	 * @return void
	 */
	private static function render_funnel( array $steps, $top ) {
		echo '<div class="ums-panel"><h2>' . esc_html__( 'Activation funnel', 'user-management-suite' ) . '</h2>';

		if ( $top <= 0 ) {
			echo '<p class="ums-chart-empty">' . esc_html__( 'No signups in this period.', 'user-management-suite' ) . '</p></div>';

			return;
		}

		echo '<div class="ums-funnel">';

		$previous = 0;

		foreach ( $steps as $index => $step ) {
			$share = ( $step['value'] / $top ) * 100;
			$step_share = ( $index > 0 && $previous > 0 ) ? ( $step['value'] / $previous ) * 100 : 100;

			printf(
				'<div class="ums-funnel-step"><div class="ums-funnel-bar" style="width:%1$.2f%%"></div>'
					. '<span class="ums-funnel-label">%2$s</span>'
					. '<span class="ums-funnel-value">%3$s</span>'
					. '<span class="ums-funnel-share">%4$s%%%5$s</span></div>',
				max( 1.5, $share ),
				esc_html( $step['label'] ),
				esc_html( number_format_i18n( $step['value'] ) ),
				esc_html( number_format_i18n( round( $share, 1 ), 1 ) ),
				$index > 0
					? ' <span class="ums-funnel-step-share">(' . esc_html( number_format_i18n( round( $step_share, 1 ), 1 ) ) . '% ' . esc_html__( 'of previous', 'user-management-suite' ) . ')</span>'
					: ''
			);

			$previous = $step['value'];
		}

		echo '</div></div>';
	}

	/**
	 * Call out the biggest leak in plain language.
	 *
	 * @param int $signups   Registered.
	 * @param int $verified  Verified.
	 * @param int $logged_in Signed in.
	 * @return void
	 */
	private static function render_dropoff( $signups, $verified, $logged_in ) {
		if ( $signups <= 0 ) {
			return;
		}

		$unverified = max( 0, $signups - $verified );
		$share      = ( $unverified / $signups ) * 100;

		if ( $share < 10 ) {
			return;
		}

		echo '<div class="notice notice-warning inline"><p>';
		printf(
			/* translators: 1: number of users, 2: percentage. */
			esc_html__( '%1$s of these signups (%2$s%%) never verified their email address. They still receive marketing, still count towards list size, and are the most likely explanation for a low open rate.', 'user-management-suite' ),
			esc_html( number_format_i18n( $unverified ) ),
			esc_html( number_format_i18n( round( $share, 1 ), 1 ) )
		);
		echo '</p></div>';
	}

	/**
	 * Render a table of step rates per dimension value.
	 *
	 * @param array<string,int> $totals   Denominators.
	 * @param array<string,int> $achieved Numerators.
	 * @param string            $header   Column label.
	 * @param bool              $labelled Whether values are channel slugs.
	 * @return void
	 */
	private static function render_rate_table( array $totals, array $achieved, $header, $labelled ) {
		if ( array() === $totals ) {
			echo '<p class="ums-chart-empty">' . esc_html__( 'Nothing recorded for this period.', 'user-management-suite' ) . '</p>';

			return;
		}

		echo '<table class="widefat striped"><thead><tr>'
			. '<th>' . esc_html( $header ) . '</th>'
			. '<th class="ums-num">' . esc_html__( 'Signups', 'user-management-suite' ) . '</th>'
			. '<th class="ums-num">' . esc_html__( 'Verified', 'user-management-suite' ) . '</th>'
			. '<th class="ums-num">' . esc_html__( 'Rate', 'user-management-suite' ) . '</th>'
			. '</tr></thead><tbody>';

		foreach ( $totals as $value => $count ) {
			$hit  = isset( $achieved[ $value ] ) ? (int) $achieved[ $value ] : 0;
			$rate = $count > 0 ? ( $hit / $count ) * 100 : 0;

			// A handful of signups makes a percentage meaningless, so say so rather
			// than printing "100%" off two users.
			$rate_text = $count < 10
				? '<span class="description">' . esc_html__( 'too few', 'user-management-suite' ) . '</span>'
				: esc_html( number_format_i18n( round( $rate, 1 ), 1 ) ) . '%';

			printf(
				'<tr><td>%1$s</td><td class="ums-num">%2$s</td><td class="ums-num">%3$s</td><td class="ums-num">%4$s</td></tr>',
				esc_html( $labelled ? ChannelMap::label( (string) $value ) : (string) $value ),
				esc_html( number_format_i18n( $count ) ),
				esc_html( number_format_i18n( $hit ) ),
				wp_kses_post( $rate_text )
			);
		}

		echo '</tbody></table>';
	}

	/**
	 * Rows for the CSV export.
	 *
	 * @param Range $range Selected range.
	 * @return array<int,array<int,string|int>>
	 */
	public static function csv_rows( Range $range ) {
		$rows = array( array( 'step', 'users' ) );

		foreach ( array(
			'registered' => StatsStore::METRIC_SIGNUPS,
			'verified'   => StatsStore::METRIC_VERIFIED,
			'signed_in'  => StatsStore::METRIC_FIRST_LOGIN,
			'converted'  => StatsStore::METRIC_CONVERTED,
		) as $label => $metric ) {
			$rows[] = array( $label, StatsStore::total( $metric, $range->from, $range->to ) );
		}

		$rows[]   = array();
		$rows[]   = array( 'channel', 'signups', 'verified' );
		$signups  = StatsStore::totals( StatsStore::METRIC_SIGNUPS, StatsStore::DIM_CHANNEL, $range->from, $range->to );
		$verified = StatsStore::totals( StatsStore::METRIC_VERIFIED, StatsStore::DIM_CHANNEL, $range->from, $range->to );

		foreach ( $signups as $channel => $count ) {
			$rows[] = array( $channel, $count, isset( $verified[ $channel ] ) ? $verified[ $channel ] : 0 );
		}

		return $rows;
	}
}
