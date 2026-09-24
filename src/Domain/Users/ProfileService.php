<?php

namespace SimpleLMS\Domain\Users;

use SimpleLMS\Core\AcademicClock;
use SimpleLMS\Infrastructure\Database\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

class ProfileService {
	const PERSON_TYPES = array( 'student', 'staff' );
	const STATUSES     = array( 'invited', 'active', 'inactive', 'completed', 'archived' );
	const ORPHAN_PURGE_OPTION  = 'slms_profile_orphan_purge_version';
	const ORPHAN_PURGE_VERSION = '1';

	public function register_hooks() {
		add_action( 'init', array( $this, 'maybe_purge_orphaned_profiles' ) );
		add_action( 'deleted_user', array( $this, 'handle_user_deleted' ) );
	}

	public function get_profile( $user_id ) {
		global $wpdb;

		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return null;
		}

		$table = Schema::table( 'user_profiles' );
		$users = $wpdb->users;

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT p.*, u.display_name, u.user_email, u.user_login
				FROM {$table} p
				INNER JOIN {$users} u ON u.ID = p.user_id
				WHERE p.user_id = %d
				LIMIT 1",
				$user_id
			),
			ARRAY_A
		);
	}

	public function get_profile_by_person_code( $person_code ) {
		global $wpdb;

		$person_code = strtoupper( sanitize_text_field( $person_code ) );

		if ( '' === $person_code ) {
			return null;
		}

		$table = Schema::table( 'user_profiles' );
		$users = $wpdb->users;

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT p.*, u.display_name, u.user_email, u.user_login
				FROM {$table} p
				INNER JOIN {$users} u ON u.ID = p.user_id
				WHERE p.person_code = %s
				LIMIT 1",
				$person_code
			),
			ARRAY_A
		);
	}

	public function find_user_id_by_person_code( $person_code ) {
		$profile = $this->get_profile_by_person_code( $person_code );

		return ! empty( $profile['user_id'] ) ? (int) $profile['user_id'] : 0;
	}

	public function get_profile_by_nrc_number( $nrc_number ) {
		global $wpdb;

		$nrc_number = $this->normalize_nrc_number( $nrc_number );

		if ( '' === $nrc_number ) {
			return null;
		}

		$table = Schema::table( 'user_profiles' );
		$users = $wpdb->users;

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT p.*, u.display_name, u.user_email, u.user_login
				FROM {$table} p
				INNER JOIN {$users} u ON u.ID = p.user_id
				WHERE p.nrc_number = %s
				LIMIT 1",
				$nrc_number
			),
			ARRAY_A
		);
	}

	public function find_user_id_by_nrc_number( $nrc_number ) {
		$profile = $this->get_profile_by_nrc_number( $nrc_number );

		return ! empty( $profile['user_id'] ) ? (int) $profile['user_id'] : 0;
	}

	public function upsert_profile( $user_id, array $data ) {
		global $wpdb;

		$user_id = absint( $user_id );

		if ( ! $user_id || ! get_userdata( $user_id ) ) {
			return new WP_Error( 'slms_invalid_user', __( 'Invalid user.', 'simple-lms' ) );
		}

		$existing = $this->get_profile( $user_id );
		$clean    = $this->sanitize_profile_data( $user_id, $data, $existing );

		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		$table = Schema::table( 'user_profiles' );

		if ( $existing ) {
			$updated = $wpdb->update(
				$table,
				$clean,
				array( 'user_id' => $user_id ),
				$this->get_profile_data_formats( $clean ),
				array( '%d' )
			);

			if ( false === $updated ) {
				return new WP_Error( 'slms_profile_update_failed', __( 'The profile could not be updated.', 'simple-lms' ) );
			}

			return $user_id;
		}

		$inserted = $wpdb->insert(
			$table,
			$clean,
			$this->get_profile_data_formats( $clean )
		);

		if ( false === $inserted ) {
			return new WP_Error( 'slms_profile_insert_failed', __( 'The profile could not be created.', 'simple-lms' ) );
		}

		return $user_id;
	}

	public function mark_invite_sent( $user_id ) {
		global $wpdb;

		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return false;
		}

		$updated = $wpdb->update(
			Schema::table( 'user_profiles' ),
			array(
				'invite_sent_at' => AcademicClock::mysql(),
				'updated_at'     => AcademicClock::mysql(),
			),
			array( 'user_id' => $user_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return false !== $updated;
	}

	public function mark_password_setup_complete( $user_id ) {
		global $wpdb;

		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return false;
		}

		$updated = $wpdb->update(
			Schema::table( 'user_profiles' ),
			array(
				'status'         => 'active',
				'last_active_at' => AcademicClock::mysql(),
				'updated_at'     => AcademicClock::mysql(),
			),
			array( 'user_id' => $user_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		return false !== $updated;
	}

	public function maybe_purge_orphaned_profiles() {
		$completed_version = (string) get_option( self::ORPHAN_PURGE_OPTION, '' );

		if ( self::ORPHAN_PURGE_VERSION === $completed_version ) {
			return;
		}

		$purged = $this->purge_orphaned_profiles();

		if ( false !== $purged ) {
			update_option( self::ORPHAN_PURGE_OPTION, self::ORPHAN_PURGE_VERSION, false );
		}
	}

	public function purge_orphaned_profiles() {
		global $wpdb;

		$table = Schema::table( 'user_profiles' );
		$users = $wpdb->users;

		return $wpdb->query(
			"DELETE p
			FROM {$table} p
			LEFT JOIN {$users} u ON u.ID = p.user_id
			WHERE u.ID IS NULL"
		);
	}

	public function handle_user_deleted( $user_id ) {
		$this->delete_profile( $user_id );
	}

	public function delete_profile( $user_id ) {
		global $wpdb;

		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return false;
		}

		$deleted = $wpdb->delete(
			Schema::table( 'user_profiles' ),
			array( 'user_id' => $user_id ),
			array( '%d' )
		);

		return false !== $deleted;
	}


	public function get_profile_photo_url( $user_id, $size = 96 ) {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return $this->get_default_profile_photo_url();
		}

		$managed_photo = get_user_meta( $user_id, 'slms_profile_photo_upload', true );

		if ( is_array( $managed_photo ) ) {
			if ( ! empty( $managed_photo['url'] ) ) {
				return esc_url_raw( $managed_photo['url'] );
			}

			if ( ! empty( $managed_photo['relative_path'] ) ) {
				$upload_dir = wp_upload_dir();

				if ( empty( $upload_dir['error'] ) ) {
					return esc_url_raw( trailingslashit( $upload_dir['baseurl'] ) . ltrim( (string) $managed_photo['relative_path'], '/' ) );
				}
			}
		} elseif ( is_string( $managed_photo ) && preg_match( '#^https?://#i', $managed_photo ) ) {
			return esc_url_raw( $managed_photo );
		}

		$attachment_id = absint( get_user_meta( $user_id, 'slms_profile_photo_id', true ) );

		if ( $attachment_id ) {
			$url = wp_get_attachment_image_url( $attachment_id, array( absint( $size ), absint( $size ) ) );

			if ( $url ) {
				return esc_url_raw( $url );
			}
		}

		return $this->get_default_profile_photo_url();
	}

	public function get_default_profile_photo_url() {
		if ( defined( 'SLMS_URL' ) ) {
			return esc_url_raw( SLMS_URL . 'assets/images/default-profile.svg' );
		}

		return esc_url_raw( includes_url( 'images/blank.gif' ) );
	}

	public function list_profiles( array $args = array() ) {
		global $wpdb;

		$table          = Schema::table( 'user_profiles' );
		$users          = $wpdb->users;
		$program_terms  = $wpdb->terms;
		$academic_terms = Schema::table( 'terms' );
		$where          = 'WHERE 1=1';
		$params         = array();
		$joins          = "INNER JOIN {$users} u ON u.ID = p.user_id
			LEFT JOIN {$program_terms} program_terms ON program_terms.term_id = p.program_id
			LEFT JOIN {$academic_terms} intake_terms ON intake_terms.id = p.intake_term_id";
		$orderby        = sanitize_key( $args['orderby'] ?? ( $args['sort'] ?? '' ) );
		$role           = sanitize_key( $args['role'] ?? '' );
		$needs_role_join = '' !== $role || in_array( $orderby, array( 'role', 'staff_role' ), true );

		if ( $needs_role_join ) {
			$role_meta_key = $wpdb->get_blog_prefix() . 'capabilities';
			$joins        .= $wpdb->prepare( "\n\t\t\tLEFT JOIN {$wpdb->usermeta} role_meta ON role_meta.user_id = u.ID AND role_meta.meta_key = %s", $role_meta_key );
		}

		if ( ! empty( $args['person_type'] ) && in_array( $args['person_type'], self::PERSON_TYPES, true ) ) {
			$where   .= ' AND p.person_type = %s';
			$params[] = $args['person_type'];
		}

		if ( ! empty( $args['status'] ) && in_array( $args['status'], self::STATUSES, true ) ) {
			$where   .= ' AND p.status = %s';
			$params[] = $args['status'];
		}

		if ( ! empty( $args['program_id'] ) ) {
			$where   .= ' AND p.program_id = %d';
			$params[] = absint( $args['program_id'] );
		}

		if ( ! empty( $args['intake_term_id'] ) ) {
			$where   .= ' AND p.intake_term_id = %d';
			$params[] = absint( $args['intake_term_id'] );
		}

		if ( ! empty( $args['intake_academic_year'] ) ) {
			$where   .= ' AND intake_terms.academic_year = %s';
			$params[] = sanitize_text_field( $args['intake_academic_year'] );
		}

		if ( ! empty( $args['department'] ) ) {
			$where   .= ' AND p.department = %s';
			$params[] = sanitize_text_field( $args['department'] );
		}

		if ( '' !== $role && in_array( $role, array( 'administrator', 'officer', 'lecturer', RoleManager::ROLE_STAFF ), true ) ) {
			$where   .= ' AND role_meta.meta_value LIKE %s';
			$params[] = '%' . $wpdb->esc_like( '"' . $role . '"' ) . '%';
		}

		if ( ! empty( $args['search'] ) ) {
			$search   = '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
			$where   .= ' AND (p.person_code LIKE %s OR u.display_name LIKE %s OR u.user_email LIKE %s OR p.department LIKE %s)';
			$params[] = $search;
			$params[] = $search;
			$params[] = $search;
			$params[] = $search;
		}

		$page     = max( 1, absint( $args['page'] ?? 1 ) );
		$per_page = max( 1, min( 100, absint( $args['per_page'] ?? 20 ) ) );
		$offset   = ( $page - 1 ) * $per_page;
		$order_by = $this->build_profile_order_clause( $orderby, $needs_role_join );

		$sql = "
			SELECT p.*, u.display_name, u.user_email, u.user_login
			FROM {$table} p
			{$joins}
			{$where}
			{$order_by}
			LIMIT %d OFFSET %d
		";

		$params[] = $per_page;
		$params[] = $offset;

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
	}

	public function count_profiles( $person_type = '' ) {
		global $wpdb;

		$table  = Schema::table( 'user_profiles' );
		$users  = $wpdb->users;
		$where  = 'WHERE 1=1';
		$params = array();

		if ( $person_type && in_array( $person_type, self::PERSON_TYPES, true ) ) {
			$where   .= ' AND p.person_type = %s';
			$params[] = $person_type;
		}

		$sql = "SELECT COUNT(*) FROM {$table} p INNER JOIN {$users} u ON u.ID = p.user_id {$where}";

		if ( $params ) {
			$sql = $wpdb->prepare( $sql, $params );
		}

		return (int) $wpdb->get_var( $sql );
	}

	public function count_profiles_matching( array $args = array() ) {
		global $wpdb;

		$table          = Schema::table( 'user_profiles' );
		$users          = $wpdb->users;
		$program_terms  = $wpdb->terms;
		$academic_terms = Schema::table( 'terms' );
		$where          = 'WHERE 1=1';
		$params         = array();
		$joins          = "INNER JOIN {$users} u ON u.ID = p.user_id
			LEFT JOIN {$program_terms} program_terms ON program_terms.term_id = p.program_id
			LEFT JOIN {$academic_terms} intake_terms ON intake_terms.id = p.intake_term_id";
		$role           = sanitize_key( $args['role'] ?? '' );

		if ( '' !== $role ) {
			$role_meta_key = $wpdb->get_blog_prefix() . 'capabilities';
			$joins        .= $wpdb->prepare( "\n\t\t\tLEFT JOIN {$wpdb->usermeta} role_meta ON role_meta.user_id = u.ID AND role_meta.meta_key = %s", $role_meta_key );
		}

		if ( ! empty( $args['person_type'] ) && in_array( $args['person_type'], self::PERSON_TYPES, true ) ) {
			$where   .= ' AND p.person_type = %s';
			$params[] = $args['person_type'];
		}

		if ( ! empty( $args['status'] ) && in_array( $args['status'], self::STATUSES, true ) ) {
			$where   .= ' AND p.status = %s';
			$params[] = $args['status'];
		}

		if ( ! empty( $args['program_id'] ) ) {
			$where   .= ' AND p.program_id = %d';
			$params[] = absint( $args['program_id'] );
		}

		if ( ! empty( $args['intake_term_id'] ) ) {
			$where   .= ' AND p.intake_term_id = %d';
			$params[] = absint( $args['intake_term_id'] );
		}

		if ( ! empty( $args['intake_academic_year'] ) ) {
			$where   .= ' AND intake_terms.academic_year = %s';
			$params[] = sanitize_text_field( $args['intake_academic_year'] );
		}

		if ( ! empty( $args['department'] ) ) {
			$where   .= ' AND p.department = %s';
			$params[] = sanitize_text_field( $args['department'] );
		}

		if ( '' !== $role && in_array( $role, array( 'administrator', 'officer', 'lecturer', RoleManager::ROLE_STAFF ), true ) ) {
			$where   .= ' AND role_meta.meta_value LIKE %s';
			$params[] = '%' . $wpdb->esc_like( '"' . $role . '"' ) . '%';
		}

		if ( ! empty( $args['search'] ) ) {
			$search   = '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
			$where   .= ' AND (p.person_code LIKE %s OR u.display_name LIKE %s OR u.user_email LIKE %s OR p.department LIKE %s)';
			$params[] = $search;
			$params[] = $search;
			$params[] = $search;
			$params[] = $search;
		}

		$sql = "SELECT COUNT(*) FROM {$table} p {$joins} {$where}";

		if ( $params ) {
			$sql = $wpdb->prepare( $sql, $params );
		}

		return (int) $wpdb->get_var( $sql );
	}

	private function build_profile_order_clause( $orderby, $has_role_join = false ) {
		$orderby = sanitize_key( $orderby );

		switch ( $orderby ) {
			case 'department':
				return "ORDER BY COALESCE(p.department, '') ASC, u.display_name ASC";

			case 'program_intake':
				return "ORDER BY COALESCE(program_terms.name, '') ASC, COALESCE(intake_terms.academic_year, '') DESC, intake_terms.sort_order ASC, u.display_name ASC";

			case 'intake_program':
				return "ORDER BY COALESCE(intake_terms.academic_year, '') DESC, intake_terms.sort_order ASC, COALESCE(program_terms.name, '') ASC, u.display_name ASC";

			case 'name':
				return 'ORDER BY u.display_name ASC, p.id DESC';

			case 'role':
			case 'staff_role':
				if ( $has_role_join ) {
					return "ORDER BY CASE
						WHEN role_meta.meta_value LIKE '%\"administrator\"%' THEN 1
						WHEN role_meta.meta_value LIKE '%\"officer\"%' THEN 2
						WHEN role_meta.meta_value LIKE '%\"lecturer\"%' THEN 3
						WHEN role_meta.meta_value LIKE '%\"slms_staff\"%' THEN 4
						ELSE 99
					END ASC, u.display_name ASC";
				}
				break;

			case 'updated':
			default:
				break;
		}

		return 'ORDER BY p.updated_at DESC, p.id DESC';
	}

	private function sanitize_profile_data( $user_id, array $data, $existing = null ) {
		global $wpdb;

		$person_type    = sanitize_key( $data['person_type'] ?? ( $existing['person_type'] ?? 'student' ) );
		$status         = sanitize_key( $data['status'] ?? ( $existing['status'] ?? 'active' ) );
		$person_code    = strtoupper( sanitize_text_field( $data['person_code'] ?? ( $existing['person_code'] ?? '' ) ) );
		$nrc_number     = $this->normalize_nrc_number( $data['nrc_number'] ?? ( $existing['nrc_number'] ?? '' ) );
		$phone          = sanitize_text_field( $data['phone'] ?? ( $existing['phone'] ?? '' ) );
		$program_id     = absint( $data['program_id'] ?? ( $existing['program_id'] ?? 0 ) );
		$intake_term_id = absint( $data['intake_term_id'] ?? ( $existing['intake_term_id'] ?? 0 ) );
		$department     = sanitize_text_field( $data['department'] ?? ( $existing['department'] ?? '' ) );
		$position_title = sanitize_text_field( $data['position_title'] ?? ( $existing['position_title'] ?? '' ) );
		$notes          = sanitize_textarea_field( $data['notes'] ?? ( $existing['notes'] ?? '' ) );
		$invite_sent_at = $data['invite_sent_at'] ?? ( $existing['invite_sent_at'] ?? null );
		$last_active_at = $data['last_active_at'] ?? ( $existing['last_active_at'] ?? null );
		$now            = AcademicClock::mysql();

		if ( ! in_array( $person_type, self::PERSON_TYPES, true ) ) {
			return new WP_Error( 'slms_invalid_person_type', __( 'Invalid person type.', 'simple-lms' ) );
		}

		if ( ! in_array( $status, self::STATUSES, true ) ) {
			$status = 'active';
		}

		if ( $person_code ) {
			$duplicate_user_id = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT p.user_id
					FROM ' . Schema::table( 'user_profiles' ) . ' p
					INNER JOIN ' . $wpdb->users . ' u ON u.ID = p.user_id
					WHERE p.person_code = %s
					LIMIT 1',
					$person_code,
				)
			);

			if ( $duplicate_user_id && (int) $duplicate_user_id !== (int) $user_id ) {
				return new WP_Error( 'slms_duplicate_person_code', __( 'That person code is already in use.', 'simple-lms' ) );
			}
		}

		if ( $nrc_number ) {
			$duplicate_nrc_user_id = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT p.user_id
					FROM ' . Schema::table( 'user_profiles' ) . ' p
					INNER JOIN ' . $wpdb->users . ' u ON u.ID = p.user_id
					WHERE p.nrc_number = %s
					LIMIT 1',
					$nrc_number,
				)
			);

			if ( $duplicate_nrc_user_id && (int) $duplicate_nrc_user_id !== (int) $user_id ) {
				return new WP_Error( 'slms_duplicate_nrc_number', __( 'That NRC number is already assigned to another profile.', 'simple-lms' ) );
			}
		}

		return array(
			'user_id'        => $user_id,
			'person_type'    => $person_type,
			'person_code'    => $person_code ?: null,
			'nrc_number'     => $nrc_number ?: null,
			'status'         => $status,
			'phone'          => $phone ?: null,
			'program_id'     => $program_id ?: null,
			'intake_term_id' => $intake_term_id ?: null,
			'department'     => $department ?: null,
			'position_title' => $position_title ?: null,
			'notes'          => $notes ?: null,
			'invite_sent_at' => $invite_sent_at ?: null,
			'last_active_at' => $last_active_at ?: null,
			'created_by'     => ! empty( $existing['created_by'] ) ? (int) $existing['created_by'] : get_current_user_id(),
			'updated_by'     => get_current_user_id(),
			'created_at'     => $existing['created_at'] ?? $now,
			'updated_at'     => $now,
		);
	}

	public function normalize_nrc_number( $value ) {
		$value = wp_strip_all_tags( (string) $value );
		$value = sanitize_text_field( $value );
		$value = preg_replace( '/\s+/u', ' ', trim( $value ) );

		if ( null === $value ) {
			$value = '';
		}

		$placeholder_values = array( '', '-', 'N/A', 'NA', 'NONE', 'TBD', 'PENDING' );

		if ( in_array( strtoupper( $value ), $placeholder_values, true ) ) {
			return '';
		}

		$value = strtoupper( $value );

		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, 100 );
		}

		return substr( $value, 0, 100 );
	}

	private function get_profile_data_formats( array $data ) {
		$format_map = array(
			'user_id'        => '%d',
			'person_type'    => '%s',
			'person_code'    => '%s',
			'nrc_number'     => '%s',
			'status'         => '%s',
			'phone'          => '%s',
			'program_id'     => '%d',
			'intake_term_id' => '%d',
			'department'     => '%s',
			'position_title' => '%s',
			'notes'          => '%s',
			'invite_sent_at' => '%s',
			'last_active_at' => '%s',
			'created_by'     => '%d',
			'updated_by'     => '%d',
			'created_at'     => '%s',
			'updated_at'     => '%s',
		);

		$formats = array();

		foreach ( array_keys( $data ) as $field ) {
			$formats[] = $format_map[ $field ] ?? '%s';
		}

		return $formats;
	}
}
