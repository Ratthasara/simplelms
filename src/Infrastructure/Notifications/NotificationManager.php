<?php

namespace SimpleLMS\Infrastructure\Notifications;

use SimpleLMS\Core\AcademicClock;
use SimpleLMS\Domain\Settings\SettingsManager;
use SimpleLMS\Infrastructure\Database\Schema;
use SimpleLMS\Infrastructure\Logging\AuditLogger;

defined( 'ABSPATH' ) || exit;

class NotificationManager {
	const CRON_HOOK = 'slms_process_notification_queue';

	/**
	 * @var SettingsManager
	 */
	private $settings;

	/**
	 * @var AuditLogger
	 */
	private $audit_logger;

	/**
	 * Limit custom sender and SMTP configuration to plugin mail flows.
	 *
	 * @var bool
	 */
	private $is_sending_plugin_email = false;

	/**
	 * @var bool
	 */
	private $force_smtp_for_current_email = false;

	/**
	 * @var bool
	 */
	private $capture_mail_failure = false;

	/**
	 * Per-email sender overrides for immediate mail flows.
	 *
	 * @var array<string,string>
	 */
	private $current_mail_overrides = array();

	/**
	 * @var string
	 */
	private $last_mail_error_message = '';

	public function __construct( SettingsManager $settings, AuditLogger $audit_logger ) {
		$this->settings     = $settings;
		$this->audit_logger = $audit_logger;
	}

	public function register_hooks() {
		add_filter( 'cron_schedules', array( $this, 'add_cron_schedule' ) );
		add_action( 'init', array( $this, 'ensure_schedule' ) );
		add_action( self::CRON_HOOK, array( $this, 'process_queue' ) );
		add_action( 'admin_post_slms_send_test_notification', array( $this, 'handle_test_notification_request' ) );
		add_action( 'admin_post_slms_test_smtp_connection', array( $this, 'handle_test_smtp_connection_request' ) );
		add_action( 'admin_post_slms_send_smtp_test_email', array( $this, 'handle_send_test_email_request' ) );
		add_action( 'wp_mail_failed', array( $this, 'capture_wp_mail_failure' ) );
		add_filter( 'wp_mail_from', array( $this, 'filter_mail_from' ) );
		add_filter( 'wp_mail_from_name', array( $this, 'filter_mail_from_name' ) );
		add_action( 'phpmailer_init', array( $this, 'configure_phpmailer' ) );
	}

	public function add_cron_schedule( $schedules ) {
		if ( ! isset( $schedules['slms_five_minutes'] ) ) {
			$schedules['slms_five_minutes'] = array(
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every 5 Minutes (Simple LMS)', 'simple-lms' ),
			);
		}

		return $schedules;
	}

	public function ensure_schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'slms_five_minutes', self::CRON_HOOK );
		}
	}

	public function queue( $recipient_user_id, $subject, $message, array $args = array() ) {
		global $wpdb;

		$recipient_user_id = absint( $recipient_user_id );
		$recipient_email   = sanitize_email( $args['recipient_email'] ?? '' );

		if ( $recipient_user_id ) {
			$user = get_userdata( $recipient_user_id );

			if ( $user && empty( $recipient_email ) ) {
				$recipient_email = $user->user_email;
			}
		}

		if ( empty( $recipient_email ) ) {
			$recipient_email = $this->settings->get( 'notification_email' );
		}

		$inserted = $wpdb->insert(
			Schema::table( 'notifications' ),
			array(
				'channel'           => sanitize_key( $args['channel'] ?? 'email' ),
				'recipient_user_id' => $recipient_user_id ?: null,
				'recipient_email'   => $recipient_email,
				'subject'           => sanitize_text_field( $subject ),
				'message'           => wp_kses_post( $message ),
				'context_json'      => ! empty( $args['context'] ) ? wp_json_encode( $args['context'] ) : null,
				'campaign_id'       => ! empty( $args['campaign_id'] ) ? absint( $args['campaign_id'] ) : null,
				'status'            => 'pending',
				'created_at'        => AcademicClock::mysql(),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return false;
		}

		$notification_id = (int) $wpdb->insert_id;

		if ( empty( $args['skip_audit'] ) ) {
			$this->audit_logger->log(
				'notification_queued',
				array(
					'object_type' => 'notification',
					'object_id'   => $notification_id,
					'message'     => 'A notification was queued.',
					'context'     => array(
						'recipient_user_id' => $recipient_user_id,
						'recipient_email'   => $recipient_email,
						'subject'           => $subject,
					),
				)
			);
		}

		if ( ! empty( $args['send_now'] ) ) {
			$this->dispatch_queued_notification( $notification_id );
		}

		return $notification_id;
	}

	public function process_queue() {
		if ( ! $this->should_process_notifications() ) {
			return;
		}

		global $wpdb;

		$limit = max( 1, absint( $this->settings->get( 'notification_batch_size', 20 ) ) );
		$table = Schema::table( 'notifications' );
		$stale_before = AcademicClock::now()->modify( '-15 minutes' )->format( 'Y-m-d H:i:s' );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, processing_started_at = NULL WHERE status = %s AND processing_started_at < %s",
				'pending',
				'processing',
				$stale_before
			)
		);
		$sql   = $wpdb->prepare(
			"SELECT id FROM {$table} WHERE status = %s ORDER BY created_at ASC, id ASC LIMIT %d",
			'pending',
			$limit
		);
		$ids   = $wpdb->get_col( $sql );

		foreach ( $ids as $notification_id ) {
			$this->dispatch_queued_notification( $notification_id );
		}
	}

	public function is_email_delivery_enabled() {
		return (bool) $this->settings->get( 'enable_email_notifications', 1 );
	}

	public function list_for_user( $user_id, array $args = array() ) {
		global $wpdb;

		$user     = get_userdata( $user_id );
		$page     = max( 1, absint( $args['page'] ?? 1 ) );
		$per_page = max( 1, min( 50, absint( $args['per_page'] ?? 10 ) ) );
		$offset   = ( $page - 1 ) * $per_page;
		$table    = Schema::table( 'notifications' );

		if ( ! $user ) {
			return array();
		}

		$sql = $wpdb->prepare(
			"SELECT * FROM {$table} WHERE recipient_user_id = %d OR recipient_email = %s ORDER BY created_at DESC LIMIT %d OFFSET %d",
			(int) $user_id,
			$user->user_email,
			$per_page,
			$offset
		);

		return $wpdb->get_results( $sql, ARRAY_A );
	}

	public function count_pending() {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . Schema::table( 'notifications' ) . ' WHERE status = %s',
				'pending'
			)
		);
	}

	public function handle_test_notification_request() {
		if ( ! current_user_can( 'slms_manage_settings' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'simple-lms' ), 403 );
		}

		check_admin_referer( 'slms_send_test_notification' );

		$this->queue(
			get_current_user_id(),
			'Simple LMS test notification',
			'Your Simple LMS Phase 1 notification pipeline is working.',
			array(
				'context' => array(
					'type' => 'test_notification',
				),
			)
		);

		$this->redirect_with_admin_notice( 'test_notification_queued', 'success', wp_get_referer() ?: admin_url( 'admin.php?page=slms-dashboard' ) );
	}

	public function handle_test_smtp_connection_request() {
		if ( ! current_user_can( 'slms_manage_settings' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'simple-lms' ), 403 );
		}

		check_admin_referer( 'slms_test_smtp_connection' );

		$result = $this->test_smtp_connection();
		$this->redirect_with_admin_notice(
			is_wp_error( $result ) ? $result->get_error_message() : __( 'SMTP connection succeeded.', 'simple-lms' ),
			is_wp_error( $result ) ? 'error' : 'success',
			wp_get_referer() ?: admin_url( 'admin.php?page=slms-settings' )
		);
	}

	public function handle_send_test_email_request() {
		if ( ! current_user_can( 'slms_manage_settings' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'simple-lms' ), 403 );
		}

		check_admin_referer( 'slms_send_smtp_test_email' );

		$recipient_email = sanitize_email( wp_unslash( $_POST['recipient_email'] ?? '' ) );
		$result          = $this->send_test_email( $recipient_email );

		$this->redirect_with_admin_notice(
			is_wp_error( $result ) ? $result->get_error_message() : sprintf( __( 'Test email sent to %s.', 'simple-lms' ), $recipient_email ),
			is_wp_error( $result ) ? 'error' : 'success',
			wp_get_referer() ?: admin_url( 'admin.php?page=slms-settings' ),
			false
		);
	}

	private function redirect_with_admin_notice( $notice, $type = 'success', $redirect = '', $use_key = false ) {
		$redirect = $redirect ?: admin_url( 'admin.php?page=slms-settings' );
		$args     = array(
			'slms_notice_type' => sanitize_key( $type ),
			'slms_notice'      => $use_key ? sanitize_key( (string) $notice ) : rawurlencode( (string) $notice ),
		);

		wp_safe_redirect( add_query_arg( $args, $redirect ) );
		exit;
	}

	public static function clear_scheduled_event() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );

		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	public function filter_mail_from( $from_email ) {
		if ( ! $this->is_sending_plugin_email ) {
			return $from_email;
		}

		if ( ! empty( $this->current_mail_overrides['from_email'] ) && is_email( $this->current_mail_overrides['from_email'] ) ) {
			return $this->current_mail_overrides['from_email'];
		}

		return $this->get_configured_from_email( $from_email );
	}

	public function filter_mail_from_name( $from_name ) {
		if ( ! $this->is_sending_plugin_email ) {
			return $from_name;
		}

		if ( '' !== (string) ( $this->current_mail_overrides['from_name'] ?? '' ) ) {
			return $this->current_mail_overrides['from_name'];
		}

		return $this->get_configured_from_name( $from_name );
	}

	public function configure_phpmailer( $phpmailer ) {
		if ( ! $this->is_sending_plugin_email || ( ! $this->force_smtp_for_current_email && ! (int) $this->settings->get( 'smtp_enabled', 0 ) ) ) {
			return;
		}

		$result = $this->apply_smtp_configuration( $phpmailer, $this->force_smtp_for_current_email );

		if ( is_wp_error( $result ) ) {
			return;
		}
	}

	public function capture_wp_mail_failure( $error ) {
		if ( $this->capture_mail_failure && is_wp_error( $error ) ) {
			$this->last_mail_error_message = $error->get_error_message();
		}
	}

	public function test_smtp_connection() {
		$this->load_phpmailer_dependencies();

		try {
			$mailer = new \PHPMailer\PHPMailer\PHPMailer( true );
			$result = $this->apply_smtp_configuration( $mailer, true );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$mailer->Timeout = 15;

			if ( ! $mailer->smtpConnect() ) {
				return new \WP_Error( 'slms_smtp_connection_failed', __( 'SMTP connection failed. Check the saved host, port, encryption, and credentials.', 'simple-lms' ) );
			}

			$mailer->smtpClose();

			$this->audit_logger->log(
				'smtp_connection_tested',
				array(
					'object_type' => 'settings',
					'message'     => 'SMTP connection test succeeded.',
				)
			);

			return true;
		} catch ( \Exception $exception ) {
			$this->audit_logger->log(
				'smtp_connection_failed',
				array(
					'level'       => 'error',
					'object_type' => 'settings',
					'message'     => 'SMTP connection test failed.',
					'context'     => array(
						'error' => $exception->getMessage(),
					),
				)
			);

			return new \WP_Error( 'slms_smtp_connection_failed', $exception->getMessage() );
		}
	}

	public function send_test_email( $recipient_email ) {
		$recipient_email = sanitize_email( $recipient_email );

		if ( ! $recipient_email || ! is_email( $recipient_email ) ) {
			return new \WP_Error( 'slms_invalid_test_email', __( 'Enter a valid test email address.', 'simple-lms' ) );
		}

		$config = $this->get_smtp_configuration( true );

		if ( is_wp_error( $config ) ) {
			return $config;
		}

		$charset                     = get_bloginfo( 'charset' ) ?: 'UTF-8';
		$this->is_sending_plugin_email    = true;
		$this->force_smtp_for_current_email = true;
		$this->capture_mail_failure       = true;
		$this->last_mail_error_message    = '';

		try {
			$sent = wp_mail(
				$recipient_email,
				__( 'Simple LMS SMTP Test Email', 'simple-lms' ),
				wpautop(
					sprintf(
						/* translators: 1: site name, 2: date and time */
						__( 'This is a test email from %1$s. The SMTP settings were used successfully on %2$s.', 'simple-lms' ),
						get_bloginfo( 'name' ),
						AcademicClock::date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) )
					)
				),
				array( 'Content-Type: text/html; charset=' . $charset )
			);
		} finally {
			$this->is_sending_plugin_email    = false;
			$this->force_smtp_for_current_email = false;
			$this->capture_mail_failure       = false;
		}

		if ( ! $sent ) {
			return new \WP_Error( 'slms_smtp_test_email_failed', $this->last_mail_error_message ?: __( 'The test email could not be sent.', 'simple-lms' ) );
		}

		$this->audit_logger->log(
			'smtp_test_email_sent',
			array(
				'object_type' => 'notification',
				'message'     => 'SMTP test email sent.',
				'context'     => array(
					'recipient_email' => $recipient_email,
				),
			)
		);

		return true;
	}

	public function send_immediate_email( $recipients, $subject, $message, array $args = array() ) {
		if ( ! (bool) $this->settings->get( 'enable_email_notifications', 1 ) ) {
			return new \WP_Error( 'slms_email_notifications_disabled', __( 'Email notifications are currently disabled in Simple LMS settings.', 'simple-lms' ) );
		}

		$recipient_list = array_values(
			array_unique(
				array_filter(
					array_map(
						'sanitize_email',
						is_array( $recipients ) ? $recipients : array( $recipients )
					),
					static function ( $email ) {
						return $email && is_email( $email );
					}
				)
			)
		);

		if ( empty( $recipient_list ) ) {
			return new \WP_Error( 'slms_missing_email_recipient', __( 'At least one valid recipient email address is required.', 'simple-lms' ) );
		}

		$charset = get_bloginfo( 'charset' ) ?: 'UTF-8';
		$headers = array( 'Content-Type: text/html; charset=' . $charset );

		$reply_to_email = sanitize_email( $args['reply_to_email'] ?? '' );
		$reply_to_name  = sanitize_text_field( $args['reply_to_name'] ?? '' );
		if ( $reply_to_email && is_email( $reply_to_email ) ) {
			$headers[] = sprintf(
				'Reply-To: %s <%s>',
				$reply_to_name ? $reply_to_name : $reply_to_email,
				$reply_to_email
			);
		}

		$attachments = array_values(
			array_filter(
				array_map(
					static function ( $path ) {
						$path = wp_normalize_path( (string) $path );

						return ( '' !== $path && file_exists( $path ) ) ? $path : '';
					},
					(array) ( $args['attachments'] ?? array() )
				)
			)
		);

		$this->is_sending_plugin_email      = true;
		$this->force_smtp_for_current_email = false;
		$this->capture_mail_failure         = true;
		$this->last_mail_error_message      = '';
		$this->current_mail_overrides       = array(
			'from_email' => sanitize_email( $args['from_email'] ?? '' ),
			'from_name'  => sanitize_text_field( $args['from_name'] ?? '' ),
		);

		try {
			$sent = wp_mail(
				$recipient_list,
				sanitize_text_field( $subject ),
				wp_kses_post( $message ),
				$headers,
				$attachments
			);
		} finally {
			$this->is_sending_plugin_email      = false;
			$this->force_smtp_for_current_email = false;
			$this->capture_mail_failure         = false;
			$this->current_mail_overrides       = array();
		}

		if ( ! $sent ) {
			return new \WP_Error( 'slms_immediate_email_failed', $this->last_mail_error_message ?: __( 'The email could not be sent.', 'simple-lms' ) );
		}

		return true;
	}

	private function should_process_notifications() {
		return (bool) $this->settings->get( 'enable_email_notifications', 1 ) || (bool) $this->settings->get( 'send_welcome_emails', 1 );
	}

	private function dispatch_queued_notification( $notification_id ) {
		global $wpdb;

		$notification_id = absint( $notification_id );

		if ( ! $notification_id ) {
			return false;
		}

		$claimed = $wpdb->update(
			Schema::table( 'notifications' ),
			array( 'status' => 'processing', 'processing_started_at' => AcademicClock::mysql() ),
			array( 'id' => $notification_id, 'status' => 'pending' ),
			array( '%s', '%s' ),
			array( '%d', '%s' )
		);

		if ( 1 !== $claimed ) {
			return false;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::table( 'notifications' ) . ' WHERE id = %d',
				$notification_id
			),
			ARRAY_A
		);

		if ( empty( $row ) || 'processing' !== (string) ( $row['status'] ?? '' ) ) {
			return false;
		}

		return $this->dispatch_notification_row( $row );
	}

	private function dispatch_notification_row( array $row ) {
		global $wpdb;

		if ( $this->is_cancelled_campaign_notification( $row ) ) {
			$wpdb->update(
				Schema::table( 'notifications' ),
				array( 'status' => 'cancelled', 'processing_started_at' => null ),
				array( 'id' => (int) $row['id'] ),
				array( '%s', '%s' ),
				array( '%d' )
			);
			return false;
		}

		if ( ! $this->can_dispatch_notification( $row ) ) {
			$wpdb->update(
				Schema::table( 'notifications' ),
				array( 'status' => 'pending', 'processing_started_at' => null ),
				array( 'id' => (int) $row['id'] ),
				array( '%s', '%s' ),
				array( '%d' )
			);
			return false;
		}

		$this->is_sending_plugin_email      = true;
		$this->force_smtp_for_current_email = false;
		$this->capture_mail_failure         = true;
		$this->last_mail_error_message      = '';
		$charset                            = get_bloginfo( 'charset' ) ?: 'UTF-8';

		try {
			try {
				$sent = wp_mail(
					$row['recipient_email'],
					$row['subject'],
					$row['message'],
					array( 'Content-Type: text/html; charset=' . $charset )
				);
			} catch ( \Throwable $exception ) {
				$sent                          = false;
				$this->last_mail_error_message = $exception->getMessage();
			}
		} finally {
			$this->is_sending_plugin_email      = false;
			$this->force_smtp_for_current_email = false;
			$this->capture_mail_failure         = false;
		}

		if ( $sent ) {
			$wpdb->update(
				Schema::table( 'notifications' ),
				array(
					'status'        => 'sent',
					'sent_at'       => AcademicClock::mysql(),
					'error_message' => null,
					'processing_started_at' => null,
				),
				array( 'id' => (int) $row['id'] ),
				array( '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);

			$this->audit_logger->log(
				'notification_sent',
				array(
					'object_type' => 'notification',
					'object_id'   => (int) $row['id'],
					'message'     => 'A queued notification was sent.',
				)
			);

			return true;
		}

		$wpdb->update(
			Schema::table( 'notifications' ),
			array(
				'status'        => 'failed',
				'error_message' => $this->last_mail_error_message ?: 'wp_mail() returned false.',
				'processing_started_at' => null,
			),
			array( 'id' => (int) $row['id'] ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		$this->audit_logger->log(
			'notification_failed',
			array(
				'level'       => 'error',
				'object_type' => 'notification',
				'object_id'   => (int) $row['id'],
				'message'     => 'A queued notification failed to send.',
				'context'     => array(
					'error_message' => $this->last_mail_error_message ?: 'wp_mail() returned false.',
				),
			)
		);

		return false;
	}

	private function can_dispatch_notification( array $row ) {
		if ( 'email' !== sanitize_key( (string) ( $row['channel'] ?? 'email' ) ) ) {
			return false;
		}

		$context = $this->get_notification_context( $row );
		$type    = sanitize_key( (string) ( $context['type'] ?? '' ) );

		if ( 'welcome' === $type ) {
			return (bool) $this->settings->get( 'send_welcome_emails', 1 );
		}

		return (bool) $this->settings->get( 'enable_email_notifications', 1 );
	}

	private function is_cancelled_campaign_notification( array $row ) {
		global $wpdb;

		$campaign_id = absint( $row['campaign_id'] ?? 0 );
		if ( ! $campaign_id ) {
			return false;
		}

		$status = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT status FROM ' . Schema::table( 'email_campaigns' ) . ' WHERE id = %d',
				$campaign_id
			)
		);

		return 'cancelled' === $status;
	}

	private function get_notification_context( array $row ) {
		$context_json = (string) ( $row['context_json'] ?? '' );

		if ( '' === $context_json ) {
			return array();
		}

		$context = json_decode( $context_json, true );

		return is_array( $context ) ? $context : array();
	}

	private function get_configured_from_email( $fallback = '' ) {
		$configured = sanitize_email( $this->settings->get( 'smtp_from_email', '' ) );

		if ( $configured && is_email( $configured ) ) {
			return $configured;
		}

		$notification_email = sanitize_email( $this->settings->get( 'notification_email', '' ) );

		if ( $notification_email && is_email( $notification_email ) ) {
			return $notification_email;
		}

		return $fallback;
	}

	private function get_configured_from_name( $fallback = '' ) {
		$configured = sanitize_text_field( $this->settings->get( 'smtp_from_name', '' ) );

		if ( '' !== $configured ) {
			return $configured;
		}

		$institution = sanitize_text_field( $this->settings->get( 'institution_name', get_bloginfo( 'name' ) ) );

		return '' !== $institution ? $institution : $fallback;
	}

	private function get_smtp_configuration( $force_smtp = false ) {
		if ( ! $force_smtp && ! (int) $this->settings->get( 'smtp_enabled', 0 ) ) {
			return new \WP_Error( 'slms_smtp_disabled', __( 'SMTP is currently disabled in Simple LMS settings.', 'simple-lms' ) );
		}

		$host = trim( (string) $this->settings->get( 'smtp_host', '' ) );

		if ( '' === $host ) {
			return new \WP_Error( 'slms_smtp_missing_host', __( 'Enter and save an SMTP host before testing.', 'simple-lms' ) );
		}

		$encryption = sanitize_key( (string) $this->settings->get( 'smtp_encryption', 'tls' ) );
		if ( ! in_array( $encryption, array( 'tls', 'ssl' ), true ) ) {
			$encryption = '';
		}

		$port     = max( 1, absint( $this->settings->get( 'smtp_port', 587 ) ) );
		$smtp_auth = (bool) $this->settings->get( 'smtp_auth', 1 );
		$username = (string) $this->settings->get( 'smtp_username', '' );
		$password = (string) $this->settings->get( 'smtp_password', '' );

		if ( $smtp_auth && ( '' === trim( $username ) || '' === trim( $password ) ) ) {
			return new \WP_Error( 'slms_smtp_missing_credentials', __( 'SMTP authentication is enabled, so a username and password must both be saved before testing.', 'simple-lms' ) );
		}

		return array(
			'host'        => $host,
			'port'        => $port,
			'encryption'  => $encryption,
			'smtp_auth'   => $smtp_auth,
			'username'    => $username,
			'password'    => $password,
			'from_email'  => $this->get_configured_from_email( get_option( 'admin_email' ) ),
			'from_name'   => $this->get_configured_from_name( get_bloginfo( 'name' ) ),
		);
	}

	private function apply_smtp_configuration( $phpmailer, $force_smtp = false ) {
		$config = $this->get_smtp_configuration( $force_smtp );

		if ( is_wp_error( $config ) ) {
			return $config;
		}

		$phpmailer->isSMTP();
		$phpmailer->Host        = $config['host'];
		$phpmailer->Port        = $config['port'];
		$phpmailer->SMTPAuth    = $config['smtp_auth'];
		$phpmailer->SMTPAutoTLS = 'tls' === $config['encryption'];
		$phpmailer->SMTPSecure  = $config['encryption'];
		$phpmailer->CharSet     = get_bloginfo( 'charset' ) ?: 'UTF-8';
		$from_email = $config['from_email'];
		$from_name  = $config['from_name'];

		if ( ! empty( $this->current_mail_overrides['from_email'] ) && is_email( $this->current_mail_overrides['from_email'] ) ) {
			$from_email = $this->current_mail_overrides['from_email'];
		}

		if ( '' !== (string) ( $this->current_mail_overrides['from_name'] ?? '' ) ) {
			$from_name = $this->current_mail_overrides['from_name'];
		}

		$phpmailer->From        = $from_email;
		$phpmailer->FromName    = $from_name;
		$phpmailer->Sender      = $config['from_email'];

		if ( $config['smtp_auth'] ) {
			$phpmailer->Username = $config['username'];
			$phpmailer->Password = $config['password'];
		}

		return true;
	}

	private function load_phpmailer_dependencies() {
		if ( class_exists( '\PHPMailer\PHPMailer\PHPMailer' ) ) {
			return;
		}

		require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
		require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
		require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
	}
}
