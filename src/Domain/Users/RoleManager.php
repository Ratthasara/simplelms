<?php

namespace SimpleLMS\Domain\Users;

defined( 'ABSPATH' ) || exit;

class RoleManager {
	const ROLE_STAFF                   = 'slms_staff';
	const MANAGED_CAPS_OPTION          = 'slms_managed_role_capabilities';
	const CAP_MANAGE_SETTINGS         = 'slms_manage_settings';
	const CAP_MANAGE_ACADEMICS        = 'slms_manage_academics';
	const CAP_MANAGE_USERS            = 'slms_manage_users';
	const CAP_MANAGE_ENROLLMENTS      = 'slms_manage_enrollments';
	const CAP_MANAGE_SECTIONS         = 'slms_manage_sections';
	const CAP_MANAGE_OWN_SECTIONS     = 'slms_manage_own_sections';
	const CAP_MANAGE_GRADEBOOK        = 'slms_manage_gradebook';
	const CAP_MANAGE_ATTENDANCE       = 'slms_manage_attendance';
	const CAP_VIEW_REPORTS            = 'slms_view_reports';
	const CAP_VIEW_AUDIT_LOG          = 'slms_view_audit_log';
	const CAP_VIEW_OWN_RECORDS        = 'slms_view_own_records';
	const CAP_SUBMIT_WORK             = 'slms_submit_work';
	const CAP_PARTICIPATE_LEARNING    = 'slms_participate_learning';
	const CAP_PUBLISH_COMMUNICATIONS  = 'slms_publish_communications';
	const CAP_MANAGE_COMMUNICATIONS   = 'slms_manage_communications';
	const CAP_RECEIVE_NOTIFICATIONS   = 'slms_receive_notifications';

	const LMS_ROLES = array( 'administrator', 'officer', 'lecturer', self::ROLE_STAFF, 'student' );

	public static function install_roles() {
		self::apply_caps( 'administrator', self::administrator_caps() );
		self::ensure_role( 'officer', 'Officer', self::officer_caps() );
		self::ensure_role( 'lecturer', 'Lecturer', self::lecturer_caps() );
		self::ensure_role( self::ROLE_STAFF, 'Staff', self::staff_caps(), true );
		self::ensure_role( 'student', 'Student', self::student_caps() );
		self::release_editor_role_from_lms();

		update_option( self::MANAGED_CAPS_OPTION, self::all_caps(), false );
	}

	public static function all_caps() {
		$caps = array(
			self::CAP_MANAGE_SETTINGS,
			self::CAP_MANAGE_ACADEMICS,
			self::CAP_MANAGE_USERS,
			self::CAP_MANAGE_ENROLLMENTS,
			self::CAP_MANAGE_SECTIONS,
			self::CAP_MANAGE_OWN_SECTIONS,
			self::CAP_MANAGE_GRADEBOOK,
			self::CAP_MANAGE_ATTENDANCE,
			self::CAP_VIEW_REPORTS,
			self::CAP_VIEW_AUDIT_LOG,
			self::CAP_VIEW_OWN_RECORDS,
			self::CAP_SUBMIT_WORK,
			self::CAP_PARTICIPATE_LEARNING,
			self::CAP_PUBLISH_COMMUNICATIONS,
			self::CAP_MANAGE_COMMUNICATIONS,
			self::CAP_RECEIVE_NOTIFICATIONS,
		);

		return array_merge(
			$caps,
			self::custom_post_type_caps( 'slms_subject', 'slms_subjects' ),
			self::custom_post_type_caps( 'slms_section', 'slms_sections' ),
			self::custom_post_type_caps( 'slms_lesson', 'slms_lessons' ),
			self::custom_post_type_caps( 'slms_announcement', 'slms_announcements' ),
			self::custom_post_type_caps( 'slms_assignment', 'slms_assignments' )
		);
	}

	private static function ensure_role( $role_key, $label, array $caps, $strict = false ) {
		$role = get_role( $role_key );

		if ( ! $role ) {
			add_role( $role_key, $label, $caps );
		}

		if ( $strict ) {
			$role = get_role( $role_key );

			foreach ( array_keys( (array) ( $role->capabilities ?? array() ) ) as $cap ) {
				if ( empty( $caps[ $cap ] ) ) {
					$role->remove_cap( $cap );
				}
			}
		}

		self::apply_caps( $role_key, $caps );
	}

	private static function apply_caps( $role_key, array $caps ) {
		$role = get_role( $role_key );

		if ( ! $role ) {
			return;
		}

		$managed_caps = array_unique( array_merge( self::all_caps(), (array) get_option( self::MANAGED_CAPS_OPTION, array() ) ) );

		if ( 'administrator' !== $role_key ) {
			$managed_caps = array_merge( $managed_caps, self::standard_post_caps() );
		}

		foreach ( $managed_caps as $cap ) {
			if ( empty( $caps[ $cap ] ) ) {
				$role->remove_cap( $cap );
			}
		}

		foreach ( $caps as $cap => $grant ) {
			if ( $grant ) {
				$role->add_cap( $cap );
			}
		}
	}

	private static function administrator_caps() {
		$caps = array_fill_keys( self::all_caps(), true );
		$caps['read'] = true;

		return $caps;
	}

	private static function officer_caps() {
		return array_merge(
			array(
				'read'                           => true,
				'upload_files'                   => true,
				self::CAP_MANAGE_ACADEMICS      => true,
				self::CAP_MANAGE_USERS          => true,
				self::CAP_MANAGE_ENROLLMENTS    => true,
				self::CAP_MANAGE_SECTIONS       => true,
				self::CAP_MANAGE_GRADEBOOK      => true,
				self::CAP_MANAGE_ATTENDANCE     => true,
				self::CAP_VIEW_REPORTS          => true,
				self::CAP_VIEW_AUDIT_LOG        => true,
				self::CAP_PUBLISH_COMMUNICATIONS => true,
				self::CAP_MANAGE_COMMUNICATIONS  => true,
				self::CAP_RECEIVE_NOTIFICATIONS => true,
			),
			array_fill_keys( self::standard_post_caps(), true ),
			array_fill_keys( self::custom_post_type_caps( 'slms_subject', 'slms_subjects' ), true ),
			array_fill_keys( self::custom_post_type_caps( 'slms_section', 'slms_sections' ), true ),
			array_fill_keys( self::custom_post_type_caps( 'slms_lesson', 'slms_lessons' ), true ),
			array_fill_keys( self::custom_post_type_caps( 'slms_announcement', 'slms_announcements' ), true ),
			array_fill_keys( self::custom_post_type_caps( 'slms_assignment', 'slms_assignments' ), true )
		);
	}

	private static function lecturer_caps() {
		return array_merge(
			array(
				'read'                           => true,
				'edit_posts'                     => true,
				'publish_posts'                  => true,
				'upload_files'                   => true,
				self::CAP_MANAGE_OWN_SECTIONS   => true,
				self::CAP_MANAGE_GRADEBOOK      => true,
				self::CAP_MANAGE_ATTENDANCE     => true,
				self::CAP_VIEW_REPORTS          => true,
				self::CAP_RECEIVE_NOTIFICATIONS => true,
			),
			self::custom_post_type_cap_map( 'slms_lesson', 'slms_lessons', self::creator_capabilities() ),
			self::custom_post_type_cap_map( 'slms_announcement', 'slms_announcements', self::creator_capabilities() ),
			self::custom_post_type_cap_map( 'slms_assignment', 'slms_assignments', self::creator_capabilities() )
		);
	}

	private static function staff_caps() {
		return array(
			'read'                           => true,
			self::CAP_RECEIVE_NOTIFICATIONS => true,
		);
	}

	private static function student_caps() {
		return array(
			'read'                          => true,
			self::CAP_VIEW_OWN_RECORDS     => true,
			self::CAP_SUBMIT_WORK          => true,
			self::CAP_PARTICIPATE_LEARNING => true,
			self::CAP_RECEIVE_NOTIFICATIONS => true,
		);
	}

	private static function release_editor_role_from_lms() {
		$role = get_role( 'editor' );

		if ( ! $role ) {
			return;
		}

		$lms_caps = array(
			self::CAP_MANAGE_SETTINGS,
			self::CAP_MANAGE_ACADEMICS,
			self::CAP_MANAGE_USERS,
			self::CAP_MANAGE_ENROLLMENTS,
			self::CAP_MANAGE_SECTIONS,
			self::CAP_MANAGE_OWN_SECTIONS,
			self::CAP_MANAGE_GRADEBOOK,
			self::CAP_MANAGE_ATTENDANCE,
			self::CAP_VIEW_REPORTS,
			self::CAP_VIEW_AUDIT_LOG,
			self::CAP_VIEW_OWN_RECORDS,
			self::CAP_SUBMIT_WORK,
			self::CAP_PARTICIPATE_LEARNING,
			self::CAP_PUBLISH_COMMUNICATIONS,
			self::CAP_MANAGE_COMMUNICATIONS,
			self::CAP_RECEIVE_NOTIFICATIONS,
		);

		$lms_caps = array_merge(
			$lms_caps,
			self::custom_post_type_caps( 'slms_subject', 'slms_subjects' ),
			self::custom_post_type_caps( 'slms_section', 'slms_sections' ),
			self::custom_post_type_caps( 'slms_lesson', 'slms_lessons' ),
			self::custom_post_type_caps( 'slms_announcement', 'slms_announcements' ),
			self::custom_post_type_caps( 'slms_assignment', 'slms_assignments' )
		);

		foreach ( array_unique( $lms_caps ) as $cap ) {
			$role->remove_cap( $cap );
		}
	}

	public static function custom_post_type_args( $singular, $plural ) {
		return array(
			'capability_type' => array( $singular, $plural ),
			'map_meta_cap'    => true,
			'capabilities'    => array(
				'edit_post'              => 'edit_' . $singular,
				'read_post'              => 'read_' . $singular,
				'delete_post'            => 'delete_' . $singular,
				'edit_posts'             => 'edit_' . $plural,
				'edit_others_posts'      => 'edit_others_' . $plural,
				'publish_posts'          => 'publish_' . $plural,
				'read_private_posts'     => 'read_private_' . $plural,
				'delete_posts'           => 'delete_' . $plural,
				'delete_private_posts'   => 'delete_private_' . $plural,
				'delete_published_posts' => 'delete_published_' . $plural,
				'delete_others_posts'    => 'delete_others_' . $plural,
				'edit_private_posts'     => 'edit_private_' . $plural,
				'edit_published_posts'   => 'edit_published_' . $plural,
				'create_posts'           => 'create_' . $plural,
			),
		);
	}

	public static function custom_post_type_caps( $singular, $plural ) {
		$args = self::custom_post_type_args( $singular, $plural );

		return array_values( $args['capabilities'] );
	}

	public static function custom_post_type_cap_map( $singular, $plural, array $allowed_capability_keys ) {
		$args = self::custom_post_type_args( $singular, $plural );
		$map  = array_fill_keys( array_values( $args['capabilities'] ), false );

		foreach ( $allowed_capability_keys as $capability_key ) {
			if ( isset( $args['capabilities'][ $capability_key ] ) ) {
				$map[ $args['capabilities'][ $capability_key ] ] = true;
			}
		}

		return $map;
	}

	public static function creator_capabilities() {
		return array(
			'edit_post',
			'read_post',
			'delete_post',
			'edit_posts',
			'publish_posts',
			'read_private_posts',
			'delete_posts',
			'delete_published_posts',
			'edit_published_posts',
			'create_posts',
		);
	}

	private static function standard_post_caps() {
		return array(
			'edit_posts',
			'edit_others_posts',
			'publish_posts',
			'read_private_posts',
			'delete_posts',
			'delete_private_posts',
			'delete_published_posts',
			'delete_others_posts',
			'edit_private_posts',
			'edit_published_posts',
		);
	}

	public static function get_primary_role( $user_id = 0 ) {
		$user = get_userdata( $user_id ?: get_current_user_id() );

		if ( ! $user ) {
			return '';
		}

		foreach ( self::LMS_ROLES as $role ) {
			if ( in_array( $role, (array) $user->roles, true ) ) {
				return $role;
			}
		}

		return '';
	}

	public static function get_role_label( $role ) {
		$role = sanitize_key( $role );

		if ( self::ROLE_STAFF === $role ) {
			return __( 'Staff', 'simple-lms' );
		}

		return ucwords( str_replace( '_', ' ', $role ) );
	}

	public static function is_plugin_user( $user_id = 0 ) {
		return '' !== self::get_primary_role( $user_id );
	}
}
