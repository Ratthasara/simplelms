<?php

namespace SimpleLMS\Infrastructure\Login;

use SimpleLMS\Domain\Settings\SettingsManager;

defined( 'ABSPATH' ) || exit;

class LoginCustomizer {
	const LOGO_URL = 'https://ugp.edu.mm/wp-content/uploads/2026/03/ugp.edu_.mm_Logo-Large-scaled.png';

	/**
	 * @var SettingsManager
	 */
	private $settings;

	public function __construct( SettingsManager $settings ) {
		$this->settings = $settings;
	}

	public function register_hooks() {
		add_action( 'login_enqueue_scripts', array( $this, 'enqueue_assets' ), 99 );
		add_filter( 'login_headerurl', array( $this, 'filter_logo_url' ), 99 );
		add_filter( 'login_headertext', array( $this, 'filter_logo_title' ), 99 );
		add_filter( 'login_message', array( $this, 'filter_login_message' ), 20 );
	}

	public function enqueue_assets() {
		wp_enqueue_style(
			'slms-login',
			SLMS_URL . 'assets/login.css',
			array(),
			SLMS_VERSION . '.' . SLMS_DB_VERSION
		);

		$institution_name = (string) $this->settings->get( 'institution_name', get_bloginfo( 'name' ) );
		$inline_css       = ':root{--slms-login-logo:url("' . esc_url_raw( self::LOGO_URL ) . '");--slms-login-institution:"' . esc_js( $institution_name ) . '";}';

		wp_add_inline_style( 'slms-login', $inline_css );
	}

	public function filter_logo_url() {
		return home_url( '/' );
	}

	public function filter_logo_title() {
		return (string) $this->settings->get( 'institution_name', get_bloginfo( 'name' ) );
	}

	public function filter_login_message( $message ) {
		return $message;
	}

	private function is_primary_login_screen() {
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : 'login';

		return '' === $action || 'login' === $action;
	}
}
