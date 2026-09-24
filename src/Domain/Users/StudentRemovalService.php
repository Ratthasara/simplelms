<?php

namespace SimpleLMS\Domain\Users;

use SimpleLMS\Infrastructure\Database\Schema;
use SimpleLMS\Infrastructure\Files\ManagedUploadPath;
use SimpleLMS\Infrastructure\Logging\AuditLogger;

defined( 'ABSPATH' ) || exit;

/**
 * Handles irreversible student removal and LMS data cleanup.
 */
class StudentRemovalService {
	/**
	 * @var ProfileService
	 */
	private $profiles;

	/**
	 * @var AuditLogger
	 */
	private $audit_logger;

	public function __construct( ProfileService $profiles, AuditLogger $audit_logger ) {
		$this->profiles     = $profiles;
		$this->audit_logger = $audit_logger;
	}

	/**
	 * Returns a count summary of student-owned records that will be purged.
	 *
	 * @param int $student_user_id Student user ID.
	 * @return array
	 */
	public function get_force_remove_summary( $student_user_id ) {
		$student_user_id = absint( $student_user_id );

		if ( ! $student_user_id ) {
			return array();
		}

		$card_ids = $this->get_id_card_ids( $student_user_id );

		return array(
			'enrollments'            => $this->count_rows( Schema::table( 'enrollments' ), 'student_user_id', $student_user_id ),
			'assignment_submissions' => $this->count_rows( Schema::table( 'assignment_submissions' ), 'student_user_id', $student_user_id ),
			'grade_scores'           => $this->count_rows( Schema::table( 'grade_scores' ), 'student_user_id', $student_user_id ),
			'attendance_records'     => $this->count_rows( Schema::table( 'attendance_records' ), 'student_user_id', $student_user_id ),
			'notifications'          => $this->count_rows( Schema::table( 'notifications' ), 'recipient_user_id', $student_user_id ),
			'id_cards'               => $this->count_rows( Schema::table( 'id_cards' ), 'person_user_id', $student_user_id ),
			'id_card_logs'           => $this->count_id_card_logs( $student_user_id, $card_ids ),
			'user_profiles'          => $this->count_rows( Schema::table( 'user_profiles' ), 'user_id', $student_user_id ),
			'submission_files'       => count( $this->get_student_submission_urls( $student_user_id ) ),
			'profile_photo'          => $this->has_profile_photo( $student_user_id ) ? 1 : 0,
		);
	}

	/**
	 * Permanently deletes a student and all student-owned LMS history.
	 *
	 * @param int $student_user_id Student user ID.
	 * @param int $reassign_user_id Fallback user ID for authored WordPress content.
	 * @return true|\WP_Error
	 */
	public function force_remove_student( $student_user_id, $reassign_user_id = 0 ) {
		$student_user_id = absint( $student_user_id );
		$reassign_user_id = absint( $reassign_user_id );

		if ( ! $this->current_user_can_force_remove() ) {
			return new \WP_Error( 'slms_student_force_permissions', __( 'Only administrators can force remove student accounts.', 'simple-lms' ) );
		}

		if ( ! $student_user_id || ! get_userdata( $student_user_id ) ) {
			return new \WP_Error( 'slms_student_force_invalid_user', __( 'A valid student user was not provided.', 'simple-lms' ) );
		}

		if ( get_current_user_id() === $student_user_id ) {
			return new \WP_Error( 'slms_student_force_self', __( 'You cannot force remove your own account.', 'simple-lms' ) );
		}

		$profile = $this->profiles->get_profile( $student_user_id );

		if ( empty( $profile ) || 'student' !== ( $profile['person_type'] ?? '' ) ) {
			return new \WP_Error( 'slms_student_force_invalid_profile', __( 'The selected record is not a student profile.', 'simple-lms' ) );
		}

		if ( $reassign_user_id && $reassign_user_id === $student_user_id ) {
			return new \WP_Error( 'slms_student_force_invalid_reassign', __( 'Authored content cannot be reassigned to the student being removed.', 'simple-lms' ) );
		}

		$summary = $this->get_force_remove_summary( $student_user_id );
		$assets  = $this->collect_student_assets( $student_user_id );
		$deleted_counts = array();

		global $wpdb;

		$wpdb->query( 'START TRANSACTION' );

		try {
			$card_ids = $this->get_id_card_ids( $student_user_id );

			$deleted_counts['assignment_submissions'] = $this->delete_rows( Schema::table( 'assignment_submissions' ), 'student_user_id', $student_user_id );
			$deleted_counts['grade_scores']           = $this->delete_rows( Schema::table( 'grade_scores' ), 'student_user_id', $student_user_id );
			$deleted_counts['attendance_records']     = $this->delete_rows( Schema::table( 'attendance_records' ), 'student_user_id', $student_user_id );
			$deleted_counts['enrollments']            = $this->delete_rows( Schema::table( 'enrollments' ), 'student_user_id', $student_user_id );
			$deleted_counts['notifications']          = $this->delete_rows( Schema::table( 'notifications' ), 'recipient_user_id', $student_user_id );
			$deleted_counts['id_card_logs']           = $this->delete_id_card_logs( $student_user_id, $card_ids );
			$deleted_counts['id_cards']               = $this->delete_rows( Schema::table( 'id_cards' ), 'person_user_id', $student_user_id );
			$deleted_counts['user_profiles']          = $this->delete_rows( Schema::table( 'user_profiles' ), 'user_id', $student_user_id );

			require_once ABSPATH . 'wp-admin/includes/user.php';

			$deleted_user = wp_delete_user( $student_user_id, $reassign_user_id ?: null );

			if ( ! $deleted_user ) {
				throw new \RuntimeException( __( 'The WordPress user account could not be removed.', 'simple-lms' ) );
			}

			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $exception ) {
			$wpdb->query( 'ROLLBACK' );
			clean_user_cache( $student_user_id );
			return new \WP_Error( 'slms_student_force_delete_failed', $exception->getMessage() );
		}

		$file_counts = $this->delete_collected_assets( $assets );

		$this->audit_logger->log(
			'student_force_removed',
			array(
				'object_type' => 'student',
				'object_id'   => $student_user_id,
				'message'     => sprintf(
					/* translators: %s: student display name */
					__( 'Student force removed: %s', 'simple-lms' ),
					isset( $profile['display_name'] ) ? $profile['display_name'] : $student_user_id
				),
				'context'     => array(
					'student_user_id' => $student_user_id,
					'person_code'     => $profile['person_code'] ?? '',
					'summary_before'  => $summary,
					'deleted_rows'    => $deleted_counts,
					'deleted_files'   => $file_counts,
				),
			)
		);

		return true;
	}

	private function current_user_can_force_remove() {
		return 'administrator' === RoleManager::get_primary_role() || current_user_can( 'delete_users' );
	}

	private function collect_student_assets( $student_user_id ) {
		return array(
			'submission_urls'        => $this->get_student_submission_urls( $student_user_id ),
			'id_card_photo_urls'     => $this->get_id_card_photo_urls( $student_user_id ),
			'profile_attachment_id'  => absint( get_user_meta( $student_user_id, 'slms_profile_photo_id', true ) ),
			'profile_managed_photo'  => get_user_meta( $student_user_id, 'slms_profile_photo_upload', true ),
		);
	}

	private function delete_collected_assets( array $assets ) {
		$counts = array(
			'submission_files'      => 0,
			'id_card_photo_files'   => 0,
			'profile_attachment'    => 0,
			'profile_managed_photo' => 0,
		);

		foreach ( $assets['submission_urls'] ?? array() as $url ) {
			if ( $this->delete_upload_url( $url ) ) {
				$counts['submission_files']++;
			}
		}

		foreach ( $assets['id_card_photo_urls'] ?? array() as $url ) {
			if ( $this->delete_upload_url( $url ) ) {
				$counts['id_card_photo_files']++;
			}
		}

		$attachment_id = absint( $assets['profile_attachment_id'] ?? 0 );

		if ( $attachment_id ) {
			require_once ABSPATH . 'wp-admin/includes/post.php';
			if ( wp_delete_attachment( $attachment_id, true ) ) {
				$counts['profile_attachment'] = 1;
			}
		}

		if ( $this->delete_managed_profile_photo( $assets['profile_managed_photo'] ?? array() ) ) {
			$counts['profile_managed_photo'] = 1;
		}

		return $counts;
	}

	private function has_profile_photo( $student_user_id ) {
		return (bool) absint( get_user_meta( $student_user_id, 'slms_profile_photo_id', true ) ) || ! empty( get_user_meta( $student_user_id, 'slms_profile_photo_upload', true ) );
	}

	private function get_student_submission_urls( $student_user_id ) {
		global $wpdb;

		$table = Schema::table( 'assignment_submissions' );

		if ( ! $this->table_exists( $table ) ) {
			return array();
		}

		$urls = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT attachment_url FROM {$table} WHERE student_user_id = %d AND attachment_url IS NOT NULL AND attachment_url <> ''",
				absint( $student_user_id )
			)
		);

		return array_values( array_unique( array_filter( array_map( 'esc_url_raw', (array) $urls ) ) ) );
	}

	private function get_id_card_photo_urls( $student_user_id ) {
		global $wpdb;

		$table = Schema::table( 'id_cards' );

		if ( ! $this->table_exists( $table ) ) {
			return array();
		}

		$urls = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT photo_url FROM {$table} WHERE person_user_id = %d AND photo_url IS NOT NULL AND photo_url <> ''",
				absint( $student_user_id )
			)
		);

		return array_values( array_unique( array_filter( array_map( 'esc_url_raw', (array) $urls ) ) ) );
	}

	private function get_id_card_ids( $student_user_id ) {
		global $wpdb;

		$table = Schema::table( 'id_cards' );

		if ( ! $this->table_exists( $table ) ) {
			return array();
		}

		return array_map(
			'absint',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE person_user_id = %d",
					absint( $student_user_id )
				)
			)
		);
	}

	private function count_id_card_logs( $student_user_id, array $card_ids ) {
		global $wpdb;

		$table = Schema::table( 'id_card_logs' );

		if ( ! $this->table_exists( $table ) ) {
			return 0;
		}

		$where = $wpdb->prepare( 'person_user_id = %d', absint( $student_user_id ) );

		if ( ! empty( $card_ids ) ) {
			$ids = implode( ',', array_map( 'absint', $card_ids ) );
			$where .= " OR card_id IN ({$ids})";
		}

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" );
	}

	private function delete_id_card_logs( $student_user_id, array $card_ids ) {
		global $wpdb;

		$table = Schema::table( 'id_card_logs' );

		if ( ! $this->table_exists( $table ) ) {
			return 0;
		}

		$where = $wpdb->prepare( 'person_user_id = %d', absint( $student_user_id ) );

		if ( ! empty( $card_ids ) ) {
			$ids = implode( ',', array_map( 'absint', $card_ids ) );
			$where .= " OR card_id IN ({$ids})";
		}

		$result = $wpdb->query( "DELETE FROM {$table} WHERE {$where}" );

		if ( false === $result ) {
			throw new \RuntimeException( __( 'ID card logs could not be removed.', 'simple-lms' ) );
		}

		return (int) $result;
	}

	private function count_rows( $table, $column, $value ) {
		global $wpdb;

		if ( ! $this->table_exists( $table ) ) {
			return 0;
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE {$column} = %d",
				absint( $value )
			)
		);
	}

	private function delete_rows( $table, $column, $value ) {
		global $wpdb;

		if ( ! $this->table_exists( $table ) ) {
			return 0;
		}

		$result = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE {$column} = %d",
				absint( $value )
			)
		);

		if ( false === $result ) {
			throw new \RuntimeException( __( 'One or more student records could not be removed.', 'simple-lms' ) );
		}

		return (int) $result;
	}

	private function table_exists( $table ) {
		global $wpdb;

		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	private function delete_upload_url( $url ) {
		$file_path = $this->map_upload_url_to_path( $url );

		if ( $file_path && is_file( $file_path ) ) {
			wp_delete_file( $file_path );
			return true;
		}

		return false;
	}

	private function delete_managed_profile_photo( $photo ) {
		if ( ! is_array( $photo ) ) {
			return false;
		}

		$file_path = '';

		if ( ! empty( $photo['relative_path'] ) ) {
			$file_path = ManagedUploadPath::from_relative( $photo['relative_path'] );
		}

		if ( '' === $file_path && ! empty( $photo['url'] ) ) {
			$file_path = $this->map_upload_url_to_path( $photo['url'] );
		}

		if ( $file_path && is_file( $file_path ) ) {
			wp_delete_file( $file_path );
			return true;
		}

		return false;
	}

	private function map_upload_url_to_path( $url ) {
		return ManagedUploadPath::from_url( $url );
	}
}
