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

// Remove the plugin option.
delete_option( 'ums_settings' );

// Remove plugin user meta (own _ums_ keys only).
$ums_meta_keys = array(
	'_ums_registration_url',
	'_ums_registration_source',
	'_ums_last_login',
	'_ums_last_login_ip',
	'_ums_activation_key',
	'_ums_activation_status',
);

foreach ( $ums_meta_keys as $ums_key ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- One-time uninstall cleanup.
	$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => $ums_key ) );
}
