<?php
/**
 * Front-end preference centre.
 *
 * @package UserManagementSuite
 */

namespace Marinski\UserManagementSuite\Modules\Notifications;

defined( 'ABSPATH' ) || exit;

/**
 * The [ums_preferences] shortcode: a self-contained preference form for
 * classic themes.
 */
class Shortcode {

	const TAG   = 'ums_preferences';
	const NONCE = 'ums_preferences_save';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_shortcode( self::TAG, array( $this, 'render' ) );
	}

	/**
	 * Render the form.
	 *
	 * @param array<string,mixed>|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render( $atts = array() ) {
		if ( ! is_user_logged_in() ) {
			return '<p class="ums-prefs-notice">'
				. esc_html__( 'Please sign in to manage your email preferences.', 'user-management-suite' )
				. '</p>';
		}

		$user_id = get_current_user_id();
		$saved   = $this->maybe_save( $user_id );
		$grouped = Registry::grouped();

		if ( array() === $grouped ) {
			return '<p class="ums-prefs-notice">'
				. esc_html__( 'There is nothing to configure yet.', 'user-management-suite' )
				. '</p>';
		}

		$stored = Preferences::stored( $user_id );

		ob_start();

		if ( $saved ) {
			echo '<p class="ums-prefs-notice ums-prefs-saved">'
				. esc_html__( 'Your preferences have been saved.', 'user-management-suite' ) . '</p>';
		}

		echo '<form method="post" class="ums-prefs-form">';
		wp_nonce_field( self::NONCE, self::NONCE );

		foreach ( $grouped as $group => $categories ) {
			echo '<fieldset class="ums-prefs-group"><legend>' . esc_html( $group ) . '</legend>';

			foreach ( $categories as $category => $types ) {
				echo '<h4>' . esc_html( Registry::category_label( $category ) ) . '</h4>';

				foreach ( $types as $type ) {
					$allowed = Preferences::allowed( $type['id'], $user_id, $stored );
					$locked  = ! $type['user_optout_allowed'];

					printf(
						'<p class="ums-prefs-row"><label><input type="checkbox" name="ums_notify[%1$s]" value="1" %2$s %3$s /> %4$s%5$s</label>%6$s</p>',
						esc_attr( $type['id'] ),
						checked( $allowed, true, false ),
						disabled( $locked, true, false ),
						esc_html( $type['label'] ),
						$locked
							? ' <span class="ums-prefs-required">' . esc_html__( '(always sent)', 'user-management-suite' ) . '</span>'
							: '',
						'' !== $type['description']
							? '<span class="ums-prefs-desc">' . esc_html( $type['description'] ) . '</span>'
							: ''
					);
				}
			}

			echo '</fieldset>';
		}

		echo '<p><button type="submit" class="ums-prefs-submit">'
			. esc_html__( 'Save preferences', 'user-management-suite' ) . '</button></p>';
		echo '</form>';

		return (string) ob_get_clean();
	}

	/**
	 * Handle a submitted form.
	 *
	 * @param int $user_id Current user id.
	 * @return bool Whether anything was saved.
	 */
	private function maybe_save( $user_id ) {
		if ( ! isset( $_POST[ self::NONCE ] ) ) {
			return false;
		}

		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		$submitted = isset( $_POST['ums_notify'] ) ? wp_unslash( $_POST['ums_notify'] ) : array();
		$submitted = is_array( $submitted ) ? $submitted : array();

		$prefs = array();

		// Unchecked boxes are simply absent from the request, so the stored value
		// is derived from the full list rather than from what came back.
		foreach ( Registry::types() as $id => $type ) {
			if ( ! $type['user_optout_allowed'] ) {
				continue;
			}

			$prefs[ $id ] = ! empty( $submitted[ $id ] );
		}

		Preferences::save( $user_id, $prefs );

		return true;
	}
}
