<?php
/**
 * Declares WordPress's own user notifications.
 *
 * @package UserManagementSuite
 */

namespace Marinski\UserManagementSuite\Modules\Notifications\Adapters;

use Marinski\UserManagementSuite\Modules\Notifications\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the mail WordPress core sends a user.
 *
 * None of it is optional — it is all account security and access. They are
 * declared anyway because the preference screen is also an answer to "what do
 * you send me", and a list that quietly omits some mail is a worse answer than
 * one that shows it and marks it required.
 */
class CoreAdapter {

	const PREFIX = 'core_';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'ums_notification_types', array( $this, 'declare_types' ) );
	}

	/**
	 * Declare the core notification types.
	 *
	 * @param array<int,array<string,mixed>> $types Existing declarations.
	 * @return array<int,array<string,mixed>>
	 */
	public function declare_types( $types ) {
		$core = array(
			array(
				'id'          => self::PREFIX . 'new_account',
				'label'       => __( 'Welcome and account details', 'user-management-suite' ),
				'description' => __( 'Sent once when the account is created.', 'user-management-suite' ),
			),
			array(
				'id'          => self::PREFIX . 'password_reset',
				'label'       => __( 'Password reset link', 'user-management-suite' ),
				'description' => __( 'Sent when a password reset is requested.', 'user-management-suite' ),
			),
			array(
				'id'          => self::PREFIX . 'password_changed',
				'label'       => __( 'Password changed confirmation', 'user-management-suite' ),
				'description' => __( 'Security notice sent after the password changes.', 'user-management-suite' ),
			),
			array(
				'id'          => self::PREFIX . 'email_changed',
				'label'       => __( 'Email address changed confirmation', 'user-management-suite' ),
				'description' => __( 'Security notice sent to the previous address.', 'user-management-suite' ),
			),
		);

		foreach ( $core as $type ) {
			$types[] = array_merge(
				$type,
				array(
					'group'               => __( 'Account', 'user-management-suite' ),
					'category'            => Registry::TRANSACTIONAL,
					'channel'             => 'email',
					'default'             => true,
					'user_optout_allowed' => false,
				)
			);
		}

		return $types;
	}
}
