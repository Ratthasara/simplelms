<?php

namespace SimpleLMS\Infrastructure\Logging;

use SimpleLMS\Core\AcademicClock;
use SimpleLMS\Infrastructure\Database\Schema;

defined( 'ABSPATH' ) || exit;

class AuditLogger {
	public function log( $event_type, array $args = array() ) {
		$settings = get_option( 'slms_settings', array() );

		if ( isset( $settings['enable_audit_log'] ) && ! (int) $settings['enable_audit_log'] ) {
			return false;
		}

		global $wpdb;

		$inserted = $wpdb->insert(
			Schema::table( 'audit_log' ),
			array(
				'event_type'    => sanitize_key( $event_type ),
				'level'         => sanitize_key( $args['level'] ?? 'info' ),
				'actor_user_id' => isset( $args['actor_user_id'] ) ? absint( $args['actor_user_id'] ) : get_current_user_id(),
				'object_type'   => isset( $args['object_type'] ) ? sanitize_text_field( $args['object_type'] ) : null,
				'object_id'     => isset( $args['object_id'] ) ? absint( $args['object_id'] ) : null,
				'message'       => isset( $args['message'] ) ? wp_kses_post( $args['message'] ) : '',
				'context_json'  => ! empty( $args['context'] ) ? wp_json_encode( $args['context'] ) : null,
				'ip_address'    => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : null,
				'created_at'    => AcademicClock::mysql(),
			),
			array( '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return false;
		}

		return (int) $wpdb->insert_id;
	}

	public function count_entries() {
		global $wpdb;

		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'audit_log' ) );
	}

	public function query( array $args = array() ) {
		global $wpdb;

		$page     = max( 1, absint( $args['page'] ?? 1 ) );
		$per_page = max( 1, min( 100, absint( $args['per_page'] ?? 20 ) ) );
		$offset   = ( $page - 1 ) * $per_page;
		$table    = Schema::table( 'audit_log' );

		$sql = $wpdb->prepare(
			"SELECT * FROM {$table} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d",
			$per_page,
			$offset
		);

		return $wpdb->get_results( $sql, ARRAY_A );
	}
}
