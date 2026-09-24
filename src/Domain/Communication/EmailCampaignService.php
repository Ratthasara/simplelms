<?php

namespace SimpleLMS\Domain\Communication;

use SimpleLMS\Core\AcademicClock;
use SimpleLMS\Domain\Users\RoleManager;
use SimpleLMS\Infrastructure\Database\Schema;
use SimpleLMS\Infrastructure\Logging\AuditLogger;
use SimpleLMS\Infrastructure\Notifications\NotificationManager;
use WP_Error;

defined( 'ABSPATH' ) || exit;

class EmailCampaignService {
	/** @var AudienceResolver */
	private $audiences;

	/** @var NotificationManager */
	private $notifications;

	/** @var AuditLogger */
	private $audit_logger;

	public function __construct( AudienceResolver $audiences, NotificationManager $notifications, AuditLogger $audit_logger ) {
		$this->audiences     = $audiences;
		$this->notifications = $notifications;
		$this->audit_logger  = $audit_logger;
	}

	public function can_manage( $user_id = 0 ) {
		return 'administrator' === RoleManager::get_primary_role( $user_id ?: get_current_user_id() );
	}

	public function is_delivery_enabled() {
		return $this->notifications->is_email_delivery_enabled();
	}

	public function get_audience_counts() {
		return $this->audiences->get_audience_counts();
	}

	public function create_campaign( array $data, $actor_user_id ) {
		global $wpdb;

		$actor_user_id = absint( $actor_user_id );
		if ( ! $this->can_manage( $actor_user_id ) ) {
			return new WP_Error( 'slms_email_campaign_forbidden', __( 'Only administrators can send email campaigns.', 'simple-lms' ) );
		}

		if ( ! $this->is_delivery_enabled() ) {
			return new WP_Error( 'slms_email_campaign_disabled', __( 'Email notifications are disabled in Simple LMS settings.', 'simple-lms' ) );
		}

		$subject = sanitize_text_field( $data['subject'] ?? '' );
		$message = wp_kses_post( $data['message'] ?? '' );
		if ( '' === $subject || '' === trim( wp_strip_all_tags( $message ) ) ) {
			return new WP_Error( 'slms_email_campaign_missing_content', __( 'Please enter an email subject and message.', 'simple-lms' ) );
		}

		$targets = $this->audiences->sanitize_targets( $data );
		if ( empty( $targets ) ) {
			return new WP_Error( 'slms_email_campaign_missing_target', __( 'Please choose at least one recipient group.', 'simple-lms' ) );
		}

		$user_ids = $this->audiences->resolve_user_ids( $targets );
		if ( empty( $user_ids ) ) {
			return new WP_Error( 'slms_email_campaign_no_recipients', __( 'No active users matched the selected recipient groups.', 'simple-lms' ) );
		}

		$recipients = array();
		$seen_emails = array();
		$skipped = 0;

		foreach ( $user_ids as $user_id ) {
			$user  = get_userdata( $user_id );
			$email = $user ? sanitize_email( $user->user_email ) : '';
			$key   = strtolower( $email );

			if ( ! $email || ! is_email( $email ) || isset( $seen_emails[ $key ] ) ) {
				$skipped++;
				continue;
			}

			$seen_emails[ $key ] = true;
			$recipients[] = array( 'user_id' => $user_id, 'email' => $email );
		}

		if ( empty( $recipients ) ) {
			return new WP_Error( 'slms_email_campaign_no_valid_emails', __( 'The selected users do not have any valid email addresses.', 'simple-lms' ) );
		}

		$now      = AcademicClock::mysql();
		$inserted = $wpdb->insert(
			Schema::table( 'email_campaigns' ),
			array(
				'subject'         => $subject,
				'message'         => wpautop( $message ),
				'targets_json'    => wp_json_encode( $targets ),
				'status'          => 'queued',
				'recipient_count' => count( $recipients ),
				'skipped_count'   => $skipped,
				'created_by'      => $actor_user_id,
				'created_at'      => $now,
				'updated_at'      => $now,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return new WP_Error( 'slms_email_campaign_create_failed', __( 'The email campaign could not be created.', 'simple-lms' ) );
		}

		$campaign_id = (int) $wpdb->insert_id;
		$queued      = 0;
		foreach ( $recipients as $recipient ) {
			$notification_id = $this->notifications->queue(
				$recipient['user_id'],
				$subject,
				wpautop( $message ),
				array(
					'recipient_email' => $recipient['email'],
					'campaign_id'     => $campaign_id,
					'skip_audit'      => true,
					'context'         => array(
						'type'        => 'email_campaign',
						'campaign_id' => $campaign_id,
					),
				)
			);

			if ( $notification_id ) {
				$queued++;
			} else {
				$skipped++;
			}
		}

		$wpdb->update(
			Schema::table( 'email_campaigns' ),
			array(
				'recipient_count' => $queued,
				'skipped_count'   => $skipped,
				'updated_at'      => AcademicClock::mysql(),
			),
			array( 'id' => $campaign_id ),
			array( '%d', '%d', '%s' ),
			array( '%d' )
		);

		if ( 0 === $queued ) {
			$wpdb->update( Schema::table( 'email_campaigns' ), array( 'status' => 'failed' ), array( 'id' => $campaign_id ), array( '%s' ), array( '%d' ) );
			return new WP_Error( 'slms_email_campaign_queue_failed', __( 'No campaign emails could be queued.', 'simple-lms' ) );
		}

		$this->audit_logger->log(
			'email_campaign_created',
			array(
				'actor_user_id' => $actor_user_id,
				'object_type'   => 'email_campaign',
				'object_id'     => $campaign_id,
				'message'       => sprintf( 'Email campaign "%s" was queued.', $subject ),
				'context'       => array( 'targets' => $targets, 'queued' => $queued, 'skipped' => $skipped ),
			)
		);

		return array( 'id' => $campaign_id, 'queued_count' => $queued, 'skipped_count' => $skipped );
	}

	public function send_test( $subject, $message, $actor_user_id ) {
		$actor_user_id = absint( $actor_user_id );
		if ( ! $this->can_manage( $actor_user_id ) ) {
			return new WP_Error( 'slms_email_campaign_forbidden', __( 'Only administrators can send test emails.', 'simple-lms' ) );
		}

		$subject = sanitize_text_field( $subject );
		$message = wp_kses_post( $message );
		$user    = get_userdata( $actor_user_id );
		if ( '' === $subject || '' === trim( wp_strip_all_tags( $message ) ) ) {
			return new WP_Error( 'slms_email_campaign_missing_content', __( 'Please enter an email subject and message.', 'simple-lms' ) );
		}

		if ( ! $user || ! is_email( $user->user_email ) ) {
			return new WP_Error( 'slms_email_campaign_test_address', __( 'Your administrator account does not have a valid email address.', 'simple-lms' ) );
		}

		return $this->notifications->send_immediate_email( $user->user_email, '[Test] ' . $subject, wpautop( $message ) );
	}

	public function get_summary() {
		global $wpdb;

		$campaigns     = Schema::table( 'email_campaigns' );
		$notifications = Schema::table( 'notifications' );

		return array(
			'campaigns' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$campaigns}" ),
			'pending'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$notifications} WHERE campaign_id IS NOT NULL AND status IN ('pending', 'processing')" ),
			'sent'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$notifications} WHERE campaign_id IS NOT NULL AND status = 'sent'" ),
			'failed'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$notifications} WHERE campaign_id IS NOT NULL AND status = 'failed'" ),
		);
	}

	public function get_campaigns( $limit = 30 ) {
		global $wpdb;

		$limit         = max( 1, min( 50, absint( $limit ) ) );
		$campaigns     = Schema::table( 'email_campaigns' );
		$notifications = Schema::table( 'notifications' );
		$sql           = $wpdb->prepare(
			"SELECT c.*,
				COALESCE(n.pending_count, 0) AS pending_count,
				COALESCE(n.sent_count, 0) AS sent_count,
				COALESCE(n.failed_count, 0) AS failed_count,
				COALESCE(n.cancelled_count, 0) AS cancelled_count
			FROM {$campaigns} c
			LEFT JOIN (
				SELECT campaign_id,
					SUM(CASE WHEN status IN ('pending', 'processing') THEN 1 ELSE 0 END) AS pending_count,
					SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS sent_count,
					SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_count,
					SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_count
				FROM {$notifications}
				WHERE campaign_id IS NOT NULL
				GROUP BY campaign_id
			) n ON n.campaign_id = c.id
			ORDER BY c.created_at DESC, c.id DESC
			LIMIT %d",
			$limit
		);

		return (array) $wpdb->get_results( $sql, ARRAY_A );
	}

	public function cancel_campaign( $campaign_id, $actor_user_id ) {
		global $wpdb;

		if ( ! $this->can_manage( $actor_user_id ) ) {
			return new WP_Error( 'slms_email_campaign_forbidden', __( 'Only administrators can cancel email campaigns.', 'simple-lms' ) );
		}

		$campaign_id = absint( $campaign_id );
		$campaign    = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'email_campaigns' ) . ' WHERE id = %d', $campaign_id ), ARRAY_A );
		if ( ! $campaign ) {
			return new WP_Error( 'slms_email_campaign_not_found', __( 'The email campaign could not be found.', 'simple-lms' ) );
		}

		$cancelled = $wpdb->update(
			Schema::table( 'notifications' ),
			array( 'status' => 'cancelled' ),
			array( 'campaign_id' => $campaign_id, 'status' => 'pending' ),
			array( '%s' ),
			array( '%d', '%s' )
		);
		if ( false === $cancelled ) {
			return new WP_Error( 'slms_email_campaign_cancel_failed', __( 'The pending campaign emails could not be cancelled.', 'simple-lms' ) );
		}

		$campaign_updated = $wpdb->update(
			Schema::table( 'email_campaigns' ),
			array( 'status' => 'cancelled', 'cancelled_at' => AcademicClock::mysql(), 'updated_at' => AcademicClock::mysql() ),
			array( 'id' => $campaign_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
		if ( false === $campaign_updated ) {
			return new WP_Error( 'slms_email_campaign_status_failed', __( 'The campaign status could not be updated.', 'simple-lms' ) );
		}

		$this->audit_logger->log( 'email_campaign_cancelled', array( 'actor_user_id' => absint( $actor_user_id ), 'object_type' => 'email_campaign', 'object_id' => $campaign_id, 'message' => 'Pending email campaign deliveries were cancelled.', 'context' => array( 'cancelled' => (int) $cancelled ) ) );

		return array( 'cancelled_count' => (int) $cancelled );
	}

	public function retry_failed( $campaign_id, $actor_user_id ) {
		global $wpdb;

		if ( ! $this->can_manage( $actor_user_id ) ) {
			return new WP_Error( 'slms_email_campaign_forbidden', __( 'Only administrators can retry email campaigns.', 'simple-lms' ) );
		}

		$campaign_id = absint( $campaign_id );
		$exists      = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'email_campaigns' ) . ' WHERE id = %d', $campaign_id ) );
		if ( ! $exists ) {
			return new WP_Error( 'slms_email_campaign_not_found', __( 'The email campaign could not be found.', 'simple-lms' ) );
		}

		$retried = $wpdb->update(
			Schema::table( 'notifications' ),
			array( 'status' => 'pending', 'error_message' => null, 'sent_at' => null, 'processing_started_at' => null ),
			array( 'campaign_id' => $campaign_id, 'status' => 'failed' ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d', '%s' )
		);
		if ( false === $retried ) {
			return new WP_Error( 'slms_email_campaign_retry_failed', __( 'The failed campaign emails could not be requeued.', 'simple-lms' ) );
		}

		$campaign_updated = $wpdb->update( Schema::table( 'email_campaigns' ), array( 'status' => 'queued', 'cancelled_at' => null, 'updated_at' => AcademicClock::mysql() ), array( 'id' => $campaign_id ), array( '%s', '%s', '%s' ), array( '%d' ) );
		if ( false === $campaign_updated ) {
			return new WP_Error( 'slms_email_campaign_status_failed', __( 'The campaign status could not be updated.', 'simple-lms' ) );
		}

		$this->audit_logger->log( 'email_campaign_retried', array( 'actor_user_id' => absint( $actor_user_id ), 'object_type' => 'email_campaign', 'object_id' => $campaign_id, 'message' => 'Failed email campaign deliveries were requeued.', 'context' => array( 'retried' => (int) $retried ) ) );

		return array( 'retried_count' => (int) $retried );
	}
}
