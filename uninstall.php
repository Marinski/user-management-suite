<?php
/**
 * Uninstall handler. Only deletes data when the user opted in.
 *
 * @package UserManagementSuite
 */

// Exit if not called by WordPress during uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$ums_settings = get_option( 'ums_settings', array() );
$ums_delete   = is_array( $ums_settings )
	&& isset( $ums_settings['general']['delete_data_on_uninstall'] )
	&& $ums_settings['general']['delete_data_on_uninstall'];

if ( ! $ums_delete ) {
	return;
}

global $wpdb;

// Remove plugin options.
$ums_options = array(
	'ums_settings',
	'ums_schema_version',
	'ums_stats_last_build',
	'ums_attribution_backfill_state',
);

foreach ( $ums_options as $ums_option ) {
	delete_option( $ums_option );
}

// Remove plugin user meta (own _ums_ keys only).
$ums_meta_keys = array(
	'_ums_registration_url',
	'_ums_registration_source',
	'_ums_last_login',
	'_ums_last_login_ip',
	'_ums_activation_key',
	'_ums_activation_status',
	'_ums_attribution_first',
	'_ums_attribution_last',
	'_ums_attribution_channel',
	'_ums_notify_prefs',
	'_ums_notify_unsubscribed_all',
);

foreach ( $ums_meta_keys as $ums_key ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One-time uninstall cleanup.
	$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => $ums_key ) );
}

// Drop the plugin's own tables.
foreach ( array( 'ums_stats_daily', 'ums_notification_log' ) as $ums_table ) {
	$ums_full = $wpdb->prefix . $ums_table;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- One-time uninstall cleanup of a known table name.
	$wpdb->query( "DROP TABLE IF EXISTS {$ums_full}" );
}

// Clear scheduled jobs.
foreach ( array( 'ums_daily_rollup', 'ums_notification_log_cleanup' ) as $ums_hook ) {
	$ums_timestamp = wp_next_scheduled( $ums_hook );

	while ( $ums_timestamp ) {
		wp_unschedule_event( $ums_timestamp, $ums_hook );
		$ums_timestamp = wp_next_scheduled( $ums_hook );
	}
}
