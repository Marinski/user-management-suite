<?php
/**
 * Custom table definitions and upgrades.
 *
 * @package UserManagementSuite
 */

namespace Marinski\UserManagementSuite\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the plugin's two custom tables.
 *
 * Both are additive and empty until the module that fills them is enabled, so
 * creating them costs nothing on sites that never turn those modules on.
 */
class Schema {

	/**
	 * Bumped whenever a CREATE TABLE statement below changes.
	 */
	const VERSION = 1;

	/**
	 * Option holding the installed schema version.
	 */
	const VERSION_OPTION = 'ums_schema_version';

	/**
	 * Fully-qualified name of the daily rollup table.
	 *
	 * @return string
	 */
	public static function stats_table() {
		global $wpdb;

		return $wpdb->prefix . 'ums_stats_daily';
	}

	/**
	 * Fully-qualified name of the notification send log.
	 *
	 * @return string
	 */
	public static function log_table() {
		global $wpdb;

		return $wpdb->prefix . 'ums_notification_log';
	}

	/**
	 * Create or update the tables if the stored version is behind.
	 *
	 * Cheap enough to call on every request: it reads one autoloaded option and
	 * returns immediately in the common case.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( (int) get_option( self::VERSION_OPTION, 0 ) === self::VERSION ) {
			return;
		}

		self::install();
	}

	/**
	 * Run dbDelta for every table and record the version.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$stats           = self::stats_table();
		$log             = self::log_table();

		/*
		 * Rollup table. Dashboards read only from here — never from wp_users or
		 * wp_usermeta directly — because those are 154k and ~2M rows on a large
		 * install and a live aggregate query would be unusable.
		 *
		 * One row per (date, metric, dimension, value). `dim_value` is capped at
		 * 191 chars so the unique key fits in an InnoDB index under utf8mb4.
		 */
		$sql_stats = "CREATE TABLE {$stats} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			stat_date date NOT NULL,
			metric varchar(32) NOT NULL,
			dimension varchar(32) NOT NULL,
			dim_value varchar(191) NOT NULL DEFAULT '',
			hits bigint(20) unsigned NOT NULL DEFAULT 0,
			amount decimal(18,4) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY ums_stat_slot (stat_date,metric,dimension,dim_value),
			KEY ums_metric_date (metric,stat_date),
			KEY ums_dimension (dimension,dim_value)
		) {$charset_collate};";

		/*
		 * Notification send log. Answers "what did we send this person, when, and
		 * did it go out" — currently unanswerable on any WordPress install.
		 */
		$sql_log = "CREATE TABLE {$log} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			recipient varchar(191) NOT NULL DEFAULT '',
			type varchar(100) NOT NULL DEFAULT '',
			channel varchar(20) NOT NULL DEFAULT 'email',
			status varchar(20) NOT NULL DEFAULT 'sent',
			sent_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY ums_log_user (user_id,sent_at),
			KEY ums_log_type (type,sent_at),
			KEY ums_log_sent (sent_at)
		) {$charset_collate};";

		dbDelta( $sql_stats );
		dbDelta( $sql_log );

		// Autoloaded on purpose: maybe_upgrade() reads it on every request, and an
		// unautoloaded option would turn that check into a query each time.
		update_option( self::VERSION_OPTION, self::VERSION, true );
	}

	/**
	 * Whether a given table physically exists.
	 *
	 * @param string $table Table name.
	 * @return bool
	 */
	public static function table_exists( $table ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return $found === $table;
	}

	/**
	 * Drop both tables. Only called from uninstall when the user opted in.
	 *
	 * @return void
	 */
	public static function drop() {
		global $wpdb;

		foreach ( array( self::stats_table(), self::log_table() ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}

		delete_option( self::VERSION_OPTION );
	}
}
