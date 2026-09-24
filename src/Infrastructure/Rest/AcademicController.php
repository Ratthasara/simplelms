<?php

namespace SimpleLMS\Infrastructure\Rest;

use SimpleLMS\Domain\Academic\EnrollmentService;
use SimpleLMS\Domain\Academic\TermService;
use SimpleLMS\Domain\Users\RoleManager;
use WP_Query;

defined( 'ABSPATH' ) || exit;

class AcademicController {
	/**
	 * @var TermService
	 */
	private $terms;

	/**
	 * @var EnrollmentService
	 */
	private $enrollments;

	public function __construct( TermService $terms, EnrollmentService $enrollments ) {
		$this->terms       = $terms;
		$this->enrollments = $enrollments;
	}

	public function register_hooks() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			'simple-lms/v1',
			'/academic/terms',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_terms' ),
					'permission_callback' => array( $this, 'can_manage_academics' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_term' ),
					'permission_callback' => array( $this, 'can_manage_academics' ),
				),
			)
		);

		register_rest_route(
			'simple-lms/v1',
			'/academic/subjects',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_subjects' ),
				'permission_callback' => array( $this, 'can_manage_academics' ),
			)
		);

		register_rest_route(
			'simple-lms/v1',
			'/academic/sections',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_sections' ),
				'permission_callback' => array( $this, 'can_manage_academics' ),
			)
		);

		register_rest_route(
			'simple-lms/v1',
			'/academic/enrollments',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_enrollments' ),
					'permission_callback' => array( $this, 'can_manage_enrollments' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_enrollment' ),
					'permission_callback' => array( $this, 'can_manage_enrollments' ),
				),
			)
		);
	}

	public function get_terms( $request ) {
		return rest_ensure_response(
			array(
				'items' => $this->terms->list_terms(
					array(
						'academic_year' => $request->get_param( 'academic_year' ),
						'status'        => $request->get_param( 'status' ),
					)
				),
			)
		);
	}

	public function create_term( $request ) {
		$params = $request->get_json_params();

		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}

		$result = $this->terms->create_term( $params );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'id'   => (int) $result,
				'term' => $this->terms->get_term( $result ),
			)
		);
	}

	public function get_subjects( $request ) {
		$query = new WP_Query(
			array(
				'post_type'      => 'slms_subject',
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => min( 200, max( 1, absint( $request->get_param( 'per_page' ) ?: 50 ) ) ),
				'paged'          => max( 1, absint( $request->get_param( 'page' ) ?: 1 ) ),
				's'              => sanitize_text_field( $request->get_param( 'search' ) ?: '' ),
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		$items = array_map(
			function ( $post ) {
				return array(
					'id'             => $post->ID,
					'title'          => $post->post_title,
					'status'         => $post->post_status,
					'subject_code'   => get_post_meta( $post->ID, '_slms_subject_code', true ),
					'credits'        => get_post_meta( $post->ID, '_slms_credits', true ),
					'subject_type'   => get_post_meta( $post->ID, '_slms_subject_type', true ),
					'subject_status' => get_post_meta( $post->ID, '_slms_subject_status', true ),
					'programs'       => wp_get_post_terms( $post->ID, 'slms_program', array( 'fields' => 'names' ) ),
				);
			},
			$query->posts
		);

		return rest_ensure_response(
			array(
				'items'       => $items,
				'total'       => (int) $query->found_posts,
				'total_pages' => (int) $query->max_num_pages,
			)
		);
	}

	public function get_sections( $request ) {
		$meta_query = array();

		if ( $request->get_param( 'subject_id' ) ) {
			$meta_query[] = array(
				'key'   => '_slms_subject_id',
				'value' => absint( $request->get_param( 'subject_id' ) ),
			);
		}

		if ( $request->get_param( 'term_id' ) ) {
			$meta_query[] = array(
				'key'   => '_slms_term_id',
				'value' => absint( $request->get_param( 'term_id' ) ),
			);
		}

		$query = new WP_Query(
			array(
				'post_type'      => 'slms_section',
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => min( 200, max( 1, absint( $request->get_param( 'per_page' ) ?: 50 ) ) ),
				'paged'          => max( 1, absint( $request->get_param( 'page' ) ?: 1 ) ),
				's'              => sanitize_text_field( $request->get_param( 'search' ) ?: '' ),
				'meta_query'     => $meta_query,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		$items = array_map(
			function ( $post ) {
				$subject_id = absint( get_post_meta( $post->ID, '_slms_subject_id', true ) );
				$term_id    = absint( get_post_meta( $post->ID, '_slms_term_id', true ) );
				$term       = $term_id ? $this->terms->get_term( $term_id ) : null;

				return array(
					'id'              => $post->ID,
					'title'           => $post->post_title,
					'status'          => $post->post_status,
					'section_code'    => get_post_meta( $post->ID, '_slms_section_code', true ),
					'capacity'        => (int) get_post_meta( $post->ID, '_slms_capacity', true ),
					'delivery_mode'   => get_post_meta( $post->ID, '_slms_delivery_mode', true ),
					'section_status'  => get_post_meta( $post->ID, '_slms_section_status', true ),
					'subject_id'      => $subject_id,
					'subject_title'   => $subject_id ? get_the_title( $subject_id ) : '',
					'term'            => $term,
					'lecturers'       => $this->enrollments->get_section_staff( $post->ID, 'lecturer' ),
				);
			},
			$query->posts
		);

		return rest_ensure_response(
			array(
				'items'       => $items,
				'total'       => (int) $query->found_posts,
				'total_pages' => (int) $query->max_num_pages,
			)
		);
	}

	public function get_enrollments( $request ) {
		return rest_ensure_response(
			array(
				'items' => $this->enrollments->list_enrollments(
					array(
						'status'          => $request->get_param( 'status' ),
						'section_id'      => $request->get_param( 'section_id' ),
						'student_user_id' => $request->get_param( 'student_user_id' ),
					)
				),
			)
		);
	}

	public function create_enrollment( $request ) {
		$params = $request->get_json_params();

		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}

		$result = $this->enrollments->enroll_student(
			absint( $params['section_id'] ?? 0 ),
			absint( $params['student_user_id'] ?? 0 ),
			array(
				'status'     => $params['status'] ?? 'enrolled',
				'source'     => $params['source'] ?? 'api',
				'notes'      => $params['notes'] ?? '',
				'created_by' => get_current_user_id(),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'id' => (int) $result,
			)
		);
	}

	public function can_manage_academics() {
		return current_user_can( RoleManager::CAP_MANAGE_ACADEMICS );
	}

	public function can_manage_enrollments() {
		return current_user_can( RoleManager::CAP_MANAGE_ENROLLMENTS );
	}
}
