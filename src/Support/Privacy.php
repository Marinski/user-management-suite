<?php
/**
 * WordPress privacy tools integration.
 *
 * @package UserManagementSuite
 */

namespace Marinski\UserManagementSuite\Support;

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

		return array(
			'data' => $items,
			'done' => true,
		);
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
		);

		foreach ( $erasable as $meta_key ) {
			$existing = get_user_meta( $user->ID, $meta_key, true );
			if ( '' !== $existing && false !== $existing ) {
				delete_user_meta( $user->ID, $meta_key );
				$removed = true;
			}
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
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
