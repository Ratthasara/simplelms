<?php

namespace SimpleLMS\Domain\Academic;

use SimpleLMS\Core\AcademicClock;
use SimpleLMS\Domain\Users\RoleManager;
use SimpleLMS\Infrastructure\Database\Schema;
use SimpleLMS\Infrastructure\Logging\AuditLogger;
use WP_Error;

defined( 'ABSPATH' ) || exit;

class AttendanceService {
	const ATTENDANCE_STATUSES = array( 'present', 'late', 'leave', 'absent' );
	const SESSION_STATUSES    = array( 'scheduled', 'completed', 'cancelled' );

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

	public function save_session( $section_id, array $data, $user_id = 0 ) {
		global $wpdb;

		$section_id = absint( $section_id );
		$user_id    = absint( $user_id ?: get_current_user_id() );
		$session_id = absint( $data['session_id'] ?? 0 );

		if ( ! $this->can_manage_attendance( $user_id, $section_id ) ) {
			return new WP_Error( 'slms_forbidden_attendance', __( 'You do not have permission to manage attendance for that section.', 'simple-lms' ) );
		}

		if ( ! $this->is_attendance_course( $section_id ) ) {
			return new WP_Error( 'slms_invalid_attendance_section', __( 'Please choose a valid subject.', 'simple-lms' ) );
		}

		$existing = $session_id ? $this->get_session( $session_id ) : null;

		if ( $existing && (int) $existing['section_id'] !== $section_id ) {
			return new WP_Error( 'slms_attendance_session_mismatch', __( 'That attendance session belongs to a different section.', 'simple-lms' ) );
		}

		$session_date = sanitize_text_field( $data['session_date'] ?? '' );
		$status       = sanitize_key( $data['status'] ?? 'scheduled' );
		$title        = sanitize_text_field( $data['session_title'] ?? '' );
		$notes        = sanitize_textarea_field( $data['notes'] ?? '' );
		$week_label   = sanitize_text_field( $data['week_label'] ?? '' );
		$starts_at    = $this->normalize_time_value( $data['starts_at'] ?? '' );
		$ends_at      = $this->normalize_time_value( $data['ends_at'] ?? '' );

		if ( ! $this->is_valid_date( $session_date ) ) {
			return new WP_Error( 'slms_invalid_attendance_date', __( 'Please provide a valid class date.', 'simple-lms' ) );
		}

		if ( ! in_array( $status, self::SESSION_STATUSES, true ) ) {
			$status = 'scheduled';
		}

		$session_number = isset( $data['session_number'] ) ? absint( $data['session_number'] ) : 0;

		if ( ! $session_number ) {
			$session_number = $existing ? (int) $existing['session_number'] : $this->get_next_session_number( $section_id );
		}

		if ( '' === $title ) {
			$title = sprintf( __( 'Class %d', 'simple-lms' ), $session_number );
		}

		$table   = Schema::table( 'attendance_sessions' );
		$now     = AcademicClock::mysql();
		$payload = array(
			'section_id'      => $section_id,
			'session_number'  => $session_number,
			'session_title'   => $title,
			'session_date'    => $session_date,
			'starts_at'       => $starts_at ?: null,
			'ends_at'         => $ends_at ?: null,
			'week_label'      => $week_label ?: null,
			'notes'           => $notes ?: null,
			'status'          => $status,
			'created_by'      => $existing ? (int) $existing['created_by'] : $user_id,
			'updated_by'      => $user_id,
			'created_at'      => $existing['created_at'] ?? $now,
			'updated_at'      => $now,
		);

		if ( $existing ) {
			$updated = $wpdb->update( $table, $payload, array( 'id' => $session_id ) );

			if ( false === $updated ) {
				return new WP_Error( 'slms_attendance_session_update_failed', __( 'The class session could not be updated.', 'simple-lms' ) );
			}
		} else {
			$inserted = $wpdb->insert( $table, $payload );

			if ( false === $inserted ) {
				return new WP_Error( 'slms_attendance_session_insert_failed', __( 'The class session could not be created.', 'simple-lms' ) );
			}

			$session_id = (int) $wpdb->insert_id;
		}

		$this->audit_logger->log(
			$existing ? 'attendance_session_updated' : 'attendance_session_created',
			array(
				'object_type' => 'attendance_session',
				'object_id'   => $session_id,
				'message'     => $existing ? 'An attendance session was updated.' : 'An attendance session was created.',
				'context'     => array(
					'section_id'     => $section_id,
					'session_number' => $session_number,
					'session_date'   => $session_date,
					'status'         => $status,
				),
			)
		);

		return $session_id;
	}

	public function delete_session( $session_id, $user_id = 0 ) {
		global $wpdb;

		$session_id = absint( $session_id );
		$user_id    = absint( $user_id ?: get_current_user_id() );
		$session    = $this->get_session( $session_id );

		if ( ! $session ) {
			return new WP_Error( 'slms_invalid_attendance_session', __( 'Invalid attendance session.', 'simple-lms' ) );
		}

		if ( ! $this->can_manage_attendance( $user_id, (int) $session['section_id'] ) ) {
			return new WP_Error( 'slms_forbidden_attendance', __( 'You do not have permission to delete that class session.', 'simple-lms' ) );
		}

		$wpdb->delete( Schema::table( 'attendance_records' ), array( 'session_id' => $session_id ), array( '%d' ) );
		$deleted = $wpdb->delete( Schema::table( 'attendance_sessions' ), array( 'id' => $session_id ), array( '%d' ) );

		if ( false === $deleted ) {
			return new WP_Error( 'slms_attendance_session_delete_failed', __( 'The class session could not be deleted.', 'simple-lms' ) );
		}

		$this->audit_logger->log(
			'attendance_session_deleted',
			array(
				'object_type' => 'attendance_session',
				'object_id'   => $session_id,
				'message'     => 'An attendance session was deleted.',
				'context'     => array(
					'section_id' => (int) $session['section_id'],
				),
			)
		);

		return true;
	}

	public function list_sessions( $section_id ) {
		global $wpdb;

		$section_id = absint( $section_id );
		$sessions   = Schema::table( 'attendance_sessions' );
		$records    = Schema::table( 'attendance_records' );
		$sql        = $wpdb->prepare(
			"SELECT s.*,
				COUNT(r.id) AS record_count,
				SUM(CASE WHEN r.attendance_status IN ('present', 'excused') THEN 1 ELSE 0 END) AS present_count,
				SUM(CASE WHEN r.attendance_status = 'late' THEN 1 ELSE 0 END) AS late_count,
				SUM(CASE WHEN r.attendance_status = 'leave' THEN 1 ELSE 0 END) AS leave_count,
				SUM(CASE WHEN r.attendance_status = 'absent' THEN 1 ELSE 0 END) AS absent_count
			FROM {$sessions} s
			LEFT JOIN {$records} r ON r.session_id = s.id
			WHERE s.section_id = %d
			GROUP BY s.id
			ORDER BY s.session_date DESC, s.session_number DESC, s.id DESC",
			$section_id
		);

		return array_map( array( $this, 'format_session_row' ), $wpdb->get_results( $sql, ARRAY_A ) );
	}

	public function get_session( $session_id ) {
		global $wpdb;

		$session_id = absint( $session_id );
		$sessions   = Schema::table( 'attendance_sessions' );
		$records    = Schema::table( 'attendance_records' );
		$sql        = $wpdb->prepare(
			"SELECT s.*,
				COUNT(r.id) AS record_count,
				SUM(CASE WHEN r.attendance_status IN ('present', 'excused') THEN 1 ELSE 0 END) AS present_count,
				SUM(CASE WHEN r.attendance_status = 'late' THEN 1 ELSE 0 END) AS late_count,
				SUM(CASE WHEN r.attendance_status = 'leave' THEN 1 ELSE 0 END) AS leave_count,
				SUM(CASE WHEN r.attendance_status = 'absent' THEN 1 ELSE 0 END) AS absent_count
			FROM {$sessions} s
			LEFT JOIN {$records} r ON r.session_id = s.id
			WHERE s.id = %d
			GROUP BY s.id
			LIMIT 1",
			$session_id
		);
		$row        = $wpdb->get_row( $sql, ARRAY_A );

		return $row ? $this->format_session_row( $row ) : null;
	}

	public function get_session_by_number( $section_id, $session_number ) {
		global $wpdb;

		$section_id     = absint( $section_id );
		$session_number = absint( $session_number );

		if ( ! $section_id || ! $session_number ) {
			return null;
		}

		$sessions = Schema::table( 'attendance_sessions' );
		$records  = Schema::table( 'attendance_records' );
		$sql      = $wpdb->prepare(
			"SELECT s.*,
				COUNT(r.id) AS record_count,
				SUM(CASE WHEN r.attendance_status IN ('present', 'excused') THEN 1 ELSE 0 END) AS present_count,
				SUM(CASE WHEN r.attendance_status = 'late' THEN 1 ELSE 0 END) AS late_count,
				SUM(CASE WHEN r.attendance_status = 'leave' THEN 1 ELSE 0 END) AS leave_count,
				SUM(CASE WHEN r.attendance_status = 'absent' THEN 1 ELSE 0 END) AS absent_count
			FROM {$sessions} s
			LEFT JOIN {$records} r ON r.session_id = s.id
			WHERE s.section_id = %d AND s.session_number = %d
			GROUP BY s.id
			ORDER BY s.id DESC
			LIMIT 1",
			$section_id,
			$session_number
		);
		$row      = $wpdb->get_row( $sql, ARRAY_A );

		return $row ? $this->format_session_row( $row ) : null;
	}

	public function get_session_records( $session_id ) {
		global $wpdb;

		$session_id = absint( $session_id );
		$records    = Schema::table( 'attendance_records' );
		$users      = $wpdb->users;
		$sql        = $wpdb->prepare(
			"SELECT r.*, u.display_name, u.user_email
			FROM {$records} r
			INNER JOIN {$users} u ON u.ID = r.student_user_id
			WHERE r.session_id = %d
			ORDER BY u.display_name ASC",
			$session_id
		);

		return array_map(
			function ( $row ) {
				$row['id']              = (int) $row['id'];
				$row['session_id']      = (int) $row['session_id'];
				$row['section_id']      = (int) $row['section_id'];
				$row['student_user_id'] = (int) $row['student_user_id'];
				$row['minutes_late']    = (int) $row['minutes_late'];

				return $row;
			},
			$wpdb->get_results( $sql, ARRAY_A )
		);
	}

	public function save_session_records( $session_id, array $records, $user_id = 0 ) {
		global $wpdb;

		$session_id = absint( $session_id );
		$user_id    = absint( $user_id ?: get_current_user_id() );
		$session    = $this->get_session( $session_id );

		if ( ! $session ) {
			return new WP_Error( 'slms_invalid_attendance_session', __( 'Invalid attendance session.', 'simple-lms' ) );
		}

		if ( ! $this->can_manage_attendance( $user_id, (int) $session['section_id'] ) ) {
			return new WP_Error( 'slms_forbidden_attendance', __( 'You do not have permission to record attendance for that section.', 'simple-lms' ) );
		}

		$students     = $this->get_section_students( (int) $session['section_id'] );
		$students_map = array();

		foreach ( $students as $student ) {
			$students_map[ (int) $student['student_user_id'] ] = $student;
		}

		$existing_map = array();

		foreach ( $this->get_session_records( $session_id ) as $row ) {
			$existing_map[ (int) $row['student_user_id'] ] = $row;
		}

		$table   = Schema::table( 'attendance_records' );
		$now     = AcademicClock::mysql();
		$saved   = 0;

		foreach ( $records as $student_user_id => $record ) {
			$student_user_id = absint( $student_user_id );

			if ( empty( $students_map[ $student_user_id ] ) || ! is_array( $record ) ) {
				continue;
			}

			$status       = $this->normalize_attendance_status( $record['status'] ?? 'present' );
			$minutes_late = 'late' === $status ? max( 0, absint( $record['minutes_late'] ?? 0 ) ) : 0;
			$notes        = sanitize_textarea_field( $record['notes'] ?? '' );
			$existing     = $existing_map[ $student_user_id ] ?? null;
			$payload      = array(
				'session_id'         => $session_id,
				'section_id'         => (int) $session['section_id'],
				'student_user_id'    => $student_user_id,
				'attendance_status'  => $status,
				'minutes_late'       => $minutes_late,
				'notes'              => $notes ?: null,
				'marked_by'          => $user_id,
				'created_at'         => $existing['created_at'] ?? $now,
				'updated_at'         => $now,
			);

			if ( $existing ) {
				$updated = $wpdb->update( $table, $payload, array( 'id' => (int) $existing['id'] ) );

				if ( false !== $updated ) {
					$saved++;
				}
			} else {
				$inserted = $wpdb->insert( $table, $payload );

				if ( false !== $inserted ) {
					$saved++;
				}
			}
		}

		$wpdb->update(
			Schema::table( 'attendance_sessions' ),
			array(
				'status'     => 'cancelled' === $session['status'] ? 'cancelled' : 'completed',
				'updated_by' => $user_id,
				'updated_at' => $now,
			),
			array( 'id' => $session_id )
		);

		$this->audit_logger->log(
			'attendance_records_saved',
			array(
				'object_type' => 'attendance_session',
				'object_id'   => $session_id,
				'message'     => 'Attendance records were saved for a class session.',
				'context'     => array(
					'section_id'    => (int) $session['section_id'],
					'record_count'  => $saved,
				),
			)
		);

		return $saved;
	}

	public function get_section_attendance_dashboard( $section_id, $user_id = 0 ) {
		$section_id = absint( $section_id );
		$user_id    = absint( $user_id ?: get_current_user_id() );

		if ( ! $this->can_view_attendance( $user_id, $section_id ) ) {
			return new WP_Error( 'slms_forbidden_attendance', __( 'You do not have access to that attendance register.', 'simple-lms' ) );
		}

		$section     = $this->enrollments->get_section_summary( $section_id );
		$students    = $this->get_section_students( $section_id );
		$sessions    = $this->list_sessions( $section_id );
		$planned_sessions = $this->get_planned_session_count( $section_id, $sessions );
		$summary_map = $this->get_student_attendance_summary_map( $section_id, $students, $sessions );
		$average     = 0.0;

		foreach ( $students as &$student ) {
			$summary                         = $summary_map[ (int) $student['student_user_id'] ] ?? $this->empty_student_summary();
			$student['attendance_summary']   = $summary;
			$average                        += (float) $summary['attendance_percentage'];
		}
		unset( $student );

		return array(
			'section'              => $section,
			'sessions'             => $sessions,
			'students'             => $students,
			'student_summary_map'  => $summary_map,
			'planned_sessions'     => $planned_sessions,
			'total_sessions'       => count( $sessions ),
			'completed_sessions'   => count(
				array_filter(
					$sessions,
					static function ( $session ) {
						return 'cancelled' !== $session['status'] && (int) $session['record_count'] > 0;
					}
				)
			),
			'average_attendance'   => ! empty( $students ) ? round( $average / count( $students ), 2 ) : 0,
			'next_session_number'  => $this->get_next_session_number( $section_id ),
		);
	}

	public function get_student_attendance_summary_map( $section_id, ?array $students = null, ?array $sessions = null ) {
		$section_id = absint( $section_id );
		$students   = is_array( $students ) ? $students : $this->get_section_students( $section_id );
		$sessions   = is_array( $sessions ) ? $sessions : $this->list_sessions( $section_id );
		$records    = $this->get_section_record_map( $section_id );
		$planned_sessions = $this->get_planned_session_count( $section_id, $sessions );
		$summary    = array();

		foreach ( $students as $student ) {
			$student_id       = (int) $student['student_user_id'];
			$enrolled_on      = ! empty( $student['enrolled_at'] ) ? AcademicClock::format_local( $student['enrolled_at'], 'Y-m-d' ) : '';
			$eligible_sessions = 0;
			$completed_before_enrollment = 0;
			$earned_points    = 0.0;
			$counts           = array(
				'present' => 0,
				'late'    => 0,
				'leave'   => 0,
				'absent'  => 0,
			);

			foreach ( $sessions as $session ) {
				if ( 'cancelled' === $session['status'] || (int) $session['record_count'] <= 0 ) {
					continue;
				}

				if ( $enrolled_on && $session['session_date'] < $enrolled_on ) {
					$completed_before_enrollment++;
					continue;
				}

				$eligible_sessions++;

				$record = $records[ $session['id'] . ':' . $student_id ] ?? array(
					'attendance_status' => 'absent',
				);
				$status = $this->normalize_attendance_status( $record['attendance_status'] ?? 'absent' );

				$counts[ $status ]++;
				$earned_points += $this->status_points( $status );
			}

			$planned_for_student = max( 0, $planned_sessions - $completed_before_enrollment );
			$attendance_base     = $planned_for_student > 0 ? $planned_for_student : $eligible_sessions;

			$summary[ $student_id ] = array(
				'planned_sessions'      => $planned_for_student,
				'eligible_sessions'     => $eligible_sessions,
				'present_sessions'      => $counts['present'],
				'late_sessions'         => $counts['late'],
				'leave_sessions'        => $counts['leave'],
				'absent_sessions'       => $counts['absent'],
				'earned_points'         => round( $earned_points, 2 ),
				'attendance_percentage' => round( $attendance_base > 0 ? ( $earned_points / $attendance_base ) * 100 : 0, 2 ),
			);
		}

		return $summary;
	}

	public function get_student_attendance_percentage( $section_id, $student_user_id ) {
		$summary = $this->get_student_attendance_summary_map( $section_id );

		return (float) ( $summary[ absint( $student_user_id ) ]['attendance_percentage'] ?? 0 );
	}

	public function get_attendance_review( $days = 7, $include_late = false, $limit = 50 ) {
		global $wpdb;

		$days = absint( $days );
		if ( ! in_array( $days, array( 7, 28, 112 ), true ) ) {
			$days = 7;
		}

		$limit        = max( 1, min( 100, absint( $limit ) ) );
		$statuses     = $include_late ? array( 'absent', 'leave', 'late' ) : array( 'absent', 'leave' );
		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		$sessions     = Schema::table( 'attendance_sessions' );
		$records      = Schema::table( 'attendance_records' );
		$profiles     = Schema::table( 'user_profiles' );
		$users        = $wpdb->users;
		$posts        = $wpdb->posts;
		$today        = AcademicClock::date( 'Y-m-d' );
		$args         = array_merge( array( $today, $days ), $statuses, array( $limit ) );
		$sql          = $wpdb->prepare(
			"SELECT
				r.student_user_id,
				u.display_name AS student_name,
				u.user_email AS student_email,
				p.person_code,
				SUM(CASE WHEN r.attendance_status = 'absent' THEN 1 ELSE 0 END) AS absent_count,
				SUM(CASE WHEN r.attendance_status = 'leave' THEN 1 ELSE 0 END) AS leave_count,
				SUM(CASE WHEN r.attendance_status = 'late' THEN 1 ELSE 0 END) AS late_count,
				COUNT(r.id) AS issue_count,
				MAX(s.session_date) AS latest_date,
				SUBSTRING_INDEX(GROUP_CONCAT(r.attendance_status ORDER BY s.session_date DESC, s.session_number DESC, r.id DESC SEPARATOR ','), ',', 1) AS latest_status,
				SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(NULLIF(post.post_title, ''), s.session_title) ORDER BY s.session_date DESC, s.session_number DESC, r.id DESC SEPARATOR '||'), '||', 1) AS latest_subject
			FROM {$records} r
			INNER JOIN {$sessions} s ON s.id = r.session_id
			INNER JOIN {$users} u ON u.ID = r.student_user_id
			LEFT JOIN {$profiles} p ON p.user_id = r.student_user_id
			LEFT JOIN {$posts} post ON post.ID = s.section_id
			WHERE s.session_date >= DATE_SUB(%s, INTERVAL %d DAY)
				AND s.status <> 'cancelled'
				AND r.attendance_status IN ({$placeholders})
			GROUP BY r.student_user_id, u.display_name, u.user_email, p.person_code
			ORDER BY latest_date DESC, issue_count DESC, student_name ASC
			LIMIT %d",
			$args
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A );

		return array_map(
			static function ( $row ) {
				return array(
					'student_user_id' => (int) $row['student_user_id'],
					'student_name'    => $row['student_name'],
					'student_email'   => $row['student_email'],
					'person_code'     => $row['person_code'],
					'absent_count'    => (int) $row['absent_count'],
					'leave_count'     => (int) $row['leave_count'],
					'late_count'      => (int) $row['late_count'],
					'issue_count'     => (int) $row['issue_count'],
					'latest_date'     => $row['latest_date'],
					'latest_status'   => $row['latest_status'],
					'latest_subject'  => $row['latest_subject'],
				);
			},
			$rows
		);
	}


	public function get_grouped_attendance_review( $days = 7, $include_late = false, $limit = 2000 ) {
		global $wpdb;

		$days = absint( $days );
		if ( ! in_array( $days, array( 7, 28, 112 ), true ) ) {
			$days = 7;
		}

		$limit        = max( 100, min( 5000, absint( $limit ) ) );
		$statuses     = $include_late ? array( 'absent', 'leave', 'late' ) : array( 'absent', 'leave' );
		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		$sessions     = Schema::table( 'attendance_sessions' );
		$records      = Schema::table( 'attendance_records' );
		$profiles     = Schema::table( 'user_profiles' );
		$users        = $wpdb->users;
		$posts        = $wpdb->posts;
		$postmeta     = $wpdb->postmeta;
		$today        = AcademicClock::date( 'Y-m-d' );
		$args         = array_merge( array( $today, $days ), $statuses, array( $limit ) );
		$sql          = $wpdb->prepare(
			"SELECT
				r.student_user_id,
				u.display_name AS student_name,
				u.user_email AS student_email,
				p.person_code,
				p.program_id AS profile_program_id,
				s.section_id,
				section_post.post_title AS section_title,
				section_post.post_type AS section_post_type,
				subject_meta.meta_value AS subject_meta_id,
				subject_post.post_title AS subject_title,
				SUM(CASE WHEN r.attendance_status = 'absent' THEN 1 ELSE 0 END) AS absent_count,
				SUM(CASE WHEN r.attendance_status = 'leave' THEN 1 ELSE 0 END) AS leave_count,
				SUM(CASE WHEN r.attendance_status = 'late' THEN 1 ELSE 0 END) AS late_count,
				COUNT(r.id) AS issue_count,
				MAX(s.session_date) AS latest_date,
				SUBSTRING_INDEX(GROUP_CONCAT(r.attendance_status ORDER BY s.session_date DESC, s.session_number DESC, r.id DESC SEPARATOR ','), ',', 1) AS latest_status,
				SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(NULLIF(s.session_title, ''), NULLIF(section_post.post_title, ''), 'Class') ORDER BY s.session_date DESC, s.session_number DESC, r.id DESC SEPARATOR '||'), '||', 1) AS latest_session_title
			FROM {$records} r
			INNER JOIN {$sessions} s ON s.id = r.session_id
			INNER JOIN {$users} u ON u.ID = r.student_user_id
			LEFT JOIN {$profiles} p ON p.user_id = r.student_user_id
			LEFT JOIN {$posts} section_post ON section_post.ID = s.section_id
			LEFT JOIN {$postmeta} subject_meta ON subject_meta.post_id = s.section_id AND subject_meta.meta_key = '_slms_subject_id'
			LEFT JOIN {$posts} subject_post ON subject_post.ID = CAST(subject_meta.meta_value AS UNSIGNED)
			WHERE s.session_date >= DATE_SUB(%s, INTERVAL %d DAY)
				AND s.status <> 'cancelled'
				AND r.attendance_status IN ({$placeholders})
			GROUP BY r.student_user_id, u.display_name, u.user_email, p.person_code, p.program_id, s.section_id, section_post.post_title, section_post.post_type, subject_meta.meta_value, subject_post.post_title
			ORDER BY section_title ASC, student_name ASC
			LIMIT %d",
			$args
		);

		$rows   = $wpdb->get_results( $sql, ARRAY_A );
		$groups = array();

		foreach ( $rows as $row ) {
			$section_id        = absint( $row['section_id'] ?? 0 );
			$section_type      = sanitize_key( $row['section_post_type'] ?? '' );
			$subject_meta_id   = absint( $row['subject_meta_id'] ?? 0 );
			$subject_id        = 'slms_subject' === $section_type ? $section_id : $subject_meta_id;
			$subject_key       = $subject_id ? 'subject-' . $subject_id : 'section-' . $section_id;
			$section_title     = sanitize_text_field( (string) ( $row['section_title'] ?? '' ) );
			$subject_title     = sanitize_text_field( (string) ( $row['subject_title'] ?? '' ) );
			$display_subject   = $subject_title ?: $section_title;
			$profile_program   = absint( $row['profile_program_id'] ?? 0 );
			$program_id        = 0;
			$program_name      = '';

			if ( $subject_id ) {
				$programs = wp_get_post_terms( $subject_id, 'slms_program', array( 'orderby' => 'name', 'order' => 'ASC' ) );
				if ( ! is_wp_error( $programs ) && ! empty( $programs ) ) {
					$program_id   = (int) $programs[0]->term_id;
					$program_name = $programs[0]->name;
				}
			}

			if ( ! $program_id && $profile_program ) {
				$program = get_term( $profile_program, 'slms_program' );
				if ( $program && ! is_wp_error( $program ) ) {
					$program_id   = (int) $program->term_id;
					$program_name = $program->name;
				}
			}

			if ( '' === $program_name ) {
				$program_name = __( 'Unassigned Program', 'simple-lms' );
			}

			if ( '' === $display_subject ) {
				$display_subject = __( 'Unassigned Subject', 'simple-lms' );
			}

			$program_key = $program_id ? 'program-' . $program_id : 'program-unassigned';

			if ( ! isset( $groups[ $program_key ] ) ) {
				$groups[ $program_key ] = array(
					'program_id'   => $program_id,
					'program_name' => $program_name,
					'totals'       => array( 'absent_count' => 0, 'leave_count' => 0, 'late_count' => 0, 'issue_count' => 0 ),
					'subjects'     => array(),
				);
			}

			if ( ! isset( $groups[ $program_key ]['subjects'][ $subject_key ] ) ) {
				$groups[ $program_key ]['subjects'][ $subject_key ] = array(
					'subject_id'    => $subject_id,
					'subject_title' => $display_subject,
					'totals'        => array( 'absent_count' => 0, 'leave_count' => 0, 'late_count' => 0, 'issue_count' => 0 ),
					'students'      => array(),
				);
			}

			$student_id = absint( $row['student_user_id'] ?? 0 );
			if ( ! $student_id ) {
				continue;
			}

			$student_key  = 'student-' . $student_id;
			$absent_count = (int) ( $row['absent_count'] ?? 0 );
			$leave_count  = (int) ( $row['leave_count'] ?? 0 );
			$late_count   = (int) ( $row['late_count'] ?? 0 );
			$issue_count  = (int) ( $row['issue_count'] ?? 0 );

			foreach ( array( 'absent_count' => $absent_count, 'leave_count' => $leave_count, 'late_count' => $late_count, 'issue_count' => $issue_count ) as $key => $value ) {
				$groups[ $program_key ]['totals'][ $key ] += $value;
				$groups[ $program_key ]['subjects'][ $subject_key ]['totals'][ $key ] += $value;
			}

			if ( ! isset( $groups[ $program_key ]['subjects'][ $subject_key ]['students'][ $student_key ] ) ) {
				$groups[ $program_key ]['subjects'][ $subject_key ]['students'][ $student_key ] = array(
					'student_user_id'      => $student_id,
					'student_name'         => sanitize_text_field( (string) ( $row['student_name'] ?? '' ) ),
					'student_email'        => sanitize_email( (string) ( $row['student_email'] ?? '' ) ),
					'person_code'          => sanitize_text_field( (string) ( $row['person_code'] ?? '' ) ),
					'section_title'        => $section_title,
					'absent_count'         => 0,
					'leave_count'          => 0,
					'late_count'           => 0,
					'issue_count'          => 0,
					'latest_date'          => '',
					'latest_status'        => '',
					'latest_session_title' => '',
				);
			}

			$student =& $groups[ $program_key ]['subjects'][ $subject_key ]['students'][ $student_key ];
			$student['absent_count'] += $absent_count;
			$student['leave_count']  += $leave_count;
			$student['late_count']   += $late_count;
			$student['issue_count']  += $issue_count;

			$latest_date = sanitize_text_field( (string) ( $row['latest_date'] ?? '' ) );
			if ( '' === $student['latest_date'] || $latest_date > $student['latest_date'] ) {
				$student['latest_date']          = $latest_date;
				$student['latest_status']        = sanitize_key( $row['latest_status'] ?? '' );
				$student['latest_session_title'] = sanitize_text_field( (string) ( $row['latest_session_title'] ?? '' ) );
			}
			unset( $student );
		}

		$programs = array_values( $groups );

		foreach ( $programs as &$program ) {
			$subjects = array_values( $program['subjects'] );

			foreach ( $subjects as &$subject ) {
				$students = array_values( $subject['students'] );
				usort(
					$students,
					static function ( $left, $right ) {
						$count_compare = (int) $right['issue_count'] <=> (int) $left['issue_count'];
						if ( 0 !== $count_compare ) {
							return $count_compare;
						}

						$date_compare = strcmp( (string) $right['latest_date'], (string) $left['latest_date'] );
						if ( 0 !== $date_compare ) {
							return $date_compare;
						}

						return strcasecmp( (string) $left['student_name'], (string) $right['student_name'] );
					}
				);

				$subject['students'] = $students;
			}
			unset( $subject );

			usort(
				$subjects,
				static function ( $left, $right ) {
					return strcasecmp( (string) $left['subject_title'], (string) $right['subject_title'] );
				}
			);

			$program['subjects'] = $subjects;
		}
		unset( $program );

		usort(
			$programs,
			static function ( $left, $right ) {
				return strcasecmp( (string) $left['program_name'], (string) $right['program_name'] );
			}
		);

		return $programs;
	}


	public function get_student_attendance_matrix_review( $days = 7, $include_late = false, $student_limit = 250 ) {
		global $wpdb;

		$days = absint( $days );
		if ( ! in_array( $days, array( 7, 28, 112 ), true ) ) {
			$days = 7;
		}

		$student_limit = max( 1, min( 500, absint( $student_limit ) ) );
		$issue_statuses = $include_late ? array( 'absent', 'leave', 'late' ) : array( 'absent', 'leave' );
		$status_placeholders = implode( ', ', array_fill( 0, count( $issue_statuses ), '%s' ) );
		$sessions = Schema::table( 'attendance_sessions' );
		$records = Schema::table( 'attendance_records' );
		$today = AcademicClock::date( 'Y-m-d' );

		$issue_args = array_merge( array( $today, $days ), $issue_statuses, array( $student_limit ) );
		$issue_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					r.student_user_id,
					SUM(CASE WHEN r.attendance_status = 'absent' THEN 1 ELSE 0 END) AS absent_count,
					SUM(CASE WHEN r.attendance_status = 'leave' THEN 1 ELSE 0 END) AS leave_count,
					SUM(CASE WHEN r.attendance_status = 'late' THEN 1 ELSE 0 END) AS late_count,
					COUNT(r.id) AS issue_count,
					MAX(s.session_date) AS latest_date
				FROM {$records} r
				INNER JOIN {$sessions} s ON s.id = r.session_id
				WHERE s.session_date >= DATE_SUB(%s, INTERVAL %d DAY)
					AND s.status <> 'cancelled'
					AND r.attendance_status IN ({$status_placeholders})
				GROUP BY r.student_user_id
				ORDER BY issue_count DESC, latest_date DESC
				LIMIT %d",
				$issue_args
			),
			ARRAY_A
		);

		if ( empty( $issue_rows ) ) {
			return array();
		}

		$student_ids = array();
		$issue_totals = array();
		foreach ( $issue_rows as $row ) {
			$student_id = absint( $row['student_user_id'] ?? 0 );
			if ( ! $student_id ) {
				continue;
			}
			$student_ids[] = $student_id;
			$issue_totals[ $student_id ] = array(
				'absent_count' => (int) ( $row['absent_count'] ?? 0 ),
				'leave_count'  => (int) ( $row['leave_count'] ?? 0 ),
				'late_count'   => (int) ( $row['late_count'] ?? 0 ),
				'issue_count'  => (int) ( $row['issue_count'] ?? 0 ),
			);
		}

		$student_ids = array_values( array_unique( $student_ids ) );
		if ( empty( $student_ids ) ) {
			return array();
		}

		$student_placeholders = implode( ', ', array_fill( 0, count( $student_ids ), '%d' ) );
		$enrollments = Schema::table( 'enrollments' );
		$profiles = Schema::table( 'user_profiles' );
		$users = $wpdb->users;
		$posts = $wpdb->posts;
		$postmeta = $wpdb->postmeta;
		$enrollment_args = array_merge( $student_ids, array( 'enrolled', 'waitlisted' ) );
		$enrollment_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					e.student_user_id,
					e.section_id,
					e.status AS enrollment_status,
					u.display_name AS student_name,
					u.user_email AS student_email,
					p.person_code,
					p.phone AS student_phone,
					p.program_id AS profile_program_id,
					section_post.post_title AS section_title,
					section_post.post_type AS section_post_type,
					subject_meta.meta_value AS subject_meta_id,
					subject_post.post_title AS subject_title
				FROM {$enrollments} e
				INNER JOIN {$users} u ON u.ID = e.student_user_id
				LEFT JOIN {$profiles} p ON p.user_id = e.student_user_id
				INNER JOIN {$posts} section_post ON section_post.ID = e.section_id
				LEFT JOIN {$postmeta} subject_meta ON subject_meta.post_id = e.section_id AND subject_meta.meta_key = '_slms_subject_id'
				LEFT JOIN {$posts} subject_post ON subject_post.ID = CAST(subject_meta.meta_value AS UNSIGNED)
				WHERE e.student_user_id IN ({$student_placeholders})
					AND e.status IN (%s, %s)
				ORDER BY u.display_name ASC, section_post.post_title ASC",
				$enrollment_args
			),
			ARRAY_A
		);

		if ( empty( $enrollment_rows ) ) {
			return array();
		}

		$students = array();
		$section_ids = array();

		foreach ( $enrollment_rows as $row ) {
			$student_id = absint( $row['student_user_id'] ?? 0 );
			$section_id = absint( $row['section_id'] ?? 0 );
			if ( ! $student_id || ! $section_id ) {
				continue;
			}

			$section_type = sanitize_key( $row['section_post_type'] ?? '' );
			$subject_meta_id = absint( $row['subject_meta_id'] ?? 0 );
			$subject_id = 'slms_subject' === $section_type ? $section_id : $subject_meta_id;
			$plan_source_id = $subject_id ?: $section_id;
			$plan_unit = sanitize_key( (string) get_post_meta( $plan_source_id, '_slms_attendance_plan_unit', true ) );
			$plan_unit = 'classes' === $plan_unit ? 'classes' : 'weeks';

			$section_title = sanitize_text_field( (string) ( $row['section_title'] ?? '' ) );
			$subject_title = sanitize_text_field( (string) ( $row['subject_title'] ?? '' ) );
			$display_title = $subject_title ?: $section_title;
			if ( '' === $display_title ) {
				$display_title = __( 'Unassigned Subject', 'simple-lms' );
			}
			if ( $subject_title && $section_title && $section_title !== $subject_title ) {
				$display_title = sprintf( '%1$s - %2$s', $subject_title, $section_title );
			}

			$program_id = absint( $row['profile_program_id'] ?? 0 );
			$program_name = '';
			if ( $program_id ) {
				$program = get_term( $program_id, 'slms_program' );
				if ( $program && ! is_wp_error( $program ) ) {
					$program_name = $program->name;
				}
			}
			if ( '' === $program_name && $subject_id ) {
				$programs = wp_get_post_terms( $subject_id, 'slms_program', array( 'orderby' => 'name', 'order' => 'ASC' ) );
				if ( ! is_wp_error( $programs ) && ! empty( $programs ) ) {
					$program_id = (int) $programs[0]->term_id;
					$program_name = $programs[0]->name;
				}
			}
			if ( '' === $program_name ) {
				$program_name = __( 'Unassigned Program', 'simple-lms' );
			}

			if ( ! isset( $students[ $student_id ] ) ) {
				$students[ $student_id ] = array(
					'student_user_id' => $student_id,
					'student_name'    => sanitize_text_field( (string) ( $row['student_name'] ?? '' ) ),
					'student_email'   => sanitize_email( (string) ( $row['student_email'] ?? '' ) ),
					'person_code'     => sanitize_text_field( (string) ( $row['person_code'] ?? '' ) ),
					'student_phone'   => sanitize_text_field( (string) ( $row['student_phone'] ?? '' ) ),
					'program_id'      => $program_id,
					'program_name'    => $program_name,
					'issue_totals'    => $issue_totals[ $student_id ] ?? array( 'absent_count' => 0, 'leave_count' => 0, 'late_count' => 0, 'issue_count' => 0 ),
					'subjects'        => array( 'weeks' => array(), 'classes' => array() ),
				);
			}

			$subject_key = 'section-' . $section_id;
			$students[ $student_id ]['subjects'][ $plan_unit ][ $subject_key ] = array(
				'subject_key'   => $subject_key,
				'subject_id'    => $subject_id,
				'section_id'    => $section_id,
				'subject_title' => $display_title,
				'plan_unit'     => $plan_unit,
			);
			$section_ids[] = $section_id;
		}

		$section_ids = array_values( array_unique( array_filter( array_map( 'absint', $section_ids ) ) ) );
		if ( empty( $section_ids ) ) {
			return array();
		}

		$section_placeholders = implode( ', ', array_fill( 0, count( $section_ids ), '%d' ) );
		$session_args = array_merge( $student_ids, $section_ids, array( $today, $days ) );
		$session_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					s.id AS session_id,
					s.section_id,
					s.session_number,
					s.session_title,
					s.session_date,
					r.student_user_id,
					r.attendance_status
				FROM {$sessions} s
				LEFT JOIN {$records} r ON r.session_id = s.id AND r.student_user_id IN ({$student_placeholders})
				WHERE s.section_id IN ({$section_placeholders})
					AND s.session_date >= DATE_SUB(%s, INTERVAL %d DAY)
					AND s.status <> 'cancelled'
				ORDER BY s.session_number ASC, s.session_date ASC, s.id ASC",
				$session_args
			),
			ARRAY_A
		);

		$sessions_by_section = array();
		$records_by_student = array();
		foreach ( $session_rows as $row ) {
			$section_id = absint( $row['section_id'] ?? 0 );
			$session_number = max( 1, absint( $row['session_number'] ?? 0 ) );
			if ( ! $section_id || ! $session_number ) {
				continue;
			}
			if ( ! isset( $sessions_by_section[ $section_id ][ $session_number ] ) ) {
				$sessions_by_section[ $section_id ][ $session_number ] = array(
					'session_id'    => absint( $row['session_id'] ?? 0 ),
					'section_id'    => $section_id,
					'number'        => $session_number,
					'session_title' => sanitize_text_field( (string) ( $row['session_title'] ?? '' ) ),
					'session_date'  => sanitize_text_field( (string) ( $row['session_date'] ?? '' ) ),
				);
			}
			$record_student_id = absint( $row['student_user_id'] ?? 0 );
			if ( $record_student_id ) {
				$records_by_student[ $record_student_id ][ $section_id ][ $session_number ] = $this->normalize_attendance_status( $row['attendance_status'] ?? 'absent' );
			}
		}

		foreach ( $students as &$student ) {
			foreach ( array( 'weeks', 'classes' ) as $plan_unit ) {
				$matrix = array(
					'subjects' => array_values( $student['subjects'][ $plan_unit ] ),
					'rows'     => array(),
				);
				$unit_numbers = array();
				foreach ( $matrix['subjects'] as $subject ) {
					$section_id = absint( $subject['section_id'] ?? 0 );
					if ( isset( $sessions_by_section[ $section_id ] ) ) {
						$unit_numbers = array_merge( $unit_numbers, array_keys( $sessions_by_section[ $section_id ] ) );
					}
				}
				$unit_numbers = array_values( array_unique( array_map( 'absint', $unit_numbers ) ) );
				sort( $unit_numbers );

				foreach ( $unit_numbers as $number ) {
					$row = array(
						'number' => $number,
						'label'  => 'classes' === $plan_unit ? sprintf( __( 'Class %d', 'simple-lms' ), $number ) : sprintf( __( 'Week %d', 'simple-lms' ), $number ),
						'cells'  => array(),
					);
					foreach ( $matrix['subjects'] as $subject ) {
						$section_id = absint( $subject['section_id'] ?? 0 );
						$subject_key = (string) ( $subject['subject_key'] ?? 'section-' . $section_id );
						if ( empty( $sessions_by_section[ $section_id ][ $number ] ) ) {
							$row['cells'][ $subject_key ] = array( 'status' => 'no_class' );
							continue;
						}
						$session = $sessions_by_section[ $section_id ][ $number ];
						$status = $records_by_student[ $student['student_user_id'] ][ $section_id ][ $number ] ?? 'not_recorded';
						$row['cells'][ $subject_key ] = array(
							'status'        => 'not_recorded' === $status ? 'not_recorded' : $this->normalize_attendance_status( $status ),
							'date'          => $session['session_date'],
							'session_title' => $session['session_title'],
						);
					}
					$matrix['rows'][] = $row;
				}

				$student[ 'weeks' === $plan_unit ? 'weekly_subjects' : 'class_subjects' ] = $matrix;
			}
			unset( $student['subjects'] );
		}
		unset( $student );

		$programs = array();
		foreach ( $students as $student ) {
			$program_key = ! empty( $student['program_id'] ) ? 'program-' . (int) $student['program_id'] : 'program-unassigned';
			if ( ! isset( $programs[ $program_key ] ) ) {
				$programs[ $program_key ] = array(
					'program_id'   => (int) ( $student['program_id'] ?? 0 ),
					'program_name' => $student['program_name'] ?: __( 'Unassigned Program', 'simple-lms' ),
					'totals'       => array( 'absent_count' => 0, 'leave_count' => 0, 'late_count' => 0, 'issue_count' => 0 ),
					'students'     => array(),
				);
			}

			foreach ( array( 'absent_count', 'leave_count', 'late_count', 'issue_count' ) as $key ) {
				$programs[ $program_key ]['totals'][ $key ] += (int) ( $student['issue_totals'][ $key ] ?? 0 );
			}
			$programs[ $program_key ]['students'][] = $student;
		}

		$programs = array_values( $programs );
		foreach ( $programs as &$program ) {
			usort(
				$program['students'],
				static function ( $left, $right ) {
					$count_compare = (int) ( $right['issue_totals']['issue_count'] ?? 0 ) <=> (int) ( $left['issue_totals']['issue_count'] ?? 0 );
					if ( 0 !== $count_compare ) {
						return $count_compare;
					}
					return strcasecmp( (string) ( $left['student_name'] ?? '' ), (string) ( $right['student_name'] ?? '' ) );
				}
			);
		}
		unset( $program );

		usort(
			$programs,
			static function ( $left, $right ) {
				return strcasecmp( (string) $left['program_name'], (string) $right['program_name'] );
			}
		);

		return $programs;
	}

	public function count_sessions( $section_id = 0 ) {
		global $wpdb;

		$table = Schema::table( 'attendance_sessions' );

		if ( $section_id ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE section_id = %d",
					absint( $section_id )
				)
			);
		}

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	public function can_manage_attendance( $user_id, $section_id ) {
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

	public function can_view_attendance( $user_id, $section_id ) {
		$user_id = absint( $user_id );

		if ( $this->can_manage_attendance( $user_id, $section_id ) ) {
			return true;
		}

		if ( 'student' === RoleManager::get_primary_role( $user_id ) ) {
			return $this->enrollments->user_can_teach_section( $user_id, $section_id );
		}

		return false;
	}

	private function get_section_students( $section_id ) {
		$students = array_values(
			array_filter(
				$this->enrollments->list_enrollments(
					array(
						'section_id' => absint( $section_id ),
					)
				),
				static function ( $row ) {
					return in_array( $row['status'], array( 'enrolled', 'completed', 'waitlisted' ), true );
				}
			)
		);

		usort(
			$students,
			static function ( $left, $right ) {
				return strcasecmp( $left['student_name'], $right['student_name'] );
			}
		);

		return $students;
	}

	private function get_section_record_map( $section_id ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::table( 'attendance_records' ) . ' WHERE section_id = %d',
				$section_id
			),
			ARRAY_A
		);
		$map  = array();

		foreach ( $rows as $row ) {
			$map[ (int) $row['session_id'] . ':' . (int) $row['student_user_id'] ] = $row;
		}

		return $map;
	}

	private function is_attendance_course( $section_id ) {
		$post_type = get_post_type( absint( $section_id ) );

		return in_array( $post_type, array( 'slms_subject', 'slms_section' ), true );
	}

	private function get_next_session_number( $section_id ) {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COALESCE(MAX(session_number), 0) + 1 FROM ' . Schema::table( 'attendance_sessions' ) . ' WHERE section_id = %d',
				$section_id
			)
		);
	}

	private function get_planned_session_count( $section_id, array $sessions = array() ) {
		$section_id         = absint( $section_id );
		$configured_classes = absint( get_post_meta( $section_id, '_slms_total_classes', true ) );
		$highest_session    = 0;

		foreach ( $sessions as $session ) {
			$highest_session = max( $highest_session, (int) ( $session['session_number'] ?? 0 ) );
		}

		if ( $configured_classes > 0 ) {
			return max( $configured_classes, $highest_session );
		}

		if ( $highest_session > 0 ) {
			return $highest_session;
		}

		return count( $sessions );
	}

	private function normalize_attendance_status( $status ) {
		$status = sanitize_key( $status );

		if ( 'excused' === $status ) {
			return 'present';
		}

		if ( ! in_array( $status, self::ATTENDANCE_STATUSES, true ) ) {
			$status = 'absent';
		}

		return $status;
	}

	private function normalize_time_value( $value ) {
		$value = sanitize_text_field( $value );

		if ( preg_match( '/^\d{2}:\d{2}$/', $value ) ) {
			return $value . ':00';
		}

		if ( preg_match( '/^\d{2}:\d{2}:\d{2}$/', $value ) ) {
			return $value;
		}

		return '';
	}

	private function is_valid_date( $value ) {
		return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value );
	}

	private function status_points( $status ) {
		switch ( $status ) {
			case 'late':
				return 0.5;
			case 'leave':
			case 'absent':
				return 0.0;
			case 'present':
			default:
				return 1.0;
		}
	}

	private function empty_student_summary() {
		return array(
			'planned_sessions'      => 0,
			'eligible_sessions'     => 0,
			'present_sessions'      => 0,
			'late_sessions'         => 0,
			'leave_sessions'        => 0,
			'absent_sessions'       => 0,
			'earned_points'         => 0,
			'attendance_percentage' => 0,
		);
	}

	private function format_session_row( array $row ) {
		return array(
			'id'             => (int) $row['id'],
			'section_id'     => (int) $row['section_id'],
			'session_number' => (int) $row['session_number'],
			'session_title'  => $row['session_title'],
			'session_date'   => $row['session_date'],
			'starts_at'      => $row['starts_at'],
			'ends_at'        => $row['ends_at'],
			'week_label'     => $row['week_label'],
			'notes'          => $row['notes'],
			'status'         => $row['status'],
			'created_by'     => ! empty( $row['created_by'] ) ? (int) $row['created_by'] : 0,
			'updated_by'     => ! empty( $row['updated_by'] ) ? (int) $row['updated_by'] : 0,
			'created_at'     => $row['created_at'],
			'updated_at'     => $row['updated_at'],
			'record_count'   => isset( $row['record_count'] ) ? (int) $row['record_count'] : 0,
			'present_count'  => isset( $row['present_count'] ) ? (int) $row['present_count'] : 0,
			'late_count'     => isset( $row['late_count'] ) ? (int) $row['late_count'] : 0,
			'leave_count'    => isset( $row['leave_count'] ) ? (int) $row['leave_count'] : 0,
			'absent_count'   => isset( $row['absent_count'] ) ? (int) $row['absent_count'] : 0,
		);
	}
}
