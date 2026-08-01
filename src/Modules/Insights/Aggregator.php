<?php
/**
 * Builds the daily rollups the dashboards read.
 *
 * @package UserManagementSuite
 */

namespace Marinski\UserManagementSuite\Modules\Insights;

use Marinski\UserManagementSuite\Modules\Attribution\ChannelMap;
use Marinski\UserManagementSuite\Modules\Attribution\Record;

defined( 'ABSPATH' ) || exit;

/**
 * Turns raw user rows into `ums_stats_daily`.
 *
 * Work is sliced by calendar month rather than streamed across the whole range
 * in one pass: a multi-year rebuild otherwise holds every date/dimension/value
 * combination in memory at once, which is where a job like this falls over on a
 * large site.
 */
class Aggregator {

	/** Bucket label used when a user has no value for a dimension. */
	const UNKNOWN = 'unknown';

	/**
	 * Metrics this class owns. Rebuilds delete only these.
	 *
	 * @return string[]
	 */
	public static function metrics() {
		return array( StatsStore::METRIC_SIGNUPS );
	}

	/**
	 * Rebuild rollups for a date range, one month at a time.
	 *
	 * @param string        $from     Start date (Y-m-d), inclusive.
	 * @param string        $to       End date (Y-m-d), inclusive.
	 * @param callable|null $progress Optional callback receiving (string $month, int $rows).
	 * @return array<string,int> Totals for the run.
	 */
	public static function rebuild( $from, $to, $progress = null ) {
		$tz    = wp_timezone();
		$start = new \DateTimeImmutable( $from . ' 00:00:00', $tz );
		$end   = new \DateTimeImmutable( $to . ' 23:59:59', $tz );

		$result = array(
			'users' => 0,
			'rows'  => 0,
		);

		$cursor = $start->modify( 'first day of this month' )->setTime( 0, 0, 0 );

		while ( $cursor <= $end ) {
			$slice_start = $cursor > $start ? $cursor : $start;
			$next_month  = $cursor->modify( 'first day of next month' )->setTime( 0, 0, 0 );
			$slice_end   = $next_month->modify( '-1 second' );

			if ( $slice_end > $end ) {
				$slice_end = $end;
			}

			$slice = self::rebuild_slice( $slice_start, $slice_end );

			$result['users'] += $slice['users'];
			$result['rows']  += $slice['rows'];

			if ( is_callable( $progress ) ) {
				call_user_func( $progress, $cursor->format( 'Y-m' ), $slice['rows'] );
			}

			$cursor = $next_month;
		}

		update_option( 'ums_stats_last_build', time(), false );

		return $result;
	}

	/**
	 * Rebuild one slice (normally a calendar month).
	 *
	 * @param \DateTimeImmutable $start Local start.
	 * @param \DateTimeImmutable $end   Local end.
	 * @return array<string,int>
	 */
	private static function rebuild_slice( \DateTimeImmutable $start, \DateTimeImmutable $end ) {
		global $wpdb;

		$tz  = wp_timezone();
		$utc = new \DateTimeZone( 'UTC' );

		// user_registered is stored in UTC; widen the SQL window by a day at each
		// end so local-timezone bucketing near midnight cannot lose anyone.
		$sql_from = $start->setTimezone( $utc )->modify( '-1 day' )->format( 'Y-m-d H:i:s' );
		$sql_to   = $end->setTimezone( $utc )->modify( '+1 day' )->format( 'Y-m-d H:i:s' );

		$local_from = $start->format( 'Y-m-d' );
		$local_to   = $end->format( 'Y-m-d' );

		$acc        = array();
		$last_id    = 0;
		$user_count = 0;
		$batch      = 2000;

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$users = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, user_registered FROM {$wpdb->users}
					 WHERE user_registered >= %s AND user_registered <= %s AND ID > %d
					 ORDER BY ID ASC LIMIT %d",
					$sql_from,
					$sql_to,
					$last_id,
					$batch
				)
			);

			if ( array() === (array) $users ) {
				break;
			}

			$ids = array();

			foreach ( $users as $user ) {
				$ids[] = (int) $user->ID;
			}

			$meta = self::fetch_meta( $ids );

			foreach ( $users as $user ) {
				$last_id = (int) $user->ID;

				$registered = new \DateTimeImmutable( $user->user_registered, $utc );
				$date       = $registered->setTimezone( $tz )->format( 'Y-m-d' );

				if ( $date < $local_from || $date > $local_to ) {
					continue;
				}

				++$user_count;
				self::accumulate( $acc, $date, isset( $meta[ $last_id ] ) ? $meta[ $last_id ] : array() );
			}
		} while ( count( $users ) === $batch );

		$rows    = self::flatten( $acc );
		$written = StatsStore::replace_range( $local_from, $local_to, self::metrics(), $rows );

		return array(
			'users' => $user_count,
			'rows'  => $written,
		);
	}

	/**
	 * Add one user to the accumulator.
	 *
	 * @param array<string,array<string,array<string,int>>> $acc  Accumulator, by reference.
	 * @param string                                        $date Local date.
	 * @param array<string,mixed>                           $meta User meta for this user.
	 * @return void
	 */
	private static function accumulate( array &$acc, $date, array $meta ) {
		$record = isset( $meta['attribution'] ) && is_array( $meta['attribution'] )
			? array_merge( Record::blank(), $meta['attribution'] )
			: Record::blank();

		$values = array(
			StatsStore::DIM_TOTAL    => '',
			// A user with no attribution row at all is "not recorded", which is a
			// different statement from "other" — the latter implies we looked.
			StatsStore::DIM_CHANNEL  => self::bucket( $record['channel'] ),
			StatsStore::DIM_SOURCE   => self::bucket( $record['source'] ),
			StatsStore::DIM_MEDIUM   => self::bucket( $record['medium'] ),
			StatsStore::DIM_CAMPAIGN => self::bucket( $record['campaign'] ),
			StatsStore::DIM_DEVICE   => self::bucket( $record['device'] ),
			StatsStore::DIM_ORIGIN   => self::bucket( $record['origin'] ),
			StatsStore::DIM_ROLE     => self::bucket( isset( $meta['role'] ) ? $meta['role'] : '' ),
		);

		foreach ( $values as $dimension => $value ) {
			if ( ! isset( $acc[ $date ][ $dimension ][ $value ] ) ) {
				$acc[ $date ][ $dimension ][ $value ] = 0;
			}

			++$acc[ $date ][ $dimension ][ $value ];
		}
	}

	/**
	 * Substitute a readable placeholder for a missing value.
	 *
	 * Empty buckets are kept and labelled rather than dropped: "how much of my
	 * data has no source at all" is itself one of the numbers worth seeing.
	 *
	 * @param mixed  $value    Raw value.
	 * @param string $fallback Label to use when empty.
	 * @return string
	 */
	private static function bucket( $value, $fallback = self::UNKNOWN ) {
		$value = trim( (string) $value );

		return '' === $value ? $fallback : $value;
	}

	/**
	 * Turn the accumulator into insertable rows.
	 *
	 * @param array<string,array<string,array<string,int>>> $acc Accumulator.
	 * @return array<int,array<string,mixed>>
	 */
	private static function flatten( array $acc ) {
		$rows = array();

		foreach ( $acc as $date => $dimensions ) {
			foreach ( $dimensions as $dimension => $values ) {
				foreach ( $values as $value => $hits ) {
					$rows[] = array(
						'date'      => $date,
						'metric'    => StatsStore::METRIC_SIGNUPS,
						'dimension' => $dimension,
						'value'     => $value,
						'hits'      => $hits,
					);
				}
			}
		}

		return $rows;
	}

	/**
	 * Fetch attribution and role meta for a batch of users in one query.
	 *
	 * @param int[] $ids User ids.
	 * @return array<int,array<string,mixed>>
	 */
	private static function fetch_meta( array $ids ) {
		global $wpdb;

		if ( array() === $ids ) {
			return array();
		}

		$caps_key = $wpdb->get_blog_prefix() . 'capabilities';
		$keys     = array( Record::META_FIRST, $caps_key );

		$id_placeholders  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$key_placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholders generated, values prepared.
		$sql = $wpdb->prepare(
			"SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta}
			 WHERE user_id IN ({$id_placeholders}) AND meta_key IN ({$key_placeholders})",
			array_merge( $ids, $keys )
		);
		// phpcs:enable

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql );

		$out = array();

		foreach ( (array) $rows as $row ) {
			$user_id = (int) $row->user_id;
			$value   = maybe_unserialize( $row->meta_value );

			if ( Record::META_FIRST === $row->meta_key ) {
				$out[ $user_id ]['attribution'] = is_array( $value ) ? $value : array();
				continue;
			}

			// wp_capabilities is a role => bool map; the first key is the primary role.
			if ( is_array( $value ) && array() !== $value ) {
				$roles                  = array_keys( $value );
				$out[ $user_id ]['role'] = (string) reset( $roles );
			}
		}

		return $out;
	}

	/**
	 * The earliest registration date on the site, for full rebuilds.
	 *
	 * @return string Y-m-d, or today when the site has no users.
	 */
	public static function earliest_signup_date() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$min = $wpdb->get_var( "SELECT MIN(user_registered) FROM {$wpdb->users}" );

		if ( ! $min ) {
			return wp_date( 'Y-m-d' );
		}

		$utc = new \DateTimeZone( 'UTC' );

		return ( new \DateTimeImmutable( $min, $utc ) )->setTimezone( wp_timezone() )->format( 'Y-m-d' );
	}
}
