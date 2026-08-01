<?php
/**
 * Reads acquisition data off the current request and remembers it.
 *
 * @package UserManagementSuite
 */

namespace Marinski\UserManagementSuite\Modules\Attribution;

defined( 'ABSPATH' ) || exit;

/**
 * Captures the touch that brought a visitor here, and holds it until they
 * register.
 *
 * Capture happens on the landing page rather than at registration, which is the
 * whole point of the module: by the time someone submits a signup form the
 * referrer is the site's own page, so anything read at that moment records the
 * last internal click instead of where the person actually came from.
 */
class Capture {

	/** Query parameters that identify a paid click. */
	const CLICK_IDS = array( 'gclid', 'gbraid', 'wbraid', 'fbclid', 'msclkid', 'ttclid', 'li_fat_id', 'twclid', 'epik', 'irclickid' );

	/**
	 * Build a record from the current HTTP request.
	 *
	 * @return array<string,mixed>
	 */
	public static function from_request() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Reading public campaign parameters off a normal page view.
		$get = wp_unslash( $_GET );
		$get = is_array( $get ) ? $get : array();

		$input = array(
			'campaign' => $get['utm_campaign'] ?? '',
			'content'  => $get['utm_content'] ?? '',
			'term'     => $get['utm_term'] ?? '',
			'medium'   => $get['utm_medium'] ?? '',
			'source'   => $get['utm_source'] ?? '',
			'landing'  => self::current_url(),
			'device'   => wp_is_mobile() ? 'Mobile' : 'Desktop',
			'origin'   => Record::ORIGIN_COOKIE,
		);

		foreach ( self::CLICK_IDS as $param ) {
			if ( ! empty( $get[ $param ] ) ) {
				$input['click_id'] = $param . ':' . $get[ $param ];
				break;
			}
		}
		// phpcs:enable

		$referrer = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
		$host     = '' !== $referrer ? wp_parse_url( $referrer, PHP_URL_HOST ) : '';
		$external = $host && ! self::is_internal( (string) $host );

		/*
		 * Only an external referrer is kept. Storing an internal one would make
		 * every page view look like a fresh touch, and the visitor's last recorded
		 * source would end up being whichever of our own pages they clicked from.
		 */
		if ( $external ) {
			$input['referrer'] = $referrer;

			// utm_source wins when present; otherwise fall back to the referring host.
			if ( '' === (string) $input['source'] ) {
				$input['source'] = strtolower( (string) $host );
				$input['medium'] = '' === (string) $input['medium'] ? 'referral' : $input['medium'];
				$input['type']   = 'referral';
			}
		}

		if ( '' !== (string) $input['source'] && '' === (string) ( $input['type'] ?? '' ) ) {
			$input['type'] = '' === (string) $input['medium'] ? 'referral' : 'utm';
		}

		$record            = Record::sanitize( $input );
		$record['channel'] = ChannelMap::resolve( $record, self::channel_map() );

		return $record;
	}

	/**
	 * Whether this request carries anything worth remembering.
	 *
	 * A visitor arriving with no referrer and no campaign parameters is direct
	 * traffic; storing a row for that would only dilute the data.
	 *
	 * @param array<string,mixed> $record Candidate record.
	 * @return bool
	 */
	public static function has_signal( array $record ) {
		return ! Record::is_empty( $record );
	}

	/**
	 * Remember the current request's touch in the visitor's cookie.
	 *
	 * @param int $days How long the cookie should live.
	 * @return void
	 */
	public static function remember( $days = 30 ) {
		if ( headers_sent() ) {
			return;
		}

		$record = self::from_request();

		if ( ! self::has_signal( $record ) ) {
			return;
		}

		$stored = self::read_cookie();

		// The first touch is written once and then left alone; only the last one
		// moves. Overwriting the first would turn every returning visitor into a
		// fresh acquisition.
		$payload = array(
			'f' => isset( $stored['f'] ) && is_array( $stored['f'] ) ? $stored['f'] : self::compact( $record ),
			'l' => self::compact( $record ),
		);

		$encoded = wp_json_encode( $payload );

		if ( ! is_string( $encoded ) ) {
			return;
		}

		setcookie(
			Record::COOKIE,
			$encoded,
			array(
				'expires'  => time() + ( max( 1, (int) $days ) * DAY_IN_SECONDS ),
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);

		// So a registration in this same request sees it without a round trip.
		$_COOKIE[ Record::COOKIE ] = $encoded;
	}

	/**
	 * Read and validate the stored cookie.
	 *
	 * @return array<string,mixed>
	 */
	public static function read_cookie() {
		if ( ! isset( $_COOKIE[ Record::COOKIE ] ) ) {
			return array();
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated as JSON below, then every field passes through Record::sanitize().
		$raw = wp_unslash( $_COOKIE[ Record::COOKIE ] );

		if ( ! is_string( $raw ) || strlen( $raw ) > 4096 ) {
			return array();
		}

		$decoded = json_decode( $raw, true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Forget the stored touch.
	 *
	 * @return void
	 */
	public static function clear_cookie() {
		unset( $_COOKIE[ Record::COOKIE ] );

		if ( headers_sent() ) {
			return;
		}

		setcookie(
			Record::COOKIE,
			'',
			array(
				'expires'  => time() - DAY_IN_SECONDS,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	/**
	 * The first and last touch for the current visitor, from any available
	 * source: an explicit payload, the request body, the cookie, or this request.
	 *
	 * Integrations that own their own signup endpoint — a headless front end, for
	 * instance — pass the payload through rather than relying on a cookie that
	 * never reaches WordPress.
	 *
	 * @param array<string,mixed> $explicit Optional caller-supplied data.
	 * @return array{first:array<string,mixed>,last:array<string,mixed>}
	 */
	public static function resolve_touches( array $explicit = array() ) {
		/**
		 * Filters the raw attribution payload before it is stored for a new user.
		 *
		 * @param array<string,mixed> $explicit Caller-supplied attribution data.
		 */
		$explicit = apply_filters( 'ums_attribution_payload', $explicit );

		if ( array() === $explicit ) {
			$explicit = self::from_submitted_payload();
		}

		if ( array() !== $explicit ) {
			$record            = Record::sanitize( array_merge( $explicit, array( 'origin' => Record::ORIGIN_REST ) ) );
			$record['channel'] = ChannelMap::resolve( $record, self::channel_map() );

			return array(
				'first' => $record,
				'last'  => $record,
			);
		}

		$cookie = self::read_cookie();

		if ( isset( $cookie['f'] ) && is_array( $cookie['f'] ) ) {
			$first            = Record::sanitize( $cookie['f'] );
			$first['channel'] = ChannelMap::resolve( $first, self::channel_map() );

			$last            = isset( $cookie['l'] ) && is_array( $cookie['l'] ) ? Record::sanitize( $cookie['l'] ) : $first;
			$last['channel'] = ChannelMap::resolve( $last, self::channel_map() );

			return array(
				'first' => $first,
				'last'  => $last,
			);
		}

		$now = self::from_request();

		return array(
			'first' => $now,
			'last'  => $now,
		);
	}

	/**
	 * Look for an attribution payload submitted alongside a registration.
	 *
	 * Accepts either a JSON string or an array, under `ums_attribution`.
	 *
	 * @return array<string,mixed>
	 */
	private static function from_submitted_payload() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only; the surrounding registration flow owns nonce checking; decoded as JSON below, then every field passes through Record::sanitize().
		$raw = isset( $_POST['ums_attribution'] ) ? wp_unslash( $_POST['ums_attribution'] ) : null;

		if ( null === $raw ) {
			return array();
		}

		if ( is_string( $raw ) ) {
			if ( strlen( $raw ) > 4096 ) {
				return array();
			}

			$raw = json_decode( $raw, true );
		}

		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * Persist the resolved touches against a user.
	 *
	 * @param int                 $user_id  User id.
	 * @param array<string,mixed> $explicit Optional caller-supplied data.
	 * @return void
	 */
	public static function store_for_user( $user_id, array $explicit = array() ) {
		$user_id = (int) $user_id;

		if ( $user_id <= 0 ) {
			return;
		}

		$touches = self::resolve_touches( $explicit );

		if ( ! self::has_signal( $touches['first'] ) && ! self::has_signal( $touches['last'] ) ) {
			return;
		}

		// Never overwrite a first touch: it is a claim about a moment that has
		// already passed.
		$existing = get_user_meta( $user_id, Record::META_FIRST, true );

		if ( ! is_array( $existing ) || array() === $existing ) {
			update_user_meta( $user_id, Record::META_FIRST, $touches['first'] );
			update_user_meta( $user_id, Record::META_CHANNEL, $touches['first']['channel'] );
		}

		update_user_meta( $user_id, Record::META_LAST, $touches['last'] );

		/**
		 * Fires after a new user's attribution has been stored.
		 *
		 * @param int                 $user_id User id.
		 * @param array<string,mixed> $touches First and last touch.
		 */
		do_action( 'ums_attribution_stored', $user_id, $touches );
	}

	/**
	 * Reduce a record to the fields worth carrying in a cookie.
	 *
	 * @param array<string,mixed> $record Full record.
	 * @return array<string,mixed>
	 */
	private static function compact( array $record ) {
		$out = array();

		foreach ( array( 'source', 'medium', 'campaign', 'content', 'term', 'device', 'type', 'click_id', 'origin', 'ts' ) as $field ) {
			if ( isset( $record[ $field ] ) && '' !== $record[ $field ] ) {
				$out[ $field ] = $record[ $field ];
			}
		}

		// URLs are the bulk of a record and browsers cap cookies around 4KB.
		foreach ( array( 'referrer', 'landing' ) as $field ) {
			if ( ! empty( $record[ $field ] ) ) {
				$out[ $field ] = substr( (string) $record[ $field ], 0, 200 );
			}
		}

		return $out;
	}

	/**
	 * The current request URL, without campaign noise.
	 *
	 * @return string
	 */
	private static function current_url() {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		if ( '' === $host ) {
			return '';
		}

		return ( is_ssl() ? 'https://' : 'http://' ) . $host . $uri;
	}

	/**
	 * Whether a host belongs to this installation.
	 *
	 * @param string $host Host name.
	 * @return bool
	 */
	private static function is_internal( $host ) {
		$home = wp_parse_url( home_url(), PHP_URL_HOST );

		return $home && strtolower( (string) $home ) === strtolower( $host );
	}

	/**
	 * Admin-configured source => channel overrides.
	 *
	 * @return array<string,string>
	 */
	private static function channel_map() {
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
