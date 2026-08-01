<?php
/**
 * A single attribution touch.
 *
 * @package UserManagementSuite
 */

namespace Marinski\UserManagementSuite\Modules\Attribution;

defined( 'ABSPATH' ) || exit;

/**
 * Value object for one acquisition touch, plus the meta keys it lives under.
 *
 * Stored as a single serialised meta row per touch rather than one row per
 * field: eleven fields across a large user table would otherwise add over a
 * million usermeta rows for data that is always read as a unit.
 */
class Record {

	/** First touch — written once, never overwritten. */
	const META_FIRST = '_ums_attribution_first';

	/** Most recent touch before registration. */
	const META_LAST = '_ums_attribution_last';

	/** Flat copy of the first-touch channel, so the Users screen can filter on it. */
	const META_CHANNEL = '_ums_attribution_channel';

	/** Cookie carrying the pre-registration touch. */
	const COOKIE = 'ums_attr';

	/*
	 * Where a record came from. Kept on the record itself because a backfilled
	 * touch is a weaker claim than a captured one, and reports should be able to
	 * say so rather than presenting both as equally certain.
	 */
	const ORIGIN_COOKIE   = 'cookie';
	const ORIGIN_REST     = 'rest';
	const ORIGIN_BACKFILL = 'wc_backfill';

	/**
	 * Field names, in storage order.
	 *
	 * @return string[]
	 */
	public static function fields() {
		return array( 'source', 'medium', 'campaign', 'content', 'term', 'referrer', 'landing', 'device', 'type', 'click_id', 'channel', 'origin', 'ts' );
	}

	/**
	 * An empty record with every key present.
	 *
	 * @return array<string,mixed>
	 */
	public static function blank() {
		$out = array();

		foreach ( self::fields() as $field ) {
			$out[ $field ] = '';
		}

		$out['ts'] = 0;

		return $out;
	}

	/**
	 * Normalise arbitrary input into a storable record.
	 *
	 * @param array<string,mixed> $input Raw data.
	 * @return array<string,mixed>
	 */
	public static function sanitize( array $input ) {
		$out = self::blank();

		foreach ( self::fields() as $field ) {
			if ( ! isset( $input[ $field ] ) ) {
				continue;
			}

			$value = $input[ $field ];

			switch ( $field ) {
				case 'ts':
					$out[ $field ] = absint( $value );
					break;

				case 'referrer':
				case 'landing':
					// Trim to a sane length: some referrers carry enormous query strings.
					$out[ $field ] = substr( esc_url_raw( (string) $value ), 0, 500 );
					break;

				case 'source':
					$out[ $field ] = strtolower( substr( sanitize_text_field( (string) $value ), 0, 191 ) );
					break;

				default:
					$out[ $field ] = substr( sanitize_text_field( (string) $value ), 0, 191 );
					break;
			}
		}

		if ( 0 === $out['ts'] ) {
			$out['ts'] = time();
		}

		return $out;
	}

	/**
	 * Whether a record carries no usable signal.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return bool
	 */
	public static function is_empty( array $record ) {
		foreach ( array( 'source', 'medium', 'campaign', 'referrer', 'click_id' ) as $field ) {
			if ( '' !== (string) ( $record[ $field ] ?? '' ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Read a stored record for a user.
	 *
	 * @param int    $user_id User id.
	 * @param string $key     Meta key (defaults to first touch).
	 * @return array<string,mixed>
	 */
	public static function get( $user_id, $key = self::META_FIRST ) {
		$stored = get_user_meta( $user_id, $key, true );

		if ( ! is_array( $stored ) ) {
			return self::blank();
		}

		return array_merge( self::blank(), $stored );
	}

	/**
	 * Human-readable one-line summary, for admin columns.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return string
	 */
	public static function summary( array $record ) {
		$source = (string) ( $record['source'] ?? '' );
		$medium = (string) ( $record['medium'] ?? '' );

		if ( '' === $source ) {
			return ChannelMap::label( (string) ( $record['channel'] ?? ChannelMap::OTHER ) );
		}

		return '' === $medium ? $source : $source . ' / ' . $medium;
	}
}
