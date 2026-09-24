<?php

namespace SimpleLMS\Infrastructure\Database;

use SimpleLMS\Core\AcademicClock;

defined( 'ABSPATH' ) || exit;

class Schema {
	const MIGRATION_FOUNDATION_0001 = 'foundation_0001';
	const MIGRATION_ACADEMICS_0002  = 'academics_0002';
	const MIGRATION_WORKSPACE_0003  = 'workspace_0003';
	const MIGRATION_ASSESSMENTS_0004 = 'assessments_0004';
	const MIGRATION_GRADEBOOK_0005 = 'gradebook_0005';
	const MIGRATION_ATTENDANCE_0006 = 'attendance_0006';
	const MIGRATION_PERSON_CODES_0007 = 'person_codes_0007';
	const MIGRATION_NRC_NUMBERS_0008 = 'nrc_numbers_0008';
	const MIGRATION_ID_CARDS_0009 = 'id_cards_0009';
	const MIGRATION_ATTENDANCE_STATUS_0010 = 'attendance_status_0010';
	const MIGRATION_BROADCASTS_0011 = 'broadcasts_0011';
	const MIGRATION_BROADCAST_MEDIA_0012 = 'broadcast_media_0012';
	const MIGRATION_BROADCAST_EMBED_0013 = 'broadcast_embed_0013';
	const MIGRATION_STAFF_AUDIT_ENROLLMENTS_0014 = 'staff_audit_enrollments_0014';
	const MIGRATION_EMAIL_CAMPAIGNS_0015 = 'email_campaigns_0015';
	const MIGRATION_DYNAMIC_LATE_STATUS_0016 = 'dynamic_late_status_0016';

	public static function install() {
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$migrations      = self::table( 'migrations' );
		$audit_log       = self::table( 'audit_log' );
		$notifications   = self::table( 'notifications' );

		$sql_migrations = "CREATE TABLE {$migrations} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			migration VARCHAR(190) NOT NULL,
			applied_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY migration (migration)
		) {$charset_collate};";

		dbDelta( $sql_migrations );

		if ( ! self::has_migration( self::MIGRATION_FOUNDATION_0001 ) ) {
			$sql_audit_log = "CREATE TABLE {$audit_log} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				event_type VARCHAR(100) NOT NULL,
				level VARCHAR(20) NOT NULL DEFAULT 'info',
				actor_user_id BIGINT(20) UNSIGNED NULL,
				object_type VARCHAR(100) NULL,
				object_id BIGINT(20) UNSIGNED NULL,
				message TEXT NULL,
				context_json LONGTEXT NULL,
				ip_address VARCHAR(45) NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				KEY event_type (event_type),
				KEY actor_user_id (actor_user_id),
				KEY object_type (object_type),
				KEY created_at (created_at)
			) {$charset_collate};";

			$sql_notifications = "CREATE TABLE {$notifications} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				channel VARCHAR(50) NOT NULL DEFAULT 'email',
				recipient_user_id BIGINT(20) UNSIGNED NULL,
				recipient_email VARCHAR(255) NULL,
				subject VARCHAR(255) NOT NULL,
				message LONGTEXT NOT NULL,
				context_json LONGTEXT NULL,
				status VARCHAR(20) NOT NULL DEFAULT 'pending',
				error_message TEXT NULL,
				created_at DATETIME NOT NULL,
				sent_at DATETIME NULL,
				PRIMARY KEY (id),
				KEY recipient_user_id (recipient_user_id),
				KEY recipient_email (recipient_email),
				KEY status (status),
				KEY created_at (created_at)
			) {$charset_collate};";

			dbDelta( $sql_audit_log );
			dbDelta( $sql_notifications );

			self::record_migration( self::MIGRATION_FOUNDATION_0001 );
		}

		if ( ! self::has_migration( self::MIGRATION_ACADEMICS_0002 ) ) {
			$terms_table         = self::table( 'terms' );
			$section_staff_table = self::table( 'section_staff' );
			$enrollments_table   = self::table( 'enrollments' );

			$sql_terms = "CREATE TABLE {$terms_table} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				code VARCHAR(100) NOT NULL,
				name VARCHAR(190) NOT NULL,
				academic_year VARCHAR(50) NOT NULL,
				start_date DATE NULL,
				end_date DATE NULL,
				status VARCHAR(30) NOT NULL DEFAULT 'planned',
				sort_order INT(11) NOT NULL DEFAULT 0,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY code (code),
				KEY academic_year (academic_year),
				KEY status (status)
			) {$charset_collate};";

			$sql_section_staff = "CREATE TABLE {$section_staff_table} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				section_id BIGINT(20) UNSIGNED NOT NULL,
				user_id BIGINT(20) UNSIGNED NOT NULL,
				role VARCHAR(50) NOT NULL DEFAULT 'lecturer',
				status VARCHAR(30) NOT NULL DEFAULT 'active',
				assigned_by BIGINT(20) UNSIGNED NULL,
				assigned_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY unique_assignment (section_id, user_id, role),
				KEY section_id (section_id),
				KEY user_id (user_id),
				KEY role (role),
				KEY status (status)
			) {$charset_collate};";

			$sql_enrollments = "CREATE TABLE {$enrollments_table} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				student_user_id BIGINT(20) UNSIGNED NOT NULL,
				section_id BIGINT(20) UNSIGNED NOT NULL,
				status VARCHAR(30) NOT NULL DEFAULT 'enrolled',
				source VARCHAR(30) NOT NULL DEFAULT 'manual',
				notes TEXT NULL,
				created_by BIGINT(20) UNSIGNED NULL,
				enrolled_at DATETIME NOT NULL,
				completed_at DATETIME NULL,
				dropped_at DATETIME NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY unique_enrollment (student_user_id, section_id),
				KEY student_user_id (student_user_id),
				KEY section_id (section_id),
				KEY status (status),
				KEY source (source)
			) {$charset_collate};";

			dbDelta( $sql_terms );
			dbDelta( $sql_section_staff );
			dbDelta( $sql_enrollments );

			self::record_migration( self::MIGRATION_ACADEMICS_0002 );
		}

		if ( ! self::has_migration( self::MIGRATION_WORKSPACE_0003 ) ) {
			$profiles_table = self::table( 'user_profiles' );

			$sql_profiles = "CREATE TABLE {$profiles_table} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id BIGINT(20) UNSIGNED NOT NULL,
				person_type VARCHAR(30) NOT NULL DEFAULT 'student',
				person_code VARCHAR(100) NULL,
				nrc_number VARCHAR(100) NULL,
				status VARCHAR(30) NOT NULL DEFAULT 'active',
				phone VARCHAR(50) NULL,
				program_id BIGINT(20) UNSIGNED NULL,
				intake_term_id BIGINT(20) UNSIGNED NULL,
				department VARCHAR(190) NULL,
				position_title VARCHAR(190) NULL,
				notes TEXT NULL,
				invite_sent_at DATETIME NULL,
				last_active_at DATETIME NULL,
				created_by BIGINT(20) UNSIGNED NULL,
				updated_by BIGINT(20) UNSIGNED NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY user_id (user_id),
				UNIQUE KEY person_code (person_code),
				UNIQUE KEY nrc_number (nrc_number),
				KEY person_type (person_type),
				KEY status (status),
				KEY program_id (program_id),
				KEY intake_term_id (intake_term_id)
			) {$charset_collate};";

			dbDelta( $sql_profiles );

			self::record_migration( self::MIGRATION_WORKSPACE_0003 );
		}

		if ( ! self::has_migration( self::MIGRATION_ASSESSMENTS_0004 ) ) {
			$submissions_table = self::table( 'assignment_submissions' );

			$sql_submissions = "CREATE TABLE {$submissions_table} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				assignment_id BIGINT(20) UNSIGNED NOT NULL,
				section_id BIGINT(20) UNSIGNED NOT NULL,
				student_user_id BIGINT(20) UNSIGNED NOT NULL,
				status VARCHAR(30) NOT NULL DEFAULT 'submitted',
				attempt_number INT(11) NOT NULL DEFAULT 1,
				submission_text LONGTEXT NULL,
				attachment_url TEXT NULL,
				attachment_name VARCHAR(255) NULL,
				score DECIMAL(8,2) NULL,
				feedback LONGTEXT NULL,
				graded_by BIGINT(20) UNSIGNED NULL,
				submitted_at DATETIME NOT NULL,
				graded_at DATETIME NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY unique_submission (assignment_id, student_user_id),
				KEY assignment_id (assignment_id),
				KEY section_id (section_id),
				KEY student_user_id (student_user_id),
				KEY status (status),
				KEY graded_by (graded_by)
			) {$charset_collate};";

			dbDelta( $sql_submissions );

			self::record_migration( self::MIGRATION_ASSESSMENTS_0004 );
		}

		if ( ! self::has_migration( self::MIGRATION_GRADEBOOK_0005 ) ) {
			$categories_table = self::table( 'grade_categories' );
			$items_table      = self::table( 'grade_items' );
			$scores_table     = self::table( 'grade_scores' );

			$sql_categories = "CREATE TABLE {$categories_table} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				section_id BIGINT(20) UNSIGNED NOT NULL,
				title VARCHAR(190) NOT NULL,
				category_type VARCHAR(50) NOT NULL DEFAULT 'custom',
				weight_percent DECIMAL(6,2) NOT NULL DEFAULT 0.00,
				scoring_method VARCHAR(50) NOT NULL DEFAULT 'weighted_items',
				publish_to_students TINYINT(1) NOT NULL DEFAULT 0,
				sort_order INT(11) NOT NULL DEFAULT 0,
				created_by BIGINT(20) UNSIGNED NULL,
				updated_by BIGINT(20) UNSIGNED NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				KEY section_id (section_id),
				KEY category_type (category_type),
				KEY sort_order (sort_order)
			) {$charset_collate};";

			$sql_items = "CREATE TABLE {$items_table} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				category_id BIGINT(20) UNSIGNED NOT NULL,
				section_id BIGINT(20) UNSIGNED NOT NULL,
				title VARCHAR(190) NOT NULL,
				item_type VARCHAR(50) NOT NULL DEFAULT 'manual',
				linked_post_id BIGINT(20) UNSIGNED NULL,
				weight_percent DECIMAL(6,2) NOT NULL DEFAULT 0.00,
				max_points DECIMAL(8,2) NOT NULL DEFAULT 100.00,
				publish_to_students TINYINT(1) NOT NULL DEFAULT 0,
				due_at DATETIME NULL,
				sort_order INT(11) NOT NULL DEFAULT 0,
				created_by BIGINT(20) UNSIGNED NULL,
				updated_by BIGINT(20) UNSIGNED NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				KEY category_id (category_id),
				KEY section_id (section_id),
				KEY item_type (item_type),
				KEY linked_post_id (linked_post_id),
				KEY sort_order (sort_order)
			) {$charset_collate};";

			$sql_scores = "CREATE TABLE {$scores_table} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				item_id BIGINT(20) UNSIGNED NOT NULL,
				category_id BIGINT(20) UNSIGNED NOT NULL,
				section_id BIGINT(20) UNSIGNED NOT NULL,
				student_user_id BIGINT(20) UNSIGNED NOT NULL,
				score DECIMAL(8,2) NULL,
				feedback LONGTEXT NULL,
				source_type VARCHAR(50) NOT NULL DEFAULT 'manual',
				source_ref_id BIGINT(20) UNSIGNED NULL,
				graded_by BIGINT(20) UNSIGNED NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY unique_item_student (item_id, student_user_id),
				KEY category_id (category_id),
				KEY section_id (section_id),
				KEY student_user_id (student_user_id),
				KEY source_type (source_type)
			) {$charset_collate};";

			dbDelta( $sql_categories );
			dbDelta( $sql_items );
			dbDelta( $sql_scores );

			self::record_migration( self::MIGRATION_GRADEBOOK_0005 );
		}

		if ( ! self::has_migration( self::MIGRATION_ATTENDANCE_0006 ) ) {
			$sessions_table = self::table( 'attendance_sessions' );
			$records_table  = self::table( 'attendance_records' );

			$sql_sessions = "CREATE TABLE {$sessions_table} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				section_id BIGINT(20) UNSIGNED NOT NULL,
				session_number INT(11) NOT NULL DEFAULT 0,
				session_title VARCHAR(190) NOT NULL,
				session_date DATE NOT NULL,
				starts_at TIME NULL,
				ends_at TIME NULL,
				week_label VARCHAR(100) NULL,
				notes TEXT NULL,
				status VARCHAR(30) NOT NULL DEFAULT 'scheduled',
				created_by BIGINT(20) UNSIGNED NULL,
				updated_by BIGINT(20) UNSIGNED NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY unique_section_session (section_id, session_number),
				KEY section_id (section_id),
				KEY session_date (session_date),
				KEY status (status)
			) {$charset_collate};";

			$sql_records = "CREATE TABLE {$records_table} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				session_id BIGINT(20) UNSIGNED NOT NULL,
				section_id BIGINT(20) UNSIGNED NOT NULL,
				student_user_id BIGINT(20) UNSIGNED NOT NULL,
				attendance_status VARCHAR(30) NOT NULL DEFAULT 'present',
				minutes_late INT(11) NOT NULL DEFAULT 0,
				notes TEXT NULL,
				marked_by BIGINT(20) UNSIGNED NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY unique_session_student (session_id, student_user_id),
				KEY section_id (section_id),
				KEY student_user_id (student_user_id),
				KEY attendance_status (attendance_status)
			) {$charset_collate};";

			dbDelta( $sql_sessions );
			dbDelta( $sql_records );

			self::record_migration( self::MIGRATION_ATTENDANCE_0006 );
		}

		if ( ! self::has_migration( self::MIGRATION_PERSON_CODES_0007 ) ) {
			$counters_table = self::table( 'person_code_counters' );

			$sql_counters = "CREATE TABLE {$counters_table} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				person_type VARCHAR(30) NOT NULL,
				code_year SMALLINT(4) UNSIGNED NOT NULL,
				last_number INT(11) UNSIGNED NOT NULL DEFAULT 0,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY unique_scope (person_type, code_year),
				KEY person_type (person_type),
				KEY code_year (code_year)
			) {$charset_collate};";

			dbDelta( $sql_counters );

			self::record_migration( self::MIGRATION_PERSON_CODES_0007 );
		}

		if ( ! self::has_migration( self::MIGRATION_NRC_NUMBERS_0008 ) ) {
			$profiles_table = self::table( 'user_profiles' );

			if ( ! self::column_exists( $profiles_table, 'nrc_number' ) ) {
				$wpdb->query( "ALTER TABLE {$profiles_table} ADD COLUMN nrc_number VARCHAR(100) NULL AFTER person_code" );
			}

			if ( ! self::index_exists( $profiles_table, 'nrc_number' ) ) {
				$wpdb->query( "ALTER TABLE {$profiles_table} ADD UNIQUE KEY nrc_number (nrc_number)" );
			}

			self::record_migration( self::MIGRATION_NRC_NUMBERS_0008 );
		}


		if ( ! self::has_migration( self::MIGRATION_ID_CARDS_0009 ) ) {
			$id_cards_table = self::table( 'id_cards' );
			$id_logs_table  = self::table( 'id_card_logs' );

			$sql_id_cards = "CREATE TABLE {$id_cards_table} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				person_user_id BIGINT(20) UNSIGNED NOT NULL,
				person_type VARCHAR(30) NOT NULL DEFAULT 'student',
				person_code VARCHAR(100) NULL,
				full_name VARCHAR(190) NULL,
				role_label VARCHAR(190) NULL,
				program_label VARCHAR(190) NULL,
				department_label VARCHAR(190) NULL,
				nrc_number VARCHAR(100) NULL,
				phone VARCHAR(80) NULL,
				email VARCHAR(190) NULL,
				photo_url TEXT NULL,
				verification_token VARCHAR(128) NOT NULL,
				status VARCHAR(30) NOT NULL DEFAULT 'active',
				issued_by BIGINT(20) UNSIGNED NULL,
				issued_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY verification_token (verification_token),
				KEY person_user_id (person_user_id),
				KEY person_type (person_type),
				KEY person_code (person_code),
				KEY status (status)
			) {$charset_collate};";

			$sql_id_logs = "CREATE TABLE {$id_logs_table} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				card_id BIGINT(20) UNSIGNED NULL,
				person_user_id BIGINT(20) UNSIGNED NULL,
				action VARCHAR(60) NOT NULL,
				actor_user_id BIGINT(20) UNSIGNED NULL,
				message TEXT NULL,
				context_json LONGTEXT NULL,
				ip_address VARCHAR(45) NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				KEY card_id (card_id),
				KEY person_user_id (person_user_id),
				KEY action (action),
				KEY created_at (created_at)
			) {$charset_collate};";

			dbDelta( $sql_id_cards );
			dbDelta( $sql_id_logs );

			self::record_migration( self::MIGRATION_ID_CARDS_0009 );
		}


		if ( ! self::has_migration( self::MIGRATION_ATTENDANCE_STATUS_0010 ) ) {
			$records_table = self::table( 'attendance_records' );

			if ( self::column_exists( $records_table, 'attendance_status' ) ) {
				$wpdb->update(
					$records_table,
					array( 'attendance_status' => 'present' ),
					array( 'attendance_status' => 'excused' ),
					array( '%s' ),
					array( '%s' )
				);
			}

			self::record_migration( self::MIGRATION_ATTENDANCE_STATUS_0010 );
		}


		if ( ! self::has_migration( self::MIGRATION_BROADCASTS_0011 ) ) {
			$broadcasts_table = self::table( 'broadcast_messages' );
			$targets_table    = self::table( 'broadcast_targets' );
			$recipients_table = self::table( 'broadcast_recipients' );

			$sql_broadcasts = "CREATE TABLE {$broadcasts_table} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				title VARCHAR(190) NOT NULL,
				message LONGTEXT NOT NULL,
				media_attachment_ids LONGTEXT NULL,
				embed_url TEXT NULL,
				message_type VARCHAR(30) NOT NULL DEFAULT 'normal',
				status VARCHAR(30) NOT NULL DEFAULT 'draft',
				show_popup TINYINT(1) NOT NULL DEFAULT 0,
				requires_acknowledgement TINYINT(1) NOT NULL DEFAULT 0,
				created_by BIGINT(20) UNSIGNED NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				published_at DATETIME NULL,
				expires_at DATETIME NULL,
				PRIMARY KEY (id),
				KEY message_type (message_type),
				KEY status (status),
				KEY show_popup (show_popup),
				KEY requires_acknowledgement (requires_acknowledgement),
				KEY published_at (published_at)
			) {$charset_collate};";

			$sql_targets = "CREATE TABLE {$targets_table} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				broadcast_id BIGINT(20) UNSIGNED NOT NULL,
				target_type VARCHAR(50) NOT NULL,
				target_value VARCHAR(190) NOT NULL,
				PRIMARY KEY (id),
				KEY broadcast_id (broadcast_id),
				KEY target_type (target_type),
				KEY target_value (target_value)
			) {$charset_collate};";

			$sql_recipients = "CREATE TABLE {$recipients_table} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				broadcast_id BIGINT(20) UNSIGNED NOT NULL,
				user_id BIGINT(20) UNSIGNED NOT NULL,
				role_at_send VARCHAR(50) NULL,
				email VARCHAR(190) NULL,
				seen_at DATETIME NULL,
				popup_seen_at DATETIME NULL,
				acknowledged_at DATETIME NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY unique_broadcast_user (broadcast_id, user_id),
				KEY broadcast_id (broadcast_id),
				KEY user_id (user_id),
				KEY seen_at (seen_at),
				KEY acknowledged_at (acknowledged_at)
			) {$charset_collate};";

			dbDelta( $sql_broadcasts );
			dbDelta( $sql_targets );
			dbDelta( $sql_recipients );

			self::record_migration( self::MIGRATION_BROADCASTS_0011 );
		}

		if ( ! self::has_migration( self::MIGRATION_BROADCAST_MEDIA_0012 ) ) {
			$broadcasts_table = self::table( 'broadcast_messages' );

			if ( ! self::column_exists( $broadcasts_table, 'media_attachment_ids' ) ) {
				$wpdb->query( "ALTER TABLE {$broadcasts_table} ADD media_attachment_ids LONGTEXT NULL AFTER message" );
			}

			self::record_migration( self::MIGRATION_BROADCAST_MEDIA_0012 );
		}

		if ( ! self::has_migration( self::MIGRATION_BROADCAST_EMBED_0013 ) ) {
			$broadcasts_table = self::table( 'broadcast_messages' );

			if ( ! self::column_exists( $broadcasts_table, 'embed_url' ) ) {
				$wpdb->query( "ALTER TABLE {$broadcasts_table} ADD embed_url TEXT NULL AFTER media_attachment_ids" );
			}

			self::record_migration( self::MIGRATION_BROADCAST_EMBED_0013 );
		}


		if ( ! self::has_migration( self::MIGRATION_STAFF_AUDIT_ENROLLMENTS_0014 ) ) {
			$staff_audit_table = self::table( 'staff_audit_enrollments' );
			$submissions_table = self::table( 'assignment_submissions' );

			$sql_staff_audit = "CREATE TABLE {$staff_audit_table} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				staff_user_id BIGINT(20) UNSIGNED NOT NULL,
				section_id BIGINT(20) UNSIGNED NOT NULL,
				status VARCHAR(30) NOT NULL DEFAULT 'active',
				source VARCHAR(30) NOT NULL DEFAULT 'manual_staff_code',
				notes TEXT NULL,
				created_by BIGINT(20) UNSIGNED NULL,
				enrolled_at DATETIME NOT NULL,
				completed_at DATETIME NULL,
				dropped_at DATETIME NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY unique_staff_audit (staff_user_id, section_id),
				KEY staff_user_id (staff_user_id),
				KEY section_id (section_id),
				KEY status (status),
				KEY source (source)
			) {$charset_collate};";

			dbDelta( $sql_staff_audit );

			if ( ! self::column_exists( $submissions_table, 'submitter_type' ) ) {
				$wpdb->query( "ALTER TABLE {$submissions_table} ADD submitter_type VARCHAR(30) NOT NULL DEFAULT 'student' AFTER student_user_id" );
			}

			if ( ! self::column_exists( $submissions_table, 'is_audit_submission' ) ) {
				$wpdb->query( "ALTER TABLE {$submissions_table} ADD is_audit_submission TINYINT(1) NOT NULL DEFAULT 0 AFTER submitter_type" );
			}

			if ( ! self::index_exists( $submissions_table, 'submitter_type' ) ) {
				$wpdb->query( "ALTER TABLE {$submissions_table} ADD KEY submitter_type (submitter_type)" );
			}

			if ( ! self::index_exists( $submissions_table, 'is_audit_submission' ) ) {
				$wpdb->query( "ALTER TABLE {$submissions_table} ADD KEY is_audit_submission (is_audit_submission)" );
			}

			self::record_migration( self::MIGRATION_STAFF_AUDIT_ENROLLMENTS_0014 );
		}

		if ( ! self::has_migration( self::MIGRATION_EMAIL_CAMPAIGNS_0015 ) ) {
			$email_campaigns = self::table( 'email_campaigns' );
			$notifications   = self::table( 'notifications' );

			$sql_email_campaigns = "CREATE TABLE {$email_campaigns} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				subject VARCHAR(255) NOT NULL,
				message LONGTEXT NOT NULL,
				targets_json LONGTEXT NOT NULL,
				status VARCHAR(20) NOT NULL DEFAULT 'queued',
				recipient_count INT(11) UNSIGNED NOT NULL DEFAULT 0,
				skipped_count INT(11) UNSIGNED NOT NULL DEFAULT 0,
				created_by BIGINT(20) UNSIGNED NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				cancelled_at DATETIME NULL,
				PRIMARY KEY (id),
				KEY status (status),
				KEY created_by (created_by),
				KEY created_at (created_at)
			) {$charset_collate};";

			dbDelta( $sql_email_campaigns );

			if ( ! self::column_exists( $notifications, 'campaign_id' ) ) {
				$wpdb->query( "ALTER TABLE {$notifications} ADD campaign_id BIGINT(20) UNSIGNED NULL AFTER context_json" );
			}

			if ( ! self::column_exists( $notifications, 'processing_started_at' ) ) {
				$wpdb->query( "ALTER TABLE {$notifications} ADD processing_started_at DATETIME NULL AFTER status" );
			}

			if ( ! self::index_exists( $notifications, 'campaign_id' ) ) {
				$wpdb->query( "ALTER TABLE {$notifications} ADD KEY campaign_id (campaign_id)" );
			}

			if ( ! self::table_exists( $email_campaigns ) || ! self::column_exists( $notifications, 'campaign_id' ) || ! self::column_exists( $notifications, 'processing_started_at' ) || ! self::index_exists( $notifications, 'campaign_id' ) ) {
				return;
			}

			self::record_migration( self::MIGRATION_EMAIL_CAMPAIGNS_0015 );
		}

		if ( ! self::has_migration( self::MIGRATION_DYNAMIC_LATE_STATUS_0016 ) ) {
			$submissions_table = self::table( 'assignment_submissions' );
			$legacy_rows       = $wpdb->get_results( "SELECT id, attempt_number FROM {$submissions_table} WHERE status = 'late'", ARRAY_A );

			if ( ! empty( $legacy_rows ) ) {
				update_option(
					'slms_legacy_late_submission_ids_3515',
					array_map(
						static function ( $row ) {
							return (int) $row['id'];
						},
						$legacy_rows
					),
					false
				);

				$wpdb->query( "UPDATE {$submissions_table} SET status = CASE WHEN attempt_number > 1 THEN 'resubmitted' ELSE 'submitted' END WHERE status = 'late'" );
			}

			if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$submissions_table} WHERE status = 'late'" ) > 0 ) {
				return;
			}

			self::record_migration( self::MIGRATION_DYNAMIC_LATE_STATUS_0016 );
		}

		update_option( 'slms_db_version', SLMS_DB_VERSION );

		if ( ! get_option( 'slms_installed_at' ) ) {
			add_option( 'slms_installed_at', AcademicClock::mysql() );
		}
	}

	public static function get_db_version() {
		return (string) get_option( 'slms_db_version', '0' );
	}

	public static function get_installed_migrations() {
		global $wpdb;

		return $wpdb->get_col( 'SELECT migration FROM ' . self::table( 'migrations' ) . ' ORDER BY id ASC' );
	}

	public static function table( $suffix ) {
		global $wpdb;

		return $wpdb->prefix . 'slms_' . $suffix;
	}

	private static function has_migration( $migration ) {
		global $wpdb;

		$table = self::table( 'migrations' );

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE migration = %s LIMIT 1",
				$migration
			)
		);
	}

	private static function record_migration( $migration ) {
		global $wpdb;

		$wpdb->insert(
			self::table( 'migrations' ),
			array(
				'migration' => $migration,
				'applied_at' => AcademicClock::mysql(),
			),
			array( '%s', '%s' )
		);
	}

	private static function column_exists( $table, $column ) {
		global $wpdb;

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SHOW COLUMNS FROM {$table} LIKE %s",
				$column
			)
		);
	}

	private static function index_exists( $table, $index ) {
		global $wpdb;

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SHOW INDEX FROM {$table} WHERE Key_name = %s",
				$index
			)
		);
	}

	private static function table_exists( $table ) {
		global $wpdb;

		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}
}
