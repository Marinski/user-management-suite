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
		return array(
			StatsStore::METRIC_SIGNUPS,
			StatsStore::METRIC_VERIFIED,
			StatsStore::METRIC_FIRST_LOGIN,
			StatsStore::METRIC_CONVERTED,
		);
	}

	/**
	 * Meta key holding email-verification state, as written by the Verification
	 * module.
	 */
	const META_VERIFIED = '_ums_activation_status';

	/** Meta key holding the last login timestamp, from the Registration module. */
	const META_LAST_LOGIN = '_ums_last_login';

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

		$metrics = array( StatsStore::METRIC_SIGNUPS );

		/*
		 * Funnel steps are attributed to the signup date, not to the date they
		 * happened. Neither verification state nor last-login carries a timestamp
		 * of the moment the step was taken, so the only honest reading is a cohort
		 * one: of the people who joined on this day, how many got this far.
		 */
		if ( ! empty( $meta['verified'] ) ) {
			$metrics[] = StatsStore::METRIC_VERIFIED;
		}

		if ( ! empty( $meta['logged_in'] ) ) {
			$metrics[] = StatsStore::METRIC_FIRST_LOGIN;
		}

		if ( ! empty( $meta['converted'] ) ) {
			$metrics[] = StatsStore::METRIC_CONVERTED;
		}

		foreach ( $metrics as $metric ) {
			foreach ( $values as $dimension => $value ) {
				if ( ! isset( $acc[ $date ][ $metric ][ $dimension ][ $value ] ) ) {
					$acc[ $date ][ $metric ][ $dimension ][ $value ] = 0;
				}

				++$acc[ $date ][ $metric ][ $dimension ][ $value ];
			}
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

		foreach ( $acc as $date => $metrics ) {
			foreach ( $metrics as $metric => $dimensions ) {
				foreach ( $dimensions as $dimension => $values ) {
					foreach ( $values as $value => $hits ) {
						$rows[] = array(
							'date'      => $date,
							'metric'    => $metric,
							'dimension' => $dimension,
							'value'     => $value,
							'hits'      => $hits,
						);
					}
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
		$keys     = array( Record::META_FIRST, $caps_key, self::META_VERIFIED, self::META_LAST_LOGIN );

		/**
		 * Filters the user meta keys the aggregator reads.
		 *
		 * Integrations that can answer "has this user converted" add their key
		 * here and pair it with the ums_insights_user_converted filter.
		 *
		 * @param string[] $keys Meta keys.
		 */
		$keys = array_values( array_unique( (array) apply_filters( 'ums_insights_meta_keys', $keys ) ) );

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
		$raw = array();

		foreach ( (array) $rows as $row ) {
			$user_id             = (int) $row->user_id;
			$raw[ $user_id ][ $row->meta_key ] = $row->meta_value;
			$value               = maybe_unserialize( $row->meta_value );

			switch ( $row->meta_key ) {
				case Record::META_FIRST:
					$out[ $user_id ]['attribution'] = is_array( $value ) ? $value : array();
					break;

				case self::META_VERIFIED:
					// Absent meta means not verified; only an explicit '1' counts.
					$out[ $user_id ]['verified'] = ( '1' === (string) $value );
					break;

				case self::META_LAST_LOGIN:
					$out[ $user_id ]['logged_in'] = ( (int) $value > 0 );
					break;

				case $caps_key:
					// wp_capabilities is a role => bool map; the first key is primary.
					if ( is_array( $value ) && array() !== $value ) {
						$roles                   = array_keys( $value );
						$out[ $user_id ]['role'] = (string) reset( $roles );
					}
					break;
			}
		}

		foreach ( array_keys( $out ) as $user_id ) {
			/**
			 * Filters whether a user counts as converted for the funnel report.
			 *
			 * Left to integrations on purpose: what "converted" means — an order, a
			 * subscription, an enrolment — is a property of the site, not of user
			 * management, and guessing it wrong would put a confidently wrong number
			 * on a dashboard.
			 *
			 * @param bool                 $converted Whether the user converted.
			 * @param int                  $user_id   User id.
			 * @param array<string,string> $meta      Raw meta fetched for this user.
			 */
			$out[ $user_id ]['converted'] = (bool) apply_filters(
				'ums_insights_user_converted',
				false,
				$user_id,
				isset( $raw[ $user_id ] ) ? $raw[ $user_id ] : array()
			);
		}

		return $out;
	}

	/**
	 * Record how many users were recently active, as of today.
	 *
	 * This one cannot be rebuilt from history: last-login is a single moving
	 * value, so yesterday's active count is unknowable once yesterday has
	 * passed. Taking a snapshot each night is the only way the series can exist
	 * at all, which is why it is separate from the cohort rebuild.
	 *
	 * @param string|null $date Date to record against, defaults to today.
	 * @return array<string,int> window => count.
	 */
	public static function snapshot_active( $date = null ) {
		global $wpdb;

		$date = $date ? $date : wp_date( 'Y-m-d' );
		$now  = time();
		$out  = array();
		$rows = array();

		foreach ( array(
			'd1'  => DAY_IN_SECONDS,
			'd7'  => 7 * DAY_IN_SECONDS,
			'd30' => 30 * DAY_IN_SECONDS,
			'd90' => 90 * DAY_IN_SECONDS,
		) as $window => $seconds ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s AND CAST(meta_value AS UNSIGNED) >= %d",
					self::META_LAST_LOGIN,
					$now - $seconds
				)
			);

			$out[ $window ] = $count;
			$rows[]         = array(
				'date'      => $date,
				'metric'    => StatsStore::METRIC_ACTIVE,
				'dimension' => StatsStore::DIM_WINDOW,
				'value'     => $window,
				'hits'      => $count,
			);
		}

		StatsStore::replace_range( $date, $date, array( StatsStore::METRIC_ACTIVE ), $rows );

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
