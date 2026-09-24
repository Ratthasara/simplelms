<?php

namespace SimpleLMS\Infrastructure\Rest;

use SimpleLMS\Domain\Assessment\GradebookService;
use SimpleLMS\Domain\Users\RoleManager;

defined( 'ABSPATH' ) || exit;

class GradebookController {
	/**
	 * @var GradebookService
	 */
	private $gradebook;

	public function __construct( GradebookService $gradebook ) {
		$this->gradebook = $gradebook;
	}

	public function register_hooks() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			'simple-lms/v1',
			'/gradebook/sections/(?P<section_id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_section_gradebook' ),
				'permission_callback' => array( $this, 'can_view_gradebook' ),
			)
		);
	}

	public function get_section_gradebook( $request ) {
		$result = $this->gradebook->get_gradebook( absint( $request['section_id'] ), get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	public function can_view_gradebook( $request = null ) {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		$section_id = $request && isset( $request['section_id'] ) ? absint( $request['section_id'] ) : 0;

		if ( $section_id > 0 ) {
			return $this->gradebook->can_view_full_gradebook( get_current_user_id(), $section_id );
		}

		return current_user_can( RoleManager::CAP_MANAGE_GRADEBOOK ) || current_user_can( RoleManager::CAP_MANAGE_ACADEMICS );
	}
}
