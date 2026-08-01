<?php
/**
 * The catalogue of notifications this site can send.
 *
 * @package UserManagementSuite
 */

namespace Marinski\UserManagementSuite\Modules\Notifications;

defined( 'ABSPATH' ) || exit;

/**
 * Collects every notification type any plugin has declared.
 *
 * Nothing can be offered as a preference until it has been named, so this
 * registry is the seam the whole module hangs off: adapters describe what a
 * plugin sends, and everything else — the preference screen, the resolver, the
 * unsubscribe link — works purely from these declarations.
 */
class Registry {

	/* Categories. */
	const TRANSACTIONAL = 'transactional';
	const MARKETING     = 'marketing';
	const PRODUCT       = 'product';
	const DIGEST        = 'digest';

	/**
	 * Cached type definitions.
	 *
	 * @var array<string,array<string,mixed>>|null
	 */
	private static $types = null;

	/**
	 * Category slug => label.
	 *
	 * @return array<string,string>
	 */
	public static function categories() {
		return array(
			self::TRANSACTIONAL => __( 'Account & orders', 'user-management-suite' ),
			self::PRODUCT       => __( 'Product updates', 'user-management-suite' ),
			self::DIGEST        => __( 'Summaries & digests', 'user-management-suite' ),
			self::MARKETING     => __( 'News & offers', 'user-management-suite' ),
		);
	}

	/**
	 * Human label for a category.
	 *
	 * @param string $category Category slug.
	 * @return string
	 */
	public static function category_label( $category ) {
		$labels = self::categories();

		return isset( $labels[ $category ] ) ? $labels[ $category ] : $category;
	}

	/**
	 * Normalise one declaration into the full shape.
	 *
	 * @param array<string,mixed> $type Raw declaration.
	 * @return array<string,mixed>|null Null when the declaration is unusable.
	 */
	private static function normalize( array $type ) {
		$id = isset( $type['id'] ) ? sanitize_key( (string) $type['id'] ) : '';

		if ( '' === $id ) {
			return null;
		}

		$category = isset( $type['category'] ) ? sanitize_key( (string) $type['category'] ) : self::MARKETING;

		if ( ! array_key_exists( $category, self::categories() ) ) {
			$category = self::MARKETING;
		}

		/*
		 * Transactional mail is never optional unless the declaring code says so
		 * explicitly. Defaulting the other way would let a careless declaration
		 * make order confirmations switchable.
		 */
		$optional = array_key_exists( 'user_optout_allowed', $type )
			? (bool) $type['user_optout_allowed']
			: ( self::TRANSACTIONAL !== $category );

		return array(
			'id'                  => $id,
			'label'               => isset( $type['label'] ) ? (string) $type['label'] : $id,
			'description'         => isset( $type['description'] ) ? (string) $type['description'] : '',
			'group'               => isset( $type['group'] ) ? (string) $type['group'] : __( 'General', 'user-management-suite' ),
			'category'            => $category,
			'channel'             => isset( $type['channel'] ) ? sanitize_key( (string) $type['channel'] ) : 'email',
			'default'             => array_key_exists( 'default', $type ) ? (bool) $type['default'] : true,
			'user_optout_allowed' => $optional,
		);
	}

	/**
	 * Every registered type, keyed by id.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function types() {
		if ( null !== self::$types ) {
			return self::$types;
		}

		/**
		 * Filters the notification types offered on the preference screen.
		 *
		 * Each entry accepts: id, label, description, group, category
		 * (transactional|product|digest|marketing), channel, default, and
		 * user_optout_allowed.
		 *
		 * @param array<int,array<string,mixed>> $types Declarations.
		 */
		$declared = apply_filters( 'ums_notification_types', array() );

		$out = array();

		foreach ( (array) $declared as $type ) {
			if ( ! is_array( $type ) ) {
				continue;
			}

			$normalized = self::normalize( $type );

			if ( null === $normalized ) {
				continue;
			}

			$out[ $normalized['id'] ] = $normalized;
		}

		self::$types = $out;

		return self::$types;
	}

	/**
	 * Discard the cached types. Used by tests and after settings changes.
	 *
	 * @return void
	 */
	public static function flush() {
		self::$types = null;
	}

	/**
	 * A single type declaration.
	 *
	 * @param string $id Type id.
	 * @return array<string,mixed>|null
	 */
	public static function get( $id ) {
		$types = self::types();

		return isset( $types[ $id ] ) ? $types[ $id ] : null;
	}

	/**
	 * Whether a type exists.
	 *
	 * @param string $id Type id.
	 * @return bool
	 */
	public static function exists( $id ) {
		return null !== self::get( $id );
	}

	/**
	 * Whether the user is allowed to switch a type off.
	 *
	 * @param string $id Type id.
	 * @return bool
	 */
	public static function is_optional( $id ) {
		$type = self::get( $id );

		return $type ? (bool) $type['user_optout_allowed'] : false;
	}

	/**
	 * The declared default for a type.
	 *
	 * @param string $id Type id.
	 * @return bool
	 */
	public static function default_for( $id ) {
		$type = self::get( $id );

		return $type ? (bool) $type['default'] : true;
	}

	/**
	 * Types grouped for display: group => category => types.
	 *
	 * @return array<string,array<string,array<int,array<string,mixed>>>>
	 */
	public static function grouped() {
		$out = array();

		foreach ( self::types() as $type ) {
			$out[ $type['group'] ][ $type['category'] ][] = $type;
		}

		ksort( $out );

		return $out;
	}

	/**
	 * All type ids in a category.
	 *
	 * @param string $category Category slug.
	 * @param bool   $optional_only Only types the user may switch off.
	 * @return string[]
	 */
	public static function ids_in_category( $category, $optional_only = true ) {
		$ids = array();

		foreach ( self::types() as $id => $type ) {
			if ( $type['category'] !== $category ) {
				continue;
			}

			if ( $optional_only && ! $type['user_optout_allowed'] ) {
				continue;
			}

			$ids[] = $id;
		}

		return $ids;
	}
}
