<?php
/**
 * Email health: what people have chosen, and what actually went out.
 *
 * @package UserManagementSuite
 */

namespace Marinski\UserManagementSuite\Modules\Insights\Reports;

use Marinski\UserManagementSuite\Modules\Insights\Chart;
use Marinski\UserManagementSuite\Modules\Insights\Range;
use Marinski\UserManagementSuite\Modules\Notifications\Log;
use Marinski\UserManagementSuite\Modules\Notifications\Preferences;
use Marinski\UserManagementSuite\Modules\Notifications\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Opt-out rates per notification type, and send volume from the log.
 */
class EmailHealthReport {

	/**
	 * Render the report body.
	 *
	 * @param Range $range Selected range.
	 * @return void
	 */
	public static function render( Range $range ) {
		$types = Registry::types();

		if ( array() === $types ) {
			echo '<div class="notice notice-info inline"><p>'
				. esc_html__( 'The Notification Preferences module is switched off, so there is nothing to report here yet.', 'user-management-suite' )
				. '</p></div>';

			return;
		}

		$optouts   = self::optout_counts();
		$total_out = array_sum( $optouts['per_type'] );

		GrowthReport::render_cards(
			array(
				array(
					'label' => __( 'Notification types', 'user-management-suite' ),
					'value' => number_format_i18n( count( $types ) ),
					'note'  => sprintf(
						/* translators: %s: number of optional types. */
						__( '%s can be switched off', 'user-management-suite' ),
						number_format_i18n( self::optional_count( $types ) )
					),
				),
				array(
					'label' => __( 'Users with choices', 'user-management-suite' ),
					'value' => number_format_i18n( $optouts['users_with_prefs'] ),
					'note'  => __( 'have changed something from the default', 'user-management-suite' ),
				),
				array(
					'label' => __( 'Fully unsubscribed', 'user-management-suite' ),
					'value' => number_format_i18n( $optouts['unsubscribed_all'] ),
					'note'  => __( 'used an unsubscribe link', 'user-management-suite' ),
				),
				array(
					'label' => __( 'Opt-outs recorded', 'user-management-suite' ),
					'value' => number_format_i18n( $total_out ),
					'note'  => __( 'across all optional types', 'user-management-suite' ),
				),
			)
		);

		echo '<div class="ums-panel"><h2>' . esc_html__( 'Opt-outs by type', 'user-management-suite' ) . '</h2>';
		echo '<p class="description">'
			. esc_html__( 'Counted from stored choices only. A user who has never touched their preferences receives the default and is not counted here.', 'user-management-suite' )
			. '</p>';

		$labelled = array();

		foreach ( $optouts['per_type'] as $id => $count ) {
			$label              = isset( $types[ $id ] ) ? $types[ $id ]['label'] : $id;
			$labelled[ $label ] = $count;
		}

		arsort( $labelled );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Chart::bars() escapes its own output.
		echo Chart::bars(
			$labelled,
			array(
				'label_header' => __( 'Notification', 'user-management-suite' ),
				'count_header' => __( 'Opted out', 'user-management-suite' ),
				'empty'        => __( 'Nobody has opted out of anything yet.', 'user-management-suite' ),
			)
		);
		echo '</div>';

		self::render_sends( $range, $types );
	}

	/**
	 * Render send volume from the log.
	 *
	 * @param Range                             $range Selected range.
	 * @param array<string,array<string,mixed>> $types Registered types.
	 * @return void
	 */
	private static function render_sends( Range $range, array $types ) {
		echo '<div class="ums-panel"><h2>' . esc_html__( 'Messages sent', 'user-management-suite' ) . '</h2>';

		if ( ! Log::enabled() ) {
			echo '<p class="ums-chart-empty">'
				. esc_html__( 'The send log is switched off, so volume cannot be reported.', 'user-management-suite' )
				. '</p></div>';

			return;
		}

		$totals   = Log::totals_by_type( $range->from, $range->to );
		$labelled = array();

		foreach ( $totals as $id => $count ) {
			$label              = isset( $types[ $id ] ) ? $types[ $id ]['label'] : $id;
			$labelled[ $label ] = $count;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Chart::bars() escapes its own output.
		echo Chart::bars(
			$labelled,
			array(
				'label_header' => __( 'Notification', 'user-management-suite' ),
				'count_header' => __( 'Sent', 'user-management-suite' ),
				'empty'        => __( 'Nothing logged in this period. The log only records mail sent after the module was switched on.', 'user-management-suite' ),
			)
		);

		echo '</div>';
	}

	/**
	 * How many types a user may switch off.
	 *
	 * @param array<string,array<string,mixed>> $types Registered types.
	 * @return int
	 */
	private static function optional_count( array $types ) {
		$count = 0;

		foreach ( $types as $type ) {
			if ( $type['user_optout_allowed'] ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Count stored opt-outs.
	 *
	 * Reads only the users who have actually stored a preference, which is a
	 * small fraction of any user table — the default-carrying majority have no
	 * row at all.
	 *
	 * @return array{per_type:array<string,int>,users_with_prefs:int,unsubscribed_all:int}
	 */
	private static function optout_counts() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s",
				Preferences::META
			)
		);

		$per_type = array();

		foreach ( (array) $rows as $row ) {
			$prefs = maybe_unserialize( $row );

			if ( ! is_array( $prefs ) ) {
				continue;
			}

			foreach ( $prefs as $id => $enabled ) {
				if ( $enabled ) {
					continue;
				}

				$id = (string) $id;

				if ( ! isset( $per_type[ $id ] ) ) {
					$per_type[ $id ] = 0;
				}

				++$per_type[ $id ];
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$unsubscribed = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s",
				Preferences::META_UNSUBSCRIBED_ALL
			)
		);

		arsort( $per_type );

		return array(
			'per_type'         => $per_type,
			'users_with_prefs' => count( (array) $rows ),
			'unsubscribed_all' => $unsubscribed,
		);
	}

	/**
	 * Rows for the CSV export.
	 *
	 * @param Range $range Selected range.
	 * @return array<int,array<int,string|int>>
	 */
	public static function csv_rows( Range $range ) {
		$rows   = array( array( 'type', 'opted_out' ) );
		$counts = self::optout_counts();

		foreach ( $counts['per_type'] as $id => $count ) {
			$rows[] = array( $id, $count );
		}

		$rows[] = array();
		$rows[] = array( 'type', 'sent' );

		if ( Log::enabled() ) {
			foreach ( Log::totals_by_type( $range->from, $range->to ) as $id => $count ) {
				$rows[] = array( $id, $count );
			}
		}

		return $rows;
	}
}
