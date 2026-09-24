<?php

namespace SimpleLMS\Domain\Reporting;

use SimpleLMS\Domain\Academic\AttendanceService;
use SimpleLMS\Domain\Academic\EnrollmentService;
use SimpleLMS\Domain\Academic\TermService;
use SimpleLMS\Domain\Assessment\ResultsService;
use SimpleLMS\Domain\Users\RoleManager;
use WP_Error;

defined( 'ABSPATH' ) || exit;

class RecordsService {
	const SECTION_STATUSES = array( 'planned', 'active', 'closed', 'archived' );

	/**
	 * @var TermService
	 */
	private $terms;

	/**
	 * @var EnrollmentService
	 */
	private $enrollments;

	/**
	 * @var AttendanceService
	 */
	private $attendance;

	/**
	 * @var ResultsService
	 */
	private $results;

	public function __construct( TermService $terms, EnrollmentService $enrollments, AttendanceService $attendance, ResultsService $results ) {
		$this->terms       = $terms;
		$this->enrollments = $enrollments;
		$this->attendance  = $attendance;
		$this->results     = $results;
	}

	public function can_view_records( $user_id = 0 ) {
		$role = RoleManager::get_primary_role( $user_id ?: get_current_user_id() );

		return in_array( $role, array( 'administrator', 'officer' ), true );
	}

	public function get_filter_options() {
		return array(
			'academic_years'  => $this->terms->list_academic_years(),
			'terms'           => $this->terms->list_terms(),
			'section_statuses' => self::SECTION_STATUSES,
		);
	}

	public function get_sections( array $filters = array(), $user_id = 0 ) {
		$user_id = absint( $user_id ?: get_current_user_id() );

		if ( ! $this->can_view_records( $user_id ) ) {
			return new WP_Error( 'slms_forbidden_records', __( 'You do not have permission to review academic archives.', 'simple-lms' ) );
		}

		$clean = $this->sanitize_filters( $filters );

		return $this->enrollments->list_sections(
			array(
				'academic_year' => $clean['academic_year'],
				'term_id'       => $clean['term_id'],
				'section_status' => $clean['section_status'],
				'search'        => $clean['search'],
				'post_statuses' => array( 'publish', 'private', 'draft', 'pending' ),
			)
		);
	}

	public function get_section_record( $section_id, $user_id = 0 ) {
		$user_id    = absint( $user_id ?: get_current_user_id() );
		$section_id = absint( $section_id );

		if ( ! $this->can_view_records( $user_id ) ) {
			return new WP_Error( 'slms_forbidden_records', __( 'You do not have permission to review academic archives.', 'simple-lms' ) );
		}

		$section = $this->enrollments->get_section_summary( $section_id );

		if ( ! $section ) {
			return new WP_Error( 'slms_invalid_record_section', __( 'That section record could not be found.', 'simple-lms' ) );
		}

		$attendance = $this->attendance->get_section_attendance_dashboard( $section_id, $user_id );

		if ( is_wp_error( $attendance ) ) {
			return $attendance;
		}

		$enrollments = $this->enrollments->list_enrollments(
			array(
				'section_id' => $section_id,
			)
		);
		$student_ids = array_map(
			static function ( $enrollment ) {
				return (int) ( $enrollment['student_user_id'] ?? 0 );
			},
			$enrollments
		);
		$snapshots   = $this->results->get_section_student_snapshot_map( $user_id, $section_id, $student_ids );

		$roster = array();

		foreach ( $enrollments as $enrollment ) {
			$student_id      = (int) $enrollment['student_user_id'];
			$snapshot        = $snapshots[ $student_id ] ?? array();
			$attendance_info = $attendance['attendance_summary_map'][ $student_id ] ?? array(
				'attendance_percentage' => 0,
				'eligible_sessions'     => 0,
			);

			$roster[] = array(
				'student_user_id'       => $student_id,
				'student_name'          => $enrollment['student_name'],
				'student_email'         => $enrollment['student_email'],
				'enrollment_status'     => $enrollment['status'],
				'enrolled_at'           => $enrollment['enrolled_at'],
				'attendance_percentage' => (float) ( $attendance_info['attendance_percentage'] ?? 0 ),
				'eligible_sessions'     => (int) ( $attendance_info['eligible_sessions'] ?? 0 ),
				'total_score'           => ! empty( $snapshot['can_show_total'] ) ? (float) ( $snapshot['total_score'] ?? 0 ) : null,
				'letter_grade'          => ! empty( $snapshot['can_show_grade'] ) ? (string) ( $snapshot['letter_grade'] ?? '' ) : '',
			);
		}

		usort(
			$roster,
			static function ( $left, $right ) {
				return strcasecmp( $left['student_name'], $right['student_name'] );
			}
		);

		return array(
			'section'    => $section,
			'attendance' => $attendance,
			'gradebook'  => array(),
			'roster'     => $roster,
			'term'       => ! empty( $section['term_id'] ) ? $this->terms->get_term( (int) $section['term_id'] ) : null,
		);
	}

	public function sanitize_filters( array $filters = array() ) {
		$academic_year = sanitize_text_field( $filters['academic_year'] ?? '' );
		$term_id       = absint( $filters['term_id'] ?? 0 );
		$section_status = sanitize_key( $filters['section_status'] ?? '' );
		$search        = sanitize_text_field( $filters['search'] ?? '' );

		if ( ! in_array( $section_status, self::SECTION_STATUSES, true ) ) {
			$section_status = '';
		}

		return array(
			'academic_year'  => $academic_year,
			'term_id'        => $term_id,
			'section_status' => $section_status,
			'search'         => $search,
		);
	}
}
