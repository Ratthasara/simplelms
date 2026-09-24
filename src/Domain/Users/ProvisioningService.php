<?php

namespace SimpleLMS\Domain\Users;

use SimpleLMS\Core\AcademicClock;
use SimpleLMS\Domain\Settings\SettingsManager;
use SimpleLMS\Infrastructure\Logging\AuditLogger;
use SimpleLMS\Infrastructure\Notifications\NotificationManager;
use WP_Error;

defined( 'ABSPATH' ) || exit;

class ProvisioningService {
	const STAFF_ROLES = array( RoleManager::ROLE_STAFF, 'lecturer', 'officer', 'administrator' );
	const DEFAULT_INITIAL_PASSWORD = '';

	/**
	 * @var ProfileService
	 */
	private $profiles;

	/**
	 * @var SettingsManager
	 */
	private $settings;

	/**
	 * @var NotificationManager
	 */
	private $notifications;

	/**
	 * @var AuditLogger
	 */
	private $audit_logger;

	public function __construct( ProfileService $profiles, SettingsManager $settings, NotificationManager $notifications, AuditLogger $audit_logger ) {
		$this->profiles      = $profiles;
		$this->settings      = $settings;
		$this->notifications = $notifications;
		$this->audit_logger  = $audit_logger;
	}

	public function create_student( array $data ) {
		$data['role']        = 'student';
		$data['person_type'] = 'student';

		return $this->create_person( $data );
	}

	public function create_staff( array $data ) {
		$role = self::normalize_staff_role( $data['role'] ?? 'lecturer' );

		if ( ! in_array( $role, self::STAFF_ROLES, true ) ) {
			return new WP_Error( 'slms_invalid_staff_role', __( 'Invalid staff role.', 'simple-lms' ) );
		}

		if ( 'administrator' === $role && ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'slms_administrator_role_restricted', __( 'Only site administrators can create administrator accounts.', 'simple-lms' ) );
		}

		$data['role']        = $role;
		$data['person_type'] = 'staff';

		return $this->create_person( $data );
	}

	public static function normalize_staff_role( $role ) {
		$role = sanitize_key( $role );

		return 'staff' === $role ? RoleManager::ROLE_STAFF : $role;
	}

	public function batch_create_students( $raw_input, array $defaults = array() ) {
		return $this->batch_create_people( 'student', $raw_input, $defaults );
	}

	public function batch_create_staff( $raw_input, array $defaults = array() ) {
		return $this->batch_create_people( 'staff', $raw_input, $defaults );
	}

	private function create_person( array $data ) {
		$person_type = sanitize_key( $data['person_type'] ?? '' );
		$role        = sanitize_key( $data['role'] ?? '' );
		$email       = sanitize_email( $data['email'] ?? '' );
		$full_name   = sanitize_text_field( $data['full_name'] ?? '' );
		$person_code = strtoupper( sanitize_text_field( $data['person_code'] ?? '' ) );
		$force_password_change = array_key_exists( 'force_password_change', $data ) ? ! empty( $data['force_password_change'] ) : true;
		$has_initial_password = isset( $data['initial_password'] ) && '' !== (string) $data['initial_password'];
		$send_invite = $force_password_change || ! $has_initial_password || ( array_key_exists( 'send_welcome_email', $data ) ? ! empty( $data['send_welcome_email'] ) : (bool) $this->settings->get( 'send_welcome_emails', 1 ) );
		$user_pass   = $has_initial_password ? (string) $data['initial_password'] : wp_generate_password( 24, true, true );

		if ( empty( $full_name ) || empty( $email ) ) {
			return new WP_Error( 'slms_missing_person_fields', __( 'Full name and email are required.', 'simple-lms' ) );
		}

		if ( ! is_email( $email ) ) {
			return new WP_Error( 'slms_invalid_person_email', __( 'Invalid email address.', 'simple-lms' ) );
		}

		if ( email_exists( $email ) ) {
			return new WP_Error( 'slms_duplicate_person_email', __( 'That email address is already in use.', 'simple-lms' ) );
		}

		if ( 'student' === $person_type ) {
			$role = 'student';
		}

		if ( 'staff' === $person_type && ! in_array( $role, self::STAFF_ROLES, true ) ) {
			return new WP_Error( 'slms_invalid_staff_role', __( 'Invalid staff role.', 'simple-lms' ) );
		}

		$generated_person_code = $person_code ?: $this->generate_person_code( $person_type );

		if ( is_wp_error( $generated_person_code ) ) {
			return $generated_person_code;
		}

		if ( $this->profiles->get_profile_by_person_code( $generated_person_code ) ) {
			return new WP_Error( 'slms_duplicate_person_code', __( 'That person code is already in use.', 'simple-lms' ) );
		}

		$names      = $this->split_full_name( $full_name );
		$user_login = $this->get_username_from_person_code( $generated_person_code );

		if ( username_exists( $user_login ) ) {
			return new WP_Error( 'slms_duplicate_username', __( 'That account username is already in use.', 'simple-lms' ) );
		}

		$user_id = wp_insert_user(
			array(
				'user_login'   => $user_login,
				'user_pass'    => $user_pass,
				'user_email'   => $email,
				'display_name' => $full_name,
				'first_name'   => $names['first_name'],
				'last_name'    => $names['last_name'],
				'role'         => $role,
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$profile_result = $this->profiles->upsert_profile(
			$user_id,
			array(
				'person_type'    => $person_type,
				'person_code'    => $generated_person_code,
				'nrc_number'     => $data['nrc_number'] ?? '',
				'status'         => $send_invite ? 'invited' : 'active',
				'phone'          => $data['phone'] ?? '',
				'program_id'     => $data['program_id'] ?? 0,
				'intake_term_id' => $data['intake_term_id'] ?? 0,
				'department'     => $data['department'] ?? '',
				'position_title' => $data['position_title'] ?? '',
				'notes'          => $data['notes'] ?? '',
			)
		);

		if ( is_wp_error( $profile_result ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $user_id );
			return $profile_result;
		}

		if ( $force_password_change ) {
			update_user_meta( $user_id, 'slms_force_password_reset', 1 );
			update_user_option( $user_id, 'default_password_nag', 1, true );
		} else {
			delete_user_meta( $user_id, 'slms_force_password_reset' );
			delete_user_option( $user_id, 'default_password_nag', true );
		}

		if ( $send_invite ) {
			$notification_id = $this->queue_welcome_notification( $user_id, $full_name, $person_type );

			if ( ! $notification_id ) {
				require_once ABSPATH . 'wp-admin/includes/user.php';
				wp_delete_user( $user_id );
				return new WP_Error( 'slms_welcome_notification_failed', __( 'The account was not created because its password setup email could not be queued.', 'simple-lms' ) );
			}

			$this->profiles->mark_invite_sent( $user_id );
		}

		update_user_meta( $user_id, 'show_admin_bar_front', 'false' );

		$this->audit_logger->log(
			'person_provisioned',
			array(
				'object_type' => $person_type,
				'object_id'   => $user_id,
				'message'     => sprintf( '%s account created.', ucfirst( $person_type ) ),
				'context'     => array(
					'role'       => $role,
					'email'      => $email,
					'full_name'  => $full_name,
					'person_code'=> $generated_person_code,
				),
			)
		);

		return $user_id;
	}

	private function batch_create_people( $person_type, $raw_input, array $defaults = array() ) {
		$lines = preg_split( '/\r\n|\r|\n/', (string) $raw_input );
		$lines = array_values( array_filter( array_map( 'trim', $lines ) ) );
		$stats = array(
			'person_type' => $person_type,
			'created'     => 0,
			'failed'      => 0,
			'errors'      => array(),
		);

		foreach ( $lines as $index => $line ) {
			$row = str_getcsv( $line );
			$row = array_map( 'trim', $row );

			if ( 'student' === $person_type ) {
				$payload = $this->map_student_batch_row( $row, $defaults );
				$result  = $this->create_student( array_merge( $defaults, $payload ) );
			} else {
				$payload = $this->map_staff_batch_row( $row, $defaults );
				$result  = $this->create_staff( array_merge( $defaults, $payload ) );
			}

			if ( is_wp_error( $result ) ) {
				$stats['failed']++;
				$stats['errors'][] = sprintf( 'Line %d: %s', $index + 1, $result->get_error_message() );
				continue;
			}

			$stats['created']++;
		}

		return $stats;
	}

	private function map_student_batch_row( array $row, array $defaults = array() ) {
		$has_nrc_column = $this->student_batch_row_has_nrc_column( $row );

		return array(
			'full_name'      => $row[0] ?? '',
			'email'          => $row[1] ?? '',
			'person_code'    => $row[2] ?? '',
			'nrc_number'     => $has_nrc_column ? ( $row[3] ?? '' ) : '',
			'program_id'     => $has_nrc_column ? ( $row[4] ?? ( $defaults['program_id'] ?? 0 ) ) : ( $row[3] ?? ( $defaults['program_id'] ?? 0 ) ),
			'intake_term_id' => $has_nrc_column ? ( $row[5] ?? ( $defaults['intake_term_id'] ?? 0 ) ) : ( $row[4] ?? ( $defaults['intake_term_id'] ?? 0 ) ),
			'phone'          => $has_nrc_column ? ( $row[6] ?? '' ) : ( $row[5] ?? '' ),
		);
	}

	private function map_staff_batch_row( array $row, array $defaults = array() ) {
		$has_nrc_column = $this->staff_batch_row_has_nrc_column( $row );

		return array(
			'full_name'      => $row[0] ?? '',
			'email'          => $row[1] ?? '',
			'person_code'    => $row[2] ?? '',
			'nrc_number'     => $has_nrc_column ? ( $row[3] ?? '' ) : '',
			'role'           => $has_nrc_column ? ( $row[4] ?? ( $defaults['role'] ?? 'lecturer' ) ) : ( $row[3] ?? ( $defaults['role'] ?? 'lecturer' ) ),
			'department'     => $has_nrc_column ? ( $row[5] ?? '' ) : ( $row[4] ?? '' ),
			'position_title' => $has_nrc_column ? ( $row[6] ?? '' ) : ( $row[5] ?? '' ),
			'phone'          => $has_nrc_column ? ( $row[7] ?? '' ) : ( $row[6] ?? '' ),
		);
	}

	private function student_batch_row_has_nrc_column( array $row ) {
		if ( count( $row ) < 7 ) {
			return false;
		}

		$maybe_program_id = trim( (string) ( $row[3] ?? '' ) );
		$maybe_term_id    = trim( (string) ( $row[4] ?? '' ) );
		$trailing_value   = trim( (string) ( $row[6] ?? '' ) );

		if ( 7 === count( $row ) && '' === $trailing_value && ctype_digit( $maybe_program_id ) && ctype_digit( $maybe_term_id ) ) {
			return false;
		}

		return true;
	}

	private function staff_batch_row_has_nrc_column( array $row ) {
		if ( count( $row ) < 8 ) {
			return false;
		}

		$maybe_role = self::normalize_staff_role( $row[3] ?? '' );

		if ( in_array( $maybe_role, self::STAFF_ROLES, true ) ) {
			return false;
		}

		return true;
	}

	private function queue_welcome_notification( $user_id, $full_name, $person_type ) {
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return false;
		}

		$dashboard_link = apply_filters( 'slms_user_destination_url', admin_url( 'admin.php?page=slms-dashboard' ), $user_id, RoleManager::get_primary_role( $user_id ) );
		$reset_link     = $this->get_password_setup_link( $user );
		$recipient_name = $full_name ?: $user->display_name ?: $user->user_login;
		$message_parts  = array(
			sprintf( '<p>Dear %s,</p>', esc_html( $recipient_name ) ),
			'<p>A warm welcome to the University of Global Peace.</p>',
			'<p>We are pleased to let you know that your portal account has been created successfully. Please set your password before signing in.</p>',
			sprintf( '<p><strong>%s</strong> %s<br><strong>%s</strong> <a href="%s">%s</a></p>',
				esc_html__( 'Username:', 'simple-lms' ),
				esc_html( $user->user_login ),
				esc_html__( 'Password Setup:', 'simple-lms' ),
				esc_url( $reset_link ),
				esc_html( $reset_link )
			),
			sprintf( '<p><strong>%s</strong> <a href="%s">%s</a></p>', esc_html__( 'Login Page:', 'simple-lms' ), esc_url( $dashboard_link ), esc_html( $dashboard_link ) ),
			'<p>For security purposes, passwords are not sent by email.</p>',
			'<p>Should you have any questions or require any assistance, please feel free to reply to this email. Our team will be glad to assist you.</p>',
			'<p>Kind regards,<br><strong>Academic Support Team</strong><br><strong>University of Global Peace</strong><br>support@ugp.edu.mm</p>',
		);
		$message       = implode( '', $message_parts );

		return $this->notifications->queue(
			$user_id,
			__( 'Welcome to the University of Global Peace', 'simple-lms' ),
			$message,
			array(
				'send_now' => true,
				'context' => array(
					'type'        => 'welcome',
					'person_type' => $person_type,
				),
			)
		);
	}

	private function get_password_setup_link( \WP_User $user ) {
		$key = get_password_reset_key( $user );

		if ( is_wp_error( $key ) ) {
			return wp_lostpassword_url();
		}

		return network_site_url(
			'wp-login.php?action=rp&key=' . rawurlencode( $key ) . '&login=' . rawurlencode( $user->user_login ),
			'login'
		);
	}

	private function split_full_name( $full_name ) {
		$parts = preg_split( '/\s+/', trim( $full_name ) );

		if ( empty( $parts ) ) {
			return array(
				'first_name' => '',
				'last_name'  => '',
			);
		}

		$first_name = array_shift( $parts );

		return array(
			'first_name' => $first_name,
			'last_name'  => implode( ' ', $parts ),
		);
	}

	private function get_username_from_person_code( $person_code ) {
		$username = sanitize_user( (string) $person_code, true );

		if ( '' === $username ) {
			return 'slmsuser';
		}

		return $username;
	}

	private function generate_person_code( $person_type ) {
		$institution_code = $this->sanitize_code_token( $this->settings->get( 'institution_code', 'UGP' ), 'UGP' );
		$type_code        = $this->get_person_type_code( $person_type );
		$full_year        = $this->get_person_code_year();
		$year_token       = substr( (string) $full_year, -2 );
		$start            = random_int( 0, 999 );
		$steps            = array( 1, 3, 7, 9, 11, 13, 17, 19, 21, 23, 27, 29, 31, 33, 37, 39, 41, 43, 47, 49 );
		$step             = $steps[ random_int( 0, count( $steps ) - 1 ) ];

		/*
		 * Walk all 1,000 three-digit combinations in a randomized order.
		 * Every step is coprime with 1,000, so no suffix repeats before the
		 * complete 000-999 space has been checked.
		 */
		for ( $attempt = 0; $attempt < 1000; $attempt++ ) {
			$suffix = ( $start + ( $attempt * $step ) ) % 1000;
			$candidate = sprintf(
				'%s%s-%s%03d',
				$institution_code,
				$type_code,
				$year_token,
				$suffix
			);

			if ( $this->is_person_code_available( $candidate ) ) {
				return $candidate;
			}
		}

		return new WP_Error(
			'slms_person_code_limit_reached',
			sprintf(
				/* translators: %s: person type */
				__( 'No more %s IDs are available for this year.', 'simple-lms' ),
				$person_type
			)
		);
	}

	private function get_person_type_code( $person_type ) {
		$default = 'student' === $person_type ? 'S' : 'F';
		$key     = 'student' === $person_type ? 'student_code_prefix' : 'staff_code_prefix';
		$token   = $this->sanitize_code_token( $this->settings->get( $key, $default ), $default );

		if ( 'student' === $person_type && in_array( $token, array( 'STU', 'UGPS' ), true ) ) {
			return 'S';
		}

		if ( 'staff' === $person_type && in_array( $token, array( 'STA', 'STF', 'UGPF' ), true ) ) {
			return 'F';
		}

		return $token ?: $default;
	}

	private function get_person_code_year() {
		$timezone = (string) $this->settings->get( 'academic_timezone', AcademicClock::TIMEZONE );

		if ( '' === $timezone ) {
			$timezone = 'UTC';
		}

		try {
			$current = new \DateTimeImmutable( 'now', new \DateTimeZone( $timezone ) );
		} catch ( \Exception $exception ) {
			$current = AcademicClock::now();
		}

		return (int) $current->format( 'Y' );
	}

	private function is_person_code_available( $candidate ) {
		if ( $this->profiles->get_profile_by_person_code( $candidate ) ) {
			return false;
		}

		return ! username_exists( $this->get_username_from_person_code( $candidate ) );
	}

	private function sanitize_code_token( $value, $fallback ) {
		$token = strtoupper( preg_replace( '/[^A-Z0-9]/', '', (string) $value ) );

		if ( '' === $token ) {
			return $fallback;
		}

		return $token;
	}
}
