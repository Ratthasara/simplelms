<?php

namespace SimpleLMS\Infrastructure;

use SimpleLMS\Domain\Settings\SettingsManager;
use SimpleLMS\Domain\Assessment\AssignmentLatePolicy;
use SimpleLMS\Domain\Assessment\AssignmentPointPolicy;
use SimpleLMS\Domain\Academic\TermService;
use SimpleLMS\Domain\Users\RoleManager;
use SimpleLMS\Infrastructure\Database\Schema;
use SimpleLMS\Infrastructure\Logging\AuditLogger;
use SimpleLMS\Infrastructure\Notifications\NotificationManager;

defined( 'ABSPATH' ) || exit;

class Installer {
	public static function activate() {
		Schema::install();
		RoleManager::install_roles();
		self::install_default_settings();
		self::normalize_assignment_due_dates();
		self::normalize_assignment_max_points();
		update_option( 'slms_plugin_version', SLMS_VERSION );
		$terms = new TermService();
		$terms->schedule_auto_creation();

		$logger = new AuditLogger();
		$logger->log(
			'plugin_activated',
			array(
				'actor_user_id' => get_current_user_id(),
				'object_type'   => 'plugin',
				'message'       => 'Simple LMS foundation plugin was activated.',
			)
		);

		flush_rewrite_rules();
	}

	public static function deactivate() {
		NotificationManager::clear_scheduled_event();
		TermService::clear_scheduled_event();
		flush_rewrite_rules();
	}

	public static function maybe_upgrade() {
		if ( version_compare( Schema::get_db_version(), SLMS_DB_VERSION, '<' ) ) {
			Schema::install();
		}

		RoleManager::install_roles();
		self::install_default_settings();
		if ( get_option( 'slms_plugin_version' ) !== SLMS_VERSION ) {
			self::normalize_assignment_due_dates();
			self::normalize_assignment_max_points();
			update_option( 'slms_flush_rewrite_rules', 1, false );
			update_option( 'slms_plugin_version', SLMS_VERSION );
		}
	}

	private static function install_default_settings() {
		$existing = get_option( SettingsManager::OPTION_KEY, array() );

		if ( empty( $existing['student_code_prefix'] ) || in_array( strtoupper( (string) $existing['student_code_prefix'] ), array( 'STU', 'UGPS' ), true ) ) {
			$existing['student_code_prefix'] = 'S';
		}

		if ( empty( $existing['staff_code_prefix'] ) || in_array( strtoupper( (string) $existing['staff_code_prefix'] ), array( 'STA', 'STF', 'UGPF' ), true ) ) {
			$existing['staff_code_prefix'] = 'F';
		}

		$existing['academic_timezone'] = SettingsManager::ACADEMIC_TIMEZONE;
		$merged   = wp_parse_args( $existing, SettingsManager::defaults() );

		update_option( SettingsManager::OPTION_KEY, $merged );
	}

	private static function normalize_assignment_due_dates() {
		$assignment_ids = get_posts(
			array(
				'post_type'      => 'slms_assignment',
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'meta_key'       => '_slms_due_at',
			)
		);

		foreach ( $assignment_ids as $assignment_id ) {
			$due_at     = (string) get_post_meta( $assignment_id, '_slms_due_at', true );
			$normalized = AssignmentLatePolicy::normalize_datetime( $due_at );

			if ( '' !== $due_at && '' !== $normalized && $normalized !== $due_at ) {
				update_post_meta( $assignment_id, '_slms_due_at', $normalized );
			}
		}
	}
	private static function normalize_assignment_max_points() {
		$assignment_ids = get_posts(
			array(
				'post_type'      => 'slms_assignment',
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'meta_key'       => '_slms_points',
			)
		);

		foreach ( $assignment_ids as $assignment_id ) {
			$raw_points        = get_post_meta( $assignment_id, '_slms_points', true );
			$normalized_points = AssignmentPointPolicy::normalize_max_points( $raw_points );

			if ( abs( (float) $raw_points - (float) $normalized_points ) >= 0.001 ) {
				add_post_meta( $assignment_id, '_slms_points_original_before_340', (string) $raw_points, true );
				update_post_meta( $assignment_id, '_slms_points', $normalized_points );
			}
		}
	}

}
