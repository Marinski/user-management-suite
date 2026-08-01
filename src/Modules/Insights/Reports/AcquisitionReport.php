<?php
/**
 * Acquisition report: where the users actually came from.
 *
 * @package UserManagementSuite
 */

namespace Marinski\UserManagementSuite\Modules\Insights\Reports;

use Marinski\UserManagementSuite\Modules\Attribution\ChannelMap;
use Marinski\UserManagementSuite\Modules\Attribution\Record;
use Marinski\UserManagementSuite\Modules\Insights\Chart;
use Marinski\UserManagementSuite\Modules\Insights\Range;
use Marinski\UserManagementSuite\Modules\Insights\StatsStore;

defined( 'ABSPATH' ) || exit;

/**
 * Source, medium, campaign and device breakdowns.
 */
class AcquisitionReport {

	/**
	 * Render the report body.
	 *
	 * @param Range $range Selected range.
	 * @return void
	 */
	public static function render( Range $range ) {
		$total = StatsStore::total( StatsStore::METRIC_SIGNUPS, $range->from, $range->to );

		$sources   = StatsStore::totals( StatsStore::METRIC_SIGNUPS, StatsStore::DIM_SOURCE, $range->from, $range->to, 25 );
		$mediums   = StatsStore::totals( StatsStore::METRIC_SIGNUPS, StatsStore::DIM_MEDIUM, $range->from, $range->to, 12 );
		$campaigns = StatsStore::totals( StatsStore::METRIC_SIGNUPS, StatsStore::DIM_CAMPAIGN, $range->from, $range->to, 15 );
		$devices   = StatsStore::totals( StatsStore::METRIC_SIGNUPS, StatsStore::DIM_DEVICE, $range->from, $range->to, 8 );
		$channels  = StatsStore::totals( StatsStore::METRIC_SIGNUPS, StatsStore::DIM_CHANNEL, $range->from, $range->to );
		$origins   = StatsStore::totals( StatsStore::METRIC_SIGNUPS, StatsStore::DIM_ORIGIN, $range->from, $range->to );

		/*
		 * `(direct)` is WooCommerce's placeholder for "no source was present", not
		 * a place anyone came from. Counting it as attributed, or letting it rank
		 * as the top source, would overstate how much is actually known.
		 */
		$all_sources = StatsStore::totals( StatsStore::METRIC_SIGNUPS, StatsStore::DIM_SOURCE, $range->from, $range->to );
		$placeholder = array( 'unknown', '(direct)', 'direct', '' );

		$unknown    = 0;
		$top_source = '';

		foreach ( $all_sources as $source => $count ) {
			if ( in_array( (string) $source, $placeholder, true ) ) {
				$unknown += (int) $count;
				continue;
			}

			if ( '' === $top_source ) {
				$top_source = (string) $source;
			}
		}

		$identified = max( 0, $total - $unknown );
		$coverage   = $total > 0 ? ( $identified / $total ) * 100 : 0;
		$distinct   = count( array_diff_key( $all_sources, array_flip( $placeholder ) ) );

		GrowthReport::render_cards(
			array(
				array(
					'label' => __( 'Known source', 'user-management-suite' ),
					'value' => number_format_i18n( $identified ),
					'note'  => sprintf(
						/* translators: %s: percentage of signups with a known source. */
						__( '%s%% of signups name a real referrer', 'user-management-suite' ),
						number_format_i18n( round( $coverage, 1 ), 1 )
					),
				),
				array(
					'label' => __( 'Top source', 'user-management-suite' ),
					'value' => '' === $top_source ? '—' : $top_source,
					'note'  => '' === $top_source ? '' : sprintf(
						/* translators: %s: user count. */
						__( '%s users', 'user-management-suite' ),
						number_format_i18n( $all_sources[ $top_source ] )
					),
				),
				array(
					'label' => __( 'Distinct sources', 'user-management-suite' ),
					'value' => number_format_i18n( $distinct ),
					'note'  => __( 'referrers seen in this period', 'user-management-suite' ),
				),
				array(
					'label' => __( 'Direct or unrecorded', 'user-management-suite' ),
					'value' => number_format_i18n( $unknown ),
					'note'  => __( 'typed in, or arrived with no referrer', 'user-management-suite' ),
				),
			)
		);

		self::render_origin_notice( $origins );

		echo '<div class="ums-panel"><h2>' . esc_html__( 'Channels', 'user-management-suite' ) . '</h2>';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Chart::bars() escapes its own output.
		echo Chart::bars(
			self::relabel( $channels, array( ChannelMap::class, 'label' ) ),
			array(
				'label_header' => __( 'Channel', 'user-management-suite' ),
				'total'        => $total,
			)
		);
		echo '</div>';

		echo '<div class="ums-panel"><h2>' . esc_html__( 'Sources', 'user-management-suite' ) . '</h2>';
		echo '<p class="description">'
			. esc_html__( 'The raw referring host or utm_source, before it is grouped into a channel.', 'user-management-suite' )
			. '</p>';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Chart::bars() escapes its own output.
		echo Chart::bars(
			$sources,
			array(
				'label_header' => __( 'Source', 'user-management-suite' ),
				'total'        => $total,
			)
		);
		echo '</div>';

		echo '<div class="ums-columns">';

		echo '<div class="ums-panel"><h2>' . esc_html__( 'Mediums', 'user-management-suite' ) . '</h2>';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Chart::bars() escapes its own output.
		echo Chart::bars(
			$mediums,
			array(
				'label_header' => __( 'Medium', 'user-management-suite' ),
				'total'        => $total,
			)
		);
		echo '</div>';

		echo '<div class="ums-panel"><h2>' . esc_html__( 'Devices', 'user-management-suite' ) . '</h2>';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Chart::bars() escapes its own output.
		echo Chart::bars(
			$devices,
			array(
				'label_header' => __( 'Device', 'user-management-suite' ),
				'total'        => $total,
			)
		);
		echo '</div>';

		echo '</div>';

		echo '<div class="ums-panel"><h2>' . esc_html__( 'Campaigns', 'user-management-suite' ) . '</h2>';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Chart::bars() escapes its own output.
		echo Chart::bars(
			$campaigns,
			array(
				'label_header' => __( 'Campaign', 'user-management-suite' ),
				'total'        => $total,
				'empty'        => __( 'No utm_campaign values recorded in this period.', 'user-management-suite' ),
			)
		);
		echo '</div>';
	}

	/**
	 * Explain how much of the data was imported rather than captured live.
	 *
	 * Backfilled rows are a weaker claim — WooCommerce keeps one rolling touch
	 * per customer, so an imported "first touch" may really be a later one. The
	 * report says so rather than presenting both as equally certain.
	 *
	 * @param array<string,int> $origins origin => count.
	 * @return void
	 */
	private static function render_origin_notice( array $origins ) {
		$backfilled = isset( $origins[ Record::ORIGIN_BACKFILL ] ) ? (int) $origins[ Record::ORIGIN_BACKFILL ] : 0;

		if ( $backfilled <= 0 ) {
			return;
		}

		$total = array_sum( $origins );
		$share = $total > 0 ? ( $backfilled / $total ) * 100 : 0;

		echo '<div class="notice notice-info inline ums-origin-notice"><p>';
		printf(
			/* translators: 1: number of users, 2: percentage. */
			esc_html__( '%1$s of these signups (%2$s%%) were imported from WooCommerce order attribution. WooCommerce keeps one rolling touch per customer, so for those users the first touch shown is the earliest one it still held, not necessarily their true first visit.', 'user-management-suite' ),
			esc_html( number_format_i18n( $backfilled ) ),
			esc_html( number_format_i18n( round( $share, 1 ), 1 ) )
		);
		echo '</p></div>';
	}

	/**
	 * Apply a labelling callback to array keys.
	 *
	 * @param array<string,int> $data     slug => count.
	 * @param callable          $callback Slug => label.
	 * @return array<string,int>
	 */
	private static function relabel( array $data, $callback ) {
		$out = array();

		foreach ( $data as $slug => $count ) {
			$out[ (string) call_user_func( $callback, (string) $slug ) ] = $count;
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
		$rows = array();

		$sections = array(
			'channel'  => StatsStore::DIM_CHANNEL,
			'source'   => StatsStore::DIM_SOURCE,
			'medium'   => StatsStore::DIM_MEDIUM,
			'campaign' => StatsStore::DIM_CAMPAIGN,
			'device'   => StatsStore::DIM_DEVICE,
		);

		foreach ( $sections as $name => $dimension ) {
			$rows[] = array( $name, 'signups' );

			foreach ( StatsStore::totals( StatsStore::METRIC_SIGNUPS, $dimension, $range->from, $range->to ) as $value => $count ) {
				$rows[] = array( $value, $count );
			}

			$rows[] = array();
		}

		return $rows;
	}
}
