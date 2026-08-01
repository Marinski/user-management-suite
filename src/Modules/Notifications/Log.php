<?php
/**
 * Record of what was sent to whom.
 *
 * @package UserManagementSuite
 */

namespace Marinski\UserManagementSuite\Modules\Notifications;

use Marinski\UserManagementSuite\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Writes and reads the notification send log.
 *
 * "Did we actually email this customer, and when" is a routine support question
 * that a stock WordPress site cannot answer at all. The log is deliberately
 * narrow — who, what, when, did it leave — and holds no message content, so it
 * does not become a second copy of everything the site has ever sent.
 */
class Log {

	const CRON_HOOK = 'ums_notification_log_cleanup';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'woocommerce_email_sent', array( $this, 'record_woocommerce' ), 10, 3 );
		add_action( self::CRON_HOOK, array( $this, 'purge' ) );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Log a WooCommerce email once it has been handed to the mailer.
	 *
	 * @param bool   $sent     Whether wp_mail() reported success.
	 * @param string $email_id WooCommerce email id.
	 * @param mixed  $email    The WC_Email instance.
	 * @return void
	 */
	public function record_woocommerce( $sent, $email_id, $email = null ) {
		$object    = is_object( $email ) && isset( $email->object ) ? $email->object : null;
		$user_id   = Adapters\WooCommerceAdapter::resolve_user_id( $object );
		$recipient = is_object( $email ) && method_exists( $email, 'get_recipient' ) ? (string) $email->get_recipient() : '';

		self::record(
			$user_id,
			$recipient,
			Adapters\WooCommerceAdapter::PREFIX . $email_id,
			'email',
			$sent ? 'sent' : 'failed'
		);
	}

	/**
	 * Write one log row.
	 *
	 * @param int    $user_id   User id, or 0 for a guest.
	 * @param string $recipient Recipient address.
	 * @param string $type      Notification type id.
	 * @param string $channel   Delivery channel.
	 * @param string $status    sent|failed|suppressed.
	 * @return void
	 */
	public static function record( $user_id, $recipient, $type, $channel = 'email', $status = 'sent' ) {
		if ( ! self::enabled() ) {
			return;
		}

		global $wpdb;

		// Multiple recipients arrive comma-separated; the first is the customer.
		if ( false !== strpos( $recipient, ',' ) ) {
			$parts     = explode( ',', $recipient );
			$recipient = trim( (string) reset( $parts ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			Schema::log_table(),
			array(
				'user_id'   => max( 0, (int) $user_id ),
				'recipient' => substr( sanitize_text_field( $recipient ), 0, 191 ),
				'type'      => substr( sanitize_text_field( $type ), 0, 100 ),
				'channel'   => substr( sanitize_key( $channel ), 0, 20 ),
				'status'    => substr( sanitize_key( $status ), 0, 20 ),
				'sent_at'   => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Whether logging is switched on.
	 *
	 * @return bool
	 */
	public static function enabled() {
		$settings = get_option( 'ums_settings', array() );

		if ( ! is_array( $settings ) ) {
			return false;
		}

		if ( empty( $settings['modules']['notifications'] ) ) {
			return false;
		}

		return ! isset( $settings['notifications']['log_sends'] ) || (bool) $settings['notifications']['log_sends'];
	}

	/**
	 * Recent entries for one user.
	 *
	 * @param int $user_id User id.
	 * @param int $limit   Maximum rows.
	 * @return array<int,object>
	 */
	public static function for_user( $user_id, $limit = 20 ) {
		global $wpdb;

		$table = Schema::log_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT type, channel, status, sent_at FROM {$table}
				 WHERE user_id = %d ORDER BY sent_at DESC LIMIT %d",
				(int) $user_id,
				max( 1, min( 200, (int) $limit ) )
			)
		);
	}

	/**
	 * Send counts per type over a date range.
	 *
	 * @param string $from Start date (Y-m-d).
	 * @param string $to   End date (Y-m-d).
	 * @return array<string,int>
	 */
	public static function totals_by_type( $from, $to ) {
		global $wpdb;

		$table = Schema::log_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT type, COUNT(*) AS hits FROM {$table}
				 WHERE sent_at >= %s AND sent_at <= %s
				 GROUP BY type ORDER BY hits DESC",
				$from . ' 00:00:00',
				$to . ' 23:59:59'
			)
		);

		$out = array();

		foreach ( (array) $rows as $row ) {
			$out[ (string) $row->type ] = (int) $row->hits;
		}

		return $out;
	}

	/**
	 * Total rows in the log.
	 *
	 * @return int
	 */
	public static function count() {
		global $wpdb;

		$table = Schema::log_table();

		if ( ! Schema::table_exists( $table ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Delete entries past the retention window.
	 *
	 * @return int Rows removed.
	 */
	public function purge() {
		global $wpdb;

		$settings = get_option( 'ums_settings', array() );
		$days     = is_array( $settings ) && isset( $settings['notifications']['log_retention_days'] )
			? (int) $settings['notifications']['log_retention_days']
			: 90;

		// Zero means keep everything, which is a legitimate choice for sites that
		// need a long audit trail.
		if ( $days <= 0 ) {
			return 0;
		}

		$table  = Schema::log_table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE sent_at < %s", $cutoff ) );
	}
}
