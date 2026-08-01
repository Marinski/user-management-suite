<?php
/**
 * REST access to notification preferences.
 *
 * @package UserManagementSuite
 */

namespace Marinski\UserManagementSuite\Modules\Notifications;

defined( 'ABSPATH' ) || exit;

/**
 * Exposes the preference centre to decoupled front ends.
 *
 * The admin profile panel is not the real interface for most sites — the user
 * never sees wp-admin. Without this route the whole module would be invisible
 * to anyone running a separate front end.
 */
class RestController {

	const NAMESPACE_ = 'ums/v1';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_,
			'/preferences',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_preferences' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'user_id' => array(
							'type'        => 'integer',
							'default'     => 0,
							'description' => __( 'Defaults to the authenticated user.', 'user-management-suite' ),
						),
					),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'update_preferences' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'user_id'     => array(
							'type'    => 'integer',
							'default' => 0,
						),
						'preferences' => array(
							'type'        => 'object',
							'description' => __( 'Map of notification type id to boolean.', 'user-management-suite' ),
						),
					),
				),
			)
		);
	}

	/**
	 * Whether the request may read or change the target user's preferences.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function can_manage( $request ) {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error(
				'ums_not_logged_in',
				__( 'You must be signed in to manage notification preferences.', 'user-management-suite' ),
				array( 'status' => 401 )
			);
		}

		$target = $this->target_user( $request );

		if ( $target === get_current_user_id() ) {
			return true;
		}

		if ( current_user_can( 'edit_user', $target ) ) {
			return true;
		}

		return new \WP_Error(
			'ums_forbidden',
			__( 'You cannot manage another user\'s notification preferences.', 'user-management-suite' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Which user the request is about.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return int
	 */
	private function target_user( $request ) {
		$requested = (int) $request->get_param( 'user_id' );

		return $requested > 0 ? $requested : get_current_user_id();
	}

	/**
	 * Return the full preference set, including what cannot be changed.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_preferences( $request ) {
		$user_id = $this->target_user( $request );
		$stored  = Preferences::stored( $user_id );
		$out     = array();

		foreach ( Registry::types() as $id => $type ) {
			$out[] = array(
				'id'          => $id,
				'label'       => $type['label'],
				'description' => $type['description'],
				'group'       => $type['group'],
				'category'    => $type['category'],
				'categoryLabel' => Registry::category_label( $type['category'] ),
				'channel'     => $type['channel'],
				'required'    => ! $type['user_optout_allowed'],
				'enabled'     => Preferences::allowed( $id, $user_id, $stored ),
			);
		}

		return new \WP_REST_Response(
			array(
				'userId'      => $user_id,
				'preferences' => $out,
			),
			200
		);
	}

	/**
	 * Apply a partial update.
	 *
	 * Only the keys present in the request are changed, so a client that knows
	 * about one toggle cannot wipe choices it has never heard of.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_preferences( $request ) {
		$user_id  = $this->target_user( $request );
		$incoming = $request->get_param( 'preferences' );

		if ( ! is_array( $incoming ) ) {
			return new \WP_Error(
				'ums_bad_request',
				__( 'The preferences parameter must be an object of type id to boolean.', 'user-management-suite' ),
				array( 'status' => 400 )
			);
		}

		$merged  = Preferences::stored( $user_id );
		$ignored = array();

		foreach ( $incoming as $id => $value ) {
			$id = sanitize_key( (string) $id );

			if ( ! Registry::is_optional( $id ) ) {
				$ignored[] = $id;
				continue;
			}

			$merged[ $id ] = (bool) rest_sanitize_boolean( $value );
		}

		Preferences::save( $user_id, $merged );

		$response = $this->get_preferences( $request );
		$data     = $response->get_data();

		// Report rather than silently discard: a client asking to switch off a
		// receipt should learn that it cannot.
		$data['ignored'] = array_values( array_unique( $ignored ) );

		return new \WP_REST_Response( $data, 200 );
	}
}
