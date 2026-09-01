<?php
/**
 * Settings repository: a thin accessor over the single ums_settings option.
 *
 * @package UserManagementSuite
 */

namespace Marinski\UserManagementSuite\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the plugin's single option array, applying defaults.
 */
class SettingsRepository {

	const OPTION_KEY = 'ums_settings';

	/**
	 * Cached settings array.
	 *
	 * @var array<string,mixed>|null
	 */
	private $cache = null;

	/**
	 * Default settings, grouped by section.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			// Which modules are active.
			'modules'       => array(
				'registration'  => true,
				'verification'  => false,
				'roles'         => true,
				'switching'     => true,
				'import_export' => true,
				// The reporting and preference modules add tables, a cron job and
				// front-end capture, so they stay off until deliberately enabled.
				'attribution'   => false,
				'insights'      => false,
				'notifications' => false,
			),
			'general'       => array(
				'delete_data_on_uninstall' => false,
			),
			'registration'  => array(
				'track_source'          => true,
				'track_last_login'      => true,
				'track_ip'              => true,
				'track_registration_ip' => true,
				'anonymize_ip'          => false,
				'show_columns'          => true,
			),
			'verification'  => array(
				'require_email_verification' => true,
				'auto_login_after_verify'    => true,
				'redirect_page_id'           => 0,
				'exclude_roles'              => array( 'administrator' ),
				'mail_from_name'             => '',
				'mail_from_email'            => '',
				'email_subject'              => '',
				'email_body'                 => '',
				// Spam protection.
				'blocked_domains'            => array(),
				'allowed_domains'            => array(),
				'blocked_usernames'          => array(),
				'blocked_keywords'           => array(),
				'blocked_ips'                => array(),
				'block_generic_email'        => false,
				// reCAPTCHA.
				'recaptcha_version'          => '', // phpcs:ignore Squiz.PHP.CommentedOutCode.Found -- Valid values: empty, v2, v2_invisible, v3.
				'recaptcha_site_key'         => '',
				'recaptcha_secret_key'       => '',
				'recaptcha_on_login'         => false,
				'recaptcha_on_register'      => true,
				'recaptcha_on_lostpassword'  => false,
				// Auto-delete unverified (0 = disabled).
				'auto_delete_days'           => 0,
			),
			'roles'         => array(
				'assignable_roles' => array(), // Empty = all roles allowed.
			),
			'switching'     => array(
				'enabled_for_roles' => array( 'administrator' ),
			),
			'import_export' => array(
				'export_batch_size' => 200,
				'import_batch_size' => 50,
			),
			'attribution'   => array(
				'capture_enabled' => true,
				'require_consent' => false,
				'cookie_days'     => 30,
				'channel_map'     => '',
				'show_columns'    => true,
			),
			'insights'      => array(
				'nightly_rebuild' => true,
				'rebuild_days'    => 7,
			),
			'notifications' => array(
				'enable_woocommerce' => true,
				'enable_core'        => true,
				'log_sends'          => true,
				'log_retention_days' => 90,
				'preferences_page'   => 0,
			),
		);
	}

	/**
	 * Get the full (defaults-merged) settings array.
	 *
	 * @return array<string,mixed>
	 */
	public function all() {
		if ( null === $this->cache ) {
			$stored      = get_option( self::OPTION_KEY, array() );
			$stored      = is_array( $stored ) ? $stored : array();
			$this->cache = $this->merge_defaults( self::defaults(), $stored );
		}

		return $this->cache;
	}

	/**
	 * Get a section, or a single key within a section.
	 *
	 * @param string      $section Section name.
	 * @param string|null $key     Optional key within the section.
	 * @param mixed       $default Default if missing.
	 * @return mixed
	 */
	public function get( $section, $key = null, $default = null ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.defaultFound -- $default is the conventional name here.
		$all = $this->all();

		if ( ! isset( $all[ $section ] ) ) {
			return $default;
		}

		if ( null === $key ) {
			return $all[ $section ];
		}

		return array_key_exists( $key, $all[ $section ] ) ? $all[ $section ][ $key ] : $default;
	}

	/**
	 * Persist the full settings array and refresh the cache.
	 *
	 * @param array<string,mixed> $settings Settings to save.
	 * @return void
	 */
	public function save( array $settings ) {
		$merged = $this->merge_defaults( self::defaults(), $settings );
		update_option( self::OPTION_KEY, $merged );
		$this->cache = $merged;
	}

	/**
	 * Whether a module is enabled.
	 *
	 * @param string $module_id Module id.
	 * @return bool
	 */
	public function is_module_enabled( $module_id ) {
		return (bool) $this->get( 'modules', $module_id, false );
	}

	/**
	 * Recursively merge stored values onto defaults (defaults define the shape).
	 *
	 * @param array<string,mixed> $defaults Defaults.
	 * @param array<string,mixed> $stored   Stored values.
	 * @return array<string,mixed>
	 */
	private function merge_defaults( array $defaults, array $stored ) {
		$out = $defaults;

		foreach ( $stored as $key => $value ) {
			if ( isset( $defaults[ $key ] ) && is_array( $defaults[ $key ] ) && is_array( $value ) ) {
				// Associative default => merge recursively; list default => replace.
				if ( $this->is_assoc( $defaults[ $key ] ) ) {
					$out[ $key ] = $this->merge_defaults( $defaults[ $key ], $value );
				} else {
					$out[ $key ] = $value;
				}
			} else {
				$out[ $key ] = $value;
			}
		}

		return $out;
	}

	/**
	 * Whether an array is associative (has non-integer keys).
	 *
	 * @param array<mixed> $arr Array.
	 * @return bool
	 */
	private function is_assoc( array $arr ) {
		if ( array() === $arr ) {
			return false;
		}

		return array_keys( $arr ) !== range( 0, count( $arr ) - 1 );
	}
}
