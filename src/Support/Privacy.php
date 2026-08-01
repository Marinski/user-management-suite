<?php
/**
 * WordPress privacy tools integration.
 *
 * @package UserManagementSuite
 */

namespace Marinski\UserManagementSuite\Support;

use Marinski\UserManagementSuite\Modules\Attribution\Record;
use Marinski\UserManagementSuite\Modules\Notifications\Log;
use Marinski\UserManagementSuite\Modules\Notifications\Preferences;
use Marinski\UserManagementSuite\Modules\Notifications\Registry;
use Marinski\UserManagementSuite\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Registers personal data exporters, erasers, and policy content with WordPress
 * so that plugin-owned user meta participates in privacy request workflows.
 */
class Privacy {

	/**
	 * Settings repository.
	 *
	 * @var SettingsRepository
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param SettingsRepository $settings Settings repository.
	 */
	public function __construct( SettingsRepository $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_erasers' ) );
		add_action( 'admin_init', array( $this, 'suggest_policy_content' ) );
	}

	/**
	 * Add the plugin's personal data exporter.
	 *
	 * @param array<int,array<string,mixed>> $exporters Registered exporters.
	 * @return array<int,array<string,mixed>>
	 */
	public function register_exporters( $exporters ) {
		$exporters[] = array(
			'exporter_friendly_name' => __( 'User Management Suite', 'user-management-suite' ),
			'callback'               => array( $this, 'export_user_data' ),
		);

		return $exporters;
	}

	/**
	 * Add the plugin's personal data eraser.
	 *
	 * @param array<int,array<string,mixed>> $erasers Registered erasers.
	 * @return array<int,array<string,mixed>>
	 */
	public function register_erasers( $erasers ) {
		$erasers[] = array(
			'eraser_friendly_name' => __( 'User Management Suite', 'user-management-suite' ),
			'callback'             => array( $this, 'erase_user_data' ),
		);

		return $erasers;
	}

	/**
	 * Export plugin-owned personal data for a given email address.
	 *
	 * @param string $email Email address of the data subject.
	 * @param int    $page  Page number (for pagination; currently single-page).
	 * @return array{data: array<int,array<string,mixed>>, done: bool}
	 */
	public function export_user_data( $email, $page = 1 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WP exporter API requires $page
		$user = get_user_by( 'email', $email );

		if ( ! $user instanceof \WP_User ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$items = array();

		// Registration & activity data.
		if ( $this->settings->is_module_enabled( 'registration' ) ) {
			$group_id    = 'ums-registration';
			$group_label = __( 'Registration & Activity', 'user-management-suite' );

			$data = array();

			$reg_url = get_user_meta( $user->ID, '_ums_registration_url', true );
			if ( $reg_url ) {
				$data[] = array(
					'name'  => __( 'Registration URL', 'user-management-suite' ),
					'value' => esc_url( $reg_url ),
				);
			}

			$reg_source = get_user_meta( $user->ID, '_ums_registration_source', true );
			if ( $reg_source ) {
				$data[] = array(
					'name'  => __( 'Registration source', 'user-management-suite' ),
					'value' => esc_html( $reg_source ),
				);
			}

			$last_login = (int) get_user_meta( $user->ID, '_ums_last_login', true );
			if ( $last_login ) {
				$data[] = array(
					'name'  => __( 'Last login', 'user-management-suite' ),
					'value' => esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_login ) ),
				);
			}

			$last_ip = get_user_meta( $user->ID, '_ums_last_login_ip', true );
			if ( $last_ip ) {
				$data[] = array(
					'name'  => __( 'Last login IP', 'user-management-suite' ),
					'value' => esc_html( $last_ip ),
				);
			}

			if ( ! empty( $data ) ) {
				$items[] = array(
					'group_id'    => $group_id,
					'group_label' => $group_label,
					'item_id'     => 'ums-registration-' . $user->ID,
					'data'        => $data,
				);
			}
		}

		// Verification status.
		if ( $this->settings->is_module_enabled( 'verification' ) ) {
			$status = get_user_meta( $user->ID, '_ums_activation_status', true );
			if ( '' !== $status && false !== $status ) {
				$items[] = array(
					'group_id'    => 'ums-verification',
					'group_label' => __( 'Email Verification', 'user-management-suite' ),
					'item_id'     => 'ums-verification-' . $user->ID,
					'data'        => array(
						array(
							'name'  => __( 'Email verified', 'user-management-suite' ),
							'value' => '1' === (string) $status ? __( 'Yes', 'user-management-suite' ) : __( 'No', 'user-management-suite' ),
						),
					),
				);
			}
		}

		$items = array_merge(
			$items,
			$this->export_attribution( $user ),
			$this->export_notifications( $user )
		);

		return array(
			'data' => $items,
			'done' => true,
		);
	}

	/**
	 * Export acquisition data.
	 *
	 * @param \WP_User $user Data subject.
	 * @return array<int,array<string,mixed>>
	 */
	private function export_attribution( \WP_User $user ) {
		if ( ! $this->settings->is_module_enabled( 'attribution' ) ) {
			return array();
		}

		$labels = array(
			'source'   => __( 'Source', 'user-management-suite' ),
			'medium'   => __( 'Medium', 'user-management-suite' ),
			'campaign' => __( 'Campaign', 'user-management-suite' ),
			'content'  => __( 'Campaign content', 'user-management-suite' ),
			'term'     => __( 'Campaign term', 'user-management-suite' ),
			'referrer' => __( 'Referring page', 'user-management-suite' ),
			'landing'  => __( 'Landing page', 'user-management-suite' ),
			'device'   => __( 'Device type', 'user-management-suite' ),
			'click_id' => __( 'Advertising click id', 'user-management-suite' ),
			'channel'  => __( 'Channel', 'user-management-suite' ),
		);

		$items = array();

		$touches = array(
			Record::META_FIRST => __( 'First visit', 'user-management-suite' ),
			Record::META_LAST  => __( 'Most recent visit before signing up', 'user-management-suite' ),
		);

		foreach ( $touches as $meta_key => $group_label ) {
			$stored = get_user_meta( $user->ID, $meta_key, true );

			if ( ! is_array( $stored ) || array() === $stored ) {
				continue;
			}

			$data = array();

			foreach ( $labels as $field => $label ) {
				if ( empty( $stored[ $field ] ) ) {
					continue;
				}

				$data[] = array(
					'name'  => $label,
					'value' => esc_html( (string) $stored[ $field ] ),
				);
			}

			if ( ! empty( $stored['ts'] ) ) {
				$data[] = array(
					'name'  => __( 'Recorded', 'user-management-suite' ),
					'value' => esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $stored['ts'] ) ),
				);
			}

			if ( array() === $data ) {
				continue;
			}

			$items[] = array(
				'group_id'    => 'ums-acquisition',
				'group_label' => __( 'Acquisition', 'user-management-suite' ),
				'item_id'     => 'ums-acquisition-' . sanitize_key( $meta_key ) . '-' . $user->ID,
				'data'        => array_merge(
					array(
						array(
							'name'  => __( 'Touch', 'user-management-suite' ),
							'value' => esc_html( $group_label ),
						),
					),
					$data
				),
			);
		}

		return $items;
	}

	/**
	 * Export notification preferences and the send log.
	 *
	 * @param \WP_User $user Data subject.
	 * @return array<int,array<string,mixed>>
	 */
	private function export_notifications( \WP_User $user ) {
		if ( ! $this->settings->is_module_enabled( 'notifications' ) ) {
			return array();
		}

		$items = array();
		$types = Registry::types();
		$prefs = Preferences::stored( $user->ID );

		if ( array() !== $prefs ) {
			$data = array();

			foreach ( $prefs as $id => $enabled ) {
				$data[] = array(
					'name'  => isset( $types[ $id ] ) ? $types[ $id ]['label'] : $id,
					'value' => $enabled
						? __( 'Subscribed', 'user-management-suite' )
						: __( 'Unsubscribed', 'user-management-suite' ),
				);
			}

			$items[] = array(
				'group_id'    => 'ums-notifications',
				'group_label' => __( 'Notification preferences', 'user-management-suite' ),
				'item_id'     => 'ums-notifications-' . $user->ID,
				'data'        => $data,
			);
		}

		/*
		 * The send log records an address and a history of contact, which is
		 * personal data in its own right — exporting the preferences without it
		 * would answer only half the question a subject access request asks.
		 */
		foreach ( Log::for_user( $user->ID, 200 ) as $index => $entry ) {
			$stamp = strtotime( $entry->sent_at . ' UTC' );

			$items[] = array(
				'group_id'    => 'ums-notification-log',
				'group_label' => __( 'Notifications sent to you', 'user-management-suite' ),
				'item_id'     => 'ums-notification-log-' . $user->ID . '-' . $index,
				'data'        => array(
					array(
						'name'  => __( 'Notification', 'user-management-suite' ),
						'value' => esc_html( isset( $types[ $entry->type ] ) ? $types[ $entry->type ]['label'] : $entry->type ),
					),
					array(
						'name'  => __( 'Sent', 'user-management-suite' ),
						'value' => esc_html( $stamp ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $stamp ) : $entry->sent_at ),
					),
					array(
						'name'  => __( 'Result', 'user-management-suite' ),
						'value' => esc_html( $entry->status ),
					),
				),
			);
		}

		return $items;
	}

	/**
	 * Erase plugin-owned personal data for a given email address.
	 *
	 * @param string $email Email address of the data subject.
	 * @param int    $page  Page number (for pagination; currently single-page).
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public function erase_user_data( $email, $page = 1 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WP eraser API requires $page
		$user = get_user_by( 'email', $email );

		if ( ! $user instanceof \WP_User ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$removed = false;

		$erasable = array(
			'_ums_registration_url',
			'_ums_registration_source',
			'_ums_last_login',
			'_ums_last_login_ip',
			'_ums_activation_key',
			Record::META_FIRST,
			Record::META_LAST,
			Record::META_CHANNEL,
			Preferences::META_UNSUBSCRIBED_ALL,
		);

		foreach ( $erasable as $meta_key ) {
			$existing = get_user_meta( $user->ID, $meta_key, true );
			if ( '' !== $existing && false !== $existing && array() !== $existing ) {
				delete_user_meta( $user->ID, $meta_key );
				$removed = true;
			}
		}

		$messages = array();

		/*
		 * Preferences are retained on purpose. Erasing an opt-out would silently
		 * resubscribe someone who asked not to be contacted, which is the opposite
		 * of what the request was for.
		 */
		$prefs    = Preferences::stored( $user->ID );
		$retained = false;

		foreach ( $prefs as $enabled ) {
			if ( ! $enabled ) {
				$retained   = true;
				$messages[] = __( 'Notification opt-outs have been kept so that this person is not contacted again. Deleting them would undo their unsubscribe.', 'user-management-suite' );
				break;
			}
		}

		if ( ! $retained && array() !== $prefs ) {
			delete_user_meta( $user->ID, Preferences::META );
			$removed = true;
		}

		if ( $this->erase_notification_log( $user->ID ) ) {
			$removed = true;
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => $messages,
			'done'           => true,
		);
	}

	/**
	 * Delete this user's rows from the notification send log.
	 *
	 * @param int $user_id User id.
	 * @return bool Whether anything was removed.
	 */
	private function erase_notification_log( $user_id ) {
		global $wpdb;

		$table = Schema::log_table();

		if ( ! Schema::table_exists( $table ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->delete( $table, array( 'user_id' => (int) $user_id ), array( '%d' ) );

		return (int) $deleted > 0;
	}

	/**
	 * Suggest privacy policy content describing what data the plugin collects.
	 *
	 * @return void
	 */
	public function suggest_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content  = '<h2>' . esc_html__( 'User Management Suite', 'user-management-suite' ) . '</h2>';
		$content .= '<p>' . esc_html__( 'This site uses the User Management Suite plugin, which may collect and store the following personal data:', 'user-management-suite' ) . '</p>';
		$content .= '<ul>';
		$content .= '<li>' . esc_html__( 'Registration referrer URL and domain — recorded when you create an account.', 'user-management-suite' ) . '</li>';
		$content .= '<li>' . esc_html__( 'Last login timestamp and IP address — updated each time you log in.', 'user-management-suite' ) . '</li>';
		$content .= '<li>' . esc_html__( 'Email verification status — records whether you have confirmed your email address.', 'user-management-suite' ) . '</li>';

		if ( $this->settings->is_module_enabled( 'attribution' ) ) {
			$content .= '<li>' . esc_html__( 'Acquisition data — the site you arrived from, any campaign parameters in the link you followed, the page you landed on, and whether you used a mobile or desktop device. This is kept in a cookie on your device until you create an account, and then stored with it.', 'user-management-suite' ) . '</li>';
		}

		if ( $this->settings->is_module_enabled( 'notifications' ) ) {
			$content .= '<li>' . esc_html__( 'Notification preferences — which emails you have chosen to receive or stop receiving.', 'user-management-suite' ) . '</li>';

			if ( \Marinski\UserManagementSuite\Modules\Notifications\Log::enabled() ) {
				$content .= '<li>' . esc_html__( 'A record of notifications sent to you — the type of message, when it was sent, and whether it was delivered. The content of those messages is not stored.', 'user-management-suite' ) . '</li>';
			}
		}

		$content .= '</ul>';
		$content .= '<p>' . esc_html__( 'This data is stored in the site database and is used only for site management and security purposes. It is not shared with third parties except as required by law.', 'user-management-suite' ) . '</p>';

		if ( $this->settings->get( 'verification', 'recaptcha_version', '' ) ) {
			$content .= '<p>' . esc_html__( 'This site uses Google reCAPTCHA on the registration and/or login form. reCAPTCHA collects hardware and software information and sends it to Google to determine whether the user is a human. This data is used by Google in accordance with their Privacy Policy.', 'user-management-suite' ) . '</p>';
		}

		wp_add_privacy_policy_content(
			__( 'User Management Suite', 'user-management-suite' ),
			wp_kses_post( $content )
		);
	}
}
