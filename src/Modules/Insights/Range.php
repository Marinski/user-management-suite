<?php
/**
 * Report date range handling.
 *
 * @package UserManagementSuite
 */

namespace Marinski\UserManagementSuite\Modules\Insights;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the requested reporting window and the comparable period before it.
 *
 * Every report shows a change-versus-previous figure, so the preceding window
 * of the same length is computed here once rather than in each report.
 */
class Range {

	/**
	 * Start date, Y-m-d.
	 *
	 * @var string
	 */
	public $from;

	/**
	 * End date, Y-m-d.
	 *
	 * @var string
	 */
	public $to;

	/**
	 * Preset key in use.
	 *
	 * @var string
	 */
	public $preset;

	/**
	 * Start of the comparison window, Y-m-d.
	 *
	 * @var string
	 */
	public $prev_from;

	/**
	 * End of the comparison window, Y-m-d.
	 *
	 * @var string
	 */
	public $prev_to;

	/**
	 * Available presets: key => label.
	 *
	 * @return array<string,string>
	 */
	public static function presets() {
		return array(
			'7'          => __( 'Last 7 days', 'user-management-suite' ),
			'30'         => __( 'Last 30 days', 'user-management-suite' ),
			'90'         => __( 'Last 90 days', 'user-management-suite' ),
			'365'        => __( 'Last 12 months', 'user-management-suite' ),
			'this_month' => __( 'This month', 'user-management-suite' ),
			'last_month' => __( 'Last month', 'user-management-suite' ),
			'ytd'        => __( 'Year to date', 'user-management-suite' ),
			'all'        => __( 'All time', 'user-management-suite' ),
			'custom'     => __( 'Custom range', 'user-management-suite' ),
		);
	}

	/**
	 * Build a range from request parameters.
	 *
	 * @param array<string,mixed> $request Raw request data (already unslashed).
	 * @return Range
	 */
	public static function from_request( array $request ) {
		$range  = new self();
		$preset = isset( $request['range'] ) ? sanitize_key( (string) $request['range'] ) : '30';

		if ( ! array_key_exists( $preset, self::presets() ) ) {
			$preset = '30';
		}

		$today = wp_date( 'Y-m-d' );

		switch ( $preset ) {
			case 'this_month':
				$from = wp_date( 'Y-m-01' );
				$to   = $today;
				break;

			case 'last_month':
				$first_of_this = wp_date( 'Y-m-01' );
				$to            = wp_date( 'Y-m-d', strtotime( $first_of_this . ' -1 day' ) );
				$from          = wp_date( 'Y-m-01', strtotime( $to ) );
				break;

			case 'ytd':
				$from = wp_date( 'Y-01-01' );
				$to   = $today;
				break;

			case 'all':
				$bounds = StatsStore::date_bounds();
				$from   = '' !== $bounds['first'] ? $bounds['first'] : wp_date( 'Y-m-d', strtotime( '-30 days' ) );
				$to     = '' !== $bounds['last'] ? max( $bounds['last'], $today ) : $today;
				break;

			case 'custom':
				$from = self::clean_date( $request['from'] ?? '', wp_date( 'Y-m-d', strtotime( '-30 days' ) ) );
				$to   = self::clean_date( $request['to'] ?? '', $today );

				if ( $from > $to ) {
					list( $from, $to ) = array( $to, $from );
				}
				break;

			default:
				$days = (int) $preset;
				$to   = $today;
				$from = wp_date( 'Y-m-d', strtotime( $to . ' -' . ( $days - 1 ) . ' days' ) );
				break;
		}

		$range->preset = $preset;
		$range->from   = $from;
		$range->to     = $to;

		$length           = max( 1, $range->days() );
		$range->prev_to   = wp_date( 'Y-m-d', strtotime( $from . ' -1 day' ) );
		$range->prev_from = wp_date( 'Y-m-d', strtotime( $range->prev_to . ' -' . ( $length - 1 ) . ' days' ) );

		return $range;
	}

	/**
	 * Number of days in the range, inclusive.
	 *
	 * @return int
	 */
	public function days() {
		$from = strtotime( $this->from );
		$to   = strtotime( $this->to );

		if ( ! $from || ! $to ) {
			return 1;
		}

		return (int) floor( ( $to - $from ) / DAY_IN_SECONDS ) + 1;
	}

	/**
	 * Human label for the current selection.
	 *
	 * @return string
	 */
	public function label() {
		$presets = self::presets();

		if ( 'custom' === $this->preset ) {
			return sprintf(
				/* translators: 1: start date, 2: end date. */
				__( '%1$s to %2$s', 'user-management-suite' ),
				$this->format( $this->from ),
				$this->format( $this->to )
			);
		}

		return isset( $presets[ $this->preset ] ) ? $presets[ $this->preset ] : $this->preset;
	}

	/**
	 * Format a stored date for display.
	 *
	 * @param string $date Y-m-d.
	 * @return string
	 */
	public function format( $date ) {
		$stamp = strtotime( $date );

		return $stamp ? wp_date( (string) get_option( 'date_format' ), $stamp ) : $date;
	}

	/**
	 * Whether the range is long enough that daily points become noise.
	 *
	 * @return bool
	 */
	public function should_group_weekly() {
		return $this->days() > 120;
	}

	/**
	 * Collapse a daily series into weekly or monthly buckets when the window is
	 * long, so the chart stays readable.
	 *
	 * @param array<string,int> $series date => value.
	 * @return array<string,int>
	 */
	public function group( array $series ) {
		$days = $this->days();

		if ( $days <= 120 ) {
			return $series;
		}

		$format = $days > 400 ? 'Y-m-01' : 'o-\WW';
		$out    = array();

		foreach ( $series as $date => $value ) {
			$stamp = strtotime( $date );

			if ( ! $stamp ) {
				continue;
			}

			$bucket = 'Y-m-01' === $format ? wp_date( 'Y-m-01', $stamp ) : wp_date( 'Y-m-d', strtotime( 'monday this week', $stamp ) );

			if ( ! isset( $out[ $bucket ] ) ) {
				$out[ $bucket ] = 0;
			}

			$out[ $bucket ] += $value;
		}

		ksort( $out );

		return $out;
	}

	/**
	 * Fill in missing dates with zeroes so a gap reads as "none", not "no data".
	 *
	 * @param array<string,int> $series date => value.
	 * @return array<string,int>
	 */
	public function fill( array $series ) {
		$out    = array();
		$cursor = strtotime( $this->from );
		$end    = strtotime( $this->to );

		if ( ! $cursor || ! $end ) {
			return $series;
		}

		// Guard against a pathological range producing millions of points.
		$limit = 0;

		while ( $cursor <= $end && $limit < 2000 ) {
			$date         = wp_date( 'Y-m-d', $cursor );
			$out[ $date ] = isset( $series[ $date ] ) ? (int) $series[ $date ] : 0;
			$cursor      += DAY_IN_SECONDS;
			++$limit;
		}

		return $out;
	}

	/**
	 * Validate a Y-m-d string.
	 *
	 * @param mixed  $value    Raw value.
	 * @param string $fallback Value to use when invalid.
	 * @return string
	 */
	private static function clean_date( $value, $fallback ) {
		$value  = sanitize_text_field( (string) $value );
		$parsed = \DateTimeImmutable::createFromFormat( 'Y-m-d', $value );

		return ( $parsed && $parsed->format( 'Y-m-d' ) === $value ) ? $value : $fallback;
	}
}
