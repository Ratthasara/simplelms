<?php

namespace SimpleLMS\Infrastructure\Admin;

defined( 'ABSPATH' ) || exit;

class AdminAssets {
	public function register_hooks() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'admin_body_class', array( $this, 'filter_body_class' ) );
	}

	public function enqueue_assets() {
		if ( ! $this->is_slms_screen() ) {
			return;
		}

		wp_enqueue_style(
			'slms-admin',
			SLMS_URL . 'assets/admin.css',
			array(),
			SLMS_VERSION . '.' . SLMS_DB_VERSION
		);

		wp_enqueue_style(
			'slms-admin-mobile',
			SLMS_URL . 'assets/admin-mobile.css',
			array( 'slms-admin' ),
			SLMS_VERSION . '.' . SLMS_DB_VERSION
		);

		wp_enqueue_script(
			'slms-admin',
			SLMS_URL . 'assets/admin.js',
			array(),
			SLMS_VERSION . '.' . SLMS_DB_VERSION,
			true
		);
	}

	public function filter_body_class( $classes ) {
		if ( ! $this->is_slms_screen() ) {
			return $classes;
		}

		return trim( $classes . ' slms-admin-chrome' );
	}

	private function is_slms_screen() {
		$screen = get_current_screen();

		if ( ! $screen ) {
			return false;
		}

		if ( 0 === strpos( (string) $screen->id, 'toplevel_page_slms-' ) || false !== strpos( (string) $screen->id, '_page_slms-' ) ) {
			return true;
		}

		if ( 0 === strpos( (string) $screen->post_type, 'slms_' ) ) {
			return true;
		}

		if ( 'slms_program' === (string) $screen->taxonomy ) {
			return true;
		}

		return false;
	}
}
