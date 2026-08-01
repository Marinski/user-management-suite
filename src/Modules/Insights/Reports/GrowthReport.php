<?php
/**
 * Growth report: how many users, over what time, and by whom.
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
 * Signups over time, with the comparison against the preceding period that
 * turns a number into a trend.
 */
class GrowthReport {

	/**
	 * Render the report body.
	 *
	 * @param Range $range Selected range.
	 * @return void
	 */
	public static function render( Range $range ) {
		$current  = StatsStore::total( StatsStore::METRIC_SIGNUPS, $range->from, $range->to );
		$previous = StatsStore::total( StatsStore::METRIC_SIGNUPS, $range->prev_from, $range->prev_to );

		$series = $range->group( $range->fill( StatsStore::series( StatsStore::METRIC_SIGNUPS, StatsStore::DIM_TOTAL, $range->from, $range->to ) ) );

		$channels = StatsStore::totals( StatsStore::METRIC_SIGNUPS, StatsStore::DIM_CHANNEL, $range->from, $range->to, 12 );
		$roles    = StatsStore::totals( StatsStore::METRIC_SIGNUPS, StatsStore::DIM_ROLE, $range->from, $range->to, 12 );

		$days       = max( 1, $range->days() );
		$best_day   = self::peak( $series );
		$daily_mean = $current / $days;

		self::render_cards(
			array(
				array(
					'label' => __( 'Signups', 'user-management-suite' ),
					'value' => number_format_i18n( $current ),
					'delta' => self::delta_html( $current, $previous ),
					'note'  => sprintf(
						/* translators: %s: signup count for the preceding period. */
						__( '%s in the preceding period', 'user-management-suite' ),
						number_format_i18n( $previous )
					),
				),
				array(
					'label' => __( 'Average per day', 'user-management-suite' ),
					'value' => number_format_i18n( round( $daily_mean, 1 ), 1 ),
					'note'  => sprintf(
						/* translators: %s: number of days in the range. */
						__( 'across %s days', 'user-management-suite' ),
						number_format_i18n( $days )
					),
				),
				array(
					'label' => __( 'Busiest day', 'user-management-suite' ),
					'value' => '' === $best_day['date'] ? '—' : number_format_i18n( $best_day['value'] ),
					'note'  => '' === $best_day['date'] ? '' : $range->format( $best_day['date'] ),
				),
				array(
					'label' => __( 'Top channel', 'user-management-suite' ),
					'value' => array() === $channels ? '—' : ChannelMap::label( (string) array_key_first( $channels ) ),
					'note'  => array() === $channels
						? ''
						: sprintf(
							/* translators: %s: user count. */
							__( '%s users', 'user-management-suite' ),
							number_format_i18n( reset( $channels ) )
						),
				),
			)
		);

		echo '<div class="ums-panel"><h2>' . esc_html__( 'Signups over time', 'user-management-suite' ) . '</h2>';

		if ( $range->should_group_weekly() ) {
			echo '<p class="description">'
				. esc_html__( 'Grouped into wider buckets because the selected period is long.', 'user-management-suite' )
				. '</p>';
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Chart::line() escapes its own output.
		echo Chart::line( $series, array( 'label' => __( 'Signups over time', 'user-management-suite' ) ) );
		echo '</div>';

		echo '<div class="ums-columns">';

		echo '<div class="ums-panel"><h2>' . esc_html__( 'By channel', 'user-management-suite' ) . '</h2>';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Chart::bars() escapes its own output.
		echo Chart::bars(
			self::label_channels( $channels ),
			array(
				'label_header' => __( 'Channel', 'user-management-suite' ),
				'total'        => $current,
			)
		);
		echo '</div>';

		echo '<div class="ums-panel"><h2>' . esc_html__( 'By role', 'user-management-suite' ) . '</h2>';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Chart::bars() escapes its own output.
		echo Chart::bars(
			self::label_roles( $roles ),
			array(
				'label_header' => __( 'Role', 'user-management-suite' ),
				'total'        => $current,
			)
		);
		echo '</div>';

		echo '</div>';
	}

	/**
	 * Render the headline stat cards.
	 *
	 * @param array<int,array<string,string>> $cards Cards.
	 * @return void
	 */
	public static function render_cards( array $cards ) {
		echo '<div class="ums-cards">';

		foreach ( $cards as $card ) {
			echo '<div class="ums-card">';
			echo '<span class="ums-card-label">' . esc_html( $card['label'] ) . '</span>';
			echo '<span class="ums-card-value">' . esc_html( $card['value'] );

			if ( ! empty( $card['delta'] ) ) {
				echo ' ' . wp_kses_post( $card['delta'] );
			}

			echo '</span>';

			if ( ! empty( $card['note'] ) ) {
				echo '<span class="ums-card-note">' . esc_html( $card['note'] ) . '</span>';
			}

			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * Format a period-over-period change.
	 *
	 * @param int $current  Current period value.
	 * @param int $previous Preceding period value.
	 * @return string HTML.
	 */
	public static function delta_html( $current, $previous ) {
		if ( $previous <= 0 ) {
			return $current > 0
				? '<span class="ums-delta ums-delta-up">' . esc_html__( 'new', 'user-management-suite' ) . '</span>'
				: '';
		}

		$change = ( ( $current - $previous ) / $previous ) * 100;
		$class  = 'ums-delta-flat';

		if ( $change > 0.5 ) {
			$class = 'ums-delta-up';
		} elseif ( $change < -0.5 ) {
			$class = 'ums-delta-down';
		}

		return sprintf(
			'<span class="ums-delta %1$s">%2$s%3$s%%</span>',
			esc_attr( $class ),
			$change > 0 ? '+' : '',
			esc_html( number_format_i18n( round( $change, 1 ), 1 ) )
		);
	}

	/**
	 * Find the highest point in a series.
	 *
	 * @param array<string,int> $series date => value.
	 * @return array{date:string,value:int}
	 */
	private static function peak( array $series ) {
		$best = array(
			'date'  => '',
			'value' => 0,
		);

		foreach ( $series as $date => $value ) {
			if ( $value > $best['value'] ) {
				$best = array(
					'date'  => (string) $date,
					'value' => (int) $value,
				);
			}
		}

		return $best;
	}

	/**
	 * Replace channel slugs with their labels.
	 *
	 * @param array<string,int> $channels slug => count.
	 * @return array<string,int>
	 */
	private static function label_channels( array $channels ) {
		$out = array();

		foreach ( $channels as $slug => $count ) {
			$out[ ChannelMap::label( (string) $slug ) ] = $count;
		}

		return $out;
	}

	/**
	 * Replace role slugs with their display names.
	 *
	 * @param array<string,int> $roles slug => count.
	 * @return array<string,int>
	 */
	private static function label_roles( array $roles ) {
		$names = wp_roles()->get_names();
		$out   = array();

		foreach ( $roles as $slug => $count ) {
			$label         = isset( $names[ $slug ] ) ? translate_user_role( $names[ $slug ] ) : (string) $slug;
			$out[ $label ] = $count;
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
		$rows = array( array( 'date', 'signups' ) );

		foreach ( $range->fill( StatsStore::series( StatsStore::METRIC_SIGNUPS, StatsStore::DIM_TOTAL, $range->from, $range->to ) ) as $date => $value ) {
			$rows[] = array( $date, $value );
		}

		$rows[] = array();
		$rows[] = array( 'channel', 'signups' );

		foreach ( StatsStore::totals( StatsStore::METRIC_SIGNUPS, StatsStore::DIM_CHANNEL, $range->from, $range->to ) as $channel => $count ) {
			$rows[] = array( $channel, $count );
		}

		$rows[] = array();
		$rows[] = array( 'role', 'signups' );

		foreach ( StatsStore::totals( StatsStore::METRIC_SIGNUPS, StatsStore::DIM_ROLE, $range->from, $range->to ) as $role => $count ) {
			$rows[] = array( $role, $count );
		}

		return $rows;
	}
}
