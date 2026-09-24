<?php

namespace SimpleLMS\Infrastructure\Frontend;

use SimpleLMS\Core\AcademicClock;
use SimpleLMS\Domain\Academic\EnrollmentService;
use SimpleLMS\Domain\Academic\TermService;
use SimpleLMS\Domain\Users\ProfileService;
use SimpleLMS\Domain\Users\ProvisioningService;
use SimpleLMS\Domain\Users\RoleManager;
use SimpleLMS\Domain\Users\StudentRemovalService;
use SimpleLMS\Infrastructure\Database\Schema;
use SimpleLMS\Infrastructure\Import\ImportService;

defined( 'ABSPATH' ) || exit;

/**
 * Owns every Academic Management workflow rendered inside the Simple LMS portal.
 * This class intentionally has no website-publishing or Simple Editors dependency.
 */
class AcademicManagementTools {
	/** @var ImportService */
	private $imports;
	/** @var TermService */
	private $terms;
	/** @var EnrollmentService */
	private $enrollments;
	/** @var ProfileService */
	private $profiles;
	/** @var ProvisioningService */
	private $provisioning;
	/** @var StudentRemovalService */
	private $student_removal;

	public function __construct( ImportService $imports, TermService $terms, EnrollmentService $enrollments, ProfileService $profiles, ProvisioningService $provisioning, StudentRemovalService $student_removal ) {
		$this->imports         = $imports;
		$this->terms           = $terms;
		$this->enrollments     = $enrollments;
		$this->profiles        = $profiles;
		$this->provisioning    = $provisioning;
		$this->student_removal = $student_removal;
	}

	public function register_hooks() {
		add_action( 'init', array( $this, 'handle_actions' ), 20 );
	}

	public function handle_actions() {
		if ( empty( $_POST['slms_academic_action'] ) || empty( $_POST['slms_academic_tools_nonce'] ) ) {
			return;
		}

		if ( ! $this->is_full_staff() ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['slms_academic_tools_nonce'] ) ), 'slms_academic_tools_action' ) ) {
			return;
		}

		$action   = sanitize_key( wp_unslash( $_POST['slms_academic_action'] ) );
		$redirect = remove_query_arg(
			array( 'slms_notice', 'slms_notice_type', 'slms_academic_group' ),
			wp_get_referer()
		);

		if ( ! $redirect ) {
			$redirect = home_url( '/' );
		}

		$full_staff_actions = array(
			'create_student_account', 'create_staff_account', 'update_student_account', 'update_staff_account',
			'delete_student_account', 'complete_student_program', 'delete_staff_account',
			'download_student_info', 'download_staff_info',
			'create_program', 'update_program', 'delete_program',
			'create_term', 'update_term', 'delete_term', 'generate_terms',
			'create_subject', 'update_subject', 'remove_subject_lecturer', 'delete_subject',
			'duplicate_subject', 'duplicate_subjects', 'import_data',
		);
		$administrator_actions = array( 'force_delete_student_account', 'force_delete_subject' );

		if ( ! in_array( $action, $full_staff_actions, true ) && ! ( in_array( $action, $administrator_actions, true ) && $this->is_administrator() ) ) {
			$this->redirect_with_result( $redirect, new \WP_Error( 'slms_academic_action_forbidden', __( 'You do not have permission to perform that Academic Management action.', 'simple-lms' ) ), '' );
		}

		switch ( $action ) {
			case 'create_student_account': $this->redirect_with_result( $redirect, $this->create_student_account(), __( 'Student account created successfully.', 'simple-lms' ) ); break;
			case 'create_staff_account': $this->redirect_with_result( $redirect, $this->create_staff_account(), __( 'Staff account created successfully.', 'simple-lms' ) ); break;
			case 'update_student_account': $this->redirect_with_result( $redirect, $this->update_student_account(), __( 'Student account updated successfully.', 'simple-lms' ) ); break;
			case 'update_staff_account': $this->redirect_with_result( $redirect, $this->update_staff_account(), __( 'Staff account updated successfully.', 'simple-lms' ) ); break;
			case 'delete_student_account': $this->redirect_with_result( $redirect, $this->delete_student_account(), __( 'Student account removed successfully.', 'simple-lms' ) ); break;
			case 'force_delete_student_account': $this->redirect_with_result( $redirect, $this->force_delete_student_account(), __( 'Student account and linked LMS history force removed successfully.', 'simple-lms' ) ); break;
			case 'complete_student_program': $this->redirect_with_result( $redirect, $this->complete_student_program(), __( 'Student program completion saved successfully.', 'simple-lms' ) ); break;
			case 'delete_staff_account': $this->redirect_with_result( $redirect, $this->delete_staff_account(), __( 'Staff account removed successfully.', 'simple-lms' ) ); break;
			case 'download_student_info': $this->redirect_with_result( $redirect, $this->download_people_info( 'student' ), __( 'Student export prepared successfully.', 'simple-lms' ) ); break;
			case 'download_staff_info': $this->redirect_with_result( $redirect, $this->download_people_info( 'staff' ), __( 'Staff export prepared successfully.', 'simple-lms' ) ); break;
			case 'create_program': $this->redirect_with_result( $redirect, $this->create_program(), __( 'Program saved successfully.', 'simple-lms' ) ); break;
			case 'update_program': $this->redirect_with_result( $redirect, $this->update_program(), __( 'Program updated successfully.', 'simple-lms' ) ); break;
			case 'delete_program': $this->redirect_with_result( $redirect, $this->delete_program(), __( 'Program removed successfully.', 'simple-lms' ) ); break;
			case 'create_term': $this->redirect_with_result( $redirect, $this->create_term(), __( 'Term created successfully.', 'simple-lms' ) ); break;
			case 'update_term': $this->redirect_with_result( $redirect, $this->update_term(), __( 'Term updated successfully.', 'simple-lms' ) ); break;
			case 'delete_term': $this->redirect_with_result( $redirect, $this->delete_term(), __( 'Term removed successfully.', 'simple-lms' ) ); break;
			case 'generate_terms': $this->redirect_with_result( $redirect, $this->generate_next_academic_year_terms(), __( 'Term automation checked successfully.', 'simple-lms' ) ); break;
			case 'create_subject': $this->redirect_with_result( $redirect, $this->create_subject(), __( 'Subject created successfully.', 'simple-lms' ) ); break;
			case 'update_subject': $this->redirect_with_result( $redirect, $this->update_subject(), __( 'Subject updated successfully.', 'simple-lms' ) ); break;
			case 'remove_subject_lecturer': $this->redirect_with_result( $redirect, $this->remove_subject_lecturer(), __( 'Lecturer unassigned successfully.', 'simple-lms' ) ); break;
			case 'delete_subject': $this->redirect_with_result( $redirect, $this->delete_subject(), __( 'Subject removed successfully.', 'simple-lms' ) ); break;
			case 'force_delete_subject': $this->redirect_with_result( $redirect, $this->delete_subject( true ), __( 'Subject and linked records removed successfully.', 'simple-lms' ) ); break;
			case 'duplicate_subject': $this->redirect_with_result( $redirect, $this->duplicate_subject(), __( 'Subject duplicated successfully.', 'simple-lms' ) ); break;
			case 'duplicate_subjects': $this->redirect_with_result( $redirect, $this->duplicate_subjects(), __( 'Selected subjects duplicated successfully.', 'simple-lms' ) ); break;
			case 'import_data': $this->redirect_with_result( $redirect, $this->run_import(), __( 'Import completed.', 'simple-lms' ) ); break;
		}
	}

	public function render_academic_management_page() {
		if ( ! $this->is_full_staff() ) {
			return '';
		}

		$current_section = $this->get_academic_section();
		$page_url        = $this->get_academic_base_url();
		$terms           = $this->terms->list_terms();
		$programs        = get_terms(
			array(
				'taxonomy'   => 'slms_program',
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);
		$programs        = is_wp_error( $programs ) ? array() : $programs;
		$subjects        = get_posts(
			array(
				'post_type'      => 'slms_subject',
				'post_status'    => array( 'publish', 'private', 'draft', 'pending' ),
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		$lecturers       = get_users(
			array(
				'role__in' => array( 'lecturer', 'officer', 'administrator' ),
				'orderby'  => 'display_name',
				'order'    => 'ASC',
			)
		);
		$student_count   = $this->profiles->count_profiles( 'student' );
		$staff_count     = $this->profiles->count_profiles( 'staff' );
		$section_counts  = array(
			'programs' => count( $programs ),
			'terms'    => count( $terms ),
			'subjects' => count( $subjects ),
			'students' => $student_count,
			'staff'    => $staff_count,
		);

		ob_start();
		?>
		<section class="slms-portal-panel slms-academic-section-shell slms-flow-section">
			<?php $this->render_academic_section_nav( $page_url, $current_section, $section_counts ); ?>
			<?php $this->render_academic_management_section( $current_section, $page_url, $terms, $programs, $subjects, $lecturers, $section_counts ); ?>
		</section>
		<?php

		return (string) ob_get_clean();
	}

	private function create_subject() {
		$title = sanitize_text_field( wp_unslash( $_POST['subject_title'] ?? '' ) );
		$visibility = $this->sanitize_post_visibility( wp_unslash( $_POST['subject_visibility'] ?? 'publish' ) );

		if ( '' === $title ) {
			return new \WP_Error( 'slms_missing_subject_title', __( 'Please provide a subject title.', 'simple-lms' ) );
		}

		$subject_id = wp_insert_post(
			array(
				'post_type'    => 'slms_subject',
				'post_status'  => $visibility,
				'post_title'   => $title,
				'post_content' => wp_kses_post( wp_unslash( $_POST['subject_description'] ?? '' ) ),
			),
			true
		);

		if ( is_wp_error( $subject_id ) ) {
			return $subject_id;
		}

		update_post_meta( $subject_id, '_slms_subject_code', strtoupper( sanitize_text_field( wp_unslash( $_POST['subject_code'] ?? '' ) ) ) );
		update_post_meta( $subject_id, '_slms_credits', max( 0, (float) wp_unslash( $_POST['credits'] ?? 0 ) ) );
		update_post_meta( $subject_id, '_slms_term_id', absint( $_POST['term_id'] ?? 0 ) );
		update_post_meta( $subject_id, '_slms_subject_status', $this->sanitize_subject_status( wp_unslash( $_POST['subject_status'] ?? 'active' ) ) );
		update_post_meta( $subject_id, '_slms_capacity', max( 0, absint( $_POST['capacity'] ?? 0 ) ) );
		update_post_meta( $subject_id, '_slms_total_classes', max( 0, absint( $_POST['total_classes'] ?? 0 ) ) );
		update_post_meta( $subject_id, '_slms_delivery_mode', $this->sanitize_delivery_mode( wp_unslash( $_POST['delivery_mode'] ?? 'onsite' ) ) );

		$program_id = absint( $_POST['program_id'] ?? 0 );
		if ( $program_id ) {
			wp_set_object_terms( $subject_id, array( $program_id ), 'slms_program', false );
		} else {
			wp_set_object_terms( $subject_id, array(), 'slms_program', false );
		}

		$lecturer_ids = array_map( 'absint', (array) wp_unslash( $_POST['lecturer_ids'] ?? array() ) );
		$this->enrollments->assign_section_staff( $subject_id, $lecturer_ids, 'lecturer', get_current_user_id() );

		return $subject_id;
	}

	private function duplicate_subject() {
		$source_subject_id = absint( $_POST['source_subject_id'] ?? 0 );
		$target_term_id    = absint( $_POST['target_term_id'] ?? 0 );
		$options           = array(
			'copy_materials'           => ! empty( $_POST['duplicate_content'] ),
			'copy_assignments'         => ! empty( $_POST['duplicate_content'] ),
			'copy_lecturers'           => true,
			'copy_welcome_discussion'  => false,
			'title_suffix'             => '',
		);

		return $this->duplicate_subject_record( $source_subject_id, $target_term_id, $options );
	}

	private function duplicate_subjects() {
		$source_raw     = isset( $_POST['source_subject_ids'] ) ? wp_unslash( $_POST['source_subject_ids'] ) : array();
		$target_term_id = absint( $_POST['target_term_id'] ?? 0 );

		if ( ! is_array( $source_raw ) ) {
			$source_raw = array( $source_raw );
		}

		$source_ids = array_values( array_unique( array_filter( array_map( 'absint', $source_raw ) ) ) );

		if ( empty( $source_ids ) ) {
			return new \WP_Error( 'slms_missing_source_subjects', __( 'Please select at least one subject to duplicate.', 'simple-lms' ) );
		}

		if ( ! $target_term_id ) {
			return new \WP_Error( 'slms_missing_target_term', __( 'Please choose a target term.', 'simple-lms' ) );
		}

		$options = array(
			'copy_materials'           => ! empty( $_POST['duplicate_copy_materials'] ),
			'copy_assignments'         => ! empty( $_POST['duplicate_copy_assignments'] ),
			'copy_lecturers'           => ! empty( $_POST['duplicate_copy_lecturers'] ),
			'copy_welcome_discussion'  => ! empty( $_POST['duplicate_copy_welcome_discussion'] ),
			'title_suffix'             => sanitize_text_field( wp_unslash( $_POST['duplicate_title_suffix'] ?? '' ) ),
		);

		$created = 0;
		$errors  = array();

		foreach ( $source_ids as $source_subject_id ) {
			$result = $this->duplicate_subject_record( $source_subject_id, $target_term_id, $options );

			if ( is_wp_error( $result ) ) {
				$errors[] = $result->get_error_message();
				continue;
			}

			$created++;
		}

		if ( 0 === $created ) {
			return new \WP_Error(
				'slms_duplicate_subjects_failed',
				$errors ? implode( ' ', array_unique( $errors ) ) : __( 'No subjects could be duplicated.', 'simple-lms' )
			);
		}

		return array(
			'created' => $created,
			'failed'  => count( $errors ),
		);
	}

	private function duplicate_subject_record( $source_subject_id, $target_term_id, array $options = array() ) {
		$source_subject_id = absint( $source_subject_id );
		$target_term_id    = absint( $target_term_id );
		$source            = get_post( $source_subject_id );

		if ( ! $source || 'slms_subject' !== $source->post_type ) {
			return new \WP_Error( 'slms_invalid_source_subject', __( 'Please choose a valid source subject.', 'simple-lms' ) );
		}

		if ( ! $target_term_id ) {
			return new \WP_Error( 'slms_missing_target_term', __( 'Please choose a target term.', 'simple-lms' ) );
		}

		$title_suffix = trim( sanitize_text_field( $options['title_suffix'] ?? '' ) );
		$new_title    = $source->post_title;

		if ( '' !== $title_suffix ) {
			$new_title = sprintf( '%1$s - %2$s', $source->post_title, $title_suffix );
		}

		$new_subject_id = wp_insert_post(
			array(
				'post_type'    => 'slms_subject',
				'post_status'  => 'publish',
				'post_title'   => $new_title,
				'post_content' => $source->post_content,
				'post_excerpt' => $source->post_excerpt,
				'post_author'  => get_current_user_id(),
			),
			true
		);

		if ( is_wp_error( $new_subject_id ) ) {
			return $new_subject_id;
		}

		foreach ( array( '_slms_subject_code', '_slms_credits', '_slms_subject_type', '_slms_subject_status', '_slms_capacity', '_slms_total_classes', '_slms_delivery_mode' ) as $meta_key ) {
			update_post_meta( $new_subject_id, $meta_key, get_post_meta( $source_subject_id, $meta_key, true ) );
		}

		update_post_meta( $new_subject_id, '_slms_term_id', $target_term_id );
		update_post_meta( $new_subject_id, '_slms_duplicated_from_subject_id', $source_subject_id );
		update_post_meta( $new_subject_id, '_slms_duplicated_at', AcademicClock::mysql() );
		update_post_meta( $new_subject_id, '_slms_duplicated_by', get_current_user_id() );

		$programs = wp_get_object_terms( $source_subject_id, 'slms_program', array( 'fields' => 'ids' ) );
		if ( ! is_wp_error( $programs ) && $programs ) {
			wp_set_object_terms( $new_subject_id, $programs, 'slms_program', false );
		}

		if ( ! empty( $options['copy_lecturers'] ) ) {
			$staff_ids = wp_list_pluck( $this->enrollments->get_section_staff( $source_subject_id, 'lecturer' ), 'user_id' );
			if ( $staff_ids ) {
				$this->enrollments->assign_section_staff( $new_subject_id, array_map( 'absint', $staff_ids ), 'lecturer', get_current_user_id() );
			}
		}

		if ( ! empty( $options['copy_materials'] ) ) {
			$this->duplicate_child_content( 'slms_lesson', $source_subject_id, $new_subject_id );
		}

		if ( ! empty( $options['copy_assignments'] ) ) {
			$this->duplicate_child_content( 'slms_assignment', $source_subject_id, $new_subject_id );
		}

		if ( ! empty( $options['copy_welcome_discussion'] ) ) {
			$this->duplicate_welcome_discussions( $source_subject_id, $new_subject_id );
		}

		return $new_subject_id;
	}

	private function duplicate_welcome_discussions( $source_subject_id, $new_subject_id ) {
		$threads = get_posts(
			array(
				'post_type'      => 'slms_discussion',
				'post_status'    => array( 'publish', 'private', 'draft', 'pending' ),
				'posts_per_page' => -1,
				'meta_query'     => array(
					array(
						'key'     => '_slms_subject_id',
						'value'   => absint( $source_subject_id ),
						'compare' => '=',
					),
					array(
						'key'     => '_slms_is_welcome_thread',
						'value'   => 1,
						'compare' => '=',
					),
				),
			)
		);

		foreach ( $threads as $thread ) {
			$new_thread_id = wp_insert_post(
				array(
					'post_type'    => 'slms_discussion',
					'post_status'  => in_array( $thread->post_status, array( 'publish', 'private', 'draft', 'pending' ), true ) ? $thread->post_status : 'publish',
					'post_title'   => $thread->post_title,
					'post_content' => $thread->post_content,
					'post_excerpt' => $thread->post_excerpt,
					'post_author'  => get_current_user_id(),
				),
				true
			);

			if ( is_wp_error( $new_thread_id ) ) {
				continue;
			}

			$meta = get_post_meta( $thread->ID );
			foreach ( $meta as $meta_key => $values ) {
				if ( '_slms_subject_id' === $meta_key ) {
					update_post_meta( $new_thread_id, $meta_key, absint( $new_subject_id ) );
					continue;
				}

				foreach ( $values as $value ) {
					add_post_meta( $new_thread_id, $meta_key, maybe_unserialize( $value ) );
				}
			}
		}
	}

	private function duplicate_child_content( $post_type, $source_subject_id, $new_subject_id ) {
		$items = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => array( 'publish', 'private', 'draft', 'pending' ),
				'posts_per_page' => -1,
				'meta_key'       => '_slms_section_id',
				'meta_value'     => $source_subject_id,
			)
		);

		foreach ( $items as $item ) {
			$new_item_id = wp_insert_post(
				array(
					'post_type'    => $post_type,
					'post_status'  => 'draft',
					'post_title'   => $item->post_title,
					'post_content' => $item->post_content,
					'post_excerpt' => $item->post_excerpt,
					'post_author'  => get_current_user_id(),
				),
				true
			);

			if ( is_wp_error( $new_item_id ) ) {
				continue;
			}

			$meta = get_post_meta( $item->ID );
			foreach ( $meta as $meta_key => $values ) {
				if ( '_slms_section_id' === $meta_key ) {
					update_post_meta( $new_item_id, $meta_key, $new_subject_id );
					continue;
				}
				foreach ( $values as $value ) {
					add_post_meta( $new_item_id, $meta_key, maybe_unserialize( $value ) );
				}
			}
		}
	}

	private function create_program() {
		if ( ! $this->is_full_staff() ) {
			return new \WP_Error( 'slms_program_permissions', __( 'You do not have permission to create programs.', 'simple-lms' ) );
		}

		$name = sanitize_text_field( wp_unslash( $_POST['program_name'] ?? '' ) );
		$code = strtoupper( sanitize_text_field( wp_unslash( $_POST['program_code'] ?? '' ) ) );

		if ( '' === $name ) {
			return new \WP_Error( 'slms_program_name_missing', __( 'Please provide a program name.', 'simple-lms' ) );
		}

		if ( '' === $code ) {
			return new \WP_Error( 'slms_program_code_missing', __( 'Please provide a program code.', 'simple-lms' ) );
		}

		if ( $this->program_code_exists( $code ) ) {
			return new \WP_Error( 'slms_program_code_duplicate', __( 'This program code is already in use. Please choose a unique code.', 'simple-lms' ) );
		}

		$result = wp_insert_term(
			$name,
			'slms_program',
			array(
				'description' => sanitize_textarea_field( wp_unslash( $_POST['program_description'] ?? '' ) ),
				'slug'        => sanitize_title( $code ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$program_id = (int) $result['term_id'];

		update_term_meta( $program_id, '_slms_program_code', $code );

		return $program_id;
	}

	private function update_program() {
		if ( ! $this->is_full_staff() ) {
			return new \WP_Error( 'slms_program_permissions', __( 'You do not have permission to update programs.', 'simple-lms' ) );
		}

		$program_id = absint( $_POST['program_id'] ?? 0 );
		$name       = sanitize_text_field( wp_unslash( $_POST['program_name'] ?? '' ) );
		$code       = strtoupper( sanitize_text_field( wp_unslash( $_POST['program_code'] ?? '' ) ) );

		if ( ! $program_id || '' === $name || '' === $code ) {
			return new \WP_Error( 'slms_program_invalid', __( 'Please choose a valid program and provide its name and code.', 'simple-lms' ) );
		}

		if ( $this->program_code_exists( $code, $program_id ) ) {
			return new \WP_Error( 'slms_program_code_duplicate', __( 'This program code is already in use. Please choose a unique code.', 'simple-lms' ) );
		}

		$result = wp_update_term(
			$program_id,
			'slms_program',
			array(
				'name'        => $name,
				'description' => sanitize_textarea_field( wp_unslash( $_POST['program_description'] ?? '' ) ),
				'slug'        => sanitize_title( $code ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		update_term_meta( $program_id, '_slms_program_code', $code );

		return $program_id;
	}

	private function delete_program() {
		if ( ! $this->is_full_staff() ) {
			return new \WP_Error( 'slms_program_permissions', __( 'You do not have permission to remove programs.', 'simple-lms' ) );
		}

		$program_id = absint( $_POST['program_id'] ?? 0 );
		$program    = get_term( $program_id, 'slms_program' );

		if ( ! $program_id || ! $program || is_wp_error( $program ) ) {
			return new \WP_Error( 'slms_program_invalid', __( 'Please choose a valid program.', 'simple-lms' ) );
		}

		$assigned_subjects = get_posts(
			array(
				'post_type'      => 'slms_subject',
				'post_status'    => array( 'publish', 'draft', 'private', 'pending', 'future' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'tax_query'      => array(
					array(
						'taxonomy' => 'slms_program',
						'field'    => 'term_id',
						'terms'    => array( $program_id ),
					),
				),
			)
		);

		if ( ! empty( $assigned_subjects ) ) {
			return new \WP_Error( 'slms_program_in_use', __( 'This program is still assigned to one or more subjects. Remove or reassign those subjects first.', 'simple-lms' ) );
		}

		$profile_refs = $this->count_rows(
			Schema::table( 'user_profiles' ),
			'program_id',
			$program_id
		);

		if ( $profile_refs > 0 ) {
			return new \WP_Error( 'slms_program_has_students', __( 'This program is still assigned to one or more student records. Reassign those students first.', 'simple-lms' ) );
		}

		$deleted = wp_delete_term( $program_id, 'slms_program' );

		if ( is_wp_error( $deleted ) ) {
			return $deleted;
		}

		if ( ! $deleted ) {
			return new \WP_Error( 'slms_program_delete_failed', __( 'The program could not be removed.', 'simple-lms' ) );
		}

		return true;
	}

	private function program_code_exists( $code, $exclude_term_id = 0 ) {
		$matches = get_terms(
			array(
				'taxonomy'   => 'slms_program',
				'hide_empty' => false,
				'fields'     => 'ids',
				'meta_query' => array(
					array(
						'key'     => '_slms_program_code',
						'value'   => strtoupper( trim( (string) $code ) ),
						'compare' => '=',
					),
				),
			)
		);

		if ( is_wp_error( $matches ) || empty( $matches ) ) {
			return false;
		}

		foreach ( $matches as $term_id ) {
			if ( (int) $term_id !== (int) $exclude_term_id ) {
				return true;
			}
		}

		return false;
	}

	private function create_term() {
		if ( ! $this->is_full_staff() ) {
			return new \WP_Error( 'slms_term_permissions', __( 'You do not have permission to create terms.', 'simple-lms' ) );
		}

		return $this->terms->create_term( $this->term_payload_from_request() );
	}

	private function update_term() {
		if ( ! $this->is_full_staff() ) {
			return new \WP_Error( 'slms_term_permissions', __( 'You do not have permission to update terms.', 'simple-lms' ) );
		}

		$term_id = absint( $_POST['term_id'] ?? 0 );

		if ( ! $term_id ) {
			return new \WP_Error( 'slms_term_invalid', __( 'Please choose a valid term.', 'simple-lms' ) );
		}

		return $this->terms->update_term( $term_id, $this->term_payload_from_request() );
	}

	private function delete_term() {
		if ( ! $this->is_full_staff() ) {
			return new \WP_Error( 'slms_term_permissions', __( 'You do not have permission to remove terms.', 'simple-lms' ) );
		}

		$term_id = absint( $_POST['term_id'] ?? 0 );

		if ( ! $term_id ) {
			return new \WP_Error( 'slms_term_invalid', __( 'Please choose a valid term.', 'simple-lms' ) );
		}

		return $this->terms->delete_term( $term_id );
	}

	private function generate_next_academic_year_terms() {
		if ( ! $this->is_full_staff() ) {
			return new \WP_Error( 'slms_term_permissions', __( 'You do not have permission to generate terms.', 'simple-lms' ) );
		}

		$created = $this->terms->maybe_create_next_academic_year_terms( true );

		return array(
			'created' => count( $created ),
			'updated' => 0,
			'failed'  => 0,
		);
	}

	private function update_subject() {
		if ( ! $this->is_full_staff() ) {
			return new \WP_Error( 'slms_subject_permissions', __( 'You do not have permission to update subjects.', 'simple-lms' ) );
		}

		$subject_id = absint( $_POST['subject_id'] ?? 0 );
		$subject    = get_post( $subject_id );
		$title      = sanitize_text_field( wp_unslash( $_POST['subject_title'] ?? '' ) );

		if ( ! $subject || 'slms_subject' !== $subject->post_type ) {
			return new \WP_Error( 'slms_subject_invalid', __( 'Please choose a valid subject.', 'simple-lms' ) );
		}

		if ( '' === $title ) {
			return new \WP_Error( 'slms_missing_subject_title', __( 'Please provide a subject title.', 'simple-lms' ) );
		}

		$updated = wp_update_post(
			array(
				'ID'           => $subject_id,
				'post_title'   => $title,
				'post_content' => wp_kses_post( wp_unslash( $_POST['subject_description'] ?? '' ) ),
				'post_status'  => $this->sanitize_post_visibility( wp_unslash( $_POST['subject_visibility'] ?? $subject->post_status ) ),
			),
			true
		);

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		update_post_meta( $subject_id, '_slms_subject_code', strtoupper( sanitize_text_field( wp_unslash( $_POST['subject_code'] ?? '' ) ) ) );
		update_post_meta( $subject_id, '_slms_credits', max( 0, (float) wp_unslash( $_POST['credits'] ?? 0 ) ) );
		update_post_meta( $subject_id, '_slms_term_id', absint( $_POST['term_id'] ?? 0 ) );
		update_post_meta( $subject_id, '_slms_subject_status', $this->sanitize_subject_status( wp_unslash( $_POST['subject_status'] ?? 'active' ) ) );
		update_post_meta( $subject_id, '_slms_capacity', max( 0, absint( $_POST['capacity'] ?? 0 ) ) );
		update_post_meta( $subject_id, '_slms_total_classes', max( 0, absint( $_POST['total_classes'] ?? 0 ) ) );
		update_post_meta( $subject_id, '_slms_delivery_mode', $this->sanitize_delivery_mode( wp_unslash( $_POST['delivery_mode'] ?? 'onsite' ) ) );

		$program_id = absint( $_POST['program_id'] ?? 0 );
		if ( $program_id ) {
			wp_set_object_terms( $subject_id, array( $program_id ), 'slms_program', false );
		} else {
			wp_set_object_terms( $subject_id, array(), 'slms_program', false );
		}

		$lecturer_ids = array_map( 'absint', (array) wp_unslash( $_POST['lecturer_ids'] ?? array() ) );
		$this->enrollments->assign_section_staff( $subject_id, $lecturer_ids, 'lecturer', get_current_user_id() );

		return $subject_id;
	}

	private function remove_subject_lecturer() {
		if ( ! $this->is_full_staff() ) {
			return new \WP_Error( 'slms_subject_permissions', __( 'You do not have permission to update lecturer assignments.', 'simple-lms' ) );
		}

		$subject_id  = absint( $_POST['subject_id'] ?? 0 );
		$lecturer_id = absint( $_POST['lecturer_id'] ?? 0 );
		$subject     = get_post( $subject_id );

		if ( ! $subject || 'slms_subject' !== $subject->post_type ) {
			return new \WP_Error( 'slms_subject_invalid', __( 'Please choose a valid subject.', 'simple-lms' ) );
		}

		if ( ! $lecturer_id ) {
			return new \WP_Error( 'slms_invalid_subject_lecturer', __( 'Please choose a valid lecturer assignment.', 'simple-lms' ) );
		}

		$assigned_ids = array_map( 'intval', wp_list_pluck( $this->enrollments->get_section_staff( $subject_id, 'lecturer' ), 'user_id' ) );

		if ( ! in_array( $lecturer_id, $assigned_ids, true ) ) {
			return new \WP_Error( 'slms_subject_lecturer_missing', __( 'That lecturer is not currently assigned to this subject.', 'simple-lms' ) );
		}

		$result = $this->enrollments->unassign_section_staff( $subject_id, $lecturer_id, 'lecturer' );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! $result ) {
			return new \WP_Error( 'slms_subject_lecturer_unassign_failed', __( 'The lecturer could not be unassigned from this subject.', 'simple-lms' ) );
		}

		return true;
	}

	private function delete_subject( $force = false ) {
		if ( ! $this->is_full_staff() ) {
			return new \WP_Error( 'slms_subject_permissions', __( 'You do not have permission to remove subjects.', 'simple-lms' ) );
		}

		$subject_id = absint( $_POST['subject_id'] ?? 0 );
		$subject    = get_post( $subject_id );

		if ( ! $subject || 'slms_subject' !== $subject->post_type ) {
			return new \WP_Error( 'slms_subject_invalid', __( 'Please choose a valid subject.', 'simple-lms' ) );
		}

		if ( $force ) {
			if ( ! $this->is_administrator() ) {
				return new \WP_Error( 'slms_subject_force_permissions', __( 'Only administrators can bypass subject removal guardrails.', 'simple-lms' ) );
			}

			$this->force_delete_subject_dependencies( $subject_id );
		} else {
			$linked_assignments = get_posts(
				array(
					'post_type'      => 'slms_assignment',
					'post_status'    => array( 'publish', 'draft', 'private', 'pending', 'future' ),
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'meta_key'       => '_slms_section_id',
					'meta_value'     => $subject_id,
				)
			);

			if ( ! empty( $linked_assignments ) ) {
				return new \WP_Error( 'slms_subject_has_assignments', __( 'This subject has assignment records. Remove those assignments first.', 'simple-lms' ) );
			}

			$linked_lessons = get_posts(
				array(
					'post_type'      => array( 'slms_lesson', 'slms_announcement' ),
					'post_status'    => array( 'publish', 'draft', 'private', 'pending', 'future' ),
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'meta_key'       => '_slms_section_id',
					'meta_value'     => $subject_id,
				)
			);

			if ( ! empty( $linked_lessons ) ) {
				return new \WP_Error( 'slms_subject_has_workspace', __( 'This subject still has materials or announcements attached to it. Remove those items first.', 'simple-lms' ) );
			}

			$linked_discussions = get_posts(
				array(
					'post_type'      => 'slms_discussion',
					'post_status'    => array( 'publish', 'draft', 'private', 'pending', 'future' ),
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'meta_key'       => '_slms_subject_id',
					'meta_value'     => $subject_id,
				)
			);

			if ( ! empty( $linked_discussions ) ) {
				return new \WP_Error( 'slms_subject_has_discussions', __( 'This subject has discussion records. Remove those discussions first.', 'simple-lms' ) );
			}

			$table_checks = array(
				Schema::table( 'section_staff' )          => __( 'This subject still has lecturer assignments.', 'simple-lms' ),
				Schema::table( 'enrollments' )            => __( 'This subject still has enrolled students.', 'simple-lms' ),
				Schema::table( 'assignment_submissions' ) => __( 'This subject still has assignment submissions.', 'simple-lms' ),
				Schema::table( 'grade_categories' )       => __( 'This subject still has gradebook categories.', 'simple-lms' ),
				Schema::table( 'grade_items' )            => __( 'This subject still has gradebook items.', 'simple-lms' ),
				Schema::table( 'grade_scores' )           => __( 'This subject still has recorded grades.', 'simple-lms' ),
				Schema::table( 'attendance_sessions' )    => __( 'This subject still has attendance sessions.', 'simple-lms' ),
				Schema::table( 'attendance_records' )     => __( 'This subject still has attendance records.', 'simple-lms' ),
			);

			foreach ( $table_checks as $table => $message ) {
				if ( $this->count_rows( $table, 'section_id', $subject_id ) > 0 ) {
					return new \WP_Error( 'slms_subject_in_use', $message );
				}
			}
		}

		$deleted = wp_delete_post( $subject_id, true );

		if ( ! $deleted ) {
			return new \WP_Error( 'slms_subject_delete_failed', __( 'The subject could not be removed.', 'simple-lms' ) );
		}

		return true;
	}

	private function force_delete_subject_dependencies( $subject_id ) {
		$subject_id = absint( $subject_id );

		if ( ! $subject_id ) {
			return;
		}

		$this->delete_subject_submission_files( $subject_id );
		$this->force_delete_subject_posts( 'slms_assignment', '_slms_section_id', $subject_id, 'assignments' );
		$this->force_delete_subject_posts( 'slms_lesson', '_slms_section_id', $subject_id, 'materials' );
		$this->force_delete_subject_posts( 'slms_announcement', '_slms_section_id', $subject_id );
		$this->force_delete_subject_posts( 'slms_discussion', '_slms_subject_id', $subject_id, 'discussions', true );

		delete_post_meta( $subject_id, '_slms_subject_results_overrides' );

		$this->delete_rows_by_column( Schema::table( 'grade_scores' ), 'section_id', $subject_id );
		$this->delete_rows_by_column( Schema::table( 'grade_items' ), 'section_id', $subject_id );
		$this->delete_rows_by_column( Schema::table( 'grade_categories' ), 'section_id', $subject_id );
		$this->delete_rows_by_column( Schema::table( 'attendance_records' ), 'section_id', $subject_id );
		$this->delete_rows_by_column( Schema::table( 'attendance_sessions' ), 'section_id', $subject_id );
		$this->delete_rows_by_column( Schema::table( 'assignment_submissions' ), 'section_id', $subject_id );
		$this->delete_rows_by_column( Schema::table( 'enrollments' ), 'section_id', $subject_id );
		$this->delete_rows_by_column( Schema::table( 'section_staff' ), 'section_id', $subject_id );
	}

	private function create_student_account() {
		if ( ! $this->is_full_staff() ) {
			return new \WP_Error( 'slms_student_permissions', __( 'You do not have permission to create student accounts.', 'simple-lms' ) );
		}

		return $this->provisioning->create_student(
			array(
				'full_name'          => sanitize_text_field( wp_unslash( $_POST['student_full_name'] ?? '' ) ),
				'email'              => sanitize_email( wp_unslash( $_POST['student_email'] ?? '' ) ),
				'person_code'        => sanitize_text_field( wp_unslash( $_POST['student_code'] ?? '' ) ),
				'nrc_number'         => sanitize_text_field( wp_unslash( $_POST['student_nrc_number'] ?? '' ) ),
				'program_id'         => absint( $_POST['student_program_id'] ?? 0 ),
				'intake_term_id'     => absint( $_POST['student_intake_term_id'] ?? 0 ),
				'phone'              => sanitize_text_field( wp_unslash( $_POST['student_phone'] ?? '' ) ),
				'send_welcome_email' => ! empty( $_POST['student_send_welcome_email'] ),
			)
		);
	}

	private function create_staff_account() {
		if ( ! $this->is_full_staff() ) {
			return new \WP_Error( 'slms_staff_permissions', __( 'You do not have permission to create staff accounts.', 'simple-lms' ) );
		}

		return $this->provisioning->create_staff(
			array(
				'full_name'          => sanitize_text_field( wp_unslash( $_POST['staff_full_name'] ?? '' ) ),
				'email'              => sanitize_email( wp_unslash( $_POST['staff_email'] ?? '' ) ),
				'person_code'        => sanitize_text_field( wp_unslash( $_POST['staff_code'] ?? '' ) ),
				'nrc_number'         => sanitize_text_field( wp_unslash( $_POST['staff_nrc_number'] ?? '' ) ),
				'role'               => ProvisioningService::normalize_staff_role( wp_unslash( $_POST['staff_role'] ?? 'lecturer' ) ),
				'department'         => sanitize_text_field( wp_unslash( $_POST['staff_department'] ?? '' ) ),
				'position_title'     => sanitize_text_field( wp_unslash( $_POST['staff_position_title'] ?? '' ) ),
				'phone'              => sanitize_text_field( wp_unslash( $_POST['staff_phone'] ?? '' ) ),
				'send_welcome_email' => ! empty( $_POST['staff_send_welcome_email'] ),
			)
		);
	}

	private function update_student_account() {
		return $this->update_person_account( 'student' );
	}

	private function update_staff_account() {
		return $this->update_person_account( 'staff' );
	}

	private function sync_student_subjects_from_request( $student_user_id ) {
		if ( empty( $_POST['slms_sync_subjects'] ) ) {
			return array(
				'enrolled'   => 0,
				'unenrolled' => 0,
				'unchanged'  => 0,
				'failed'     => 0,
				'errors'     => array(),
			);
		}

		$selected_raw  = isset( $_POST['slms_student_subject_ids'] ) ? wp_unslash( $_POST['slms_student_subject_ids'] ) : array();
		$available_raw = isset( $_POST['slms_available_subject_ids'] ) ? wp_unslash( $_POST['slms_available_subject_ids'] ) : array();

		if ( ! is_array( $selected_raw ) ) {
			$selected_raw = array( $selected_raw );
		}

		if ( ! is_array( $available_raw ) ) {
			$available_raw = array( $available_raw );
		}

		$selected_ids  = array_map( 'absint', $selected_raw );
		$available_ids = array_map( 'absint', $available_raw );
		$program_id    = absint( $_POST['student_program_id'] ?? 0 );

		if ( $program_id ) {
			$available_ids = array_values(
				array_filter(
					$available_ids,
					function ( $subject_id ) use ( $program_id ) {
						return $program_id === $this->get_subject_program_id( $subject_id );
					}
				)
			);
			$selected_ids  = array_values(
				array_filter(
					$selected_ids,
					function ( $subject_id ) use ( $program_id ) {
						return $program_id === $this->get_subject_program_id( $subject_id );
					}
				)
			);
		} else {
			$available_ids = array();
			$selected_ids  = array();
		}

		return $this->enrollments->sync_student_subject_enrollments(
			absint( $student_user_id ),
			$selected_ids,
			$available_ids,
			array(
				'created_by' => get_current_user_id(),
				'source'     => 'student_record',
			)
		);
	}

	private function delete_student_account() {
		return $this->delete_person_account( 'student' );
	}

	private function force_delete_student_account() {
		if ( ! $this->is_administrator() ) {
			return new \WP_Error( 'slms_student_force_permissions', __( 'Only administrators can force remove student accounts.', 'simple-lms' ) );
		}

		$user_id = absint( $_POST['person_user_id'] ?? 0 );

		if ( ! $user_id ) {
			return new \WP_Error( 'slms_missing_person', __( 'A valid student user was not provided.', 'simple-lms' ) );
		}

		if ( empty( $_POST['force_remove_student_confirm'] ) ) {
			return new \WP_Error( 'slms_student_force_confirmation', __( 'Please confirm force removal before permanently deleting this student.', 'simple-lms' ) );
		}

		$reassign_user_id = $this->get_delete_reassign_user_id( $user_id );

		if ( ! $reassign_user_id ) {
			return new \WP_Error( 'slms_no_reassign_user', __( 'No fallback account is available to receive authored content. Create another administrator or officer first.', 'simple-lms' ) );
		}

		return $this->student_removal->force_remove_student( $user_id, $reassign_user_id );
	}

	private function complete_student_program() {
		if ( ! $this->is_full_staff() ) {
			return new \WP_Error( 'slms_program_completion_permissions', __( 'You do not have permission to complete student programs.', 'simple-lms' ) );
		}

		$user_id = absint( $_POST['person_user_id'] ?? 0 );
		$result  = sanitize_key( wp_unslash( $_POST['program_completion_result'] ?? '' ) );
		$date    = sanitize_text_field( wp_unslash( $_POST['program_completion_date'] ?? '' ) );
		$notes   = sanitize_textarea_field( wp_unslash( $_POST['program_completion_notes'] ?? '' ) );

		if ( ! $user_id ) {
			return new \WP_Error( 'slms_missing_student', __( 'A valid student was not provided.', 'simple-lms' ) );
		}

		if ( ! in_array( $result, array( 'pass', 'fail' ), true ) ) {
			return new \WP_Error( 'slms_missing_completion_result', __( 'Please choose Pass or Fail before completing the program.', 'simple-lms' ) );
		}

		$profile = $this->profiles->get_profile( $user_id );
		if ( empty( $profile ) || 'student' !== ( $profile['person_type'] ?? '' ) ) {
			return new \WP_Error( 'slms_invalid_student_record', __( 'The selected student record could not be found.', 'simple-lms' ) );
		}

		$completion_date = $date ? AcademicClock::format_local( $date, 'Y-m-d' ) : AcademicClock::date( 'Y-m-d' );
		if ( ! $completion_date || '1970-01-01' === $completion_date ) {
			$completion_date = AcademicClock::date( 'Y-m-d' );
		}

		global $wpdb;
		$now     = AcademicClock::mysql();
		$updated = $wpdb->update(
			Schema::table( 'user_profiles' ),
			array(
				'status'     => 'completed',
				'updated_by' => get_current_user_id(),
				'updated_at' => $now,
			),
			array( 'user_id' => $user_id ),
			array( '%s', '%d', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new \WP_Error( 'slms_program_completion_failed', __( 'The student profile could not be marked as completed.', 'simple-lms' ) );
		}

		$closed = 0;
		if ( ! empty( $_POST['close_active_enrollments'] ) ) {
			$closed_result = $this->enrollments->complete_active_student_enrollments( $user_id, get_current_user_id(), $notes );
			if ( is_wp_error( $closed_result ) ) {
				return $closed_result;
			}
			$closed = (int) $closed_result;
		}

		update_user_meta( $user_id, '_slms_program_completion_status', 'completed' );
		update_user_meta( $user_id, '_slms_program_completion_result', $result );
		update_user_meta( $user_id, '_slms_program_completion_date', $completion_date );
		update_user_meta( $user_id, '_slms_program_completion_notes', $notes );
		update_user_meta( $user_id, '_slms_program_completion_by', get_current_user_id() );
		update_user_meta( $user_id, '_slms_program_completion_closed_enrollments', $closed );
		update_user_meta( $user_id, '_slms_program_completion_recorded_at', $now );

		return true;
	}

	private function delete_staff_account() {
		return $this->delete_person_account( 'staff' );
	}

	private function update_person_account( $person_type ) {
		if ( ! $this->is_full_staff() ) {
			return new \WP_Error( 'slms_person_permissions', __( 'You do not have permission to update people records.', 'simple-lms' ) );
		}

		$user_id = absint( $_POST['person_user_id'] ?? 0 );
		if ( ! $user_id ) {
			return new \WP_Error( 'slms_missing_person', __( 'A valid user was not provided.', 'simple-lms' ) );
		}

		$profile = $this->profiles->get_profile( $user_id );
		if ( empty( $profile ) || $person_type !== ( $profile['person_type'] ?? '' ) ) {
			return new \WP_Error( 'slms_invalid_person_record', __( 'The selected record could not be found.', 'simple-lms' ) );
		}

		$full_name = sanitize_text_field( wp_unslash( $_POST['person_full_name'] ?? '' ) );
		$email     = sanitize_email( wp_unslash( $_POST['person_email'] ?? '' ) );

		if ( '' === $full_name || '' === $email ) {
			return new \WP_Error( 'slms_missing_person_fields', __( 'Full name and email are required.', 'simple-lms' ) );
		}

		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'slms_invalid_person_email', __( 'Invalid email address.', 'simple-lms' ) );
		}

		$existing_user = get_user_by( 'email', $email );
		if ( $existing_user && (int) $existing_user->ID !== $user_id ) {
			return new \WP_Error( 'slms_duplicate_person_email', __( 'That email address is already in use.', 'simple-lms' ) );
		}

		$name_parts = preg_split( '/\s+/', trim( $full_name ), 2 );
		$first_name = $name_parts[0] ?? '';
		$last_name  = $name_parts[1] ?? '';

		$user_args = array(
			'ID'           => $user_id,
			'display_name' => $full_name,
			'first_name'   => $first_name,
			'last_name'    => $last_name,
			'user_email'   => $email,
		);

		if ( 'staff' === $person_type ) {
			$staff_role     = ProvisioningService::normalize_staff_role( wp_unslash( $_POST['staff_role'] ?? 'lecturer' ) );
			$current_user   = get_userdata( $user_id );
			$current_roles  = $current_user ? (array) $current_user->roles : array();
			$allowed_roles  = ProvisioningService::STAFF_ROLES;

			if ( ! current_user_can( 'manage_options' ) && ( 'administrator' === $staff_role || in_array( 'administrator', $current_roles, true ) ) ) {
				return new \WP_Error( 'slms_administrator_role_restricted', __( 'Only site administrators can assign or modify administrator accounts.', 'simple-lms' ) );
			}

			// Preserve an existing legacy Editor assignment without allowing new academic Editor accounts.
			if ( in_array( 'editor', $current_roles, true ) ) {
				$allowed_roles[] = 'editor';
			}

			if ( ! in_array( $staff_role, $allowed_roles, true ) ) {
				return new \WP_Error( 'slms_invalid_staff_role', __( 'Invalid staff role.', 'simple-lms' ) );
			}

			$user_args['role'] = $staff_role;
		} else {
			$user_args['role'] = 'student';
		}

		$password_update = $this->prepare_person_password_update( $user_id );
		if ( is_wp_error( $password_update ) ) {
			return $password_update;
		}

		if ( ! empty( $password_update['changed'] ) ) {
			$user_args['user_pass'] = $password_update['password'];
		}

		$user_result = wp_update_user( $user_args );
		if ( is_wp_error( $user_result ) ) {
			return $user_result;
		}

		if ( ! empty( $password_update['changed'] ) ) {
			$this->apply_person_password_reset_policy( $user_id, $password_update['password'] );
		}

		$profile_result = $this->profiles->upsert_profile(
			$user_id,
			array(
				'person_type'    => $person_type,
				'person_code'    => sanitize_text_field( wp_unslash( $_POST['person_code'] ?? '' ) ),
				'nrc_number'     => sanitize_text_field( wp_unslash( $_POST['person_nrc_number'] ?? '' ) ),
				'status'         => sanitize_key( wp_unslash( $_POST['person_status'] ?? 'active' ) ),
				'phone'          => sanitize_text_field( wp_unslash( $_POST['person_phone'] ?? '' ) ),
				'program_id'     => 'student' === $person_type ? absint( $_POST['student_program_id'] ?? 0 ) : 0,
				'intake_term_id' => 'student' === $person_type ? absint( $_POST['student_intake_term_id'] ?? 0 ) : 0,
				'department'     => 'staff' === $person_type ? sanitize_text_field( wp_unslash( $_POST['staff_department'] ?? '' ) ) : '',
				'position_title' => 'staff' === $person_type ? sanitize_text_field( wp_unslash( $_POST['staff_position_title'] ?? '' ) ) : '',
				'notes'          => sanitize_textarea_field( wp_unslash( $_POST['person_notes'] ?? '' ) ),
			)
		);

		if ( is_wp_error( $profile_result ) ) {
			return $profile_result;
		}

		if ( 'student' === $person_type ) {
			$sync_result = $this->sync_student_subjects_from_request( $user_id );

			if ( is_wp_error( $sync_result ) ) {
				return $sync_result;
			}

			if ( ! empty( $sync_result['failed'] ) ) {
				return new \WP_Error(
					'slms_student_subject_sync_failed',
					! empty( $sync_result['errors'] ) ? implode( ' ', array_slice( $sync_result['errors'], 0, 3 ) ) : __( 'One or more subject enrollment changes could not be saved.', 'simple-lms' )
				);
			}
		}

		return $user_id;
	}

	private function prepare_person_password_update( $user_id ) {
		$new_password     = isset( $_POST['person_new_password'] ) ? (string) wp_unslash( $_POST['person_new_password'] ) : '';
		$confirm_password = isset( $_POST['person_confirm_password'] ) ? (string) wp_unslash( $_POST['person_confirm_password'] ) : '';

		if ( '' === $new_password && '' === $confirm_password ) {
			return array( 'changed' => false );
		}

		if ( get_current_user_id() === absint( $user_id ) ) {
			return new \WP_Error( 'slms_password_self_update_blocked', __( 'For account safety, change your own password from your profile or password screen instead of this people-management form.', 'simple-lms' ) );
		}

		if ( '' === $new_password || '' === $confirm_password ) {
			return new \WP_Error( 'slms_password_missing_confirmation', __( 'Enter and confirm the new password, or leave both password fields blank to keep the current password.', 'simple-lms' ) );
		}

		if ( $new_password !== $confirm_password ) {
			return new \WP_Error( 'slms_password_mismatch', __( 'The new password and confirmation do not match.', 'simple-lms' ) );
		}

		if ( strlen( $new_password ) < 8 ) {
			return new \WP_Error( 'slms_password_too_short', __( 'The new password must be at least 8 characters long.', 'simple-lms' ) );
		}

		return array(
			'changed'  => true,
			'password' => $new_password,
		);
	}

	private function apply_person_password_reset_policy( $user_id, $password ) {
		$user_id = absint( $user_id );

		if ( ProvisioningService::DEFAULT_INITIAL_PASSWORD === (string) $password ) {
			update_user_meta( $user_id, 'slms_force_password_reset', 1 );
			update_user_option( $user_id, 'default_password_nag', 1, true );
			return;
		}

		delete_user_meta( $user_id, 'slms_force_password_reset' );
		delete_user_option( $user_id, 'default_password_nag', true );
	}

	private function delete_person_account( $person_type ) {
		if ( ! $this->is_full_staff() ) {
			return new \WP_Error( 'slms_person_permissions', __( 'You do not have permission to remove people records.', 'simple-lms' ) );
		}

		$user_id = absint( $_POST['person_user_id'] ?? 0 );
		if ( ! $user_id ) {
			return new \WP_Error( 'slms_missing_person', __( 'A valid user was not provided.', 'simple-lms' ) );
		}

		if ( get_current_user_id() === $user_id ) {
			return new \WP_Error( 'slms_remove_self', __( 'You cannot remove your own account from this screen.', 'simple-lms' ) );
		}

		$profile = $this->profiles->get_profile( $user_id );
		if ( empty( $profile ) || $person_type !== ( $profile['person_type'] ?? '' ) ) {
			return new \WP_Error( 'slms_invalid_person_record', __( 'The selected record could not be found.', 'simple-lms' ) );
		}

		if ( 'staff' === $person_type && 'administrator' === RoleManager::get_primary_role( $user_id ) && ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'slms_administrator_role_restricted', __( 'Only site administrators can remove administrator accounts.', 'simple-lms' ) );
		}

		if ( 'staff' === $person_type ) {
			$deletion_check = $this->validate_staff_deletion( $user_id );
		} else {
			$deletion_check = $this->validate_student_deletion( $user_id );
		}

		if ( is_wp_error( $deletion_check ) ) {
			return $deletion_check;
		}

		$reassign_user_id = $this->get_delete_reassign_user_id( $user_id );

		if ( ! $reassign_user_id ) {
			return new \WP_Error( 'slms_no_reassign_user', __( 'No fallback account is available to receive authored content. Create another administrator or officer first.', 'simple-lms' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/user.php';

		$deleted = wp_delete_user( $user_id, $reassign_user_id );

		if ( ! $deleted ) {
			return new \WP_Error( 'slms_person_delete_failed', __( 'The account could not be removed.', 'simple-lms' ) );
		}

		global $wpdb;
		$wpdb->delete(
			Schema::table( 'user_profiles' ),
			array( 'user_id' => $user_id ),
			array( '%d' )
		);

		return true;
	}

	private function run_import() {
		$target    = sanitize_key( wp_unslash( $_POST['import_target'] ?? '' ) );
		$sheet_url = esc_url_raw( wp_unslash( $_POST['import_sheet_url'] ?? '' ) );
		$rows      = $this->imports->read_rows_from_request( $sheet_url, 'import_file' );

		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		if ( 'students' === $target ) {
			return $this->imports->import_students( $rows );
		}

		if ( 'staff' === $target ) {
			return $this->imports->import_staff( $rows );
		}

		if ( 'subjects' === $target ) {
			return $this->imports->import_subjects( $rows );
		}

		return new \WP_Error( 'slms_invalid_import_target', __( 'Please choose a valid import target.', 'simple-lms' ) );
	}

	private function get_academic_sections() {
		return array(
			'programs' => array(
				'label'       => __( 'Programs', 'simple-lms' ),
				'description' => __( 'Create and edit the program catalogue.', 'simple-lms' ),
			),
			'terms'    => array(
				'label'       => __( 'Terms', 'simple-lms' ),
				'description' => __( 'Maintain term names, dates, and statuses.', 'simple-lms' ),
			),
			'subjects' => array(
				'label'       => __( 'Subjects', 'simple-lms' ),
				'description' => __( 'Manage offerings, assignments, and lecturer setup.', 'simple-lms' ),
			),
			'students' => array(
				'label'       => __( 'Students', 'simple-lms' ),
				'description' => __( 'Create, search, and edit student records.', 'simple-lms' ),
			),
			'staff'    => array(
				'label'       => __( 'Staff', 'simple-lms' ),
				'description' => __( 'Create, search, and edit staff records.', 'simple-lms' ),
			),
		);
	}

	private function get_academic_section() {
		$section  = sanitize_key( wp_unslash( $_GET['slms_manage'] ?? '' ) );
		$sections = $this->get_academic_sections();

		if ( isset( $sections[ $section ] ) ) {
			return $section;
		}

		return 'overview';
	}

	private function get_academic_base_url() {
		return remove_query_arg(
			array(
				'slms_notice',
				'slms_notice_type',
				'slms_open',
				'slms_people_search',
				'slms_people_program',
				'slms_people_intake_year',
				'slms_people_sort',
				'slms_students_page',
				'slms_staff_role',
				'slms_staff_sort',
				'slms_staff_page',
				'slms_subject_program',
				'slms_subject_term',
				'slms_subject_sort',
			),
			$this->get_current_page_url()
		);
	}

	private function get_academic_section_url( $page_url, $section = 'overview', array $extra_args = array() ) {
		$url = remove_query_arg(
			array(
				'slms_manage',
				'slms_people_search',
				'slms_people_program',
				'slms_people_intake_year',
				'slms_people_sort',
				'slms_students_page',
				'slms_staff_role',
				'slms_staff_sort',
				'slms_staff_page',
				'slms_subject_program',
				'slms_subject_term',
				'slms_subject_sort',
				'slms_open',
			),
			$page_url
		);

		$args = array( 'slms_view' => 'academic-management' );
		if ( 'overview' !== $section ) {
			$args['slms_manage'] = $section;
		}

		if ( ! empty( $extra_args ) ) {
			$args = array_merge( $args, $extra_args );
		}

		return add_query_arg( $args, $url );
	}

	private function render_academic_section_nav( $page_url, $current_section, array $section_counts ) {
		$sections = $this->get_academic_sections();
		?>
		<div class="slms-hub-chip-row slms-academic-section-nav">
			<a class="slms-academic-nav-link is-overview<?php echo 'overview' === $current_section ? ' is-active' : ''; ?>" href="<?php echo esc_url( $this->get_academic_section_url( $page_url, 'overview' ) ); ?>">
				<span><?php esc_html_e( 'Overview', 'simple-lms' ); ?></span>
			</a>
			<?php foreach ( $sections as $section_key => $section_config ) : ?>
				<a class="slms-academic-nav-link is-<?php echo esc_attr( sanitize_html_class( $section_key ) ); ?><?php echo $current_section === $section_key ? ' is-active' : ''; ?>" href="<?php echo esc_url( $this->get_academic_section_url( $page_url, $section_key ) ); ?>">
					<span><?php echo esc_html( $section_config['label'] ); ?></span>
					<strong class="slms-academic-nav-count"><?php echo esc_html( (string) ( $section_counts[ $section_key ] ?? 0 ) ); ?></strong>
				</a>
			<?php endforeach; ?>
		</div>
		<?php
	}

	private function render_academic_management_section( $current_section, $page_url, array $terms, array $programs, array $subjects, array $lecturers, array $section_counts ) {
		switch ( $current_section ) {
			case 'programs':
				$this->render_academic_programs_page( $programs );
				return;

			case 'terms':
				$this->render_academic_terms_page( $terms );
				return;

			case 'subjects':
				$this->render_academic_subjects_page( $terms, $programs, $subjects, $lecturers );
				return;

			case 'students':
				$this->render_academic_students_page( $page_url, $terms, $programs );
				return;

			case 'staff':
				$this->render_academic_staff_page( $page_url );
				return;

			case 'overview':
			default:
				$this->render_academic_management_overview( $page_url, $section_counts );
				return;
		}
	}

	private function render_academic_management_overview( $page_url, array $section_counts ) {
		$sections = $this->get_academic_sections();
		?>
		<div class="slms-workspace-card-grid">
			<?php foreach ( $sections as $section_key => $section_config ) : ?>
				<a class="slms-workspace-card is-<?php echo esc_attr( sanitize_html_class( $section_key ) ); ?>" href="<?php echo esc_url( $this->get_academic_section_url( $page_url, $section_key ) ); ?>">
					<div class="slms-workspace-card-headline">
						<span class="slms-workspace-card-icon dashicons <?php echo esc_attr( $this->get_management_dashicon_class( $section_key ) ); ?>" aria-hidden="true"></span>
						<div class="slms-workspace-card-title-stack">
							<h3><?php echo esc_html( $section_config['label'] ); ?></h3>
						</div>
					</div>
					<p><?php echo esc_html( $section_config['description'] ); ?></p>
					<span class="slms-workspace-card-meta"><?php echo esc_html( sprintf( _n( '%d record', '%d records', (int) ( $section_counts[ $section_key ] ?? 0 ), 'simple-lms' ), (int) ( $section_counts[ $section_key ] ?? 0 ) ) ); ?></span>
				</a>
			<?php endforeach; ?>
		</div>
		<?php
	}

	private function render_academic_programs_page( array $programs ) {
		$action_items = array(
			array(
				'key'         => 'add-program',
				'title'       => __( 'Add Program', 'simple-lms' ),
				'description' => __( 'Open to add a new program to the academic catalog.', 'simple-lms' ),
				'icon'        => 'programs',
				'content'     => $this->capture_management_action_markup(
					function () {
						$this->render_program_creation_form();
					}
				),
			),
		);
		?>
		<div class="slms-tool-stack">
			<section class="slms-portal-panel slms-academic-page-intro">
				<div class="slms-section-heading">
					<div>
						<h2><?php esc_html_e( 'Programs', 'simple-lms' ); ?></h2>
						<p class="slms-field-help"><?php esc_html_e( 'Create new programs and update each individual program record from one dedicated page.', 'simple-lms' ); ?></p>
					</div>
				</div>
			</section>
			<?php $this->render_management_action_group( 'programs', $action_items ); ?>
			<?php $this->render_program_records_panel( $programs ); ?>
		</div>
		<?php
	}

	private function render_academic_terms_page( array $terms ) {
		$action_items = array(
			array(
				'key'         => 'add-term',
				'title'       => __( 'Add Term', 'simple-lms' ),
				'description' => __( 'Open to add a term or generate the next academic year terms.', 'simple-lms' ),
				'icon'        => 'terms',
				'content'     => $this->capture_management_action_markup(
					function () {
						$this->render_term_creation_controls();
					}
				),
			),
		);
		?>
		<div class="slms-tool-stack">
			<section class="slms-portal-panel slms-academic-page-intro">
				<div class="slms-section-heading">
					<div>
						<h2><?php esc_html_e( 'Terms', 'simple-lms' ); ?></h2>
						<p class="slms-field-help"><?php esc_html_e( 'Manage academic terms, their date ranges, sort order, and lifecycle status from a single page.', 'simple-lms' ); ?></p>
					</div>
				</div>
			</section>
			<?php $this->render_management_action_group( 'terms', $action_items ); ?>
			<?php $this->render_term_records_panel( $terms ); ?>
		</div>
		<?php
	}

	private function render_academic_subjects_page( array $terms, array $programs, array $subjects, array $lecturers ) {
		$page_url         = $this->get_academic_base_url();
		$subject_filters  = $this->get_subject_management_filters();
		$total_subjects    = count( $subjects );
		$filtered_subjects = $this->filter_subjects_for_management( $subjects, $subject_filters );
		$filtered_subjects = $this->sort_subjects_for_management( $filtered_subjects, $subject_filters['sort'], $terms, $programs );
		$action_items      = array(
			array(
				'key'         => 'create-subject',
				'title'       => __( 'Create Subject Offering', 'simple-lms' ),
				'description' => __( 'Open to create a subject offering or duplicate an existing subject into another term.', 'simple-lms' ),
				'icon'        => 'subjects',
				'content'     => $this->capture_management_action_markup(
					function () use ( $terms, $programs, $lecturers, $subjects ) {
						$this->render_subject_creation_card( $terms, $programs, $lecturers, $subjects, true );
					}
				),
			),
			array(
				'key'         => 'duplicate-subjects',
				'title'       => __( 'Duplicate Subjects', 'simple-lms' ),
				'description' => __( 'Open to duplicate several subjects into a new term while keeping materials and welcome posts only.', 'simple-lms' ),
				'icon'        => 'subjects',
				'content'     => $this->capture_management_action_markup(
					function () use ( $terms, $subjects ) {
						$this->render_bulk_subject_duplication_card( $terms, $subjects, true );
					}
				),
			),
			array(
				'key'         => 'import-subjects',
				'title'       => __( 'Import Subjects', 'simple-lms' ),
				'description' => __( 'Open to import subject offerings from CSV, spreadsheet, or Google Sheet.', 'simple-lms' ),
				'icon'        => 'import',
				'content'     => $this->capture_management_action_markup(
					function () {
						$this->render_targeted_import_management_card( 'subjects', true );
					}
				),
			),
		);
		?>
		<div class="slms-tool-stack">
			<section class="slms-portal-panel slms-academic-page-intro">
				<h2><?php esc_html_e( 'Subjects', 'simple-lms' ); ?></h2>
				<p class="slms-field-help"><?php esc_html_e( 'Create new subject offerings, duplicate them into future terms, and edit each subject record from the frontend.', 'simple-lms' ); ?></p>
			</section>
			<?php $this->render_management_action_group( 'subjects', $action_items ); ?>
			<?php $this->render_subject_directory_controls( $page_url, $terms, $programs, $subject_filters, count( $filtered_subjects ), $total_subjects ); ?>
			<?php $this->render_subject_management_card( $filtered_subjects, $terms, $programs, $lecturers ); ?>
		</div>
		<?php
	}

	private function render_academic_students_page( $page_url, array $terms, array $programs ) {
		$search          = sanitize_text_field( wp_unslash( $_GET['slms_people_search'] ?? '' ) );
		$current_page    = max( 1, absint( $_GET['slms_students_page'] ?? 1 ) );
		$student_filters = $this->get_student_management_filters();
		$query_args      = array(
			'person_type' => 'student',
			'page'        => $current_page,
			'per_page'    => 12,
			'orderby'     => $student_filters['sort'],
		);
		$count_args      = array( 'person_type' => 'student' );

		if ( '' !== $search ) {
			$query_args['search'] = $search;
			$count_args['search'] = $search;
		}

		if ( ! empty( $student_filters['program_id'] ) ) {
			$query_args['program_id'] = $student_filters['program_id'];
			$count_args['program_id'] = $student_filters['program_id'];
		}

		if ( '' !== $student_filters['intake_academic_year'] ) {
			$query_args['intake_academic_year'] = $student_filters['intake_academic_year'];
			$count_args['intake_academic_year'] = $student_filters['intake_academic_year'];
		}

		if ( ! empty( $student_filters['status'] ) ) {
			$query_args['status'] = $student_filters['status'];
			$count_args['status'] = $student_filters['status'];
		}

		$students        = $this->profiles->list_profiles( $query_args );
		$total_matches   = $this->profiles->count_profiles_matching( $count_args );
		$max_pages       = max( 1, (int) ceil( $total_matches / 12 ) );
		$subject_options = $this->get_student_subject_options( $terms, $programs );
		$action_items    = array(
			array(
				'key'         => 'create-student',
				'title'       => __( 'Create Student', 'simple-lms' ),
				'description' => __( 'Open to add a student account and LMS profile.', 'simple-lms' ),
				'icon'        => 'students',
				'content'     => $this->capture_management_action_markup(
					function () use ( $terms, $programs ) {
						$this->render_student_creation_card( $terms, $programs, true );
					}
				),
			),
			array(
				'key'         => 'import-students',
				'title'       => __( 'Import Students', 'simple-lms' ),
				'description' => __( 'Open to import student accounts from CSV, spreadsheet, or Google Sheet.', 'simple-lms' ),
				'icon'        => 'import',
				'content'     => $this->capture_management_action_markup(
					function () {
						$this->render_targeted_import_management_card( 'students', true );
					}
				),
			),
			array(
				'key'         => 'student-directory',
				'title'       => __( 'Student Directory', 'simple-lms' ),
				'description' => __( 'Open to search, filter, and sort the student directory.', 'simple-lms' ),
				'icon'        => 'directory',
				'content'     => $this->capture_management_action_markup(
					function () use ( $page_url, $search, $current_page, $max_pages, $total_matches, $student_filters, $terms, $programs ) {
						$this->render_student_directory_controls( $page_url, $search, $current_page, $max_pages, $total_matches, $student_filters, $terms, $programs );
					}
				),
			),
			array(
				'key'         => 'download-students',
				'title'       => __( 'Download Info', 'simple-lms' ),
				'description' => __( 'Export student information in CSV format for all records or the selected filters.', 'simple-lms' ),
				'icon'        => 'download',
				'content'     => $this->capture_management_action_markup(
					function () use ( $search, $student_filters, $terms, $programs ) {
						$this->render_student_export_controls( $search, $student_filters, $terms, $programs );
					}
				),
			),
		);
		?>
		<div class="slms-tool-stack">
			<?php $this->render_management_action_group( 'students', $action_items ); ?>

			<section class="slms-portal-panel">
				<div class="slms-section-heading">
					<div>
						<h2><?php esc_html_e( 'Students', 'simple-lms' ); ?></h2>
						<p class="slms-field-help"><?php esc_html_e( 'Each student opens as its own editable record so administrators and officers can maintain accounts and subject enrollment without leaving the frontend.', 'simple-lms' ); ?></p>
					</div>
					<span class="slms-status-pill"><?php echo esc_html( sprintf( _n( '%d student', '%d students', $total_matches, 'simple-lms' ), $total_matches ) ); ?></span>
				</div>
				<div class="slms-record-stack">
					<?php if ( empty( $students ) ) : ?>
						<div class="slms-empty-state">
							<h3><?php esc_html_e( 'No student records found', 'simple-lms' ); ?></h3>
							<p><?php esc_html_e( 'Adjust the search, program, intake year, or create a new student account from the panel above.', 'simple-lms' ); ?></p>
						</div>
					<?php else : ?>
						<?php foreach ( $students as $student ) : ?>
							<?php $this->render_student_record_card( $student, $terms, $programs, $subject_options ); ?>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>
				<?php $this->render_pagination_controls( 'slms_students_page', $current_page, $max_pages ); ?>
			</section>
		</div>
		<?php
	}

	private function render_academic_staff_page( $page_url ) {
		$search        = sanitize_text_field( wp_unslash( $_GET['slms_people_search'] ?? '' ) );
		$current_page  = max( 1, absint( $_GET['slms_staff_page'] ?? 1 ) );
		$staff_filters = $this->get_staff_management_filters();
		$query_args    = array(
			'person_type' => 'staff',
			'page'        => $current_page,
			'per_page'    => 12,
			'orderby'     => $staff_filters['sort'],
		);
		$count_args    = array( 'person_type' => 'staff' );

		if ( '' !== $search ) {
			$query_args['search'] = $search;
			$count_args['search'] = $search;
		}

		if ( '' !== $staff_filters['role'] ) {
			$query_args['role'] = $staff_filters['role'];
			$count_args['role'] = $staff_filters['role'];
		}

		$staff         = $this->profiles->list_profiles( $query_args );
		$total_matches = $this->profiles->count_profiles_matching( $count_args );
		$max_pages     = max( 1, (int) ceil( $total_matches / 12 ) );
		$action_items  = array(
			array(
				'key'         => 'create-staff',
				'title'       => __( 'Create Staff Account', 'simple-lms' ),
				'description' => __( 'Open to add general staff, lecturers, and administrative staff from the frontend.', 'simple-lms' ),
				'icon'        => 'staff',
				'content'     => $this->capture_management_action_markup(
					function () {
						$this->render_staff_creation_card( true );
					}
				),
			),
			array(
				'key'         => 'import-staff',
				'title'       => __( 'Import Staff', 'simple-lms' ),
				'description' => __( 'Open to import staff accounts from CSV, spreadsheet, or Google Sheet.', 'simple-lms' ),
				'icon'        => 'import',
				'content'     => $this->capture_management_action_markup(
					function () {
						$this->render_targeted_import_management_card( 'staff', true );
					}
				),
			),
			array(
				'key'         => 'staff-directory',
				'title'       => __( 'Staff Directory', 'simple-lms' ),
				'description' => __( 'Open to search, filter, and sort staff records by role.', 'simple-lms' ),
				'icon'        => 'directory',
				'content'     => $this->capture_management_action_markup(
					function () use ( $page_url, $search, $current_page, $max_pages, $total_matches, $staff_filters ) {
						$this->render_staff_directory_controls( $page_url, $search, $current_page, $max_pages, $total_matches, $staff_filters );
					}
				),
			),
			array(
				'key'         => 'download-staff',
				'title'       => __( 'Download Info', 'simple-lms' ),
				'description' => __( 'Export staff information in CSV format for all records or the selected filters.', 'simple-lms' ),
				'icon'        => 'download',
				'content'     => $this->capture_management_action_markup(
					function () use ( $search, $staff_filters ) {
						$this->render_staff_export_controls( $search, $staff_filters );
					}
				),
			),
		);
		?>
		<div class="slms-tool-stack">
			<?php $this->render_management_action_group( 'staff', $action_items ); ?>

			<section class="slms-portal-panel">
				<div class="slms-section-heading">
					<div>
						<h2><?php esc_html_e( 'Staff', 'simple-lms' ); ?></h2>
						<p class="slms-field-help"><?php esc_html_e( 'Each staff record can be opened and edited from the frontend, including role, department, title, status, and contact details.', 'simple-lms' ); ?></p>
					</div>
					<span class="slms-status-pill"><?php echo esc_html( sprintf( _n( '%d staff member', '%d staff members', $total_matches, 'simple-lms' ), $total_matches ) ); ?></span>
				</div>
				<div class="slms-record-stack">
					<?php if ( empty( $staff ) ) : ?>
						<div class="slms-empty-state">
							<h3><?php esc_html_e( 'No staff records found', 'simple-lms' ); ?></h3>
							<p><?php esc_html_e( 'Adjust the search, role filter, or create a new staff account from the panel above.', 'simple-lms' ); ?></p>
						</div>
					<?php else : ?>
						<?php foreach ( $staff as $staff_profile ) : ?>
							<?php $this->render_staff_record_card( $staff_profile ); ?>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>
				<?php $this->render_pagination_controls( 'slms_staff_page', $current_page, $max_pages ); ?>
			</section>
		</div>
		<?php
	}

	private function get_subject_management_filters() {
		$sort = sanitize_key( wp_unslash( $_GET['slms_subject_sort'] ?? 'program_term' ) );

		if ( ! in_array( $sort, array( 'program_term', 'term_program', 'title' ), true ) ) {
			$sort = 'program_term';
		}

		return array(
			'program_id' => absint( $_GET['slms_subject_program'] ?? 0 ),
			'term_id'    => absint( $_GET['slms_subject_term'] ?? 0 ),
			'sort'       => $sort,
		);
	}

	private function filter_subjects_for_management( array $subjects, array $filters ) {
		$program_id = absint( $filters['program_id'] ?? 0 );
		$term_id    = absint( $filters['term_id'] ?? 0 );

		return array_values(
			array_filter(
				$subjects,
				function ( $subject ) use ( $program_id, $term_id ) {
					if ( $program_id && $program_id !== $this->get_subject_program_id( $subject->ID ) ) {
						return false;
					}

					if ( $term_id && $term_id !== absint( get_post_meta( $subject->ID, '_slms_term_id', true ) ) ) {
						return false;
					}

					return true;
				}
			)
		);
	}

	private function sort_subjects_for_management( array $subjects, $sort, array $terms, array $programs ) {
		usort(
			$subjects,
			function ( $left, $right ) use ( $sort, $terms, $programs ) {
				$left_program  = $this->get_program_label( $this->get_subject_program_id( $left->ID ), $programs );
				$right_program = $this->get_program_label( $this->get_subject_program_id( $right->ID ), $programs );
				$left_term     = $this->get_subject_term_sort_key( absint( get_post_meta( $left->ID, '_slms_term_id', true ) ), $terms );
				$right_term    = $this->get_subject_term_sort_key( absint( get_post_meta( $right->ID, '_slms_term_id', true ) ), $terms );
				$left_title    = strtolower( $left->post_title );
				$right_title   = strtolower( $right->post_title );

				if ( 'title' === $sort ) {
					return strnatcasecmp( $left_title, $right_title );
				}

				if ( 'term_program' === $sort ) {
					$term_compare = strcmp( $left_term, $right_term );
					if ( 0 !== $term_compare ) {
						return $term_compare;
					}

					$program_compare = strnatcasecmp( $left_program, $right_program );
					if ( 0 !== $program_compare ) {
						return $program_compare;
					}

					return strnatcasecmp( $left_title, $right_title );
				}

				$program_compare = strnatcasecmp( $left_program, $right_program );
				if ( 0 !== $program_compare ) {
					return $program_compare;
				}

				$term_compare = strcmp( $left_term, $right_term );
				if ( 0 !== $term_compare ) {
					return $term_compare;
				}

				return strnatcasecmp( $left_title, $right_title );
			}
		);

		return $subjects;
	}

	private function get_student_management_filters() {
		$sort   = sanitize_key( wp_unslash( $_GET['slms_people_sort'] ?? 'program_intake' ) );
		$status = sanitize_key( wp_unslash( $_GET['slms_people_status'] ?? '' ) );

		if ( ! in_array( $sort, array( 'program_intake', 'intake_program', 'name', 'updated' ), true ) ) {
			$sort = 'program_intake';
		}

		if ( '' !== $status && ! in_array( $status, ProfileService::STATUSES, true ) ) {
			$status = '';
		}

		return array(
			'program_id'           => absint( $_GET['slms_people_program'] ?? 0 ),
			'intake_academic_year' => sanitize_text_field( wp_unslash( $_GET['slms_people_intake_year'] ?? '' ) ),
			'status'               => $status,
			'sort'                 => $sort,
		);
	}

	private function get_staff_management_filters() {
		$sort = sanitize_key( wp_unslash( $_GET['slms_staff_sort'] ?? 'role' ) );
		$role = ProvisioningService::normalize_staff_role( wp_unslash( $_GET['slms_staff_role'] ?? '' ) );

		if ( ! in_array( $sort, array( 'role', 'name', 'updated' ), true ) ) {
			$sort = 'role';
		}

		if ( '' !== $role && ! array_key_exists( $role, $this->get_staff_role_filter_options() ) ) {
			$role = '';
		}

		return array(
			'role' => $role,
			'sort' => $sort,
		);
	}

	private function get_staff_role_filter_options() {
		return array(
			'administrator' => __( 'Administrator', 'simple-lms' ),
			'officer'       => __( 'Officer', 'simple-lms' ),
			'lecturer'      => __( 'Lecturer', 'simple-lms' ),
			RoleManager::ROLE_STAFF => __( 'Staff', 'simple-lms' ),
		);
	}

	private function get_intake_year_options( array $terms ) {
		$years = array();

		foreach ( $terms as $term ) {
			$year = trim( (string) ( $term['academic_year'] ?? '' ) );

			if ( '' !== $year ) {
				$years[ $year ] = $year;
			}
		}

		rsort( $years, SORT_NATURAL );

		return $years;
	}

	private function get_student_subject_options( array $terms, array $programs ) {
		$subjects = get_posts(
			array(
				'post_type'      => 'slms_subject',
				'post_status'    => array( 'publish', 'private', 'draft', 'pending' ),
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		$subjects = $this->sort_subjects_for_management( $subjects, 'program_term', $terms, $programs );
		$options  = array();

		foreach ( $subjects as $subject ) {
			$program_id = $this->get_subject_program_id( $subject->ID );
			$term_id    = absint( get_post_meta( $subject->ID, '_slms_term_id', true ) );
			$code       = get_post_meta( $subject->ID, '_slms_subject_code', true );

			$options[] = array(
				'id'            => (int) $subject->ID,
				'title'         => $subject->post_title,
				'code'          => $code,
				'program_id'    => $program_id,
				'program_label' => $this->get_program_label( $program_id, $programs ),
				'term_label'    => $this->get_term_label( $term_id, $terms ),
				'status'        => get_post_meta( $subject->ID, '_slms_subject_status', true ) ?: 'active',
			);
		}

		return $options;
	}

	private function get_subject_program_id( $subject_id ) {
		$program_ids = wp_get_object_terms( absint( $subject_id ), 'slms_program', array( 'fields' => 'ids' ) );

		return ! is_wp_error( $program_ids ) && ! empty( $program_ids ) ? absint( $program_ids[0] ) : 0;
	}

	private function get_subject_term_sort_key( $term_id, array $terms ) {
		$term_id = absint( $term_id );

		foreach ( $terms as $term ) {
			if ( absint( $term['id'] ?? 0 ) !== $term_id ) {
				continue;
			}

			$year       = (string) ( $term['academic_year'] ?? '' );
			$sort_order = str_pad( (string) absint( $term['sort_order'] ?? 0 ), 5, '0', STR_PAD_LEFT );
			$name       = strtolower( (string) ( $term['name'] ?? '' ) );

			return sprintf( '%s-%s-%s', $year, $sort_order, $name );
		}

		return 'zzzzz';
	}

	private function render_subject_directory_controls( $page_url, array $terms, array $programs, array $filters, $shown_count, $total_count ) {
		?>
		<section class="slms-portal-panel slms-subpanel slms-subpanel-tight">
			<h3><?php esc_html_e( 'Subject Sorting', 'simple-lms' ); ?></h3>
			<form method="get" action="" class="slms-form-stack slms-directory-search">
				<input type="hidden" name="slms_view" value="academic-management">
				<input type="hidden" name="slms_manage" value="subjects">
				<div class="slms-form-split">
					<label>
						<span><?php esc_html_e( 'Program', 'simple-lms' ); ?></span>
						<select name="slms_subject_program">
							<option value=""><?php esc_html_e( 'All programs', 'simple-lms' ); ?></option>
							<?php foreach ( $programs as $program ) : ?>
								<option value="<?php echo esc_attr( $program->term_id ); ?>" <?php selected( absint( $filters['program_id'] ?? 0 ), (int) $program->term_id ); ?>><?php echo esc_html( $program->name ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label>
						<span><?php esc_html_e( 'Semester / Term', 'simple-lms' ); ?></span>
						<select name="slms_subject_term">
							<option value=""><?php esc_html_e( 'All semesters', 'simple-lms' ); ?></option>
							<?php foreach ( $terms as $term ) : ?>
								<option value="<?php echo esc_attr( $term['id'] ); ?>" <?php selected( absint( $filters['term_id'] ?? 0 ), (int) $term['id'] ); ?>><?php echo esc_html( $term['name'] . ' (' . $term['academic_year'] . ')' ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
				</div>
				<label>
					<span><?php esc_html_e( 'Sort Subjects', 'simple-lms' ); ?></span>
					<select name="slms_subject_sort">
						<option value="program_term" <?php selected( $filters['sort'], 'program_term' ); ?>><?php esc_html_e( 'Program, then semester', 'simple-lms' ); ?></option>
						<option value="term_program" <?php selected( $filters['sort'], 'term_program' ); ?>><?php esc_html_e( 'Semester, then program', 'simple-lms' ); ?></option>
						<option value="title" <?php selected( $filters['sort'], 'title' ); ?>><?php esc_html_e( 'Subject title', 'simple-lms' ); ?></option>
					</select>
				</label>
				<div class="slms-row-actions">
					<button type="submit" class="slms-portal-button is-secondary"><?php esc_html_e( 'Apply Subject Sort', 'simple-lms' ); ?></button>
					<a class="slms-portal-button is-secondary" href="<?php echo esc_url( $this->get_academic_section_url( $page_url, 'subjects' ) ); ?>"><?php esc_html_e( 'Reset', 'simple-lms' ); ?></a>
				</div>
			</form>
			<div class="slms-portal-stat-grid">
				<div class="slms-portal-stat"><span><?php esc_html_e( 'Showing', 'simple-lms' ); ?></span><strong><?php echo esc_html( (string) $shown_count ); ?></strong></div>
				<div class="slms-portal-stat"><span><?php esc_html_e( 'Total Subjects', 'simple-lms' ); ?></span><strong><?php echo esc_html( (string) $total_count ); ?></strong></div>
			</div>
		</section>
		<?php
	}

	private function capture_management_action_markup( callable $renderer ) {
		ob_start();
		$renderer();
		return (string) ob_get_clean();
	}

	private function render_management_action_group( $section, array $items ) {
		if ( empty( $items ) ) {
			return;
		}

		$section_class = sanitize_html_class( $section );
		$group_label   = sprintf( __( '%s actions', 'simple-lms' ), ucwords( str_replace( '-', ' ', $section ) ) );
		?>
		<div class="slms-management-action-group is-<?php echo esc_attr( $section_class ); ?>" data-slms-action-group>
			<div class="slms-management-action-list" role="group" aria-label="<?php echo esc_attr( $group_label ); ?>">
				<?php foreach ( $items as $index => $item ) : ?>
					<?php
					$key        = sanitize_html_class( $item['key'] ?? 'action-' . $index );
					$panel_id   = 'slms-management-panel-' . $section_class . '-' . $key;
					$icon_class = $this->get_management_dashicon_class( $item['icon'] ?? '' );
					?>
					<button type="button" class="slms-action-panel-trigger" data-slms-action-trigger="<?php echo esc_attr( $key ); ?>" aria-expanded="false" aria-controls="<?php echo esc_attr( $panel_id ); ?>">
						<?php if ( $icon_class ) : ?>
							<span class="slms-management-action-icon dashicons <?php echo esc_attr( $icon_class ); ?>" aria-hidden="true"></span>
						<?php endif; ?>
						<span class="slms-action-panel-trigger-label"><?php echo esc_html( $item['title'] ); ?></span>
					</button>
				<?php endforeach; ?>
			</div>
			<div class="slms-management-shared-window" data-slms-action-window hidden>
				<?php foreach ( $items as $index => $item ) : ?>
					<?php
					$key      = sanitize_html_class( $item['key'] ?? 'action-' . $index );
					$panel_id = 'slms-management-panel-' . $section_class . '-' . $key;
					?>
					<section class="slms-management-shared-panel" id="<?php echo esc_attr( $panel_id ); ?>" data-slms-action-panel="<?php echo esc_attr( $key ); ?>" hidden>
						<?php if ( ! empty( $item['description'] ) ) : ?>
							<div class="slms-action-panel-copy">
								<h3><?php echo esc_html( $item['title'] ); ?></h3>
								<p class="slms-field-help"><?php echo esc_html( $item['description'] ); ?></p>
							</div>
						<?php endif; ?>
						<?php echo $item['content']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</section>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	private function get_management_dashicon_class( $key ) {
		$map = array(
			'directory' => 'dashicons-id-alt',
			'download'  => 'dashicons-download',
			'import'    => 'dashicons-upload',
			'programs'  => 'dashicons-screenoptions',
			'staff'     => 'dashicons-businessperson',
			'students'  => 'dashicons-welcome-learn-more',
			'subjects'  => 'dashicons-book-alt',
			'terms'     => 'dashicons-calendar-alt',
		);

		return $map[ $key ] ?? 'dashicons-admin-generic';
	}

	private function render_program_creation_form() {
		?>
		<form method="post" action="" class="slms-form-stack">
			<?php wp_nonce_field( 'slms_academic_tools_action', 'slms_academic_tools_nonce' ); ?>
			<input type="hidden" name="slms_academic_group" value="academics">
			<input type="hidden" name="slms_academic_action" value="create_program">
			<label><span><?php esc_html_e( 'Program Name', 'simple-lms' ); ?></span><input type="text" name="program_name" required></label>
			<div class="slms-form-split">
				<label><span><?php esc_html_e( 'Program Code', 'simple-lms' ); ?></span><input type="text" name="program_code" placeholder="<?php esc_attr_e( 'Required', 'simple-lms' ); ?>" required></label>
				<label><span><?php esc_html_e( 'Slug Preview', 'simple-lms' ); ?></span><input type="text" value="<?php esc_attr_e( 'Generated automatically', 'simple-lms' ); ?>" disabled></label>
			</div>
			<label><span><?php esc_html_e( 'Description', 'simple-lms' ); ?></span><textarea name="program_description" rows="3"></textarea></label>
			<button type="submit" class="slms-portal-button"><?php esc_html_e( 'Add Program', 'simple-lms' ); ?></button>
		</form>
		<?php
	}

	private function render_program_records_panel( array $programs ) {
		?>
		<section class="slms-portal-panel slms-program-records-panel">
			<h2><?php esc_html_e( 'Programs', 'simple-lms' ); ?></h2>
			<div class="slms-record-stack">
				<?php if ( empty( $programs ) ) : ?>
					<p class="slms-field-help"><?php esc_html_e( 'No programs have been created yet.', 'simple-lms' ); ?></p>
				<?php else : ?>
					<?php foreach ( $programs as $program ) : ?>
						<?php $program_code = get_term_meta( $program->term_id, '_slms_program_code', true ); ?>
						<details class="slms-record-card">
							<summary>
								<div>
									<strong><?php echo esc_html( $program->name ); ?></strong>
									<span><?php echo esc_html( $program_code ?: __( 'No code', 'simple-lms' ) ); ?></span>
								</div>
							</summary>
							<div class="slms-record-card-body" data-slms-record-body>
								<div class="slms-record-view" data-slms-record-view>
									<?php
									$this->render_record_facts(
										array(
											array(
												'label' => __( 'Program Name', 'simple-lms' ),
												'value' => $program->name,
											),
											array(
												'label' => __( 'Program Code', 'simple-lms' ),
												'value' => $program_code ?: __( 'Not assigned', 'simple-lms' ),
											),
											array(
												'label' => __( 'Description', 'simple-lms' ),
												'value' => $program->description ?: __( 'No description yet.', 'simple-lms' ),
											),
										)
									);
									?>
									<div class="slms-record-actions">
										<button type="button" class="slms-portal-button is-secondary" data-slms-record-edit-toggle><?php esc_html_e( 'Edit Program', 'simple-lms' ); ?></button>
									</div>
								</div>
								<form method="post" action="" class="slms-inline-editor slms-record-edit-form" data-slms-record-edit-form hidden>
									<?php wp_nonce_field( 'slms_academic_tools_action', 'slms_academic_tools_nonce' ); ?>
									<input type="hidden" name="slms_academic_group" value="academics">
									<input type="hidden" name="slms_academic_action" value="update_program">
									<input type="hidden" name="program_id" value="<?php echo esc_attr( $program->term_id ); ?>">
									<div class="slms-form-split">
										<label><span><?php esc_html_e( 'Name', 'simple-lms' ); ?></span><input type="text" name="program_name" value="<?php echo esc_attr( $program->name ); ?>" required></label>
										<label><span><?php esc_html_e( 'Code', 'simple-lms' ); ?></span><input type="text" name="program_code" value="<?php echo esc_attr( $program_code ); ?>" required></label>
									</div>
									<label><span><?php esc_html_e( 'Description', 'simple-lms' ); ?></span><textarea name="program_description" rows="2"><?php echo esc_textarea( $program->description ); ?></textarea></label>
									<div class="slms-record-actions">
										<button type="submit" class="slms-portal-button is-secondary"><?php esc_html_e( 'Save Program', 'simple-lms' ); ?></button>
										<button type="submit" class="slms-portal-button is-danger" form="slms-delete-program-<?php echo esc_attr( $program->term_id ); ?>" formnovalidate onclick="return confirm('<?php echo esc_js( __( 'Remove this program? This cannot be undone.', 'simple-lms' ) ); ?>');"><?php esc_html_e( 'Remove Program', 'simple-lms' ); ?></button>
										<button type="button" class="slms-portal-button is-secondary" data-slms-record-edit-cancel><?php esc_html_e( 'Cancel', 'simple-lms' ); ?></button>
									</div>
								</form>
								<form method="post" action="" id="slms-delete-program-<?php echo esc_attr( $program->term_id ); ?>" hidden>
									<?php wp_nonce_field( 'slms_academic_tools_action', 'slms_academic_tools_nonce' ); ?>
									<input type="hidden" name="slms_academic_group" value="academics">
									<input type="hidden" name="slms_academic_action" value="delete_program">
									<input type="hidden" name="program_id" value="<?php echo esc_attr( $program->term_id ); ?>">
								</form>
							</div>
						</details>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>
		</section>
		<?php
	}

	private function render_term_creation_controls() {
		$current_year = (int) AcademicClock::date( 'Y' );
		?>
		<form method="post" action="" class="slms-form-stack">
			<?php wp_nonce_field( 'slms_academic_tools_action', 'slms_academic_tools_nonce' ); ?>
			<input type="hidden" name="slms_academic_group" value="academics">
			<input type="hidden" name="slms_academic_action" value="create_term">
			<div class="slms-form-split">
				<label><span><?php esc_html_e( 'Term Name', 'simple-lms' ); ?></span><input type="text" name="term_name" placeholder="<?php esc_attr_e( 'Semester 1', 'simple-lms' ); ?>" required></label>
				<label><span><?php esc_html_e( 'Term Code', 'simple-lms' ); ?></span><input type="text" name="term_code" placeholder="<?php esc_attr_e( 'SEM1-2026', 'simple-lms' ); ?>" required></label>
			</div>
			<div class="slms-form-split">
				<label><span><?php esc_html_e( 'Academic Year', 'simple-lms' ); ?></span><input type="text" name="term_academic_year" value="<?php echo esc_attr( $current_year . '/' . ( $current_year + 1 ) ); ?>" required></label>
				<label><span><?php esc_html_e( 'Sort Order', 'simple-lms' ); ?></span><input type="number" name="term_sort_order" min="0" value="1"></label>
			</div>
			<div class="slms-form-split">
				<label><span><?php esc_html_e( 'Start Date', 'simple-lms' ); ?></span><input type="date" name="term_start_date"></label>
				<label><span><?php esc_html_e( 'End Date', 'simple-lms' ); ?></span><input type="date" name="term_end_date"></label>
			</div>
			<label>
				<span><?php esc_html_e( 'Status', 'simple-lms' ); ?></span>
				<select name="term_status">
					<option value="planned"><?php esc_html_e( 'Planned', 'simple-lms' ); ?></option>
					<option value="active"><?php esc_html_e( 'Active', 'simple-lms' ); ?></option>
					<option value="closed"><?php esc_html_e( 'Closed', 'simple-lms' ); ?></option>
					<option value="archived"><?php esc_html_e( 'Archived', 'simple-lms' ); ?></option>
				</select>
			</label>
			<button type="submit" class="slms-portal-button"><?php esc_html_e( 'Add Term', 'simple-lms' ); ?></button>
		</form>
		<div class="slms-tool-divider"></div>
		<form method="post" action="" class="slms-inline-actions">
			<?php wp_nonce_field( 'slms_academic_tools_action', 'slms_academic_tools_nonce' ); ?>
			<input type="hidden" name="slms_academic_group" value="academics">
			<input type="hidden" name="slms_academic_action" value="generate_terms">
			<button type="submit" class="slms-portal-button is-secondary"><?php esc_html_e( 'Generate Next Academic Year Terms', 'simple-lms' ); ?></button>
		</form>
		<?php
	}

	private function render_term_records_panel( array $terms ) {
		?>
		<section class="slms-portal-panel slms-term-records-panel">
			<h2><?php esc_html_e( 'Terms', 'simple-lms' ); ?></h2>
			<div class="slms-record-stack">
				<?php if ( empty( $terms ) ) : ?>
					<p class="slms-field-help"><?php esc_html_e( 'No terms have been created yet.', 'simple-lms' ); ?></p>
				<?php else : ?>
					<?php foreach ( $terms as $term ) : ?>
						<details class="slms-record-card">
							<summary>
								<div>
									<strong><?php echo esc_html( $term['name'] ); ?></strong>
									<span><?php echo esc_html( $term['academic_year'] ); ?></span>
								</div>
								<span class="slms-status-pill"><?php echo esc_html( ucfirst( $term['status'] ) ); ?></span>
							</summary>
							<div class="slms-record-card-body" data-slms-record-body>
								<div class="slms-record-view" data-slms-record-view>
									<?php
									$this->render_record_facts(
										array(
											array(
												'label' => __( 'Term Code', 'simple-lms' ),
												'value' => $term['code'],
											),
											array(
												'label' => __( 'Academic Year', 'simple-lms' ),
												'value' => $term['academic_year'],
											),
											array(
												'label' => __( 'Sort Order', 'simple-lms' ),
												'value' => (string) ( $term['sort_order'] ?? 0 ),
											),
											array(
												'label' => __( 'Start Date', 'simple-lms' ),
												'value' => $term['start_date'] ?: __( 'Not set', 'simple-lms' ),
											),
											array(
												'label' => __( 'End Date', 'simple-lms' ),
												'value' => $term['end_date'] ?: __( 'Not set', 'simple-lms' ),
											),
											array(
												'label' => __( 'Status', 'simple-lms' ),
												'value' => ucfirst( $term['status'] ),
											),
										)
									);
									?>
									<div class="slms-record-actions">
										<button type="button" class="slms-portal-button is-secondary" data-slms-record-edit-toggle><?php esc_html_e( 'Edit Term', 'simple-lms' ); ?></button>
									</div>
								</div>
								<form method="post" action="" class="slms-inline-editor slms-record-edit-form" data-slms-record-edit-form hidden>
									<?php wp_nonce_field( 'slms_academic_tools_action', 'slms_academic_tools_nonce' ); ?>
									<input type="hidden" name="slms_academic_group" value="academics">
									<input type="hidden" name="slms_academic_action" value="update_term">
									<input type="hidden" name="term_id" value="<?php echo esc_attr( $term['id'] ); ?>">
									<div class="slms-form-split">
										<label><span><?php esc_html_e( 'Name', 'simple-lms' ); ?></span><input type="text" name="term_name" value="<?php echo esc_attr( $term['name'] ); ?>" required></label>
										<label><span><?php esc_html_e( 'Code', 'simple-lms' ); ?></span><input type="text" name="term_code" value="<?php echo esc_attr( $term['code'] ); ?>" required></label>
									</div>
									<div class="slms-form-split">
										<label><span><?php esc_html_e( 'Academic Year', 'simple-lms' ); ?></span><input type="text" name="term_academic_year" value="<?php echo esc_attr( $term['academic_year'] ); ?>" required></label>
										<label><span><?php esc_html_e( 'Sort Order', 'simple-lms' ); ?></span><input type="number" name="term_sort_order" min="0" value="<?php echo esc_attr( (string) ( $term['sort_order'] ?? 0 ) ); ?>"></label>
									</div>
									<div class="slms-form-split">
										<label><span><?php esc_html_e( 'Start Date', 'simple-lms' ); ?></span><input type="date" name="term_start_date" value="<?php echo esc_attr( $term['start_date'] ); ?>"></label>
										<label><span><?php esc_html_e( 'End Date', 'simple-lms' ); ?></span><input type="date" name="term_end_date" value="<?php echo esc_attr( $term['end_date'] ); ?>"></label>
									</div>
									<label>
										<span><?php esc_html_e( 'Status', 'simple-lms' ); ?></span>
										<select name="term_status">
											<option value="planned" <?php selected( $term['status'], 'planned' ); ?>><?php esc_html_e( 'Planned', 'simple-lms' ); ?></option>
											<option value="active" <?php selected( $term['status'], 'active' ); ?>><?php esc_html_e( 'Active', 'simple-lms' ); ?></option>
											<option value="closed" <?php selected( $term['status'], 'closed' ); ?>><?php esc_html_e( 'Closed', 'simple-lms' ); ?></option>
											<option value="archived" <?php selected( $term['status'], 'archived' ); ?>><?php esc_html_e( 'Archived', 'simple-lms' ); ?></option>
										</select>
									</label>
									<div class="slms-record-actions">
										<button type="submit" class="slms-portal-button is-secondary"><?php esc_html_e( 'Save Term', 'simple-lms' ); ?></button>
										<button type="submit" class="slms-portal-button is-danger" form="slms-delete-term-<?php echo esc_attr( $term['id'] ); ?>" formnovalidate onclick="return confirm('<?php echo esc_js( __( 'Remove this term? This cannot be undone.', 'simple-lms' ) ); ?>');"><?php esc_html_e( 'Remove Term', 'simple-lms' ); ?></button>
										<button type="button" class="slms-portal-button is-secondary" data-slms-record-edit-cancel><?php esc_html_e( 'Cancel', 'simple-lms' ); ?></button>
									</div>
								</form>
								<form method="post" action="" id="slms-delete-term-<?php echo esc_attr( $term['id'] ); ?>" hidden>
									<?php wp_nonce_field( 'slms_academic_tools_action', 'slms_academic_tools_nonce' ); ?>
									<input type="hidden" name="slms_academic_group" value="academics">
									<input type="hidden" name="slms_academic_action" value="delete_term">
									<input type="hidden" name="term_id" value="<?php echo esc_attr( $term['id'] ); ?>">
								</form>
							</div>
						</details>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>
		</section>
		<?php
	}

	private function render_student_directory_controls( $page_url, $search, $current_page, $max_pages, $total_matches, array $filters, array $terms, array $programs ) {
		$intake_years = $this->get_intake_year_options( $terms );
		?>
		<form method="get" action="" class="slms-form-stack slms-directory-search">
			<input type="hidden" name="slms_view" value="academic-management">
			<input type="hidden" name="slms_manage" value="students">
			<label><span><?php esc_html_e( 'Search Students', 'simple-lms' ); ?></span><input type="text" name="slms_people_search" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search by name, email, or code', 'simple-lms' ); ?>"></label>
			<div class="slms-form-split">
				<label>
					<span><?php esc_html_e( 'Program', 'simple-lms' ); ?></span>
					<select name="slms_people_program">
						<option value=""><?php esc_html_e( 'All programs', 'simple-lms' ); ?></option>
						<?php foreach ( $programs as $program ) : ?>
							<option value="<?php echo esc_attr( $program->term_id ); ?>" <?php selected( absint( $filters['program_id'] ?? 0 ), (int) $program->term_id ); ?>><?php echo esc_html( $program->name ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label>
					<span><?php esc_html_e( 'Intake Year', 'simple-lms' ); ?></span>
					<select name="slms_people_intake_year">
						<option value=""><?php esc_html_e( 'All intake years', 'simple-lms' ); ?></option>
						<?php foreach ( $intake_years as $year ) : ?>
							<option value="<?php echo esc_attr( $year ); ?>" <?php selected( $filters['intake_academic_year'] ?? '', $year ); ?>><?php echo esc_html( $year ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
			</div>
			<label>
				<span><?php esc_html_e( 'Student Status', 'simple-lms' ); ?></span>
				<select name="slms_people_status">
					<option value=""><?php esc_html_e( 'All statuses', 'simple-lms' ); ?></option>
					<?php foreach ( ProfileService::STATUSES as $status ) : ?>
						<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $filters['status'] ?? '', $status ); ?>><?php echo esc_html( ucfirst( $status ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label>
				<span><?php esc_html_e( 'Sort Students', 'simple-lms' ); ?></span>
				<select name="slms_people_sort">
					<option value="program_intake" <?php selected( $filters['sort'], 'program_intake' ); ?>><?php esc_html_e( 'Program, then intake year', 'simple-lms' ); ?></option>
					<option value="intake_program" <?php selected( $filters['sort'], 'intake_program' ); ?>><?php esc_html_e( 'Intake year, then program', 'simple-lms' ); ?></option>
					<option value="name" <?php selected( $filters['sort'], 'name' ); ?>><?php esc_html_e( 'Student name', 'simple-lms' ); ?></option>
					<option value="updated" <?php selected( $filters['sort'], 'updated' ); ?>><?php esc_html_e( 'Recently updated', 'simple-lms' ); ?></option>
				</select>
			</label>
			<div class="slms-row-actions">
				<button type="submit" class="slms-portal-button is-secondary"><?php esc_html_e( 'Apply Student Sort', 'simple-lms' ); ?></button>
				<a class="slms-portal-button is-secondary" href="<?php echo esc_url( $this->get_academic_section_url( $page_url, 'students' ) ); ?>"><?php esc_html_e( 'Reset', 'simple-lms' ); ?></a>
			</div>
		</form>
		<div class="slms-portal-stat-grid">
			<div class="slms-portal-stat"><span><?php esc_html_e( 'Matching Records', 'simple-lms' ); ?></span><strong><?php echo esc_html( (string) $total_matches ); ?></strong></div>
			<div class="slms-portal-stat"><span><?php esc_html_e( 'Page', 'simple-lms' ); ?></span><strong><?php echo esc_html( sprintf( '%1$d/%2$d', $current_page, $max_pages ) ); ?></strong></div>
		</div>
		<?php
	}

	private function render_staff_directory_controls( $page_url, $search, $current_page, $max_pages, $total_matches, array $filters ) {
		$role_options = $this->get_staff_role_filter_options();
		?>
		<form method="get" action="" class="slms-form-stack slms-directory-search">
			<input type="hidden" name="slms_view" value="academic-management">
			<input type="hidden" name="slms_manage" value="staff">
			<label><span><?php esc_html_e( 'Search Staff', 'simple-lms' ); ?></span><input type="text" name="slms_people_search" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search by name, email, code, or department', 'simple-lms' ); ?>"></label>
			<div class="slms-form-split">
				<label>
					<span><?php esc_html_e( 'Role', 'simple-lms' ); ?></span>
					<select name="slms_staff_role">
						<option value=""><?php esc_html_e( 'All staff roles', 'simple-lms' ); ?></option>
						<?php foreach ( $role_options as $role => $label ) : ?>
							<option value="<?php echo esc_attr( $role ); ?>" <?php selected( $filters['role'] ?? '', $role ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label>
					<span><?php esc_html_e( 'Sort Staff', 'simple-lms' ); ?></span>
					<select name="slms_staff_sort">
						<option value="role" <?php selected( $filters['sort'], 'role' ); ?>><?php esc_html_e( 'Role, then name', 'simple-lms' ); ?></option>
						<option value="name" <?php selected( $filters['sort'], 'name' ); ?>><?php esc_html_e( 'Staff name', 'simple-lms' ); ?></option>
						<option value="updated" <?php selected( $filters['sort'], 'updated' ); ?>><?php esc_html_e( 'Recently updated', 'simple-lms' ); ?></option>
					</select>
				</label>
			</div>
			<div class="slms-row-actions">
				<button type="submit" class="slms-portal-button is-secondary"><?php esc_html_e( 'Apply Staff Sort', 'simple-lms' ); ?></button>
				<a class="slms-portal-button is-secondary" href="<?php echo esc_url( $this->get_academic_section_url( $page_url, 'staff' ) ); ?>"><?php esc_html_e( 'Reset', 'simple-lms' ); ?></a>
			</div>
		</form>
		<div class="slms-portal-stat-grid">
			<div class="slms-portal-stat"><span><?php esc_html_e( 'Matching Records', 'simple-lms' ); ?></span><strong><?php echo esc_html( (string) $total_matches ); ?></strong></div>
			<div class="slms-portal-stat"><span><?php esc_html_e( 'Page', 'simple-lms' ); ?></span><strong><?php echo esc_html( sprintf( '%1$d/%2$d', $current_page, $max_pages ) ); ?></strong></div>
		</div>
		<?php
	}

	private function render_student_creation_card( array $terms, array $programs, $compact = false ) {
		?>
		<?php if ( ! $compact ) : ?>
		<section class="slms-portal-panel">
			<h2><?php esc_html_e( 'Create Student', 'simple-lms' ); ?></h2>
			<p class="slms-field-help"><?php esc_html_e( 'Add a student account and LMS profile in one step.', 'simple-lms' ); ?></p>
		<?php endif; ?>
			<form method="post" action="" class="slms-form-stack">
				<?php wp_nonce_field( 'slms_academic_tools_action', 'slms_academic_tools_nonce' ); ?>
				<input type="hidden" name="slms_academic_group" value="people">
				<input type="hidden" name="slms_academic_action" value="create_student_account">
				<label><span><?php esc_html_e( 'Full Name', 'simple-lms' ); ?></span><input type="text" name="student_full_name" required></label>
				<div class="slms-form-split">
					<label><span><?php esc_html_e( 'Email', 'simple-lms' ); ?></span><input type="email" name="student_email" required></label>
					<label><span><?php esc_html_e( 'Student Code', 'simple-lms' ); ?></span><input type="text" name="student_code"></label>
				</div>
					<label><span><?php esc_html_e( 'NRC Number', 'simple-lms' ); ?></span><input type="text" name="student_nrc_number" maxlength="100" autocomplete="off"></label>
				<div class="slms-form-split">
					<label>
						<span><?php esc_html_e( 'Program', 'simple-lms' ); ?></span>
						<select name="student_program_id">
							<option value=""><?php esc_html_e( 'Select a program', 'simple-lms' ); ?></option>
							<?php foreach ( $programs as $program ) : ?>
								<option value="<?php echo esc_attr( $program->term_id ); ?>"><?php echo esc_html( $program->name ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label>
						<span><?php esc_html_e( 'Intake Term', 'simple-lms' ); ?></span>
						<select name="student_intake_term_id">
							<option value=""><?php esc_html_e( 'Select a term', 'simple-lms' ); ?></option>
							<?php foreach ( $terms as $term ) : ?>
								<option value="<?php echo esc_attr( $term['id'] ); ?>"><?php echo esc_html( $term['name'] . ' (' . $term['academic_year'] . ')' ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
				</div>
				<label><span><?php esc_html_e( 'Phone', 'simple-lms' ); ?></span><input type="text" name="student_phone"></label>
				<label class="slms-inline-checkbox"><input type="checkbox" name="student_send_welcome_email" value="1" checked> <span><?php esc_html_e( 'Send welcome email', 'simple-lms' ); ?></span></label>
				<p class="slms-field-help"><?php echo esc_html( sprintf( __( 'New accounts start with the temporary password %s and must change it the first time they sign in to the LMS portal.', 'simple-lms' ), ProvisioningService::DEFAULT_INITIAL_PASSWORD ) ); ?></p>
				<button type="submit" class="slms-portal-button"><?php esc_html_e( 'Create Student', 'simple-lms' ); ?></button>
			</form>
		<?php if ( ! $compact ) : ?>
		</section>
		<?php endif; ?>
		<?php
	}

	private function render_staff_creation_card( $compact = false ) {
		?>
		<?php if ( ! $compact ) : ?>
		<section class="slms-portal-panel">
			<h2><?php esc_html_e( 'Create Staff Account', 'simple-lms' ); ?></h2>
			<p class="slms-field-help"><?php esc_html_e( 'Add general staff, lecturers, and administrative staff without leaving the frontend.', 'simple-lms' ); ?></p>
		<?php endif; ?>
			<form method="post" action="" class="slms-form-stack">
				<?php wp_nonce_field( 'slms_academic_tools_action', 'slms_academic_tools_nonce' ); ?>
				<input type="hidden" name="slms_academic_group" value="people">
				<input type="hidden" name="slms_academic_action" value="create_staff_account">
				<label><span><?php esc_html_e( 'Full Name', 'simple-lms' ); ?></span><input type="text" name="staff_full_name" required></label>
				<div class="slms-form-split">
					<label><span><?php esc_html_e( 'Email', 'simple-lms' ); ?></span><input type="email" name="staff_email" required></label>
					<label><span><?php esc_html_e( 'Staff Code', 'simple-lms' ); ?></span><input type="text" name="staff_code"></label>
				</div>
					<label><span><?php esc_html_e( 'NRC Number', 'simple-lms' ); ?></span><input type="text" name="staff_nrc_number" maxlength="100" autocomplete="off"></label>
				<div class="slms-form-split">
					<label>
						<span><?php esc_html_e( 'Role', 'simple-lms' ); ?></span>
						<select name="staff_role">
							<option value="lecturer"><?php esc_html_e( 'Lecturer', 'simple-lms' ); ?></option>
							<option value="<?php echo esc_attr( RoleManager::ROLE_STAFF ); ?>"><?php esc_html_e( 'Staff (minimum access)', 'simple-lms' ); ?></option>
							<option value="officer"><?php esc_html_e( 'Officer', 'simple-lms' ); ?></option>
							<?php if ( current_user_can( 'manage_options' ) ) : ?>
								<option value="administrator"><?php esc_html_e( 'Administrator', 'simple-lms' ); ?></option>
							<?php endif; ?>
						</select>
					</label>
					<label><span><?php esc_html_e( 'Department', 'simple-lms' ); ?></span><input type="text" name="staff_department"></label>
				</div>
				<div class="slms-form-split">
					<label><span><?php esc_html_e( 'Position Title', 'simple-lms' ); ?></span><input type="text" name="staff_position_title"></label>
					<label><span><?php esc_html_e( 'Phone', 'simple-lms' ); ?></span><input type="text" name="staff_phone"></label>
				</div>
				<label class="slms-inline-checkbox"><input type="checkbox" name="staff_send_welcome_email" value="1" checked> <span><?php esc_html_e( 'Send welcome email', 'simple-lms' ); ?></span></label>
				<p class="slms-field-help"><?php echo esc_html( sprintf( __( 'New accounts start with the temporary password %s and must change it the first time they sign in to the LMS portal.', 'simple-lms' ), ProvisioningService::DEFAULT_INITIAL_PASSWORD ) ); ?></p>
				<button type="submit" class="slms-portal-button"><?php esc_html_e( 'Create Staff Account', 'simple-lms' ); ?></button>
			</form>
		<?php if ( ! $compact ) : ?>
		</section>
		<?php endif; ?>
		<?php
	}

	private function get_program_completion_summary( $user_id ) {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return array();
		}

		$status = sanitize_key( get_user_meta( $user_id, '_slms_program_completion_status', true ) );
		$result = sanitize_key( get_user_meta( $user_id, '_slms_program_completion_result', true ) );
		$date   = sanitize_text_field( get_user_meta( $user_id, '_slms_program_completion_date', true ) );
		$closed = absint( get_user_meta( $user_id, '_slms_program_completion_closed_enrollments', true ) );

		if ( 'completed' !== $status ) {
			return array();
		}

		$result_label = 'fail' === $result ? __( 'Fail', 'simple-lms' ) : __( 'Pass', 'simple-lms' );
		$parts        = array( $result_label );

		if ( $date ) {
			$parts[] = sprintf( __( 'Completed on %s', 'simple-lms' ), $date );
		}

		$parts[] = sprintf( _n( '%d enrollment closed', '%d enrollments closed', $closed, 'simple-lms' ), $closed );

		return array(
			'result'       => $result,
			'result_label' => $result_label,
			'date'         => $date,
			'summary'      => implode( ' | ', $parts ),
		);
	}

	private function render_person_password_fields() {
		?>
		<div class="slms-form-stack slms-account-password-fields">
			<span class="slms-field-label"><?php esc_html_e( 'Account Password', 'simple-lms' ); ?></span>
			<div class="slms-form-split">
				<label><span><?php esc_html_e( 'New Password', 'simple-lms' ); ?></span><input type="password" name="person_new_password" value="" autocomplete="new-password" minlength="8"></label>
				<label><span><?php esc_html_e( 'Confirm Password', 'simple-lms' ); ?></span><input type="password" name="person_confirm_password" value="" autocomplete="new-password" minlength="8"></label>
			</div>
			<p class="slms-field-help"><?php echo esc_html( sprintf( __( 'Leave both fields blank to keep the current password. Setting %s keeps the first-login password-change requirement; setting any other password clears it.', 'simple-lms' ), ProvisioningService::DEFAULT_INITIAL_PASSWORD ) ); ?></p>
		</div>
		<?php
	}

	private function render_student_record_card( array $student, array $terms, array $programs, array $subject_options = array() ) {
		$program_id     = absint( $student['program_id'] ?? 0 );
		$intake_term_id = absint( $student['intake_term_id'] ?? 0 );
		$status_label   = ucfirst( $student['status'] ?: 'active' );
		$completion     = $this->get_program_completion_summary( absint( $student['user_id'] ?? 0 ) );

		if ( 'completed' === ( $student['status'] ?? '' ) && ! empty( $completion['result_label'] ) ) {
			$status_label = sprintf( '%1$s - %2$s', __( 'Completed', 'simple-lms' ), $completion['result_label'] );
		}

		$summary_line   = array_filter(
			array(
				$student['person_code'] ?? '',
				$student['user_email'] ?? '',
				$this->get_program_label( $program_id, $programs ),
			)
		);
		?>
		<details class="slms-record-card">
			<summary>
				<div>
					<strong><?php echo $this->get_profile_avatar_name_markup( $student, 'is-compact' ); ?></strong>
					<span><?php echo esc_html( implode( ' | ', $summary_line ) ); ?></span>
				</div>
				<span class="slms-status-pill"><?php echo esc_html( $status_label ); ?></span>
			</summary>
			<div class="slms-record-card-body" data-slms-record-body>
				<div class="slms-record-view" data-slms-record-view>
					<?php
					$this->render_record_facts(
						array(
							array(
								'label' => __( 'Email', 'simple-lms' ),
								'value' => $student['user_email'],
							),
							array(
								'label' => __( 'Student Code', 'simple-lms' ),
								'value' => $student['person_code'] ?: __( 'Not assigned', 'simple-lms' ),
							),
							array(
								'label' => __( 'NRC Number', 'simple-lms' ),
								'value' => ( $student['nrc_number'] ?? '' ) ?: __( 'Not provided', 'simple-lms' ),
							),
							array(
								'label' => __( 'Program', 'simple-lms' ),
								'value' => $this->get_program_label( $program_id, $programs ),
							),
							array(
								'label' => __( 'Intake Term', 'simple-lms' ),
								'value' => $this->get_term_label( $intake_term_id, $terms ),
							),
							array(
								'label' => __( 'Phone', 'simple-lms' ),
								'value' => $student['phone'] ?: __( 'Not provided', 'simple-lms' ),
							),
							array(
								'label' => __( 'Username', 'simple-lms' ),
								'value' => $student['user_login'],
							),
							array(
								'label' => __( 'Status', 'simple-lms' ),
								'value' => $status_label,
							),
							array(
								'label' => __( 'Program Completion', 'simple-lms' ),
								'value' => ! empty( $completion['summary'] ) ? $completion['summary'] : __( 'Not completed', 'simple-lms' ),
							),
							array(
								'label' => __( 'Notes', 'simple-lms' ),
								'value' => $student['notes'] ?: __( 'No notes yet.', 'simple-lms' ),
							),
						)
					);
					?>
					<div class="slms-record-actions">
						<button type="button" class="slms-portal-button is-secondary" data-slms-record-edit-toggle><?php esc_html_e( 'Edit Student', 'simple-lms' ); ?></button>
					</div>
					<details class="slms-program-completion-panel">
						<summary class="slms-portal-button is-secondary"><?php esc_html_e( 'Mark Program Completed', 'simple-lms' ); ?></summary>
						<form method="post" action="" class="slms-form-stack slms-program-completion-form">
							<?php wp_nonce_field( 'slms_academic_tools_action', 'slms_academic_tools_nonce' ); ?>
							<input type="hidden" name="slms_academic_group" value="people">
							<input type="hidden" name="slms_academic_action" value="complete_student_program">
							<input type="hidden" name="person_user_id" value="<?php echo esc_attr( $student['user_id'] ); ?>">
							<p class="slms-field-help"><?php esc_html_e( 'This marks the student program as completed and closes active subject enrollments while preserving attendance, marks, submissions, and historical records.', 'simple-lms' ); ?></p>
							<div class="slms-form-split">
								<label>
									<span><?php esc_html_e( 'Program Result', 'simple-lms' ); ?></span>
									<select name="program_completion_result" required>
										<option value=""><?php esc_html_e( 'Select result', 'simple-lms' ); ?></option>
										<option value="pass"><?php esc_html_e( 'Pass', 'simple-lms' ); ?></option>
										<option value="fail"><?php esc_html_e( 'Fail', 'simple-lms' ); ?></option>
									</select>
								</label>
								<label><span><?php esc_html_e( 'Completion Date', 'simple-lms' ); ?></span><input type="date" name="program_completion_date" value="<?php echo esc_attr( AcademicClock::date( 'Y-m-d' ) ); ?>"></label>
							</div>
							<label><span><?php esc_html_e( 'Completion Notes', 'simple-lms' ); ?></span><textarea name="program_completion_notes" rows="3" placeholder="<?php esc_attr_e( 'Optional internal notes about program completion.', 'simple-lms' ); ?>"></textarea></label>
							<label class="slms-inline-checkbox"><input type="checkbox" name="close_active_enrollments" value="1" checked> <span><?php esc_html_e( 'Close active subject enrollments and preserve records', 'simple-lms' ); ?></span></label>
							<button type="submit" class="slms-portal-button" onclick="return confirm('<?php echo esc_js( __( 'Mark this student program as completed? Active subject enrollments will be closed, but records will be preserved.', 'simple-lms' ) ); ?>');"><?php esc_html_e( 'Confirm Program Completion', 'simple-lms' ); ?></button>
						</form>
					</details>
				</div>
				<form method="post" action="" class="slms-inline-editor slms-record-edit-form" data-slms-record-edit-form hidden>
					<?php wp_nonce_field( 'slms_academic_tools_action', 'slms_academic_tools_nonce' ); ?>
					<input type="hidden" name="slms_academic_group" value="people">
					<input type="hidden" name="slms_academic_action" value="update_student_account">
					<input type="hidden" name="person_user_id" value="<?php echo esc_attr( $student['user_id'] ); ?>">
					<input type="hidden" name="slms_sync_subjects" value="1">
					<label><span><?php esc_html_e( 'Full Name', 'simple-lms' ); ?></span><input type="text" name="person_full_name" value="<?php echo esc_attr( $student['display_name'] ); ?>" required></label>
					<div class="slms-form-split">
						<label><span><?php esc_html_e( 'Email', 'simple-lms' ); ?></span><input type="email" name="person_email" value="<?php echo esc_attr( $student['user_email'] ); ?>" required></label>
						<label><span><?php esc_html_e( 'Student Code', 'simple-lms' ); ?></span><input type="text" name="person_code" value="<?php echo esc_attr( $student['person_code'] ); ?>"></label>
					</div>
					<label><span><?php esc_html_e( 'NRC Number', 'simple-lms' ); ?></span><input type="text" name="person_nrc_number" value="<?php echo esc_attr( $student['nrc_number'] ?? '' ); ?>" maxlength="100" autocomplete="off"></label>
					<?php $this->render_person_password_fields(); ?>
					<div class="slms-form-split">
						<label>
							<span><?php esc_html_e( 'Program', 'simple-lms' ); ?></span>
							<select name="student_program_id" data-slms-student-program-select>
								<option value=""><?php esc_html_e( 'Select a program', 'simple-lms' ); ?></option>
								<?php foreach ( $programs as $program ) : ?>
									<option value="<?php echo esc_attr( $program->term_id ); ?>" <?php selected( $program_id, (int) $program->term_id ); ?>><?php echo esc_html( $program->name ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<label>
							<span><?php esc_html_e( 'Intake Term', 'simple-lms' ); ?></span>
							<select name="student_intake_term_id">
								<option value=""><?php esc_html_e( 'Select a term', 'simple-lms' ); ?></option>
								<?php foreach ( $terms as $term ) : ?>
									<option value="<?php echo esc_attr( $term['id'] ); ?>" <?php selected( $intake_term_id, (int) $term['id'] ); ?>><?php echo esc_html( $term['name'] . ' (' . $term['academic_year'] . ')' ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
					</div>
					<div class="slms-form-split">
						<label><span><?php esc_html_e( 'Phone', 'simple-lms' ); ?></span><input type="text" name="person_phone" value="<?php echo esc_attr( $student['phone'] ?? '' ); ?>"></label>
						<label>
							<span><?php esc_html_e( 'Status', 'simple-lms' ); ?></span>
							<select name="person_status">
								<?php foreach ( ProfileService::STATUSES as $status ) : ?>
									<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $student['status'], $status ); ?>><?php echo esc_html( ucfirst( $status ) ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
					</div>
					<?php $this->render_student_subject_assignment_dropdown( absint( $student['user_id'] ), $subject_options, $program_id ); ?>
					<label><span><?php esc_html_e( 'Notes', 'simple-lms' ); ?></span><textarea name="person_notes" rows="3"><?php echo esc_textarea( $student['notes'] ?? '' ); ?></textarea></label>
					<div class="slms-record-actions">
						<button type="submit" class="slms-portal-button is-secondary"><?php esc_html_e( 'Save Student', 'simple-lms' ); ?></button>
						<button type="submit" class="slms-portal-button is-danger" form="slms-delete-student-<?php echo esc_attr( $student['user_id'] ); ?>" formnovalidate onclick="return confirm('<?php echo esc_js( __( 'Remove this student account? This cannot be undone.', 'simple-lms' ) ); ?>');"><?php esc_html_e( 'Remove Student', 'simple-lms' ); ?></button>
						<?php if ( $this->is_administrator() && get_current_user_id() !== absint( $student['user_id'] ) ) : ?>
							<button type="submit" class="slms-portal-button is-danger" form="slms-force-delete-student-<?php echo esc_attr( $student['user_id'] ); ?>" formnovalidate onclick="return confirm('<?php echo esc_js( __( 'Force remove this student and permanently delete all linked enrollments, submissions, grades, attendance history, notifications, ID card records, profile data, and managed files? This cannot be undone.', 'simple-lms' ) ); ?>');"><?php esc_html_e( 'Force Remove Student', 'simple-lms' ); ?></button>
						<?php endif; ?>
						<button type="button" class="slms-portal-button is-secondary" data-slms-record-edit-cancel><?php esc_html_e( 'Cancel', 'simple-lms' ); ?></button>
					</div>
				</form>
				<form method="post" action="" id="slms-delete-student-<?php echo esc_attr( $student['user_id'] ); ?>" hidden>
					<?php wp_nonce_field( 'slms_academic_tools_action', 'slms_academic_tools_nonce' ); ?>
					<input type="hidden" name="slms_academic_group" value="people">
					<input type="hidden" name="slms_academic_action" value="delete_student_account">
					<input type="hidden" name="person_user_id" value="<?php echo esc_attr( $student['user_id'] ); ?>">
				</form>
				<?php if ( $this->is_administrator() && get_current_user_id() !== absint( $student['user_id'] ) ) : ?>
					<form method="post" action="" id="slms-force-delete-student-<?php echo esc_attr( $student['user_id'] ); ?>" hidden>
						<?php wp_nonce_field( 'slms_academic_tools_action', 'slms_academic_tools_nonce' ); ?>
						<input type="hidden" name="slms_academic_group" value="people">
						<input type="hidden" name="slms_academic_action" value="force_delete_student_account">
						<input type="hidden" name="person_user_id" value="<?php echo esc_attr( $student['user_id'] ); ?>">
						<input type="hidden" name="force_remove_student_confirm" value="1">
					</form>
				<?php endif; ?>
			</div>
		</details>
		<?php
	}

	private function render_student_subject_assignment_dropdown( $student_user_id, array $subject_options, $selected_program_id = 0 ) {
		$enrollment_map      = $this->get_student_subject_enrollment_map( $student_user_id );
		$selected_program_id = absint( $selected_program_id );
		$selected_count      = 0;
		$visible_count       = 0;

		foreach ( $subject_options as $subject ) {
			$subject_program_id = absint( $subject['program_id'] ?? 0 );
			$is_visible         = $selected_program_id && $selected_program_id === $subject_program_id;

			if ( ! $is_visible ) {
				continue;
			}

			$visible_count++;
			$status = $enrollment_map[ absint( $subject['id'] ?? 0 ) ] ?? '';

			if ( in_array( $status, array( 'enrolled', 'waitlisted', 'completed' ), true ) ) {
				$selected_count++;
			}
		}

		$empty_message = '';

		if ( empty( $subject_options ) ) {
			$empty_message = __( 'No subjects are available yet. Create subjects first, then return to assign them.', 'simple-lms' );
		} elseif ( ! $selected_program_id ) {
			$empty_message = __( 'Select a student program to show matching subjects.', 'simple-lms' );
		} elseif ( ! $visible_count ) {
			$empty_message = __( 'No subjects are available for the selected program.', 'simple-lms' );
		}
		?>
		<div class="slms-form-stack slms-subject-assignment-field">
			<span class="slms-field-label"><?php esc_html_e( 'Subject Enrollments', 'simple-lms' ); ?></span>
			<details
				class="slms-subject-picker"
				data-slms-student-subject-picker
				data-slms-no-program-text="<?php echo esc_attr__( 'Select a student program to show matching subjects.', 'simple-lms' ); ?>"
				data-slms-empty-text="<?php echo esc_attr__( 'No subjects are available for the selected program.', 'simple-lms' ); ?>"
				data-slms-no-subjects-text="<?php echo esc_attr__( 'No subjects are available yet. Create subjects first, then return to assign them.', 'simple-lms' ); ?>"
			>
				<summary data-slms-subject-picker-summary data-slms-summary-template="<?php echo esc_attr__( 'Choose subjects (%d selected)', 'simple-lms' ); ?>"><?php echo esc_html( sprintf( __( 'Choose subjects (%d selected)', 'simple-lms' ), $selected_count ) ); ?></summary>
				<div class="slms-subject-picker-list">
					<p class="slms-field-help slms-subject-picker-empty" data-slms-subject-picker-empty <?php echo $empty_message ? '' : 'hidden'; ?>><?php echo esc_html( $empty_message ); ?></p>
					<?php foreach ( $subject_options as $subject ) : ?>
						<?php
						$subject_id         = absint( $subject['id'] ?? 0 );
						$subject_program_id = absint( $subject['program_id'] ?? 0 );
						$status             = $enrollment_map[ $subject_id ] ?? '';
						$checked            = in_array( $status, array( 'enrolled', 'waitlisted', 'completed' ), true );
						$locked             = 'completed' === $status;
						$is_visible         = $selected_program_id && $selected_program_id === $subject_program_id;
						$meta               = array_filter(
							array(
								$subject['code'] ?? '',
								$subject['program_label'] ?? '',
								$subject['term_label'] ?? '',
								$status ? ucfirst( $status ) : '',
							)
						);
						?>
						<label class="slms-inline-checkbox slms-subject-picker-option" data-slms-subject-picker-option data-slms-subject-program-id="<?php echo esc_attr( $subject_program_id ); ?>" <?php echo $is_visible ? '' : 'hidden'; ?>>
							<input type="checkbox" data-slms-subject-checkbox data-slms-subject-locked="<?php echo esc_attr( $locked ? '1' : '0' ); ?>" name="slms_student_subject_ids[]" value="<?php echo esc_attr( $subject_id ); ?>" <?php checked( $checked ); ?> <?php disabled( $locked || ! $is_visible ); ?>>
							<input type="hidden" data-slms-subject-available-input name="slms_available_subject_ids[]" value="<?php echo esc_attr( $subject_id ); ?>" <?php disabled( ! $is_visible ); ?>>
							<span class="slms-subject-picker-copy">
								<strong><?php echo esc_html( $subject['title'] ?? __( 'Untitled Subject', 'simple-lms' ) ); ?></strong>
								<small><?php echo esc_html( implode( ' | ', $meta ) ); ?></small>
							</span>
						</label>
					<?php endforeach; ?>
				</div>
			</details>
			<p class="slms-field-help"><?php esc_html_e( 'Only subjects from the selected student program are shown here. Checked subjects are enrolled. Unchecking an active subject marks the enrollment as dropped instead of deleting history. Completed subjects stay locked.', 'simple-lms' ); ?></p>
		</div>
		<?php
	}

	private function get_student_subject_enrollment_map( $student_user_id ) {
		$map = array();

		foreach ( $this->enrollments->list_enrollments( array( 'student_user_id' => absint( $student_user_id ) ) ) as $enrollment ) {
			$section_id = absint( $enrollment['section_id'] ?? 0 );

			if ( $section_id ) {
				$map[ $section_id ] = sanitize_key( $enrollment['status'] ?? '' );
			}
		}

		return $map;
	}

	private function render_staff_record_card( array $staff ) {
		$staff_role   = $this->get_staff_role_value( $staff['user_id'] ?? 0 );
		$status_label = ucfirst( $staff['status'] ?: 'active' );
		$summary_line = array_filter(
			array(
				$staff['person_code'] ?? '',
				RoleManager::get_role_label( $staff_role ),
				$staff['position_title'] ?? '',
			)
		);
		?>
		<details class="slms-record-card slms-staff-record-card">
			<summary>
				<div>
					<strong><?php echo $this->get_profile_avatar_name_markup( $staff, 'is-compact' ); ?></strong>
					<span><?php echo esc_html( implode( ' | ', $summary_line ) ); ?></span>
				</div>
				<span class="slms-status-pill"><?php echo esc_html( $status_label ); ?></span>
			</summary>
			<div class="slms-record-card-body" data-slms-record-body>
				<div class="slms-record-view" data-slms-record-view>
					<?php
					$this->render_record_facts(
						array(
							array(
								'label' => __( 'Email', 'simple-lms' ),
								'value' => $staff['user_email'],
							),
							array(
								'label' => __( 'Staff Code', 'simple-lms' ),
								'value' => $staff['person_code'] ?: __( 'Not assigned', 'simple-lms' ),
							),
							array(
								'label' => __( 'NRC Number', 'simple-lms' ),
								'value' => ( $staff['nrc_number'] ?? '' ) ?: __( 'Not provided', 'simple-lms' ),
							),
							array(
								'label' => __( 'Role', 'simple-lms' ),
								'value' => RoleManager::get_role_label( $staff_role ),
							),
							array(
								'label' => __( 'Department', 'simple-lms' ),
								'value' => $staff['department'] ?: __( 'Not assigned', 'simple-lms' ),
							),
							array(
								'label' => __( 'Position Title', 'simple-lms' ),
								'value' => $staff['position_title'] ?: __( 'Not assigned', 'simple-lms' ),
							),
							array(
								'label' => __( 'Phone', 'simple-lms' ),
								'value' => $staff['phone'] ?: __( 'Not provided', 'simple-lms' ),
							),
							array(
								'label' => __( 'Username', 'simple-lms' ),
								'value' => $staff['user_login'],
							),
							array(
								'label' => __( 'Status', 'simple-lms' ),
								'value' => $status_label,
							),
							array(
								'label' => __( 'Notes', 'simple-lms' ),
								'value' => $staff['notes'] ?: __( 'No notes yet.', 'simple-lms' ),
							),
						)
					);
					?>
					<div class="slms-record-actions">
						<button type="button" class="slms-portal-button is-secondary" data-slms-record-edit-toggle><?php esc_html_e( 'Edit Staff Record', 'simple-lms' ); ?></button>
					</div>
				</div>
				<form method="post" action="" class="slms-inline-editor slms-record-edit-form" data-slms-record-edit-form hidden>
					<?php wp_nonce_field( 'slms_academic_tools_action', 'slms_academic_tools_nonce' ); ?>
					<input type="hidden" name="slms_academic_group" value="people">
					<input type="hidden" name="slms_academic_action" value="update_staff_account">
					<input type="hidden" name="person_user_id" value="<?php echo esc_attr( $staff['user_id'] ); ?>">
					<label><span><?php esc_html_e( 'Full Name', 'simple-lms' ); ?></span><input type="text" name="person_full_name" value="<?php echo esc_attr( $staff['display_name'] ); ?>" required></label>
					<div class="slms-form-split">
						<label><span><?php esc_html_e( 'Email', 'simple-lms' ); ?></span><input type="email" name="person_email" value="<?php echo esc_attr( $staff['user_email'] ); ?>" required></label>
						<label><span><?php esc_html_e( 'Staff Code', 'simple-lms' ); ?></span><input type="text" name="person_code" value="<?php echo esc_attr( $staff['person_code'] ); ?>"></label>
					</div>
					<label><span><?php esc_html_e( 'NRC Number', 'simple-lms' ); ?></span><input type="text" name="person_nrc_number" value="<?php echo esc_attr( $staff['nrc_number'] ?? '' ); ?>" maxlength="100" autocomplete="off"></label>
					<?php $this->render_person_password_fields(); ?>
					<div class="slms-form-split">
						<label>
							<span><?php esc_html_e( 'Role', 'simple-lms' ); ?></span>
							<select name="staff_role">
								<?php if ( 'editor' === $staff_role ) : ?>
									<option value="editor" selected><?php esc_html_e( 'Editor (legacy content role)', 'simple-lms' ); ?></option>
								<?php endif; ?>
								<?php foreach ( ProvisioningService::STAFF_ROLES as $role ) : ?>
									<option value="<?php echo esc_attr( $role ); ?>" <?php selected( $staff_role, $role ); ?>><?php echo esc_html( RoleManager::get_role_label( $role ) ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<label>
							<span><?php esc_html_e( 'Status', 'simple-lms' ); ?></span>
							<select name="person_status">
								<?php foreach ( ProfileService::STATUSES as $status ) : ?>
									<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $staff['status'], $status ); ?>><?php echo esc_html( ucfirst( $status ) ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
					</div>
					<div class="slms-form-split">
						<label><span><?php esc_html_e( 'Department', 'simple-lms' ); ?></span><input type="text" name="staff_department" value="<?php echo esc_attr( $staff['department'] ?? '' ); ?>"></label>
						<label><span><?php esc_html_e( 'Position Title', 'simple-lms' ); ?></span><input type="text" name="staff_position_title" value="<?php echo esc_attr( $staff['position_title'] ?? '' ); ?>"></label>
					</div>
					<label><span><?php esc_html_e( 'Phone', 'simple-lms' ); ?></span><input type="text" name="person_phone" value="<?php echo esc_attr( $staff['phone'] ?? '' ); ?>"></label>
					<label><span><?php esc_html_e( 'Notes', 'simple-lms' ); ?></span><textarea name="person_notes" rows="3"><?php echo esc_textarea( $staff['notes'] ?? '' ); ?></textarea></label>
					<div class="slms-record-actions">
						<button type="submit" class="slms-portal-button is-secondary"><?php esc_html_e( 'Save Staff Record', 'simple-lms' ); ?></button>
						<button type="submit" class="slms-portal-button is-danger" form="slms-delete-staff-<?php echo esc_attr( $staff['user_id'] ); ?>" formnovalidate onclick="return confirm('<?php echo esc_js( __( 'Remove this staff account? This cannot be undone.', 'simple-lms' ) ); ?>');"><?php esc_html_e( 'Remove Staff', 'simple-lms' ); ?></button>
						<button type="button" class="slms-portal-button is-secondary" data-slms-record-edit-cancel><?php esc_html_e( 'Cancel', 'simple-lms' ); ?></button>
					</div>
				</form>
				<form method="post" action="" id="slms-delete-staff-<?php echo esc_attr( $staff['user_id'] ); ?>" hidden>
					<?php wp_nonce_field( 'slms_academic_tools_action', 'slms_academic_tools_nonce' ); ?>
					<input type="hidden" name="slms_academic_group" value="people">
					<input type="hidden" name="slms_academic_action" value="delete_staff_account">
					<input type="hidden" name="person_user_id" value="<?php echo esc_attr( $staff['user_id'] ); ?>">
				</form>
			</div>
		</details>
		<?php
	}

	private function get_program_label( $program_id, array $programs ) {
		if ( ! $program_id ) {
			return __( 'No program assigned', 'simple-lms' );
		}

		foreach ( $programs as $program ) {
			if ( (int) $program->term_id === (int) $program_id ) {
				return $program->name;
			}
		}

		return __( 'Unknown program', 'simple-lms' );
	}

	private function get_staff_role_value( $user_id ) {
		$role = RoleManager::get_primary_role( $user_id );

		if ( in_array( $role, ProvisioningService::STAFF_ROLES, true ) ) {
			return $role;
		}

		return 'lecturer';
	}

	private function render_student_export_controls( $search, array $filters, array $terms, array $programs ) {
		$intake_years = $this->get_intake_year_options( $terms );
		?>
		<form method="post" action="" class="slms-form-stack slms-directory-search slms-export-search">
			<?php wp_nonce_field( 'slms_academic_tools_action', 'slms_academic_tools_nonce' ); ?>
			<input type="hidden" name="slms_academic_group" value="academics">
			<input type="hidden" name="slms_academic_action" value="download_student_info">
			<label><span><?php esc_html_e( 'Search Students', 'simple-lms' ); ?></span><input type="text" name="slms_export_search" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search by name, email, or code', 'simple-lms' ); ?>"></label>
			<div class="slms-form-split">
				<label>
					<span><?php esc_html_e( 'Program', 'simple-lms' ); ?></span>
					<select name="slms_export_program">
						<option value=""><?php esc_html_e( 'All programs', 'simple-lms' ); ?></option>
						<?php foreach ( $programs as $program ) : ?>
							<option value="<?php echo esc_attr( $program->term_id ); ?>" <?php selected( absint( $filters['program_id'] ?? 0 ), (int) $program->term_id ); ?>><?php echo esc_html( $program->name ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label>
					<span><?php esc_html_e( 'Intake Year', 'simple-lms' ); ?></span>
					<select name="slms_export_intake_year">
						<option value=""><?php esc_html_e( 'All intake years', 'simple-lms' ); ?></option>
						<?php foreach ( $intake_years as $year ) : ?>
							<option value="<?php echo esc_attr( $year ); ?>" <?php selected( $filters['intake_academic_year'] ?? '', $year ); ?>><?php echo esc_html( $year ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
			</div>
			<label>
				<span><?php esc_html_e( 'Student Status', 'simple-lms' ); ?></span>
				<select name="slms_export_status">
					<option value=""><?php esc_html_e( 'All statuses', 'simple-lms' ); ?></option>
					<?php foreach ( ProfileService::STATUSES as $status ) : ?>
						<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $filters['status'] ?? '', $status ); ?>><?php echo esc_html( ucfirst( $status ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<input type="hidden" name="slms_export_sort" value="<?php echo esc_attr( $filters['sort'] ?? 'program_intake' ); ?>">
			<p class="slms-field-help"><?php esc_html_e( 'The CSV includes all matching student records, not only the current page.', 'simple-lms' ); ?></p>
			<div class="slms-row-actions">
				<button type="submit" class="slms-portal-button is-secondary"><?php esc_html_e( 'Download Info', 'simple-lms' ); ?></button>
			</div>
		</form>
		<?php
	}

	private function render_staff_export_controls( $search, array $filters ) {
		$role_options = $this->get_staff_role_filter_options();
		?>
		<form method="post" action="" class="slms-form-stack slms-directory-search slms-export-search">
			<?php wp_nonce_field( 'slms_academic_tools_action', 'slms_academic_tools_nonce' ); ?>
			<input type="hidden" name="slms_academic_group" value="academics">
			<input type="hidden" name="slms_academic_action" value="download_staff_info">
			<label><span><?php esc_html_e( 'Search Staff', 'simple-lms' ); ?></span><input type="text" name="slms_export_search" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search by name, email, code, or department', 'simple-lms' ); ?>"></label>
			<div class="slms-form-split">
				<label>
					<span><?php esc_html_e( 'Role', 'simple-lms' ); ?></span>
					<select name="slms_export_role">
						<option value=""><?php esc_html_e( 'All staff roles', 'simple-lms' ); ?></option>
						<?php foreach ( $role_options as $role => $label ) : ?>
							<option value="<?php echo esc_attr( $role ); ?>" <?php selected( $filters['role'] ?? '', $role ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label>
					<span><?php esc_html_e( 'Sort Staff', 'simple-lms' ); ?></span>
					<select name="slms_export_sort">
						<option value="role" <?php selected( $filters['sort'], 'role' ); ?>><?php esc_html_e( 'Role, then name', 'simple-lms' ); ?></option>
						<option value="name" <?php selected( $filters['sort'], 'name' ); ?>><?php esc_html_e( 'Staff name', 'simple-lms' ); ?></option>
						<option value="updated" <?php selected( $filters['sort'], 'updated' ); ?>><?php esc_html_e( 'Recently updated', 'simple-lms' ); ?></option>
					</select>
				</label>
			</div>
			<p class="slms-field-help"><?php esc_html_e( 'The CSV includes all matching staff records, not only the current page.', 'simple-lms' ); ?></p>
			<div class="slms-row-actions">
				<button type="submit" class="slms-portal-button is-secondary"><?php esc_html_e( 'Download Info', 'simple-lms' ); ?></button>
			</div>
		</form>
		<?php
	}

	private function download_people_info( $person_type ) {
		$person_type = sanitize_key( $person_type );
		if ( ! in_array( $person_type, ProfileService::PERSON_TYPES, true ) || ! $this->is_full_staff() ) {
			return new \WP_Error( 'slms_export_forbidden', __( 'You do not have permission to export these records.', 'simple-lms' ) );
		}

		$args     = $this->get_people_export_query_args( $person_type );
		$profiles = $this->get_people_export_profiles( $args );
		$filename = 'simple-lms-' . sanitize_file_name( $person_type ) . '-info-' . AcademicClock::date( 'Ymd-His' ) . '.csv';

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'X-Content-Type-Options: nosniff' );

		$output = fopen( 'php://output', 'w' );
		if ( ! $output ) {
			exit;
		}

		fwrite( $output, "\xEF\xBB\xBF" );
		$this->write_people_export_rows( $output, $person_type, $profiles );
		fclose( $output );
		exit;
	}

	private function get_people_export_query_args( $person_type ) {
		$sort = sanitize_key( wp_unslash( $_POST['slms_export_sort'] ?? '' ) );
		$args = array(
			'person_type' => $person_type,
			'orderby'     => $sort,
			'per_page'    => 100,
		);

		$search = sanitize_text_field( wp_unslash( $_POST['slms_export_search'] ?? '' ) );
		if ( '' !== $search ) {
			$args['search'] = $search;
		}

		if ( 'student' === $person_type ) {
			if ( ! in_array( $sort, array( 'program_intake', 'intake_program', 'name', 'updated' ), true ) ) {
				$args['orderby'] = 'program_intake';
			}

			$program_id = absint( $_POST['slms_export_program'] ?? 0 );
			if ( $program_id ) {
				$args['program_id'] = $program_id;
			}

			$intake_year = sanitize_text_field( wp_unslash( $_POST['slms_export_intake_year'] ?? '' ) );
			if ( '' !== $intake_year ) {
				$args['intake_academic_year'] = $intake_year;
			}

			$status = sanitize_key( wp_unslash( $_POST['slms_export_status'] ?? '' ) );
			if ( '' !== $status && in_array( $status, ProfileService::STATUSES, true ) ) {
				$args['status'] = $status;
			}
		} else {
			if ( ! in_array( $sort, array( 'role', 'name', 'updated' ), true ) ) {
				$args['orderby'] = 'role';
			}

			$role = sanitize_key( wp_unslash( $_POST['slms_export_role'] ?? '' ) );
			if ( '' !== $role && array_key_exists( $role, $this->get_staff_role_filter_options() ) ) {
				$args['role'] = $role;
			}
		}

		return $args;
	}

	private function get_people_export_profiles( array $args ) {
		$profiles = array();
		$page     = 1;

		do {
			$args['page'] = $page;
			$batch        = $this->profiles->list_profiles( $args );
			if ( empty( $batch ) ) {
				break;
			}

			$profiles = array_merge( $profiles, $batch );
			$page++;
		} while ( count( $batch ) >= 100 );

		return $profiles;
	}

	private function write_people_export_rows( $output, $person_type, array $profiles ) {
		$terms        = $this->terms->list_terms();
		$programs     = get_terms(
			array(
				'taxonomy'   => 'slms_program',
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);
		$programs     = is_wp_error( $programs ) ? array() : $programs;
		$role_options = $this->get_staff_role_filter_options();

		if ( 'student' === $person_type ) {
			fputcsv( $output, array( 'Student Code', 'Full Name', 'Username', 'Email', 'Phone', 'NRC Number', 'Status', 'Program', 'Intake Term', 'Department', 'Notes', 'Invite Sent At', 'Last Active At', 'Created At', 'Updated At' ) );
		} else {
			fputcsv( $output, array( 'Staff Code', 'Full Name', 'Username', 'Email', 'Phone', 'NRC Number', 'Status', 'Role', 'Department', 'Position Title', 'Notes', 'Invite Sent At', 'Last Active At', 'Created At', 'Updated At' ) );
		}

		foreach ( $profiles as $profile ) {
			if ( 'student' === $person_type ) {
				$row = array(
					$profile['person_code'] ?? '',
					$profile['display_name'] ?? '',
					$profile['user_login'] ?? '',
					$profile['user_email'] ?? '',
					$profile['phone'] ?? '',
					$profile['nrc_number'] ?? '',
					$profile['status'] ?? '',
					$this->get_program_label( absint( $profile['program_id'] ?? 0 ), $programs ),
					$this->get_term_label( absint( $profile['intake_term_id'] ?? 0 ), $terms ),
					$profile['department'] ?? '',
					$profile['notes'] ?? '',
					$profile['invite_sent_at'] ?? '',
					$profile['last_active_at'] ?? '',
					$profile['created_at'] ?? '',
					$profile['updated_at'] ?? '',
				);
			} else {
				$role       = $this->get_staff_role_value( absint( $profile['user_id'] ?? 0 ) );
				$role_label = $role_options[ $role ] ?? RoleManager::get_role_label( $role );
				$row        = array(
					$profile['person_code'] ?? '',
					$profile['display_name'] ?? '',
					$profile['user_login'] ?? '',
					$profile['user_email'] ?? '',
					$profile['phone'] ?? '',
					$profile['nrc_number'] ?? '',
					$profile['status'] ?? '',
					$role_label,
					$profile['department'] ?? '',
					$profile['position_title'] ?? '',
					$profile['notes'] ?? '',
					$profile['invite_sent_at'] ?? '',
					$profile['last_active_at'] ?? '',
					$profile['created_at'] ?? '',
					$profile['updated_at'] ?? '',
				);
			}

			fputcsv( $output, array_map( array( $this, 'prepare_csv_cell' ), $row ) );
		}
	}

	private function prepare_csv_cell( $value ) {
		$value = wp_strip_all_tags( (string) $value );
		$value = preg_replace( "/[\r\n\t]+/", ' ', $value );
		$value = trim( $value );

		if ( '' !== $value && preg_match( '/^[=+\-@]/', $value ) ) {
			$value = "'" . $value;
		}

		return $value;
	}

	private function render_record_facts( array $rows ) {
		echo '<div class="slms-record-facts">';
		foreach ( $rows as $row ) {
			if ( empty( $row['label'] ) ) {
				continue;
			}

			echo '<div class="slms-record-fact">';
			echo '<span class="slms-record-fact-label">' . esc_html( $row['label'] ) . '</span>';
			echo '<strong class="slms-record-fact-value">' . esc_html( $row['value'] ?? '' ) . '</strong>';
			echo '</div>';
		}
		echo '</div>';
	}

	private function render_subject_creation_card( array $terms, array $programs, array $lecturers, array $subjects, $compact = false ) {
		?>
		<?php if ( ! $compact ) : ?>
		<section class="slms-portal-panel">
			<h2><?php esc_html_e( 'Create Subject Offering', 'simple-lms' ); ?></h2>
		<?php endif; ?>
			<form method="post" action="" class="slms-form-stack">
				<?php wp_nonce_field( 'slms_academic_tools_action', 'slms_academic_tools_nonce' ); ?>
				<input type="hidden" name="slms_academic_group" value="academics">
				<input type="hidden" name="slms_academic_action" value="create_subject">
				<label><span><?php esc_html_e( 'Subject Title', 'simple-lms' ); ?></span><input type="text" name="subject_title" required></label>
				<div class="slms-form-split">
					<label><span><?php esc_html_e( 'Subject Code', 'simple-lms' ); ?></span><input type="text" name="subject_code"></label>
					<label><span><?php esc_html_e( 'Credits', 'simple-lms' ); ?></span><input type="number" name="credits" step="0.5" min="0"></label>
				</div>
				<div class="slms-form-split">
					<label>
						<span><?php esc_html_e( 'Term', 'simple-lms' ); ?></span>
						<select name="term_id">
							<option value=""><?php esc_html_e( 'Select a term', 'simple-lms' ); ?></option>
							<?php foreach ( $terms as $term ) : ?>
								<option value="<?php echo esc_attr( $term['id'] ); ?>"><?php echo esc_html( $term['name'] . ' (' . $term['academic_year'] . ')' ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label>
						<span><?php esc_html_e( 'Program', 'simple-lms' ); ?></span>
						<select name="program_id">
							<option value=""><?php esc_html_e( 'Select a program', 'simple-lms' ); ?></option>
							<?php foreach ( $programs as $program ) : ?>
								<option value="<?php echo esc_attr( $program->term_id ); ?>"><?php echo esc_html( $program->name ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
				</div>
				<div class="slms-form-split">
					<label>
						<span><?php esc_html_e( 'Delivery Mode', 'simple-lms' ); ?></span>
						<select name="delivery_mode">
							<option value="onsite"><?php esc_html_e( 'Onsite', 'simple-lms' ); ?></option>
							<option value="online"><?php esc_html_e( 'Online', 'simple-lms' ); ?></option>
							<option value="hybrid"><?php esc_html_e( 'Hybrid', 'simple-lms' ); ?></option>
						</select>
					</label>
					<label><span><?php esc_html_e( 'Capacity', 'simple-lms' ); ?></span><input type="number" name="capacity" min="0"></label>
				</div>
				<div class="slms-form-split">
					<label><span><?php esc_html_e( 'Total Classes', 'simple-lms' ); ?></span><input type="number" name="total_classes" min="0" placeholder="16"></label>
					<div class="slms-field-help"><?php esc_html_e( 'Predefine how many class meetings this subject offering should have so attendance opens as numbered weeks.', 'simple-lms' ); ?></div>
				</div>
				<div class="slms-form-split">
					<label>
						<span><?php esc_html_e( 'Catalog Status', 'simple-lms' ); ?></span>
						<select name="subject_status">
							<option value="active"><?php esc_html_e( 'Active', 'simple-lms' ); ?></option>
							<option value="inactive"><?php esc_html_e( 'Inactive', 'simple-lms' ); ?></option>
							<option value="archived"><?php esc_html_e( 'Archived', 'simple-lms' ); ?></option>
						</select>
					</label>
					<label>
						<span><?php esc_html_e( 'Visibility', 'simple-lms' ); ?></span>
						<select name="subject_visibility">
							<option value="publish"><?php esc_html_e( 'Published', 'simple-lms' ); ?></option>
							<option value="draft"><?php esc_html_e( 'Draft', 'simple-lms' ); ?></option>
							<option value="private"><?php esc_html_e( 'Private', 'simple-lms' ); ?></option>
						</select>
					</label>
				</div>
				<label>
					<span><?php esc_html_e( 'Assign Lecturers', 'simple-lms' ); ?></span>
					<select name="lecturer_ids[]" multiple size="5">
						<?php foreach ( $lecturers as $lecturer ) : ?>
							<option value="<?php echo esc_attr( $lecturer->ID ); ?>"><?php echo esc_html( $lecturer->display_name . ' (' . $lecturer->user_email . ')' ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label><span><?php esc_html_e( 'Description', 'simple-lms' ); ?></span><textarea name="subject_description" rows="4"></textarea></label>
				<button type="submit" class="slms-portal-button"><?php esc_html_e( 'Create Subject', 'simple-lms' ); ?></button>
			</form>

			<div class="slms-tool-divider"></div>

			<h3><?php esc_html_e( 'Duplicate Subject to Another Term', 'simple-lms' ); ?></h3>
			<form method="post" action="" class="slms-form-stack">
				<?php wp_nonce_field( 'slms_academic_tools_action', 'slms_academic_tools_nonce' ); ?>
				<input type="hidden" name="slms_academic_group" value="academics">
				<input type="hidden" name="slms_academic_action" value="duplicate_subject">
				<label>
					<span><?php esc_html_e( 'Source Subject', 'simple-lms' ); ?></span>
					<select name="source_subject_id" required>
						<option value=""><?php esc_html_e( 'Choose a subject', 'simple-lms' ); ?></option>
						<?php foreach ( $subjects as $subject ) : ?>
							<option value="<?php echo esc_attr( $subject->ID ); ?>"><?php echo esc_html( $subject->post_title ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label>
					<span><?php esc_html_e( 'Target Term', 'simple-lms' ); ?></span>
					<select name="target_term_id" required>
						<option value=""><?php esc_html_e( 'Choose a term', 'simple-lms' ); ?></option>
						<?php foreach ( $terms as $term ) : ?>
							<option value="<?php echo esc_attr( $term['id'] ); ?>"><?php echo esc_html( $term['name'] . ' (' . $term['academic_year'] . ')' ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label class="slms-inline-checkbox"><input type="checkbox" name="duplicate_content" value="1" checked> <span><?php esc_html_e( 'Copy materials and assignments', 'simple-lms' ); ?></span></label>
				<button type="submit" class="slms-portal-button is-secondary"><?php esc_html_e( 'Duplicate Subject', 'simple-lms' ); ?></button>
			</form>
		<?php if ( ! $compact ) : ?>
		</section>
		<?php endif; ?>
		<?php
	}

	private function render_bulk_subject_duplication_card( array $terms, array $subjects, $compact = false ) {
		?>
		<?php if ( ! $compact ) : ?>
		<section class="slms-portal-panel">
			<h2><?php esc_html_e( 'Duplicate Subjects', 'simple-lms' ); ?></h2>
		<?php endif; ?>
			<form method="post" action="" class="slms-form-stack slms-subject-duplicate-form">
				<?php wp_nonce_field( 'slms_academic_tools_action', 'slms_academic_tools_nonce' ); ?>
				<input type="hidden" name="slms_academic_group" value="academics">
				<input type="hidden" name="slms_academic_action" value="duplicate_subjects">
				<p class="slms-field-help"><?php esc_html_e( 'Create clean yearly copies of selected subjects. Student enrollments, attendance, marks, submissions, and normal discussion replies are never copied.', 'simple-lms' ); ?></p>
				<label>
					<span><?php esc_html_e( 'Target Term', 'simple-lms' ); ?></span>
					<select name="target_term_id" required>
						<option value=""><?php esc_html_e( 'Choose the new term/year', 'simple-lms' ); ?></option>
						<?php foreach ( $terms as $term ) : ?>
							<option value="<?php echo esc_attr( $term['id'] ); ?>"><?php echo esc_html( $term['name'] . ' (' . $term['academic_year'] . ')' ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label><span><?php esc_html_e( 'Title Suffix', 'simple-lms' ); ?></span><input type="text" name="duplicate_title_suffix" placeholder="<?php esc_attr_e( 'Example: 2026 or Semester 1 2026', 'simple-lms' ); ?>"><small class="slms-field-help"><?php esc_html_e( 'Optional. When provided, new subjects are named like “Reading Skills - 2026”.', 'simple-lms' ); ?></small></label>
				<div class="slms-form-stack slms-subject-duplicate-options">
					<span class="slms-field-label"><?php esc_html_e( 'Duplication Options', 'simple-lms' ); ?></span>
					<label class="slms-inline-checkbox"><input type="checkbox" name="duplicate_copy_materials" value="1" checked> <span><?php esc_html_e( 'Copy subject materials / lessons', 'simple-lms' ); ?></span></label>
					<label class="slms-inline-checkbox"><input type="checkbox" name="duplicate_copy_welcome_discussion" value="1" checked> <span><?php esc_html_e( 'Copy the welcome discussion post only', 'simple-lms' ); ?></span></label>
					<label class="slms-inline-checkbox"><input type="checkbox" name="duplicate_copy_lecturers" value="1" checked> <span><?php esc_html_e( 'Copy assigned lecturers', 'simple-lms' ); ?></span></label>
					<label class="slms-inline-checkbox"><input type="checkbox" name="duplicate_copy_assignments" value="1"> <span><?php esc_html_e( 'Copy assignments / grading setup too', 'simple-lms' ); ?></span></label>
				</div>
				<div class="slms-form-stack slms-subject-duplicate-list">
					<span class="slms-field-label"><?php esc_html_e( 'Subjects to Duplicate', 'simple-lms' ); ?></span>
					<?php if ( empty( $subjects ) ) : ?>
						<p class="slms-field-help"><?php esc_html_e( 'No subjects are available to duplicate yet.', 'simple-lms' ); ?></p>
					<?php else : ?>
						<?php foreach ( $subjects as $subject ) : ?>
							<?php
							$term_id      = absint( get_post_meta( $subject->ID, '_slms_term_id', true ) );
							$subject_code = get_post_meta( $subject->ID, '_slms_subject_code', true );
							$meta         = array_filter(
								array(
									$subject_code,
									$this->get_term_label( $term_id, $terms ),
								)
							);
							?>
							<label class="slms-inline-checkbox slms-subject-duplicate-option">
								<input type="checkbox" name="source_subject_ids[]" value="<?php echo esc_attr( $subject->ID ); ?>">
								<span><strong><?php echo esc_html( $subject->post_title ); ?></strong><?php echo $meta ? ' <small>' . esc_html( implode( ' | ', $meta ) ) . '</small>' : ''; ?></span>
							</label>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>
				<button type="submit" class="slms-portal-button" onclick="return confirm('<?php echo esc_js( __( 'Duplicate the selected subjects into the target term? Student enrollments and records will not be copied.', 'simple-lms' ) ); ?>');"><?php esc_html_e( 'Duplicate Selected Subjects', 'simple-lms' ); ?></button>
			</form>
		<?php if ( ! $compact ) : ?>
		</section>
		<?php endif; ?>
		<?php
	}

	private function render_subject_management_card( array $subjects, array $terms, array $programs, array $lecturers ) {
		?>
		<section class="slms-portal-panel">
			<h2><?php esc_html_e( 'Manage Subjects', 'simple-lms' ); ?></h2>
			<p class="slms-field-help"><?php esc_html_e( 'Open a subject to adjust its semester, lecturers, delivery settings, or description from the frontend.', 'simple-lms' ); ?></p>
			<div class="slms-record-stack">
				<?php if ( empty( $subjects ) ) : ?>
					<p class="slms-field-help"><?php esc_html_e( 'No subjects are available yet.', 'simple-lms' ); ?></p>
				<?php else : ?>
					<?php foreach ( $subjects as $subject ) : ?>
						<?php
						$subject_code   = get_post_meta( $subject->ID, '_slms_subject_code', true );
						$credits        = get_post_meta( $subject->ID, '_slms_credits', true );
						$term_id        = absint( get_post_meta( $subject->ID, '_slms_term_id', true ) );
						$subject_status = get_post_meta( $subject->ID, '_slms_subject_status', true ) ?: 'active';
						$capacity       = absint( get_post_meta( $subject->ID, '_slms_capacity', true ) );
						$total_classes  = absint( get_post_meta( $subject->ID, '_slms_total_classes', true ) );
						$delivery_mode  = get_post_meta( $subject->ID, '_slms_delivery_mode', true ) ?: 'onsite';
						$program_ids    = wp_get_object_terms( $subject->ID, 'slms_program', array( 'fields' => 'ids' ) );
						$program_id     = ! is_wp_error( $program_ids ) && ! empty( $program_ids ) ? (int) $program_ids[0] : 0;
						$assigned_staff = $this->enrollments->get_section_staff( $subject->ID, 'lecturer' );
						$assigned_ids   = array_map( 'intval', wp_list_pluck( $assigned_staff, 'user_id' ) );
						?>
						<details class="slms-record-card slms-subject-management-record">
							<summary>
								<div>
									<strong><?php echo esc_html( $subject->post_title ); ?></strong>
									<?php
									$assigned_names = array_map(
										static function ( $assigned_staff_member ) {
											return $assigned_staff_member['display_name'];
										},
										$assigned_staff
									);
									$summary_bits   = array_filter(
										array(
											$subject_code ?: '',
											! empty( $assigned_names ) ? implode( ', ', $assigned_names ) : '',
										)
									);
									?>
									<?php if ( ! empty( $summary_bits ) ) : ?>
										<span><?php echo esc_html( implode( ' | ', $summary_bits ) ); ?></span>
									<?php endif; ?>
								</div>
								<span><?php echo esc_html( $this->get_term_label( $term_id, $terms ) ); ?></span>
							</summary>
							<div class="slms-record-card-body" data-slms-record-body>
								<div class="slms-record-view" data-slms-record-view>
									<?php
									$subject_facts = array(
										array(
											'label' => __( 'Subject Code', 'simple-lms' ),
											'value' => $subject_code ?: __( 'Not assigned', 'simple-lms' ),
										),
										array(
											'label' => __( 'Credits', 'simple-lms' ),
											'value' => '' !== (string) $credits ? (string) $credits : __( 'Not set', 'simple-lms' ),
										),
										array(
											'label' => __( 'Term', 'simple-lms' ),
											'value' => $this->get_term_label( $term_id, $terms ),
										),
										array(
											'label' => __( 'Program', 'simple-lms' ),
											'value' => $this->get_program_label( $program_id, $programs ),
										),
										array(
											'label' => __( 'Delivery Mode', 'simple-lms' ),
											'value' => ucfirst( $delivery_mode ),
										),
										array(
											'label' => __( 'Capacity', 'simple-lms' ),
											'value' => $capacity ? (string) $capacity : __( 'Not set', 'simple-lms' ),
										),
										array(
											'label' => __( 'Total Classes', 'simple-lms' ),
											'value' => $total_classes ? (string) $total_classes : __( 'Not set', 'simple-lms' ),
										),
										array(
											'label' => __( 'Catalog Status', 'simple-lms' ),
											'value' => ucfirst( $subject_status ),
										),
										array(
											'label' => __( 'Visibility', 'simple-lms' ),
											'value' => ucfirst( $subject->post_status ),
										),
									);

									if ( ! empty( $assigned_names ) ) {
										$subject_facts[] = array(
											'label' => __( 'Assigned Lecturers', 'simple-lms' ),
											'value' => implode( ', ', $assigned_names ),
										);
									}

									$subject_facts[] = array(
										'label' => __( 'Description', 'simple-lms' ),
										'value' => wp_strip_all_tags( $subject->post_content ) ?: __( 'No description yet.', 'simple-lms' ),
									);

									$this->render_record_facts( $subject_facts );
									?>
									<?php if ( ! empty( $assigned_staff ) ) : ?>
										<div class="slms-subject-assignment-strip">
											<div class="slms-inline-editor-header">
												<strong><?php esc_html_e( 'Lecturer Assignments', 'simple-lms' ); ?></strong>
												<span><?php esc_html_e( 'Use Remove to unassign a lecturer instantly from this subject.', 'simple-lms' ); ?></span>
											</div>
											<div class="slms-subject-assignment-list">
												<?php foreach ( $assigned_staff as $assigned_staff_member ) : ?>
													<div class="slms-subject-assignment-item">
														<div class="slms-subject-assignment-copy">
															<strong><?php echo $this->get_profile_avatar_name_markup( $assigned_staff_member, 'is-compact' ); ?></strong>
															<span><?php echo esc_html( $assigned_staff_member['user_email'] ); ?></span>
														</div>
														<form method="post" action="">
															<?php wp_nonce_field( 'slms_academic_tools_action', 'slms_academic_tools_nonce' ); ?>
															<input type="hidden" name="slms_academic_group" value="academics">
															<input type="hidden" name="slms_academic_action" value="remove_subject_lecturer">
															<input type="hidden" name="subject_id" value="<?php echo esc_attr( $subject->ID ); ?>">
															<input type="hidden" name="lecturer_id" value="<?php echo esc_attr( (int) $assigned_staff_member['user_id'] ); ?>">
															<button type="submit" class="slms-portal-button is-secondary is-small" onclick="return confirm('<?php echo esc_js( sprintf( __( 'Unassign %s from this subject?', 'simple-lms' ), $assigned_staff_member['display_name'] ) ); ?>');"><?php esc_html_e( 'Remove', 'simple-lms' ); ?></button>
														</form>
													</div>
												<?php endforeach; ?>
											</div>
										</div>
									<?php endif; ?>
									<div class="slms-record-actions">
										<button type="button" class="slms-portal-button is-secondary" data-slms-record-edit-toggle><?php esc_html_e( 'Edit Subject', 'simple-lms' ); ?></button>
									</div>
								</div>
								<form method="post" action="" class="slms-inline-editor slms-record-edit-form" data-slms-record-edit-form hidden>
									<?php wp_nonce_field( 'slms_academic_tools_action', 'slms_academic_tools_nonce' ); ?>
									<input type="hidden" name="slms_academic_group" value="academics">
									<input type="hidden" name="slms_academic_action" value="update_subject">
									<input type="hidden" name="subject_id" value="<?php echo esc_attr( $subject->ID ); ?>">
									<label><span><?php esc_html_e( 'Subject Title', 'simple-lms' ); ?></span><input type="text" name="subject_title" value="<?php echo esc_attr( $subject->post_title ); ?>" required></label>
									<div class="slms-form-split">
										<label><span><?php esc_html_e( 'Subject Code', 'simple-lms' ); ?></span><input type="text" name="subject_code" value="<?php echo esc_attr( $subject_code ); ?>"></label>
										<label><span><?php esc_html_e( 'Credits', 'simple-lms' ); ?></span><input type="number" name="credits" step="0.5" min="0" value="<?php echo esc_attr( (string) $credits ); ?>"></label>
									</div>
									<div class="slms-form-split">
										<label>
											<span><?php esc_html_e( 'Term', 'simple-lms' ); ?></span>
											<select name="term_id">
												<option value=""><?php esc_html_e( 'Select a term', 'simple-lms' ); ?></option>
												<?php foreach ( $terms as $term ) : ?>
													<option value="<?php echo esc_attr( $term['id'] ); ?>" <?php selected( $term_id, (int) $term['id'] ); ?>><?php echo esc_html( $term['name'] . ' (' . $term['academic_year'] . ')' ); ?></option>
												<?php endforeach; ?>
											</select>
										</label>
										<label>
											<span><?php esc_html_e( 'Program', 'simple-lms' ); ?></span>
											<select name="program_id">
												<option value=""><?php esc_html_e( 'Select a program', 'simple-lms' ); ?></option>
												<?php foreach ( $programs as $program ) : ?>
													<option value="<?php echo esc_attr( $program->term_id ); ?>" <?php selected( $program_id, (int) $program->term_id ); ?>><?php echo esc_html( $program->name ); ?></option>
												<?php endforeach; ?>
											</select>
										</label>
									</div>
									<div class="slms-form-split">
										<label>
											<span><?php esc_html_e( 'Delivery Mode', 'simple-lms' ); ?></span>
											<select name="delivery_mode">
												<option value="onsite" <?php selected( $delivery_mode, 'onsite' ); ?>><?php esc_html_e( 'Onsite', 'simple-lms' ); ?></option>
												<option value="online" <?php selected( $delivery_mode, 'online' ); ?>><?php esc_html_e( 'Online', 'simple-lms' ); ?></option>
												<option value="hybrid" <?php selected( $delivery_mode, 'hybrid' ); ?>><?php esc_html_e( 'Hybrid', 'simple-lms' ); ?></option>
											</select>
										</label>
										<label><span><?php esc_html_e( 'Capacity', 'simple-lms' ); ?></span><input type="number" name="capacity" min="0" value="<?php echo esc_attr( (string) $capacity ); ?>"></label>
									</div>
									<div class="slms-form-split">
										<label><span><?php esc_html_e( 'Total Classes', 'simple-lms' ); ?></span><input type="number" name="total_classes" min="0" value="<?php echo esc_attr( (string) $total_classes ); ?>"></label>
										<div class="slms-field-help"><?php esc_html_e( 'Use the planned number of meetings so the attendance board opens as Week 1, Week 2, and so on.', 'simple-lms' ); ?></div>
									</div>
									<div class="slms-form-split">
										<label>
											<span><?php esc_html_e( 'Catalog Status', 'simple-lms' ); ?></span>
											<select name="subject_status">
												<option value="active" <?php selected( $subject_status, 'active' ); ?>><?php esc_html_e( 'Active', 'simple-lms' ); ?></option>
												<option value="inactive" <?php selected( $subject_status, 'inactive' ); ?>><?php esc_html_e( 'Inactive', 'simple-lms' ); ?></option>
												<option value="archived" <?php selected( $subject_status, 'archived' ); ?>><?php esc_html_e( 'Archived', 'simple-lms' ); ?></option>
											</select>
										</label>
										<label>
											<span><?php esc_html_e( 'Visibility', 'simple-lms' ); ?></span>
											<select name="subject_visibility">
												<option value="publish" <?php selected( $subject->post_status, 'publish' ); ?>><?php esc_html_e( 'Published', 'simple-lms' ); ?></option>
												<option value="draft" <?php selected( $subject->post_status, 'draft' ); ?>><?php esc_html_e( 'Draft', 'simple-lms' ); ?></option>
												<option value="private" <?php selected( $subject->post_status, 'private' ); ?>><?php esc_html_e( 'Private', 'simple-lms' ); ?></option>
											</select>
										</label>
									</div>
									<label>
										<span><?php esc_html_e( 'Assigned Lecturers', 'simple-lms' ); ?></span>
										<select name="lecturer_ids[]" multiple size="5">
											<?php foreach ( $lecturers as $lecturer ) : ?>
												<option value="<?php echo esc_attr( $lecturer->ID ); ?>" <?php selected( in_array( $lecturer->ID, $assigned_ids, true ) ); ?>><?php echo esc_html( $lecturer->display_name . ' (' . $lecturer->user_email . ')' ); ?></option>
											<?php endforeach; ?>
										</select>
									</label>
									<label><span><?php esc_html_e( 'Description', 'simple-lms' ); ?></span><textarea name="subject_description" rows="4"><?php echo esc_textarea( $subject->post_content ); ?></textarea></label>
									<div class="slms-record-actions">
										<button type="submit" class="slms-portal-button is-secondary"><?php esc_html_e( 'Save Subject', 'simple-lms' ); ?></button>
										<button type="submit" class="slms-portal-button is-danger" form="slms-delete-subject-<?php echo esc_attr( $subject->ID ); ?>" formnovalidate onclick="return confirm('<?php echo esc_js( __( 'Remove this subject? This cannot be undone.', 'simple-lms' ) ); ?>');"><?php esc_html_e( 'Remove Subject', 'simple-lms' ); ?></button>
										<?php if ( $this->is_administrator() ) : ?>
											<button type="submit" class="slms-portal-button is-danger" form="slms-force-delete-subject-<?php echo esc_attr( $subject->ID ); ?>" formnovalidate onclick="return confirm('<?php echo esc_js( __( 'Force remove this subject and permanently delete all linked lecturers, enrolments, assignments, materials, discussions, grades, attendance, and submissions?', 'simple-lms' ) ); ?>');"><?php esc_html_e( 'Force Remove Subject', 'simple-lms' ); ?></button>
										<?php endif; ?>
										<button type="button" class="slms-portal-button is-secondary" data-slms-record-edit-cancel><?php esc_html_e( 'Cancel', 'simple-lms' ); ?></button>
									</div>
								</form>
								<form method="post" action="" id="slms-delete-subject-<?php echo esc_attr( $subject->ID ); ?>" hidden>
									<?php wp_nonce_field( 'slms_academic_tools_action', 'slms_academic_tools_nonce' ); ?>
									<input type="hidden" name="slms_academic_group" value="academics">
									<input type="hidden" name="slms_academic_action" value="delete_subject">
									<input type="hidden" name="subject_id" value="<?php echo esc_attr( $subject->ID ); ?>">
								</form>
								<?php if ( $this->is_administrator() ) : ?>
									<form method="post" action="" id="slms-force-delete-subject-<?php echo esc_attr( $subject->ID ); ?>" hidden>
										<?php wp_nonce_field( 'slms_academic_tools_action', 'slms_academic_tools_nonce' ); ?>
										<input type="hidden" name="slms_academic_group" value="academics">
										<input type="hidden" name="slms_academic_action" value="force_delete_subject">
										<input type="hidden" name="subject_id" value="<?php echo esc_attr( $subject->ID ); ?>">
									</form>
								<?php endif; ?>
							</div>
						</details>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>
		</section>
		<?php
	}

	private function render_targeted_import_management_card( $target, $compact = false ) {
		$config = $this->get_import_target_config( $target );
		if ( empty( $config ) ) {
			return;
		}
		?>
		<?php if ( ! $compact ) : ?>
		<section class="slms-portal-panel">
			<h2><?php echo esc_html( $config['title'] ); ?></h2>
			<p class="slms-field-help"><?php echo esc_html( $config['description'] ); ?></p>
		<?php endif; ?>
			<?php $this->render_import_template_links( array( $target ) ); ?>
			<form method="post" action="" enctype="multipart/form-data" class="slms-form-stack">
				<?php wp_nonce_field( 'slms_academic_tools_action', 'slms_academic_tools_nonce' ); ?>
				<input type="hidden" name="slms_academic_group" value="people">
				<input type="hidden" name="slms_academic_action" value="import_data">
				<input type="hidden" name="import_target" value="<?php echo esc_attr( $target ); ?>">
				<label><span><?php esc_html_e( 'Google Sheet CSV URL (optional)', 'simple-lms' ); ?></span><input type="url" name="import_sheet_url" placeholder="https://docs.google.com/spreadsheets/d/.../export?format=csv"></label>
				<label><span><?php echo esc_html( $config['upload_label'] ); ?></span><input type="file" name="import_file" accept=".csv,.xlsx"></label>
				<p class="slms-field-help"><?php esc_html_e( 'Supported formats: CSV, Excel (.xlsx), and published Google Sheet CSV links.', 'simple-lms' ); ?></p>
				<button type="submit" class="slms-portal-button is-secondary"><?php echo esc_html( $config['button_label'] ); ?></button>
			</form>
		<?php if ( ! $compact ) : ?>
		</section>
		<?php endif; ?>
		<?php
	}

	private function get_import_target_config( $target ) {
		$configs = array(
			'students' => array(
				'title'       => __( 'Import Students', 'simple-lms' ),
				'description' => __( 'Upload student accounts in bulk from CSV, Excel, or a published Google Sheet.', 'simple-lms' ),
				'upload_label'=> __( 'Upload Student CSV / XLSX', 'simple-lms' ),
				'button_label'=> __( 'Run Student Import', 'simple-lms' ),
			),
			'staff'    => array(
				'title'       => __( 'Import Staff', 'simple-lms' ),
				'description' => __( 'Upload staff, lecturers, officers, editors, and administrators in bulk from CSV, Excel, or a published Google Sheet.', 'simple-lms' ),
				'upload_label'=> __( 'Upload Staff CSV / XLSX', 'simple-lms' ),
				'button_label'=> __( 'Run Staff Import', 'simple-lms' ),
			),
			'subjects' => array(
				'title'       => __( 'Import Subjects', 'simple-lms' ),
				'description' => __( 'Upload subject offerings in bulk, including term mapping, program code mapping, and lecturer assignments.', 'simple-lms' ),
				'upload_label'=> __( 'Upload Subject CSV / XLSX', 'simple-lms' ),
				'button_label'=> __( 'Run Subject Import', 'simple-lms' ),
			),
		);

		return $configs[ $target ] ?? array();
	}

	private function validate_student_deletion( $user_id ) {
		$table_checks = array(
			Schema::table( 'enrollments' )            => __( 'This student is still enrolled in one or more subjects. Unenroll the student first.', 'simple-lms' ),
			Schema::table( 'assignment_submissions' ) => __( 'This student has assignment submissions on record and cannot be removed.', 'simple-lms' ),
			Schema::table( 'grade_scores' )           => __( 'This student has recorded grades and cannot be removed.', 'simple-lms' ),
			Schema::table( 'attendance_records' )     => __( 'This student has attendance records and cannot be removed.', 'simple-lms' ),
		);

		foreach ( $table_checks as $table => $message ) {
			if ( $this->count_rows( $table, 'student_user_id', $user_id ) > 0 ) {
				return new \WP_Error( 'slms_student_in_use', $message );
			}
		}

		return true;
	}

	private function validate_staff_deletion( $user_id ) {
		$role = $this->get_staff_role_value( $user_id );

		if ( 'administrator' === $role ) {
			$other_admins = get_users(
				array(
					'role'    => 'administrator',
					'exclude' => array( $user_id ),
					'fields'  => 'ids',
					'number'  => 1,
				)
			);

			if ( empty( $other_admins ) ) {
				return new \WP_Error( 'slms_last_admin', __( 'You cannot remove the last administrator account.', 'simple-lms' ) );
			}
		}

		$table_checks = array(
			array(
				'table'   => Schema::table( 'section_staff' ),
				'column'  => 'user_id',
				'message' => __( 'This staff member is still assigned to one or more subjects. Remove those assignments first.', 'simple-lms' ),
			),
			array(
				'table'   => Schema::table( 'assignment_submissions' ),
				'column'  => 'graded_by',
				'message' => __( 'This staff member has grading records on file and cannot be removed.', 'simple-lms' ),
			),
			array(
				'table'   => Schema::table( 'grade_categories' ),
				'column'  => 'created_by',
				'message' => __( 'This staff member has gradebook records on file and cannot be removed.', 'simple-lms' ),
			),
			array(
				'table'   => Schema::table( 'grade_categories' ),
				'column'  => 'updated_by',
				'message' => __( 'This staff member has gradebook records on file and cannot be removed.', 'simple-lms' ),
			),
			array(
				'table'   => Schema::table( 'grade_items' ),
				'column'  => 'created_by',
				'message' => __( 'This staff member has gradebook records on file and cannot be removed.', 'simple-lms' ),
			),
			array(
				'table'   => Schema::table( 'grade_items' ),
				'column'  => 'updated_by',
				'message' => __( 'This staff member has gradebook records on file and cannot be removed.', 'simple-lms' ),
			),
			array(
				'table'   => Schema::table( 'grade_scores' ),
				'column'  => 'graded_by',
				'message' => __( 'This staff member has recorded grades on file and cannot be removed.', 'simple-lms' ),
			),
			array(
				'table'   => Schema::table( 'attendance_sessions' ),
				'column'  => 'created_by',
				'message' => __( 'This staff member has attendance sessions on file and cannot be removed.', 'simple-lms' ),
			),
			array(
				'table'   => Schema::table( 'attendance_sessions' ),
				'column'  => 'updated_by',
				'message' => __( 'This staff member has attendance sessions on file and cannot be removed.', 'simple-lms' ),
			),
			array(
				'table'   => Schema::table( 'attendance_records' ),
				'column'  => 'marked_by',
				'message' => __( 'This staff member has attendance records on file and cannot be removed.', 'simple-lms' ),
			),
		);

		foreach ( $table_checks as $check ) {
			if ( $this->count_rows( $check['table'], $check['column'], $user_id ) > 0 ) {
				return new \WP_Error( 'slms_staff_in_use', $check['message'] );
			}
		}

		return true;
	}

	private function get_delete_reassign_user_id( $excluded_user_id ) {
		$current_user_id = get_current_user_id();

		if ( $current_user_id && $current_user_id !== (int) $excluded_user_id ) {
			return $current_user_id;
		}

		$fallback_users = get_users(
			array(
				'role__in' => array( 'administrator', 'officer' ),
				'exclude'  => array( $excluded_user_id ),
				'fields'   => 'ids',
				'number'   => 1,
				'orderby'  => 'ID',
				'order'    => 'ASC',
			)
		);

		return ! empty( $fallback_users ) ? (int) $fallback_users[0] : 0;
	}

	private function force_delete_subject_posts( $post_type, $subject_meta_key, $subject_id, $item_type = '', $delete_comment_attachments = false ) {
		$posts = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => array( 'publish', 'draft', 'private', 'pending', 'future' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => $subject_meta_key,
				'meta_value'     => $subject_id,
			)
		);

		foreach ( $posts as $post_id ) {
			$post_id = absint( $post_id );

			if ( ! $post_id ) {
				continue;
			}

			if ( $item_type ) {
				$this->delete_subject_post_attachments( $post_id, $item_type );
			}

			if ( $delete_comment_attachments ) {
				$this->delete_discussion_comment_attachments( $post_id );
			}

			wp_delete_post( $post_id, true );
		}
	}

	private function delete_subject_post_attachments( $post_id, $item_type ) {
		$config = $this->get_subject_force_delete_item_config( $item_type );

		if ( empty( $config ) ) {
			return;
		}

		$attachment_ids = $this->normalize_attachment_ids( get_post_meta( $post_id, $config['attachment_meta_key'], true ) );
		$managed_files  = $this->normalize_managed_attachment_records( get_post_meta( $post_id, $config['managed_attachment_meta_key'], true ) );
		$legacy_url     = ! empty( $config['legacy_url_meta_key'] ) ? esc_url_raw( (string) get_post_meta( $post_id, $config['legacy_url_meta_key'], true ) ) : '';
		$legacy_id      = ! empty( $config['legacy_attachment_id_meta_key'] ) ? absint( get_post_meta( $post_id, $config['legacy_attachment_id_meta_key'], true ) ) : 0;

		if ( $legacy_id ) {
			$attachment_ids[] = $legacy_id;
		}

		foreach ( array_values( array_unique( array_filter( array_map( 'absint', $attachment_ids ) ) ) ) as $attachment_id ) {
			wp_delete_attachment( $attachment_id, true );
		}

		foreach ( $managed_files as $managed_file ) {
			$this->delete_subject_managed_upload_file( $managed_file );
		}

		if ( $legacy_url ) {
			$legacy_path = $this->map_upload_url_to_path( $legacy_url );

			if ( $legacy_path && file_exists( $legacy_path ) ) {
				wp_delete_file( $legacy_path );
			}
		}
	}

	private function delete_subject_submission_files( $subject_id ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT attachment_url FROM ' . Schema::table( 'assignment_submissions' ) . ' WHERE section_id = %d',
				$subject_id
			),
			ARRAY_A
		);

		foreach ( $rows as $row ) {
			$file_path = $this->map_upload_url_to_path( $row['attachment_url'] ?? '' );

			if ( $file_path && file_exists( $file_path ) ) {
				wp_delete_file( $file_path );
			}
		}
	}

	private function delete_discussion_comment_attachments( $post_id ) {
		$comments = get_comments(
			array(
				'post_id' => absint( $post_id ),
				'status'  => 'all',
			)
		);

		foreach ( $comments as $comment ) {
			$attachment_url = esc_url_raw( (string) get_comment_meta( $comment->comment_ID, '_slms_attachment_url', true ) );
			$file_path      = $this->map_upload_url_to_path( $attachment_url );

			if ( $file_path && file_exists( $file_path ) ) {
				wp_delete_file( $file_path );
			}
		}
	}

	private function get_subject_force_delete_item_config( $item_type ) {
		$configs = array(
			'materials'   => array(
				'attachment_meta_key'           => '_slms_material_attachment_ids',
				'managed_attachment_meta_key'   => '_slms_material_managed_attachments',
				'legacy_url_meta_key'           => '_slms_material_file_url',
				'legacy_attachment_id_meta_key' => '',
			),
			'assignments' => array(
				'attachment_meta_key'           => '_slms_assignment_attachment_ids',
				'managed_attachment_meta_key'   => '_slms_assignment_managed_attachments',
				'legacy_url_meta_key'           => '_slms_assignment_file_url',
				'legacy_attachment_id_meta_key' => '',
			),
			'discussions' => array(
				'attachment_meta_key'           => '_slms_thread_attachment_ids',
				'managed_attachment_meta_key'   => '_slms_thread_managed_attachments',
				'legacy_url_meta_key'           => '_slms_attachment_url',
				'legacy_attachment_id_meta_key' => '_slms_attachment_id',
			),
		);

		return $configs[ $item_type ] ?? array();
	}

	private function normalize_attachment_ids( $value ) {
		if ( empty( $value ) ) {
			return array();
		}

		if ( ! is_array( $value ) ) {
			$value = maybe_unserialize( $value );
		}

		if ( ! is_array( $value ) ) {
			$value = array( $value );
		}

		return array_values( array_unique( array_filter( array_map( 'absint', $value ) ) ) );
	}

	private function normalize_managed_attachment_records( $value ) {
		if ( empty( $value ) ) {
			return array();
		}

		if ( ! is_array( $value ) ) {
			$value = maybe_unserialize( $value );
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$records = array();
		$seen    = array();

		foreach ( $value as $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}

			$relative_path = ltrim( str_replace( '\\', '/', sanitize_text_field( (string) ( $record['relative_path'] ?? '' ) ) ), '/' );
			$url           = esc_url_raw( (string) ( $record['url'] ?? '' ) );
			$key           = $relative_path ?: $url;

			if ( '' === $key || isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$records[]    = array(
				'relative_path' => $relative_path,
				'url'           => $url,
			);
		}

		return $records;
	}

	private function delete_subject_managed_upload_file( array $attachment ) {
		$file_path = '';
		$uploads   = wp_get_upload_dir();
		$basedir   = trailingslashit( (string) ( $uploads['basedir'] ?? '' ) );

		if ( ! empty( $attachment['relative_path'] ) && '' !== $basedir ) {
			$file_path = wp_normalize_path( $basedir . ltrim( (string) $attachment['relative_path'], '/' ) );
		}

		if ( '' === $file_path ) {
			$file_path = $this->map_upload_url_to_path( $attachment['url'] ?? '' );
		}

		if ( $file_path && file_exists( $file_path ) ) {
			wp_delete_file( $file_path );
		}
	}

	private function map_upload_url_to_path( $url ) {
		$url = esc_url_raw( (string) $url );

		if ( '' === $url ) {
			return '';
		}

		$uploads = wp_get_upload_dir();
		$baseurl = trailingslashit( (string) ( $uploads['baseurl'] ?? '' ) );
		$basedir = trailingslashit( (string) ( $uploads['basedir'] ?? '' ) );

		if ( '' === $baseurl || '' === $basedir || 0 !== strpos( $url, $baseurl ) ) {
			return '';
		}

		return wp_normalize_path( $basedir . ltrim( substr( $url, strlen( $baseurl ) ), '/' ) );
	}

	private function delete_rows_by_column( $table, $column, $value ) {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE {$column} = %d",
				absint( $value )
			)
		);
	}

	private function count_rows( $table, $column, $value ) {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE {$column} = %d",
				absint( $value )
			)
		);
	}

	private function term_payload_from_request() {
		return array(
			'code'          => sanitize_text_field( wp_unslash( $_POST['term_code'] ?? '' ) ),
			'name'          => sanitize_text_field( wp_unslash( $_POST['term_name'] ?? '' ) ),
			'academic_year' => sanitize_text_field( wp_unslash( $_POST['term_academic_year'] ?? '' ) ),
			'start_date'    => sanitize_text_field( wp_unslash( $_POST['term_start_date'] ?? '' ) ),
			'end_date'      => sanitize_text_field( wp_unslash( $_POST['term_end_date'] ?? '' ) ),
			'status'        => sanitize_key( wp_unslash( $_POST['term_status'] ?? 'planned' ) ),
			'sort_order'    => intval( $_POST['term_sort_order'] ?? 0 ),
		);
	}

	private function get_term_label( $term_id, array $terms ) {
		if ( ! $term_id ) {
			return __( 'No term assigned', 'simple-lms' );
		}

		foreach ( $terms as $term ) {
			if ( (int) $term['id'] === (int) $term_id ) {
				return $term['name'] . ' (' . $term['academic_year'] . ')';
			}
		}

		return __( 'Unknown term', 'simple-lms' );
	}

	private function sanitize_subject_status( $status ) {
		$status = sanitize_key( $status );

		if ( ! in_array( $status, array( 'active', 'inactive', 'archived' ), true ) ) {
			return 'active';
		}

		return $status;
	}

	private function sanitize_delivery_mode( $mode ) {
		$mode = sanitize_key( $mode );

		if ( ! in_array( $mode, array( 'onsite', 'online', 'hybrid' ), true ) ) {
			return 'onsite';
		}

		return $mode;
	}

	private function sanitize_post_visibility( $status ) {
		$status = sanitize_key( $status );

		if ( ! in_array( $status, array( 'publish', 'draft', 'private' ), true ) ) {
			return 'publish';
		}

		return $status;
	}

	private function redirect_with_result( $redirect, $result, $success_message ) {
		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( add_query_arg( array( 'slms_notice' => rawurlencode( $result->get_error_message() ), 'slms_notice_type' => 'error' ), $redirect ) );
			exit;
		}

		if ( is_array( $result ) ) {
			$bits = array();

			foreach ( array( 'created', 'updated', 'enrolled', 'unenrolled', 'failed' ) as $key ) {
				if ( isset( $result[ $key ] ) ) {
					$bits[] = sprintf( '%s: %d', ucfirst( $key ), (int) $result[ $key ] );
				}
			}

			$message = trim( $success_message . ' ' . implode( ' | ', $bits ) );

			if ( ! empty( $result['errors'] ) ) {
				$message .= ' ' . implode( ' ', array_slice( $result['errors'], 0, 3 ) );
			}

			wp_safe_redirect( add_query_arg( array( 'slms_notice' => rawurlencode( $message ), 'slms_notice_type' => ! empty( $result['failed'] ) ? 'warning' : 'success' ), $redirect ) );
			exit;
		}

		wp_safe_redirect( add_query_arg( array( 'slms_notice' => rawurlencode( $success_message ), 'slms_notice_type' => 'success' ), $redirect ) );
		exit;
	}

	private function get_profile_avatar_name_markup( array $profile, $class = '' ) {
		$user_id = absint( $profile['user_id'] ?? 0 );
		$name    = trim( (string) ( $profile['display_name'] ?? '' ) );
		$name    = '' !== $name ? $name : __( 'Unknown', 'simple-lms' );
		$classes = trim( 'slms-person-inline ' . (string) $class );

		return sprintf(
			'<span class="%1$s"><img class="slms-person-avatar" src="%2$s" alt="" loading="lazy" decoding="async"><span class="slms-person-name">%3$s</span></span>',
			esc_attr( $classes ),
			esc_url( $this->profiles->get_profile_photo_url( $user_id, 96 ) ),
			esc_html( $name )
		);
	}

	private function render_import_template_links( array $targets = array() ) {
		$templates = array(
			array(
				'target'      => 'students',
				'label'       => __( 'Student Template', 'simple-lms' ),
				'description' => __( 'Includes optional NRC number, program name, and intake term code columns for easier imports.', 'simple-lms' ),
				'csv_url'     => SLMS_URL . 'assets/templates/students-import-template.csv',
				'xlsx_url'    => SLMS_URL . 'assets/templates/students-import-template.xlsx',
			),
			array(
				'target'      => 'staff',
				'label'       => __( 'Staff Template', 'simple-lms' ),
				'description' => __( 'Includes optional NRC number and covers staff, lecturers, officers, and administrators with role-based import columns.', 'simple-lms' ),
				'csv_url'     => SLMS_URL . 'assets/templates/staff-import-template.csv',
				'xlsx_url'    => SLMS_URL . 'assets/templates/staff-import-template.xlsx',
			),
			array(
				'target'      => 'subjects',
				'label'       => __( 'Subject Template', 'simple-lms' ),
				'description' => __( 'Includes term codes, program codes, delivery mode, and lecturer code mapping.', 'simple-lms' ),
				'csv_url'     => SLMS_URL . 'assets/templates/subjects-import-template.csv',
				'xlsx_url'    => SLMS_URL . 'assets/templates/subjects-import-template.xlsx',
			),
		);

		echo '<div class="slms-manage-list"><h4>' . esc_html__( 'Download Templates', 'simple-lms' ) . '</h4>';
		foreach ( $templates as $template ) {
			if ( ! empty( $targets ) && ! in_array( $template['target'], $targets, true ) ) {
				continue;
			}
			echo '<div class="slms-manage-row">';
			echo '<div class="slms-manage-meta"><strong>' . esc_html( $template['label'] ) . '</strong><span>' . esc_html( $template['description'] ) . '</span></div>';
			echo '<div class="slms-row-actions">';
			echo '<a class="slms-portal-button is-secondary is-small" href="' . esc_url( $template['csv_url'] ) . '" download>' . esc_html__( 'Download CSV', 'simple-lms' ) . '</a>';
			echo '<a class="slms-portal-button is-secondary is-small" href="' . esc_url( $template['xlsx_url'] ) . '" download>' . esc_html__( 'Download Spreadsheet', 'simple-lms' ) . '</a>';
			echo '</div>';
			echo '</div>';
		}
		echo '</div>';
	}

	private function render_pagination_controls( $page_arg, $current_page, $max_pages, $open_group = '' ) {
		if ( $max_pages <= 1 ) {
			return;
		}

		$base_url = remove_query_arg( array( 'slms_notice', 'slms_notice_type' ), $this->get_current_page_url() );
		$prev_url = add_query_arg( array( $page_arg => $current_page - 1 ), $base_url );
		$next_url = add_query_arg( array( $page_arg => $current_page + 1 ), $base_url );

		if ( '' !== $open_group ) {
			$prev_url = add_query_arg( array( 'slms_open' => $open_group ), $prev_url );
			$next_url = add_query_arg( array( 'slms_open' => $open_group ), $next_url );
		}

		echo '<div class="slms-pagination-bar">';
		if ( $current_page > 1 ) {
			echo '<a class="slms-portal-button is-secondary is-small" href="' . esc_url( $prev_url ) . '">' . esc_html__( '< Newer', 'simple-lms' ) . '</a>';
		} else {
			echo '<span class="slms-pagination-spacer"></span>';
		}

		echo '<span class="slms-pagination-status">' . esc_html( sprintf( __( 'Page %1$d of %2$d', 'simple-lms' ), $current_page, $max_pages ) ) . '</span>';

		if ( $current_page < $max_pages ) {
			echo '<a class="slms-portal-button is-secondary is-small" href="' . esc_url( $next_url ) . '">' . esc_html__( 'Older >', 'simple-lms' ) . '</a>';
		} else {
			echo '<span class="slms-pagination-spacer"></span>';
		}
		echo '</div>';
	}

	private function get_current_page_url() {
		if ( is_admin() ) {
			$url = menu_page_url( 'slms-editor-tools', false );

			if ( ! $url ) {
				$url = admin_url( 'admin.php?page=slms-editor-tools' );
			}
		} else {
			global $wp;

			$request_path = isset( $wp->request ) ? (string) $wp->request : '';
			$url          = home_url( '/' . ltrim( $request_path, '/' ) );
		}

		$query_args = array();
		foreach ( $_GET as $key => $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}

			$query_args[ sanitize_key( (string) $key ) ] = sanitize_text_field( wp_unslash( (string) $value ) );
		}

		if ( empty( $query_args ) ) {
			return $url;
		}

		return add_query_arg( $query_args, $url );
	}

	private function is_full_staff() {
		return in_array( RoleManager::get_primary_role(), array( 'administrator', 'officer' ), true );
	}

	private function is_administrator() {
		return 'administrator' === RoleManager::get_primary_role();
	}
}
