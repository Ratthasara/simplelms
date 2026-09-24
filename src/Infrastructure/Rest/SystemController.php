<?php

namespace SimpleLMS\Infrastructure\Rest;

use SimpleLMS\Domain\Settings\SettingsManager;
use SimpleLMS\Infrastructure\Database\Schema;
use SimpleLMS\Infrastructure\Logging\AuditLogger;
use SimpleLMS\Infrastructure\Notifications\NotificationManager;

defined( 'ABSPATH' ) || exit;

class SystemController {
	/**
	 * @var SettingsManager
	 */
	private $settings;

	/**
	 * @var AuditLogger
	 */
	private $audit_logger;

	/**
	 * @var NotificationManager
	 */
	private $notifications;

	public function __construct( SettingsManager $settings, AuditLogger $audit_logger, NotificationManager $notifications ) {
		$this->settings      = $settings;
		$this->audit_logger  = $audit_logger;
		$this->notifications = $notifications;
	}

	public function register_hooks() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			'simple-lms/v1',
			'/system/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_status' ),
				'permission_callback' => array( $this, 'can_view_status' ),
			)
		);

		register_rest_route(
			'simple-lms/v1',
			'/settings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'can_manage_settings' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'can_manage_settings' ),
				),
			)
		);

		register_rest_route(
			'simple-lms/v1',
			'/audit-log',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_audit_log' ),
				'permission_callback' => array( $this, 'can_view_audit_log' ),
			)
		);

		register_rest_route(
			'simple-lms/v1',
			'/notifications/me',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_my_notifications' ),
				'permission_callback' => array( $this, 'is_logged_in' ),
			)
		);
	}

	public function get_status() {
		return rest_ensure_response(
			array(
				'plugin_version'        => SLMS_VERSION,
				'db_version'            => Schema::get_db_version(),
				'migrations'            => Schema::get_installed_migrations(),
				'installed_at'          => get_option( 'slms_installed_at' ),
				'pending_notifications' => $this->notifications->count_pending(),
				'audit_entry_count'     => $this->audit_logger->count_entries(),
				'next_notification_run' => wp_next_scheduled( NotificationManager::CRON_HOOK ),
			)
		);
	}

	public function get_settings() {
		return rest_ensure_response( $this->settings->all() );
	}

	public function update_settings( $request ) {
		$current  = $this->settings->all();
		$incoming = $request->get_json_params();

		if ( ! is_array( $incoming ) ) {
			$incoming = $request->get_params();
		}

		$merged = array_merge( $current, $incoming );
		$clean  = $this->settings->sanitize( $merged );

		update_option( SettingsManager::OPTION_KEY, $clean );

		return rest_ensure_response(
			array(
				'success'  => true,
				'settings' => $clean,
			)
		);
	}

	public function get_audit_log( $request ) {
		$page     = max( 1, absint( $request->get_param( 'page' ) ?: 1 ) );
		$per_page = max( 1, min( 100, absint( $request->get_param( 'per_page' ) ?: 20 ) ) );

		return rest_ensure_response(
			array(
				'items' => $this->audit_logger->query(
					array(
						'page'     => $page,
						'per_page' => $per_page,
					)
				),
			)
		);
	}

	public function get_my_notifications( $request ) {
		$page     = max( 1, absint( $request->get_param( 'page' ) ?: 1 ) );
		$per_page = max( 1, min( 50, absint( $request->get_param( 'per_page' ) ?: 10 ) ) );

		return rest_ensure_response(
			array(
				'items' => $this->notifications->list_for_user(
					get_current_user_id(),
					array(
						'page'     => $page,
						'per_page' => $per_page,
					)
				),
			)
		);
	}

	public function can_view_status() {
		return current_user_can( 'slms_manage_academics' ) || current_user_can( 'slms_manage_settings' );
	}

	public function can_manage_settings() {
		return current_user_can( 'slms_manage_settings' );
	}

	public function can_view_audit_log() {
		return current_user_can( 'slms_view_audit_log' ) || current_user_can( 'slms_manage_settings' );
	}

	public function is_logged_in() {
		return is_user_logged_in();
	}
}
