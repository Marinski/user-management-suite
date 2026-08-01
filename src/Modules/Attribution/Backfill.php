<?php
/**
 * Imports historical acquisition data from WooCommerce order attribution.
 *
 * @package UserManagementSuite
 */

namespace Marinski\UserManagementSuite\Modules\Attribution;

defined( 'ABSPATH' ) || exit;

/**
 * Copies WooCommerce's per-customer attribution meta into the plugin's own
 * schema.
 *
 * WooCommerce has quietly recorded utm/referrer/device data against customers
 * for years on any store that runs a recent version. Without this importer a
 * fresh install has to wait months to accumulate enough data to show a trend;
 * with it, the dashboards are useful on day one.
 *
 * The import is resumable and never overwrites a record that was captured live,
 * so it is safe to re-run.
 */
class Backfill {

	/** Option holding resumable progress. */
	const STATE_OPTION = 'ums_attribution_backfill_state';

	/** The WooCommerce meta key present on every attributed customer. */
	const PROBE_KEY = '_wc_order_attribution_source_type';

	/**
	 * WooCommerce meta key suffix => our field name.
	 *
	 * @return array<string,string>
	 */
	public static function field_map() {
		return array(
			'source_type'        => 'type',
			'utm_source'         => 'source',
			'utm_medium'         => 'medium',
			'utm_campaign'       => 'campaign',
			'utm_content'        => 'content',
			'utm_term'           => 'term',
			'referrer'           => 'referrer',
			'device_type'        => 'device',
			'session_entry'      => 'landing',
			'session_start_time' => 'ts',
		);
	}

	/**
	 * Current progress state.
	 *
	 * @return array<string,int>
	 */
	public static function state() {
		$state = get_option( self::STATE_OPTION, array() );
		$state = is_array( $state ) ? $state : array();

		return array_merge(
			array(
				'last_user_id' => 0,
				'scanned'      => 0,
				'imported'     => 0,
				'skipped'      => 0,
				'empty'        => 0,
				'completed'    => 0,
			),
			$state
		);
	}

	/**
	 * Persist progress state.
	 *
	 * @param array<string,int> $state State.
	 * @return void
	 */
	private static function save_state( array $state ) {
		update_option( self::STATE_OPTION, $state, false );
	}

	/**
	 * Forget progress so the next run starts from the beginning.
	 *
	 * @return void
	 */
	public static function reset() {
		delete_option( self::STATE_OPTION );
	}

	/**
	 * How many users carry WooCommerce attribution data at all.
	 *
	 * @return int
	 */
	public static function total_candidates() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key = %s",
				self::PROBE_KEY
			)
		);
	}

	/**
	 * How many users already hold an imported or captured first touch.
	 *
	 * @return int
	 */
	public static function total_done() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key = %s",
				Record::META_FIRST
			)
		);
	}

	/**
	 * Process one batch of users.
	 *
	 * @param int  $batch_size How many users to handle.
	 * @param bool $dry_run    When true, calculate but write nothing.
	 * @return array<string,int> Batch results, plus `done` when the run finished.
	 */
	public static function run_batch( $batch_size = 200, $dry_run = false ) {
		global $wpdb;

		$batch_size = max( 1, min( 2000, (int) $batch_size ) );
		$state      = self::state();
		$result     = array(
			'scanned'  => 0,
			'imported' => 0,
			'skipped'  => 0,
			'empty'    => 0,
			'done'     => 0,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$user_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT user_id FROM {$wpdb->usermeta}
				 WHERE meta_key = %s AND user_id > %d
				 ORDER BY user_id ASC
				 LIMIT %d",
				self::PROBE_KEY,
				(int) $state['last_user_id'],
				$batch_size
			)
		);

		$user_ids = array_map( 'intval', (array) $user_ids );

		if ( array() === $user_ids ) {
			$state['completed'] = 1;
			if ( ! $dry_run ) {
				self::save_state( $state );
			}
			$result['done'] = 1;

			return $result;
		}

		$rows = self::fetch_meta( $user_ids );

		foreach ( $user_ids as $user_id ) {
			++$result['scanned'];

			$meta = isset( $rows[ $user_id ] ) ? $rows[ $user_id ] : array();

			// Never clobber a record captured live — that one is the better claim.
			if ( isset( $meta[ Record::META_FIRST ] ) ) {
				++$result['skipped'];
				continue;
			}

			$record = self::build_record( $meta );

			if ( Record::is_empty( $record ) ) {
				++$result['empty'];
				continue;
			}

			if ( ! $dry_run ) {
				update_user_meta( $user_id, Record::META_FIRST, $record );
				update_user_meta( $user_id, Record::META_CHANNEL, $record['channel'] );

				// WooCommerce keeps one rolling touch per customer, so it is the
				// best evidence for both ends until live capture separates them.
				if ( ! isset( $meta[ Record::META_LAST ] ) ) {
					update_user_meta( $user_id, Record::META_LAST, $record );
				}
			}

			++$result['imported'];
		}

		$state['last_user_id'] = (int) end( $user_ids );
		$state['scanned']     += $result['scanned'];
		$state['imported']    += $result['imported'];
		$state['skipped']     += $result['skipped'];
		$state['empty']       += $result['empty'];

		if ( ! $dry_run ) {
			self::save_state( $state );
		}

		return $result;
	}

	/**
	 * Fetch every meta key we care about for a batch of users, in one query.
	 *
	 * @param int[] $user_ids User ids.
	 * @return array<int,array<string,string>> user_id => (meta_key => meta_value).
	 */
	private static function fetch_meta( array $user_ids ) {
		global $wpdb;

		$keys = array( Record::META_FIRST, Record::META_LAST );

		foreach ( array_keys( self::field_map() ) as $suffix ) {
			$keys[] = '_wc_order_attribution_' . $suffix;
		}

		$user_placeholders = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );
		$key_placeholders  = implode( ',', array_fill( 0, count( $keys ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholders are generated, values are passed to prepare().
		$sql = $wpdb->prepare(
			"SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta}
			 WHERE user_id IN ({$user_placeholders}) AND meta_key IN ({$key_placeholders})",
			array_merge( $user_ids, $keys )
		);
		// phpcs:enable

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql );

		$out = array();

		foreach ( (array) $rows as $row ) {
			$out[ (int) $row->user_id ][ $row->meta_key ] = $row->meta_value;
		}

		return $out;
	}

	/**
	 * Translate WooCommerce meta into one of our records.
	 *
	 * @param array<string,string> $meta Raw meta for a single user.
	 * @return array<string,mixed>
	 */
	private static function build_record( array $meta ) {
		$input = array();

		foreach ( self::field_map() as $suffix => $field ) {
			$key = '_wc_order_attribution_' . $suffix;

			if ( ! isset( $meta[ $key ] ) || '' === $meta[ $key ] ) {
				continue;
			}

			$input[ $field ] = $meta[ $key ];
		}

		// WooCommerce stores the session start as a datetime string, not a stamp.
		if ( isset( $input['ts'] ) ) {
			$stamp         = strtotime( (string) $input['ts'] );
			$input['ts']   = $stamp ? $stamp : 0;
		}

		$input['origin'] = Record::ORIGIN_BACKFILL;

		$record            = Record::sanitize( $input );
		$record['channel'] = ChannelMap::resolve( $record, self::custom_map() );

		return $record;
	}

	/**
	 * The admin-configured source => channel overrides.
	 *
	 * @return array<string,string>
	 */
	private static function custom_map() {
		static $map = null;

		if ( null === $map ) {
			$settings = get_option( 'ums_settings', array() );
			$raw      = is_array( $settings ) && isset( $settings['attribution']['channel_map'] )
				? $settings['attribution']['channel_map']
				: '';

			$map = ChannelMap::parse_map( (string) $raw );
		}

		return $map;
	}
}
