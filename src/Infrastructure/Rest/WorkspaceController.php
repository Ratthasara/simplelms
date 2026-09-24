<?php

namespace SimpleLMS\Infrastructure\Rest;

use SimpleLMS\Domain\Learning\WorkspaceService;

defined( 'ABSPATH' ) || exit;

class WorkspaceController {
	/**
	 * @var WorkspaceService
	 */
	private $workspace;

	public function __construct( WorkspaceService $workspace ) {
		$this->workspace = $workspace;
	}

	public function register_hooks() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			'simple-lms/v1',
			'/workspace/me',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_my_workspace' ),
				'permission_callback' => array( $this, 'is_logged_in' ),
			)
		);

		register_rest_route(
			'simple-lms/v1',
			'/workspace/sections/(?P<section_id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_section_workspace' ),
				'permission_callback' => array( $this, 'is_logged_in' ),
			)
		);
	}

	public function get_my_workspace() {
		return rest_ensure_response( $this->workspace->get_dashboard_snapshot( get_current_user_id() ) );
	}

	public function get_section_workspace( $request ) {
		$result = $this->workspace->get_section_workspace( get_current_user_id(), absint( $request['section_id'] ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	public function is_logged_in() {
		return is_user_logged_in();
	}
}
