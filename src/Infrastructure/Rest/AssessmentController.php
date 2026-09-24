<?php

namespace SimpleLMS\Infrastructure\Rest;

use SimpleLMS\Domain\Assessment\AssessmentService;
use SimpleLMS\Domain\Users\RoleManager;

defined( 'ABSPATH' ) || exit;

class AssessmentController {
	/**
	 * @var AssessmentService
	 */
	private $assessments;

	public function __construct( AssessmentService $assessments ) {
		$this->assessments = $assessments;
	}

	public function register_hooks() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			'simple-lms/v1',
			'/assessments',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_assignments' ),
				'permission_callback' => array( $this, 'is_logged_in' ),
			)
		);

		register_rest_route(
			'simple-lms/v1',
			'/assessments/(?P<assignment_id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_assignment' ),
				'permission_callback' => array( $this, 'is_logged_in' ),
			)
		);

		register_rest_route(
			'simple-lms/v1',
			'/assessments/(?P<assignment_id>\d+)/submit',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'submit_assignment' ),
				'permission_callback' => array( $this, 'can_submit_assignment_request' ),
			)
		);

		register_rest_route(
			'simple-lms/v1',
			'/assessments/(?P<assignment_id>\d+)/grade',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'grade_submission' ),
				'permission_callback' => array( $this, 'can_grade_assignment_request' ),
			)
		);
	}

	public function get_assignments( $request ) {
		$user_id = get_current_user_id();
		$results = $this->assessments->list_assignments_for_user(
			$user_id,
			array(
				'section_id'  => absint( $request->get_param( 'section_id' ) ),
				'search'      => sanitize_text_field( $request->get_param( 'search' ) ?: '' ),
				'due_filter'  => sanitize_key( $request->get_param( 'due_filter' ) ?: '' ),
				'per_page'    => min( 100, max( 1, absint( $request->get_param( 'per_page' ) ?: 20 ) ) ),
				'page'        => max( 1, absint( $request->get_param( 'page' ) ?: 1 ) ),
			)
		);

		return rest_ensure_response( $results );
	}

	public function get_assignment( $request ) {
		$user_id      = get_current_user_id();
		$assignment_id = absint( $request['assignment_id'] );
		$assignment   = $this->assessments->get_assignment( $assignment_id, $user_id );

		if ( ! $assignment ) {
			return new \WP_Error( 'slms_assignment_not_found', __( 'Assignment not found or inaccessible.', 'simple-lms' ), array( 'status' => 404 ) );
		}

		if ( $this->assessments->can_user_grade_assignment( $assignment_id, $user_id ) ) {
			$assignment['submissions'] = $this->assessments->list_submissions( $assignment_id );
		}

		return rest_ensure_response( $assignment );
	}

	public function submit_assignment( $request ) {
		$params = $request->get_json_params();

		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}

		$result = $this->assessments->submit_assignment(
			absint( $request['assignment_id'] ),
			get_current_user_id(),
			array(
				'submission_text' => $params['submission_text'] ?? '',
				'attachment_url'  => $params['attachment_url'] ?? '',
				'attachment_name' => $params['attachment_name'] ?? '',
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => __( 'Assignment submitted successfully.', 'simple-lms' ),
			)
		);
	}

	public function grade_submission( $request ) {
		$params = $request->get_json_params();

		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}

		$result = $this->assessments->grade_submission(
			absint( $request['assignment_id'] ),
			absint( $params['student_user_id'] ?? 0 ),
			array(
				'score'     => $params['score'] ?? '',
				'feedback'  => $params['feedback'] ?? '',
				'graded_by' => get_current_user_id(),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => __( 'Submission graded successfully.', 'simple-lms' ),
			)
		);
	}

	public function is_logged_in() {
		return is_user_logged_in();
	}

	public function can_submit_assignment_request( $request ) {
		return is_user_logged_in() && $this->assessments->can_user_submit_assignment( absint( $request['assignment_id'] ?? 0 ), get_current_user_id() );
	}

	public function can_grade_assignment_request( $request ) {
		return is_user_logged_in() && $this->assessments->can_user_grade_assignment( absint( $request['assignment_id'] ?? 0 ), get_current_user_id() );
	}

	public function can_submit_work() {
		return is_user_logged_in() && current_user_can( RoleManager::CAP_SUBMIT_WORK );
	}

	public function can_grade_work() {
		return is_user_logged_in() && ( current_user_can( RoleManager::CAP_MANAGE_GRADEBOOK ) || current_user_can( RoleManager::CAP_MANAGE_ACADEMICS ) );
	}
}
