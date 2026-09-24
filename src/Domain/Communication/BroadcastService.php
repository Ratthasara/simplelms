<?php

namespace SimpleLMS\Domain\Communication;

use SimpleLMS\Core\AcademicClock;
use SimpleLMS\Domain\Users\RoleManager;
use SimpleLMS\Infrastructure\Database\Schema;
use SimpleLMS\Infrastructure\Logging\AuditLogger;
use WP_Error;

\defined( 'ABSPATH' ) || exit;

class BroadcastService {
	const TYPE_NORMAL    = 'normal';
	const TYPE_IMPORTANT = 'important';
	const TYPE_CRITICAL  = 'critical';

	/**
	 * @var AuditLogger
	 */
	private $audit_logger;

	/**
	 * @var AudienceResolver
	 */
	private $audiences;

	public function __construct( AuditLogger $audit_logger, AudienceResolver $audiences ) {
		$this->audit_logger = $audit_logger;
		$this->audiences    = $audiences;
	}

	public function can_manage_broadcasts( $user_id = 0 ) {
		return 'administrator' === RoleManager::get_primary_role( $user_id ?: get_current_user_id() );
	}

	public function get_summary_for_user( $user_id ) {
		global $wpdb;

		$user_id = absint( $user_id );
		$table   = Schema::table( 'broadcast_recipients' );
		$messages = Schema::table( 'broadcast_messages' );
		$now     = AcademicClock::mysql();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) AS total,
					SUM(CASE WHEN r.seen_at IS NULL THEN 1 ELSE 0 END) AS unread,
					SUM(CASE WHEN m.requires_acknowledgement = 1 AND r.acknowledged_at IS NULL THEN 1 ELSE 0 END) AS action_required
				FROM {$table} r
				INNER JOIN {$messages} m ON m.id = r.broadcast_id
				WHERE r.user_id = %d
				AND m.status = 'published'
				AND (m.published_at IS NULL OR m.published_at <= %s)
				AND (m.expires_at IS NULL OR m.expires_at = '0000-00-00 00:00:00' OR m.expires_at >= %s)",
				$user_id,
				$now,
				$now
			),
			ARRAY_A
		);

		return array(
			'total'           => isset( $row['total'] ) ? (int) $row['total'] : 0,
			'unread'          => isset( $row['unread'] ) ? (int) $row['unread'] : 0,
			'action_required' => isset( $row['action_required'] ) ? (int) $row['action_required'] : 0,
		);
	}

	public function get_dashboard_items_for_user( $user_id, $limit = 3 ) {
		return $this->get_items_for_user( $user_id, $limit );
	}

	public function get_items_for_user( $user_id, $limit = 20 ) {
		global $wpdb;

		$user_id = absint( $user_id );
		$limit   = max( 1, min( 50, absint( $limit ) ) );
		$messages = Schema::table( 'broadcast_messages' );
		$recipients = Schema::table( 'broadcast_recipients' );
		$now = AcademicClock::mysql();

		$sql = $wpdb->prepare(
			"SELECT m.*, r.seen_at, r.popup_seen_at, r.acknowledged_at
			FROM {$recipients} r
			INNER JOIN {$messages} m ON m.id = r.broadcast_id
			WHERE r.user_id = %d
			AND m.status = 'published'
			AND (m.published_at IS NULL OR m.published_at <= %s)
			AND (m.expires_at IS NULL OR m.expires_at = '0000-00-00 00:00:00' OR m.expires_at >= %s)
			ORDER BY
				CASE WHEN m.requires_acknowledgement = 1 AND r.acknowledged_at IS NULL THEN 0 ELSE 1 END ASC,
				CASE m.message_type WHEN 'critical' THEN 0 WHEN 'important' THEN 1 ELSE 2 END ASC,
				m.published_at DESC,
				m.id DESC
			LIMIT %d",
			$user_id,
			$now,
			$now,
			$limit
		);

		$items = $wpdb->get_results( $sql, ARRAY_A );
		return is_array( $items ) ? array_map( array( $this, 'prepare_message_row' ), $items ) : array();
	}

	public function get_pending_popup_for_user( $user_id ) {
		global $wpdb;

		$user_id = absint( $user_id );
		$messages = Schema::table( 'broadcast_messages' );
		$recipients = Schema::table( 'broadcast_recipients' );
		$now = AcademicClock::mysql();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT m.*, r.seen_at, r.popup_seen_at, r.acknowledged_at
				FROM {$recipients} r
				INNER JOIN {$messages} m ON m.id = r.broadcast_id
				WHERE r.user_id = %d
				AND m.status = 'published'
				AND m.show_popup = 1
				AND (m.published_at IS NULL OR m.published_at <= %s)
				AND (m.expires_at IS NULL OR m.expires_at = '0000-00-00 00:00:00' OR m.expires_at >= %s)
				AND (
					(m.requires_acknowledgement = 1 AND r.acknowledged_at IS NULL)
					OR
					(m.requires_acknowledgement = 0 AND r.popup_seen_at IS NULL)
				)
				ORDER BY
					CASE WHEN m.requires_acknowledgement = 1 THEN 0 ELSE 1 END ASC,
					CASE m.message_type WHEN 'critical' THEN 0 WHEN 'important' THEN 1 ELSE 2 END ASC,
					m.published_at DESC,
					m.id DESC
				LIMIT 1",
				$user_id,
				$now,
				$now
			),
			ARRAY_A
		);

		return $row ? $this->prepare_message_row( $row ) : null;
	}

	public function get_admin_summary() {
		global $wpdb;

		$messages   = Schema::table( 'broadcast_messages' );
		$recipients = Schema::table( 'broadcast_recipients' );

		$row = $wpdb->get_row(
			"SELECT
				(SELECT COUNT(*) FROM {$messages} WHERE status = 'published') AS active_messages,
				(SELECT COUNT(*) FROM {$recipients} r INNER JOIN {$messages} m ON m.id = r.broadcast_id WHERE m.status = 'published' AND m.requires_acknowledgement = 1 AND r.acknowledged_at IS NULL) AS pending_acknowledgements,
				(SELECT COUNT(*) FROM {$recipients} r INNER JOIN {$messages} m ON m.id = r.broadcast_id WHERE m.status = 'published' AND r.seen_at IS NULL) AS unread_deliveries",
			ARRAY_A
		);

		return array(
			'active_messages'          => isset( $row['active_messages'] ) ? (int) $row['active_messages'] : 0,
			'pending_acknowledgements' => isset( $row['pending_acknowledgements'] ) ? (int) $row['pending_acknowledgements'] : 0,
			'unread_deliveries'        => isset( $row['unread_deliveries'] ) ? (int) $row['unread_deliveries'] : 0,
		);
	}

	public function get_admin_messages( $limit = 30 ) {
		global $wpdb;

		$limit = max( 1, min( 100, absint( $limit ) ) );
		$messages   = Schema::table( 'broadcast_messages' );
		$recipients = Schema::table( 'broadcast_recipients' );

		$sql = $wpdb->prepare(
			"SELECT m.*,
				COUNT(r.id) AS recipient_count,
				SUM(CASE WHEN r.seen_at IS NOT NULL THEN 1 ELSE 0 END) AS seen_count,
				SUM(CASE WHEN r.acknowledged_at IS NOT NULL THEN 1 ELSE 0 END) AS acknowledged_count,
				SUM(CASE WHEN m.requires_acknowledgement = 1 AND r.acknowledged_at IS NULL THEN 1 ELSE 0 END) AS pending_acknowledgement_count
			FROM {$messages} m
			LEFT JOIN {$recipients} r ON r.broadcast_id = m.id
			GROUP BY m.id
			ORDER BY m.created_at DESC, m.id DESC
			LIMIT %d",
			$limit
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A );
		return is_array( $rows ) ? array_map( array( $this, 'prepare_message_row' ), $rows ) : array();
	}

	public function create_broadcast( array $data, $actor_user_id ) {
		global $wpdb;

		$actor_user_id = absint( $actor_user_id );
		if ( ! $this->can_manage_broadcasts( $actor_user_id ) ) {
			return new WP_Error( 'slms_broadcast_forbidden', __( 'Only administrators can create broadcasts.', 'simple-lms' ) );
		}

		$title = sanitize_text_field( $data['title'] ?? '' );
		$message = wp_kses_post( $data['message'] ?? '' );
		$type = sanitize_key( $data['message_type'] ?? self::TYPE_NORMAL );
		if ( ! in_array( $type, array( self::TYPE_NORMAL, self::TYPE_IMPORTANT, self::TYPE_CRITICAL ), true ) ) {
			$type = self::TYPE_NORMAL;
		}

		$embed_url = $this->sanitize_embed_url( $data['embed_url'] ?? '' );
		if ( is_wp_error( $embed_url ) ) {
			return $embed_url;
		}

		if ( '' === $title || ( '' === trim( wp_strip_all_tags( $message ) ) && '' === $embed_url ) ) {
			return new WP_Error( 'slms_broadcast_missing_content', __( 'Please enter a title and either a message or embedded media link.', 'simple-lms' ) );
		}

		$targets = $this->audiences->sanitize_targets( $data );
		if ( empty( $targets ) ) {
			return new WP_Error( 'slms_broadcast_missing_target', __( 'Please choose at least one recipient group.', 'simple-lms' ) );
		}

		$recipient_ids = $this->audiences->resolve_user_ids( $targets );
		if ( empty( $recipient_ids ) ) {
			return new WP_Error( 'slms_broadcast_no_recipients', __( 'No active recipients matched the selected target groups.', 'simple-lms' ) );
		}

		$now = AcademicClock::mysql();
		$show_popup = ! empty( $data['show_popup'] ) || ! empty( $data['requires_acknowledgement'] );
		$requires_ack = ! empty( $data['requires_acknowledgement'] );

		$inserted = $wpdb->insert(
			Schema::table( 'broadcast_messages' ),
			array(
				'title'                    => $title,
				'message'                  => $message,
				'media_attachment_ids'     => null,
				'embed_url'                => $embed_url,
				'message_type'             => $type,
				'status'                   => 'published',
				'show_popup'               => $show_popup ? 1 : 0,
				'requires_acknowledgement' => $requires_ack ? 1 : 0,
				'created_by'               => $actor_user_id,
				'created_at'               => $now,
				'updated_at'               => $now,
				'published_at'             => $now,
				'expires_at'               => null,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return new WP_Error( 'slms_broadcast_create_failed', __( 'The broadcast could not be created.', 'simple-lms' ) );
		}

		$broadcast_id = (int) $wpdb->insert_id;
		foreach ( $targets as $target ) {
			$target_inserted = $wpdb->insert(
				Schema::table( 'broadcast_targets' ),
				array(
					'broadcast_id' => $broadcast_id,
					'target_type'  => $target['type'],
					'target_value' => $target['value'],
				),
				array( '%d', '%s', '%s' )
			);

			if ( false === $target_inserted ) {
				return new WP_Error( 'slms_broadcast_target_failed', __( 'The broadcast target list could not be saved.', 'simple-lms' ) );
			}
		}

		foreach ( $recipient_ids as $recipient_id ) {
			$recipient_user = get_userdata( $recipient_id );
			$role_at_send = RoleManager::get_primary_role( $recipient_id );
			$wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO " . Schema::table( 'broadcast_recipients' ) . "
					(broadcast_id, user_id, role_at_send, email, created_at)
					VALUES (%d, %d, %s, %s, %s)",
					$broadcast_id,
					$recipient_id,
					$role_at_send,
					$recipient_user ? $recipient_user->user_email : '',
					$now
				)
			);
		}
		$this->audit_logger->log(
			'broadcast_created',
			array(
				'actor_user_id' => $actor_user_id,
				'object_type'   => 'broadcast',
				'object_id'     => $broadcast_id,
				'message'       => sprintf( 'Broadcast "%s" was published.', $title ),
				'context'       => array(
					'type'            => $type,
					'targets'         => $targets,
					'recipients'      => count( $recipient_ids ),
					'has_embed'       => '' !== $embed_url,
				),
			)
		);

		return array(
			'id'              => $broadcast_id,
			'recipient_count' => count( $recipient_ids ),
		);
	}

	public function archive_broadcast( $broadcast_id, $actor_user_id ) {
		global $wpdb;

		$broadcast_id = absint( $broadcast_id );
		$actor_user_id = absint( $actor_user_id );
		if ( ! $this->can_manage_broadcasts( $actor_user_id ) ) {
			return new WP_Error( 'slms_broadcast_forbidden', __( 'Only administrators can archive broadcasts.', 'simple-lms' ) );
		}

		$updated = $wpdb->update(
			Schema::table( 'broadcast_messages' ),
			array(
				'status' => 'archived',
				'updated_at' => AcademicClock::mysql(),
			),
			array( 'id' => $broadcast_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error( 'slms_broadcast_archive_failed', __( 'The broadcast could not be archived.', 'simple-lms' ) );
		}

		$this->audit_logger->log(
			'broadcast_archived',
			array(
				'actor_user_id' => $actor_user_id,
				'object_type'   => 'broadcast',
				'object_id'     => $broadcast_id,
				'message'       => 'Broadcast was archived.',
			)
		);

		return true;
	}


	public function delete_broadcast( $broadcast_id, $actor_user_id ) {
		global $wpdb;

		$broadcast_id  = absint( $broadcast_id );
		$actor_user_id = absint( $actor_user_id );

		if ( ! $this->can_manage_broadcasts( $actor_user_id ) ) {
			return new WP_Error( 'slms_broadcast_forbidden', __( 'Only administrators can delete broadcasts.', 'simple-lms' ) );
		}

		if ( ! $broadcast_id ) {
			return new WP_Error( 'slms_broadcast_missing_id', __( 'Please choose a broadcast to delete.', 'simple-lms' ) );
		}

		$messages = Schema::table( 'broadcast_messages' );
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, title, status FROM {$messages} WHERE id = %d",
				$broadcast_id
			),
			ARRAY_A
		);

		if ( ! $existing ) {
			return new WP_Error( 'slms_broadcast_not_found', __( 'The broadcast could not be found.', 'simple-lms' ) );
		}

		$target_deleted = $wpdb->delete(
			Schema::table( 'broadcast_targets' ),
			array( 'broadcast_id' => $broadcast_id ),
			array( '%d' )
		);

		if ( false === $target_deleted ) {
			return new WP_Error( 'slms_broadcast_delete_targets_failed', __( 'The broadcast target records could not be deleted.', 'simple-lms' ) );
		}

		$recipient_deleted = $wpdb->delete(
			Schema::table( 'broadcast_recipients' ),
			array( 'broadcast_id' => $broadcast_id ),
			array( '%d' )
		);

		if ( false === $recipient_deleted ) {
			return new WP_Error( 'slms_broadcast_delete_recipients_failed', __( 'The broadcast recipient records could not be deleted.', 'simple-lms' ) );
		}

		$notification_deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM " . Schema::table( 'notifications' ) . " WHERE status = %s AND context_json LIKE %s AND context_json LIKE %s",
				'pending',
				'%"type":"broadcast"%',
				'%"broadcast_id":' . $broadcast_id . '%'
			)
		);

		$deleted = $wpdb->delete(
			$messages,
			array( 'id' => $broadcast_id ),
			array( '%d' )
		);

		if ( false === $deleted || 0 === $deleted ) {
			return new WP_Error( 'slms_broadcast_delete_failed', __( 'The broadcast could not be deleted.', 'simple-lms' ) );
		}

		$this->audit_logger->log(
			'broadcast_deleted',
			array(
				'actor_user_id' => $actor_user_id,
				'object_type'   => 'broadcast',
				'object_id'     => $broadcast_id,
				'message'       => 'Broadcast was permanently deleted.',
				'context'       => array(
					'title'                         => sanitize_text_field( $existing['title'] ?? '' ),
					'previous_status'               => sanitize_key( $existing['status'] ?? '' ),
					'target_records_deleted'        => (int) $target_deleted,
					'recipient_records_deleted'     => (int) $recipient_deleted,
					'pending_notifications_removed' => false === $notification_deleted ? 0 : (int) $notification_deleted,
				),
			)
		);

		return true;
	}

	public function mark_seen( $broadcast_id, $user_id, $popup = false ) {
		global $wpdb;

		$broadcast_id = absint( $broadcast_id );
		$user_id = absint( $user_id );
		$now = AcademicClock::mysql();
		$table = Schema::table( 'broadcast_recipients' );

		$fields = array( 'seen_at' => $now );
		$formats = array( '%s' );
		if ( $popup ) {
			$fields['popup_seen_at'] = $now;
			$formats[] = '%s';
		}

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET seen_at = COALESCE(seen_at, %s)" . ( $popup ? ', popup_seen_at = COALESCE(popup_seen_at, %s)' : '' ) . "
				WHERE broadcast_id = %d AND user_id = %d",
				$popup ? array( $now, $now, $broadcast_id, $user_id ) : array( $now, $broadcast_id, $user_id )
			)
		);

		return true;
	}

	public function acknowledge( $broadcast_id, $user_id ) {
		global $wpdb;

		$broadcast_id = absint( $broadcast_id );
		$user_id = absint( $user_id );
		$now = AcademicClock::mysql();
		$table = Schema::table( 'broadcast_recipients' );

		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET seen_at = COALESCE(seen_at, %s), popup_seen_at = COALESCE(popup_seen_at, %s), acknowledged_at = COALESCE(acknowledged_at, %s)
				WHERE broadcast_id = %d AND user_id = %d",
				$now,
				$now,
				$now,
				$broadcast_id,
				$user_id
			)
		);

		return false !== $updated;
	}

	private function prepare_message_row( array $row ) {
		$row['id'] = absint( $row['id'] ?? 0 );
		$row['created_by'] = absint( $row['created_by'] ?? 0 );
		$row['title'] = sanitize_text_field( (string) ( $row['title'] ?? '' ) );
		$row['message'] = wp_kses_post( (string) ( $row['message'] ?? '' ) );
		$row['message_type'] = sanitize_key( (string) ( $row['message_type'] ?? self::TYPE_NORMAL ) );
		$row['status'] = sanitize_key( (string) ( $row['status'] ?? 'published' ) );
		$row['show_popup'] = ! empty( $row['show_popup'] );
		$row['requires_acknowledgement'] = ! empty( $row['requires_acknowledgement'] );
		$row['media_attachment_ids'] = array();
		$embed_url = $this->sanitize_embed_url( $row['embed_url'] ?? '' );
		$row['embed_url'] = is_wp_error( $embed_url ) ? '' : $embed_url;
		$row['recipient_count'] = isset( $row['recipient_count'] ) ? (int) $row['recipient_count'] : 0;
		$row['seen_count'] = isset( $row['seen_count'] ) ? (int) $row['seen_count'] : 0;
		$row['acknowledged_count'] = isset( $row['acknowledged_count'] ) ? (int) $row['acknowledged_count'] : 0;
		$row['pending_acknowledgement_count'] = isset( $row['pending_acknowledgement_count'] ) ? (int) $row['pending_acknowledgement_count'] : 0;
		$row['is_unread'] = empty( $row['seen_at'] );
		$row['needs_acknowledgement'] = ! empty( $row['requires_acknowledgement'] ) && empty( $row['acknowledged_at'] );
		$row['preview'] = wp_trim_words( wp_strip_all_tags( $row['message'] ), 22 );
		if ( '' === $row['preview'] && '' !== $row['embed_url'] ) {
			$row['preview'] = __( 'Embedded media announcement', 'simple-lms' );
		}
		return $row;
	}

	private function sanitize_embed_url( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}

		$url = esc_url_raw( $value, array( 'http', 'https' ) );
		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			return new WP_Error( 'slms_broadcast_invalid_embed_url', __( 'Please enter a valid photo or video link.', 'simple-lms' ) );
		}

		return $url;
	}

	private function sanitize_media_attachment_ids( $value ) {
		if ( is_string( $value ) ) {
			$value = preg_split( '/[,\s]+/', $value );
		}

		$ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $value ) ) ) );
		$valid = array();

		foreach ( $ids as $attachment_id ) {
			if ( count( $valid ) >= 10 ) {
				break;
			}

			if ( 'attachment' !== get_post_type( $attachment_id ) ) {
				continue;
			}

			$mime = (string) get_post_mime_type( $attachment_id );
			if ( 0 !== strpos( $mime, 'image/' ) && 0 !== strpos( $mime, 'video/' ) ) {
				continue;
			}

			$valid[] = $attachment_id;
		}

		return $valid;
	}

	private function decode_media_attachment_ids( $value ) {
		if ( is_string( $value ) && '' !== trim( $value ) ) {
			$decoded = json_decode( $value, true );
			if ( is_array( $decoded ) ) {
				$value = $decoded;
			}
		}

		return $this->sanitize_media_attachment_ids( $value );
	}

}
