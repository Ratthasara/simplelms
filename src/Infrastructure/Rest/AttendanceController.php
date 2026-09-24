<?php

namespace SimpleLMS\Infrastructure\Rest;

use SimpleLMS\Domain\Academic\AttendanceService;
use SimpleLMS\Domain\Users\RoleManager;

defined( 'ABSPATH' ) || exit;

class AttendanceController {
	/**
	 * @var AttendanceService
	 */
	private $attendance;

	public function __construct( AttendanceService $attendance ) {
		$this->attendance = $attendance;
	}

	public function register_hooks() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			'simple-lms/v1',
			'/attendance/sections/(?P<section_id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_section_attendance' ),
				'permission_callback' => array( $this, 'can_view_attendance' ),
			)
		);
	}

	public function get_section_attendance( $request ) {
		$result = $this->attendance->get_section_attendance_dashboard( absint( $request['section_id'] ), get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	public function can_view_attendance( $request ) {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		$section_id = absint( $request['section_id'] ?? 0 );

		if ( $section_id ) {
			return $this->attendance->can_view_attendance( get_current_user_id(), $section_id );
		}

		return current_user_can( RoleManager::CAP_MANAGE_ATTENDANCE ) || current_user_can( RoleManager::CAP_MANAGE_ACADEMICS ) || current_user_can( RoleManager::CAP_VIEW_OWN_RECORDS );
	}
}
