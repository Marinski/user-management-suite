<?php
/**
 * Declares WooCommerce's customer emails as notification types.
 *
 * @package UserManagementSuite
 */

namespace Marinski\UserManagementSuite\Modules\Notifications\Adapters;

use Marinski\UserManagementSuite\Modules\Notifications\Preferences;
use Marinski\UserManagementSuite\Modules\Notifications\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Bridges WooCommerce's mailer into the preference registry.
 *
 * All WooCommerce knowledge in this plugin lives here. The file no-ops entirely
 * without WooCommerce, which is what lets the module ship in a plugin that has
 * to work on any WordPress site.
 */
class WooCommerceAdapter {

	/** Prefix so WooCommerce ids cannot collide with another adapter's. */
	const PREFIX = 'wc_';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		if ( ! self::available() ) {
			return;
		}

		add_filter( 'ums_notification_types', array( $this, 'declare_types' ) );

		// Gating must be attached late enough that WooCommerce has built its
		// mailer, but before any email is actually sent.
		add_action( 'woocommerce_email_init', array( $this, 'attach_gates' ) );
	}

	/**
	 * Whether WooCommerce is present and usable.
	 *
	 * @return bool
	 */
	public static function available() {
		return function_exists( 'WC' ) && class_exists( 'WC_Emails' );
	}

	/**
	 * The WooCommerce mailer's customer-facing emails.
	 *
	 * @return array<string,\WC_Email>
	 */
	private static function customer_emails() {
		static $cache = null;

		if ( null !== $cache ) {
			return $cache;
		}

		$cache = array();

		if ( ! self::available() ) {
			return $cache;
		}

		$mailer = WC()->mailer();

		if ( ! $mailer ) {
			return $cache;
		}

		foreach ( $mailer->get_emails() as $email ) {
			if ( ! is_object( $email ) || ! method_exists( $email, 'is_customer_email' ) ) {
				continue;
			}

			// Admin notifications are not the recipient's preference to set.
			if ( ! $email->is_customer_email() || empty( $email->id ) ) {
				continue;
			}

			$cache[ (string) $email->id ] = $email;
		}

		return $cache;
	}

	/**
	 * WooCommerce email ids a user is allowed to switch off.
	 *
	 * Deliberately short. Order, payment and account mail is the record of a
	 * transaction the user entered into, and letting someone silence their own
	 * receipts creates support tickets, not satisfaction. The advance reminders
	 * are different: they are courtesy notices about something that has not
	 * happened yet.
	 *
	 * @return string[]
	 */
	public static function optional_ids() {
		$optional = array(
			'customer_notification_manual_trial_expiry',
			'customer_notification_auto_trial_expiry',
			'customer_notification_manual_renewal',
			'customer_notification_auto_renewal',
			'customer_notification_subscription_expiry',
		);

		/**
		 * Filters which WooCommerce emails a user may switch off.
		 *
		 * @param string[] $optional WooCommerce email ids.
		 */
		return (array) apply_filters( 'ums_notification_woocommerce_optional', $optional );
	}

	/**
	 * Declare every customer email as a notification type.
	 *
	 * @param array<int,array<string,mixed>> $types Existing declarations.
	 * @return array<int,array<string,mixed>>
	 */
	public function declare_types( $types ) {
		$optional = self::optional_ids();

		foreach ( self::customer_emails() as $id => $email ) {
			$is_optional = in_array( $id, $optional, true );

			$types[] = array(
				'id'                  => self::PREFIX . $id,
				'label'               => $email->get_title() ? $email->get_title() : $id,
				'description'         => method_exists( $email, 'get_description' ) ? (string) $email->get_description() : '',
				'group'               => __( 'Shop', 'user-management-suite' ),
				'category'            => $is_optional ? Registry::PRODUCT : Registry::TRANSACTIONAL,
				'channel'             => 'email',
				'default'             => true,
				'user_optout_allowed' => $is_optional,
			);
		}

		return $types;
	}

	/**
	 * Attach the per-recipient gate to the emails a user may switch off.
	 *
	 * Locked types get no filter at all: code that cannot say no is code that
	 * cannot wrongly say no.
	 *
	 * @return void
	 */
	public function attach_gates() {
		foreach ( self::optional_ids() as $id ) {
			add_filter(
				'woocommerce_email_enabled_' . $id,
				function ( $enabled, $object = null ) use ( $id ) {
					return $this->gate( $enabled, $object, $id );
				},
				20,
				2
			);
		}
	}

	/**
	 * Decide whether one WooCommerce email may go out.
	 *
	 * @param bool   $enabled Whether WooCommerce would send it.
	 * @param mixed  $object  The order, subscription or user being mailed.
	 * @param string $id      WooCommerce email id.
	 * @return bool
	 */
	private function gate( $enabled, $object, $id ) {
		// Never turn a disabled email back on.
		if ( ! $enabled ) {
			return false;
		}

		$user_id = self::resolve_user_id( $object );

		// No identifiable recipient means no preference to honour, so send.
		if ( $user_id <= 0 ) {
			return true;
		}

		return Preferences::allowed( self::PREFIX . $id, $user_id );
	}

	/**
	 * Work out which WordPress user an email object is addressed to.
	 *
	 * @param mixed $object Order, subscription, user or similar.
	 * @return int User id, or 0 when it cannot be determined.
	 */
	public static function resolve_user_id( $object ) {
		if ( ! is_object( $object ) ) {
			return 0;
		}

		// WC_Order and WC_Subscription both answer this.
		if ( method_exists( $object, 'get_customer_id' ) ) {
			$customer_id = (int) $object->get_customer_id();

			if ( $customer_id > 0 ) {
				return $customer_id;
			}
		}

		if ( $object instanceof \WP_User ) {
			return (int) $object->ID;
		}

		if ( isset( $object->ID ) && is_numeric( $object->ID ) ) {
			return (int) $object->ID;
		}

		// Guest checkout: fall back to matching the billing address to an account.
		$email = '';

		if ( method_exists( $object, 'get_billing_email' ) ) {
			$email = (string) $object->get_billing_email();
		} elseif ( isset( $object->user_email ) ) {
			$email = (string) $object->user_email;
		}

		if ( '' === $email || ! is_email( $email ) ) {
			return 0;
		}

		$user = get_user_by( 'email', $email );

		return $user ? (int) $user->ID : 0;
	}
}
