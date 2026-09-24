<?php

namespace SimpleLMS\Domain\Assessment;

use SimpleLMS\Core\AcademicClock;
use SimpleLMS\Domain\Academic\AttendanceService;
use SimpleLMS\Domain\Academic\EnrollmentService;
use SimpleLMS\Domain\Users\RoleManager;
use SimpleLMS\Infrastructure\Database\Schema;
use SimpleLMS\Infrastructure\Logging\AuditLogger;
use WP_Error;
use WP_Query;

defined( 'ABSPATH' ) || exit;

class GradebookService {
	const CATEGORY_TYPES = array( 'attendance', 'participation', 'assignment', 'presentation', 'quiz', 'project', 'exam', 'custom' );
	const SCORING_METHODS = array( 'weighted_items', 'manual_total', 'attendance_rollup' );
	const ITEM_TYPES = array( 'manual', 'assignment', 'presentation', 'participation', 'quiz', 'project', 'exam', 'attendance' );

	/**
	 * @var EnrollmentService
	 */
	private $enrollments;

	/**
	 * @var AssessmentService
	 */
	private $assessments;

	/**
	 * @var AttendanceService
	 */
	private $attendance;

	/**
	 * @var AuditLogger
	 */
	private $audit_logger;

	public function __construct( EnrollmentService $enrollments, AssessmentService $assessments, AttendanceService $attendance, AuditLogger $audit_logger ) {
		$this->enrollments  = $enrollments;
		$this->assessments  = $assessments;
		$this->attendance   = $attendance;
		$this->audit_logger = $audit_logger;
	}

	public function get_gradebook( $section_id, $user_id = 0 ) {
		$section_id = absint( $section_id );
		$user_id    = absint( $user_id ?: get_current_user_id() );

		if ( ! $this->can_view_gradebook( $user_id, $section_id ) ) {
			return new WP_Error( 'slms_forbidden_gradebook', __( 'You do not have access to that gradebook.', 'simple-lms' ) );
		}

		$this->sync_linked_assignment_metadata( $section_id );

		$section    = $this->enrollments->get_section_summary( $section_id );
		$categories = $this->list_categories( $section_id );
		$items      = $this->list_items( $section_id );
		$students   = $this->get_gradebook_students( $section_id );
		$score_map  = $this->get_score_map( $section_id );
		$attendance_summary_map = $this->attendance->get_student_attendance_summary_map( $section_id, $students );

		$assignment_ids = array_filter(
			array_map(
				'absint',
				wp_list_pluck(
					array_filter(
						$items,
						static function ( $item ) {
							return 'assignment' === $item['item_type'] && ! empty( $item['linked_post_id'] );
						}
					),
					'linked_post_id'
				)
			)
		);

		$submission_map = $this->get_assignment_submission_map( $section_id, $assignment_ids );
		$items_by_category = array();
		$manual_items      = array();
		$assignment_items  = array();

		foreach ( $items as $item ) {
			$items_by_category[ $item['category_id'] ][] = $item;

			if ( 'assignment' === $item['item_type'] ) {
				$assignment_items[] = $item;
				continue;
			}

			if ( 'attendance' === $item['item_type'] ) {
				continue;
			}

			$manual_items[] = $item;
		}

		$weight_total = 0.0;

		foreach ( $categories as &$category ) {
			$category['items']             = $items_by_category[ $category['id'] ] ?? array();
			$category['item_weight_total'] = $this->sum_weights( $category['items'], 'weight_percent' );
			$weight_total                 += (float) $category['weight_percent'];
		}
		unset( $category );

		$student_rows = array();

		foreach ( $students as $student ) {
			$student_rows[] = $this->build_student_grade_row( $student, $categories, $score_map, $submission_map, $attendance_summary_map );
		}

		return array(
			'section'             => $section,
			'categories'          => $categories,
			'manual_items'        => $manual_items,
			'assignment_items'    => $assignment_items,
			'students'            => $student_rows,
			'attendance_summary_map' => $attendance_summary_map,
			'weight_total'        => round( $weight_total, 2 ),
			'is_weight_balanced'  => abs( $weight_total - 100 ) < 0.01,
			'assignment_options'  => $this->list_section_assignment_options( $section_id ),
			'score_sources'       => array(
				'assignment' => __( 'Assessment submissions', 'simple-lms' ),
				'attendance' => __( 'Attendance register rollup', 'simple-lms' ),
				'manual'     => __( 'Manual entry', 'simple-lms' ),
				'override'   => __( 'Manual override', 'simple-lms' ),
			),
		);
	}

	public function save_category( $section_id, array $data, $user_id = 0 ) {
		global $wpdb;

		$section_id  = absint( $section_id );
		$user_id     = absint( $user_id ?: get_current_user_id() );
		$category_id = absint( $data['category_id'] ?? 0 );

		if ( ! $this->can_manage_gradebook( $user_id, $section_id ) ) {
			return new WP_Error( 'slms_forbidden_gradebook', __( 'You do not have permission to manage that gradebook.', 'simple-lms' ) );
		}

		$title          = sanitize_text_field( $data['title'] ?? '' );
		$category_type  = sanitize_key( $data['category_type'] ?? 'custom' );
		$weight_percent = $this->normalize_weight( $data['weight_percent'] ?? 0 );
		$method         = sanitize_key( $data['scoring_method'] ?? 'weighted_items' );
		$publish        = ! empty( $data['publish_to_students'] ) ? 1 : 0;
		$sort_order     = isset( $data['sort_order'] ) ? (int) $data['sort_order'] : $this->get_next_category_sort_order( $section_id );

		if ( '' === $title ) {
			return new WP_Error( 'slms_missing_category_title', __( 'Please provide a category title.', 'simple-lms' ) );
		}

		if ( ! in_array( $category_type, self::CATEGORY_TYPES, true ) ) {
			$category_type = 'custom';
		}

		if ( ! in_array( $method, self::SCORING_METHODS, true ) ) {
			$method = 'weighted_items';
		}

		$table    = Schema::table( 'grade_categories' );
		$existing = $category_id ? $this->get_category_row( $category_id ) : null;
		$now      = AcademicClock::mysql();
		$payload  = array(
			'section_id'           => $section_id,
			'title'                => $title,
			'category_type'        => $category_type,
			'weight_percent'       => $weight_percent,
			'scoring_method'       => $method,
			'publish_to_students'  => $publish,
			'sort_order'           => $sort_order,
			'created_by'           => ! empty( $existing['created_by'] ) ? (int) $existing['created_by'] : $user_id,
			'updated_by'           => $user_id,
			'created_at'           => $existing['created_at'] ?? $now,
			'updated_at'           => $now,
		);

		if ( $existing ) {
			$updated = $wpdb->update( $table, $payload, array( 'id' => $category_id ) );

			if ( false === $updated ) {
				return new WP_Error( 'slms_category_update_failed', __( 'The grading category could not be updated.', 'simple-lms' ) );
			}
		} else {
			$inserted = $wpdb->insert( $table, $payload );

			if ( false === $inserted ) {
				return new WP_Error( 'slms_category_insert_failed', __( 'The grading category could not be created.', 'simple-lms' ) );
			}

			$category_id = (int) $wpdb->insert_id;
		}

		$this->audit_logger->log(
			$existing ? 'grade_category_updated' : 'grade_category_created',
			array(
				'object_type' => 'grade_category',
				'object_id'   => $category_id,
				'message'     => $existing ? 'A grading category was updated.' : 'A grading category was created.',
				'context'     => array(
					'section_id'      => $section_id,
					'weight_percent'  => $weight_percent,
					'category_type'   => $category_type,
					'scoring_method'  => $method,
				),
			)
		);

		return $category_id;
	}

	public function delete_category( $category_id, $user_id = 0 ) {
		global $wpdb;

		$category_id = absint( $category_id );
		$user_id     = absint( $user_id ?: get_current_user_id() );
		$category    = $this->get_category_row( $category_id );

		if ( ! $category ) {
			return new WP_Error( 'slms_invalid_grade_category', __( 'Invalid grading category.', 'simple-lms' ) );
		}

		if ( ! $this->can_manage_gradebook( $user_id, (int) $category['section_id'] ) ) {
			return new WP_Error( 'slms_forbidden_gradebook', __( 'You do not have permission to delete that grading category.', 'simple-lms' ) );
		}

		$item_ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM ' . Schema::table( 'grade_items' ) . ' WHERE category_id = %d',
				$category_id
			)
		);

		if ( $item_ids ) {
			$placeholders = implode( ',', array_fill( 0, count( $item_ids ), '%d' ) );
			$wpdb->query(
				$wpdb->prepare(
					'DELETE FROM ' . Schema::table( 'grade_scores' ) . " WHERE item_id IN ({$placeholders})",
					$item_ids
				)
			);
		}

		$wpdb->delete( Schema::table( 'grade_items' ), array( 'category_id' => $category_id ), array( '%d' ) );
		$deleted = $wpdb->delete( Schema::table( 'grade_categories' ), array( 'id' => $category_id ), array( '%d' ) );

		if ( false === $deleted ) {
			return new WP_Error( 'slms_category_delete_failed', __( 'The grading category could not be deleted.', 'simple-lms' ) );
		}

		$this->audit_logger->log(
			'grade_category_deleted',
			array(
				'object_type' => 'grade_category',
				'object_id'   => $category_id,
				'message'     => 'A grading category was deleted.',
				'context'     => array(
					'section_id' => (int) $category['section_id'],
				),
			)
		);

		return true;
	}

	public function save_item( $section_id, array $data, $user_id = 0 ) {
		global $wpdb;

		$section_id  = absint( $section_id );
		$user_id     = absint( $user_id ?: get_current_user_id() );
		$item_id     = absint( $data['item_id'] ?? 0 );
		$category_id = absint( $data['category_id'] ?? 0 );

		if ( ! $this->can_manage_gradebook( $user_id, $section_id ) ) {
			return new WP_Error( 'slms_forbidden_gradebook', __( 'You do not have permission to manage that gradebook.', 'simple-lms' ) );
		}

		$category = $this->get_category_row( $category_id );

		if ( ! $category || (int) $category['section_id'] !== $section_id ) {
			return new WP_Error( 'slms_invalid_grade_category', __( 'Please choose a valid grading category.', 'simple-lms' ) );
		}

		$item_type      = sanitize_key( $data['item_type'] ?? 'manual' );
		$linked_post_id = absint( $data['linked_post_id'] ?? 0 );
		$title          = sanitize_text_field( $data['title'] ?? '' );
		$weight_percent = $this->normalize_weight( $data['weight_percent'] ?? 0 );
		$max_points     = $this->normalize_points( $data['max_points'] ?? 100 );
		$publish        = ! empty( $data['publish_to_students'] ) ? 1 : 0;
		$sort_order     = isset( $data['sort_order'] ) ? (int) $data['sort_order'] : $this->get_next_item_sort_order( $category_id );
		$due_at         = '';

		if ( ! in_array( $item_type, self::ITEM_TYPES, true ) ) {
			$item_type = 'manual';
		}

		if ( 'assignment' === $item_type ) {
			if ( ! $linked_post_id || 'slms_assignment' !== get_post_type( $linked_post_id ) ) {
				return new WP_Error( 'slms_invalid_linked_assignment', __( 'Please choose a valid assignment to link.', 'simple-lms' ) );
			}

			if ( $section_id !== absint( get_post_meta( $linked_post_id, '_slms_section_id', true ) ) ) {
				return new WP_Error( 'slms_assignment_section_mismatch', __( 'That assignment belongs to a different section.', 'simple-lms' ) );
			}

			if ( '' === $title ) {
				$title = get_the_title( $linked_post_id );
			}

			$max_points = AssignmentPointPolicy::normalize_max_points( get_post_meta( $linked_post_id, '_slms_points', true ) ?: $max_points );
			$due_at     = sanitize_text_field( get_post_meta( $linked_post_id, '_slms_due_at', true ) );
		}

		if ( '' === $title ) {
			return new WP_Error( 'slms_missing_grade_item_title', __( 'Please provide an item title.', 'simple-lms' ) );
		}

		$table    = Schema::table( 'grade_items' );
		$existing = $item_id ? $this->get_item_row( $item_id ) : null;
		$now      = AcademicClock::mysql();
		$payload  = array(
			'category_id'          => $category_id,
			'section_id'           => $section_id,
			'title'                => $title,
			'item_type'            => $item_type,
			'linked_post_id'       => $linked_post_id ?: null,
			'weight_percent'       => $weight_percent,
			'max_points'           => $max_points,
			'publish_to_students'  => $publish,
			'due_at'               => $due_at ?: null,
			'sort_order'           => $sort_order,
			'created_by'           => ! empty( $existing['created_by'] ) ? (int) $existing['created_by'] : $user_id,
			'updated_by'           => $user_id,
			'created_at'           => $existing['created_at'] ?? $now,
			'updated_at'           => $now,
		);

		if ( $existing ) {
			$updated = $wpdb->update( $table, $payload, array( 'id' => $item_id ) );

			if ( false === $updated ) {
				return new WP_Error( 'slms_grade_item_update_failed', __( 'The grade item could not be updated.', 'simple-lms' ) );
			}
		} else {
			$inserted = $wpdb->insert( $table, $payload );

			if ( false === $inserted ) {
				return new WP_Error( 'slms_grade_item_insert_failed', __( 'The grade item could not be created.', 'simple-lms' ) );
			}

			$item_id = (int) $wpdb->insert_id;
		}

		$this->audit_logger->log(
			$existing ? 'grade_item_updated' : 'grade_item_created',
			array(
				'object_type' => 'grade_item',
				'object_id'   => $item_id,
				'message'     => $existing ? 'A grade item was updated.' : 'A grade item was created.',
				'context'     => array(
					'section_id'      => $section_id,
					'category_id'     => $category_id,
					'item_type'       => $item_type,
					'linked_post_id'  => $linked_post_id,
					'weight_percent'  => $weight_percent,
					'max_points'      => $max_points,
				),
			)
		);

		return $item_id;
	}

	public function delete_item( $item_id, $user_id = 0 ) {
		global $wpdb;

		$item_id = absint( $item_id );
		$user_id = absint( $user_id ?: get_current_user_id() );
		$item    = $this->get_item_row( $item_id );

		if ( ! $item ) {
			return new WP_Error( 'slms_invalid_grade_item', __( 'Invalid grade item.', 'simple-lms' ) );
		}

		if ( ! $this->can_manage_gradebook( $user_id, (int) $item['section_id'] ) ) {
			return new WP_Error( 'slms_forbidden_gradebook', __( 'You do not have permission to delete that grade item.', 'simple-lms' ) );
		}

		$wpdb->delete( Schema::table( 'grade_scores' ), array( 'item_id' => $item_id ), array( '%d' ) );
		$deleted = $wpdb->delete( Schema::table( 'grade_items' ), array( 'id' => $item_id ), array( '%d' ) );

		if ( false === $deleted ) {
			return new WP_Error( 'slms_grade_item_delete_failed', __( 'The grade item could not be deleted.', 'simple-lms' ) );
		}

		$this->audit_logger->log(
			'grade_item_deleted',
			array(
				'object_type' => 'grade_item',
				'object_id'   => $item_id,
				'message'     => 'A grade item was deleted.',
				'context'     => array(
					'section_id'  => (int) $item['section_id'],
					'category_id' => (int) $item['category_id'],
				),
			)
		);

		return true;
	}

	public function save_score( $item_id, $student_user_id, array $data, $user_id = 0 ) {
		global $wpdb;

		$item_id         = absint( $item_id );
		$student_user_id = absint( $student_user_id );
		$user_id         = absint( $user_id ?: get_current_user_id() );
		$item            = $this->get_item_row( $item_id );

		if ( ! $item ) {
			return new WP_Error( 'slms_invalid_grade_item', __( 'Invalid grade item.', 'simple-lms' ) );
		}

		if ( ! $this->can_manage_gradebook( $user_id, (int) $item['section_id'] ) ) {
			return new WP_Error( 'slms_forbidden_gradebook', __( 'You do not have permission to score that item.', 'simple-lms' ) );
		}

		if ( ! $this->student_is_enrolled( (int) $item['section_id'], $student_user_id ) ) {
			return new WP_Error( 'slms_invalid_grade_student', __( 'That student is not enrolled in this section.', 'simple-lms' ) );
		}

		$score     = isset( $data['score'] ) && '' !== (string) $data['score'] ? $this->normalize_points( $data['score'] ) : null;
		$feedback  = wp_kses_post( $data['feedback'] ?? '' );
		$existing  = $this->get_score_row( $item_id, $student_user_id );
		$source    = 'assignment' === $item['item_type'] && ! empty( $item['linked_post_id'] ) ? 'manual_override' : 'manual';
		$now       = AcademicClock::mysql();
		$payload   = array(
			'item_id'         => $item_id,
			'category_id'     => (int) $item['category_id'],
			'section_id'      => (int) $item['section_id'],
			'student_user_id' => $student_user_id,
			'score'           => $score,
			'feedback'        => $feedback ?: null,
			'source_type'     => $source,
			'source_ref_id'   => ! empty( $item['linked_post_id'] ) ? (int) $item['linked_post_id'] : null,
			'graded_by'       => $user_id,
			'created_at'      => $existing['created_at'] ?? $now,
			'updated_at'      => $now,
		);

		if ( $existing ) {
			$updated = $wpdb->update( Schema::table( 'grade_scores' ), $payload, array( 'id' => (int) $existing['id'] ) );

			if ( false === $updated ) {
				return new WP_Error( 'slms_grade_score_update_failed', __( 'The score could not be updated.', 'simple-lms' ) );
			}
		} else {
			$inserted = $wpdb->insert( Schema::table( 'grade_scores' ), $payload );

			if ( false === $inserted ) {
				return new WP_Error( 'slms_grade_score_insert_failed', __( 'The score could not be saved.', 'simple-lms' ) );
			}
		}

		$this->audit_logger->log(
			'grade_score_saved',
			array(
				'object_type' => 'grade_score',
				'object_id'   => $existing ? (int) $existing['id'] : (int) $wpdb->insert_id,
				'message'     => 'A gradebook score was saved.',
				'context'     => array(
					'section_id'       => (int) $item['section_id'],
					'category_id'      => (int) $item['category_id'],
					'item_id'          => $item_id,
					'student_user_id'  => $student_user_id,
					'source_type'      => $source,
				),
			)
		);

		return true;
	}

	public function sync_assignment_items( $section_id, $category_id, $user_id = 0 ) {
		$section_id  = absint( $section_id );
		$category_id = absint( $category_id );
		$user_id     = absint( $user_id ?: get_current_user_id() );

		if ( ! $this->can_manage_gradebook( $user_id, $section_id ) ) {
			return new WP_Error( 'slms_forbidden_gradebook', __( 'You do not have permission to sync assignment items for that section.', 'simple-lms' ) );
		}

		$category = $this->get_category_row( $category_id );

		if ( ! $category || (int) $category['section_id'] !== $section_id ) {
			return new WP_Error( 'slms_invalid_grade_category', __( 'Please choose a valid assignment category.', 'simple-lms' ) );
		}

		$assignments = $this->list_section_assignment_options( $section_id );
		$items       = $this->list_items( $section_id );
		$existing_map = array();

		foreach ( $items as $item ) {
			if ( ! empty( $item['linked_post_id'] ) ) {
				$existing_map[ (int) $item['linked_post_id'] ] = true;
			}
		}

		$existing_category_items = array_filter(
			$items,
			static function ( $item ) use ( $category_id ) {
				return (int) $item['category_id'] === $category_id;
			}
		);

		$has_existing_category_items = ! empty( $existing_category_items );
		$new_items                   = array();

		foreach ( $assignments as $assignment ) {
			if ( ! empty( $existing_map[ (int) $assignment['id'] ] ) ) {
				continue;
			}

			$new_items[] = $assignment;
		}

		if ( empty( $new_items ) ) {
			return 0;
		}

		$default_weight = $has_existing_category_items ? 0 : round( 100 / count( $new_items ), 2 );
		$created_count  = 0;

		foreach ( $new_items as $position => $assignment ) {
			$result = $this->save_item(
				$section_id,
				array(
					'category_id'         => $category_id,
					'title'               => $assignment['title'],
					'item_type'           => 'assignment',
					'linked_post_id'      => $assignment['id'],
					'weight_percent'      => $default_weight,
					'max_points'          => $assignment['points'],
					'publish_to_students' => 0,
					'sort_order'          => $this->get_next_item_sort_order( $category_id ) + $position,
				),
				$user_id
			);

			if ( ! is_wp_error( $result ) ) {
				$created_count++;
			}
		}

		return $created_count;
	}

	public function can_manage_gradebook( $user_id, $section_id ) {
		$user_id    = absint( $user_id );
		$section_id = absint( $section_id );
		$role       = RoleManager::get_primary_role( $user_id );

		if ( in_array( $role, array( 'administrator', 'officer' ), true ) ) {
			return true;
		}

		if ( 'lecturer' === $role ) {
			return $this->enrollments->user_can_teach_section( $user_id, $section_id );
		}

		return false;
	}

	public function can_view_full_gradebook( $user_id, $section_id ) {
		return $this->can_manage_gradebook( $user_id, $section_id );
	}

	public function can_view_gradebook( $user_id, $section_id ) {
		$user_id    = absint( $user_id );
		$section_id = absint( $section_id );
		$role       = RoleManager::get_primary_role( $user_id );

		if ( $this->can_manage_gradebook( $user_id, $section_id ) ) {
			return true;
		}

		return false;
	}

	private function list_categories( $section_id ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::table( 'grade_categories' ) . ' WHERE section_id = %d ORDER BY sort_order ASC, id ASC',
				$section_id
			),
			ARRAY_A
		);

		return array_map( array( $this, 'format_category_row' ), $rows );
	}

	private function list_items( $section_id ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::table( 'grade_items' ) . ' WHERE section_id = %d ORDER BY category_id ASC, sort_order ASC, id ASC',
				$section_id
			),
			ARRAY_A
		);

		return array_map( array( $this, 'format_item_row' ), $rows );
	}

	private function get_gradebook_students( $section_id ) {
		$rows = $this->enrollments->list_enrollments(
			array(
				'section_id' => $section_id,
			)
		);

		return array_values(
			array_filter(
				$rows,
				static function ( $row ) {
					return in_array( $row['status'], array( 'enrolled', 'completed', 'waitlisted' ), true );
				}
			)
		);
	}

	private function build_student_grade_row( array $student, array $categories, array $score_map, array $submission_map, array $attendance_summary_map ) {
		$student_id       = (int) $student['student_user_id'];
		$attendance_rollup = $attendance_summary_map[ $student_id ] ?? array(
			'attendance_percentage' => 0,
		);
		$category_scores = array();
		$item_scores     = array();
		$weighted_total  = 0.0;
		$weight_total    = 0.0;

		foreach ( $categories as $category ) {
			$item_weight_total = max( 0.0, (float) $category['item_weight_total'] );
			$category_points   = 0.0;

			foreach ( $category['items'] as $item ) {
				$resolved = $this->resolve_item_score( $item, $student_id, $score_map, $submission_map, $attendance_rollup );
				$item_scores[ $item['id'] ] = $resolved;

				if ( $item_weight_total <= 0 || null === $resolved['score'] || (float) $item['max_points'] <= 0 ) {
					continue;
				}

				$ratio = min( 1, max( 0, (float) $resolved['score'] / (float) $item['max_points'] ) );
				$category_points += $ratio * (float) $item['weight_percent'];
			}

			if ( 'attendance_rollup' === $category['scoring_method'] ) {
				$category_percentage = (float) $attendance_rollup['attendance_percentage'];
			} else {
				$category_percentage = $item_weight_total > 0 ? ( $category_points / $item_weight_total ) * 100 : 0;
			}

			$weighted_points     = ( $category_percentage * (float) $category['weight_percent'] ) / 100;

			$category_scores[ $category['id'] ] = array(
				'percentage'      => round( $category_percentage, 2 ),
				'weighted_points' => round( $weighted_points, 2 ),
			);

			$weighted_total += $weighted_points;
			$weight_total   += (float) $category['weight_percent'];
		}

		return array(
			'student_user_id'   => (int) $student['student_user_id'],
			'student_name'      => $student['student_name'],
			'student_email'     => $student['student_email'],
			'enrollment_status' => $student['status'],
			'item_scores'       => $item_scores,
			'category_scores'   => $category_scores,
			'attendance_summary' => $attendance_rollup,
			'weighted_total'    => round( $weighted_total, 2 ),
			'final_percentage'  => round( $weight_total > 0 ? ( $weighted_total / $weight_total ) * 100 : 0, 2 ),
		);
	}

	private function resolve_item_score( array $item, $student_user_id, array $score_map, array $submission_map, array $attendance_rollup ) {
		$item_key = $item['id'] . ':' . $student_user_id;

		if ( isset( $score_map[ $item_key ] ) ) {
			$row = $score_map[ $item_key ];

			return array(
				'score'     => null !== $row['score'] ? (float) $row['score'] : null,
				'feedback'  => $row['feedback'],
				'source'    => 'manual_override' === $row['source_type'] ? 'override' : 'manual',
				'status'    => 'saved',
				'updated_at' => $row['updated_at'],
			);
		}

		if ( 'assignment' === $item['item_type'] && ! empty( $item['linked_post_id'] ) ) {
			$submission_key = $item['linked_post_id'] . ':' . $student_user_id;

			if ( isset( $submission_map[ $submission_key ] ) ) {
				$row          = $submission_map[ $submission_key ];
				$raw_score    = null !== $row['score'] && '' !== (string) $row['score'] ? (float) $row['score'] : null;
				$late_details = AssignmentLatePolicy::get_adjustment_details( $item, $row );
				$adjusted     = null !== $raw_score ? round( $raw_score * (float) $late_details['late_multiplier'], 2 ) : null;

				return array(
					'score'                => $adjusted,
					'raw_score'            => $raw_score,
					'adjusted_score'       => $adjusted,
					'late_days'            => (int) $late_details['late_days'],
					'late_multiplier'      => (float) $late_details['late_multiplier'],
					'late_penalty_percent' => (float) $late_details['late_penalty_percent'],
					'late_policy_label'    => $late_details['policy_short_label'],
					'is_late'              => (bool) $late_details['is_late'],
					'is_over_late_limit'   => (bool) $late_details['is_over_late_limit'],
					'feedback'             => $row['feedback'] ?? '',
					'source'               => 'assignment',
					'status'               => $row['status'] ?? '',
					'updated_at'           => $row['updated_at'] ?? '',
				);
			}
		}

		if ( 'attendance' === $item['item_type'] && (float) $item['max_points'] > 0 ) {
			$attendance_percentage = (float) ( $attendance_rollup['attendance_percentage'] ?? 0 );

			return array(
				'score'      => round( ( $attendance_percentage / 100 ) * (float) $item['max_points'], 2 ),
				'feedback'   => '',
				'source'     => 'attendance',
				'status'     => 'rollup',
				'updated_at' => '',
			);
		}

		return array(
			'score'      => null,
			'feedback'   => '',
			'source'     => '',
			'status'     => '',
			'updated_at' => '',
		);
	}

	private function get_score_map( $section_id ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::table( 'grade_scores' ) . ' WHERE section_id = %d',
				$section_id
			),
			ARRAY_A
		);

		$map = array();

		foreach ( $rows as $row ) {
			$map[ (int) $row['item_id'] . ':' . (int) $row['student_user_id'] ] = array(
				'score'       => null !== $row['score'] ? (float) $row['score'] : null,
				'feedback'    => $row['feedback'],
				'source_type' => $row['source_type'],
				'updated_at'  => $row['updated_at'],
			);
		}

		return $map;
	}

	private function get_assignment_submission_map( $section_id, array $assignment_ids ) {
		global $wpdb;

		$assignment_ids = array_values( array_unique( array_filter( array_map( 'absint', $assignment_ids ) ) ) );

		if ( empty( $assignment_ids ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $assignment_ids ), '%d' ) );
		$params       = array_merge( array( $section_id ), $assignment_ids );
		$sql          = 'SELECT * FROM ' . Schema::table( 'assignment_submissions' ) . " WHERE section_id = %d AND assignment_id IN ({$placeholders}) AND is_audit_submission = 0";
		$rows         = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		$map          = array();

		foreach ( $rows as $row ) {
			$map[ (int) $row['assignment_id'] . ':' . (int) $row['student_user_id'] ] = $row;
		}

		return $map;
	}

	private function sync_linked_assignment_metadata( $section_id ) {
		global $wpdb;

		$items = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::table( 'grade_items' ) . ' WHERE section_id = %d AND item_type = %s AND linked_post_id IS NOT NULL',
				$section_id,
				'assignment'
			),
			ARRAY_A
		);

		foreach ( $items as $item ) {
			$assignment_id = absint( $item['linked_post_id'] );

			if ( ! $assignment_id || 'slms_assignment' !== get_post_type( $assignment_id ) ) {
				continue;
			}

			$wpdb->update(
				Schema::table( 'grade_items' ),
				array(
					'title'      => get_the_title( $assignment_id ),
					'max_points' => AssignmentPointPolicy::normalize_max_points( get_post_meta( $assignment_id, '_slms_points', true ) ?: $item['max_points'] ),
					'due_at'     => sanitize_text_field( get_post_meta( $assignment_id, '_slms_due_at', true ) ) ?: null,
					'updated_at' => AcademicClock::mysql(),
				),
				array( 'id' => (int) $item['id'] )
			);
		}
	}

	private function list_section_assignment_options( $section_id ) {
		$query = new WP_Query(
			array(
				'post_type'      => 'slms_assignment',
				'post_status'    => array( 'publish', 'private', 'draft', 'pending' ),
				'posts_per_page' => 200,
				'meta_query'     => array(
					array(
						'key'   => '_slms_section_id',
						'value' => $section_id,
					),
				),
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		return array_map(
			static function ( $post ) {
				return array(
					'id'       => (int) $post->ID,
					'title'    => $post->post_title,
					'points'   => AssignmentPointPolicy::normalize_max_points( get_post_meta( $post->ID, '_slms_points', true ) ),
					'due_at'   => sanitize_text_field( get_post_meta( $post->ID, '_slms_due_at', true ) ),
					'status'   => $post->post_status,
				);
			},
			$query->posts
		);
	}

	private function get_category_row( $category_id ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::table( 'grade_categories' ) . ' WHERE id = %d LIMIT 1',
				$category_id
			),
			ARRAY_A
		);

		return $row ? $this->format_category_row( $row ) : null;
	}

	private function get_item_row( $item_id ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::table( 'grade_items' ) . ' WHERE id = %d LIMIT 1',
				$item_id
			),
			ARRAY_A
		);

		return $row ? $this->format_item_row( $row ) : null;
	}

	private function get_score_row( $item_id, $student_user_id ) {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::table( 'grade_scores' ) . ' WHERE item_id = %d AND student_user_id = %d LIMIT 1',
				$item_id,
				$student_user_id
			),
			ARRAY_A
		);
	}

	private function student_is_enrolled( $section_id, $student_user_id ) {
		foreach ( $this->get_gradebook_students( $section_id ) as $student ) {
			if ( (int) $student['student_user_id'] === (int) $student_user_id ) {
				return true;
			}
		}

		return false;
	}

	private function get_next_category_sort_order( $section_id ) {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COALESCE(MAX(sort_order), 0) + 10 FROM ' . Schema::table( 'grade_categories' ) . ' WHERE section_id = %d',
				$section_id
			)
		);
	}

	private function get_next_item_sort_order( $category_id ) {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COALESCE(MAX(sort_order), 0) + 10 FROM ' . Schema::table( 'grade_items' ) . ' WHERE category_id = %d',
				$category_id
			)
		);
	}

	private function sum_weights( array $rows, $field ) {
		$total = 0.0;

		foreach ( $rows as $row ) {
			$total += (float) ( $row[ $field ] ?? 0 );
		}

		return round( $total, 2 );
	}

	private function normalize_weight( $value ) {
		return round( max( 0, min( 100, (float) $value ) ), 2 );
	}

	private function normalize_points( $value ) {
		return round( max( 0, (float) $value ), 2 );
	}

	private function format_category_row( array $row ) {
		return array(
			'id'                  => (int) $row['id'],
			'section_id'          => (int) $row['section_id'],
			'title'               => $row['title'],
			'category_type'       => $row['category_type'],
			'weight_percent'      => (float) $row['weight_percent'],
			'scoring_method'      => $row['scoring_method'],
			'publish_to_students' => (int) $row['publish_to_students'],
			'sort_order'          => (int) $row['sort_order'],
			'created_by'          => ! empty( $row['created_by'] ) ? (int) $row['created_by'] : 0,
			'updated_by'          => ! empty( $row['updated_by'] ) ? (int) $row['updated_by'] : 0,
			'created_at'          => $row['created_at'],
			'updated_at'          => $row['updated_at'],
		);
	}

	private function format_item_row( array $row ) {
		return array(
			'id'                  => (int) $row['id'],
			'category_id'         => (int) $row['category_id'],
			'section_id'          => (int) $row['section_id'],
			'title'               => $row['title'],
			'item_type'           => $row['item_type'],
			'linked_post_id'      => ! empty( $row['linked_post_id'] ) ? (int) $row['linked_post_id'] : 0,
			'weight_percent'      => (float) $row['weight_percent'],
			'max_points'          => (float) $row['max_points'],
			'publish_to_students' => (int) $row['publish_to_students'],
			'due_at'              => $row['due_at'],
			'sort_order'          => (int) $row['sort_order'],
			'created_by'          => ! empty( $row['created_by'] ) ? (int) $row['created_by'] : 0,
			'updated_by'          => ! empty( $row['updated_by'] ) ? (int) $row['updated_by'] : 0,
			'created_at'          => $row['created_at'],
			'updated_at'          => $row['updated_at'],
		);
	}
}
