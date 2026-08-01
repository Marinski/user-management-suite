<?php
/**
 * Read/write access to the daily rollup table.
 *
 * @package UserManagementSuite
 */

namespace Marinski\UserManagementSuite\Modules\Insights;

use Marinski\UserManagementSuite\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * The only class allowed to touch `ums_stats_daily`.
 *
 * Every dashboard query goes through here, which is what keeps the reporting
 * screens off `wp_users` and `wp_usermeta` at request time.
 */
class StatsStore {

	/* Metrics. */
	const METRIC_SIGNUPS     = 'signups';
	const METRIC_VERIFIED    = 'verified';
	const METRIC_FIRST_LOGIN = 'first_login';
	const METRIC_CONVERTED   = 'converted';
	const METRIC_ACTIVE      = 'active';

	/* Dimensions. */
	const DIM_TOTAL    = 'total';
	const DIM_CHANNEL  = 'channel';
	const DIM_SOURCE   = 'source';
	const DIM_MEDIUM   = 'medium';
	const DIM_CAMPAIGN = 'campaign';
	const DIM_DEVICE   = 'device';
	const DIM_ROLE     = 'role';
	const DIM_ORIGIN   = 'origin';
	const DIM_WINDOW   = 'window';

	/**
	 * Replace every row in a date range for the given metrics.
	 *
	 * Rebuilds are idempotent: the old rows for the range go first, so a rerun
	 * can never double-count.
	 *
	 * @param string                         $from    Start date (Y-m-d), inclusive.
	 * @param string                         $to      End date (Y-m-d), inclusive.
	 * @param string[]                       $metrics Metrics being rebuilt.
	 * @param array<int,array<string,mixed>> $rows Rows to insert.
	 * @return int Rows written.
	 */
	public static function replace_range( $from, $to, array $metrics, array $rows ) {
		global $wpdb;

		if ( array() === $metrics ) {
			return 0;
		}

		$table = Schema::stats_table();

		$placeholders = implode( ',', array_fill( 0, count( $metrics ), '%s' ) );
		$args         = array_merge( array( $from, $to ), $metrics );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholders generated, values prepared.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Placeholder count is generated to match the argument array.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE stat_date >= %s AND stat_date <= %s AND metric IN ({$placeholders})",
				$args
			)
		);
		// phpcs:enable

		return self::insert_rows( $rows );
	}

	/**
	 * Bulk-insert rollup rows in chunks.
	 *
	 * @param array<int,array<string,mixed>> $rows Rows with date/metric/dimension/value/hits keys.
	 * @return int Rows written.
	 */
	public static function insert_rows( array $rows ) {
		global $wpdb;

		if ( array() === $rows ) {
			return 0;
		}

		$table   = Schema::stats_table();
		$written = 0;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is our own; every value is passed through prepare().
		foreach ( array_chunk( $rows, 500 ) as $chunk ) {
			$values = array();
			$args   = array();

			foreach ( $chunk as $row ) {
				$values[] = '(%s,%s,%s,%s,%d,%f)';
				$args[]   = $row['date'];
				$args[]   = $row['metric'];
				$args[]   = $row['dimension'];
				$args[]   = substr( (string) $row['value'], 0, 191 );
				$args[]   = (int) $row['hits'];
				$args[]   = isset( $row['amount'] ) ? (float) $row['amount'] : 0;
			}

			$sql = "INSERT INTO {$table} (stat_date,metric,dimension,dim_value,hits,amount) VALUES "
				. implode( ',', $values )
				. ' ON DUPLICATE KEY UPDATE hits = VALUES(hits), amount = VALUES(amount)';

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholders generated, values prepared.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->query( $wpdb->prepare( $sql, $args ) );
			// phpcs:enable

			$written += (int) $result;
		}

		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $written;
	}

	/**
	 * Daily series for one metric/dimension, optionally filtered to one value.
	 *
	 * @param string      $metric    Metric.
	 * @param string      $dimension Dimension.
	 * @param string      $from      Start date (Y-m-d).
	 * @param string      $to        End date (Y-m-d).
	 * @param string|null $value     Optional dimension value filter.
	 * @return array<string,int> date => hits.
	 */
	public static function series( $metric, $dimension, $from, $to, $value = null ) {
		global $wpdb;

		$table = Schema::stats_table();
		$sql   = "SELECT stat_date, SUM(hits) AS hits FROM {$table}
			 WHERE metric = %s AND dimension = %s AND stat_date >= %s AND stat_date <= %s";
		$args  = array( $metric, $dimension, $from, $to );

		if ( null !== $value ) {
			$sql   .= ' AND dim_value = %s';
			$args[] = $value;
		}

		$sql .= ' GROUP BY stat_date ORDER BY stat_date ASC';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ) );

		$out = array();

		foreach ( (array) $rows as $row ) {
			$out[ $row->stat_date ] = (int) $row->hits;
		}

		return $out;
	}

	/**
	 * Totals per dimension value over a range, biggest first.
	 *
	 * @param string $metric    Metric.
	 * @param string $dimension Dimension.
	 * @param string $from      Start date (Y-m-d).
	 * @param string $to        End date (Y-m-d).
	 * @param int    $limit     Max rows (0 = all).
	 * @return array<string,int> value => hits.
	 */
	public static function totals( $metric, $dimension, $from, $to, $limit = 0 ) {
		global $wpdb;

		$table = Schema::stats_table();
		$sql   = "SELECT dim_value, SUM(hits) AS hits FROM {$table}
			 WHERE metric = %s AND dimension = %s AND stat_date >= %s AND stat_date <= %s
			 GROUP BY dim_value ORDER BY hits DESC";
		$args  = array( $metric, $dimension, $from, $to );

		if ( $limit > 0 ) {
			$sql   .= ' LIMIT %d';
			$args[] = (int) $limit;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ) );

		$out = array();

		foreach ( (array) $rows as $row ) {
			$out[ (string) $row->dim_value ] = (int) $row->hits;
		}

		return $out;
	}

	/**
	 * Single total for a metric over a range.
	 *
	 * @param string $metric Metric.
	 * @param string $from   Start date (Y-m-d).
	 * @param string $to     End date (Y-m-d).
	 * @return int
	 */
	public static function total( $metric, $from, $to ) {
		global $wpdb;

		$table = Schema::stats_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from $wpdb->prefix; every value is prepared.
				"SELECT SUM(hits) FROM {$table}
				 WHERE metric = %s AND dimension = %s AND stat_date >= %s AND stat_date <= %s",
				$metric,
				self::DIM_TOTAL,
				$from,
				$to
			)
		);
	}

	/**
	 * The earliest and latest dates present in the table.
	 *
	 * @return array{first:string,last:string}
	 */
	public static function date_bounds() {
		global $wpdb;

		$table = Schema::stats_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( "SELECT MIN(stat_date) AS first_date, MAX(stat_date) AS last_date FROM {$table}" );

		return array(
			'first' => $row && $row->first_date ? $row->first_date : '',
			'last'  => $row && $row->last_date ? $row->last_date : '',
		);
	}

	/**
	 * Total number of rollup rows.
	 *
	 * @return int
	 */
	public static function row_count() {
		global $wpdb;

		$table = Schema::stats_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Remove every row. Used before a full rebuild.
	 *
	 * @return void
	 */
	public static function truncate() {
		global $wpdb;

		$table = Schema::stats_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "TRUNCATE TABLE {$table}" );
	}
}
