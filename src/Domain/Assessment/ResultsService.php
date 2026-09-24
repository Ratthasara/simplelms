<?php

namespace SimpleLMS\Domain\Assessment;

use SimpleLMS\Domain\Academic\AttendanceService;

defined( 'ABSPATH' ) || exit;

class ResultsService {
	/**
	 * @var AssessmentService
	 */
	private $assessments;

	/**
	 * @var AttendanceService
	 */
	private $attendance;

	/**
	 * @var GradeScaleService
	 */
	private $grade_scale;

	/**
	 * @var array<int, array<string, mixed>>
	 */
	private $context_cache = array();

	public function __construct( AssessmentService $assessments, AttendanceService $attendance, GradeScaleService $grade_scale ) {
		$this->assessments  = $assessments;
		$this->attendance   = $attendance;
		$this->grade_scale  = $grade_scale;
	}

	public function get_attendance_requirement_percentage() {
		return 0.0;
	}

	public function get_attendance_participation_weight() {
		return 10.0;
	}

	public function get_results_controlled_weight_cap() {
		return 90.0;
	}

	public function get_assignment_weight_total( array $assignments ) {
		$total = 0.0;

		foreach ( $assignments as $assignment ) {
			$total += max( 0, (float) ( $assignment['weight'] ?? 0 ) );
		}

		return round( $total, 2 );
	}

	public function get_section_assignments( $viewer_user_id, $subject_id, $limit = 100 ) {
		return $this->assessments->get_section_assignments( $viewer_user_id, $subject_id, $limit );
	}

	public function get_section_context( $viewer_user_id, $subject_id ) {
		$viewer_user_id = absint( $viewer_user_id ?: get_current_user_id() );
		$subject_id     = absint( $subject_id );
		$cache_key      = $viewer_user_id . ':' . $subject_id;

		if ( isset( $this->context_cache[ $cache_key ] ) ) {
			return $this->context_cache[ $cache_key ];
		}

		$this->context_cache[ $cache_key ] = array(
			'assignments'            => $this->get_section_assignments( $viewer_user_id, $subject_id ),
			'attendance_summary_map' => $this->attendance->get_student_attendance_summary_map( $subject_id ),
		);

		return $this->context_cache[ $cache_key ];
	}

	public function get_student_result_snapshot( $viewer_user_id, $subject_id, $student_user_id, ?array $assignments = null, ?array $attendance_summary = null ) {
		$subject_id      = absint( $subject_id );
		$student_user_id = absint( $student_user_id );

		if ( null === $assignments || null === $attendance_summary ) {
			$context            = $this->get_section_context( $viewer_user_id, $subject_id );
			$assignments        = null === $assignments ? (array) ( $context['assignments'] ?? array() ) : $assignments;
			$attendance_summary = null === $attendance_summary ? (array) ( $context['attendance_summary_map'][ $student_user_id ] ?? array() ) : $attendance_summary;
		}

		$attendance_weight      = $this->get_attendance_participation_weight();
		$assignment_weight_cap  = $this->get_results_controlled_weight_cap();
		$attendance_percentage  = max( 0.0, min( 100.0, (float) ( $attendance_summary['attendance_percentage'] ?? 0 ) ) );
		$attendance_auto        = round( $attendance_percentage * ( $attendance_weight / 100 ), 2 );
		$override               = $this->get_subject_results_override( $subject_id, $student_user_id );
		$attendance_value       = null !== $override['attendance_score'] ? (float) $override['attendance_score'] : $attendance_auto;
		$assignment_score       = null !== $override['assignment_score'] ? (float) $override['assignment_score'] : null;
		$final_exam_score       = null !== $override['final_exam_score'] ? (float) $override['final_exam_score'] : null;
		$assignment_weight_total = 0.0;
		$assignment_total       = 0.0;
		$assignment_calculated_total = 0.0;
		$rows                   = array();
		$all_assignments_ready  = true;

		foreach ( $assignments as $assignment ) {
			$weight = max( 0, (float) ( $assignment['weight'] ?? 0 ) );
			$assignment_weight_total += $weight;

			$submission = $assignment['submission'] ?? null;

			if ( ! is_array( $submission ) ) {
				$submission = $this->assessments->get_submission( (int) $assignment['id'], $student_user_id );
			}

			$explanation = $this->explain_assignment_contribution( $assignment, $submission );
			$contribution = $explanation['contribution'];

			if ( $weight > 0 && null === $contribution ) {
				$all_assignments_ready = false;
			}

			if ( null !== $contribution ) {
				$assignment_calculated_total += $contribution;
			}

			$rows[] = array(
				'label'                => $assignment['title'],
				'weight'               => $weight,
				'points'               => $explanation['points'],
				'score'                => $explanation['score'],
				'grade'                => $explanation['grade'],
				'raw_contribution'     => $explanation['raw_contribution'],
				'contribution'         => $explanation['contribution'],
				'late_days'            => (int) $explanation['late_days'],
				'late_multiplier'      => (float) $explanation['late_multiplier'],
				'late_penalty_percent' => (float) $explanation['late_penalty_percent'],
				'late_policy_label'    => $explanation['late_policy_label'],
				'is_late'              => (bool) $explanation['is_late'],
				'is_over_late_limit'   => (bool) $explanation['is_over_late_limit'],
				'is_missing'           => $explanation['is_missing'],
				'is_pending'           => $explanation['is_pending'],
				'status_label'         => $explanation['status_label'],
			);
		}

		$assignment_calculated_total = round( $assignment_calculated_total, 2 );

		if ( null !== $assignment_score ) {
			$assignment_total      = round( $assignment_score, 2 );
			$all_assignments_ready = true;
		} else {
			$assignment_total = $assignment_calculated_total;
		}

		$rows[] = array(
			'label'        => __( 'Attendance and participation', 'simple-lms' ),
			'weight'       => $attendance_weight,
			'grade'        => $attendance_weight > 0 ? round( ( $attendance_value / $attendance_weight ) * 100, 2 ) : null,
			'contribution' => round( $attendance_value, 2 ),
		);

		$remaining_exam_weight = max( 0.0, $assignment_weight_cap - min( $assignment_weight_cap, $assignment_weight_total ) );

		if ( $remaining_exam_weight > 0 || null !== $final_exam_score ) {
			$rows[] = array(
				'label'        => __( 'Final Exam', 'simple-lms' ),
				'weight'       => round( $remaining_exam_weight, 2 ),
				'grade'        => ( null !== $final_exam_score && $remaining_exam_weight > 0 ) ? round( ( $final_exam_score / $remaining_exam_weight ) * 100, 2 ) : null,
				'contribution' => null !== $final_exam_score ? round( $final_exam_score, 2 ) : null,
			);
		}

		$total_score    = round( $assignment_total + $attendance_value + ( null !== $final_exam_score ? $final_exam_score : 0 ), 2 );
		$can_show_total = $all_assignments_ready && ( $remaining_exam_weight <= 0 || null !== $final_exam_score );
		$can_show_grade = $can_show_total;
		$grade_summary  = $can_show_grade ? $this->grade_scale->resolve( $total_score ) : array(
			'letter' => '',
			'points' => null,
		);

		return array(
			'rows'                   => $rows,
			'assignment_total'       => round( $assignment_total, 2 ),
			'assignment_calculated_total' => round( $assignment_calculated_total, 2 ),
			'assignment_score'       => null !== $assignment_score ? round( $assignment_score, 2 ) : null,
			'assignment_score_is_override' => null !== $assignment_score,
			'assignment_weight_total' => round( $assignment_weight_total, 2 ),
			'attendance_auto'        => round( $attendance_auto, 2 ),
			'attendance_value'       => round( $attendance_value, 2 ),
			'final_exam_score'       => null !== $final_exam_score ? round( $final_exam_score, 2 ) : null,
			'remaining_exam_weight'  => round( $remaining_exam_weight, 2 ),
			'total_score'            => $total_score,
			'can_show_total'         => $can_show_total,
			'can_show_grade'         => $can_show_grade,
			'all_assignments_ready'  => $all_assignments_ready,
			'letter_grade'           => (string) $grade_summary['letter'],
			'grade_points'           => null !== $grade_summary['points'] ? (float) $grade_summary['points'] : null,
		);
	}

	public function get_section_student_snapshot_map( $viewer_user_id, $subject_id, array $student_ids ) {
		$context   = $this->get_section_context( $viewer_user_id, $subject_id );
		$map       = array();
		$assignments = (array) ( $context['assignments'] ?? array() );
		$attendance_map = (array) ( $context['attendance_summary_map'] ?? array() );

		foreach ( $student_ids as $student_id ) {
			$student_id = absint( $student_id );

			if ( ! $student_id ) {
				continue;
			}

			$map[ $student_id ] = $this->get_student_result_snapshot(
				$viewer_user_id,
				$subject_id,
				$student_id,
				$assignments,
				(array) ( $attendance_map[ $student_id ] ?? array() )
			);
		}

		return $map;
	}

	public function get_results_weight_summary( array $assignments ) {
		$attendance_weight       = $this->get_attendance_participation_weight();
		$assignment_weight_cap   = $this->get_results_controlled_weight_cap();
		$assignment_weight_total = $this->get_assignment_weight_total( $assignments );
		$final_exam_weight       = max( 0.0, $assignment_weight_cap - min( $assignment_weight_cap, $assignment_weight_total ) );
		$total_planned_weight    = $attendance_weight + min( $assignment_weight_cap, $assignment_weight_total ) + $final_exam_weight;

		return array(
			'attendance_weight'       => round( $attendance_weight, 2 ),
			'assignment_weight_cap'   => round( $assignment_weight_cap, 2 ),
			'assignment_weight_total' => round( $assignment_weight_total, 2 ),
			'final_exam_weight'       => round( $final_exam_weight, 2 ),
			'total_planned_weight'    => round( $total_planned_weight, 2 ),
			'over_assignment_cap'     => $assignment_weight_total > $assignment_weight_cap,
			'over_cap_amount'         => round( max( 0.0, $assignment_weight_total - $assignment_weight_cap ), 2 ),
		);
	}

	public function get_assignment_weight_breakdown( array $assignments ) {
		$rows = array();

		foreach ( $assignments as $assignment ) {
			$weight = max( 0.0, (float) ( $assignment['weight'] ?? 0 ) );
			$points = (float) ( $assignment['points'] ?? 0 );
			$rows[] = array(
				'id'                         => absint( $assignment['id'] ?? 0 ),
				'title'                      => (string) ( $assignment['title'] ?? __( 'Assignment', 'simple-lms' ) ),
				'weight'                     => round( $weight, 2 ),
				'points'                     => AssignmentPointPolicy::normalize_max_points( $points ),
				'original_points'            => $points,
				'has_decimal_max_points'     => AssignmentPointPolicy::has_decimal_max_points( $points ),
				'counts_toward_results'      => $weight > 0,
				'counts_toward_results_label' => $weight > 0 ? __( 'Counts toward Results', 'simple-lms' ) : __( 'Does not affect Results', 'simple-lms' ),
			);
		}

		return $rows;
	}

	public function explain_assignment_contribution( array $assignment, $submission ) {
		$points       = AssignmentPointPolicy::normalize_max_points( $assignment['points'] ?? 0 );
		$weight       = max( 0.0, (float) ( $assignment['weight'] ?? 0 ) );
		$has_score    = ! empty( $submission ) && isset( $submission['score'] ) && null !== $submission['score'];
		$score        = $has_score ? (float) $submission['score'] : null;
		$grade        = null;
		$raw          = null;
		$contribution = null;

		if ( null !== $score && $points > 0 ) {
			$grade = round( ( $score / $points ) * 100, 2 );

			if ( $weight > 0 ) {
				$raw = round( ( $score / $points ) * $weight, 2 );
			}
		}

		$late_details = AssignmentLatePolicy::get_adjustment_details( $assignment, $submission );
		$is_missing   = empty( $submission );

		if ( $is_missing ) {
			$late_details['late_days']            = 0;
			$late_details['late_multiplier']      = 1.0;
			$late_details['late_penalty_percent'] = 0.0;
			$late_details['is_late']              = false;
			$late_details['is_over_late_limit']   = false;
			$late_details['policy_short_label']   = __( 'No submitted work yet', 'simple-lms' );
		}

		if ( null !== $raw ) {
			$contribution = round( $raw * (float) $late_details['late_multiplier'], 2 );
		}

		$is_pending = ( ! $is_missing && null === $score ) || ( $weight > 0 && null === $contribution );

		if ( $is_missing ) {
			$status_label = __( 'Not submitted', 'simple-lms' );
		} elseif ( null === $score ) {
			$status_label = __( 'Pending grade', 'simple-lms' );
		} elseif ( ! empty( $late_details['is_late'] ) ) {
			$status_label = __( 'Graded late', 'simple-lms' );
		} else {
			$status_label = __( 'Graded', 'simple-lms' );
		}

		return array(
			'score'                => null !== $score ? round( $score, 2 ) : null,
			'points'               => round( $points, 2 ),
			'weight'               => round( $weight, 2 ),
			'grade'                => $grade,
			'raw_contribution'     => $raw,
			'contribution'         => $contribution,
			'late_days'            => (int) $late_details['late_days'],
			'late_multiplier'      => (float) $late_details['late_multiplier'],
			'late_penalty_percent' => (float) $late_details['late_penalty_percent'],
			'late_policy_label'    => $late_details['policy_short_label'],
			'is_late'              => (bool) $late_details['is_late'],
			'is_over_late_limit'   => (bool) $late_details['is_over_late_limit'],
			'is_missing'           => $is_missing,
			'is_pending'           => $is_pending,
			'status_label'         => $status_label,
		);
	}

	private function get_subject_results_override( $subject_id, $student_user_id ) {
		$all_overrides = get_post_meta( absint( $subject_id ), '_slms_subject_results_overrides', true );
		$all_overrides = is_array( $all_overrides ) ? $all_overrides : array();
		$student_key   = (string) absint( $student_user_id );
		$override      = isset( $all_overrides[ $student_key ] ) && is_array( $all_overrides[ $student_key ] ) ? $all_overrides[ $student_key ] : array();

		return array(
			'attendance_score' => isset( $override['attendance_score'] ) && '' !== (string) $override['attendance_score'] ? (float) $override['attendance_score'] : null,
			'assignment_score' => isset( $override['assignment_score'] ) && '' !== (string) $override['assignment_score'] ? (float) $override['assignment_score'] : null,
			'final_exam_score' => isset( $override['final_exam_score'] ) && '' !== (string) $override['final_exam_score'] ? (float) $override['final_exam_score'] : null,
		);
	}

	private function calculate_assignment_raw_weighted_contribution( array $assignment, $submission ) {
		if ( empty( $submission ) || ! isset( $submission['score'] ) || null === $submission['score'] ) {
			return null;
		}

		$points = AssignmentPointPolicy::normalize_max_points( $assignment['points'] ?? 0 );
		$weight = (float) ( $assignment['weight'] ?? 0 );

		if ( $points <= 0 || $weight <= 0 ) {
			return null;
		}

		return ( (float) $submission['score'] / $points ) * $weight;
	}

	private function calculate_assignment_weighted_contribution( array $assignment, $submission ) {
		$raw_contribution = $this->calculate_assignment_raw_weighted_contribution( $assignment, $submission );

		if ( null === $raw_contribution ) {
			return null;
		}

		$late_details = AssignmentLatePolicy::get_adjustment_details( $assignment, $submission );

		return $raw_contribution * (float) $late_details['late_multiplier'];
	}
}
