<?php
/**
 * One-click unsubscribe handling and List-Unsubscribe headers.
 *
 * @package UserManagementSuite
 */

namespace Marinski\UserManagementSuite\Modules\Notifications;

defined( 'ABSPATH' ) || exit;

/**
 * Implements RFC 8058 one-click unsubscribe.
 *
 * Bulk senders are required by the large mailbox providers to offer a
 * one-click unsubscribe header; without it, mail is filtered harder regardless
 * of how good the list is.
 */
class Unsubscribe {

	/** Query variable carrying the token. */
	const QUERY_VAR = 'ums_unsubscribe';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'init', array( $this, 'maybe_handle' ), 1 );
		add_filter( 'woocommerce_email_headers', array( $this, 'add_woocommerce_headers' ), 20, 2 );
	}

	/**
	 * Build the unsubscribe URL for a user.
	 *
	 * @param int    $user_id User id.
	 * @param string $scope   Category, or '' for everything optional.
	 * @return string
	 */
	public static function url( $user_id, $scope = '' ) {
		$token = Token::create( $user_id, $scope );

		if ( '' === $token ) {
			return '';
		}

		return add_query_arg( self::QUERY_VAR, rawurlencode( $token ), home_url( '/' ) );
	}

	/**
	 * The RFC 8058 header pair for a user.
	 *
	 * @param int    $user_id User id.
	 * @param string $scope   Category, or '' for everything optional.
	 * @return string Header lines, or '' when a URL cannot be built.
	 */
	public static function headers( $user_id, $scope = '' ) {
		$url = self::url( $user_id, $scope );

		if ( '' === $url ) {
			return '';
		}

		return 'List-Unsubscribe: <' . $url . ">\r\n"
			. "List-Unsubscribe-Post: List-Unsubscribe=One-Click\r\n";
	}

	/**
	 * Append the headers to WooCommerce emails a user may switch off.
	 *
	 * Locked emails get nothing: offering an unsubscribe link for a receipt that
	 * cannot be switched off would be a lie.
	 *
	 * @param string $headers Existing headers.
	 * @param string $email_id WooCommerce email id.
	 * @return string
	 */
	public function add_woocommerce_headers( $headers, $email_id = '' ) {
		if ( ! in_array( (string) $email_id, Adapters\WooCommerceAdapter::optional_ids(), true ) ) {
			return $headers;
		}

		$object = null;

		// The email object is not passed to this filter's second argument, so the
		// recipient is resolved from the mailer's current object.
		if ( function_exists( 'WC' ) && WC()->mailer() ) {
			$emails = WC()->mailer()->get_emails();

			foreach ( $emails as $email ) {
				if ( isset( $email->id ) && (string) $email->id === (string) $email_id ) {
					$object = isset( $email->object ) ? $email->object : null;
					break;
				}
			}
		}

		$user_id = Adapters\WooCommerceAdapter::resolve_user_id( $object );

		if ( $user_id <= 0 ) {
			return $headers;
		}

		$extra = self::headers( $user_id, Registry::PRODUCT );

		return '' === $extra ? $headers : $headers . $extra;
	}

	/**
	 * Handle an incoming unsubscribe request.
	 *
	 * @return void
	 */
	public function maybe_handle() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The signed token is the authentication; sanitising it would corrupt the signature.
		$raw = isset( $_GET[ self::QUERY_VAR ] ) ? wp_unslash( $_GET[ self::QUERY_VAR ] ) : '';

		if ( '' === $raw || ! is_string( $raw ) ) {
			return;
		}

		$claim = Token::verify( $raw );

		if ( null === $claim ) {
			$this->render_page(
				__( 'This link is no longer valid', 'user-management-suite' ),
				__( 'The unsubscribe link has expired or the address it was sent to has changed. Sign in to manage your preferences.', 'user-management-suite' ),
				false
			);

			return;
		}

		if ( $this->is_one_click_post() ) {
			Preferences::opt_out_all( $claim['user_id'], $claim['scope'] );

			// Mailbox providers want a bare success, not a page.
			status_header( 200 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo 'OK';
			exit;
		}

		if ( $this->is_confirm_post( $raw ) ) {
			$count = Preferences::opt_out_all( $claim['user_id'], $claim['scope'] );

			$this->render_page(
				__( 'You have been unsubscribed', 'user-management-suite' ),
				sprintf(
					/* translators: %d: number of notification types switched off. */
					_n(
						'%d type of message has been switched off. Account and order emails still apply, because they are records of your transactions.',
						'%d types of message have been switched off. Account and order emails still apply, because they are records of your transactions.',
						$count,
						'user-management-suite'
					),
					$count
				),
				true
			);

			return;
		}

		/*
		 * A plain GET only ever shows a confirmation. Mail clients and security
		 * scanners routinely prefetch links, and acting on GET would unsubscribe
		 * people who never clicked anything.
		 */
		$this->render_confirmation( $raw, $claim );
	}

	/**
	 * Whether this is a mailbox provider's one-click POST.
	 *
	 * @return bool
	 */
	private function is_one_click_post() {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The signed token is the authentication; this body is defined by RFC 8058.
		$value = isset( $_POST['List-Unsubscribe'] ) ? sanitize_text_field( wp_unslash( $_POST['List-Unsubscribe'] ) ) : '';

		return 'One-Click' === $value;
	}

	/**
	 * Whether this is our own confirmation form being submitted.
	 *
	 * @param string $token Token from the query string.
	 * @return bool
	 */
	private function is_confirm_post( $token ) {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The signed token is the authentication.
		$confirm = isset( $_POST['ums_unsubscribe_confirm'] ) ? sanitize_text_field( wp_unslash( $_POST['ums_unsubscribe_confirm'] ) ) : '';

		return '' !== $confirm && hash_equals( $token, $confirm );
	}

	/**
	 * Show the confirmation form.
	 *
	 * @param string                          $token Raw token.
	 * @param array{user_id:int,scope:string} $claim Verified claim.
	 * @return void
	 */
	private function render_confirmation( $token, array $claim ) {
		$user  = get_userdata( $claim['user_id'] );
		$email = $user ? $user->user_email : '';

		$body = '<p>' . esc_html(
			sprintf(
				/* translators: %s: email address. */
				__( 'Stop sending these messages to %s?', 'user-management-suite' ),
				$email
			)
		) . '</p>';

		$body .= '<form method="post">';
		$body .= '<input type="hidden" name="ums_unsubscribe_confirm" value="' . esc_attr( $token ) . '" />';
		$body .= '<button type="submit" class="ums-unsub-button">'
			. esc_html__( 'Yes, unsubscribe me', 'user-management-suite' ) . '</button>';
		$body .= '</form>';

		$body .= '<p class="ums-unsub-note">'
			. esc_html__( 'Account and order emails will continue, because they are records of your transactions.', 'user-management-suite' )
			. '</p>';

		$page = self::preferences_url();

		if ( '' !== $page ) {
			$body .= '<p><a href="' . esc_url( $page ) . '">'
				. esc_html__( 'Choose individually instead', 'user-management-suite' ) . '</a></p>';
		}

		$this->render_page( __( 'Unsubscribe', 'user-management-suite' ), $body, true, false );
	}

	/**
	 * The configured preference page URL, if any.
	 *
	 * @return string
	 */
	public static function preferences_url() {
		$settings = get_option( 'ums_settings', array() );
		$page_id  = is_array( $settings ) && isset( $settings['notifications']['preferences_page'] )
			? (int) $settings['notifications']['preferences_page']
			: 0;

		return $page_id > 0 ? (string) get_permalink( $page_id ) : '';
	}

	/**
	 * Render a small standalone page and stop.
	 *
	 * Deliberately theme-independent: an unsubscribe link has to work even if
	 * the theme is broken, and it is followed by people who are already annoyed.
	 *
	 * @param string $title   Page title.
	 * @param string $body    Body HTML (already escaped) or plain text.
	 * @param bool   $success Whether this is a success state.
	 * @param bool   $escape  Whether to escape the body.
	 * @return void
	 */
	private function render_page( $title, $body, $success = true, $escape = true ) {
		status_header( 200 );
		nocache_headers();

		if ( ! headers_sent() ) {
			header( 'Content-Type: text/html; charset=utf-8' );
		}

		$content = $escape ? '<p>' . esc_html( $body ) . '</p>' : $body;

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_language_attributes() returns a fixed, safe attribute string.
		echo '<!DOCTYPE html><html ' . get_language_attributes() . '><head><meta charset="utf-8" />';
		echo '<meta name="viewport" content="width=device-width, initial-scale=1" />';
		echo '<meta name="robots" content="noindex, nofollow" />';
		echo '<title>' . esc_html( $title ) . '</title>';
		echo '<style>
			body{font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;line-height:1.5;margin:0;padding:48px 20px;background:#f6f7f7;color:#1e1e1e}
			.ums-box{max-width:520px;margin:0 auto;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:32px}
			h1{margin-top:0;font-size:22px}
			.ums-unsub-button{display:inline-block;padding:10px 18px;font-size:15px;border:0;border-radius:4px;background:#2271b1;color:#fff;cursor:pointer}
			.ums-unsub-note{color:#646970;font-size:14px}
			a{color:#2271b1}
			@media (prefers-color-scheme:dark){
				body{background:#1d2327;color:#f0f0f1}
				.ums-box{background:#2c3338;border-color:#3c434a}
				a{color:#72aee6}
			}
		</style></head><body><div class="ums-box">';
		echo '<h1>' . esc_html( $title ) . '</h1>';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above, or assembled here from individually escaped parts.
		echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div></body></html>';

		exit;
	}
}
