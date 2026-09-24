<?php

namespace SimpleLMS\Domain\Academic;

use SimpleLMS\Core\AcademicClock;
use SimpleLMS\Domain\Users\RoleManager;
use SimpleLMS\Infrastructure\Database\Schema;
use SimpleLMS\Infrastructure\Logging\AuditLogger;
use WP_Error;

defined( 'ABSPATH' ) || exit;

class EnrollmentService {
	/**
	 * @var AuditLogger|null
	 */
	private $audit_logger;

	public function __construct( ?AuditLogger $audit_logger = null ) {
		$this->audit_logger = $audit_logger;
	}

	const VALID_ENROLLMENT_STATUSES = array( 'enrolled', 'waitlisted', 'dropped', 'completed' );
	const ACTIVE_ENROLLMENT_STATUSES = array( 'enrolled', 'waitlisted' );
	const VALID_SECTION_STAFF_ROLES = array( 'lecturer' );
	const VALID_STAFF_AUDIT_STATUSES = array( 'active', 'dropped', 'completed' );
	const ACTIVE_STAFF_AUDIT_STATUSES = array( 'active' );
	const COURSE_POST_TYPES         = array( 'slms_subject', 'slms_section' );

	public function assign_section_staff( $section_id, array $user_ids, $role = 'lecturer', $assigned_by = 0 ) {
		global $wpdb;

		$section_id  = absint( $section_id );
		$assigned_by = absint( $assigned_by );
		$role        = sanitize_key( $role );

		if ( ! $this->is_course_post( $section_id ) ) {
			return new WP_Error( 'slms_invalid_section', __( 'Invalid subject.', 'simple-lms' ) );
		}

		if ( ! in_array( $role, self::VALID_SECTION_STAFF_ROLES, true ) ) {
			return new WP_Error( 'slms_invalid_section_role', __( 'Invalid section staff role.', 'simple-lms' ) );
		}

		$user_ids = array_values( array_unique( array_filter( array_map( 'absint', $user_ids ) ) ) );
		$table    = Schema::table( 'section_staff' );

		$wpdb->delete(
			$table,
			array(
				'section_id' => $section_id,
				'role'       => $role,
			),
			array( '%d', '%s' )
		);

		$assigned_ids = array();
		$now          = AcademicClock::mysql();

		foreach ( $user_ids as $user_id ) {
			if ( ! $this->user_can_be_section_staff( $user_id ) ) {
				continue;
			}

			$this->update_staff_audit_status( $section_id, $user_id, 'completed' );

			$inserted = $wpdb->insert(
				$table,
				array(
					'section_id'   => $section_id,
					'user_id'      => $user_id,
					'role'         => $role,
					'status'       => 'active',
					'assigned_by'  => $assigned_by ?: get_current_user_id(),
					'assigned_at'  => $now,
					'updated_at'   => $now,
				),
				array( '%d', '%d', '%s', '%s', '%d', '%s', '%s' )
			);

			if ( false !== $inserted ) {
				$assigned_ids[] = $user_id;
			}
		}

		return $assigned_ids;
	}

	public function unassign_section_staff( $section_id, $user_id, $role = 'lecturer' ) {
		global $wpdb;

		$section_id = absint( $section_id );
		$user_id    = absint( $user_id );
		$role       = sanitize_key( $role );

		if ( ! $this->is_course_post( $section_id ) ) {
			return new WP_Error( 'slms_invalid_section', __( 'Invalid subject.', 'simple-lms' ) );
		}

		if ( ! in_array( $role, self::VALID_SECTION_STAFF_ROLES, true ) ) {
			return new WP_Error( 'slms_invalid_section_role', __( 'Invalid section staff role.', 'simple-lms' ) );
		}

		if ( ! $user_id ) {
			return new WP_Error( 'slms_invalid_staff_user', __( 'Invalid staff assignment.', 'simple-lms' ) );
		}

		$deleted = $wpdb->delete(
			Schema::table( 'section_staff' ),
			array(
				'section_id' => $section_id,
				'user_id'    => $user_id,
				'role'       => $role,
			),
			array( '%d', '%d', '%s' )
		);

		if ( false === $deleted ) {
			return new WP_Error( 'slms_section_staff_unassign_failed', __( 'The lecturer could not be unassigned from this subject.', 'simple-lms' ) );
		}

		return $deleted > 0;
	}

	public function get_section_staff( $section_id, $role = 'lecturer' ) {
		global $wpdb;

		$section_id = absint( $section_id );
		$role       = sanitize_key( $role );
		$table      = Schema::table( 'section_staff' );
		$users      = $wpdb->users;

		$sql = $wpdb->prepare(
			"SELECT ss.*, u.display_name, u.user_email
			FROM {$table} ss
			INNER JOIN {$users} u ON u.ID = ss.user_id
			WHERE ss.section_id = %d AND ss.role = %s
			ORDER BY u.display_name ASC",
			$section_id,
			$role
		);

		return $wpdb->get_results( $sql, ARRAY_A );
	}

	public function enroll_student( $section_id, $student_user_id, array $args = array() ) {
		global $wpdb;

		$section_id       = absint( $section_id );
		$student_user_id  = absint( $student_user_id );
		$status           = sanitize_key( $args['status'] ?? 'enrolled' );
		$source           = sanitize_key( $args['source'] ?? 'manual' );
		$notes            = sanitize_textarea_field( $args['notes'] ?? '' );
		$created_by       = absint( $args['created_by'] ?? get_current_user_id() );
		$enrolled_at      = AcademicClock::mysql();
		$now              = AcademicClock::mysql();

		if ( ! $this->is_course_post( $section_id ) ) {
			return new WP_Error( 'slms_invalid_section', __( 'Invalid subject.', 'simple-lms' ) );
		}

		if ( ! $this->user_is_student( $student_user_id ) ) {
			return new WP_Error( 'slms_invalid_student', __( 'The selected user is not a student.', 'simple-lms' ) );
		}

		if ( ! in_array( $status, self::VALID_ENROLLMENT_STATUSES, true ) ) {
			$status = 'enrolled';
		}

		$table    = Schema::table( 'enrollments' );
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE student_user_id = %d AND section_id = %d LIMIT 1",
				$student_user_id,
				$section_id
			),
			ARRAY_A
		);

		$data = array(
			'student_user_id' => $student_user_id,
			'section_id'      => $section_id,
			'status'          => $status,
			'source'          => $source,
			'notes'           => $notes,
			'created_by'      => ! empty( $existing['created_by'] ) ? (int) $existing['created_by'] : $created_by,
			'enrolled_at'     => $existing['enrolled_at'] ?? $enrolled_at,
			'completed_at'    => 'completed' === $status ? ( $existing['completed_at'] ?? $now ) : null,
			'dropped_at'      => 'dropped' === $status ? ( $existing['dropped_at'] ?? $now ) : null,
			'updated_at'      => $now,
		);

		if ( ! empty( $existing['id'] ) ) {
			$updated = $wpdb->update(
				$table,
				$data,
				array( 'id' => (int) $existing['id'] ),
				array( '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);

			if ( false === $updated ) {
				return new WP_Error( 'slms_enrollment_update_failed', __( 'The enrollment could not be updated.', 'simple-lms' ) );
			}

			return (int) $existing['id'];
		}

		$inserted = $wpdb->insert(
			$table,
			$data,
			array( '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return new WP_Error( 'slms_enrollment_insert_failed', __( 'The student could not be enrolled.', 'simple-lms' ) );
		}

		return (int) $wpdb->insert_id;
	}

	public function update_enrollment_status( $enrollment_id, $status ) {
		global $wpdb;

		$enrollment_id = absint( $enrollment_id );
		$status        = sanitize_key( $status );

		if ( ! in_array( $status, self::VALID_ENROLLMENT_STATUSES, true ) ) {
			return new WP_Error( 'slms_invalid_enrollment_status', __( 'Invalid enrollment status.', 'simple-lms' ) );
		}

		$existing = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::table( 'enrollments' ) . ' WHERE id = %d LIMIT 1',
				$enrollment_id
			),
			ARRAY_A
		);

		if ( ! $existing ) {
			return new WP_Error( 'slms_invalid_enrollment', __( 'Invalid enrollment.', 'simple-lms' ) );
		}

		$now = AcademicClock::mysql();

		$data = array(
			'status'       => $status,
			'updated_at'   => $now,
			'completed_at' => 'completed' === $status ? ( $existing['completed_at'] ?: $now ) : null,
			'dropped_at'   => 'dropped' === $status ? ( $existing['dropped_at'] ?: $now ) : null,
		);

		$updated = $wpdb->update(
			Schema::table( 'enrollments' ),
			$data,
			array( 'id' => $enrollment_id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error( 'slms_enrollment_update_failed', __( 'The enrollment could not be updated.', 'simple-lms' ) );
		}

		if ( 'dropped' === $status ) {
			$wpdb->delete(
				Schema::table( 'attendance_records' ),
				array(
					'section_id'      => absint( $existing['section_id'] ?? 0 ),
					'student_user_id' => absint( $existing['student_user_id'] ?? 0 ),
				),
				array( '%d', '%d' )
			);
		}

		return true;
	}


	public function complete_active_student_enrollments( $student_user_id, $completed_by = 0, $note = '' ) {
		global $wpdb;

		$student_user_id = absint( $student_user_id );
		$completed_by    = absint( $completed_by );
		$note            = sanitize_textarea_field( $note );

		if ( ! $student_user_id ) {
			return new WP_Error( 'slms_invalid_student', __( 'Invalid student.', 'simple-lms' ) );
		}

		if ( ! $this->user_is_student( $student_user_id ) ) {
			return new WP_Error( 'slms_invalid_student', __( 'The selected user is not a student.', 'simple-lms' ) );
		}

		$table = Schema::table( 'enrollments' );
		$now   = AcademicClock::mysql();

		$append_note_sql = '';
		$params          = array( $now, $now );

		if ( '' !== $note ) {
			$append_note_sql = ", notes = TRIM(CONCAT(COALESCE(notes, ''), CASE WHEN COALESCE(notes, '') = '' THEN '' ELSE '\n' END, %s))";
			$params[]        = sprintf(
				/* translators: 1: completion note, 2: user id */
				__( 'Program completion: %1$s (completed by user #%2$d)', 'simple-lms' ),
				$note,
				$completed_by ?: get_current_user_id()
			);
		}

		$params[] = $student_user_id;

		$sql = "UPDATE {$table}
			SET status = 'completed',
				completed_at = CASE WHEN completed_at IS NULL THEN %s ELSE completed_at END,
				updated_at = %s
				{$append_note_sql}
			WHERE student_user_id = %d
			AND status IN ('enrolled', 'waitlisted')";

		$updated = $wpdb->query( $wpdb->prepare( $sql, $params ) );

		if ( false === $updated ) {
			return new WP_Error( 'slms_enrollment_completion_failed', __( 'Active subject enrollments could not be closed.', 'simple-lms' ) );
		}

		return (int) $updated;
	}


	public function get_student_subject_enrollment_map( $student_user_id, array $section_ids = array() ) {
		global $wpdb;

		$student_user_id = absint( $student_user_id );

		if ( ! $student_user_id ) {
			return array();
		}

		$section_ids = array_values( array_unique( array_filter( array_map( 'absint', $section_ids ) ) ) );
		$table       = Schema::table( 'enrollments' );
		$where       = 'WHERE student_user_id = %d';
		$params      = array( $student_user_id );

		if ( ! empty( $section_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $section_ids ), '%d' ) );
			$where       .= " AND section_id IN ({$placeholders})";
			$params       = array_merge( $params, $section_ids );
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} {$where}",
				$params
			),
			ARRAY_A
		);

		$map = array();

		foreach ( (array) $rows as $row ) {
			$map[ (int) $row['section_id'] ] = $row;
		}

		return $map;
	}

	public function get_student_active_section_ids( $student_user_id, array $section_ids = array() ) {
		$map = $this->get_student_subject_enrollment_map( $student_user_id, $section_ids );
		$ids = array();

		foreach ( $map as $section_id => $row ) {
			if ( in_array( $row['status'] ?? '', self::ACTIVE_ENROLLMENT_STATUSES, true ) ) {
				$ids[] = (int) $section_id;
			}
		}

		return $ids;
	}

	public function drop_student_from_section( $section_id, $student_user_id, array $args = array() ) {
		$section_id      = absint( $section_id );
		$student_user_id = absint( $student_user_id );

		if ( ! $this->is_course_post( $section_id ) ) {
			return new WP_Error( 'slms_invalid_section', __( 'Invalid subject.', 'simple-lms' ) );
		}

		if ( ! $this->user_is_student( $student_user_id ) ) {
			return new WP_Error( 'slms_invalid_student', __( 'The selected user is not a student.', 'simple-lms' ) );
		}

		$enrollment_map = $this->get_student_subject_enrollment_map( $student_user_id, array( $section_id ) );
		$existing       = $enrollment_map[ $section_id ] ?? null;

		if ( empty( $existing['id'] ) ) {
			return new WP_Error( 'slms_enrollment_not_found', __( 'That student is not enrolled in this subject.', 'simple-lms' ) );
		}

		$status = sanitize_key( $existing['status'] ?? '' );

		if ( 'dropped' === $status ) {
			return true;
		}

		if ( 'completed' === $status ) {
			return new WP_Error( 'slms_enrollment_completed_locked', __( 'Completed enrollments are preserved and cannot be unenrolled from this shortcut.', 'simple-lms' ) );
		}

		if ( ! in_array( $status, self::ACTIVE_ENROLLMENT_STATUSES, true ) ) {
			return new WP_Error( 'slms_enrollment_not_active', __( 'Only active enrollments can be unenrolled from this shortcut.', 'simple-lms' ) );
		}

		return $this->update_enrollment_status( (int) $existing['id'], 'dropped' );
	}

	public function sync_student_subject_enrollments( $student_user_id, array $selected_section_ids, array $available_section_ids, array $args = array() ) {
		$student_user_id = absint( $student_user_id );

		if ( ! $this->user_is_student( $student_user_id ) ) {
			return new WP_Error( 'slms_invalid_student', __( 'The selected user is not a student.', 'simple-lms' ) );
		}

		$available_section_ids = $this->filter_valid_course_post_ids( $available_section_ids );
		$selected_section_ids  = array_values( array_intersect( $this->filter_valid_course_post_ids( $selected_section_ids ), $available_section_ids ) );
		$selected_lookup       = array_fill_keys( $selected_section_ids, true );
		$current_map           = $this->get_student_subject_enrollment_map( $student_user_id, $available_section_ids );
		$result                = array(
			'enrolled'   => 0,
			'unenrolled' => 0,
			'unchanged'  => 0,
			'failed'    => 0,
			'errors'    => array(),
		);

		foreach ( $selected_section_ids as $section_id ) {
			$current = $current_map[ $section_id ] ?? null;
			$status  = sanitize_key( $current['status'] ?? '' );

			if ( $current && in_array( $status, array( 'enrolled', 'waitlisted', 'completed' ), true ) ) {
				$result['unchanged']++;
				continue;
			}

			$enrolled = $this->enroll_student(
				$section_id,
				$student_user_id,
				array(
					'status'     => 'enrolled',
					'source'     => sanitize_key( $args['source'] ?? 'student_record_editor' ),
					'created_by' => absint( $args['created_by'] ?? get_current_user_id() ),
				)
			);

			if ( is_wp_error( $enrolled ) ) {
				$result['failed']++;
				$result['errors'][] = $enrolled->get_error_message();
				continue;
			}

			$result['enrolled']++;
		}

		foreach ( $available_section_ids as $section_id ) {
			if ( isset( $selected_lookup[ $section_id ] ) ) {
				continue;
			}

			$current = $current_map[ $section_id ] ?? null;

			if ( empty( $current['id'] ) || ! in_array( $current['status'] ?? '', self::ACTIVE_ENROLLMENT_STATUSES, true ) ) {
				continue;
			}

			$dropped = $this->drop_student_from_section( $section_id, $student_user_id );

			if ( is_wp_error( $dropped ) ) {
				$result['failed']++;
				$result['errors'][] = $dropped->get_error_message();
				continue;
			}

			$result['unenrolled']++;
		}

		$result['errors'] = array_values( array_unique( $result['errors'] ) );

		return $result;
	}

	public function list_enrollments( array $args = array() ) {
		global $wpdb;

		$table       = Schema::table( 'enrollments' );
		$users       = $wpdb->users;
		$posts       = $wpdb->posts;
		$postmeta    = $wpdb->postmeta;
		$where       = 'WHERE 1=1';
		$params      = array();

		if ( ! empty( $args['statuses'] ) && is_array( $args['statuses'] ) ) {
			$statuses = array_values( array_unique( array_intersect( array_map( 'sanitize_key', $args['statuses'] ), self::VALID_ENROLLMENT_STATUSES ) ) );

			if ( $statuses ) {
				$where   .= ' AND e.status IN (' . implode( ', ', array_fill( 0, count( $statuses ), '%s' ) ) . ')';
				$params = array_merge( $params, $statuses );
			} else {
				$where .= ' AND 1=0';
			}
		} elseif ( ! empty( $args['status'] ) && in_array( $args['status'], self::VALID_ENROLLMENT_STATUSES, true ) ) {
			$where   .= ' AND e.status = %s';
			$params[] = $args['status'];
		}

		if ( ! empty( $args['section_id'] ) ) {
			$where   .= ' AND e.section_id = %d';
			$params[] = absint( $args['section_id'] );
		}

		if ( ! empty( $args['student_user_id'] ) ) {
			$where   .= ' AND e.student_user_id = %d';
			$params[] = absint( $args['student_user_id'] );
		}

		$sql = "
			SELECT
				e.*,
				u.display_name AS student_name,
				u.user_email AS student_email,
				course_posts.post_title AS section_title,
				CASE
					WHEN course_posts.post_type = 'slms_subject' THEN course_posts.post_title
					ELSE COALESCE(subject_posts.post_title, '')
				END AS subject_title,
				course_posts.post_type AS course_post_type
			FROM {$table} e
			INNER JOIN {$users} u ON u.ID = e.student_user_id
			INNER JOIN {$posts} course_posts ON course_posts.ID = e.section_id
			LEFT JOIN {$postmeta} subject_meta ON subject_meta.post_id = course_posts.ID AND subject_meta.meta_key = '_slms_subject_id'
			LEFT JOIN {$posts} subject_posts ON subject_posts.ID = CAST(subject_meta.meta_value AS UNSIGNED)
			{$where}
			ORDER BY e.updated_at DESC, e.id DESC
		";

		if ( $params ) {
			$sql = $wpdb->prepare( $sql, $params );
		}

		return $wpdb->get_results( $sql, ARRAY_A );
	}

	public function count_enrollments() {
		global $wpdb;

		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'enrollments' ) );
	}

	public function get_section_summary( $section_id ) {
		$section_id = absint( $section_id );

		if ( ! $this->is_course_post( $section_id ) ) {
			return null;
		}

		$sections = $this->list_sections_query(
			array(
				'post_statuses' => array( 'publish', 'private', 'draft', 'pending' ),
				'where_sql'     => ' AND section_posts.ID = %d',
				'params'        => array( $section_id ),
				'relationship'  => 'direct',
			)
		);

		return ! empty( $sections ) ? $sections[0] : null;
	}

	public function list_sections( array $args = array() ) {
		$post_statuses = ! empty( $args['post_statuses'] ) ? (array) $args['post_statuses'] : array( 'publish', 'private', 'draft', 'pending' );
		$where_sql     = '';
		$params        = array();

		if ( ! empty( $args['section_id'] ) ) {
			$where_sql .= ' AND section_posts.ID = %d';
			$params[]   = absint( $args['section_id'] );
		}

		if ( ! empty( $args['term_id'] ) ) {
			$where_sql .= ' AND CAST(COALESCE(term_meta.meta_value, 0) AS UNSIGNED) = %d';
			$params[]   = absint( $args['term_id'] );
		}

		if ( ! empty( $args['academic_year'] ) ) {
			$where_sql .= ' AND COALESCE(term_rows.academic_year, \'\') = %s';
			$params[]   = sanitize_text_field( $args['academic_year'] );
		}

		if ( ! empty( $args['section_status'] ) ) {
			$where_sql .= ' AND COALESCE(section_status_meta.meta_value, \'\') = %s';
			$params[]   = sanitize_key( $args['section_status'] );
		}

		if ( ! empty( $args['search'] ) ) {
			global $wpdb;

			$search     = '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
			$where_sql .= ' AND (section_posts.post_title LIKE %s OR COALESCE(section_code_meta.meta_value, \'\') LIKE %s OR COALESCE(subject_posts.post_title, \'\') LIKE %s)';
			$params[]   = $search;
			$params[]   = $search;
			$params[]   = $search;
		}

		return $this->list_sections_query(
			array(
				'post_statuses' => $post_statuses,
				'where_sql'     => $where_sql,
				'params'        => $params,
				'relationship'  => 'records',
			)
		);
	}

	public function get_sections_for_user( $user_id = 0 ) {
		$user_id = absint( $user_id ?: get_current_user_id() );
		$role    = RoleManager::get_primary_role( $user_id );

		if ( ! $user_id || ! $role ) {
			return array();
		}

		if ( in_array( $role, array( 'administrator', 'officer' ), true ) ) {
			return $this->list_sections_query(
				array(
					'post_statuses' => array( 'publish', 'private', 'draft', 'pending' ),
					'relationship'  => 'oversight',
				)
			);
		}


		if ( 'lecturer' === $role ) {
			return $this->get_teaching_sections_for_user( $user_id );
		}

		if ( 'student' === $role ) {
			$access_cutoff = AcademicClock::now()->modify( '-12 months' )->format( 'Y-m-d H:i:s' );

			return $this->list_sections_query(
				array(
					'post_statuses' => array( 'publish', 'private' ),
					'joins_sql'     => ' INNER JOIN ' . Schema::table( 'enrollments' ) . ' enroll_rel ON enroll_rel.section_id = section_posts.ID',
					'where_sql'     => ' AND enroll_rel.student_user_id = %d AND enroll_rel.status IN (%s, %s) AND enroll_rel.enrolled_at >= %s',
					'params'        => array( $user_id, 'enrolled', 'waitlisted', $access_cutoff ),
					'extra_select'  => ', enroll_rel.status AS enrollment_status',
					'relationship'  => 'learning',
				)
			);
		}

		return array();
	}

	public function get_teaching_sections_for_user( $user_id = 0 ) {
		$user_id = absint( $user_id ?: get_current_user_id() );

		if ( ! $user_id ) {
			return array();
		}

		return $this->list_sections_query(
			array(
				'post_statuses' => array( 'publish', 'private', 'draft', 'pending' ),
				'joins_sql'     => ' INNER JOIN ' . Schema::table( 'section_staff' ) . ' staff_rel ON staff_rel.section_id = section_posts.ID',
				'where_sql'     => ' AND staff_rel.user_id = %d AND staff_rel.role = %s AND staff_rel.status = %s',
				'params'        => array( $user_id, 'lecturer', 'active' ),
				'relationship'  => 'teaching',
			)
		);
	}

	public function get_accessible_section_ids( $user_id = 0 ) {
		return array_map( 'absint', wp_list_pluck( $this->get_sections_for_user( $user_id ), 'id' ) );
	}

	public function get_teaching_section_ids_for_user( $user_id = 0 ) {
		return array_map( 'absint', wp_list_pluck( $this->get_teaching_sections_for_user( $user_id ), 'id' ) );
	}

	public function get_staff_audit_sections_for_user( $user_id = 0 ) {
		$user_id = absint( $user_id ?: get_current_user_id() );

		if ( ! $user_id ) {
			return array();
		}

		return $this->list_sections_query(
			array(
				'post_statuses' => array( 'publish', 'private' ),
				'joins_sql'     => ' INNER JOIN ' . Schema::table( 'staff_audit_enrollments' ) . ' audit_rel ON audit_rel.section_id = section_posts.ID',
				'where_sql'     => ' AND audit_rel.staff_user_id = %d AND audit_rel.status = %s',
				'params'        => array( $user_id, 'active' ),
				'extra_select'  => ', audit_rel.status AS audit_status, audit_rel.enrolled_at AS audit_enrolled_at',
				'relationship'  => 'auditing',
			)
		);
	}

	public function get_staff_audit_section_ids( $user_id = 0 ) {
		return array_map( 'absint', wp_list_pluck( $this->get_staff_audit_sections_for_user( $user_id ), 'id' ) );
	}

	public function get_learning_section_ids_for_user( $user_id = 0 ) {
		$user_id = absint( $user_id ?: get_current_user_id() );
		$role    = RoleManager::get_primary_role( $user_id );

		if ( 'lecturer' === $role ) {
			return array_values( array_unique( array_merge( $this->get_teaching_section_ids_for_user( $user_id ), $this->get_staff_audit_section_ids( $user_id ) ) ) );
		}

		return $this->get_accessible_section_ids( $user_id );
	}


	public function get_student_user_id_by_code( $student_code ) {
		global $wpdb;

		$student_code = strtoupper( sanitize_text_field( $student_code ) );
		$profiles     = Schema::table( 'user_profiles' );
		$users        = $wpdb->users;

		if ( '' === $student_code ) {
			return 0;
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT p.user_id
				FROM {$profiles} p
				INNER JOIN {$users} u ON u.ID = p.user_id
				WHERE p.person_code = %s AND p.person_type = %s
				LIMIT 1",
				$student_code,
				'student'
			)
		);
	}

	public function enroll_student_by_code( $section_id, $student_code, array $args = array() ) {
		$student_user_id = $this->get_student_user_id_by_code( $student_code );

		if ( ! $student_user_id ) {
			return new WP_Error( 'slms_missing_student_code', __( 'No student record was found for that student code.', 'simple-lms' ) );
		}

		return $this->enroll_student( $section_id, $student_user_id, $args );
	}

	public function get_staff_user_id_by_code( $staff_code ) {
		global $wpdb;

		$staff_code = strtoupper( sanitize_text_field( $staff_code ) );
		$profiles   = Schema::table( 'user_profiles' );
		$users      = $wpdb->users;

		if ( '' === $staff_code ) {
			return 0;
		}

		$user_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT p.user_id
				FROM {$profiles} p
				INNER JOIN {$users} u ON u.ID = p.user_id
				WHERE p.person_code = %s AND p.person_type = %s
				LIMIT 1",
				$staff_code,
				'staff'
			)
		);

		return $user_id && $this->user_can_be_staff_auditor( $user_id ) ? $user_id : 0;
	}

	public function enroll_staff_auditor_by_code( $section_id, $staff_code, array $args = array() ) {
		$staff_user_id = $this->get_staff_user_id_by_code( $staff_code );

		if ( ! $staff_user_id ) {
			return new WP_Error( 'slms_missing_staff_code', __( 'No lecturer/staff record was found for that staff code.', 'simple-lms' ) );
		}

		return $this->enroll_staff_auditor( $section_id, $staff_user_id, $args );
	}

	public function enroll_staff_auditor( $section_id, $staff_user_id, array $args = array() ) {
		global $wpdb;

		$section_id    = absint( $section_id );
		$staff_user_id = absint( $staff_user_id );
		$status        = sanitize_key( $args['status'] ?? 'active' );
		$source        = sanitize_key( $args['source'] ?? 'manual_staff_code' );
		$notes         = sanitize_textarea_field( $args['notes'] ?? '' );
		$created_by    = absint( $args['created_by'] ?? get_current_user_id() );
		$now           = AcademicClock::mysql();

		if ( ! $this->is_course_post( $section_id ) ) {
			return new WP_Error( 'slms_invalid_section', __( 'Invalid subject.', 'simple-lms' ) );
		}

		if ( ! $this->user_can_be_staff_auditor( $staff_user_id ) ) {
			return new WP_Error( 'slms_invalid_staff_auditor', __( 'The selected user is not an eligible lecturer/staff auditor.', 'simple-lms' ) );
		}

		if ( ! in_array( $status, self::VALID_STAFF_AUDIT_STATUSES, true ) ) {
			$status = 'active';
		}

		if ( $this->user_can_teach_section( $staff_user_id, $section_id ) ) {
			return new WP_Error( 'slms_staff_already_teaches_section', __( 'This lecturer already teaches this subject and does not need audit enrollment.', 'simple-lms' ) );
		}

		$student_map = $this->get_student_subject_enrollment_map( $staff_user_id, array( $section_id ) );
		if ( ! empty( $student_map[ $section_id ] ) ) {
			return new WP_Error( 'slms_staff_already_student_enrolled', __( 'This user is already enrolled as a student in this subject.', 'simple-lms' ) );
		}

		$table    = Schema::table( 'staff_audit_enrollments' );
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE staff_user_id = %d AND section_id = %d LIMIT 1",
				$staff_user_id,
				$section_id
			),
			ARRAY_A
		);

		$data = array(
			'staff_user_id' => $staff_user_id,
			'section_id'    => $section_id,
			'status'        => $status,
			'source'        => $source,
			'notes'         => $notes,
			'created_by'    => ! empty( $existing['created_by'] ) ? (int) $existing['created_by'] : $created_by,
			'enrolled_at'   => $existing['enrolled_at'] ?? $now,
			'completed_at'  => 'completed' === $status ? ( $existing['completed_at'] ?? $now ) : null,
			'dropped_at'    => 'dropped' === $status ? ( $existing['dropped_at'] ?? $now ) : null,
			'updated_at'    => $now,
		);

		$format = array( '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' );

		if ( ! empty( $existing['id'] ) ) {
			$previous_status = sanitize_key( $existing['status'] ?? '' );
			$updated         = $wpdb->update(
				$table,
				$data,
				array( 'id' => (int) $existing['id'] ),
				$format,
				array( '%d' )
			);

			if ( false === $updated ) {
				return new WP_Error( 'slms_staff_audit_update_failed', __( 'The staff audit enrollment could not be updated.', 'simple-lms' ) );
			}

			$this->log_staff_audit_event_for_status(
				$status,
				$section_id,
				$staff_user_id,
				$created_by,
				array(
					'audit_enrollment_id' => (int) $existing['id'],
					'previous_status'     => $previous_status,
					'source'              => $source,
				)
			);

			return (int) $existing['id'];
		}

		$inserted = $wpdb->insert( $table, $data, $format );
		if ( false === $inserted ) {
			return new WP_Error( 'slms_staff_audit_insert_failed', __( 'The staff auditor could not be enrolled.', 'simple-lms' ) );
		}

		$insert_id = (int) $wpdb->insert_id;
		$this->log_staff_audit_event_for_status(
			$status,
			$section_id,
			$staff_user_id,
			$created_by,
			array(
				'audit_enrollment_id' => $insert_id,
				'source'              => $source,
			)
		);

		return $insert_id;
	}

	public function update_staff_audit_status( $section_id, $staff_user_id, $status ) {
		global $wpdb;

		$section_id    = absint( $section_id );
		$staff_user_id = absint( $staff_user_id );
		$status        = sanitize_key( $status );

		if ( ! $section_id || ! $staff_user_id || ! in_array( $status, self::VALID_STAFF_AUDIT_STATUSES, true ) ) {
			return false;
		}

		$existing = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::table( 'staff_audit_enrollments' ) . ' WHERE section_id = %d AND staff_user_id = %d LIMIT 1',
				$section_id,
				$staff_user_id
			),
			ARRAY_A
		);

		if ( empty( $existing['id'] ) ) {
			return false;
		}

		$now  = AcademicClock::mysql();
		$data = array(
			'status'       => $status,
			'updated_at'   => $now,
			'completed_at' => 'completed' === $status ? ( $existing['completed_at'] ?: $now ) : null,
			'dropped_at'   => 'dropped' === $status ? ( $existing['dropped_at'] ?: $now ) : null,
		);

		$updated = $wpdb->update(
			Schema::table( 'staff_audit_enrollments' ),
			$data,
			array( 'id' => (int) $existing['id'] ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return false;
		}

		$this->log_staff_audit_event_for_status(
			$status,
			$section_id,
			$staff_user_id,
			get_current_user_id(),
			array(
				'audit_enrollment_id' => (int) $existing['id'],
				'previous_status'     => sanitize_key( $existing['status'] ?? '' ),
			)
		);

		return true;
	}

	public function drop_staff_auditor_from_section( $section_id, $staff_user_id ) {
		if ( ! $this->user_can_audit_section( $staff_user_id, $section_id ) ) {
			return new WP_Error( 'slms_staff_audit_not_found', __( 'That staff auditor is not actively auditing this subject.', 'simple-lms' ) );
		}

		return $this->update_staff_audit_status( $section_id, $staff_user_id, 'dropped' );
	}

	public function list_staff_audit_enrollments( array $args = array() ) {
		global $wpdb;

		$table    = Schema::table( 'staff_audit_enrollments' );
		$users    = $wpdb->users;
		$profiles = Schema::table( 'user_profiles' );
		$where    = 'WHERE 1=1';
		$params   = array();

		if ( ! empty( $args['section_id'] ) ) {
			$where   .= ' AND a.section_id = %d';
			$params[] = absint( $args['section_id'] );
		}

		if ( ! empty( $args['staff_user_id'] ) ) {
			$where   .= ' AND a.staff_user_id = %d';
			$params[] = absint( $args['staff_user_id'] );
		}

		if ( ! empty( $args['statuses'] ) && is_array( $args['statuses'] ) ) {
			$statuses = array_values( array_unique( array_intersect( array_map( 'sanitize_key', $args['statuses'] ), self::VALID_STAFF_AUDIT_STATUSES ) ) );
			if ( $statuses ) {
				$where   .= ' AND a.status IN (' . implode( ', ', array_fill( 0, count( $statuses ), '%s' ) ) . ')';
				$params = array_merge( $params, $statuses );
			}
		} elseif ( ! empty( $args['status'] ) && in_array( $args['status'], self::VALID_STAFF_AUDIT_STATUSES, true ) ) {
			$where   .= ' AND a.status = %s';
			$params[] = sanitize_key( $args['status'] );
		}

		$sql = "
			SELECT a.*, u.display_name AS staff_name, u.user_email AS staff_email, p.person_code AS staff_code, p.department, p.position_title
			FROM {$table} a
			INNER JOIN {$users} u ON u.ID = a.staff_user_id
			LEFT JOIN {$profiles} p ON p.user_id = a.staff_user_id
			{$where}
			ORDER BY u.display_name ASC, a.updated_at DESC
		";

		if ( $params ) {
			$sql = $wpdb->prepare( $sql, $params );
		}

		return $wpdb->get_results( $sql, ARRAY_A );
	}


	public function user_can_access_section( $user_id, $section_id ) {
		$user_id    = absint( $user_id );
		$section_id = absint( $section_id );

		if ( ! $user_id || ! $section_id ) {
			return false;
		}

		if ( in_array( RoleManager::get_primary_role( $user_id ), array( 'administrator', 'officer' ), true ) ) {
			return true;
		}

		return in_array( $section_id, $this->get_accessible_section_ids( $user_id ), true );
	}


	public function user_can_audit_section( $user_id, $section_id ) {
		$user_id    = absint( $user_id );
		$section_id = absint( $section_id );

		if ( ! $user_id || ! $section_id ) {
			return false;
		}

		return in_array( $section_id, $this->get_staff_audit_section_ids( $user_id ), true );
	}

	public function user_can_learn_section( $user_id, $section_id ) {
		$user_id    = absint( $user_id );
		$section_id = absint( $section_id );

		if ( ! $user_id || ! $section_id ) {
			return false;
		}

		if ( $this->user_can_teach_section( $user_id, $section_id ) ) {
			return true;
		}

		if ( $this->user_can_audit_section( $user_id, $section_id ) ) {
			return true;
		}

		return $this->user_can_access_section( $user_id, $section_id );
	}

	public function user_can_teach_section( $user_id, $section_id ) {
		$user_id    = absint( $user_id );
		$section_id = absint( $section_id );
		$role       = RoleManager::get_primary_role( $user_id );

		if ( ! $user_id || ! $section_id ) {
			return false;
		}

		if ( in_array( $role, array( 'administrator', 'officer' ), true ) ) {
			return true;
		}

		return 'lecturer' === $role && in_array( $section_id, $this->get_teaching_section_ids_for_user( $user_id ), true );
	}

	public function user_can_manage_section( $user_id, $section_id ) {
		return $this->user_can_teach_section( $user_id, $section_id );
	}



	private function log_staff_audit_event_for_status( $status, $section_id, $staff_user_id, $actor_user_id = 0, array $context = array() ) {
		$event_type = '';

		switch ( $status ) {
			case 'active':
				$event_type = 'staff_auditor_enrolled';
				break;
			case 'dropped':
				$event_type = 'staff_auditor_dropped';
				break;
			case 'completed':
				$event_type = 'staff_auditor_completed';
				break;
		}

		if ( ! $event_type || ! $this->audit_logger ) {
			return false;
		}

		$section_id    = absint( $section_id );
		$staff_user_id = absint( $staff_user_id );
		$actor_user_id = absint( $actor_user_id ?: get_current_user_id() );
		$context       = array_merge(
			array(
				'section_id'    => $section_id,
				'staff_user_id' => $staff_user_id,
				'status'        => sanitize_key( $status ),
			),
			$context
		);

		return $this->audit_logger->log(
			$event_type,
			array(
				'actor_user_id' => $actor_user_id,
				'object_type'   => 'staff_audit_enrollment',
				'object_id'     => absint( $context['audit_enrollment_id'] ?? 0 ),
				'message'       => sprintf(
					/* translators: 1: staff user ID, 2: subject ID, 3: audit status. */
					__( 'Staff auditor %1$d for subject %2$d changed to %3$s.', 'simple-lms' ),
					$staff_user_id,
					$section_id,
					sanitize_key( $status )
				),
				'context'       => $context,
			)
		);
	}


	private function user_can_be_staff_auditor( $user_id ) {
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return false;
		}

		$roles = (array) $user->roles;

		return in_array( 'lecturer', $roles, true ) || in_array( 'officer', $roles, true ) || in_array( 'administrator', $roles, true );
	}

	private function filter_valid_course_post_ids( array $section_ids ) {
		$valid_ids = array();

		foreach ( array_values( array_unique( array_filter( array_map( 'absint', $section_ids ) ) ) ) as $section_id ) {
			if ( $this->is_course_post( $section_id ) ) {
				$valid_ids[] = $section_id;
			}
		}

		return $valid_ids;
	}

	private function user_is_student( $user_id ) {
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return false;
		}

		return in_array( 'student', (array) $user->roles, true );
	}

	private function user_can_be_section_staff( $user_id ) {
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return false;
		}

		$roles = (array) $user->roles;

		return (bool) array_intersect( array( 'lecturer', 'officer', 'administrator' ), $roles );
	}

	private function list_sections_query( array $args = array() ) {
		global $wpdb;

		$posts              = $wpdb->posts;
		$postmeta           = $wpdb->postmeta;
		$terms_table        = Schema::table( 'terms' );
		$enrollments_table  = Schema::table( 'enrollments' );
		$attendance_table   = Schema::table( 'attendance_sessions' );
		$post_statuses      = ! empty( $args['post_statuses'] ) ? (array) $args['post_statuses'] : array( 'publish', 'private' );
		$joins_sql          = $args['joins_sql'] ?? '';
		$where_sql          = $args['where_sql'] ?? '';
		$params             = $args['params'] ?? array();
		$extra_select       = $args['extra_select'] ?? '';
		$relationship_type  = $args['relationship'] ?? 'learning';
		$status_placeholders = implode( ',', array_fill( 0, count( $post_statuses ), '%s' ) );

		$course_post_types   = ! empty( $args['course_post_types'] ) ? (array) $args['course_post_types'] : self::COURSE_POST_TYPES;
		$type_placeholders   = implode( ',', array_fill( 0, count( $course_post_types ), '%s' ) );

		$sql = "
			SELECT DISTINCT
				section_posts.ID AS id,
				section_posts.post_title AS title,
				section_posts.post_type AS post_type,
				COALESCE(
					CASE
						WHEN section_posts.post_type = 'slms_subject' THEN subject_code_meta.meta_value
						ELSE section_code_meta.meta_value
					END,
					''
				) AS section_code,
				COALESCE(subject_code_meta.meta_value, '') AS subject_code,
				COALESCE(
					CASE
						WHEN section_posts.post_type = 'slms_subject' THEN subject_status_meta.meta_value
						ELSE section_status_meta.meta_value
					END,
					''
				) AS section_status,
				COALESCE(delivery_meta.meta_value, '') AS delivery_mode,
				CAST(COALESCE(capacity_meta.meta_value, 0) AS UNSIGNED) AS capacity,
				CASE
					WHEN section_posts.post_type = 'slms_subject' THEN section_posts.ID
					ELSE CAST(COALESCE(subject_meta.meta_value, 0) AS UNSIGNED)
				END AS subject_id,
				CASE
					WHEN section_posts.post_type = 'slms_subject' THEN section_posts.post_title
					ELSE COALESCE(subject_posts.post_title, '')
				END AS subject_title,
				CAST(COALESCE(term_meta.meta_value, 0) AS UNSIGNED) AS term_id,
				COALESCE(term_rows.name, '') AS term_name,
				COALESCE(term_rows.academic_year, '') AS term_academic_year,
				CAST(COALESCE(enrollment_counts.enrollment_count, 0) AS UNSIGNED) AS enrollment_count,
				CAST(COALESCE(enrollment_counts.active_enrollment_count, 0) AS UNSIGNED) AS active_enrollment_count,
				CAST(COALESCE(attendance_counts.attendance_session_count, 0) AS UNSIGNED) AS attendance_session_count,
				COALESCE(attendance_counts.latest_session_date, '') AS latest_session_date,
				%s AS relationship_type
				{$extra_select}
			FROM {$posts} section_posts
			LEFT JOIN {$postmeta} section_code_meta ON section_code_meta.post_id = section_posts.ID AND section_code_meta.meta_key = '_slms_section_code'
			LEFT JOIN {$postmeta} section_status_meta ON section_status_meta.post_id = section_posts.ID AND section_status_meta.meta_key = '_slms_section_status'
			LEFT JOIN {$postmeta} subject_code_meta ON subject_code_meta.post_id = section_posts.ID AND subject_code_meta.meta_key = '_slms_subject_code'
			LEFT JOIN {$postmeta} subject_status_meta ON subject_status_meta.post_id = section_posts.ID AND subject_status_meta.meta_key = '_slms_subject_status'
			LEFT JOIN {$postmeta} delivery_meta ON delivery_meta.post_id = section_posts.ID AND delivery_meta.meta_key = '_slms_delivery_mode'
			LEFT JOIN {$postmeta} capacity_meta ON capacity_meta.post_id = section_posts.ID AND capacity_meta.meta_key = '_slms_capacity'
			LEFT JOIN {$postmeta} subject_meta ON subject_meta.post_id = section_posts.ID AND subject_meta.meta_key = '_slms_subject_id'
			LEFT JOIN {$posts} subject_posts ON subject_posts.ID = CAST(subject_meta.meta_value AS UNSIGNED)
			LEFT JOIN {$postmeta} term_meta ON term_meta.post_id = section_posts.ID AND term_meta.meta_key = '_slms_term_id'
			LEFT JOIN {$terms_table} term_rows ON term_rows.id = CAST(term_meta.meta_value AS UNSIGNED)
			LEFT JOIN (
				SELECT
					section_id,
					COUNT(*) AS enrollment_count,
					SUM(CASE WHEN status IN ('enrolled', 'waitlisted') THEN 1 ELSE 0 END) AS active_enrollment_count
				FROM {$enrollments_table}
				GROUP BY section_id
			) enrollment_counts ON enrollment_counts.section_id = section_posts.ID
			LEFT JOIN (
				SELECT
					section_id,
					COUNT(*) AS attendance_session_count,
					MAX(session_date) AS latest_session_date
				FROM {$attendance_table}
				GROUP BY section_id
			) attendance_counts ON attendance_counts.section_id = section_posts.ID
			{$joins_sql}
			WHERE section_posts.post_type IN ({$type_placeholders})
				AND section_posts.post_status IN ({$status_placeholders})
				{$where_sql}
			ORDER BY term_rows.academic_year DESC, term_rows.sort_order ASC, section_posts.post_title ASC
		";

		$prepared = array_merge(
			array( $relationship_type ),
			$course_post_types,
			$post_statuses,
			$params
		);

		return $wpdb->get_results( $wpdb->prepare( $sql, $prepared ), ARRAY_A );
	}

	private function is_course_post( $post_id ) {
		$post_id = absint( $post_id );

		if ( ! $post_id ) {
			return false;
		}

		return in_array( get_post_type( $post_id ), self::COURSE_POST_TYPES, true );
	}
}
