<?php

namespace SimpleLMS\Domain\Academic;

use SimpleLMS\Core\AcademicClock;
use SimpleLMS\Infrastructure\Database\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

class TermService {
	const VALID_STATUSES = array( 'planned', 'active', 'closed', 'archived' );
	const AUTO_CREATE_EVENT = 'slms_auto_create_terms';

	public function register_hooks() {
		add_action( 'init', array( $this, 'schedule_auto_creation' ) );
		add_action( self::AUTO_CREATE_EVENT, array( $this, 'maybe_create_next_academic_year_terms' ) );
	}

	public function schedule_auto_creation() {
		if ( wp_next_scheduled( self::AUTO_CREATE_EVENT ) ) {
			return;
		}

		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::AUTO_CREATE_EVENT );
	}

	public static function clear_scheduled_event() {
		$timestamp = wp_next_scheduled( self::AUTO_CREATE_EVENT );

		while ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::AUTO_CREATE_EVENT );
			$timestamp = wp_next_scheduled( self::AUTO_CREATE_EVENT );
		}
	}

	public function maybe_create_next_academic_year_terms( $force = false ) {
		$timestamp = AcademicClock::timestamp();
		$month     = AcademicClock::date( 'n', $timestamp );

		if ( ! $force && 5 !== (int) $month ) {
			return array();
		}

		$academic_year_start = (int) AcademicClock::date( 'Y', $timestamp );
		$academic_year_label = sprintf( '%d/%d', $academic_year_start, $academic_year_start + 1 );
		$created             = array();

		foreach ( $this->get_default_term_blueprints( $academic_year_start, $academic_year_label ) as $blueprint ) {
			$existing = $this->get_term_by_code( $blueprint['code'] );

			if ( $existing ) {
				continue;
			}

			$result = $this->create_term( $blueprint );

			if ( ! is_wp_error( $result ) ) {
				$created[] = (int) $result;
			}
		}

		return $created;
	}

	public function create_term( array $data ) {
		global $wpdb;

		$clean = $this->sanitize_term_data( $data );

		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		$inserted = $wpdb->insert(
			Schema::table( 'terms' ),
			$clean,
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return new WP_Error( 'slms_term_insert_failed', __( 'The term could not be created.', 'simple-lms' ) );
		}

		$term_id = (int) $wpdb->insert_id;

		if ( 'active' === ( $clean['status'] ?? '' ) ) {
			$result = $this->deactivate_other_active_terms( $term_id );

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return $term_id;
	}

	public function update_term( $term_id, array $data ) {
		global $wpdb;

		$term_id = absint( $term_id );

		if ( ! $term_id ) {
			return new WP_Error( 'slms_invalid_term', __( 'Invalid term.', 'simple-lms' ) );
		}

		$clean = $this->sanitize_term_data( $data, $term_id );

		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		$updated = $wpdb->update(
			Schema::table( 'terms' ),
			$clean,
			array( 'id' => $term_id ),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error( 'slms_term_update_failed', __( 'The term could not be updated.', 'simple-lms' ) );
		}

		if ( 'active' === ( $clean['status'] ?? '' ) ) {
			$result = $this->deactivate_other_active_terms( $term_id );

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return $term_id;
	}

	public function delete_term( $term_id ) {
		global $wpdb;

		$term_id = absint( $term_id );

		if ( ! $term_id ) {
			return new WP_Error( 'slms_invalid_term', __( 'Invalid term.', 'simple-lms' ) );
		}

		$assigned_sections = get_posts(
			array(
				'post_type'      => 'slms_section',
				'post_status'    => array( 'publish', 'draft', 'private', 'pending' ),
				'posts_per_page' => 1,
				'meta_key'       => '_slms_term_id',
				'meta_value'     => $term_id,
				'fields'         => 'ids',
			)
		);

		if ( ! empty( $assigned_sections ) ) {
			return new WP_Error( 'slms_term_in_use', __( 'This term is already assigned to one or more sections.', 'simple-lms' ) );
		}

		$assigned_subjects = get_posts(
			array(
				'post_type'      => 'slms_subject',
				'post_status'    => array( 'publish', 'draft', 'private', 'pending', 'future' ),
				'posts_per_page' => 1,
				'meta_key'       => '_slms_term_id',
				'meta_value'     => $term_id,
				'fields'         => 'ids',
			)
		);

		if ( ! empty( $assigned_subjects ) ) {
			return new WP_Error( 'slms_term_in_use', __( 'This term is already assigned to one or more subjects.', 'simple-lms' ) );
		}

		$intake_refs = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . Schema::table( 'user_profiles' ) . ' WHERE intake_term_id = %d',
				$term_id
			)
		);

		if ( $intake_refs > 0 ) {
			return new WP_Error( 'slms_term_in_use', __( 'This term is still assigned to one or more student records.', 'simple-lms' ) );
		}

		$deleted = $wpdb->delete(
			Schema::table( 'terms' ),
			array( 'id' => $term_id ),
			array( '%d' )
		);

		if ( false === $deleted ) {
			return new WP_Error( 'slms_term_delete_failed', __( 'The term could not be deleted.', 'simple-lms' ) );
		}

		return true;
	}

	public function get_term( $term_id ) {
		global $wpdb;

		$term_id = absint( $term_id );

		if ( ! $term_id ) {
			return null;
		}

		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::table( 'terms' ) . ' WHERE id = %d LIMIT 1',
				$term_id
			),
			ARRAY_A
		);
	}

	public function list_terms( array $args = array() ) {
		global $wpdb;

		$where    = 'WHERE 1=1';
		$params   = array();
		$statuses = self::VALID_STATUSES;

		if ( ! empty( $args['academic_year'] ) ) {
			$where   .= ' AND academic_year = %s';
			$params[] = sanitize_text_field( $args['academic_year'] );
		}

		if ( ! empty( $args['status'] ) && in_array( $args['status'], $statuses, true ) ) {
			$where   .= ' AND status = %s';
			$params[] = $args['status'];
		}

		$sql = 'SELECT * FROM ' . Schema::table( 'terms' ) . ' ' . $where . ' ORDER BY academic_year DESC, sort_order ASC, start_date ASC, id DESC';

		if ( $params ) {
			$sql = $wpdb->prepare( $sql, $params );
		}

		return $wpdb->get_results( $sql, ARRAY_A );
	}

	public function get_active_term() {
		$terms = $this->list_terms(
			array(
				'status' => 'active',
			)
		);

		return $terms[0] ?? null;
	}

	public function count_terms() {
		global $wpdb;

		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'terms' ) );
	}

	public function get_term_by_code( $code ) {
		global $wpdb;

		$code = strtoupper( sanitize_text_field( $code ) );

		if ( '' === $code ) {
			return null;
		}

		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::table( 'terms' ) . ' WHERE code = %s LIMIT 1',
				$code
			),
			ARRAY_A
		);
	}

	public function list_academic_years() {
		global $wpdb;

		$rows = $wpdb->get_col( 'SELECT DISTINCT academic_year FROM ' . Schema::table( 'terms' ) . ' WHERE academic_year <> \'\' ORDER BY academic_year DESC' );

		return array_values( array_filter( array_map( 'strval', $rows ) ) );
	}

	private function sanitize_term_data( array $data, $term_id = 0 ) {
		global $wpdb;

		$code          = strtoupper( sanitize_text_field( $data['code'] ?? '' ) );
		$name          = sanitize_text_field( $data['name'] ?? '' );
		$academic_year = sanitize_text_field( $data['academic_year'] ?? '' );
		$start_date    = $this->sanitize_date_value( $data['start_date'] ?? '' );
		$end_date      = $this->sanitize_date_value( $data['end_date'] ?? '' );
		$status        = sanitize_key( $data['status'] ?? 'planned' );
		$sort_order    = isset( $data['sort_order'] ) ? intval( $data['sort_order'] ) : 0;

		if ( empty( $code ) || empty( $name ) || empty( $academic_year ) ) {
			return new WP_Error( 'slms_term_missing_fields', __( 'Code, name, and academic year are required.', 'simple-lms' ) );
		}

		if ( ! in_array( $status, self::VALID_STATUSES, true ) ) {
			$status = 'planned';
		}

		if ( $start_date && $end_date && $end_date < $start_date ) {
			return new WP_Error( 'slms_term_invalid_dates', __( 'End date must be after start date.', 'simple-lms' ) );
		}

		$existing_id = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . Schema::table( 'terms' ) . ' WHERE code = %s LIMIT 1',
				$code
			)
		);

		if ( $existing_id && (int) $existing_id !== (int) $term_id ) {
			return new WP_Error( 'slms_term_duplicate_code', __( 'A term with that code already exists.', 'simple-lms' ) );
		}

		$existing_term = null;

		if ( $term_id ) {
			$existing_term = $this->get_term( $term_id );

			if ( ! $existing_term ) {
				return new WP_Error( 'slms_invalid_term', __( 'Invalid term.', 'simple-lms' ) );
			}
		}

		$now = AcademicClock::mysql();

		return array(
			'code'          => $code,
			'name'          => $name,
			'academic_year' => $academic_year,
			'start_date'    => $start_date,
			'end_date'      => $end_date,
			'status'        => $status,
			'sort_order'    => $sort_order,
			'created_at'    => $existing_term['created_at'] ?? $now,
			'updated_at'    => $now,
		);
	}

	private function deactivate_other_active_terms( $active_term_id ) {
		global $wpdb;

		$active_term_id = absint( $active_term_id );

		if ( ! $active_term_id ) {
			return new WP_Error( 'slms_invalid_term', __( 'Invalid term.', 'simple-lms' ) );
		}

		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . Schema::table( 'terms' ) . ' SET status = %s, updated_at = %s WHERE status = %s AND id <> %d',
				'planned',
				AcademicClock::mysql(),
				'active',
				$active_term_id
			)
		);

		if ( false === $updated ) {
			return new WP_Error( 'slms_term_exclusive_active_failed', __( 'The active term could not be enforced.', 'simple-lms' ) );
		}

		return true;
	}

	private function sanitize_date_value( $value ) {
		$value = sanitize_text_field( $value );

		if ( empty( $value ) ) {
			return null;
		}

		$date = AcademicClock::parse( $value );

		if ( ! $date ) {
			return null;
		}

		return $date->format( 'Y-m-d' );
	}

	private function get_default_term_blueprints( $academic_year_start, $academic_year_label ) {
		$academic_year_start = (int) $academic_year_start;

		return array(
			array(
				'code'          => sprintf( '%d-SEM1', $academic_year_start ),
				'name'          => __( 'Semester 1', 'simple-lms' ),
				'academic_year' => $academic_year_label,
				'start_date'    => sprintf( '%d-06-01', $academic_year_start ),
				'end_date'      => sprintf( '%d-10-31', $academic_year_start ),
				'status'        => 'planned',
				'sort_order'    => 1,
			),
			array(
				'code'          => sprintf( '%d-SEM2', $academic_year_start ),
				'name'          => __( 'Semester 2', 'simple-lms' ),
				'academic_year' => $academic_year_label,
				'start_date'    => sprintf( '%d-11-01', $academic_year_start ),
				'end_date'      => sprintf( '%d-03-31', $academic_year_start + 1 ),
				'status'        => 'planned',
				'sort_order'    => 2,
			),
		);
	}
}
