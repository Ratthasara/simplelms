<?php

namespace SimpleLMS\Infrastructure\Rest;

use SimpleLMS\Domain\Reporting\RecordsService;

defined( 'ABSPATH' ) || exit;

class RecordsController {
	/**
	 * @var RecordsService
	 */
	private $records;

	public function __construct( RecordsService $records ) {
		$this->records = $records;
	}

	public function register_hooks() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			'simple-lms/v1',
			'/records/sections',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_sections' ),
				'permission_callback' => array( $this, 'can_view_records' ),
			)
		);

		register_rest_route(
			'simple-lms/v1',
			'/records/sections/(?P<section_id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_section_record' ),
				'permission_callback' => array( $this, 'can_view_records' ),
			)
		);
	}

	public function get_sections( $request ) {
		$result = $this->records->get_sections(
			array(
				'academic_year'  => $request->get_param( 'academic_year' ),
				'term_id'        => $request->get_param( 'term_id' ),
				'section_status' => $request->get_param( 'section_status' ),
				'search'         => $request->get_param( 'search' ),
			),
			get_current_user_id()
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'filters' => $this->records->get_filter_options(),
				'items'   => $result,
			)
		);
	}

	public function get_section_record( $request ) {
		$result = $this->records->get_section_record( absint( $request['section_id'] ), get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	public function can_view_records() {
		return is_user_logged_in() && $this->records->can_view_records( get_current_user_id() );
	}
}
