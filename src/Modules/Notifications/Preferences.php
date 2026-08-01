<?php
/**
 * Per-user notification preferences.
 *
 * @package UserManagementSuite
 */

namespace Marinski\UserManagementSuite\Modules\Notifications;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes what each user has chosen to receive.
 *
 * Only explicit choices are stored. A type the user has never touched falls
 * back to its declared default, so adding a new notification type does not
 * silently mark every existing user as opted out of it.
 */
class Preferences {

	/** All choices live in one meta row, not one row per type. */
	const META = '_ums_notify_prefs';

	/** Set when a user opts out of everything optional at once. */
	const META_UNSUBSCRIBED_ALL = '_ums_notify_unsubscribed_all';

	/**
	 * Explicit choices for a user.
	 *
	 * @param int $user_id User id.
	 * @return array<string,bool>
	 */
	public static function stored( $user_id ) {
		$stored = get_user_meta( (int) $user_id, self::META, true );

		if ( ! is_array( $stored ) ) {
			return array();
		}

		$out = array();

		foreach ( $stored as $id => $value ) {
			$out[ sanitize_key( (string) $id ) ] = (bool) $value;
		}

		return $out;
	}

	/**
	 * Resolve every known type for a user: id => bool.
	 *
	 * @param int $user_id User id.
	 * @return array<string,bool>
	 */
	public static function resolved( $user_id ) {
		$stored = self::stored( $user_id );
		$out    = array();

		foreach ( Registry::types() as $id => $type ) {
			$out[ $id ] = self::allowed( $id, $user_id, $stored );
		}

		return $out;
	}

	/**
	 * Whether a notification may be sent to a user.
	 *
	 * Fails open at every uncertain step. A wrongly delivered newsletter is an
	 * annoyance; a suppressed order confirmation is a support ticket and a lost
	 * customer, so anything unknown resolves to "send".
	 *
	 * @param string                  $type_id Type id.
	 * @param int                     $user_id User id.
	 * @param array<string,bool>|null $stored  Pre-fetched choices, to avoid a repeat read.
	 * @return bool
	 */
	public static function allowed( $type_id, $user_id, $stored = null ) {
		$type_id = sanitize_key( (string) $type_id );
		$user_id = (int) $user_id;

		$type = Registry::get( $type_id );

		// An unregistered type is not ours to suppress.
		if ( null === $type ) {
			return self::filter( true, $type_id, $user_id );
		}

		// Locked types ignore stored preferences entirely, so a stale or hand-edited
		// meta row can never switch off a receipt.
		if ( ! $type['user_optout_allowed'] ) {
			return self::filter( true, $type_id, $user_id );
		}

		if ( $user_id <= 0 ) {
			return self::filter( (bool) $type['default'], $type_id, $user_id );
		}

		if ( null === $stored ) {
			$stored = self::stored( $user_id );
		}

		if ( array_key_exists( $type_id, $stored ) ) {
			return self::filter( (bool) $stored[ $type_id ], $type_id, $user_id );
		}

		return self::filter( (bool) $type['default'], $type_id, $user_id );
	}

	/**
	 * Apply the final filter to a resolved decision.
	 *
	 * @param bool   $allowed Decision.
	 * @param string $type_id Type id.
	 * @param int    $user_id User id.
	 * @return bool
	 */
	private static function filter( $allowed, $type_id, $user_id ) {
		/**
		 * Filters whether a notification may be sent to a user.
		 *
		 * @param bool   $allowed Whether sending is allowed.
		 * @param string $type_id Notification type id.
		 * @param int    $user_id User id.
		 */
		return (bool) apply_filters( 'ums_notification_allowed', $allowed, $type_id, $user_id );
	}

	/**
	 * Save a set of choices, ignoring anything the user may not change.
	 *
	 * @param int                $user_id User id.
	 * @param array<string,bool> $prefs   Submitted choices.
	 * @return void
	 */
	public static function save( $user_id, array $prefs ) {
		$user_id = (int) $user_id;

		if ( $user_id <= 0 ) {
			return;
		}

		$clean = array();

		foreach ( $prefs as $id => $value ) {
			$id = sanitize_key( (string) $id );

			// Silently drop unknown or locked types rather than storing a choice
			// that the resolver would then ignore.
			if ( ! Registry::is_optional( $id ) ) {
				continue;
			}

			$clean[ $id ] = (bool) $value;
		}

		if ( array() === $clean ) {
			delete_user_meta( $user_id, self::META );
		} else {
			update_user_meta( $user_id, self::META, $clean );
		}

		// Any deliberate choice clears the blanket opt-out flag.
		delete_user_meta( $user_id, self::META_UNSUBSCRIBED_ALL );

		/**
		 * Fires after a user's notification preferences are saved.
		 *
		 * @param int                $user_id User id.
		 * @param array<string,bool> $clean   Stored choices.
		 */
		do_action( 'ums_notification_preferences_saved', $user_id, $clean );
	}

	/**
	 * Switch off everything the user is permitted to switch off.
	 *
	 * @param int    $user_id  User id.
	 * @param string $category Limit to one category, or '' for all optional types.
	 * @return int Number of types switched off.
	 */
	public static function opt_out_all( $user_id, $category = '' ) {
		$user_id = (int) $user_id;

		if ( $user_id <= 0 ) {
			return 0;
		}

		$stored = self::stored( $user_id );
		$count  = 0;

		foreach ( Registry::types() as $id => $type ) {
			if ( ! $type['user_optout_allowed'] ) {
				continue;
			}

			if ( '' !== $category && $type['category'] !== $category ) {
				continue;
			}

			$stored[ $id ] = false;
			++$count;
		}

		update_user_meta( $user_id, self::META, $stored );
		update_user_meta( $user_id, self::META_UNSUBSCRIBED_ALL, time() );

		/**
		 * Fires when a user opts out in bulk, typically via an unsubscribe link.
		 *
		 * @param int    $user_id  User id.
		 * @param string $category Category limited to, or '' for everything optional.
		 */
		do_action( 'ums_notification_unsubscribed_all', $user_id, $category );

		return $count;
	}

	/**
	 * Summary counts for admin display.
	 *
	 * @param int $user_id User id.
	 * @return array{on:int,off:int,locked:int}
	 */
	public static function summary( $user_id ) {
		$out = array(
			'on'     => 0,
			'off'    => 0,
			'locked' => 0,
		);

		$stored = self::stored( $user_id );

		foreach ( Registry::types() as $id => $type ) {
			if ( ! $type['user_optout_allowed'] ) {
				++$out['locked'];
				continue;
			}

			if ( self::allowed( $id, $user_id, $stored ) ) {
				++$out['on'];
			} else {
				++$out['off'];
			}
		}

		return $out;
	}
}
