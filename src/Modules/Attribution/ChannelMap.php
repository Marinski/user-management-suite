<?php
/**
 * Normalises a raw traffic source into a small, stable set of channels.
 *
 * @package UserManagementSuite
 */

namespace Marinski\UserManagementSuite\Modules\Attribution;

defined( 'ABSPATH' ) || exit;

/**
 * Turns messy source/medium data into one of a handful of channels.
 *
 * Raw sources have a long tail — every referring host is its own value — which
 * is useless for spotting a trend. Channels are the level at which "where did
 * my users come from" is actually answerable.
 *
 * Site-specific rules belong in the settings map or the `ums_attribution_channel`
 * filter, never in this file: the defaults here have to stay true for any site.
 */
class ChannelMap {

	const DIRECT    = 'direct';
	const ORGANIC   = 'organic';
	const PAID      = 'paid';
	const SOCIAL    = 'social';
	const EMAIL     = 'email';
	const REFERRAL  = 'referral';
	const AFFILIATE = 'affiliate';
	const OWNED     = 'owned';
	const OTHER     = 'other';
	const UNKNOWN   = 'unknown';

	/**
	 * Channel slug => human label.
	 *
	 * @return array<string,string>
	 */
	public static function labels() {
		return array(
			self::DIRECT    => __( 'Direct', 'user-management-suite' ),
			self::ORGANIC   => __( 'Organic search', 'user-management-suite' ),
			self::PAID      => __( 'Paid', 'user-management-suite' ),
			self::SOCIAL    => __( 'Social', 'user-management-suite' ),
			self::EMAIL     => __( 'Email', 'user-management-suite' ),
			self::REFERRAL  => __( 'Referral', 'user-management-suite' ),
			self::AFFILIATE => __( 'Affiliate', 'user-management-suite' ),
			self::OWNED     => __( 'Owned properties', 'user-management-suite' ),
			self::OTHER     => __( 'Other', 'user-management-suite' ),
			self::UNKNOWN   => __( 'Not recorded', 'user-management-suite' ),
		);
	}

	/**
	 * Human label for a channel slug.
	 *
	 * @param string $channel Channel slug.
	 * @return string
	 */
	public static function label( $channel ) {
		$labels = self::labels();

		return isset( $labels[ $channel ] ) ? $labels[ $channel ] : $channel;
	}

	/**
	 * Hosts that are search engines.
	 *
	 * @return string[]
	 */
	private static function search_hosts() {
		return array(
			'google', 'bing', 'yahoo', 'duckduckgo', 'baidu', 'yandex', 'ecosia',
			'brave', 'startpage', 'qwant', 'naver', 'seznam', 'ask', 'aol',
			'searx', 'lycos', 'mojeek',
		);
	}

	/**
	 * Hosts that are social or community platforms.
	 *
	 * @return string[]
	 */
	private static function social_hosts() {
		return array(
			'facebook', 'instagram', 'youtube', 'twitter', 'x', 'linkedin',
			'pinterest', 'reddit', 'tiktok', 'snapchat', 'tumblr', 'quora',
			'telegram', 'whatsapp', 'discord', 'vk', 'weibo', 'threads',
			'mastodon', 'medium', 'twitch',
		);
	}

	/**
	 * Mediums that mean paid acquisition.
	 *
	 * @return string[]
	 */
	private static function paid_mediums() {
		return array( 'cpc', 'ppc', 'paid', 'paidsearch', 'paid-search', 'cpm', 'cpv', 'cpa', 'banner', 'display', 'retargeting', 'ads' );
	}

	/**
	 * Resolve an attribution record to a channel slug.
	 *
	 * @param array<string,mixed> $attr    Attribution data (source, medium, type, referrer...).
	 * @param array<string,string> $custom Optional host/source => channel overrides.
	 * @return string
	 */
	public static function resolve( array $attr, array $custom = array() ) {
		$source   = strtolower( trim( (string) ( $attr['source'] ?? '' ) ) );
		$medium   = strtolower( trim( (string) ( $attr['medium'] ?? '' ) ) );
		$type     = strtolower( trim( (string) ( $attr['type'] ?? '' ) ) );
		$click_id = trim( (string) ( $attr['click_id'] ?? '' ) );

		/*
		 * A click id only exists because an ad platform put it there. Without this
		 * an auto-tagged Google Ads click — gclid, no utm parameters, referrer
		 * google.com — reads as organic search, quietly crediting SEO for traffic
		 * that was paid for.
		 */
		if ( '' !== $click_id && '' === self::match_custom( $source, $custom ) ) {
			/** This filter is documented below in this method. */
			return apply_filters( 'ums_attribution_channel', self::PAID, $attr );
		}

		$channel = self::calculate( $source, $medium, $type, $custom );

		/**
		 * Filters the channel resolved for an attribution record.
		 *
		 * The place to put site-specific rules that the generic defaults cannot know
		 * about — partner networks, internal campaign naming conventions and so on.
		 *
		 * @param string              $channel Resolved channel slug.
		 * @param array<string,mixed> $attr    Full attribution record.
		 */
		return apply_filters( 'ums_attribution_channel', $channel, $attr );
	}

	/**
	 * The default resolution rules.
	 *
	 * @param string               $source Lowercased source.
	 * @param string               $medium Lowercased medium.
	 * @param string               $type   Lowercased source type.
	 * @param array<string,string> $custom Host/source => channel overrides.
	 * @return string
	 */
	private static function calculate( $source, $medium, $type, array $custom ) {
		// Site-owned rules win, so an admin can always override the defaults.
		$override = self::match_custom( $source, $custom );
		if ( '' !== $override ) {
			return $override;
		}

		if ( 'affiliate' === $medium || 'affiliate' === $type ) {
			return self::AFFILIATE;
		}

		if ( in_array( $medium, self::paid_mediums(), true ) ) {
			return self::PAID;
		}

		if ( 'email' === $medium || 'newsletter' === $medium || 'crm' === $source ) {
			return self::EMAIL;
		}

		// WooCommerce writes "(direct)" as the source for typed-in traffic.
		if ( '' === $source || '(direct)' === $source || 'direct' === $source || 'typein' === $type ) {
			return self::DIRECT;
		}

		$root = self::root_name( $source );

		if ( in_array( $root, self::social_hosts(), true ) ) {
			return self::SOCIAL;
		}

		if ( in_array( $root, self::search_hosts(), true ) || 'organic' === $type ) {
			return self::ORGANIC;
		}

		if ( self::is_own_host( $source ) ) {
			return self::OWNED;
		}

		if ( 'referral' === $type || '' !== $source ) {
			return self::REFERRAL;
		}

		return self::OTHER;
	}

	/**
	 * Match a source against the admin-supplied map.
	 *
	 * Entries beginning with a dot match by suffix, so `.forexsb.com` covers every
	 * subdomain with one line.
	 *
	 * @param string               $source Lowercased source.
	 * @param array<string,string> $custom Map of pattern => channel.
	 * @return string Channel slug, or '' when nothing matched.
	 */
	private static function match_custom( $source, array $custom ) {
		if ( '' === $source || array() === $custom ) {
			return '';
		}

		foreach ( $custom as $pattern => $channel ) {
			$pattern = strtolower( trim( (string) $pattern ) );

			if ( '' === $pattern ) {
				continue;
			}

			if ( '.' === $pattern[0] ) {
				if ( substr( $source, -strlen( $pattern ) ) === $pattern ) {
					return sanitize_key( $channel );
				}
				continue;
			}

			if ( $source === $pattern ) {
				return sanitize_key( $channel );
			}
		}

		return '';
	}

	/**
	 * Reduce a host to its registrable-ish name, so `m.youtube.com`,
	 * `youtube.com` and `www.youtube.co.uk` all collapse to `youtube`.
	 *
	 * @param string $source Source host or name.
	 * @return string
	 */
	private static function root_name( $source ) {
		$host = $source;

		if ( false !== strpos( $host, '/' ) ) {
			$parsed = wp_parse_url( $host, PHP_URL_HOST );
			$host   = $parsed ? $parsed : $host;
		}

		$host = preg_replace( '/^www\d*\./', '', $host );
		$host = preg_replace( '/^(m|mobile|amp|l)\./', '', (string) $host );

		$parts = explode( '.', (string) $host );
		if ( count( $parts ) < 2 ) {
			return (string) $host;
		}

		/*
		 * Walk back past the public suffix. Two-letter final labels are country
		 * codes, which are usually preceded by a second-level suffix (co.uk,
		 * com.au), so step over one more part in that case.
		 */
		$index = count( $parts ) - 2;
		if ( 2 === strlen( end( $parts ) ) && $index > 0 && strlen( $parts[ $index ] ) <= 3 ) {
			--$index;
		}

		return isset( $parts[ $index ] ) ? $parts[ $index ] : (string) $host;
	}

	/**
	 * Whether a source points back at this installation.
	 *
	 * @param string $source Source host.
	 * @return bool
	 */
	private static function is_own_host( $source ) {
		$home = wp_parse_url( home_url(), PHP_URL_HOST );

		if ( ! $home ) {
			return false;
		}

		$home = strtolower( (string) $home );

		return $source === $home || self::root_name( $source ) === self::root_name( $home );
	}

	/**
	 * Parse the admin textarea (one `pattern = channel` per line) into a map.
	 *
	 * @param string $raw Raw textarea contents.
	 * @return array<string,string>
	 */
	public static function parse_map( $raw ) {
		$map = array();

		foreach ( preg_split( '/\r\n|\r|\n/', (string) $raw ) as $line ) {
			$line = trim( $line );

			if ( '' === $line || '#' === $line[0] ) {
				continue;
			}

			$parts = array_map( 'trim', explode( '=', $line, 2 ) );

			if ( 2 !== count( $parts ) || '' === $parts[0] || '' === $parts[1] ) {
				continue;
			}

			$map[ strtolower( $parts[0] ) ] = sanitize_key( $parts[1] );
		}

		return $map;
	}

	/**
	 * Render a map back to textarea form.
	 *
	 * @param array<string,string> $map Pattern => channel.
	 * @return string
	 */
	public static function render_map( array $map ) {
		$lines = array();

		foreach ( $map as $pattern => $channel ) {
			$lines[] = $pattern . ' = ' . $channel;
		}

		return implode( "\n", $lines );
	}
}
