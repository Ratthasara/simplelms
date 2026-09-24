<?php

namespace SimpleLMS\Infrastructure\Login;

use SimpleLMS\Domain\Users\ProfileService;
use WP_Error;
use WP_User;

defined( 'ABSPATH' ) || exit;

class LoginAccessGuard {
	/**
	 * @var ProfileService
	 */
	private $profiles;

	public function __construct( ProfileService $profiles ) {
		$this->profiles = $profiles;
	}

	public function register_hooks() {
		add_filter( 'wp_authenticate_user', array( $this, 'block_login_until_password_is_set' ), 10, 2 );
		add_action( 'after_password_reset', array( $this, 'handle_after_password_reset' ), 10, 2 );
	}

	public function block_login_until_password_is_set( $user, $password ) {
		if ( ! $user instanceof WP_User ) {
			return $user;
		}

		if ( ! get_user_meta( $user->ID, 'slms_force_password_reset', true ) ) {
			return $user;
		}

		return new WP_Error(
			'slms_password_setup_required',
			sprintf(
				/* translators: %s: password reset URL */
				__( 'Your account requires password setup. Use the link sent to your email address, or <a href="%s">request a new password setup link</a>.', 'simple-lms' ),
				esc_url( wp_lostpassword_url() )
			)
		);
	}

	public function handle_after_password_reset( $user, $new_pass ) {
		if ( ! $user instanceof WP_User ) {
			return;
		}

		delete_user_meta( $user->ID, 'slms_force_password_reset' );
		delete_user_option( $user->ID, 'default_password_nag', true );
		$this->profiles->mark_password_setup_complete( $user->ID );
	}
}
