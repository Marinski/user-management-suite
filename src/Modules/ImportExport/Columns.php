<?php
/**
 * Column definitions for CSV import and export.
 *
 * @package UserManagementSuite
 */

namespace Marinski\UserManagementSuite\Modules\ImportExport;

defined( 'ABSPATH' ) || exit;

/**
 * Centralised registry of every column the Import/Export module can handle.
 *
 * Each entry:
 *   label      – human-readable name shown in the UI.
 *   group      – GROUP_CORE | GROUP_META | GROUP_UMS | GROUP_WOO.
 *   default    – pre-checked in the export column picker.
 *   importable – whether the column can be written on import.
 *   meta_key   – (meta/ums/woo only) the actual usermeta key.
 */
class Columns {

	const GROUP_CORE = 'core';
	const GROUP_META = 'meta';
	const GROUP_UMS  = 'ums';
	const GROUP_WOO  = 'woo';

	/**
	 * All column definitions, merged by group.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function all() {
		$cols = array_merge(
			self::core_columns(),
			self::meta_columns(),
			self::ums_columns()
		);

		if ( self::woocommerce_active() ) {
			$cols = array_merge( $cols, self::woo_columns() );
		}

		/**
		 * Filter the complete column list.
		 *
		 * @param array<string,array<string,mixed>> $cols Column definitions keyed by column id.
		 */
		return apply_filters( 'ums_ie_columns', $cols );
	}

	/**
	 * Only columns for the export picker, keyed by group label.
	 *
	 * @return array<string,array<string,array<string,mixed>>>
	 */
	public static function grouped() {
		$groups = array(
			self::GROUP_CORE => __( 'Core user fields', 'user-management-suite' ),
			self::GROUP_META => __( 'User meta', 'user-management-suite' ),
			self::GROUP_UMS  => __( 'UMS tracking', 'user-management-suite' ),
			self::GROUP_WOO  => __( 'WooCommerce', 'user-management-suite' ),
		);

		$out = array();
		foreach ( self::all() as $key => $col ) {
			$group                 = isset( $col['group'] ) ? $col['group'] : self::GROUP_META;
			$label                 = isset( $groups[ $group ] ) ? $groups[ $group ] : $group;
			$out[ $label ][ $key ] = $col;
		}

		return $out;
	}

	/**
	 * Keys that are selected by default.
	 *
	 * @return string[]
	 */
	public static function default_keys() {
		$keys = array();
		foreach ( self::all() as $key => $col ) {
			if ( ! empty( $col['default'] ) ) {
				$keys[] = $key;
			}
		}
		return $keys;
	}

	/**
	 * Keys that can be imported (written to WP).
	 *
	 * @return string[]
	 */
	public static function importable_keys() {
		$keys = array();
		foreach ( self::all() as $key => $col ) {
			if ( ! empty( $col['importable'] ) ) {
				$keys[] = $key;
			}
		}
		return $keys;
	}

	/**
	 * Auto-map a raw CSV header string to a known column key.
	 *
	 * @param string $header Raw header from CSV.
	 * @return string Column key, or empty string if no match.
	 */
	public static function auto_map( $header ) {
		$header = strtolower( trim( $header ) );
		$cols   = self::all();

		// Exact key match.
		if ( isset( $cols[ $header ] ) ) {
			return $header;
		}

		// Try sanitized header against known keys.
		$sanitized = preg_replace( '/[^a-z0-9_]/', '_', $header );
		if ( isset( $cols[ $sanitized ] ) ) {
			return $sanitized;
		}

		// Known aliases.
		$aliases = array(
			'id'                  => 'ID',
			'user_id'             => 'ID',
			'userid'              => 'ID',
			'email'               => 'user_email',
			'username'            => 'user_login',
			'login'               => 'user_login',
			'password'            => 'user_pass',
			'pass'                => 'user_pass',
			'name'                => 'display_name',
			'registered'          => 'user_registered',
			'reg_date'            => 'user_registered',
			'registration_date'   => 'user_registered',
			'website'             => 'user_url',
			'url'                 => 'user_url',
			'role'                => 'roles',
			'bio'                 => 'description',
			'reg_source'          => 'ums_registration_source',
			'registration_source' => 'ums_registration_source',
			'reg_url'             => 'ums_registration_url',
			'registration_url'    => 'ums_registration_url',
			'last_login'          => 'ums_last_login',
			'last_ip'             => 'ums_last_login_ip',
			'verified'            => 'ums_activation_status',
		);

		if ( isset( $aliases[ $header ] ) ) {
			return $aliases[ $header ];
		}

		return '';
	}

	/**
	 * Core wp_users table columns.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function core_columns() {
		return array(
			'ID'              => array(
				'label'      => __( 'User ID', 'user-management-suite' ),
				'group'      => self::GROUP_CORE,
				'default'    => true,
				'importable' => true,
			),
			'user_login'      => array(
				'label'      => __( 'Username', 'user-management-suite' ),
				'group'      => self::GROUP_CORE,
				'default'    => true,
				'importable' => true,
			),
			'user_pass'       => array(
				'label'      => __( 'Password (hashed)', 'user-management-suite' ),
				'group'      => self::GROUP_CORE,
				'default'    => false,
				'importable' => true,
			),
			'user_email'      => array(
				'label'      => __( 'Email', 'user-management-suite' ),
				'group'      => self::GROUP_CORE,
				'default'    => true,
				'importable' => true,
			),
			'user_registered' => array(
				'label'      => __( 'Registered date', 'user-management-suite' ),
				'group'      => self::GROUP_CORE,
				'default'    => true,
				'importable' => true,
			),
			'display_name'    => array(
				'label'      => __( 'Display name', 'user-management-suite' ),
				'group'      => self::GROUP_CORE,
				'default'    => true,
				'importable' => true,
			),
			'user_url'        => array(
				'label'      => __( 'Website URL', 'user-management-suite' ),
				'group'      => self::GROUP_CORE,
				'default'    => false,
				'importable' => true,
			),
			'user_nicename'   => array(
				'label'      => __( 'Nicename / slug', 'user-management-suite' ),
				'group'      => self::GROUP_CORE,
				'default'    => false,
				'importable' => true,
			),
			'roles'           => array(
				'label'      => __( 'Roles', 'user-management-suite' ),
				'group'      => self::GROUP_CORE,
				'default'    => true,
				'importable' => true,
			),
		);
	}

	/**
	 * Standard WordPress usermeta columns.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function meta_columns() {
		// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- 'meta_key' is a PHP array key, not a DB query parameter.
		return array(
			'first_name'  => array(
				'label'      => __( 'First name', 'user-management-suite' ),
				'group'      => self::GROUP_META,
				'default'    => true,
				'importable' => true,
				'meta_key'   => 'first_name',
			),
			'last_name'   => array(
				'label'      => __( 'Last name', 'user-management-suite' ),
				'group'      => self::GROUP_META,
				'default'    => true,
				'importable' => true,
				'meta_key'   => 'last_name',
			),
			'nickname'    => array(
				'label'      => __( 'Nickname', 'user-management-suite' ),
				'group'      => self::GROUP_META,
				'default'    => false,
				'importable' => true,
				'meta_key'   => 'nickname',
			),
			'description' => array(
				'label'      => __( 'Biographical info', 'user-management-suite' ),
				'group'      => self::GROUP_META,
				'default'    => false,
				'importable' => true,
				'meta_key'   => 'description',
			),
			'locale'      => array(
				'label'      => __( 'Language', 'user-management-suite' ),
				'group'      => self::GROUP_META,
				'default'    => false,
				'importable' => true,
				'meta_key'   => 'locale',
			),
		);
		// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_key
	}

	/**
	 * UMS-specific tracking meta columns.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function ums_columns() {
		// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- 'meta_key' is a PHP array key, not a DB query parameter.
		return array(
			'ums_registration_source' => array(
				'label'      => __( 'Registration source', 'user-management-suite' ),
				'group'      => self::GROUP_UMS,
				'default'    => true,
				'importable' => true,
				'meta_key'   => '_ums_registration_source',
			),
			'ums_registration_url'    => array(
				'label'      => __( 'Registration URL', 'user-management-suite' ),
				'group'      => self::GROUP_UMS,
				'default'    => true,
				'importable' => true,
				'meta_key'   => '_ums_registration_url',
			),
			'ums_last_login'          => array(
				'label'      => __( 'Last login', 'user-management-suite' ),
				'group'      => self::GROUP_UMS,
				'default'    => true,
				'importable' => false,
				'meta_key'   => '_ums_last_login',
			),
			'ums_last_login_ip'       => array(
				'label'      => __( 'Last login IP', 'user-management-suite' ),
				'group'      => self::GROUP_UMS,
				'default'    => false,
				'importable' => false,
				'meta_key'   => '_ums_last_login_ip',
			),
			'ums_activation_status'   => array(
				'label'      => __( 'Verification status', 'user-management-suite' ),
				'group'      => self::GROUP_UMS,
				'default'    => false,
				'importable' => true,
				'meta_key'   => '_ums_activation_status',
			),
		);
		// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_key
	}

	/**
	 * WooCommerce customer columns (billing, shipping, order stats).
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function woo_columns() {
		// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- 'meta_key' is a PHP array key, not a DB query parameter.
		$billing = array(
			'billing_first_name' => array(
				'label'      => __( 'Billing first name', 'user-management-suite' ),
				'default'    => true,
				'importable' => true,
				'meta_key'   => 'billing_first_name',
			),
			'billing_last_name'  => array(
				'label'      => __( 'Billing last name', 'user-management-suite' ),
				'default'    => true,
				'importable' => true,
				'meta_key'   => 'billing_last_name',
			),
			'billing_company'    => array(
				'label'      => __( 'Billing company', 'user-management-suite' ),
				'default'    => false,
				'importable' => true,
				'meta_key'   => 'billing_company',
			),
			'billing_email'      => array(
				'label'      => __( 'Billing email', 'user-management-suite' ),
				'default'    => false,
				'importable' => true,
				'meta_key'   => 'billing_email',
			),
			'billing_phone'      => array(
				'label'      => __( 'Billing phone', 'user-management-suite' ),
				'default'    => true,
				'importable' => true,
				'meta_key'   => 'billing_phone',
			),
			'billing_address_1'  => array(
				'label'      => __( 'Billing address 1', 'user-management-suite' ),
				'default'    => true,
				'importable' => true,
				'meta_key'   => 'billing_address_1',
			),
			'billing_address_2'  => array(
				'label'      => __( 'Billing address 2', 'user-management-suite' ),
				'default'    => false,
				'importable' => true,
				'meta_key'   => 'billing_address_2',
			),
			'billing_city'       => array(
				'label'      => __( 'Billing city', 'user-management-suite' ),
				'default'    => true,
				'importable' => true,
				'meta_key'   => 'billing_city',
			),
			'billing_state'      => array(
				'label'      => __( 'Billing state', 'user-management-suite' ),
				'default'    => true,
				'importable' => true,
				'meta_key'   => 'billing_state',
			),
			'billing_postcode'   => array(
				'label'      => __( 'Billing postcode', 'user-management-suite' ),
				'default'    => true,
				'importable' => true,
				'meta_key'   => 'billing_postcode',
			),
			'billing_country'    => array(
				'label'      => __( 'Billing country', 'user-management-suite' ),
				'default'    => true,
				'importable' => true,
				'meta_key'   => 'billing_country',
			),
		);

		$shipping = array(
			'shipping_first_name' => array(
				'label'      => __( 'Shipping first name', 'user-management-suite' ),
				'default'    => false,
				'importable' => true,
				'meta_key'   => 'shipping_first_name',
			),
			'shipping_last_name'  => array(
				'label'      => __( 'Shipping last name', 'user-management-suite' ),
				'default'    => false,
				'importable' => true,
				'meta_key'   => 'shipping_last_name',
			),
			'shipping_company'    => array(
				'label'      => __( 'Shipping company', 'user-management-suite' ),
				'default'    => false,
				'importable' => true,
				'meta_key'   => 'shipping_company',
			),
			'shipping_phone'      => array(
				'label'      => __( 'Shipping phone', 'user-management-suite' ),
				'default'    => false,
				'importable' => true,
				'meta_key'   => 'shipping_phone',
			),
			'shipping_address_1'  => array(
				'label'      => __( 'Shipping address 1', 'user-management-suite' ),
				'default'    => false,
				'importable' => true,
				'meta_key'   => 'shipping_address_1',
			),
			'shipping_address_2'  => array(
				'label'      => __( 'Shipping address 2', 'user-management-suite' ),
				'default'    => false,
				'importable' => true,
				'meta_key'   => 'shipping_address_2',
			),
			'shipping_city'       => array(
				'label'      => __( 'Shipping city', 'user-management-suite' ),
				'default'    => false,
				'importable' => true,
				'meta_key'   => 'shipping_city',
			),
			'shipping_state'      => array(
				'label'      => __( 'Shipping state', 'user-management-suite' ),
				'default'    => false,
				'importable' => true,
				'meta_key'   => 'shipping_state',
			),
			'shipping_postcode'   => array(
				'label'      => __( 'Shipping postcode', 'user-management-suite' ),
				'default'    => false,
				'importable' => true,
				'meta_key'   => 'shipping_postcode',
			),
			'shipping_country'    => array(
				'label'      => __( 'Shipping country', 'user-management-suite' ),
				'default'    => false,
				'importable' => true,
				'meta_key'   => 'shipping_country',
			),
		);

		$stats = array(
			'woo_total_spent' => array(
				'label'      => __( 'Total spent', 'user-management-suite' ),
				'default'    => false,
				'importable' => false,
			),
			'woo_order_count' => array(
				'label'      => __( 'Order count', 'user-management-suite' ),
				'default'    => false,
				'importable' => false,
			),
			'woo_avg_order'   => array(
				'label'      => __( 'Average order value', 'user-management-suite' ),
				'default'    => false,
				'importable' => false,
			),
		);

		// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_key

		$cols = array_merge( $billing, $shipping, $stats );

		foreach ( $cols as $key => $col ) {
			$cols[ $key ]['group'] = self::GROUP_WOO;
		}

		return $cols;
	}

	/**
	 * Whether WooCommerce is active and its classes are available.
	 *
	 * @return bool
	 */
	public static function woocommerce_active() {
		return class_exists( 'WooCommerce' );
	}
}
