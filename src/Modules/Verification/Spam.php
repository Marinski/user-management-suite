<?php
/**
 * Registration spam protection (domain/username/generic-email blocking).
 *
 * @package UserManagementSuite
 */

namespace Marinski\UserManagementSuite\Modules\Verification;

use Marinski\UserManagementSuite\Settings\SettingsRepository;
use Marinski\UserManagementSuite\Support\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Validates registrations against allow/block lists.
 */
class Spam {

	/**
	 * Settings repository.
	 *
	 * @var SettingsRepository
	 */
	private $settings;

	/**
	 * Default role-based local-parts blocked when "generic email" is enabled.
	 *
	 * @var string[]
	 */
	private $generic_prefixes = array(
		'admin',
		'administrator',
		'info',
		'sales',
		'support',
		'contact',
		'noreply',
		'no-reply',
		'webmaster',
		'postmaster',
	);

	/**
	 * Constructor.
	 *
	 * @param SettingsRepository $settings Settings repository.
	 */
	public function __construct( SettingsRepository $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Validate a registration; add errors as needed.
	 *
	 * @param \WP_Error $errors      Error object.
	 * @param string    $user_login  Sanitized login.
	 * @param string    $user_email  Email.
	 * @return \WP_Error
	 */
	public function validate( $errors, $user_login, $user_email ) {
		if ( ! $errors instanceof \WP_Error ) {
			$errors = new \WP_Error();
		}

		$email  = strtolower( trim( (string) $user_email ) );
		$at     = strrpos( $email, '@' );
		$domain = ( false !== $at ) ? substr( $email, $at + 1 ) : '';
		$local  = ( false !== $at ) ? substr( $email, 0, $at ) : $email;

		if ( $this->has_blocked_keyword( $user_login, $local ) ) {
			$message = __( '<strong>Error</strong>: Your account could not be created. Please contact support.', 'user-management-suite' );

			/**
			 * Filters the message shown when a registration matches a blocked
			 * keyword. Kept vague so the rule itself is not disclosed.
			 *
			 * @param string            $message Message.
			 * @param string            $login   Sanitized login attempt.
			 * @param string            $local   Email local part (before @), lowercased.
			 */
			$message = apply_filters( 'ums_registration_keyword_message', $message, $user_login, $local );
			$errors->add( 'ums_blocked_keyword', $message );
		}

		$blocked_domains = array_map( 'strtolower', (array) $this->settings->get( 'verification', 'blocked_domains', array() ) );
		$allowed_domains = array_map( 'strtolower', (array) $this->settings->get( 'verification', 'allowed_domains', array() ) );
		$blocked_users   = array_map( 'strtolower', (array) $this->settings->get( 'verification', 'blocked_usernames', array() ) );
		$blocked_ips     = array_map( 'strtolower', (array) $this->settings->get( 'verification', 'blocked_ips', array() ) );

		if ( $domain && $this->domain_matches( $domain, $blocked_domains ) ) {
			$errors->add( 'ums_blocked_domain', __( '<strong>Error</strong>: Registrations from this email domain are not allowed.', 'user-management-suite' ) );
		}

		if ( $domain && ! empty( $allowed_domains ) && ! in_array( $domain, $allowed_domains, true ) ) {
			$errors->add( 'ums_domain_not_allowed', __( '<strong>Error</strong>: Please register with an approved email domain.', 'user-management-suite' ) );
		}

		$login_lc = strtolower( (string) $user_login );
		if ( in_array( $login_lc, $blocked_users, true ) ) {
			$errors->add( 'ums_blocked_username', __( '<strong>Error</strong>: This username is not allowed.', 'user-management-suite' ) );
		}

		$ip = Security::client_ip();
		if ( $ip && in_array( $ip, $blocked_ips, true ) ) {
			$message = __( '<strong>Error</strong>: Your account could not be created. Please contact support.', 'user-management-suite' );

			/**
			 * Filters the message shown when a registration originates from a
			 * blocked IP. Kept vague so the rule itself is not disclosed.
			 *
			 * @param string $message Message.
			 * @param string $ip      Resolved client IP.
			 */
			$message = apply_filters( 'ums_registration_ip_message', $message, $ip );
			$errors->add( 'ums_blocked_ip', $message );
		}

		if ( $this->settings->get( 'verification', 'block_generic_email', false ) ) {
			$prefixes = (array) apply_filters( 'ums_generic_email_prefixes', $this->generic_prefixes );
			if ( in_array( $local, array_map( 'strtolower', $prefixes ), true ) ) {
				$errors->add( 'ums_generic_email', __( '<strong>Error</strong>: Please use a personal email address.', 'user-management-suite' ) );
			}
		}

		return $errors;
	}

	/**
	 * Whether a username or email local part matches a blocked keyword.
	 *
	 * Scans the login and the email local part (before `@`) only. The domain half
	 * is excluded on purpose — a word like `.vip` legitimately appears in domains,
	 * and matching it would reject real addresses.
	 *
	 * Short tokens (< 5 chars) are ambiguous substrings (`bet` in "betsy",
	 * `vip` in "vipul", `neha`, `789`), so they only match a whole username or a
	 * whole email local part. Tokens of 5+ characters still match anywhere
	 * (e.g. `casino`, `sexdollpartner`). This keeps the WooCommerce checkout
	 * surface safe for real names while still catching the brand/slug markers.
	 *
	 * @param string $login Login.
	 * @param string $local Email local part, lowercased.
	 * @return bool
	 */
	private function has_blocked_keyword( $login, $local ) {
		$keywords = array_filter( array_map( 'strtolower', (array) $this->settings->get( 'verification', 'blocked_keywords', array() ) ) );
		if ( empty( $keywords ) ) {
			return false;
		}

		$login_lc = strtolower( (string) $login );

		foreach ( $keywords as $keyword ) {
			if ( strlen( $keyword ) < 5 ) {
				if ( $login_lc === $keyword || ( $local && $local === $keyword ) ) {
					return true;
				}
				continue;
			}

			if ( ( $login_lc && false !== strpos( $login_lc, $keyword ) )
				|| ( $local && false !== strpos( $local, $keyword ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Match a domain against a block list.
	 *
	 * An entry beginning with a dot is treated as a suffix, so `.xyz` blocks the
	 * whole TLD and `.example.com` blocks every subdomain of it. Anything else is
	 * an exact match. One field covers both cases, which keeps the settings
	 * screen simple.
	 *
	 * @param string   $domain   Domain from the submitted address, lowercased.
	 * @param string[] $entries  Block list, lowercased.
	 * @return bool
	 */
	private function domain_matches( $domain, array $entries ) {
		foreach ( $entries as $entry ) {
			$entry = trim( (string) $entry );

			if ( '' === $entry ) {
				continue;
			}

			if ( '.' === $entry[0] ) {
				$suffix = substr( $entry, 1 );
				if ( '' !== $suffix && substr( $domain, -strlen( $entry ) ) === $entry ) {
					return true;
				}
				continue;
			}

			if ( $domain === $entry ) {
				return true;
			}
		}

		return false;
	}
}
