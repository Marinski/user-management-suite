<?php
/**
 * Acquisition attribution module.
 *
 * @package UserManagementSuite
 */

namespace Marinski\UserManagementSuite\Modules\Attribution;

use Marinski\UserManagementSuite\Modules\AbstractModule;
use Marinski\UserManagementSuite\Settings\Fields;
use Marinski\UserManagementSuite\Settings\ProvidesSettings;
use Marinski\UserManagementSuite\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Records where each user came from, and exposes it on the admin screens.
 */
class AttributionModule extends AbstractModule implements ProvidesSettings {

	const REST_NAMESPACE = 'ums/v1';

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'attribution';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return __( 'Acquisition Tracking', 'user-management-suite' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function register() {
		add_action( 'init', array( $this, 'maybe_capture' ), 5 );
		add_action( 'user_register', array( $this, 'capture_for_user' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest' ) );

		if ( is_admin() ) {
			add_action( 'show_user_profile', array( $this, 'render_profile_panel' ) );
			add_action( 'edit_user_profile', array( $this, 'render_profile_panel' ) );

			if ( $this->settings->get( 'attribution', 'show_columns', true ) ) {
				add_filter( 'manage_users_columns', array( $this, 'add_column' ) );
				add_filter( 'manage_users_custom_column', array( $this, 'render_column' ), 10, 3 );
				add_action( 'restrict_manage_users', array( $this, 'render_filter' ) );
				add_action( 'pre_get_users', array( $this, 'apply_filter' ) );
			}
		}
	}

	/**
	 * Record the current touch, if this looks like a real visitor page view.
	 *
	 * @return void
	 */
	public function maybe_capture() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		if ( ! $this->settings->get( 'attribution', 'capture_enabled', true ) ) {
			return;
		}

		if ( ! $this->has_consent() ) {
			return;
		}

		Capture::remember( (int) $this->settings->get( 'attribution', 'cookie_days', 30 ) );
	}

	/**
	 * Whether the visitor may be tracked.
	 *
	 * When consent is required the answer defaults to no, so a site that turns
	 * this on without wiring a consent platform stops tracking rather than
	 * quietly carrying on.
	 *
	 * @return bool
	 */
	private function has_consent() {
		if ( ! $this->settings->get( 'attribution', 'require_consent', false ) ) {
			return true;
		}

		/**
		 * Filters whether the visitor has consented to acquisition tracking.
		 *
		 * Consent platforms hook here. Defaults to false when consent is required.
		 *
		 * @param bool $consented Whether tracking is allowed.
		 */
		return (bool) apply_filters( 'ums_attribution_has_consent', false );
	}

	/**
	 * Store attribution against a newly registered user.
	 *
	 * @param int $user_id New user id.
	 * @return void
	 */
	public function capture_for_user( $user_id ) {
		Capture::store_for_user( $user_id );
	}

	/**
	 * Register the REST route headless front ends use.
	 *
	 * @return void
	 */
	public function register_rest() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/attribution',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_rest_post' ),
				'permission_callback' => static function () {
					return is_user_logged_in();
				},
				'args'                => array(
					'source'   => array( 'type' => 'string' ),
					'medium'   => array( 'type' => 'string' ),
					'campaign' => array( 'type' => 'string' ),
					'content'  => array( 'type' => 'string' ),
					'term'     => array( 'type' => 'string' ),
					'referrer' => array( 'type' => 'string' ),
					'landing'  => array( 'type' => 'string' ),
					'click_id' => array( 'type' => 'string' ),
				),
			)
		);
	}

	/**
	 * Store a touch posted by a decoupled front end for the current user.
	 *
	 * A headless signup never sets a cookie WordPress can read, so the front end
	 * sends what it captured on the landing page instead.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle_rest_post( $request ) {
		$user_id = get_current_user_id();
		$payload = array();

		foreach ( Record::fields() as $field ) {
			$value = $request->get_param( $field );

			if ( null !== $value && '' !== $value ) {
				$payload[ $field ] = $value;
			}
		}

		Capture::store_for_user( $user_id, $payload );

		$stored = Record::get( $user_id );

		return new \WP_REST_Response(
			array(
				'stored'  => true,
				'channel' => $stored['channel'],
				'source'  => $stored['source'],
			),
			200
		);
	}

	/**
	 * Render the acquisition panel on a user profile.
	 *
	 * @param \WP_User $user User being viewed.
	 * @return void
	 */
	public function render_profile_panel( $user ) {
		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}

		$first = Record::get( $user->ID, Record::META_FIRST );
		$last  = Record::get( $user->ID, Record::META_LAST );

		if ( Record::is_empty( $first ) && Record::is_empty( $last ) ) {
			return;
		}

		?>
		<h2><?php esc_html_e( 'User Management — Acquisition', 'user-management-suite' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'First touch', 'user-management-suite' ); ?></th>
				<td><?php $this->render_touch( $first ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Last touch before signup', 'user-management-suite' ); ?></th>
				<td><?php $this->render_touch( $last ); ?></td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Render one touch as a definition list.
	 *
	 * @param array<string,mixed> $touch Record.
	 * @return void
	 */
	private function render_touch( array $touch ) {
		if ( Record::is_empty( $touch ) ) {
			echo '<em>' . esc_html__( 'Not recorded.', 'user-management-suite' ) . '</em>';

			return;
		}

		$rows = array(
			__( 'Channel', 'user-management-suite' )  => ChannelMap::label( (string) $touch['channel'] ),
			__( 'Source', 'user-management-suite' )   => (string) $touch['source'],
			__( 'Medium', 'user-management-suite' )   => (string) $touch['medium'],
			__( 'Campaign', 'user-management-suite' ) => (string) $touch['campaign'],
			__( 'Click id', 'user-management-suite' ) => (string) $touch['click_id'],
			__( 'Referrer', 'user-management-suite' ) => (string) $touch['referrer'],
			__( 'Landing page', 'user-management-suite' ) => (string) $touch['landing'],
			__( 'Device', 'user-management-suite' )   => (string) $touch['device'],
			__( 'Recorded', 'user-management-suite' ) => $touch['ts']
				? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $touch['ts'] )
				: '',
		);

		echo '<ul style="margin:0;">';
		foreach ( $rows as $label => $value ) {
			if ( '' === $value ) {
				continue;
			}

			echo '<li><strong>' . esc_html( $label ) . ':</strong> ' . esc_html( $value ) . '</li>';
		}

		if ( Record::ORIGIN_BACKFILL === (string) $touch['origin'] ) {
			echo '<li><em>' . esc_html__( 'Imported from WooCommerce order attribution — may not be the true first visit.', 'user-management-suite' ) . '</em></li>';
		}

		echo '</ul>';
	}

	/**
	 * Add the acquisition column to the Users list.
	 *
	 * @param array<string,string> $columns Columns.
	 * @return array<string,string>
	 */
	public function add_column( $columns ) {
		$columns['ums_acquisition'] = __( 'Acquired via', 'user-management-suite' );

		return $columns;
	}

	/**
	 * Render the acquisition column.
	 *
	 * @param string $output      Default output.
	 * @param string $column_name Column id.
	 * @param int    $user_id     User id.
	 * @return string
	 */
	public function render_column( $output, $column_name, $user_id ) {
		if ( 'ums_acquisition' !== $column_name ) {
			return $output;
		}

		$record = Record::get( $user_id );

		if ( Record::is_empty( $record ) ) {
			return '&mdash;';
		}

		return esc_html( ChannelMap::label( (string) $record['channel'] ) )
			. '<br /><span class="description">' . esc_html( Record::summary( $record ) ) . '</span>';
	}

	/**
	 * Render the channel filter above the Users list.
	 *
	 * @param string $which Position ("top" or "bottom").
	 * @return void
	 */
	public function render_filter( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter.
		$selected = isset( $_GET['ums_channel'] ) ? sanitize_key( wp_unslash( $_GET['ums_channel'] ) ) : '';

		echo '<label class="screen-reader-text" for="ums_channel">'
			. esc_html__( 'Filter by acquisition channel', 'user-management-suite' ) . '</label>';
		echo '<select name="ums_channel" id="ums_channel">';
		echo '<option value="">' . esc_html__( 'All channels', 'user-management-suite' ) . '</option>';

		foreach ( ChannelMap::labels() as $slug => $label ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $slug ),
				selected( $selected, $slug, false ),
				esc_html( $label )
			);
		}

		echo '</select>';

		submit_button( __( 'Filter', 'user-management-suite' ), 'secondary', 'ums_filter_channel', false );
	}

	/**
	 * Apply the channel filter to the Users query.
	 *
	 * @param \WP_User_Query $query User query.
	 * @return void
	 */
	public function apply_filter( $query ) {
		if ( ! is_admin() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter.
		$channel = isset( $_GET['ums_channel'] ) ? sanitize_key( wp_unslash( $_GET['ums_channel'] ) ) : '';

		if ( '' === $channel ) {
			return;
		}

		$meta_query   = (array) $query->get( 'meta_query' );
		$meta_query[] = array(
			'key'   => Record::META_CHANNEL,
			'value' => $channel,
		);

		$query->set( 'meta_query', $meta_query );
	}

	/**
	 * {@inheritDoc}
	 */
	public function settings_tab_id() {
		return 'attribution';
	}

	/**
	 * {@inheritDoc}
	 */
	public function settings_tab_label() {
		return __( 'Acquisition', 'user-management-suite' );
	}

	/**
	 * Render the Acquisition settings tab.
	 *
	 * @param SettingsRepository $settings Settings repository.
	 * @return void
	 */
	public function render_settings_tab( SettingsRepository $settings ) {
		Fields::row_start( __( 'Capture', 'user-management-suite' ) );
		Fields::checkbox(
			'attribution',
			'capture_enabled',
			$settings->get( 'attribution', 'capture_enabled', true ),
			__( 'Record where visitors arrive from', 'user-management-suite' ),
			__( 'Campaign parameters and the referring site are stored in a first-party cookie on the landing page, then attached to the account if the visitor registers.', 'user-management-suite' )
		);
		Fields::row_end();

		Fields::row_start( __( 'Consent', 'user-management-suite' ) );
		Fields::checkbox(
			'attribution',
			'require_consent',
			$settings->get( 'attribution', 'require_consent', false ),
			__( 'Only track visitors who have given consent', 'user-management-suite' ),
			__( 'Nothing is stored until the ums_attribution_has_consent filter returns true, so a consent platform can gate it.', 'user-management-suite' )
		);
		Fields::row_end();

		Fields::row_start( __( 'Cookie lifetime', 'user-management-suite' ) );
		Fields::text( 'attribution', 'cookie_days', $settings->get( 'attribution', 'cookie_days', 30 ), '30', 'number' );
		echo '<p class="description">'
			. esc_html__( 'Days to remember a visit before signup. Longer windows credit slower decisions; shorter ones keep the data fresher.', 'user-management-suite' )
			. '</p>';
		Fields::row_end();

		Fields::row_start( __( 'Channel rules', 'user-management-suite' ) );
		Fields::textarea(
			'attribution',
			'channel_map',
			(string) $settings->get( 'attribution', 'channel_map', '' ),
			__( 'One rule per line, as "source = channel". A leading dot matches every subdomain, e.g. ".partner.com = affiliate". These override the built-in rules.', 'user-management-suite' )
		);
		echo '<p class="description">' . esc_html__( 'Available channels:', 'user-management-suite' ) . ' <code>'
			. esc_html( implode( '</code>, <code>', array_keys( ChannelMap::labels() ) ) ) . '</code></p>';
		Fields::row_end();

		Fields::row_start( __( 'Users list', 'user-management-suite' ) );
		Fields::checkbox(
			'attribution',
			'show_columns',
			$settings->get( 'attribution', 'show_columns', true ),
			__( 'Show an "Acquired via" column and channel filter on the Users screen', 'user-management-suite' )
		);
		Fields::row_end();
	}

	/**
	 * {@inheritDoc}
	 */
	public function sanitize_settings( array $input, array $current ) {
		if ( ! isset( $input['attribution'] ) || ! is_array( $input['attribution'] ) ) {
			return array();
		}

		$in = $input['attribution'];

		return array(
			'attribution' => array(
				'capture_enabled' => ! empty( $in['capture_enabled'] ),
				'require_consent' => ! empty( $in['require_consent'] ),
				'cookie_days'     => max( 1, min( 730, (int) ( $in['cookie_days'] ?? 30 ) ) ),
				'channel_map'     => ChannelMap::render_map( ChannelMap::parse_map( (string) ( $in['channel_map'] ?? '' ) ) ),
				'show_columns'    => ! empty( $in['show_columns'] ),
			),
		);
	}
}
