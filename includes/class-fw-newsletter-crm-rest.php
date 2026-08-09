<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * REST controller — `fw-crm/v1`, following the Site Converter's precedent
 * (`fw-sc/v1` + a capability-gated permission_callback).
 *
 * Deliberately small in Phase 1: it claims the namespace and proves the service
 * layer really is shared, without committing to a public API surface before the
 * campaign/automation features exist. Both routes go through the same service the
 * admin screen and the capture hook use, so there is no second copy of the rules.
 */
class FW_Newsletter_CRM_REST {

	const NAMESPACE_ = 'fw-crm/v1';

	public function __construct() {
		add_action( 'rest_api_init', array( $this, '_register_routes' ) );
	}

	/**
	 * @internal
	 */
	public function _register_routes() {
		register_rest_route( self::NAMESPACE_, '/subscribers', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, '_get_subscribers' ),
				'permission_callback' => array( $this, '_authorize' ),
				'args'                => array(
					'status'   => array( 'type' => 'string', 'required' => false ),
					'list'     => array( 'type' => 'string', 'required' => false ),
					'search'   => array( 'type' => 'string', 'required' => false ),
					'per_page' => array( 'type' => 'integer', 'required' => false, 'default' => 20 ),
					'page'     => array( 'type' => 'integer', 'required' => false, 'default' => 1 ),
				),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, '_create_subscriber' ),
				'permission_callback' => array( $this, '_authorize' ),
				'args'                => array(
					'email' => array( 'type' => 'string', 'required' => true ),
					'name'  => array( 'type' => 'string', 'required' => false ),
					'list'  => array( 'type' => 'string', 'required' => false ),
					'tags'  => array( 'type' => 'array', 'required' => false ),
				),
			),
		) );
	}

	/**
	 * @internal
	 *
	 * @return bool
	 */
	public function _authorize() {
		return current_user_can( FW_Newsletter_CRM_Service::capability() );
	}

	/**
	 * @internal
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return WP_REST_Response
	 */
	public function _get_subscribers( $request ) {
		$args = array(
			'status'   => (string) $request->get_param( 'status' ),
			'list'     => (string) $request->get_param( 'list' ),
			'search'   => (string) $request->get_param( 'search' ),
			'per_page' => min( 100, max( 1, (int) $request->get_param( 'per_page' ) ) ),
			'paged'    => max( 1, (int) $request->get_param( 'page' ) ),
		);

		$rows  = FW_Newsletter_CRM_Subscribers::query( $args );
		$total = FW_Newsletter_CRM_Subscribers::count( $args );

		$items = array();

		foreach ( $rows as $row ) {
			$items[] = $this->prepare( $row );
		}

		$response = new WP_REST_Response( $items );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) ceil( $total / $args['per_page'] ) );

		return $response;
	}

	/**
	 * @internal
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function _create_subscriber( $request ) {
		$result = FW_Newsletter_CRM_Service::subscribe( (string) $request->get_param( 'email' ), array(
			'name'   => (string) $request->get_param( 'name' ),
			'list'   => (string) $request->get_param( 'list' ),
			'tags'   => (array) $request->get_param( 'tags' ),
			'source' => 'rest',
		) );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );

			return $result;
		}

		return new WP_REST_Response( $this->prepare( $result ), 201 );
	}

	/**
	 * Never expose the tokens — they are credentials, not data.
	 *
	 * @param object $row
	 *
	 * @return array
	 */
	private function prepare( $row ) {
		return array(
			'id'         => (int) $row->id,
			'email'      => $row->email,
			'first_name' => $row->first_name,
			'last_name'  => $row->last_name,
			'status'     => $row->status,
			'source'     => $row->source,
			'lists'      => wp_list_pluck( FW_Newsletter_CRM_Subscribers::get_lists( $row->id, 'list' ), 'slug' ),
			'tags'       => wp_list_pluck( FW_Newsletter_CRM_Subscribers::get_lists( $row->id, 'tag' ), 'slug' ),
			'created_at' => $row->created_at,
		);
	}
}
