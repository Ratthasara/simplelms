<?php

namespace SimpleLMS\Domain\Assessment;

use SimpleLMS\Core\AcademicClock;
use SimpleLMS\Domain\Academic\EnrollmentService;
use SimpleLMS\Domain\Users\RoleManager;
use SimpleLMS\Infrastructure\Database\Schema;
use SimpleLMS\Infrastructure\Logging\AuditLogger;
use WP_Error;
use WP_Query;

defined( 'ABSPATH' ) || exit;

class AssessmentService {
	const WORKFLOW_STATUSES   = array( 'submitted', 'resubmitted', 'graded' );
	const SUBMISSION_STATUSES = array( 'submitted', 'resubmitted', 'graded', 'late' );

	/**
	 * @var EnrollmentService
	 */
	private $enrollments;

	/**
	 * @var AuditLogger
	 */
	private $audit_logger;

	public function __construct( EnrollmentService $enrollments, AuditLogger $audit_logger ) {
		$this->enrollments  = $enrollments;
		$this->audit_logger = $audit_logger;
	}

	public function get_assignment( $assignment_id, $user_id = 0 ) {
		$assignment_id = absint( $assignment_id );
		$user_id       = absint( $user_id ?: get_current_user_id() );
		$post          = get_post( $assignment_id );

		if ( ! $post || 'slms_assignment' !== $post->post_type ) {
			return null;
		}

		$section_id = absint( get_post_meta( $assignment_id, '_slms_section_id', true ) );

		if ( ! $this->enrollments->user_can_learn_section( $user_id, $section_id ) && ! $this->user_can_grade_section( $user_id, $section_id ) ) {
			return null;
		}

		return $this->format_assignment( $post, $user_id );
	}

	public function list_assignments_for_user( $user_id = 0, array $args = array() ) {
		$user_id       = absint( $user_id ?: get_current_user_id() );
		$role          = RoleManager::get_primary_role( $user_id );
		$accessible_ids = $this->enrollments->get_learning_section_ids_for_user( $user_id );
		$post_statuses = in_array( $role, array( 'administrator', 'officer', 'lecturer' ), true ) ? array( 'publish', 'private', 'draft', 'pending' ) : array( 'publish' );
		$per_page      = max( 1, min( 100, absint( $args['per_page'] ?? 20 ) ) );
		$paged         = max( 1, absint( $args['page'] ?? 1 ) );
		$meta_query    = array();

		if ( ! empty( $args['section_id'] ) ) {
			$meta_query[] = array(
				'key'   => '_slms_section_id',
				'value' => absint( $args['section_id'] ),
			);
		}

		if ( ! in_array( $role, array( 'administrator', 'officer' ), true ) ) {
			if ( empty( $accessible_ids ) ) {
				return array(
					'items'       => array(),
					'total'       => 0,
					'total_pages' => 0,
				);
			}

			$meta_query[] = array(
				'key'     => '_slms_section_id',
				'value'   => array_map( 'absint', $accessible_ids ),
				'compare' => 'IN',
			);
		}

		if ( ! empty( $args['due_filter'] ) ) {
			$now = AcademicClock::mysql();

			if ( 'upcoming' === $args['due_filter'] ) {
				$meta_query[] = array(
					'key'     => '_slms_due_at',
					'value'   => $now,
					'compare' => '>=',
					'type'    => 'DATETIME',
				);
			} elseif ( 'overdue' === $args['due_filter'] ) {
				$meta_query[] = array(
					'key'     => '_slms_due_at',
					'value'   => $now,
					'compare' => '<',
					'type'    => 'DATETIME',
				);
			}
		}

		$query_args = array(
			'post_type'      => 'slms_assignment',
			'post_status'    => $post_statuses,
			'posts_per_page' => $per_page,
			'paged'          => $paged,
			'orderby'        => 'date',
			'order'          => 'DESC',
			's'              => sanitize_text_field( $args['search'] ?? '' ),
			'meta_query'     => $meta_query,
		);

		if ( 'upcoming' === ( $args['due_filter'] ?? '' ) ) {
			$query_args['meta_key'] = '_slms_due_at';
			$query_args['orderby']  = 'meta_value';
			$query_args['order']    = 'ASC';
			$query_args['meta_type'] = 'DATETIME';
		}

		$query = new WP_Query( $query_args );

		$items = array();

		foreach ( $query->posts as $post ) {
			$section_id = absint( get_post_meta( $post->ID, '_slms_section_id', true ) );

			if ( ! $this->user_can_view_assignment_post( $user_id, $section_id, $post ) ) {
				continue;
			}

			$items[] = $this->format_assignment( $post, $user_id );
		}

		return array(
			'items'       => $items,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
		);
	}

	public function get_recent_assignments( $user_id = 0, $limit = 5 ) {
		$results = $this->list_assignments_for_user(
			$user_id,
			array(
				'per_page' => max( 1, min( 20, absint( $limit ) ) ),
				'page'     => 1,
			)
		);

		return array_slice( $results['items'], 0, $limit );
	}

	public function get_section_assignments( $user_id, $section_id, $limit = 20 ) {
		$results = $this->list_assignments_for_user(
			$user_id,
			array(
				'section_id' => absint( $section_id ),
				'per_page'   => max( 1, min( 100, absint( $limit ) ) ),
				'page'       => 1,
			)
		);

		return $results['items'];
	}

	public function count_assignments( $section_id = 0 ) {
		$args = array(
			'post_type'      => 'slms_assignment',
			'post_status'    => array( 'publish', 'private', 'draft', 'pending' ),
			'posts_per_page' => 1,
			'fields'         => 'ids',
		);

		if ( $section_id ) {
			$args['meta_query'] = array(
				array(
					'key'   => '_slms_section_id',
					'value' => absint( $section_id ),
				),
			);
		}

		$query = new WP_Query( $args );

		return (int) $query->found_posts;
	}

	public function count_submissions( $status = '', $grader_user_id = 0 ) {
		global $wpdb;

		$where  = 'WHERE 1=1';
		$params = array();
		$table  = Schema::table( 'assignment_submissions' );

		if ( 'late' === $status ) {
			$postmeta = $wpdb->postmeta;
			$where   .= " AND EXISTS (SELECT 1 FROM {$postmeta} pm WHERE pm.post_id = s.assignment_id AND pm.meta_key = '_slms_due_at' AND pm.meta_value <> '' AND s.submitted_at > pm.meta_value)";
		} elseif ( $status && in_array( $status, self::WORKFLOW_STATUSES, true ) ) {
			$where   .= ' AND s.status = %s';
			$params[] = $status;
		}

		if ( $grader_user_id ) {
			$section_ids = $this->enrollments->get_teaching_section_ids_for_user( $grader_user_id );

			if ( empty( $section_ids ) ) {
				return 0;
			}

			$where .= ' AND s.section_id IN (' . implode( ',', array_fill( 0, count( $section_ids ), '%d' ) ) . ')';
			$params = array_merge( $params, $section_ids );
		}

		$sql = 'SELECT COUNT(*) FROM ' . $table . ' s ' . $where;

		return (int) $wpdb->get_var( $params ? $wpdb->prepare( $sql, $params ) : $sql );
	}

	public function get_submission( $assignment_id, $student_user_id ) {
		global $wpdb;

		$assignment_id   = absint( $assignment_id );
		$student_user_id = absint( $student_user_id );

		if ( ! $assignment_id || ! $student_user_id ) {
			return null;
		}

		$submission = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::table( 'assignment_submissions' ) . ' WHERE assignment_id = %d AND student_user_id = %d LIMIT 1',
				$assignment_id,
				$student_user_id
			),
			ARRAY_A
		);

		return $submission ? $this->decorate_submission( $submission ) : null;
	}

	public function list_submissions( $assignment_id, array $args = array() ) {
		global $wpdb;

		$assignment_id = absint( $assignment_id );
		$table         = Schema::table( 'assignment_submissions' );
		$users         = $wpdb->users;
		$where         = 'WHERE s.assignment_id = %d';
		$params        = array( $assignment_id );

		$requested_status = sanitize_key( (string) ( $args['status'] ?? '' ) );

		if ( $requested_status && 'late' !== $requested_status && in_array( $requested_status, self::WORKFLOW_STATUSES, true ) ) {
			$where   .= ' AND s.status = %s';
			$params[] = $requested_status;
		}

		$sql = "
			SELECT s.*, u.display_name, u.user_email
			FROM {$table} s
			INNER JOIN {$users} u ON u.ID = s.student_user_id
			{$where}
			ORDER BY s.updated_at DESC, s.id DESC
		";

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		$rows = array_map( array( $this, 'decorate_submission' ), $rows );

		if ( 'late' === $requested_status ) {
			$rows = array_values(
				array_filter(
					$rows,
					static function ( $row ) {
						return ! empty( $row['is_late'] );
					}
				)
			);
		}

		return $rows;
	}

	public function get_grading_queue( $user_id = 0, $limit = 15 ) {
		$user_id     = absint( $user_id ?: get_current_user_id() );
		$section_ids = $this->enrollments->get_teaching_section_ids_for_user( $user_id );

		if ( empty( $section_ids ) ) {
			return array();
		}

		global $wpdb;
		$table  = Schema::table( 'assignment_submissions' );
		$users  = $wpdb->users;
		$in_sql = implode( ',', array_fill( 0, count( $section_ids ), '%d' ) );

		$sql = "
			SELECT s.*, u.display_name, u.user_email
			FROM {$table} s
			INNER JOIN {$users} u ON u.ID = s.student_user_id
			WHERE s.section_id IN ({$in_sql}) AND s.status IN (%s, %s, %s)
			ORDER BY s.submitted_at ASC
			LIMIT %d
		";

		$params = array_merge( $section_ids, array( 'submitted', 'resubmitted', 'late', max( 1, min( 50, absint( $limit ) ) ) ) );

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		return array_map( array( $this, 'decorate_submission' ), $rows );
	}

	public function submit_assignment( $assignment_id, $student_user_id, array $data ) {
		global $wpdb;

		$assignment_id   = absint( $assignment_id );
		$student_user_id = absint( $student_user_id );
		$assignment      = get_post( $assignment_id );

		if ( ! $assignment || 'slms_assignment' !== $assignment->post_type ) {
			return new WP_Error( 'slms_invalid_assignment', __( 'Invalid assignment.', 'simple-lms' ) );
		}

		$section_id     = absint( get_post_meta( $assignment_id, '_slms_section_id', true ) );
		$submitter_type = $this->get_assignment_submitter_type( $assignment_id, $student_user_id );

		if ( '' === $submitter_type ) {
			return new WP_Error( 'slms_forbidden_assignment', __( 'You are not enrolled as a submitter for this assignment.', 'simple-lms' ) );
		}

		$text            = wp_kses_post( $data['submission_text'] ?? '' );
		$attachment_url  = esc_url_raw( $data['attachment_url'] ?? '' );
		$attachment_name = sanitize_text_field( $data['attachment_name'] ?? '' );
		$submission_type = $this->normalize_submission_type( get_post_meta( $assignment_id, '_slms_submission_type', true ), 'text_file' );
		$has_text        = '' !== trim( wp_strip_all_tags( $text ) );
		$has_file        = '' !== trim( $attachment_url );

		if ( 'text' === $submission_type ) {
			$attachment_url  = '';
			$attachment_name = '';

			if ( ! $has_text ) {
				return new WP_Error( 'slms_empty_submission', __( 'Please type your answer before submitting.', 'simple-lms' ) );
			}
		} elseif ( 'file' === $submission_type ) {
			if ( ! $has_file ) {
				return new WP_Error( 'slms_missing_submission_file', __( 'Please upload a file for this assignment.', 'simple-lms' ) );
			}
		} elseif ( ! $has_text && ! $has_file ) {
			return new WP_Error( 'slms_empty_submission', __( 'Please type an answer or upload a file before submitting.', 'simple-lms' ) );
		}

		$now         = AcademicClock::mysql();
		$existing    = $this->get_submission( $assignment_id, $student_user_id );
		$attempt     = ! empty( $existing['attempt_number'] ) ? (int) $existing['attempt_number'] + 1 : 1;

		if ( $existing && 'graded' === (string) ( $existing['status'] ?? '' ) ) {
			return new WP_Error( 'slms_submission_locked', __( 'This submission has already been graded and can no longer be edited.', 'simple-lms' ) );
		}

		$status = $existing ? 'resubmitted' : 'submitted';
		$table  = Schema::table( 'assignment_submissions' );
		$data   = array(
			'assignment_id'       => $assignment_id,
			'section_id'          => $section_id,
			'student_user_id'     => $student_user_id,
			'submitter_type'      => $submitter_type,
			'is_audit_submission' => 'auditor' === $submitter_type ? 1 : 0,
			'status'              => $status,
			'attempt_number'      => $attempt,
			'submission_text' => $text ?: null,
			'attachment_url'  => $attachment_url ?: null,
			'attachment_name' => $attachment_name ?: null,
			'score'           => null,
			'feedback'        => null,
			'graded_by'       => null,
			'submitted_at'    => $now,
			'graded_at'       => null,
			'created_at'      => $existing['created_at'] ?? $now,
			'updated_at'      => $now,
		);

		if ( $existing ) {
			$updated = $wpdb->update(
				$table,
				$data,
				array( 'id' => (int) $existing['id'] )
			);

			if ( false === $updated ) {
				return new WP_Error( 'slms_submission_update_failed', __( 'The submission could not be updated.', 'simple-lms' ) );
			}
		} else {
			$inserted = $wpdb->insert(
				$table,
				$data
			);

			if ( false === $inserted ) {
				return new WP_Error( 'slms_submission_insert_failed', __( 'The submission could not be saved.', 'simple-lms' ) );
			}
		}

		$this->audit_logger->log(
			'assignment_submitted',
			array(
				'object_type' => 'assignment_submission',
				'object_id'   => $existing ? (int) $existing['id'] : (int) $wpdb->insert_id,
				'message'     => 'An assignment submission was saved.',
				'context'     => array(
					'assignment_id'   => $assignment_id,
					'section_id'      => $section_id,
					'student_user_id' => $student_user_id,
					'status'          => $status,
					'attempt_number'  => $attempt,
				),
			)
		);

		return true;
	}

	public function grade_submission( $assignment_id, $student_user_id, array $data ) {
		global $wpdb;

		$assignment_id   = absint( $assignment_id );
		$student_user_id = absint( $student_user_id );
		$grader_user_id  = absint( $data['graded_by'] ?? get_current_user_id() );
		$submission      = $this->get_submission( $assignment_id, $student_user_id );

		if ( ! $submission ) {
			return new WP_Error( 'slms_missing_submission', __( 'No submission was found for grading.', 'simple-lms' ) );
		}

		if ( ! $this->user_can_grade_section( $grader_user_id, (int) $submission['section_id'] ) ) {
			return new WP_Error( 'slms_forbidden_grade', __( 'You do not have permission to grade this submission.', 'simple-lms' ) );
		}

		$raw_score      = isset( $data['score'] ) ? trim( (string) $data['score'] ) : '';
		$has_score      = '' !== $raw_score;
		$score          = $has_score ? (float) $raw_score : null;
		$points         = AssignmentPointPolicy::normalize_max_points( get_post_meta( $assignment_id, '_slms_points', true ) );
		$feedback       = wp_kses_post( $data['feedback'] ?? '' );
		$now            = AcademicClock::mysql();

		if ( $has_score && ( $score < 0 || ( $points > 0 && $score > $points ) ) ) {
			return new WP_Error( 'slms_invalid_score', __( 'The score must stay within the assignment point value.', 'simple-lms' ) );
		}

		$update_data = array(
			'feedback'   => $feedback ?: null,
			'updated_at' => $now,
		);

		if ( $has_score ) {
			$update_data['status']    = 'graded';
			$update_data['score']     = $score;
			$update_data['graded_by'] = $grader_user_id;
			$update_data['graded_at'] = $now;
		}

		$updated = $wpdb->update(
			Schema::table( 'assignment_submissions' ),
			$update_data,
			array( 'id' => (int) $submission['id'] )
		);

		if ( false === $updated ) {
			return new WP_Error( 'slms_grade_update_failed', __( 'The submission grade could not be saved.', 'simple-lms' ) );
		}

		$this->audit_logger->log(
			$has_score ? 'assignment_graded' : 'assignment_feedback_updated',
			array(
				'object_type' => 'assignment_submission',
				'object_id'   => (int) $submission['id'],
				'message'     => $has_score ? 'An assignment submission was graded.' : 'Assignment submission feedback was updated without changing the score.',
				'context'     => array(
					'assignment_id'   => $assignment_id,
					'student_user_id' => $student_user_id,
					'grader_user_id'  => $grader_user_id,
					'score'           => $has_score ? $score : ( isset( $submission['score'] ) ? $submission['score'] : null ),
					'score_changed'   => $has_score,
				),
			)
		);

		return $this->get_submission( $assignment_id, $student_user_id );
	}

	private function format_assignment( $post, $user_id ) {
		$assignment_id = (int) $post->ID;
		$section_id    = absint( get_post_meta( $assignment_id, '_slms_section_id', true ) );
		$section       = $this->enrollments->get_section_summary( $section_id );
		$user_role     = RoleManager::get_primary_role( $user_id );
		$submission    = $this->can_user_submit_assignment( $assignment_id, $user_id ) ? $this->get_submission( $assignment_id, $user_id ) : null;
		$due_at        = AssignmentLatePolicy::normalize_datetime( sanitize_text_field( get_post_meta( $assignment_id, '_slms_due_at', true ) ) );

		return array(
			'id'                 => $assignment_id,
			'title'              => $post->post_title,
			'excerpt'            => wp_trim_words( wp_strip_all_tags( $post->post_excerpt ?: $post->post_content ), 30 ),
			'instructions'       => $post->post_content,
			'post_status'        => $post->post_status,
			'section_id'         => $section_id,
			'section_title'      => $section['title'] ?? '',
			'subject_title'      => $section['subject_title'] ?? '',
			'section_code'       => $section['section_code'] ?? '',
			'due_at'             => $due_at,
			'due_at_display'     => AssignmentLatePolicy::format_datetime_display( $due_at ),
			'weight'             => (float) get_post_meta( $assignment_id, '_slms_assignment_weight', true ),
			'points'             => AssignmentPointPolicy::normalize_max_points( get_post_meta( $assignment_id, '_slms_points', true ) ),
			'submission_type'    => $this->normalize_submission_type( get_post_meta( $assignment_id, '_slms_submission_type', true ), 'text_file' ),
			'allow_late'         => (int) get_post_meta( $assignment_id, '_slms_allow_late', true ),
			'max_attempts'       => absint( get_post_meta( $assignment_id, '_slms_max_attempts', true ) ),
			'submission'         => $submission,
			'submission_count'   => $this->count_submissions_for_assignment( $assignment_id ),
			'graded_count'       => $this->count_submissions_for_assignment( $assignment_id, 'graded' ),
		);
	}



	public function can_user_submit_assignment( $assignment_id, $user_id ) {
		return '' !== $this->get_assignment_submitter_type( $assignment_id, $user_id );
	}

	public function can_user_grade_assignment( $assignment_id, $user_id ) {
		$assignment_id = absint( $assignment_id );
		$user_id       = absint( $user_id );

		if ( ! $assignment_id || ! $user_id ) {
			return false;
		}

		$section_id = absint( get_post_meta( $assignment_id, '_slms_section_id', true ) );

		return $section_id && $this->user_can_grade_section( $user_id, $section_id );
	}

	private function get_assignment_submitter_type( $assignment_id, $user_id ) {
		$assignment_id = absint( $assignment_id );
		$user_id       = absint( $user_id );

		if ( ! $assignment_id || ! $user_id ) {
			return '';
		}

		$section_id = absint( get_post_meta( $assignment_id, '_slms_section_id', true ) );

		if ( ! $section_id ) {
			return '';
		}

		if ( 'student' === RoleManager::get_primary_role( $user_id ) && $this->enrollments->user_can_access_section( $user_id, $section_id ) ) {
			return 'student';
		}

		if ( $this->enrollments->user_can_audit_section( $user_id, $section_id ) ) {
			return 'auditor';
		}

		return '';
	}

	private function user_can_view_assignment_post( $user_id, $section_id, $post ) {
		if ( $this->user_can_grade_section( $user_id, $section_id ) ) {
			return true;
		}

		if ( ! $this->enrollments->user_can_learn_section( $user_id, $section_id ) ) {
			return false;
		}

		return $post && 'publish' === $post->post_status;
	}

	private function normalize_submission_type( $value, $default = 'text_file' ) {
		$type    = sanitize_key( (string) $value );
		$default = in_array( $default, array( 'file', 'text', 'text_file' ), true ) ? $default : 'text_file';

		return in_array( $type, array( 'file', 'text', 'text_file' ), true ) ? $type : $default;
	}

	private function count_submissions_for_assignment( $assignment_id, $status = '' ) {
		global $wpdb;

		$where  = 'WHERE assignment_id = %d';
		$params = array( absint( $assignment_id ) );

		if ( 'late' === $status ) {
			$due_at = AssignmentLatePolicy::normalize_datetime( get_post_meta( absint( $assignment_id ), '_slms_due_at', true ) );

			if ( '' === $due_at ) {
				return 0;
			}

			$where   .= ' AND submitted_at > %s';
			$params[] = $due_at;
		} elseif ( $status && in_array( $status, self::WORKFLOW_STATUSES, true ) ) {
			$where   .= ' AND status = %s';
			$params[] = $status;
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . Schema::table( 'assignment_submissions' ) . ' ' . $where,
				$params
			)
		);
	}

	public function decorate_submission( array $submission ) {
		$stored_status = sanitize_key( (string) ( $submission['status'] ?? 'submitted' ) );
		$status        = $stored_status;

		if ( 'late' === $status ) {
			$status = (int) ( $submission['attempt_number'] ?? 1 ) > 1 ? 'resubmitted' : 'submitted';
		}

		if ( ! in_array( $status, self::WORKFLOW_STATUSES, true ) ) {
			$status = 'submitted';
		}

		$assignment = array(
			'due_at' => get_post_meta( absint( $submission['assignment_id'] ?? 0 ), '_slms_due_at', true ),
		);
		$late_details = AssignmentLatePolicy::get_adjustment_details( $assignment, $submission );
		$is_graded    = 'graded' === $status;

		$submission['stored_status']       = $stored_status;
		$submission['status']              = $status;
		$submission['workflow_status']     = $status;
		$submission['display_status']      = $is_graded ? ( $late_details['is_late'] ? 'graded_late' : 'graded' ) : ( $late_details['is_late'] ? 'late' : $status );
		$submission['is_late']             = (bool) $late_details['is_late'];
		$submission['late_days']           = (int) $late_details['late_days'];
		$submission['late_multiplier']     = (float) $late_details['late_multiplier'];
		$submission['late_penalty_percent'] = (float) $late_details['late_penalty_percent'];
		$submission['is_over_late_limit']  = (bool) $late_details['is_over_late_limit'];

		return $submission;
	}

	private function user_can_grade_section( $user_id, $section_id ) {
		$role = RoleManager::get_primary_role( $user_id );

		if ( in_array( $role, array( 'administrator', 'officer' ), true ) ) {
			return true;
		}

		if ( 'lecturer' === $role ) {
			return $this->enrollments->user_can_teach_section( $user_id, $section_id );
		}

		return false;
	}
}
