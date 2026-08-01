<?php
/**
 * WP-CLI commands.
 *
 * @package UserManagementSuite
 */

namespace Marinski\UserManagementSuite\Cli;

use Marinski\UserManagementSuite\Modules\Attribution\Backfill;
use Marinski\UserManagementSuite\Modules\Insights\Aggregator;
use Marinski\UserManagementSuite\Modules\Insights\StatsStore;
use Marinski\UserManagementSuite\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Manage User Management Suite data.
 *
 * The heavy jobs live here rather than behind an admin button because they run
 * over every user on the site: on a large install that is minutes of work, which
 * no web request should be asked to hold open.
 */
class Commands {

	/**
	 * Register the command namespace.
	 *
	 * @return void
	 */
	public static function register() {
		if ( ! defined( 'WP_CLI' ) || ! \WP_CLI ) {
			return;
		}

		\WP_CLI::add_command( 'ums attribution', array( __CLASS__, 'attribution' ) );
		\WP_CLI::add_command( 'ums stats', array( __CLASS__, 'stats' ) );
	}

	/**
	 * Import or inspect acquisition attribution.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : backfill | status | reset
	 *
	 * [--batch=<number>]
	 * : Users per batch. Default 500.
	 *
	 * [--dry-run]
	 * : Report what would happen without writing anything.
	 *
	 * [--limit=<number>]
	 * : Stop after this many users. Default 0 (no limit).
	 *
	 * ## EXAMPLES
	 *
	 *     wp ums attribution status
	 *     wp ums attribution backfill --dry-run --limit=1000
	 *     wp ums attribution backfill --batch=500
	 *
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public static function attribution( $args, $assoc_args ) {
		$action = isset( $args[0] ) ? $args[0] : 'status';

		if ( 'reset' === $action ) {
			Backfill::reset();
			\WP_CLI::success( 'Backfill progress reset.' );

			return;
		}

		if ( 'status' === $action ) {
			self::attribution_status();

			return;
		}

		if ( 'backfill' !== $action ) {
			\WP_CLI::error( 'Unknown action. Use backfill, status or reset.' );
		}

		Schema::maybe_upgrade();

		$batch   = (int) ( $assoc_args['batch'] ?? 500 );
		$limit   = (int) ( $assoc_args['limit'] ?? 0 );
		$dry_run = isset( $assoc_args['dry-run'] );

		$candidates = Backfill::total_candidates();
		$state      = Backfill::state();
		$remaining  = $limit > 0 ? $limit : max( 0, $candidates - (int) $state['scanned'] );

		if ( 0 === $candidates ) {
			\WP_CLI::warning( 'No WooCommerce attribution data found. Nothing to import.' );

			return;
		}

		\WP_CLI::log(
			sprintf(
				'%s %d users with WooCommerce attribution data (batch size %d).',
				$dry_run ? 'Dry run over' : 'Importing from',
				$candidates,
				$batch
			)
		);

		$progress = \WP_CLI\Utils\make_progress_bar( 'Importing', max( 1, $remaining ) );
		$totals   = array(
			'scanned'  => 0,
			'imported' => 0,
			'skipped'  => 0,
			'empty'    => 0,
		);

		while ( true ) {
			$result = Backfill::run_batch( $batch, $dry_run );

			foreach ( array_keys( $totals ) as $key ) {
				$totals[ $key ] += $result[ $key ];
			}

			$progress->tick( $result['scanned'] );

			if ( ! empty( $result['done'] ) ) {
				break;
			}

			if ( $limit > 0 && $totals['scanned'] >= $limit ) {
				break;
			}

			// A dry run never advances the stored cursor, so it would otherwise
			// re-read the same batch until the limit is hit.
			if ( $dry_run ) {
				break;
			}
		}

		$progress->finish();

		\WP_CLI::success(
			sprintf(
				'%s%d scanned, %d imported, %d already had a record, %d had no usable data.',
				$dry_run ? '[dry run] ' : '',
				$totals['scanned'],
				$totals['imported'],
				$totals['skipped'],
				$totals['empty']
			)
		);

		if ( ! $dry_run && $totals['imported'] > 0 ) {
			\WP_CLI::log( 'Next: wp ums stats rebuild --all' );
		}
	}

	/**
	 * Print backfill progress.
	 *
	 * @return void
	 */
	private static function attribution_status() {
		$state = Backfill::state();

		$rows = array(
			array(
				'metric' => 'Users with WooCommerce attribution',
				'value'  => Backfill::total_candidates(),
			),
			array(
				'metric' => 'Users with a UMS first-touch record',
				'value'  => Backfill::total_done(),
			),
			array(
				'metric' => 'Backfill cursor (user id)',
				'value'  => $state['last_user_id'],
			),
			array(
				'metric' => 'Scanned',
				'value'  => $state['scanned'],
			),
			array(
				'metric' => 'Imported',
				'value'  => $state['imported'],
			),
			array(
				'metric' => 'Skipped (already had a record)',
				'value'  => $state['skipped'],
			),
			array(
				'metric' => 'No usable data',
				'value'  => $state['empty'],
			),
			array(
				'metric' => 'Completed',
				'value'  => $state['completed'] ? 'yes' : 'no',
			),
		);

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'metric', 'value' ) );
	}

	/**
	 * Build or inspect the daily rollups.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : rebuild | status | truncate
	 *
	 * [--from=<date>]
	 * : Start date (Y-m-d). Defaults to 30 days ago.
	 *
	 * [--to=<date>]
	 * : End date (Y-m-d). Defaults to today.
	 *
	 * [--all]
	 * : Rebuild from the first registration on the site.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ums stats rebuild --all
	 *     wp ums stats rebuild --from=2026-01-01 --to=2026-07-31
	 *     wp ums stats status
	 *
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public static function stats( $args, $assoc_args ) {
		$action = isset( $args[0] ) ? $args[0] : 'status';

		Schema::maybe_upgrade();

		if ( 'truncate' === $action ) {
			StatsStore::truncate();
			\WP_CLI::success( 'Rollup table emptied.' );

			return;
		}

		if ( 'status' === $action ) {
			self::stats_status();

			return;
		}

		if ( 'rebuild' !== $action ) {
			\WP_CLI::error( 'Unknown action. Use rebuild, status or truncate.' );
		}

		$to   = isset( $assoc_args['to'] ) ? (string) $assoc_args['to'] : wp_date( 'Y-m-d' );
		$from = isset( $assoc_args['from'] ) ? (string) $assoc_args['from'] : wp_date( 'Y-m-d', strtotime( '-30 days' ) );

		if ( isset( $assoc_args['all'] ) ) {
			$from = Aggregator::earliest_signup_date();
		}

		if ( ! self::valid_date( $from ) || ! self::valid_date( $to ) ) {
			\WP_CLI::error( 'Dates must be in Y-m-d format.' );
		}

		if ( $from > $to ) {
			\WP_CLI::error( 'The start date is after the end date.' );
		}

		\WP_CLI::log( sprintf( 'Rebuilding rollups from %s to %s.', $from, $to ) );

		$start  = microtime( true );
		$result = Aggregator::rebuild(
			$from,
			$to,
			static function ( $month, $rows ) {
				\WP_CLI::log( sprintf( '  %s — %d rows', $month, $rows ) );
			}
		);

		\WP_CLI::success(
			sprintf(
				'%d users aggregated into %d rollup rows in %.1fs.',
				$result['users'],
				$result['rows'],
				microtime( true ) - $start
			)
		);
	}

	/**
	 * Print rollup table status.
	 *
	 * @return void
	 */
	private static function stats_status() {
		$bounds = StatsStore::date_bounds();
		$last   = (int) get_option( 'ums_stats_last_build', 0 );

		$rows = array(
			array(
				'metric' => 'Rollup rows',
				'value'  => StatsStore::row_count(),
			),
			array(
				'metric' => 'First date',
				'value'  => '' === $bounds['first'] ? '—' : $bounds['first'],
			),
			array(
				'metric' => 'Last date',
				'value'  => '' === $bounds['last'] ? '—' : $bounds['last'],
			),
			array(
				'metric' => 'Last build',
				'value'  => $last ? wp_date( 'Y-m-d H:i', $last ) : 'never',
			),
		);

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'metric', 'value' ) );
	}

	/**
	 * Whether a string is a Y-m-d date.
	 *
	 * @param string $date Date string.
	 * @return bool
	 */
	private static function valid_date( $date ) {
		$parsed = \DateTimeImmutable::createFromFormat( 'Y-m-d', (string) $date );

		return $parsed && $parsed->format( 'Y-m-d' ) === $date;
	}
}
