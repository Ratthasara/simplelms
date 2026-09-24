<?php

namespace SimpleLMS\Domain\Communication;

use SimpleLMS\Domain\Users\RoleManager;
use SimpleLMS\Infrastructure\Database\Schema;

defined( 'ABSPATH' ) || exit;

class AudienceResolver {
	public function sanitize_targets( array $data ) {
		$targets     = array();
		$target_keys = isset( $data['targets'] ) ? (array) $data['targets'] : array();
		$target_keys = array_map( 'sanitize_key', $target_keys );

		if ( in_array( 'all_students', $target_keys, true ) ) {
			$targets[] = array( 'type' => 'all_students', 'value' => 'all' );
		}

		if ( in_array( 'all_staff', $target_keys, true ) ) {
			$targets[] = array( 'type' => 'all_staff', 'value' => 'all' );
		}

		$section_ids = isset( $data['section_ids'] ) ? array_filter( array_map( 'absint', (array) $data['section_ids'] ) ) : array();
		foreach ( array_unique( $section_ids ) as $section_id ) {
			$targets[] = array( 'type' => 'section_students', 'value' => (string) $section_id );
		}

		return $targets;
	}

	public function resolve_user_ids( array $targets ) {
		$ids = array();

		foreach ( $targets as $target ) {
			switch ( $target['type'] ?? '' ) {
				case 'all_students':
					$ids = array_merge( $ids, $this->get_student_user_ids() );
					break;
				case 'all_staff':
					$ids = array_merge( $ids, $this->get_staff_user_ids() );
					break;
				case 'section_students':
					$ids = array_merge( $ids, $this->get_section_student_user_ids( absint( $target['value'] ?? 0 ) ) );
					break;
			}
		}

		return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
	}

	public function get_audience_counts() {
		return array(
			'all_students' => count( $this->get_student_user_ids() ),
			'all_staff'    => count( $this->get_staff_user_ids() ),
		);
	}

	private function get_student_user_ids() {
		global $wpdb;

		$table = Schema::table( 'user_profiles' );
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$table} WHERE person_type = %s AND status = %s", 'student', 'active' ) );

		return array_map( 'absint', (array) $ids );
	}

	private function get_staff_user_ids() {
		$users = get_users(
			array(
				'role__in' => array( 'administrator', 'officer', 'lecturer', RoleManager::ROLE_STAFF ),
				'fields'   => 'ID',
			)
		);

		global $wpdb;

		$table       = Schema::table( 'user_profiles' );
		$profile_ids = $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$table} WHERE person_type = %s AND status = %s", 'staff', 'active' ) );

		return array_values( array_unique( array_merge( array_map( 'absint', (array) $users ), array_map( 'absint', (array) $profile_ids ) ) ) );
	}

	private function get_section_student_user_ids( $section_id ) {
		global $wpdb;

		$section_id = absint( $section_id );
		if ( ! $section_id ) {
			return array();
		}

		$table = Schema::table( 'enrollments' );
		$ids   = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT student_user_id FROM {$table} WHERE section_id = %d AND status = %s",
				$section_id,
				'enrolled'
			)
		);

		return array_map( 'absint', (array) $ids );
	}
}
