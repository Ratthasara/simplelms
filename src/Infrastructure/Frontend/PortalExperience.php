<?php

namespace SimpleLMS\Infrastructure\Frontend;

use SimpleLMS\Core\AcademicClock;
use SimpleLMS\Domain\Academic\AttendanceService;
use SimpleLMS\Domain\Academic\EnrollmentService;
use SimpleLMS\Domain\Academic\LeaveApplicationService;
use SimpleLMS\Domain\Academic\TermService;
use SimpleLMS\Domain\Assessment\AssessmentService;
use SimpleLMS\Domain\Assessment\AssignmentLatePolicy;
use SimpleLMS\Domain\Assessment\AssignmentPointPolicy;
use SimpleLMS\Domain\Assessment\GradebookService;
use SimpleLMS\Domain\Assessment\GradeScaleService;
use SimpleLMS\Domain\Assessment\ResultsService;
use SimpleLMS\Domain\Communication\BroadcastService;
use SimpleLMS\Domain\Communication\EmailCampaignService;
use SimpleLMS\Domain\Settings\SettingsManager;
use SimpleLMS\Domain\Users\ProfileService;
use SimpleLMS\Domain\Users\RoleManager;
use SimpleLMS\Infrastructure\Database\Schema;
use SimpleLMS\Infrastructure\Files\ManagedUploadPath;
use SimpleLMS\Infrastructure\Import\ImportService;
use SimpleLMS\Infrastructure\Notifications\AssignmentNotificationService;
use SimpleLMS\Infrastructure\Notifications\NotificationManager;
use SimpleLMS\Infrastructure\IdCards\IdCardsModule;

defined( 'ABSPATH' ) || exit;

class PortalExperience {
	const MAX_SUBJECT_EMBED_URLS = 10;

	/**
	 * @var SettingsManager
	 */
	private $settings;

	/**
	 * @var TermService
	 */
	private $terms;

	/**
	 * @var EnrollmentService
	 */
	private $enrollments;

	/**
	 * @var AssessmentService
	 */
	private $assessments;

	/**
	 * @var GradebookService
	 */
	private $gradebook;

	/**
	 * @var GradeScaleService
	 */
	private $grade_scale;

	/**
	 * @var ResultsService
	 */
	private $results;

	/**
	 * @var AttendanceService
	 */
	private $attendance;

	/**
	 * @var ProfileService
	 */
	private $profiles;

	/**
	 * @var ImportService
	 */
	private $imports;


	/**
	 * @var AcademicManagementTools
	 */
	private $academic_management;

	/**
	 * @var NotificationManager
	 */
	private $notifications;

	/**
	 * @var AssignmentNotificationService
	 */
	private $assignment_notifications;

	/**
	 * @var LeaveApplicationService
	 */
	private $leave_applications;

	/**
	 * @var IdCardsModule
	 */
	private $id_cards;

	/**
	 * @var BroadcastService
	 */
	private $broadcasts;

	/**
	 * @var EmailCampaignService
	 */
	private $email_campaigns;

	/**
	 * @var string
	 */
	private $active_upload_subdirectory = '';

	public function __construct( SettingsManager $settings, TermService $terms, EnrollmentService $enrollments, AssessmentService $assessments, GradebookService $gradebook, GradeScaleService $grade_scale, ResultsService $results, AttendanceService $attendance, ProfileService $profiles, ImportService $imports, AcademicManagementTools $academic_management, NotificationManager $notifications, AssignmentNotificationService $assignment_notifications, LeaveApplicationService $leave_applications, IdCardsModule $id_cards, BroadcastService $broadcasts, EmailCampaignService $email_campaigns ) {
		$this->settings           = $settings;
		$this->terms              = $terms;
		$this->enrollments        = $enrollments;
		$this->assessments        = $assessments;
		$this->gradebook          = $gradebook;
		$this->grade_scale        = $grade_scale;
		$this->results            = $results;
		$this->attendance         = $attendance;
		$this->profiles           = $profiles;
		$this->imports            = $imports;
		$this->academic_management = $academic_management;
		$this->notifications             = $notifications;
		$this->assignment_notifications = $assignment_notifications;
		$this->leave_applications        = $leave_applications;
		$this->id_cards                  = $id_cards;
		$this->broadcasts                 = $broadcasts;
		$this->email_campaigns            = $email_campaigns;
	}

	public function render( $page_url ) {
		$user_id                  = get_current_user_id();
		$role                     = RoleManager::get_primary_role( $user_id );
		$institution              = $this->settings->get( 'institution_name', get_bloginfo( 'name' ) );
		$profile                  = $this->profiles->get_profile( $user_id );
		$greyscale_enabled        = $this->user_prefers_greyscale( $user_id );
		$subjects                 = $this->get_subjects_for_user( $user_id );
			$auditing_subjects        = 'lecturer' === $role ? $this->get_auditing_subjects_for_user( $user_id ) : array();
		$header_subjects          = $this->get_header_subjects_for_user( $user_id, $role );
		$header_subject_count     = count( $header_subjects );
		$requires_password_change = $this->user_requires_password_change( $user_id );
		$subject_id               = absint( $_GET['slms_subject'] ?? 0 );
		$item_id                  = absint( $_GET['slms_item'] ?? 0 );
		$open_item_id             = absint( $_GET['slms_open_item'] ?? $item_id );
		$view                     = sanitize_key( $_GET['slms_view'] ?? ( $subject_id ? 'subject' : 'dashboard' ) );
		$active_tab               = sanitize_key( $_GET['tab'] ?? 'materials' );
		$subject_view             = $subject_id ? $this->get_subject_view( $user_id, $subject_id, $role ) : null;

		if ( $requires_password_change && 'security' !== $view ) {
			$view         = 'security';
			$subject_id   = 0;
			$subject_view = null;
			$open_item_id = 0;
		}

		$is_subject_view      = in_array( $view, array( 'subject', 'subject-item' ), true ) && ! empty( $subject_view['subject'] );
			$is_auditing_subject_view = $is_subject_view && $this->enrollments->user_can_audit_section( $user_id, $subject_id ) && ! $this->can_teach_subject( $subject_id, $role );
			$active_view          = $is_subject_view ? ( $is_auditing_subject_view ? 'auditing' : 'subjects' ) : ( 'editor-tools' === $view ? 'dashboard' : $view );
		$dashboard_chip  = 'slms-portal-button is-secondary slms-nav-button is-dashboard' . ( 'dashboard' === $active_view ? ' is-active' : '' );
		$academic_chip   = 'slms-portal-button is-secondary slms-nav-button is-management' . ( 'academic-management' === $active_view ? ' is-active' : '' );
		$subjects_chip   = 'slms-portal-button is-secondary slms-nav-button is-subjects' . ( 'subjects' === $active_view ? ' is-active' : '' );
			$auditing_chip   = 'slms-portal-button is-secondary slms-nav-button is-auditing' . ( 'auditing' === $active_view ? ' is-active' : '' );
		$id_chip         = 'slms-portal-button is-secondary slms-nav-button is-id' . ( 'id-card' === $active_view ? ' is-active' : '' );
		$issuer_chip     = 'slms-portal-button is-secondary slms-nav-button is-card-issuer' . ( 'card-issuer' === $active_view ? ' is-active' : '' );
		$security_chip   = 'slms-portal-button is-secondary slms-nav-button is-security' . ( 'security' === $active_view ? ' is-active' : '' );
		$display_name         = $profile['display_name'] ?? wp_get_current_user()->display_name;
		$role_label           = $role ? RoleManager::get_role_label( $role ) : __( 'User', 'simple-lms' );
		$can_view_subjects    = in_array( $role, array( 'administrator', 'officer', 'lecturer', 'student' ), true );
		$active_subjects_text = sprintf( _n( '%d Active Subject', '%d Active Subjects', $header_subject_count, 'simple-lms' ), $header_subject_count );
		$portal_header_image_url         = $this->get_portal_header_image_url();
		$portal_header_image_opacity     = $this->get_portal_header_image_opacity_percent();
		$can_edit_portal_header          = $this->can_manage_portal_header( $role );
		$can_edit_subject_header         = $is_subject_view ? $this->can_manage_subject_header( (int) ( $subject_view['subject']['id'] ?? 0 ), $role ) : false;
		$can_manage_academics            = current_user_can( RoleManager::CAP_MANAGE_ACADEMICS ) && current_user_can( RoleManager::CAP_MANAGE_USERS );
			$can_teach_subject           = $this->can_teach_subject( $subject_id, $role );
			$is_auditing_subject        = $this->enrollments->user_can_audit_section( get_current_user_id(), $subject_id ) && ! $can_teach_subject;

		ob_start();
		?>
		<section class="slms-portal-app <?php echo $is_subject_view ? 'is-subject-view ' : ''; ?><?php echo $greyscale_enabled ? 'is-greyscale' : ''; ?>" id="slms-portal">
			<?php $this->render_notice(); ?>
			<?php if ( ! $is_subject_view ) : ?>
				<section class="slms-page-flow-shell">
					<header class="slms-portal-banner<?php echo $portal_header_image_url ? ' has-header-image' : ''; ?>">
						<?php if ( $portal_header_image_url ) : ?>
							<div class="slms-portal-header-media" style="--slms-header-image-opacity: <?php echo esc_attr( $this->format_header_image_opacity_decimal( $portal_header_image_opacity ) ); ?>; background-image: url('<?php echo esc_url( $portal_header_image_url ); ?>');"></div>
						<?php endif; ?>
						<div class="slms-portal-banner-copy">
							<div class="slms-portal-banner-topbar">
								<p class="slms-portal-kicker"><?php esc_html_e( 'Academic Portal', 'simple-lms' ); ?></p>
								<form method="post" action="" class="slms-portal-display-toggle-form">
									<?php wp_nonce_field( 'slms_portal_action', 'slms_portal_nonce' ); ?>
									<input type="hidden" name="slms_portal_action" value="toggle_greyscale_mode">
									<input type="hidden" name="slms_greyscale_enabled" value="<?php echo $greyscale_enabled ? '0' : '1'; ?>">
									<input type="hidden" name="slms_return_view" value="<?php echo esc_attr( $active_view ); ?>">
									<button type="submit" class="slms-portal-button is-secondary slms-portal-display-toggle<?php echo $greyscale_enabled ? ' is-active' : ''; ?>" aria-pressed="<?php echo $greyscale_enabled ? 'true' : 'false'; ?>" title="<?php echo esc_attr( $greyscale_enabled ? __( 'Turn greyscale off', 'simple-lms' ) : __( 'Turn greyscale on', 'simple-lms' ) ); ?>">
										<span><?php esc_html_e( 'Greyscale', 'simple-lms' ); ?></span>
									</button>
								</form>
							</div>
							<h1 class="slms-portal-title"><?php echo esc_html( $institution ); ?></h1>
							<div class="slms-portal-identity" aria-label="<?php esc_attr_e( 'Signed in user identity', 'simple-lms' ); ?>">
								<span class="slms-portal-identity-chip is-user"><span class="dashicons dashicons-admin-users" aria-hidden="true"></span><span><?php echo esc_html( $display_name ); ?></span></span>
								<span class="slms-portal-identity-chip is-role"><span class="dashicons dashicons-awards" aria-hidden="true"></span><span><?php echo esc_html( $role_label ); ?></span></span>
								<?php if ( $header_subject_count > 0 ) : ?>
									<span class="slms-portal-identity-chip is-subject-count"><span class="dashicons dashicons-book-alt" aria-hidden="true"></span><span><?php echo esc_html( $active_subjects_text ); ?></span></span>
								<?php endif; ?>
							</div>
							<div class="slms-header-action-row">
								<nav class="slms-portal-actions slms-portal-actions--hero">
									<a class="<?php echo esc_attr( $dashboard_chip ); ?>" href="<?php echo esc_url( $this->view_url( $page_url, 'dashboard' ) ); ?>"><span class="dashicons dashicons-chart-bar" aria-hidden="true"></span><span><?php esc_html_e( 'Dashboard', 'simple-lms' ); ?></span></a>
									<?php if ( $can_manage_academics ) : ?>
										<a class="<?php echo esc_attr( $academic_chip ); ?>" href="<?php echo esc_url( $this->view_url( $page_url, 'academic-management' ) ); ?>"><span class="dashicons dashicons-portfolio" aria-hidden="true"></span><span><?php esc_html_e( 'Academic Management', 'simple-lms' ); ?></span></a>
									<?php endif; ?>
									<?php if ( $this->can_use_card_issuer( $role ) ) : ?>
										<a class="<?php echo esc_attr( $issuer_chip ); ?>" href="<?php echo esc_url( $this->view_url( $page_url, 'card-issuer' ) ); ?>"><span class="dashicons dashicons-id-alt" aria-hidden="true"></span><span><?php esc_html_e( 'Card Issuer', 'simple-lms' ); ?></span></a>
									<?php endif; ?>
									<?php if ( $can_view_subjects ) : ?>
										<a class="<?php echo esc_attr( $subjects_chip ); ?>" href="<?php echo esc_url( $this->view_url( $page_url, 'subjects' ) ); ?>"><span class="dashicons dashicons-book-alt" aria-hidden="true"></span><span><?php esc_html_e( 'My Subjects', 'simple-lms' ); ?></span></a>
									<?php endif; ?>
									<?php if ( 'lecturer' === $role && ! empty( $auditing_subjects ) ) : ?>
										<a class="<?php echo esc_attr( $auditing_chip ); ?>" href="<?php echo esc_url( $this->view_url( $page_url, 'auditing' ) ); ?>"><span class="dashicons dashicons-visibility" aria-hidden="true"></span><span><?php esc_html_e( 'Auditing', 'simple-lms' ); ?></span></a>
									<?php endif; ?>
									<a class="<?php echo esc_attr( $id_chip ); ?>" href="<?php echo esc_url( $this->view_url( $page_url, 'id-card' ) ); ?>"><span class="dashicons dashicons-id" aria-hidden="true"></span><span><?php esc_html_e( 'ID', 'simple-lms' ); ?></span></a>
									<a class="<?php echo esc_attr( $security_chip ); ?>" href="<?php echo esc_url( $this->view_url( $page_url, 'security' ) ); ?>"><span class="dashicons dashicons-shield" aria-hidden="true"></span><span><?php esc_html_e( 'Security', 'simple-lms' ); ?></span></a>
									<a class="slms-portal-button is-secondary slms-nav-button is-logout" href="<?php echo esc_url( wp_logout_url( $page_url ) ); ?>"><span class="dashicons dashicons-exit" aria-hidden="true"></span><span><?php esc_html_e( 'Log Out', 'simple-lms' ); ?></span></a>
								</nav>
								<?php if ( $can_edit_portal_header ) : ?>
									<button type="button" class="slms-header-edit-button" data-slms-open-header-image-modal data-slms-header-action="save_portal_header_image" data-slms-header-title="<?php echo esc_attr__( 'Edit LMS Header Image', 'simple-lms' ); ?>" data-slms-current-image="<?php echo esc_url( $portal_header_image_url ); ?>" data-slms-current-opacity="<?php echo esc_attr( (string) $portal_header_image_opacity ); ?>" data-slms-header-view="<?php echo esc_attr( $active_view ); ?>" aria-label="<?php esc_attr_e( 'Edit LMS header image', 'simple-lms' ); ?>" title="<?php esc_attr_e( 'Edit LMS header image', 'simple-lms' ); ?>"><span class="slms-header-edit-button-icon" aria-hidden="true"><?php echo $this->get_portal_icon_markup( 'image-edit' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span></button>
								<?php endif; ?>
							</div>
						</div>
					</header>

					<main class="slms-app-content slms-app-content--full slms-page-flow-main">
						<?php if ( 'academic-management' === $view && $can_manage_academics ) : ?>
							<?php echo $this->academic_management->render_academic_management_page(); ?>
						<?php elseif ( 'card-issuer' === $view && $this->can_use_card_issuer( $role ) ) : ?>
							<?php $this->render_card_issuer_page( $page_url ); ?>
						<?php elseif ( 'subjects' === $view ) : ?>
							<?php $this->render_subjects_index( $page_url, $subjects ); ?>
							<?php elseif ( 'auditing' === $view && 'lecturer' === $role && ! empty( $auditing_subjects ) ) : ?>
								<?php $this->render_auditing_subjects_index( $page_url, $auditing_subjects ); ?>
						<?php elseif ( 'id-card' === $view ) : ?>
							<?php $this->render_id_card( $user_id, $role, $profile ); ?>
						<?php elseif ( 'security' === $view ) : ?>
							<?php $this->render_security_panel( $page_url, $requires_password_change ); ?>
						<?php elseif ( 'broadcast-center' === $view && 'administrator' === $role ) : ?>
							<?php $this->render_broadcast_center_page( $page_url ); ?>
						<?php elseif ( 'email-center' === $view && 'administrator' === $role ) : ?>
							<?php $this->render_email_center_page( $page_url ); ?>
						<?php elseif ( 'announcements' === $view ) : ?>
							<?php $this->render_announcements_page( $page_url, $role ); ?>
						<?php elseif ( 'attendance-review' === $view && $this->can_view_frontend_attendance_review( $role ) ) : ?>
							<?php $this->render_frontend_attendance_review_page( $page_url ); ?>
						<?php elseif ( 'leave-application' === $view && 'student' === $role && $this->is_leave_application_enabled() ) : ?>
							<?php $this->render_leave_application_page( $page_url, $subjects, $profile ); ?>
						<?php elseif ( 'transcript' === $view && 'student' === $role ) : ?>
							<?php $this->render_transcript( $user_id ); ?>
						<?php else : ?>
							<?php $this->render_dashboard( $page_url, $role, $subjects, $profile, $auditing_subjects ); ?>
						<?php endif; ?>
					</main>
				</section>
			<?php else : ?>
				<main class="slms-app-content slms-app-content--full">
					<?php $this->render_subject_view( $page_url, $role, $subject_view, $active_tab, $open_item_id ); ?>
				</main>
			<?php endif; ?>
			<?php if ( ! $requires_password_change ) : ?>
				<?php $this->render_broadcast_login_popup( $user_id ); ?>
			<?php endif; ?>
			<?php if ( $can_edit_portal_header || $can_edit_subject_header ) : ?>
				<?php $this->render_header_image_modal(); ?>
			<?php endif; ?>
		</section>
		<?php

		return (string) ob_get_clean();
	}

	private function user_requires_password_change( $user_id ) {
		return (bool) get_user_meta( absint( $user_id ), 'slms_force_password_reset', true );
	}

	private function user_prefers_greyscale( $user_id ) {
		return '1' === (string) get_user_meta( absint( $user_id ), 'slms_portal_greyscale_enabled', true );
	}

	private function set_user_greyscale_preference( $user_id, $enabled ) {
		if ( $enabled ) {
			update_user_meta( absint( $user_id ), 'slms_portal_greyscale_enabled', '1' );
			return;
		}

		delete_user_meta( absint( $user_id ), 'slms_portal_greyscale_enabled' );
	}

	private function get_subjects_for_user( $user_id ) {
		$rows = $this->enrollments->get_sections_for_user( $user_id );

		return array_values(
			array_filter(
				$rows,
				static function ( $row ) {
					return empty( $row['post_type'] ) || 'slms_subject' === $row['post_type'];
				}
			)
		);
	}


	private function get_auditing_subjects_for_user( $user_id ) {
		$rows = $this->enrollments->get_staff_audit_sections_for_user( $user_id );

		return array_values(
			array_filter(
				$rows,
				static function ( $row ) {
					return empty( $row['post_type'] ) || 'slms_subject' === $row['post_type'];
				}
			)
		);
	}

	private function get_header_subjects_for_user( $user_id, $role ) {
		if ( 'student' === $role ) {
			return $this->get_subjects_for_user( $user_id );
		}

		if ( in_array( $role, array( 'administrator', 'officer', 'lecturer' ), true ) ) {
			$rows = $this->enrollments->get_teaching_sections_for_user( $user_id );

			return array_values(
				array_filter(
					$rows,
					static function ( $row ) {
						return empty( $row['post_type'] ) || 'slms_subject' === $row['post_type'];
					}
				)
			);
		}

		return array();
	}

	private function get_subject_view( $user_id, $subject_id, $role = '' ) {
		if ( ! $this->enrollments->user_can_learn_section( $user_id, $subject_id ) ) {
			return array();
		}

		$role = $role ?: RoleManager::get_primary_role( $user_id );
		$weeks = $this->get_subject_week_structure( $subject_id );
		$attendance_dashboard = $this->attendance->get_section_attendance_dashboard( $subject_id, $user_id );

		return array(
			'subject'     => $this->enrollments->get_section_summary( $subject_id ),
			'weeks'       => $weeks,
			'materials'   => $this->get_materials( $subject_id, $role ),
			'assignments' => $this->get_visible_subject_assignments( $user_id, $subject_id, $role ),
			'threads'     => $this->get_discussion_threads( $subject_id, $role ),
			'lecturers'   => $this->get_subject_lecturers( $subject_id ),
			'roster'      => $this->enrollments->list_enrollments( array( 'section_id' => $subject_id, 'statuses' => array( 'enrolled', 'waitlisted' ) ) ),
			'attendance'  => $attendance_dashboard,
			'current_week_number' => $this->get_subject_current_week_number( $weeks, $attendance_dashboard ),
			'gradebook'   => $this->can_teach_subject( $subject_id, $role ) ? $this->gradebook->get_gradebook( $subject_id, $user_id ) : null,
		);
	}

	private function get_subject_lecturers( $subject_id ) {
		static $cache = array();

		$subject_id = absint( $subject_id );

		if ( isset( $cache[ $subject_id ] ) ) {
			return $cache[ $subject_id ];
		}

		$rows = $this->enrollments->get_section_staff( $subject_id, 'lecturer' );
		$names = array_values(
			array_filter(
				array_map(
					static function ( $row ) {
						return sanitize_text_field( (string) ( $row['display_name'] ?? '' ) );
					},
					is_array( $rows ) ? $rows : array()
				)
			)
		);

		$cache[ $subject_id ] = array_values( array_unique( $names ) );

		return $cache[ $subject_id ];
	}

	private function get_subject_lecturer_label( $subject_id ) {
		$lecturers = $this->get_subject_lecturers( $subject_id );

		if ( empty( $lecturers ) ) {
			return '';
		}

		return implode( ', ', $lecturers );
	}

	private function render_dashboard( $page_url, $role, array $subjects, ?array $profile, array $auditing_subjects = array() ) {
		$user_id                  = get_current_user_id();
		$program_name             = ! empty( $profile['program_id'] ) ? $this->get_program_name( (int) $profile['program_id'] ) : '';
		$intake_term              = ! empty( $profile['intake_term_id'] ) ? $this->get_term_name( (int) $profile['intake_term_id'] ) : '';
		$completed_rows           = 'student' === $role ? $this->get_completed_transcript_rows( $user_id ) : array();
		$upcoming_assignments     = 'student' === $role ? $this->get_student_upcoming_assignments( $user_id ) : array();
		$requires_password_change = $this->user_requires_password_change( $user_id );
		$current_term             = $this->get_active_term_label();
		if ( '' === $current_term ) {
			$current_term = __( 'No active term linked yet.', 'simple-lms' );
		}
		$subject_preview = array_slice( $subjects, 0, 4 );
			$auditing_preview = array_slice( $auditing_subjects, 0, 4 );
		$scope_copy      = array(
			'administrator' => __( 'Full institutional control across students, staff, records, publishing, and academic operations.', 'simple-lms' ),
			'officer'       => __( 'Operational access to academic records, enrolments, terms, and institutional workflows.', 'simple-lms' ),
			'lecturer'      => __( 'Teaching access for assigned subjects, student enrolment, attendance, assignments, and grades.', 'simple-lms' ),
			RoleManager::ROLE_STAFF => __( 'Basic staff access to institutional announcements, identification, profile information, and account security.', 'simple-lms' ),
			'student'       => __( 'Learning access to active subjects, progress tracking, attendance, and completed-course records.', 'simple-lms' ),
		);
		?>
		<section class="slms-portal-panel slms-workspace-panel slms-flow-section">
			<div class="slms-portal-stat-grid">
				<div class="slms-portal-stat is-role"><span><?php esc_html_e( 'Role', 'simple-lms' ); ?></span><strong><?php echo esc_html( $role ? RoleManager::get_role_label( $role ) : __( 'User', 'simple-lms' ) ); ?></strong></div>
				<div class="slms-portal-stat is-subjects"><span><?php esc_html_e( 'Active Subjects', 'simple-lms' ); ?></span><strong><?php echo esc_html( (string) count( $subjects ) ); ?></strong></div>
				<div class="slms-portal-stat is-code"><span><?php esc_html_e( 'Code', 'simple-lms' ); ?></span><strong><?php echo esc_html( $profile['person_code'] ?? '-' ); ?></strong></div>
				<div class="slms-portal-stat is-current-term"><span><?php echo esc_html( 'student' === $role ? __( 'Completed Courses', 'simple-lms' ) : __( 'Current Term', 'simple-lms' ) ); ?></span><strong><?php echo esc_html( 'student' === $role ? (string) count( $completed_rows ) : $current_term ); ?></strong></div>
			</div>
			<div class="slms-workspace-card-grid">
				<?php
				// 1. Profile Snapshot for every role.
				$this->render_workspace_card(
					__( 'Profile Snapshot', 'simple-lms' ),
					$program_name
						? sprintf( __( '%1$s. %2$s', 'simple-lms' ), $program_name, $scope_copy[ $role ] ?? __( 'Academic access is available according to your role.', 'simple-lms' ) )
						: ( $scope_copy[ $role ] ?? __( 'Academic access is available according to your role.', 'simple-lms' ) ),
					array(
						'meta' => $intake_term ?: __( 'No intake term on file yet.', 'simple-lms' ),
						'icon' => 'profile',
					)
				);

				// 2. Broadcast Center for admins; Announcements for all other roles.
				$this->render_broadcast_dashboard_block( $page_url, $role, $user_id );

				if ( 'administrator' === $role ) {
					$this->render_workspace_card(
						__( 'Email Center', 'simple-lms' ),
						__( 'Send email-only announcements to staff, students, or selected class groups.', 'simple-lms' ),
						array(
							'url'  => $this->view_url( $page_url, 'email-center' ),
							'icon' => 'email-center',
						)
					);
				}

				if ( in_array( $role, array( 'administrator', 'officer' ), true ) ) {
					// Admin/officer order: Academic Management, Attendance Review, My Subjects, then role-specific cards.
					$this->render_workspace_card(
						__( 'Academic Management', 'simple-lms' ),
						__( 'Manage programs, terms, subjects, students, staff, imports, and institutional records from the frontend.', 'simple-lms' ),
						array(
							'meta' => __( 'Registrar and admin workflows', 'simple-lms' ),
							'url'  => $this->view_url( $page_url, 'academic-management' ),
							'icon' => 'management',
						)
					);

					$this->render_workspace_card(
						__( 'Attendance Review', 'simple-lms' ),
						__( 'Review absent, leave, and optional late records grouped by program and subject from the frontend dashboard.', 'simple-lms' ),
						array(
							'meta' => __( 'Program and subject oversight', 'simple-lms' ),
							'url'  => $this->view_url( $page_url, 'attendance-review' ),
							'icon' => 'attendance-review',
						)
					);

					$this->render_my_subjects_dashboard_card( $page_url, $subjects, $subject_preview, $current_term );

					if ( $this->can_use_card_issuer( $role ) ) {
						$this->render_workspace_card(
							__( 'Card Issuer', 'simple-lms' ),
							__( 'Issue, regenerate, preview, and batch-print student and staff ID cards from the frontend admin dashboard.', 'simple-lms' ),
							array(
								'meta' => __( 'Administrator only', 'simple-lms' ),
								'url'  => $this->view_url( $page_url, 'card-issuer' ),
								'icon' => 'card-issuer',
							)
						);
					}

					$this->render_id_card_dashboard_card( $page_url, $profile );
					$this->render_security_dashboard_card( $page_url, $requires_password_change );
				} elseif ( RoleManager::ROLE_STAFF === $role ) {
					// Baseline staff accounts have no academic-management or teaching surface.
					$this->render_id_card_dashboard_card( $page_url, $profile );
					$this->render_security_dashboard_card( $page_url, $requires_password_change );
				} elseif ( 'student' === $role ) {
					// Student order: My Subjects, Upcoming Assignments, Transcript, ID Card, Security, Subject cards.
					$this->render_my_subjects_dashboard_card( $page_url, $subjects, $subject_preview, $current_term );
					$this->render_dashboard_upcoming_assignments_block( $page_url, $upcoming_assignments );

					$this->render_workspace_card(
						__( 'Transcript', 'simple-lms' ),
						__( 'Review completed courses and print the formal academic transcript from the portal.', 'simple-lms' ),
						array(
							'meta' => sprintf( _n( '%d completed course', '%d completed courses', count( $completed_rows ), 'simple-lms' ), count( $completed_rows ) ),
							'url'  => $this->view_url( $page_url, 'transcript' ),
							'icon' => 'transcript',
						)
					);

					$this->render_id_card_dashboard_card( $page_url, $profile );
					$this->render_security_dashboard_card( $page_url, $requires_password_change );
					$this->render_dashboard_subject_cards( $page_url, $subject_preview );
				} else {
					// Lecturer and other teaching/editorial roles keep subject cards after the core cards.
					$this->render_my_subjects_dashboard_card( $page_url, $subjects, $subject_preview, $current_term );
						if ( 'lecturer' === $role && ! empty( $auditing_subjects ) ) {
							$this->render_auditing_dashboard_card( $page_url, $auditing_subjects, $auditing_preview, $current_term );
						}
					$this->render_id_card_dashboard_card( $page_url, $profile );
					$this->render_security_dashboard_card( $page_url, $requires_password_change );
					$this->render_dashboard_subject_cards( $page_url, $subject_preview );
				}
				?>
			</div>
		</section>
		<?php
	}

	private function render_my_subjects_dashboard_card( $page_url, array $subjects, array $subject_preview, $current_term ) {
		$this->render_workspace_card(
			__( 'My Subjects', 'simple-lms' ),
			$subjects
				? sprintf( __( 'Open your active subjects. Latest: %s', 'simple-lms' ), $subject_preview[0]['subject_title'] ?: $subject_preview[0]['title'] )
				: __( 'Your subject list will appear here once enrolments or lecturer assignments are in place.', 'simple-lms' ),
			array(
				'meta' => $current_term,
				'url'  => $this->view_url( $page_url, 'subjects' ),
				'icon' => 'subjects',
			)
		);
	}


	private function render_auditing_dashboard_card( $page_url, array $subjects, array $subject_preview, $current_term ) {
		$this->render_workspace_card(
			__( 'Auditing', 'simple-lms' ),
			$subjects
				? sprintf( __( 'Open subjects you are auditing. Latest: %s', 'simple-lms' ), $subject_preview[0]['subject_title'] ?: $subject_preview[0]['title'] )
				: __( 'Subjects you audit will appear here once assigned.', 'simple-lms' ),
			array(
				'meta' => $current_term,
				'url'  => $this->view_url( $page_url, 'auditing' ),
				'icon' => 'visibility',
			)
		);
	}

	private function render_id_card_dashboard_card( $page_url, ?array $profile ) {
		$this->render_workspace_card(
			__( 'ID Card', 'simple-lms' ),
			__( 'View, print, and update your LMS ID and profile photo from the frontend portal.', 'simple-lms' ),
			array(
				'meta' => $profile['person_code'] ?? __( 'ID ready', 'simple-lms' ),
				'url'  => $this->view_url( $page_url, 'id-card' ),
				'icon' => 'id',
			)
		);
	}

	private function render_security_dashboard_card( $page_url, $requires_password_change ) {
		$this->render_workspace_card(
			__( 'Security', 'simple-lms' ),
			$requires_password_change
				? __( 'Set a new password before using the rest of the LMS portal.', 'simple-lms' )
				: __( 'Change your portal password without leaving the frontend workspace.', 'simple-lms' ),
			array(
				'meta' => $requires_password_change ? __( 'Password change required', 'simple-lms' ) : __( 'Password tools', 'simple-lms' ),
				'url'  => $this->view_url( $page_url, 'security' ),
				'icon' => 'security',
			)
		);
	}

	private function render_dashboard_subject_cards( $page_url, array $subject_preview ) {
		if ( empty( $subject_preview ) ) {
			$this->render_workspace_card(
				__( 'No Active Subjects Yet', 'simple-lms' ),
				__( 'Once a subject is assigned or you are enrolled, it will appear here as its own workspace card.', 'simple-lms' ),
				array(
					'meta' => __( 'Waiting for enrolment or assignment', 'simple-lms' ),
					'icon' => 'subjects-empty',
				)
			);
			return;
		}

		foreach ( $subject_preview as $subject ) {
			$lecturer_label = $this->get_subject_lecturer_label( (int) $subject['id'] );
			$this->render_workspace_card(
				$subject['subject_title'] ?: $subject['title'],
				$lecturer_label ?: __( 'No lecturer assigned yet.', 'simple-lms' ),
				array(
					'code' => $subject['section_code'] ?: ( $subject['subject_code'] ?? __( 'Subject', 'simple-lms' ) ),
					'meta' => $subject['term_name'] ?: __( 'No term assigned', 'simple-lms' ),
					'url'  => $this->subject_url( $page_url, (int) $subject['id'] ),
					'icon' => 'subject',
				)
			);
		}
	}

	private function render_dashboard_upcoming_assignments_block( $page_url, array $upcoming_assignments ) {
		?>
		<article class="slms-workspace-card slms-dashboard-upcoming-card is-upcoming<?php echo $upcoming_assignments ? ' has-upcoming-items' : ''; ?>">
			<div class="slms-workspace-card-headline">
				<span class="slms-workspace-card-icon dashicons dashicons-clipboard" aria-hidden="true"></span>
				<div class="slms-workspace-card-title-stack">
					<h3><?php esc_html_e( 'Upcoming Assignments', 'simple-lms' ); ?></h3>
				</div>
			</div>
			<span class="slms-workspace-card-code"><?php echo esc_html( sprintf( _n( '%d assignment', '%d assignments', count( $upcoming_assignments ), 'simple-lms' ), count( $upcoming_assignments ) ) ); ?></span>
			<p><?php esc_html_e( 'Track approaching deadlines across all current subjects and open each assignment directly.', 'simple-lms' ); ?></p>
			<?php if ( empty( $upcoming_assignments ) ) : ?>
				<span class="slms-workspace-card-meta"><?php esc_html_e( 'No upcoming assignment deadlines right now.', 'simple-lms' ); ?></span>
			<?php else : ?>
				<div class="slms-dashboard-upcoming-list">
					<?php foreach ( $upcoming_assignments as $assignment ) : ?>
						<?php
						$assignment_meta_bits = $this->get_assignment_display_meta_bits(
							array(
								'due_at'          => $assignment['due_at'] ?? '',
								'points'          => $assignment['points'] ?? 0,
								'weight'          => $assignment['weight'] ?? 0,
								'submission_type' => $assignment['submission_type'] ?? 'text_file',
								'attachments'     => $this->get_subject_item_attachments( (int) $assignment['id'], 'assignments' ),
								'embed_url'       => '',
							),
							true
						);
						?>
						<a class="slms-dashboard-upcoming-item" href="<?php echo esc_url( $this->subject_item_url( $page_url, (int) $assignment['section_id'], 'assignments', (int) $assignment['id'] ) ); ?>">
							<div>
								<strong><?php echo esc_html( $assignment['title'] ); ?></strong>
								<p><?php echo esc_html( $assignment['subject_title'] ?: $assignment['section_title'] ); ?></p>
							</div>
							<div class="slms-dashboard-upcoming-meta">
								<?php foreach ( $assignment_meta_bits as $assignment_meta_bit ) : ?>
									<span><?php echo esc_html( $assignment_meta_bit ); ?></span>
								<?php endforeach; ?>
							</div>
						</a>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</article>
		<?php
	}



	private function render_broadcast_dashboard_block( $page_url, $role, $user_id ) {
		$is_admin = 'administrator' === $role;
		$summary  = $is_admin ? $this->broadcasts->get_admin_summary() : $this->broadcasts->get_summary_for_user( $user_id );
		$url      = $this->view_url( $page_url, $is_admin ? 'broadcast-center' : 'announcements' );
		$title    = $is_admin ? __( 'Broadcast Center', 'simple-lms' ) : __( 'Announcements', 'simple-lms' );
		$icon     = $is_admin ? 'broadcast' : 'announcements';

		if ( $is_admin ) {
			$description = __( 'Create and manage official LMS broadcasts for staff and students.', 'simple-lms' );
			$meta        = sprintf(
				/* translators: 1: active broadcast count, 2: pending acknowledgement count */
				__( '%1$d active · %2$d pending acknowledgements', 'simple-lms' ),
				(int) ( $summary['active_messages'] ?? 0 ),
				(int) ( $summary['pending_acknowledgements'] ?? 0 )
			);
			$code        = '';
		} else {
			$action_required = (int) ( $summary['action_required'] ?? 0 );
			$unread          = (int) ( $summary['unread'] ?? 0 );
			$total           = (int) ( $summary['total'] ?? 0 );
			$description     = $action_required > 0
				? __( 'Review and acknowledge important LMS notices that require your attention.', 'simple-lms' )
				: __( 'Read institutional announcements and recent LMS notices for your account.', 'simple-lms' );
			$meta            = $action_required > 0
				? sprintf(
					/* translators: 1: unread notice count, 2: action required count */
					__( '%1$d unread · %2$d action required', 'simple-lms' ),
					$unread,
					$action_required
				)
				: sprintf(
					/* translators: 1: unread notice count, 2: total notice count */
					__( '%1$d unread · %2$d available notices', 'simple-lms' ),
					$unread,
					$total
				);
			$code            = $action_required > 0 ? __( 'Action required', 'simple-lms' ) : __( 'Notices', 'simple-lms' );
		}

		$this->render_workspace_card(
			$title,
			$description,
			array(
				'code' => $code,
				'meta' => $meta,
				'url'  => $url,
				'icon' => $icon,
			)
		);
	}

	private function render_announcements_page( $page_url, $role ) {
		$user_id = get_current_user_id();
		$items   = $this->broadcasts->get_items_for_user( $user_id, 50 );
		?>
		<section class="slms-portal-panel slms-flow-section slms-broadcast-page">
			<div class="slms-section-heading">
				<div>
					<p class="slms-portal-kicker"><?php esc_html_e( 'LMS Notices', 'simple-lms' ); ?></p>
					<h2><?php esc_html_e( 'Announcements', 'simple-lms' ); ?></h2>
					<p class="slms-field-help"><?php esc_html_e( 'Open a notice to read the full announcement. Critical notices may ask for acknowledgement.', 'simple-lms' ); ?></p>
				</div>
				<a class="slms-portal-button is-secondary" href="<?php echo esc_url( $this->view_url( $page_url, 'dashboard' ) ); ?>"><?php esc_html_e( 'Back to Dashboard', 'simple-lms' ); ?></a>
			</div>
			<?php if ( empty( $items ) ) : ?>
				<p class="slms-field-help"><?php esc_html_e( 'There are no announcements for your account right now.', 'simple-lms' ); ?></p>
			<?php else : ?>
				<div class="slms-broadcast-card-list">
					<?php foreach ( $items as $item ) : ?>
						<?php $this->render_broadcast_message_card( $item, false ); ?>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}

	private function render_broadcast_center_page( $page_url ) {
		$summary  = $this->broadcasts->get_admin_summary();
		$messages = $this->broadcasts->get_admin_messages( 50 );
		$sections = $this->get_broadcast_target_sections();
		?>
		<section class="slms-portal-panel slms-flow-section slms-broadcast-page slms-broadcast-center-page">
			<div class="slms-section-heading">
				<div>
					<p class="slms-portal-kicker"><?php esc_html_e( 'Administrator Only', 'simple-lms' ); ?></p>
					<h2><?php esc_html_e( 'Broadcast Center', 'simple-lms' ); ?></h2>
					<p class="slms-field-help"><?php esc_html_e( 'Create in-app LMS broadcasts for staff, students, or selected class groups. This feature is separate from the public/frontend announcement post type.', 'simple-lms' ); ?></p>
				</div>
				<a class="slms-portal-button is-secondary" href="<?php echo esc_url( $this->view_url( $page_url, 'dashboard' ) ); ?>"><?php esc_html_e( 'Back to Dashboard', 'simple-lms' ); ?></a>
			</div>

			<div class="slms-broadcast-summary-row is-admin-summary">
				<span><strong><?php echo esc_html( (string) $summary['active_messages'] ); ?></strong><?php esc_html_e( 'Active broadcasts', 'simple-lms' ); ?></span>
				<span><strong><?php echo esc_html( (string) $summary['pending_acknowledgements'] ); ?></strong><?php esc_html_e( 'Pending acknowledgements', 'simple-lms' ); ?></span>
				<span><strong><?php echo esc_html( (string) $summary['unread_deliveries'] ); ?></strong><?php esc_html_e( 'Unread deliveries', 'simple-lms' ); ?></span>
			</div>

			<div class="slms-broadcast-admin-layout">
				<form method="post" class="slms-broadcast-composer">
					<?php wp_nonce_field( 'slms_portal_action', 'slms_portal_nonce' ); ?>
					<input type="hidden" name="slms_portal_action" value="create_broadcast">
					<div class="slms-section-heading is-compact">
						<div>
							<h3><?php esc_html_e( 'Create Broadcast', 'simple-lms' ); ?></h3>
							<p class="slms-field-help"><?php esc_html_e( 'Use critical acknowledgement only for important policy or rule changes.', 'simple-lms' ); ?></p>
						</div>
					</div>
					<label><span><?php esc_html_e( 'Title', 'simple-lms' ); ?></span><input type="text" name="broadcast_title" required maxlength="190" placeholder="<?php esc_attr_e( 'e.g. Updated Attendance Policy', 'simple-lms' ); ?>"></label>
					<label><span><?php esc_html_e( 'Message', 'simple-lms' ); ?></span><textarea name="broadcast_message" rows="7" placeholder="<?php esc_attr_e( 'Write the full announcement here.', 'simple-lms' ); ?>"></textarea></label>
					<label class="slms-broadcast-embed-field"><span><?php esc_html_e( 'Embed photo/video', 'simple-lms' ); ?></span><input type="url" name="broadcast_embed_url" maxlength="2048"></label>
					<div class="slms-form-grid two-columns">
						<label><span><?php esc_html_e( 'Notice Type', 'simple-lms' ); ?></span><select name="broadcast_type"><option value="normal"><?php esc_html_e( 'Normal Announcement', 'simple-lms' ); ?></option><option value="important"><?php esc_html_e( 'Important Notice', 'simple-lms' ); ?></option><option value="critical"><?php esc_html_e( 'Critical / Policy Update', 'simple-lms' ); ?></option></select></label>
						<label><span><?php esc_html_e( 'Popup', 'simple-lms' ); ?></span><select name="broadcast_popup"><option value="0"><?php esc_html_e( 'No login popup', 'simple-lms' ); ?></option><option value="1"><?php esc_html_e( 'Show after login', 'simple-lms' ); ?></option></select></label>
					</div>
					<div class="slms-broadcast-target-box">
						<strong><?php esc_html_e( 'Recipients', 'simple-lms' ); ?></strong>
						<label class="slms-choice-row"><input type="checkbox" name="broadcast_targets[]" value="all_students"> <span><?php esc_html_e( 'All students', 'simple-lms' ); ?></span></label>
						<label class="slms-choice-row"><input type="checkbox" name="broadcast_targets[]" value="all_staff"> <span><?php esc_html_e( 'All staff', 'simple-lms' ); ?></span></label>
						<?php if ( ! empty( $sections ) ) : ?>
							<label><span><?php esc_html_e( 'Specific class / subject groups', 'simple-lms' ); ?></span><select name="broadcast_section_ids[]" multiple size="6">
								<?php foreach ( $sections as $section ) : ?>
									<option value="<?php echo esc_attr( (string) $section['id'] ); ?>"><?php echo esc_html( $section['label'] ); ?></option>
								<?php endforeach; ?>
							</select></label>
							<p class="slms-field-help"><?php esc_html_e( 'Hold Ctrl/Cmd to select multiple groups. Group targeting sends to enrolled students in those subjects.', 'simple-lms' ); ?></p>
						<?php endif; ?>
					</div>
					<label class="slms-choice-row slms-broadcast-ack-row"><input type="checkbox" name="broadcast_requires_ack" value="1"> <span><?php esc_html_e( 'Require acknowledgement. Use this for policy changes only.', 'simple-lms' ); ?></span></label>
					<button type="submit" class="slms-portal-button"><?php esc_html_e( 'Publish Broadcast', 'simple-lms' ); ?></button>
				</form>

				<div class="slms-broadcast-admin-list">
					<div class="slms-section-heading is-compact">
						<div>
							<h3><?php esc_html_e( 'Recent Broadcasts', 'simple-lms' ); ?></h3>
							<p class="slms-field-help"><?php esc_html_e( 'Click a broadcast to preview it exactly as users read it.', 'simple-lms' ); ?></p>
						</div>
					</div>
					<?php if ( empty( $messages ) ) : ?>
						<p class="slms-field-help"><?php esc_html_e( 'No broadcasts have been created yet.', 'simple-lms' ); ?></p>
					<?php else : ?>
						<div class="slms-broadcast-card-list">
							<?php foreach ( $messages as $message ) : ?>
								<?php $this->render_broadcast_message_card( $message, true ); ?>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</section>
		<?php
	}

	private function render_email_center_page( $page_url ) {
		$summary         = $this->email_campaigns->get_summary();
		$campaigns       = $this->email_campaigns->get_campaigns( 30 );
		$sections        = $this->get_broadcast_target_sections();
		$audience_counts = $this->email_campaigns->get_audience_counts();
		?>
		<section class="slms-portal-panel slms-flow-section slms-broadcast-page slms-email-center-page">
			<div class="slms-section-heading">
				<div>
					<h2><?php esc_html_e( 'Email Center', 'simple-lms' ); ?></h2>
					<p class="slms-field-help"><?php esc_html_e( 'Send email-only announcements. Messages sent here do not create broadcasts, portal notices, popups, or acknowledgement records.', 'simple-lms' ); ?></p>
				</div>
				<a class="slms-portal-button is-secondary" href="<?php echo esc_url( $this->view_url( $page_url, 'dashboard' ) ); ?>"><?php esc_html_e( 'Back to Dashboard', 'simple-lms' ); ?></a>
			</div>

			<?php if ( ! $this->email_campaigns->is_delivery_enabled() ) : ?>
				<div class="slms-portal-alert is-error"><p><?php esc_html_e( 'Email notifications are disabled in Simple LMS settings. Enable them before sending a campaign.', 'simple-lms' ); ?></p></div>
			<?php endif; ?>

			<div class="slms-broadcast-summary-row is-admin-summary">
				<span><strong><?php echo esc_html( (string) $summary['campaigns'] ); ?></strong><?php esc_html_e( 'Campaigns', 'simple-lms' ); ?></span>
				<span><strong><?php echo esc_html( (string) $summary['pending'] ); ?></strong><?php esc_html_e( 'Queued emails', 'simple-lms' ); ?></span>
				<span><strong><?php echo esc_html( (string) $summary['sent'] ); ?></strong><?php esc_html_e( 'Sent emails', 'simple-lms' ); ?></span>
				<span><strong><?php echo esc_html( (string) $summary['failed'] ); ?></strong><?php esc_html_e( 'Failed emails', 'simple-lms' ); ?></span>
			</div>

			<div class="slms-broadcast-admin-layout">
				<form method="post" class="slms-broadcast-composer slms-email-composer">
					<?php wp_nonce_field( 'slms_portal_action', 'slms_portal_nonce' ); ?>
					<input type="hidden" name="slms_portal_action" value="create_email_campaign">
					<div class="slms-section-heading is-compact"><div><h3><?php esc_html_e( 'Compose Email', 'simple-lms' ); ?></h3></div></div>
					<label><span><?php esc_html_e( 'Subject', 'simple-lms' ); ?></span><input type="text" name="email_campaign_subject" required maxlength="255"></label>
					<label><span><?php esc_html_e( 'Message', 'simple-lms' ); ?></span><textarea name="email_campaign_message" rows="10" required placeholder="<?php esc_attr_e( 'Write the email message here.', 'simple-lms' ); ?>"></textarea></label>
					<div class="slms-broadcast-target-box">
						<strong><?php esc_html_e( 'Recipients', 'simple-lms' ); ?></strong>
						<label class="slms-choice-row"><input type="checkbox" name="email_campaign_targets[]" value="all_students"> <span><?php echo esc_html( sprintf( __( 'All students (%d)', 'simple-lms' ), (int) $audience_counts['all_students'] ) ); ?></span></label>
						<label class="slms-choice-row"><input type="checkbox" name="email_campaign_targets[]" value="all_staff"> <span><?php echo esc_html( sprintf( __( 'All staff (%d)', 'simple-lms' ), (int) $audience_counts['all_staff'] ) ); ?></span></label>
						<?php if ( ! empty( $sections ) ) : ?>
							<label><span><?php esc_html_e( 'Specific class / subject groups', 'simple-lms' ); ?></span><select name="email_campaign_section_ids[]" multiple size="6">
								<?php foreach ( $sections as $section ) : ?>
									<?php $section_student_count = (int) ( $section['student_count'] ?? 0 ); ?>
									<option value="<?php echo esc_attr( (string) $section['id'] ); ?>"><?php echo esc_html( sprintf( _n( '%1$s (%2$d student)', '%1$s (%2$d students)', $section_student_count, 'simple-lms' ), $section['label'], $section_student_count ) ); ?></option>
								<?php endforeach; ?>
							</select></label>
							<p class="slms-field-help"><?php esc_html_e( 'Hold Ctrl/Cmd to select multiple groups. Overlapping users and duplicate email addresses are automatically deduplicated.', 'simple-lms' ); ?></p>
						<?php endif; ?>
					</div>
					<label class="slms-choice-row slms-email-confirm-row"><input type="checkbox" name="email_campaign_confirm" value="1"> <span><?php esc_html_e( 'I have reviewed the subject, message, and recipient groups.', 'simple-lms' ); ?></span></label>
					<div class="slms-inline-actions">
						<button type="submit" name="email_campaign_intent" value="test" class="slms-portal-button is-secondary"><?php esc_html_e( 'Send Test to Me', 'simple-lms' ); ?></button>
						<button type="submit" name="email_campaign_intent" value="send" class="slms-portal-button" <?php disabled( ! $this->email_campaigns->is_delivery_enabled() ); ?>><?php esc_html_e( 'Queue Email Campaign', 'simple-lms' ); ?></button>
					</div>
				</form>

				<div class="slms-broadcast-admin-list slms-email-campaign-list">
					<div class="slms-section-heading is-compact"><div><h3><?php esc_html_e( 'Recent Email Campaigns', 'simple-lms' ); ?></h3></div></div>
					<?php if ( empty( $campaigns ) ) : ?>
						<p class="slms-field-help"><?php esc_html_e( 'No email campaigns have been created yet.', 'simple-lms' ); ?></p>
					<?php else : ?>
						<div class="slms-email-campaign-rows">
							<?php foreach ( $campaigns as $campaign ) : ?>
								<?php
								$pending_count   = (int) ( $campaign['pending_count'] ?? 0 );
								$sent_count      = (int) ( $campaign['sent_count'] ?? 0 );
								$failed_count    = (int) ( $campaign['failed_count'] ?? 0 );
								$cancelled_count = (int) ( $campaign['cancelled_count'] ?? 0 );
								$status_label     = 'cancelled' === $campaign['status'] ? __( 'Cancelled', 'simple-lms' ) : ( $pending_count > 0 ? __( 'Sending', 'simple-lms' ) : ( $failed_count > 0 ? __( 'Completed with failures', 'simple-lms' ) : __( 'Sent', 'simple-lms' ) ) );
								?>
								<article class="slms-email-campaign-row">
									<div class="slms-email-campaign-heading">
										<strong><?php echo esc_html( $campaign['subject'] ); ?></strong>
										<span class="slms-status-pill"><?php echo esc_html( $status_label ); ?></span>
									</div>
									<p><?php echo esc_html( sprintf( __( '%1$d queued | %2$d sent | %3$d failed | %4$d cancelled | %5$d skipped', 'simple-lms' ), $pending_count, $sent_count, $failed_count, $cancelled_count, (int) $campaign['skipped_count'] ) ); ?></p>
									<span class="slms-field-help"><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $campaign['created_at'] ) ); ?></span>
									<?php if ( $pending_count > 0 || $failed_count > 0 ) : ?>
										<div class="slms-inline-actions">
											<?php if ( $pending_count > 0 ) : ?>
												<form method="post">
													<?php wp_nonce_field( 'slms_portal_action', 'slms_portal_nonce' ); ?>
													<input type="hidden" name="slms_portal_action" value="cancel_email_campaign"><input type="hidden" name="email_campaign_id" value="<?php echo esc_attr( (string) $campaign['id'] ); ?>">
													<button type="submit" class="slms-portal-button is-secondary is-small"><?php esc_html_e( 'Cancel Pending', 'simple-lms' ); ?></button>
												</form>
											<?php endif; ?>
											<?php if ( $failed_count > 0 ) : ?>
												<form method="post">
													<?php wp_nonce_field( 'slms_portal_action', 'slms_portal_nonce' ); ?>
													<input type="hidden" name="slms_portal_action" value="retry_email_campaign"><input type="hidden" name="email_campaign_id" value="<?php echo esc_attr( (string) $campaign['id'] ); ?>">
													<button type="submit" class="slms-portal-button is-secondary is-small"><?php esc_html_e( 'Retry Failed', 'simple-lms' ); ?></button>
												</form>
											<?php endif; ?>
										</div>
									<?php endif; ?>
								</article>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</section>
		<?php
	}

	private function render_broadcast_message_card( array $item, $is_admin = false ) {
		$type = sanitize_key( $item['message_type'] ?? 'normal' );
		$modal_id = 'slms-broadcast-modal-' . absint( $item['id'] );
		$needs_ack = ! empty( $item['needs_acknowledgement'] );
		$unread = ! empty( $item['is_unread'] );
		$status = $is_admin
			? sprintf( __( '%1$d recipients · %2$d seen · %3$d acknowledged', 'simple-lms' ), (int) ( $item['recipient_count'] ?? 0 ), (int) ( $item['seen_count'] ?? 0 ), (int) ( $item['acknowledged_count'] ?? 0 ) )
			: ( $needs_ack ? __( 'Action required', 'simple-lms' ) : ( $unread ? __( 'Unread', 'simple-lms' ) : __( 'Read', 'simple-lms' ) ) );
		?>
		<article class="slms-broadcast-card is-<?php echo esc_attr( $type ); ?><?php echo $needs_ack ? ' needs-ack' : ''; ?><?php echo $unread ? ' is-unread' : ''; ?>">
			<button type="button" class="slms-broadcast-card-button" data-slms-broadcast-open="<?php echo esc_attr( $modal_id ); ?>" data-slms-broadcast-id="<?php echo esc_attr( (string) $item['id'] ); ?>">
				<span class="slms-broadcast-type-badge"><?php echo esc_html( $this->get_broadcast_type_icon( $type ) . ' ' . $this->get_broadcast_type_label( $type ) ); ?></span>
				<strong><?php echo esc_html( $item['title'] ); ?></strong>
				<span class="slms-broadcast-preview"><?php echo esc_html( $item['preview'] ); ?></span>
				<?php if ( ! empty( $item['embed_url'] ) ) : ?>
					<span class="slms-broadcast-embed-count"><?php esc_html_e( 'Embedded media', 'simple-lms' ); ?></span>
				<?php endif; ?>
				<span class="slms-broadcast-meta-line"><span><?php echo esc_html( $this->get_broadcast_time_label( $item ) ); ?></span><span><?php echo esc_html( $status ); ?></span></span>
			</button>
			<?php if ( $is_admin ) : ?>
				<div class="slms-broadcast-admin-actions">
					<?php if ( 'archived' !== ( $item['status'] ?? '' ) ) : ?>
						<form method="post" class="slms-broadcast-archive-form">
							<?php wp_nonce_field( 'slms_portal_action', 'slms_portal_nonce' ); ?>
							<input type="hidden" name="slms_portal_action" value="archive_broadcast">
							<input type="hidden" name="broadcast_id" value="<?php echo esc_attr( (string) $item['id'] ); ?>">
							<button type="submit" class="slms-portal-button is-secondary"><?php esc_html_e( 'Archive', 'simple-lms' ); ?></button>
						</form>
					<?php endif; ?>
					<form method="post" class="slms-broadcast-delete-form" onsubmit="return window.confirm('<?php echo esc_js( __( 'Permanently delete this broadcast and remove it from all targeted users? This cannot be undone.', 'simple-lms' ) ); ?>');">
						<?php wp_nonce_field( 'slms_portal_action', 'slms_portal_nonce' ); ?>
						<input type="hidden" name="slms_portal_action" value="delete_broadcast">
						<input type="hidden" name="broadcast_id" value="<?php echo esc_attr( (string) $item['id'] ); ?>">
						<button type="submit" class="slms-portal-button is-danger"><?php esc_html_e( 'Delete', 'simple-lms' ); ?></button>
					</form>
				</div>
			<?php endif; ?>
			<?php $this->render_broadcast_modal( $item, $modal_id, false, true ); ?>
		</article>
		<?php
	}

	private function render_broadcast_modal( array $item, $modal_id, $is_login_popup = false, $allow_close = true ) {
		$type = sanitize_key( $item['message_type'] ?? 'normal' );
		$requires_ack = ! empty( $item['requires_acknowledgement'] );
		$needs_ack = ! empty( $item['needs_acknowledgement'] );
		$show_close = $allow_close && ( ! $is_login_popup || ! $requires_ack );
		?>
		<div class="slms-broadcast-modal<?php echo $is_login_popup ? ' is-login-popup' : ''; ?>" id="<?php echo esc_attr( $modal_id ); ?>" data-slms-broadcast-modal data-slms-broadcast-id="<?php echo esc_attr( (string) $item['id'] ); ?>" data-slms-login-popup="<?php echo $is_login_popup ? '1' : '0'; ?>" data-slms-requires-ack="<?php echo $requires_ack ? '1' : '0'; ?>" aria-hidden="true">
			<div class="slms-broadcast-modal-backdrop" data-slms-broadcast-close></div>
			<div class="slms-broadcast-modal-dialog is-<?php echo esc_attr( $type ); ?>" role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr( $modal_id . '-title' ); ?>">
				<?php if ( $show_close ) : ?>
					<button type="button" class="slms-broadcast-modal-close" data-slms-broadcast-close aria-label="<?php esc_attr_e( 'Close announcement', 'simple-lms' ); ?>">&times;</button>
				<?php endif; ?>
				<div class="slms-broadcast-modal-header">
					<span class="slms-broadcast-type-badge"><?php echo esc_html( $this->get_broadcast_type_icon( $type ) . ' ' . $this->get_broadcast_type_label( $type ) ); ?></span>
					<h2 id="<?php echo esc_attr( $modal_id . '-title' ); ?>"><?php echo esc_html( $item['title'] ); ?></h2>
					<p><?php echo esc_html( $this->get_broadcast_time_label( $item ) ); ?></p>
				</div>
				<div class="slms-broadcast-modal-body">
					<?php echo wp_kses_post( wpautop( $item['message'] ) ); ?>
					<?php $this->render_broadcast_embed( $item['embed_url'] ?? '' ); ?>
				</div>
				<div class="slms-broadcast-modal-actions">
					<?php if ( $requires_ack && $needs_ack ) : ?>
						<button type="button" class="slms-portal-button" data-slms-broadcast-acknowledge="<?php echo esc_attr( (string) $item['id'] ); ?>"><?php esc_html_e( 'I Acknowledge', 'simple-lms' ); ?></button>
						<span class="slms-field-help"><?php esc_html_e( 'Acknowledgement is recorded with your account and time.', 'simple-lms' ); ?></span>
					<?php elseif ( $requires_ack && ! empty( $item['acknowledged_at'] ) ) : ?>
						<span class="slms-portal-badge is-success"><?php echo esc_html( sprintf( __( 'Acknowledged on %s', 'simple-lms' ), mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $item['acknowledged_at'] ) ) ); ?></span>
					<?php else : ?>
						<button type="button" class="slms-portal-button is-secondary" data-slms-broadcast-close><?php esc_html_e( 'Close', 'simple-lms' ); ?></button>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	private function render_broadcast_embed( $embed_url ) {
		$embed_url = esc_url_raw( (string) $embed_url, array( 'http', 'https' ) );
		if ( '' === $embed_url ) {
			return;
		}

		$path = (string) wp_parse_url( $embed_url, PHP_URL_PATH );
		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		$image_extensions = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg' );
		$video_extensions = array( 'mp4', 'webm', 'ogg', 'ogv', 'mov', 'm4v' );

		if ( in_array( $extension, $image_extensions, true ) ) {
			?>
			<figure class="slms-broadcast-embed is-image">
				<img src="<?php echo esc_url( $embed_url ); ?>" alt="" loading="lazy">
			</figure>
			<?php
			return;
		}

		if ( in_array( $extension, $video_extensions, true ) ) {
			$filetype = wp_check_filetype( $embed_url );
			$mime = ! empty( $filetype['type'] ) ? $filetype['type'] : 'video/mp4';
			?>
			<figure class="slms-broadcast-embed is-video">
				<video controls preload="metadata" playsinline>
					<source src="<?php echo esc_url( $embed_url ); ?>" type="<?php echo esc_attr( $mime ); ?>">
				</video>
			</figure>
			<?php
			return;
		}

		$embed_html = wp_oembed_get( $embed_url, array( 'width' => 680 ) );
		if ( ! $embed_html ) {
			return;
		}
		?>
		<div class="slms-broadcast-embed is-oembed">
			<?php echo wp_kses( $embed_html, $this->get_broadcast_embed_allowed_html() ); ?>
		</div>
		<?php
	}

	private function get_broadcast_embed_allowed_html() {
		$allowed = wp_kses_allowed_html( 'post' );
		$allowed['iframe'] = array(
			'src'             => true,
			'title'           => true,
			'width'           => true,
			'height'          => true,
			'frameborder'     => true,
			'allow'           => true,
			'allowfullscreen' => true,
			'loading'         => true,
			'referrerpolicy'  => true,
			'sandbox'         => true,
			'class'           => true,
			'style'           => true,
		);
		$allowed['blockquote'] = array(
			'class' => true,
			'cite'  => true,
		);
		return $allowed;
	}

	private function render_broadcast_login_popup( $user_id ) {
		$item = $this->broadcasts->get_pending_popup_for_user( $user_id );
		if ( ! $item ) {
			return;
		}

		$this->render_broadcast_modal( $item, 'slms-broadcast-login-popup', true, true );
		?>
		<script>window.slmsBroadcastLoginPopupId = 'slms-broadcast-login-popup';</script>
		<?php
	}

	private function get_broadcast_type_label( $type ) {
		switch ( $type ) {
			case 'critical':
				return __( 'Policy Update', 'simple-lms' );
			case 'important':
				return __( 'Important Notice', 'simple-lms' );
			default:
				return __( 'Announcement', 'simple-lms' );
		}
	}

	private function get_broadcast_type_icon( $type ) {
		switch ( $type ) {
			case 'critical':
				return '🔴';
			case 'important':
				return '🟡';
			default:
				return '🔵';
		}
	}

	private function get_broadcast_time_label( array $item ) {
		$published_at = (string) ( $item['published_at'] ?? $item['created_at'] ?? '' );
		if ( '' === $published_at || '0000-00-00 00:00:00' === $published_at ) {
			return __( 'Published recently', 'simple-lms' );
		}
		return sprintf( __( 'Published %s', 'simple-lms' ), mysql2date( get_option( 'date_format' ), $published_at ) );
	}

	private function get_broadcast_target_sections() {
		global $wpdb;

		$posts = get_posts(
			array(
				'post_type'      => 'slms_section',
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => 200,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		$enrollment_counts = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT section_id, COUNT(DISTINCT student_user_id) AS student_count FROM ' . Schema::table( 'enrollments' ) . ' WHERE status = %s GROUP BY section_id',
				'enrolled'
			),
			OBJECT_K
		);

		$sections = array();
		foreach ( $posts as $post ) {
			$code = (string) get_post_meta( $post->ID, '_slms_section_code', true );
			$sections[] = array(
				'id'            => (int) $post->ID,
				'student_count' => isset( $enrollment_counts[ $post->ID ] ) ? (int) $enrollment_counts[ $post->ID ]->student_count : 0,
				'label' => trim( $post->post_title . ( $code ? ' · ' . $code : '' ) ),
			);
		}
		return $sections;
	}

	private function can_view_frontend_attendance_review( $role ) {
		return in_array( $role, array( 'administrator', 'officer' ), true );
	}

	private function render_frontend_attendance_review_page( $page_url ) {
		$days = absint( $_GET['attendance_review_days'] ?? 7 );
		if ( ! in_array( $days, array( 7, 28, 112 ), true ) ) {
			$days = 7;
		}

		$include_late = ! empty( $_GET['attendance_review_include_late'] );
		$groups       = $this->attendance->get_student_attendance_matrix_review( $days, $include_late );
		$total_issues = 0;
		$total_students = 0;

		foreach ( $groups as $program ) {
			$total_issues += (int) ( $program['totals']['issue_count'] ?? 0 );
			$total_students += count( $program['students'] ?? array() );
		}
		?>
		<section class="slms-portal-panel slms-attendance-review-page slms-flow-section">
			<div class="slms-section-heading slms-attendance-review-heading">
				<div>
					<p class="slms-portal-kicker"><?php esc_html_e( 'Frontend Admin Dashboard', 'simple-lms' ); ?></p>
					<h2><?php esc_html_e( 'Attendance Review', 'simple-lms' ); ?></h2>
					<p class="slms-field-help"><?php esc_html_e( 'Review affected students by program. Each student block shows enrolled subjects in a clean week/class attendance matrix for the selected period.', 'simple-lms' ); ?></p>
				</div>
				<a class="slms-portal-button is-secondary" href="<?php echo esc_url( $this->view_url( $page_url, 'dashboard' ) ); ?>"><?php esc_html_e( 'Back to Dashboard', 'simple-lms' ); ?></a>
			</div>

			<form method="get" action="<?php echo esc_url( $page_url ); ?>" class="slms-attendance-review-filters">
				<input type="hidden" name="slms_view" value="attendance-review">
				<label>
					<span><?php esc_html_e( 'Date range', 'simple-lms' ); ?></span>
					<select name="attendance_review_days">
						<option value="7" <?php selected( $days, 7 ); ?>><?php esc_html_e( 'Last 7 days', 'simple-lms' ); ?></option>
						<option value="28" <?php selected( $days, 28 ); ?>><?php esc_html_e( 'Last 4 weeks', 'simple-lms' ); ?></option>
						<option value="112" <?php selected( $days, 112 ); ?>><?php esc_html_e( 'Last 16 weeks', 'simple-lms' ); ?></option>
					</select>
				</label>
				<label class="slms-attendance-review-checkbox">
					<input type="checkbox" name="attendance_review_include_late" value="1" <?php checked( $include_late ); ?>>
					<span><?php esc_html_e( 'Include late-only students', 'simple-lms' ); ?></span>
				</label>
				<button type="submit" class="slms-portal-button is-primary"><?php esc_html_e( 'Apply Filter', 'simple-lms' ); ?></button>
				<span class="slms-attendance-review-total"><?php echo esc_html( sprintf( _n( '%1$d student / %2$d issue', '%1$d students / %2$d issues', $total_students, 'simple-lms' ), $total_students, $total_issues ) ); ?></span>
			</form>

			<?php if ( empty( $groups ) ) : ?>
				<div class="slms-empty-state slms-attendance-review-empty">
					<h3><?php esc_html_e( 'No attendance issues found', 'simple-lms' ); ?></h3>
					<p><?php echo esc_html( $include_late ? __( 'No absent, leave, or late records were found in the selected period.', 'simple-lms' ) : __( 'No absent or leave records were found in the selected period.', 'simple-lms' ) ); ?></p>
				</div>
			<?php else : ?>
				<div class="slms-attendance-review-groups">
					<?php foreach ( $groups as $program ) : ?>
						<details class="slms-attendance-program-group" open>
							<summary>
								<span><?php echo esc_html( $program['program_name'] ); ?></span>
								<?php $this->render_attendance_review_summary_badges( $program['totals'], $include_late ); ?>
							</summary>
							<div class="slms-attendance-student-review-list">
								<?php foreach ( $program['students'] as $student ) : ?>
									<details class="slms-attendance-student-review-card">
										<summary class="slms-attendance-student-review-head">
											<div class="slms-attendance-student-review-title">
												<?php
												$student_meta = array( $student['person_code'] ?: __( 'No student ID', 'simple-lms' ) );
												if ( ! empty( $student['student_email'] ) ) {
													$student_meta[] = $student['student_email'];
												}
												if ( ! empty( $student['student_phone'] ) ) {
													$student_meta[] = sprintf( __( 'Phone: %s', 'simple-lms' ), $student['student_phone'] );
												}
												?>
												<h3><?php echo $this->get_person_avatar_name_markup( (int) ( $student['student_user_id'] ?? 0 ), $student['student_name'] ?: __( 'Unnamed student', 'simple-lms' ), 'is-large' ); ?></h3>
												<p><?php echo esc_html( implode( ' · ', $student_meta ) ); ?></p>
											</div>
											<?php $this->render_attendance_review_summary_badges( $student['issue_totals'], $include_late ); ?>
											<span class="slms-attendance-review-expand-label" aria-hidden="true"><?php esc_html_e( 'Review', 'simple-lms' ); ?></span>
										</summary>

										<div class="slms-attendance-student-review-body">
											<?php $this->render_attendance_review_matrix_table( $student['weekly_subjects'], __( 'Week-Based Subjects', 'simple-lms' ), __( 'Week', 'simple-lms' ) ); ?>
											<?php $this->render_attendance_review_matrix_table( $student['class_subjects'], __( 'Class-Based Subjects', 'simple-lms' ), __( 'Class', 'simple-lms' ) ); ?>
										</div>
									</details>
								<?php endforeach; ?>
							</div>
						</details>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}

	private function render_attendance_review_matrix_table( array $matrix, $heading, $row_heading ) {
		if ( empty( $matrix['subjects'] ) ) {
			return;
		}
		?>
		<div class="slms-attendance-matrix-section">
			<h4><?php echo esc_html( $heading ); ?></h4>
			<table class="slms-portal-table slms-attendance-review-table slms-attendance-matrix-table">
				<thead>
					<tr>
						<th><?php echo esc_html( $row_heading ); ?></th>
						<?php foreach ( $matrix['subjects'] as $subject ) : ?>
							<th><?php echo esc_html( $subject['subject_title'] ); ?></th>
						<?php endforeach; ?>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $matrix['rows'] ) ) : ?>
						<tr>
							<td colspan="<?php echo esc_attr( (string) ( count( $matrix['subjects'] ) + 1 ) ); ?>"><?php esc_html_e( 'No class sessions were found for these subjects in the selected period.', 'simple-lms' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $matrix['rows'] as $row ) : ?>
							<tr>
								<th scope="row"><?php echo esc_html( $row['label'] ); ?></th>
								<?php foreach ( $matrix['subjects'] as $subject ) : ?>
									<td><?php $this->render_attendance_review_matrix_cell( $row['cells'][ $subject['subject_key'] ] ?? array( 'status' => 'no_class' ) ); ?></td>
								<?php endforeach; ?>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private function render_attendance_review_matrix_cell( array $cell ) {
		$status = sanitize_key( $cell['status'] ?? 'no_class' );
		$this->render_attendance_review_status_badge( $status );
		$meta = array();
		if ( ! empty( $cell['date'] ) ) {
			$meta[] = $cell['date'];
		}
		if ( ! empty( $cell['session_title'] ) ) {
			$meta[] = $cell['session_title'];
		}
		if ( ! empty( $meta ) ) {
			echo '<small>' . esc_html( implode( ' · ', $meta ) ) . '</small>';
		}
	}

	private function render_attendance_review_summary_badges( array $totals, $include_late ) {
		echo '<span class="slms-attendance-review-summary-badges">';
		$this->render_attendance_review_count_badge( 'absent', (int) ( $totals['absent_count'] ?? 0 ) );
		$this->render_attendance_review_count_badge( 'leave', (int) ( $totals['leave_count'] ?? 0 ) );
		if ( $include_late ) {
			$this->render_attendance_review_count_badge( 'late', (int) ( $totals['late_count'] ?? 0 ) );
		}
		echo '</span>';
	}

	private function render_attendance_review_count_badge( $status, $count ) {
		printf(
			'<span class="slms-attendance-review-badge is-%1$s"><strong>%2$s</strong> %3$s</span>',
			esc_attr( sanitize_html_class( $status ) ),
			esc_html( (string) max( 0, (int) $count ) ),
			esc_html( $this->get_attendance_review_status_label( $status ) )
		);
	}

	private function render_attendance_review_status_badge( $status ) {
		$status = sanitize_key( $status );
		printf(
			'<span class="slms-attendance-review-status is-%1$s">%2$s</span>',
			esc_attr( sanitize_html_class( $status ) ),
			esc_html( $this->get_attendance_review_status_label( $status ) )
		);
	}

	private function get_attendance_review_status_label( $status ) {
		$labels = array(
			'absent'  => __( 'Absent', 'simple-lms' ),
			'leave'   => __( 'Leave', 'simple-lms' ),
			'late'    => __( 'Late', 'simple-lms' ),
			'present'      => __( 'Present', 'simple-lms' ),
			'not_recorded' => __( 'Not Recorded', 'simple-lms' ),
			'no_class'     => __( 'No Class', 'simple-lms' ),
		);

		return $labels[ sanitize_key( $status ) ] ?? __( 'Record', 'simple-lms' );
	}

	private function render_security_panel( $page_url, $requires_password_change ) {
		?>
		<section class="slms-portal-panel slms-security-panel slms-flow-section">
			<?php if ( $requires_password_change ) : ?>
				<div class="slms-portal-alert is-warning">
					<p><?php esc_html_e( 'Set a new password to unlock the rest of the LMS portal.', 'simple-lms' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="" class="slms-form-stack slms-security-form">
				<?php wp_nonce_field( 'slms_portal_action', 'slms_portal_nonce' ); ?>
				<input type="hidden" name="slms_portal_action" value="change_password">
				<label>
					<span><?php esc_html_e( 'Current Password', 'simple-lms' ); ?></span>
					<input type="password" name="current_password" autocomplete="current-password" required>
				</label>
				<label>
					<span><?php esc_html_e( 'New Password', 'simple-lms' ); ?></span>
					<input type="password" name="new_password" autocomplete="new-password" required>
				</label>
				<label>
					<span><?php esc_html_e( 'Confirm New Password', 'simple-lms' ); ?></span>
					<input type="password" name="confirm_password" autocomplete="new-password" required>
				</label>
				<p class="slms-field-help"><?php esc_html_e( 'Use at least 8 characters and choose something different from the temporary or current password.', 'simple-lms' ); ?></p>
				<div class="slms-inline-actions slms-security-actions">
					<button type="submit" class="slms-portal-button"><?php echo esc_html( $requires_password_change ? __( 'Save New Password', 'simple-lms' ) : __( 'Update Password', 'simple-lms' ) ); ?></button>
				</div>
			</form>
		</section>
		<?php
	}

	private function render_leave_application_page( $page_url, array $subjects, ?array $profile ) {
		$profile      = $profile ?: array();
		$user         = wp_get_current_user();
		$student_name = $profile['display_name'] ?? $user->display_name;
		$student_code = $profile['person_code'] ?? $this->generate_fallback_person_code( $user->ID, 'student' );
		$program_name = ! empty( $profile['program_id'] ) ? $this->get_program_name( (int) $profile['program_id'] ) : '';
		$leave_types  = $this->leave_applications->get_leave_types();
		$has_subjects = ! empty( $subjects );
		?>
		<section class="slms-portal-panel slms-leave-panel slms-flow-section">
			<div class="slms-section-heading">
				<div>
					<h2><?php esc_html_e( 'Leave Application', 'simple-lms' ); ?></h2>
					<p class="slms-field-help"><?php esc_html_e( 'Submit one subject-specific leave request for one class date at a time. The registrar, academic coordinator, and the assigned lecturer(s) will receive it by email.', 'simple-lms' ); ?></p>
				</div>
				<span class="slms-portal-badge"><?php esc_html_e( 'Student Request', 'simple-lms' ); ?></span>
			</div>
			<div class="slms-leave-panel-grid">
				<section class="slms-leave-profile-card">
					<span class="slms-leave-profile-kicker"><?php esc_html_e( 'Applicant', 'simple-lms' ); ?></span>
					<h3><?php echo $this->get_person_avatar_name_markup( $user->ID, $student_name, 'is-large' ); ?></h3>
					<div class="slms-leave-profile-facts">
						<div><span><?php esc_html_e( 'Student ID', 'simple-lms' ); ?></span><strong><?php echo esc_html( $student_code ); ?></strong></div>
						<div><span><?php esc_html_e( 'Email', 'simple-lms' ); ?></span><strong><?php echo esc_html( $user->user_email ); ?></strong></div>
						<div><span><?php esc_html_e( 'Program', 'simple-lms' ); ?></span><strong><?php echo esc_html( $program_name ?: __( 'Not assigned yet', 'simple-lms' ) ); ?></strong></div>
					</div>
					<p class="slms-field-help"><?php esc_html_e( 'Your request email is sent using your student identity and signed with your name.', 'simple-lms' ); ?></p>
				</section>
				<section class="slms-leave-form-card">
					<form method="post" action="" enctype="multipart/form-data" class="slms-form-stack slms-leave-form" data-slms-leave-form>
						<?php wp_nonce_field( 'slms_portal_action', 'slms_portal_nonce' ); ?>
						<input type="hidden" name="slms_portal_action" value="submit_leave_application">
						<div class="slms-form-split">
							<label>
								<span><?php esc_html_e( 'Leave Type', 'simple-lms' ); ?></span>
								<select name="leave_type" required>
									<option value=""><?php esc_html_e( 'Select leave type', 'simple-lms' ); ?></option>
									<?php foreach ( $leave_types as $leave_value => $leave_label ) : ?>
										<option value="<?php echo esc_attr( $leave_value ); ?>"><?php echo esc_html( $leave_label ); ?></option>
									<?php endforeach; ?>
								</select>
							</label>
							<label>
								<span><?php esc_html_e( 'Class', 'simple-lms' ); ?></span>
								<select name="subject_id" <?php echo $has_subjects ? 'required' : 'disabled'; ?>>
									<option value=""><?php echo esc_html( $has_subjects ? __( 'Select enrolled subject', 'simple-lms' ) : __( 'No enrolled subjects available', 'simple-lms' ) ); ?></option>
									<?php foreach ( $subjects as $subject ) : ?>
										<?php $subject_label = $subject['subject_title'] ?: $subject['title']; ?>
										<?php $subject_code = $subject['section_code'] ?: ( $subject['subject_code'] ?? '' ); ?>
										<option value="<?php echo esc_attr( (string) ( $subject['id'] ?? 0 ) ); ?>"><?php echo esc_html( $subject_label . ( $subject_code ? ' (' . $subject_code . ')' : '' ) ); ?></option>
									<?php endforeach; ?>
								</select>
							</label>
						</div>
						<div class="slms-form-split slms-leave-form-date-row">
							<label>
								<span><?php esc_html_e( 'Class Date', 'simple-lms' ); ?></span>
								<input type="date" name="leave_date" required>
							</label>
						</div>
						<label>
							<span><?php esc_html_e( 'Reason / Description', 'simple-lms' ); ?></span>
							<textarea name="leave_reason" rows="6" required></textarea>
						</label>
						<label>
							<span><?php esc_html_e( 'Supporting Document', 'simple-lms' ); ?></span>
							<input type="file" name="leave_attachment" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx">
						</label>
						<p class="slms-field-help"><?php esc_html_e( 'Accepted files: PDF, JPG, JPEG, PNG, WEBP, DOC, and DOCX. Maximum file size: 10MB.', 'simple-lms' ); ?></p>
						<p class="slms-field-help"><?php esc_html_e( 'If you need to notify several classes or several dates, submit one leave request per class date so the correct lecturer receives it.', 'simple-lms' ); ?></p>
						<button type="submit" class="slms-portal-button" <?php echo $has_subjects ? '' : 'disabled'; ?>><?php esc_html_e( 'Send Leave Application', 'simple-lms' ); ?></button>
					</form>
				</section>
			</div>
		</section>
		<?php
	}

	private function render_subjects_index( $page_url, array $subjects ) {
		?>
		<section class="slms-portal-panel slms-subjects-page slms-flow-section">
			<?php if ( empty( $subjects ) ) : ?>
				<p class="slms-field-help"><?php esc_html_e( 'No active subjects are currently available in your frontend workspace.', 'simple-lms' ); ?></p>
			<?php else : ?>
				<div class="slms-subject-grid">
					<?php foreach ( $subjects as $subject ) : ?>
						<?php $lecturer_label = $this->get_subject_lecturer_label( (int) $subject['id'] ); ?>
						<?php
						$this->render_workspace_card(
							$subject['subject_title'] ?: $subject['title'],
							$lecturer_label ?: __( 'No lecturer assigned yet.', 'simple-lms' ),
							array(
								'code' => $subject['section_code'] ?: ( $subject['subject_code'] ?? __( 'Subject', 'simple-lms' ) ),
								'meta' => $subject['term_name'] ?: __( 'No term assigned', 'simple-lms' ),
								'url'  => $this->subject_url( $page_url, (int) $subject['id'] ),
								'icon' => 'subject',
							)
						);
						?>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}


	private function render_auditing_subjects_index( $page_url, array $subjects ) {
		?>
		<section class="slms-portal-panel slms-subjects-page slms-flow-section">
			<div class="slms-section-heading">
				<div>
					<h2><?php esc_html_e( 'Auditing', 'simple-lms' ); ?></h2>
					<p class="slms-field-help"><?php esc_html_e( 'These are subjects where you are enrolled as an auditor. You can view materials, join discussions, and submit assignments without affecting official student records.', 'simple-lms' ); ?></p>
				</div>
				<span class="slms-portal-badge"><?php echo esc_html( sprintf( _n( '%d subject', '%d subjects', count( $subjects ), 'simple-lms' ), count( $subjects ) ) ); ?></span>
			</div>
			<?php if ( empty( $subjects ) ) : ?>
				<p class="slms-field-help"><?php esc_html_e( 'You are not auditing any subjects yet.', 'simple-lms' ); ?></p>
			<?php else : ?>
				<div class="slms-subject-grid">
					<?php foreach ( $subjects as $subject ) : ?>
						<?php $lecturer_label = $this->get_subject_lecturer_label( (int) $subject['id'] ); ?>
						<?php
						$this->render_workspace_card(
							$subject['subject_title'] ?: $subject['title'],
							$lecturer_label ?: __( 'No lecturer assigned yet.', 'simple-lms' ),
							array(
								'code' => $subject['section_code'] ?: ( $subject['subject_code'] ?? __( 'Subject', 'simple-lms' ) ),
								'meta' => __( 'Auditor', 'simple-lms' ),
								'url'  => $this->subject_url( $page_url, (int) $subject['id'] ),
								'icon' => 'visibility',
							)
						);
						?>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}

	private function render_subject_view( $page_url, $role, array $subject_view, $active_tab, $open_item_id = 0 ) {
		$subject              = $subject_view['subject'];
		$subject_id           = (int) $subject['id'];
		$attendance_view      = $subject_view['attendance'];
		$current_week_number  = absint( $subject_view['current_week_number'] ?? 0 );
		$subject_code         = $subject['section_code'] ?: ( $subject['subject_code'] ?? '' );
		$lecturer_line        = $this->get_subject_lecturer_label( $subject_id );
		$subject_header_image_url     = $this->get_subject_header_image_url( $subject_id );
		$subject_header_image_opacity = $this->get_subject_header_image_opacity_percent( $subject_id );
		$can_edit_subject_header      = $this->can_manage_subject_header( $subject_id, $role );
			$can_teach_subject           = $this->can_teach_subject( $subject_id, $role );
			$is_auditing_subject        = $this->enrollments->user_can_audit_section( get_current_user_id(), $subject_id ) && ! $can_teach_subject;
		$attendance_tab_label = $this->attendance->can_manage_attendance( get_current_user_id(), $subject_id ) ? __( 'Take Attendance', 'simple-lms' ) : __( 'Attendance', 'simple-lms' );
		$tabs                = array(
			'materials'   => array(
				'label' => __( 'Materials', 'simple-lms' ),
				'icon'  => 'dashicons-media-document',
				'count' => count( $subject_view['materials'] ),
			),
			'assignments' => array(
				'label' => __( 'Assignments', 'simple-lms' ),
				'icon'  => 'dashicons-clipboard',
				'count' => count( $subject_view['assignments'] ),
			),
			'discussions' => array(
				'label' => __( 'Discussions', 'simple-lms' ),
				'icon'  => 'dashicons-format-chat',
				'count' => count( $subject_view['threads'] ),
			),
			'people'      => array(
				'label' => __( 'Students', 'simple-lms' ),
				'icon'  => 'dashicons-admin-users',
				'count' => count( $subject_view['roster'] ),
			),
			'results'     => array(
				'label' => __( 'Results', 'simple-lms' ),
				'icon'  => 'dashicons-chart-pie',
				'count' => null,
			),
			'attendance'  => array(
				'label' => $attendance_tab_label,
				'icon'  => 'dashicons-yes-alt',
				'count' => null,
			),
		);

			if ( $is_auditing_subject ) {
				unset( $tabs['people'], $tabs['results'], $tabs['attendance'] );
				if ( ! isset( $tabs[ $active_tab ] ) ) {
					$active_tab = 'materials';
				}
			}
		?>
		<section class="slms-page-flow-shell slms-page-flow-shell--subject">
		<header class="slms-portal-banner slms-subject-hero<?php echo $subject_header_image_url ? ' has-header-image' : ''; ?>">
			<?php if ( $subject_header_image_url ) : ?>
				<div class="slms-portal-header-media" style="--slms-header-image-opacity: <?php echo esc_attr( $this->format_header_image_opacity_decimal( $subject_header_image_opacity ) ); ?>; background-image: url('<?php echo esc_url( $subject_header_image_url ); ?>');"></div>
			<?php endif; ?>
			<div class="slms-portal-banner-copy slms-subject-hero-copy">
				<div class="slms-subject-hero-topbar">
					<div class="slms-subject-hero-copy-main">
					<p class="slms-portal-kicker"><?php echo esc_html( $subject['term_name'] ?: __( 'Subject Workspace', 'simple-lms' ) ); ?></p>
						<h2 class="slms-portal-title"><?php echo esc_html( $subject['subject_title'] ?: $subject['title'] ); ?></h2>
						<div class="slms-portal-identity slms-subject-hero-meta">
						<?php if ( $subject_code ) : ?>
							<span class="slms-portal-identity-chip is-subject-code"><span class="dashicons dashicons-tag" aria-hidden="true"></span><span><?php echo esc_html( $subject_code ); ?></span></span>
						<?php endif; ?>
						<?php if ( $lecturer_line ) : ?>
							<span class="slms-portal-identity-chip is-subject-lecturers"><span class="dashicons dashicons-businessperson" aria-hidden="true"></span><span><?php echo esc_html( $lecturer_line ); ?></span></span>
						<?php endif; ?>
							<?php if ( $is_auditing_subject ) : ?>
								<span class="slms-portal-identity-chip is-auditor"><span class="dashicons dashicons-visibility" aria-hidden="true"></span><span><?php esc_html_e( 'Auditor', 'simple-lms' ); ?></span></span>
							<?php endif; ?>
					</div>
					</div>
					<a class="slms-portal-button is-secondary slms-subject-hero-back" href="<?php echo esc_url( $this->view_url( $page_url, $is_auditing_subject ? 'auditing' : 'subjects' ) ); ?>"><span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span><span><?php echo esc_html( $is_auditing_subject ? __( 'Auditing', 'simple-lms' ) : __( 'My Subjects', 'simple-lms' ) ); ?></span></a>
				</div>
				<div class="slms-header-action-row">
					<div class="slms-tab-strip slms-portal-actions--hero slms-subject-hero-tabs">
					<?php foreach ( $tabs as $tab_key => $tab_config ) : ?>
						<a class="slms-tab-link slms-tab-link--<?php echo esc_attr( sanitize_html_class( $tab_key ) ); ?> <?php echo $active_tab === $tab_key ? 'is-active' : ''; ?>" href="<?php echo esc_url( $this->subject_url( $page_url, $subject_id, $tab_key ) ); ?>"><span class="dashicons <?php echo esc_attr( $tab_config['icon'] ); ?>" aria-hidden="true"></span><span><?php echo esc_html( $tab_config['label'] ); ?></span><?php if ( null !== $tab_config['count'] ) : ?><strong class="slms-tab-count"><?php echo esc_html( (string) $tab_config['count'] ); ?></strong><?php endif; ?></a>
					<?php endforeach; ?>
					</div>
					<?php if ( $can_edit_subject_header ) : ?>
						<button type="button" class="slms-header-edit-button" data-slms-open-header-image-modal data-slms-header-action="save_subject_header_image" data-slms-header-subject="<?php echo esc_attr( (string) $subject_id ); ?>" data-slms-header-tab="<?php echo esc_attr( $active_tab ); ?>" data-slms-header-title="<?php echo esc_attr__( 'Edit Subject Header Image', 'simple-lms' ); ?>" data-slms-current-image="<?php echo esc_url( $subject_header_image_url ); ?>" data-slms-current-opacity="<?php echo esc_attr( (string) $subject_header_image_opacity ); ?>" aria-label="<?php esc_attr_e( 'Edit subject header image', 'simple-lms' ); ?>" title="<?php esc_attr_e( 'Edit subject header image', 'simple-lms' ); ?>"><span class="slms-header-edit-button-icon" aria-hidden="true"><?php echo $this->get_portal_icon_markup( 'image-edit' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span></button>
					<?php endif; ?>
				</div>
			</div>
		</header>
		<div class="slms-page-flow-main slms-page-flow-main--subject">
		<?php

		if ( 'materials' === $active_tab ) {
			$this->render_materials_tab( $page_url, $subject_id, $role, $subject_view['materials'], $open_item_id, $current_week_number );
		} elseif ( 'assignments' === $active_tab ) {
			$this->render_assignments_tab( $page_url, $subject_id, $role, $subject_view['assignments'], $open_item_id, $current_week_number );
		} elseif ( 'discussions' === $active_tab ) {
			$this->render_discussions_tab( $page_url, $subject_id, $role, $subject_view['threads'], $open_item_id );
		} elseif ( 'attendance' === $active_tab && ! $is_auditing_subject ) {
			$this->render_attendance_tab( $subject_id, $role, $attendance_view, $subject_view['assignments'], $current_week_number );
		} elseif ( 'people' === $active_tab && ! $is_auditing_subject ) {
			$this->render_people_tab( $subject_id, $role, $subject_view['roster'] );
		} else {
			$this->render_results_tab( $subject_id, $role, $subject_view['gradebook'], $subject_view['assignments'], $attendance_view );
		}
		?>
		</div>
		</section>
		<?php

		$this->render_subject_media_viewer();
	}

	private function render_materials_tab( $page_url, $subject_id, $role, array $materials, $open_item_id = 0, $current_week_number = 0 ) {
		$can_teach = $this->can_teach_subject( $subject_id, $role );
		?>
		<div class="slms-subject-tab-layout">
			<?php if ( $can_teach ) : ?>
				<details class="slms-subject-action-card">
					<summary><span class="slms-action-panel-trigger"><span class="slms-action-panel-trigger-label"><?php esc_html_e( 'Add Subject Material', 'simple-lms' ); ?></span></span></summary>
					<div class="slms-accordion-body slms-action-panel-window">
						<form method="post" action="" enctype="multipart/form-data" class="slms-form-stack">
							<?php wp_nonce_field( 'slms_portal_action', 'slms_portal_nonce' ); ?>
							<input type="hidden" name="slms_portal_action" value="create_material">
							<input type="hidden" name="subject_id" value="<?php echo esc_attr( $subject_id ); ?>">
							<label><span><?php esc_html_e( 'Title', 'simple-lms' ); ?></span><input type="text" name="title" required></label>
							<?php $this->render_portal_rich_text_editor_field( 'content', __( 'Description', 'simple-lms' ), '', 5, __( 'Write the material description...', 'simple-lms' ), '', true ); ?>
							<?php $this->render_subject_item_week_fields( $subject_id ); ?>
							<?php $this->render_subject_file_upload_repeater( 'material_files', __( 'Upload Files', 'simple-lms' ) ); ?>
							<p class="slms-field-help"><?php esc_html_e( 'Add multiple files if needed. Each file can be up to 100MB.', 'simple-lms' ); ?></p>
							<?php $this->render_subject_embed_url_repeater( __( 'Video / Audio Embed URLs', 'simple-lms' ) ); ?>
							<button type="submit" class="slms-portal-button"><?php esc_html_e( 'Publish Material', 'simple-lms' ); ?></button>
						</form>
					</div>
				</details>
			<?php endif; ?>
			<section class="slms-portal-panel slms-subject-list-panel">
				<div class="slms-section-heading">
					<div>
						<h2><?php esc_html_e( 'Subject Materials', 'simple-lms' ); ?></h2>
						<p class="slms-field-help"><?php esc_html_e( 'View any material inline, with downloads, media playback, and gallery support.', 'simple-lms' ); ?></p>
					</div>
					<span class="slms-portal-badge"><?php echo esc_html( sprintf( _n( '%d item', '%d items', count( $materials ), 'simple-lms' ), count( $materials ) ) ); ?></span>
				</div>
				<?php if ( empty( $materials ) ) : ?>
					<p class="slms-field-help"><?php esc_html_e( 'No materials have been added to this subject yet.', 'simple-lms' ); ?></p>
				<?php else : ?>
					<?php
					$item_views = $this->prepare_subject_item_views( $subject_id, $role, 'materials', $materials );
					if ( empty( $item_views ) ) {
						echo '<p class="slms-field-help">' . esc_html__( 'No materials are currently visible in this subject.', 'simple-lms' ) . '</p>';
					} else {
						$this->render_grouped_subject_item_stack( $page_url, $subject_id, $role, 'materials', $item_views, $open_item_id, $current_week_number );
					}
					?>
				<?php endif; ?>
			</section>
		</div>
		<?php
	}

	private function render_assignments_tab( $page_url, $subject_id, $role, array $assignments, $open_item_id = 0, $current_week_number = 0 ) {
		$can_teach = $this->can_teach_subject( $subject_id, $role );
		?>
		<div class="slms-subject-tab-layout">
			<?php if ( $can_teach ) : ?>
				<details class="slms-subject-action-card">
					<summary><span class="slms-action-panel-trigger"><span class="slms-action-panel-trigger-label"><?php esc_html_e( 'Create New Assignment', 'simple-lms' ); ?></span></span></summary>
					<div class="slms-accordion-body slms-action-panel-window">
						<form method="post" action="" enctype="multipart/form-data" class="slms-form-stack">
							<?php wp_nonce_field( 'slms_portal_action', 'slms_portal_nonce' ); ?>
							<input type="hidden" name="slms_portal_action" value="create_assignment">
							<input type="hidden" name="subject_id" value="<?php echo esc_attr( $subject_id ); ?>">
							<label><span><?php esc_html_e( 'Title', 'simple-lms' ); ?></span><input type="text" name="title" required></label>
							<?php $this->render_portal_rich_text_editor_field( 'content', __( 'Instructions', 'simple-lms' ), '', 5, __( 'Write the assignment instructions...', 'simple-lms' ), '', true ); ?>
							<div class="slms-form-split">
								<label><span><?php esc_html_e( 'Deadline', 'simple-lms' ); ?></span><input type="datetime-local" name="due_at"></label>
								<label><span><?php esc_html_e( 'Weight (%)', 'simple-lms' ); ?></span><input type="number" name="weight" step="0.1" min="0"></label>
							</div>
							<label><span><?php esc_html_e( 'Points', 'simple-lms' ); ?></span><input type="number" name="points" step="1" min="0" value="100"></label>
							<?php $this->render_subject_embed_url_repeater( __( 'Video / Audio Embed URLs', 'simple-lms' ) ); ?>
							<?php $this->render_assignment_submission_type_field( 'text_file' ); ?>
							<?php $this->render_subject_item_week_fields( $subject_id ); ?>
							<?php $this->render_subject_file_upload_repeater( 'assignment_files', __( 'Attach Files', 'simple-lms' ) ); ?>
							<p class="slms-field-help"><?php esc_html_e( 'Assignments can include multiple brief files, references, or media handouts. Each file can be up to 100MB.', 'simple-lms' ); ?></p>
							<button type="submit" class="slms-portal-button"><?php esc_html_e( 'Publish Assignment', 'simple-lms' ); ?></button>
						</form>
					</div>
				</details>
			<?php endif; ?>
			<section class="slms-portal-panel slms-subject-list-panel">
				<div class="slms-section-heading">
					<div>
						<h2><?php esc_html_e( 'Assignments', 'simple-lms' ); ?></h2>
						<p class="slms-field-help"><?php esc_html_e( 'View an assignment inline to read the full brief, review its files, submit work, and check grading.', 'simple-lms' ); ?></p>
					</div>
					<span class="slms-portal-badge"><?php echo esc_html( sprintf( _n( '%d item', '%d items', count( $assignments ), 'simple-lms' ), count( $assignments ) ) ); ?></span>
				</div>
				<?php if ( empty( $assignments ) ) : ?>
					<p class="slms-field-help"><?php esc_html_e( 'No assignments have been created for this subject yet.', 'simple-lms' ); ?></p>
				<?php else : ?>
					<?php
					$item_views = $this->prepare_subject_item_views( $subject_id, $role, 'assignments', $assignments );
					if ( empty( $item_views ) ) {
						echo '<p class="slms-field-help">' . esc_html__( 'No assignments are currently visible in this subject.', 'simple-lms' ) . '</p>';
					} else {
						$this->render_grouped_subject_item_stack( $page_url, $subject_id, $role, 'assignments', $item_views, $open_item_id, $current_week_number );
					}
					?>
				<?php endif; ?>
			</section>
		</div>
		<?php
	}


	private function render_assignment_submission_type_field( $current = 'text_file' ) {
		$current  = $this->normalize_assignment_submission_type( $current, 'text_file' );
		$field_id = wp_unique_id( 'slms_assignment_submission_type_' );
		$options  = array(
			'file'      => __( 'File upload only', 'simple-lms' ),
			'text'      => __( 'Typed answer only', 'simple-lms' ),
			'text_file' => __( 'Typed answer or file upload', 'simple-lms' ),
		);
		?>
		<div class="slms-assignment-submission-type-card">
			<label for="<?php echo esc_attr( $field_id ); ?>">
				<span><?php esc_html_e( 'Student Submission Type', 'simple-lms' ); ?></span>
				<select id="<?php echo esc_attr( $field_id ); ?>" name="submission_type" required>
					<?php foreach ( $options as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<p class="slms-field-help"><?php esc_html_e( 'Choose whether students must upload a file, type their answer in the LMS, or may use either method.', 'simple-lms' ); ?></p>
		</div>
		<?php
	}

	private function normalize_assignment_submission_type( $value, $default = 'text_file' ) {
		$type    = sanitize_key( (string) $value );
		$default = in_array( $default, array( 'file', 'text', 'text_file' ), true ) ? $default : 'text_file';

		return in_array( $type, array( 'file', 'text', 'text_file' ), true ) ? $type : $default;
	}

	private function get_assignment_submission_type_label( $type ) {
		$type = $this->normalize_assignment_submission_type( $type, 'text_file' );

		if ( 'text' === $type ) {
			return __( 'Typed answer only', 'simple-lms' );
		}

		if ( 'text_file' === $type ) {
			return __( 'Typed answer or file upload', 'simple-lms' );
		}

		return __( 'File upload only', 'simple-lms' );
	}

	private function get_assignment_submission_requirement_message( $type ) {
		$type = $this->normalize_assignment_submission_type( $type, 'text_file' );

		if ( 'text' === $type ) {
			return __( 'Type your answer directly in the LMS. File uploads are disabled for this assignment.', 'simple-lms' );
		}

		if ( 'text_file' === $type ) {
			return __( 'You may type an answer, upload a file, or provide both.', 'simple-lms' );
		}

		return __( 'Upload your completed work as a file. You may also add a short note for your teacher.', 'simple-lms' );
	}

	private function assignment_submission_allows_text( $type ) {
		return in_array( $this->normalize_assignment_submission_type( $type, 'text_file' ), array( 'text', 'text_file' ), true );
	}

	private function assignment_submission_allows_file( $type ) {
		return in_array( $this->normalize_assignment_submission_type( $type, 'text_file' ), array( 'file', 'text_file' ), true );
	}

	private function get_assignment_submission_summary_title( array $submission, $fallback = '' ) {
		$text = trim( wp_strip_all_tags( (string) ( $submission['submission_text'] ?? '' ) ) );

		if ( '' !== $text ) {
			return wp_trim_words( $text, 10, '...' );
		}

		if ( ! empty( $submission['attachment_name'] ) ) {
			return sanitize_text_field( (string) $submission['attachment_name'] );
		}

		return $fallback ?: __( 'Submitted Work', 'simple-lms' );
	}

	private function render_discussions_tab( $page_url, $subject_id, $role, array $threads, $open_item_id = 0 ) {
		$can_start_topic = $this->can_start_discussion_topic( get_current_user_id(), $subject_id, $role );
		?>
		<div class="slms-subject-tab-layout">
			<?php if ( $can_start_topic ) : ?>
				<details class="slms-subject-action-card">
					<summary><span class="slms-action-panel-trigger"><span class="slms-action-panel-trigger-label"><?php esc_html_e( 'Start New Topic', 'simple-lms' ); ?></span></span></summary>
					<div class="slms-accordion-body slms-action-panel-window">
						<form method="post" action="" enctype="multipart/form-data" class="slms-form-stack">
							<?php wp_nonce_field( 'slms_portal_action', 'slms_portal_nonce' ); ?>
							<input type="hidden" name="slms_portal_action" value="create_thread">
							<input type="hidden" name="subject_id" value="<?php echo esc_attr( $subject_id ); ?>">
							<label><span><?php esc_html_e( 'Thread Title', 'simple-lms' ); ?></span><input type="text" name="title" required></label>
							<?php $this->render_portal_rich_text_editor_field( 'content', __( 'Description', 'simple-lms' ), '', 5, __( 'Write the discussion topic description...', 'simple-lms' ), '', true ); ?>
							<?php $this->render_subject_file_upload_repeater( 'thread_files', __( 'Upload Files', 'simple-lms' ) ); ?>
							<p class="slms-field-help"><?php esc_html_e( 'Thread posts can include multiple attachments. Each file can be up to 100MB.', 'simple-lms' ); ?></p>
							<?php $this->render_subject_embed_url_repeater( __( 'Video / Audio Embed URLs', 'simple-lms' ) ); ?>
							<button type="submit" class="slms-portal-button"><?php esc_html_e( 'Publish Thread', 'simple-lms' ); ?></button>
						</form>
					</div>
				</details>
			<?php endif; ?>
			<section class="slms-portal-panel slms-subject-list-panel">
				<div class="slms-section-heading">
					<div>
						<h2><?php esc_html_e( 'Discussion Threads', 'simple-lms' ); ?></h2>
						<p class="slms-field-help"><?php esc_html_e( 'View a thread inline to read the full discussion, see its media, and reply in place.', 'simple-lms' ); ?></p>
					</div>
					<span class="slms-portal-badge"><?php echo esc_html( sprintf( _n( '%d thread', '%d threads', count( $threads ), 'simple-lms' ), count( $threads ) ) ); ?></span>
				</div>
				<?php if ( empty( $threads ) ) : ?>
					<p class="slms-field-help"><?php esc_html_e( 'No discussion threads have been started for this subject yet.', 'simple-lms' ); ?></p>
				<?php else : ?>
					<?php
					$item_views = $this->prepare_subject_item_views( $subject_id, $role, 'discussions', $threads );
					if ( empty( $item_views ) ) {
						echo '<p class="slms-field-help">' . esc_html__( 'No discussions are currently visible in this subject.', 'simple-lms' ) . '</p>';
					} else {
						$this->render_subject_item_stack( $page_url, $subject_id, $role, $item_views, $open_item_id );
					}
					?>
				<?php endif; ?>
			</section>
		</div>
		<?php
	}

	private function prepare_subject_item_views( $subject_id, $role, $tab, array $items ) {
		$item_views = array();

		foreach ( $items as $item ) {
			$item_id = $this->get_subject_tab_item_id( $tab, $item );

			if ( ! $item_id ) {
				continue;
			}

			$item_view = $this->get_subject_item_view( get_current_user_id(), $role, $subject_id, $tab, $item_id );

			if ( empty( $item_view['post'] ) ) {
				continue;
			}

			$item_views[] = $item_view;
		}

		usort( $item_views, array( $this, 'compare_subject_item_views' ) );

		return $item_views;
	}

	private function render_grouped_subject_item_stack( $page_url, $subject_id, $role, $tab, array $item_views, $open_item_id = 0, $current_week_number = 0 ) {
		$groups         = $this->group_subject_item_views( $subject_id, $tab, $item_views );
		$open_group_key = $this->get_subject_group_key_for_open_item( $item_views, $open_item_id );

		foreach ( $groups as $group ) {
			$bucket = $group['bucket'];
			$is_current_week       = $current_week_number && (int) ( $bucket['week_number'] ?? 0 ) === (int) $current_week_number && empty( $bucket['is_general'] );
			$item_count            = count( $group['items'] );
			$contains_open_item    = $open_group_key && $open_group_key === (string) ( $bucket['key'] ?? '' );
			$contains_welcome_thread = ! empty( $group['contains_welcome_thread'] );
			$should_open           = $contains_open_item;
			$group_classes         = array( 'slms-subject-detail-section', 'slms-subject-week-group' );

			if ( $is_current_week ) {
				$group_classes[] = 'is-current-week';
			}

			if ( $bucket['is_general'] ?? false ) {
				$group_classes[] = 'is-general-week';
			}

			if ( $item_count < 1 ) {
				$group_classes[] = 'is-empty-week';
			}
			?>
			<details class="<?php echo esc_attr( implode( ' ', $group_classes ) ); ?>" <?php echo $should_open ? 'open' : ''; ?>>
				<summary class="slms-subject-week-group-summary">
					<div class="slms-section-heading">
						<div class="slms-subject-week-group-heading-main">
							<div class="slms-subject-week-group-title-row">
								<span class="slms-subject-week-group-toggle" aria-hidden="true"></span>
								<h3><?php echo esc_html( $bucket['label'] ); ?></h3>
							</div>
							<?php if ( ! empty( $bucket['description'] ) ) : ?>
								<p class="slms-field-help"><?php echo esc_html( $bucket['description'] ); ?></p>
							<?php elseif ( ! empty( $bucket['is_general'] ) ) : ?>
								<p class="slms-field-help"><?php esc_html_e( 'Items without a week assignment stay here until they are placed into a teaching week.', 'simple-lms' ); ?></p>
							<?php endif; ?>
							<?php if ( $contains_welcome_thread ) : ?>
								<div class="slms-subject-week-group-flags">
									<span class="slms-portal-badge slms-portal-badge--welcome-thread"><?php esc_html_e( 'Welcome Thread', 'simple-lms' ); ?></span>
								</div>
							<?php endif; ?>
						</div>
						<div class="slms-subject-week-group-heading-side">
							<span class="slms-portal-badge"><?php echo esc_html( sprintf( _n( '%d item', '%d items', $item_count, 'simple-lms' ), $item_count ) ); ?></span>
						</div>
					</div>
				</summary>
				<div class="slms-subject-week-group-body">
					<?php if ( $item_count < 1 ) : ?>
						<p class="slms-field-help slms-subject-week-group-empty"><?php echo esc_html( $this->get_subject_week_group_empty_message( $tab, $bucket ) ); ?></p>
					<?php else : ?>
						<div class="slms-subject-item-stack slms-subject-item-stack--week-list">
							<?php foreach ( $group['items'] as $item_view ) : ?>
								<?php $this->render_subject_item_accordion( $page_url, $subject_id, $role, $item_view, (int) $item_view['post']->ID === (int) $open_item_id ); ?>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</div>
			</details>
			<?php
		}
	}

	private function render_subject_item_stack( $page_url, $subject_id, $role, array $item_views, $open_item_id = 0 ) {
		?>
		<div class="slms-subject-item-stack">
			<?php foreach ( $item_views as $item_view ) : ?>
				<?php $this->render_subject_item_accordion( $page_url, $subject_id, $role, $item_view, (int) $item_view['post']->ID === (int) $open_item_id ); ?>
			<?php endforeach; ?>
		</div>
		<?php
	}

	private function group_subject_item_views( $subject_id, $tab, array $item_views ) {
		$groups = array();

		foreach ( $this->get_subject_week_structure( $subject_id ) as $defined_week ) {
			$bucket                  = $this->get_subject_week_bucket( $subject_id, (int) $defined_week['week_number'] );
			$groups[ $bucket['key'] ] = array(
				'bucket'                  => $bucket,
				'items'                   => array(),
				'contains_welcome_thread' => false,
			);
		}

		foreach ( $item_views as $item_view ) {
			$bucket = $item_view['week_bucket'] ?? $this->get_general_week_bucket();
			$key    = sanitize_key( (string) ( $bucket['key'] ?? 'general' ) );

			if ( '' === $key ) {
				$key = 'general';
			}

			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'bucket'                  => $bucket,
					'items'                   => array(),
					'contains_welcome_thread' => false,
				);
			}

			$groups[ $key ]['items'][] = $item_view;
			if ( ! empty( $item_view['is_welcome_thread'] ) ) {
				$groups[ $key ]['contains_welcome_thread'] = true;
			}
		}

		$groups = array_values(
			array_filter(
				$groups,
				static function ( $group ) {
					return ! empty( $group['items'] ) || empty( $group['bucket']['is_general'] );
				}
			)
		);

		usort( $groups, array( $this, 'compare_subject_week_groups' ) );

		return $groups;
	}

	private function compare_subject_week_groups( array $left, array $right ) {
		$left_bucket  = $left['bucket'] ?? array();
		$right_bucket = $right['bucket'] ?? array();
		$left_general  = ! empty( $left_bucket['is_general'] );
		$right_general = ! empty( $right_bucket['is_general'] );

		if ( $left_general !== $right_general ) {
			return $left_general ? 1 : -1;
		}

		$left_rank  = (int) ( $left_bucket['week_number'] ?? 0 );
		$right_rank = (int) ( $right_bucket['week_number'] ?? 0 );

		if ( $left_rank !== $right_rank ) {
			return $right_rank <=> $left_rank;
		}

		return strcmp( (string) ( $left_bucket['key'] ?? '' ), (string) ( $right_bucket['key'] ?? '' ) );
	}

	private function get_subject_group_key_for_open_item( array $item_views, $open_item_id ) {
		$open_item_id = absint( $open_item_id );

		if ( ! $open_item_id ) {
			return '';
		}

		foreach ( $item_views as $item_view ) {
			if ( (int) ( $item_view['post']->ID ?? 0 ) !== $open_item_id ) {
				continue;
			}

			return sanitize_key( (string) ( $item_view['week_bucket']['key'] ?? 'general' ) );
		}

		return '';
	}

	private function compare_subject_item_views( array $left, array $right ) {
		$left_tab  = (string) ( $left['tab'] ?? '' );
		$right_tab = (string) ( $right['tab'] ?? '' );

		if ( 'discussions' === $left_tab && 'discussions' === $right_tab ) {
			return $this->compare_discussion_thread_views_by_start_date( $left, $right );
		}

		$left_week_rank  = ! empty( $left['week_number'] ) ? (int) $left['week_number'] : PHP_INT_MAX;
		$right_week_rank = ! empty( $right['week_number'] ) ? (int) $right['week_number'] : PHP_INT_MAX;

		if ( $left_week_rank !== $right_week_rank ) {
			return $left_week_rank <=> $right_week_rank;
		}

		$left_order  = absint( $left['week_order'] ?? 0 );
		$right_order = absint( $right['week_order'] ?? 0 );

		if ( $left_order && $right_order && $left_order !== $right_order ) {
			return $left_order <=> $right_order;
		}

		if ( $left_order && ! $right_order ) {
			return -1;
		}

		if ( ! $left_order && $right_order ) {
			return 1;
		}

		$left_time  = $this->get_subject_item_sort_timestamp( $left['post'] ?? null );
		$right_time = $this->get_subject_item_sort_timestamp( $right['post'] ?? null );

		if ( $left_time !== $right_time ) {
			return $right_time <=> $left_time;
		}

		return (int) ( $right['post']->ID ?? 0 ) <=> (int) ( $left['post']->ID ?? 0 );
	}

	private function compare_discussion_thread_views_by_start_date( array $left, array $right ) {
		$left_post  = $left['post'] ?? null;
		$right_post = $right['post'] ?? null;
		$left_date  = $left_post instanceof \WP_Post ? (string) $left_post->post_date : '';
		$right_date = $right_post instanceof \WP_Post ? (string) $right_post->post_date : '';
		$date_order = strcmp( $right_date, $left_date );

		if ( 0 !== $date_order ) {
			return $date_order;
		}

		return (int) ( $right_post->ID ?? 0 ) <=> (int) ( $left_post->ID ?? 0 );
	}

	private function get_subject_item_sort_timestamp( $post ) {
		if ( ! $post instanceof \WP_Post ) {
			return 0;
		}

		$raw_date = $post->post_date_gmt ?: $post->post_date;
		$time     = $post->post_date_gmt ? AcademicClock::timestamp_from_utc( $raw_date ) : AcademicClock::timestamp_from_local( $raw_date );

		return false === $time ? 0 : (int) $time;
	}

	private function get_subject_tab_item_id( $tab, $item ) {
		if ( $item instanceof \WP_Post ) {
			return (int) $item->ID;
		}

		if ( is_array( $item ) ) {
			if ( 'assignments' === $tab ) {
				return absint( $item['id'] ?? 0 );
			}

			return absint( $item['ID'] ?? $item['id'] ?? 0 );
		}

		return 0;
	}

	private function render_subject_item_accordion( $page_url, $subject_id, $role, array $item_view, $is_open = false ) {
		$post                     = $item_view['post'];
		$tab                      = $item_view['tab'];
		$supports_visibility      = in_array( $tab, array( 'materials', 'assignments', 'discussions' ), true );
		$is_visible_to_students   = ! $supports_visibility || 'staff' !== ( $item_view['visibility'] ?? 'students' );
		$can_manage_visibility    = $supports_visibility && ! empty( $item_view['can_manage'] );
		$accordion_classes        = 'slms-subject-item-accordion';
		$summary_excerpt          = $this->get_subject_item_summary_excerpt( $item_view );
		$meta_bits                = $this->get_subject_item_meta_bits( $role, $item_view );
		$asset_bits               = $this->summarize_subject_item_assets( $item_view['attachments'], $item_view['embed_urls'] );
		$meta_bits                = array_values( array_filter( array_merge( $meta_bits, $asset_bits ) ) );
		$visibility_form_id       = 'slms-subject-item-visibility-' . absint( $subject_id ) . '-' . absint( $post->ID );

		if ( $can_manage_visibility && ! $is_visible_to_students ) {
			$accordion_classes .= ' is-hidden-item';
		}
		?>
		<details class="<?php echo esc_attr( $accordion_classes ); ?>" <?php echo $is_open ? 'open' : ''; ?>>
			<summary class="slms-subject-item-card">
				<span class="slms-subject-item-card-icon" aria-hidden="true"><?php echo $this->get_subject_item_summary_icon( $item_view ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<div class="slms-subject-item-card-content">
					<div class="slms-subject-item-card-head">
						<div class="slms-subject-item-card-title">
							<strong><?php echo esc_html( get_the_title( $post ) ); ?></strong>
						</div>
					</div>
					<?php if ( $summary_excerpt ) : ?>
						<p class="slms-subject-item-card-summary"><?php echo esc_html( $summary_excerpt ); ?></p>
					<?php endif; ?>
					<?php if ( $meta_bits ) : ?>
						<div class="slms-subject-item-card-meta">
							<?php foreach ( $meta_bits as $meta_bit ) : ?>
								<span><?php echo esc_html( $meta_bit ); ?></span>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</div>
				<div class="slms-subject-item-card-actions">
					<span class="slms-subject-item-card-action">
						<span class="slms-subject-item-card-action-view">
							<span><?php esc_html_e( 'View', 'simple-lms' ); ?></span>
						</span>
						<span class="slms-subject-item-card-action-close">
							<span><?php esc_html_e( 'Close', 'simple-lms' ); ?></span>
						</span>
					</span>
					<?php if ( $can_manage_visibility ) : ?>
						<button
							type="submit"
							form="<?php echo esc_attr( $visibility_form_id ); ?>"
							class="slms-subject-item-visibility-toggle <?php echo $is_visible_to_students ? 'is-visible' : 'is-hidden'; ?>"
							data-slms-summary-action-button
							aria-pressed="<?php echo $is_visible_to_students ? 'true' : 'false'; ?>"
							aria-label="<?php echo esc_attr( $is_visible_to_students ? __( 'Hide this item from students', 'simple-lms' ) : __( 'Show this item to students', 'simple-lms' ) ); ?>"
							title="<?php echo esc_attr( $is_visible_to_students ? __( 'Hide this item from students', 'simple-lms' ) : __( 'Show this item to students', 'simple-lms' ) ); ?>"
						>
							<span class="screen-reader-text"><?php echo esc_html( $is_visible_to_students ? __( 'Visible to students', 'simple-lms' ) : __( 'Hidden from students', 'simple-lms' ) ); ?></span>
							<span class="slms-subject-item-visibility-toggle-icon" aria-hidden="true"><?php echo $this->get_portal_icon_markup( $is_visible_to_students ? 'eye' : 'eye-off' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						</button>
					<?php endif; ?>
				</div>
			</summary>
			<?php if ( $can_manage_visibility ) : ?>
				<form method="post" action="" id="<?php echo esc_attr( $visibility_form_id ); ?>" class="slms-subject-item-visibility-form" hidden>
					<?php wp_nonce_field( 'slms_portal_action', 'slms_portal_nonce' ); ?>
					<input type="hidden" name="slms_portal_action" value="toggle_material_visibility">
					<input type="hidden" name="subject_id" value="<?php echo esc_attr( $subject_id ); ?>">
					<input type="hidden" name="item_id" value="<?php echo esc_attr( $post->ID ); ?>">
					<input type="hidden" name="item_tab" value="<?php echo esc_attr( $tab ); ?>">
					<input type="hidden" name="slms_keep_open" value="<?php echo $is_open ? '1' : '0'; ?>" data-slms-keep-open>
				</form>
			<?php endif; ?>
			<div class="slms-subject-item-accordion-body">
				<div class="slms-subject-detail-shell is-single">
					<article class="slms-subject-detail-main slms-subject-accordion-main">
						<div class="slms-subject-detail-head">
							<span class="slms-subject-detail-kicker"><?php echo esc_html( $item_view['config']['singular'] ); ?></span>
							<h2><?php echo esc_html( get_the_title( $post ) ); ?></h2>
							<?php if ( 'assignments' === $tab ) : ?>
								<p class="slms-subject-detail-subhead"><?php echo esc_html( implode( ' | ', $this->get_assignment_display_meta_bits( $item_view, true ) ) ); ?></p>
							<?php else : ?>
								<p class="slms-subject-detail-subhead slms-subject-detail-subhead--author">
									<span><?php echo esc_html( sprintf( __( 'Published %s by', 'simple-lms' ), $item_view['published_at'] ) ); ?></span>
									<?php echo $this->get_person_name_with_auditor_tag_markup( $this->get_person_icon_name_markup( $item_view['author_name'], 'is-compact' ), ! empty( $item_view['author_is_auditor'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								</p>
							<?php endif; ?>
							<?php if ( $item_view['can_manage'] ) : ?>
								<div class="slms-subject-detail-head-actions">
									<?php $this->render_subject_item_inline_editor( $subject_id, $tab, $item_view ); ?>
								</div>
							<?php endif; ?>
						</div>

						<?php if ( ! empty( $post->post_content ) ) : ?>
							<div class="slms-rich-content">
								<?php echo wpautop( wp_kses_post( $post->post_content ) ); ?>
							</div>
						<?php endif; ?>

						<?php $this->render_subject_item_media_sections( $item_view['attachments'], $item_view['embed_urls'] ); ?>

						<?php if ( 'assignments' === $tab && ! empty( $item_view['can_submit_assignment'] ) ) : ?>
							<?php $this->render_assignment_submission_panel( $subject_id, $item_view, true ); ?>
						<?php endif; ?>

						<?php if ( 'discussions' === $tab ) : ?>
							<section class="slms-subject-detail-section slms-subject-discussion-section">
								<div class="slms-section-heading">
									<div>
										<h3><?php esc_html_e( 'Discussion Replies', 'simple-lms' ); ?></h3>
									</div>
								</div>
								<?php $this->render_thread_replies( $post->ID ); ?>
								<form method="post" action="" enctype="multipart/form-data" class="slms-form-stack slms-subject-reply-form">
									<?php wp_nonce_field( 'slms_portal_action', 'slms_portal_nonce' ); ?>
									<input type="hidden" name="slms_portal_action" value="reply_thread">
									<input type="hidden" name="thread_id" value="<?php echo esc_attr( $post->ID ); ?>">
									<input type="hidden" name="subject_id" value="<?php echo esc_attr( $subject_id ); ?>">
									<input type="hidden" name="parent_comment_id" value="0">
									<?php $this->render_discussion_reply_editor_field( __( 'Reply', 'simple-lms' ), '', 4 ); ?>
									<label><span><?php esc_html_e( 'Attach File', 'simple-lms' ); ?></span><input type="file" name="reply_file"></label>
									<p class="slms-field-help"><?php esc_html_e( 'Reply attachments can be up to 100MB.', 'simple-lms' ); ?></p>
									<button type="submit" class="slms-portal-button is-secondary"><?php esc_html_e( 'Publish Reply', 'simple-lms' ); ?></button>
								</form>
							</section>
						<?php endif; ?>

						<?php if ( 'assignments' === $tab && $this->can_teach_subject( $subject_id, $role ) ) : ?>
							<?php $this->render_assignment_grading_panel( $subject_id, $item_view ); ?>
						<?php endif; ?>
					</article>
				</div>
			</div>
		</details>
		<?php
	}

	private function get_subject_item_meta_bits( $role, array $item_view ) {
		$post      = $item_view['post'];
		$meta_bits = array();

		if ( 'assignments' === $item_view['tab'] ) {
			return $this->get_assignment_display_meta_bits( $item_view, false );
		}

		$meta_bits[] = get_the_date( get_option( 'date_format' ), $post );

		if ( 'materials' === $item_view['tab'] && ! empty( $item_view['week_number'] ) && ! empty( $item_view['week_label'] ) ) {
			$meta_bits[] = $item_view['week_label'];
		}

		if ( ! empty( $item_view['is_welcome_thread'] ) ) {
			$meta_bits[] = __( 'Welcome thread', 'simple-lms' );
		}

		if ( in_array( $item_view['tab'], array( 'materials', 'discussions' ), true ) && ! empty( $item_view['can_manage'] ) && 'staff' === ( $item_view['visibility'] ?? 'students' ) ) {
			$meta_bits[] = __( 'Hidden from students', 'simple-lms' );
		}

		if ( 'discussions' === $item_view['tab'] ) {
			$meta_bits[] = sprintf( _n( '%d reply', '%d replies', (int) $item_view['reply_count'], 'simple-lms' ), (int) $item_view['reply_count'] );
		}

		return array_values( array_filter( $meta_bits ) );
	}

	private function get_assignment_display_meta_bits( array $item_view, $include_assets = false ) {
		$weight = isset( $item_view['weight'] ) ? (float) $item_view['weight'] : 0;
		$bits   = array(
			sprintf( __( 'Due: %s', 'simple-lms' ), $this->format_assignment_deadline_display( $item_view['due_at'] ?? '' ) ),
			sprintf( __( 'Points: %s', 'simple-lms' ), AssignmentPointPolicy::format_max_points( $item_view['points'] ?? 0 ) ),
			sprintf( __( 'Weight: %s', 'simple-lms' ), $weight > 0 ? number_format_i18n( $weight, 1 ) . '%' : __( 'Not weighted', 'simple-lms' ) ),
			sprintf( __( 'Submission: %s', 'simple-lms' ), $this->get_assignment_submission_type_label( $item_view['submission_type'] ?? 'text_file' ) ),
		);

		if ( $include_assets ) {
			$bits = array_merge(
				$bits,
				$this->summarize_subject_item_assets(
					is_array( $item_view['attachments'] ?? null ) ? $item_view['attachments'] : array(),
					is_array( $item_view['embed_urls'] ?? null ) ? $item_view['embed_urls'] : array()
				)
			);
		}

		return array_values( array_filter( $bits ) );
	}

	private function get_subject_item_summary_excerpt( array $item_view ) {
		$post = $item_view['post'] ?? null;

		if ( ! $post instanceof \WP_Post ) {
			return '';
		}

		$source = $post->post_excerpt ?: $post->post_content;
		$source = preg_replace( '/\s+/', ' ', trim( wp_strip_all_tags( (string) $source ) ) );

		if ( '' === $source ) {
			return '';
		}

		$excerpt = wp_trim_words( $source, 22, '...' );

		if ( '...' !== substr( $excerpt, -3 ) ) {
			$excerpt .= '...';
		}

		return $excerpt;
	}

	private function render_subject_item_view( $page_url, $role, array $subject_view, $active_tab, array $item_view ) {
		$subject    = $subject_view['subject'];
		$subject_id = (int) $subject['id'];
		$post       = $item_view['post'];
		$config     = $item_view['config'];
		?>
		<section class="slms-portal-panel slms-subject-item-window-shell">
			<div class="slms-subject-item-window-toolbar">
				<a class="slms-portal-button is-secondary" href="<?php echo esc_url( $this->subject_url( $page_url, $subject_id, $active_tab ) ); ?>"><?php echo esc_html( sprintf( __( 'Back to %s', 'simple-lms' ), $config['plural'] ) ); ?></a>
				<div class="slms-subject-item-window-badges">
					<span class="slms-portal-badge"><?php echo esc_html( $subject['subject_title'] ?: $subject['title'] ); ?></span>
					<?php if ( ! empty( $subject['section_code'] ) || ! empty( $subject['subject_code'] ) ) : ?>
						<span class="slms-portal-badge"><?php echo esc_html( $subject['section_code'] ?: $subject['subject_code'] ); ?></span>
					<?php endif; ?>
					<span class="slms-portal-badge"><?php echo esc_html( $config['singular'] ); ?></span>
				</div>
			</div>

			<div class="slms-subject-item-window-frame">
				<div class="slms-subject-detail-shell">
					<article class="slms-portal-panel slms-subject-detail-panel slms-subject-detail-main">
						<div class="slms-subject-detail-head">
							<span class="slms-subject-detail-kicker"><?php echo esc_html( $subject['term_name'] ?: __( 'Subject Workspace', 'simple-lms' ) ); ?></span>
							<h2><?php echo esc_html( get_the_title( $post ) ); ?></h2>
							<?php if ( 'assignments' === $active_tab ) : ?>
								<p class="slms-subject-detail-subhead"><?php echo esc_html( implode( ' | ', $this->get_assignment_display_meta_bits( $item_view, true ) ) ); ?></p>
							<?php else : ?>
								<p class="slms-subject-detail-subhead slms-subject-detail-subhead--author">
									<span><?php echo esc_html( sprintf( __( 'Published %s by', 'simple-lms' ), $item_view['published_at'] ) ); ?></span>
									<?php echo $this->get_person_name_with_auditor_tag_markup( $this->get_person_icon_name_markup( $item_view['author_name'], 'is-compact' ), ! empty( $item_view['author_is_auditor'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								</p>
							<?php endif; ?>
						</div>

						<?php if ( ! empty( $item_view['excerpt'] ) ) : ?>
							<p class="slms-subject-detail-lead"><?php echo esc_html( $item_view['excerpt'] ); ?></p>
						<?php endif; ?>

						<?php if ( ! empty( $post->post_content ) ) : ?>
							<div class="slms-rich-content">
								<?php echo wpautop( wp_kses_post( $post->post_content ) ); ?>
							</div>
						<?php endif; ?>

						<?php $this->render_subject_item_media_sections( $item_view['attachments'], $item_view['embed_urls'] ); ?>

						<?php if ( 'assignments' === $active_tab && ! empty( $item_view['can_submit_assignment'] ) ) : ?>
							<?php $this->render_assignment_submission_panel( $subject_id, $item_view, true ); ?>
						<?php endif; ?>

						<?php if ( 'discussions' === $active_tab ) : ?>
							<section class="slms-subject-detail-section">
								<div class="slms-section-heading">
									<div>
										<h3><?php esc_html_e( 'Discussion Replies', 'simple-lms' ); ?></h3>
									</div>
								</div>
								<?php $this->render_thread_replies( $post->ID ); ?>
								<form method="post" action="" enctype="multipart/form-data" class="slms-form-stack slms-subject-reply-form">
									<?php wp_nonce_field( 'slms_portal_action', 'slms_portal_nonce' ); ?>
									<input type="hidden" name="slms_portal_action" value="reply_thread">
									<input type="hidden" name="thread_id" value="<?php echo esc_attr( $post->ID ); ?>">
									<input type="hidden" name="subject_id" value="<?php echo esc_attr( $subject_id ); ?>">
									<input type="hidden" name="parent_comment_id" value="0">
									<?php $this->render_discussion_reply_editor_field( __( 'Reply', 'simple-lms' ), '', 4 ); ?>
									<label><span><?php esc_html_e( 'Attach File', 'simple-lms' ); ?></span><input type="file" name="reply_file"></label>
									<p class="slms-field-help"><?php esc_html_e( 'Reply attachments can be up to 100MB.', 'simple-lms' ); ?></p>
									<button type="submit" class="slms-portal-button is-secondary"><?php esc_html_e( 'Publish Reply', 'simple-lms' ); ?></button>
								</form>
							</section>
						<?php endif; ?>
					</article>

					<aside class="slms-subject-detail-sidebar">
						<?php $this->render_subject_item_meta_cards( $item_view ); ?>
						<?php if ( $item_view['can_manage'] ) : ?>
							<?php $this->render_subject_item_manage_panel( $subject_id, $active_tab, $item_view ); ?>
						<?php endif; ?>
					</aside>
				</div>
			</div>
		</section>

		<?php $this->render_subject_media_viewer(); ?>
		<?php
	}

	private function render_subject_item_card( $page_url, $subject_id, $tab, $item_id, $title, $excerpt, array $meta_bits, array $attachments, array $embed_urls = array() ) {
		$meta_bits = array_values( array_filter( array_merge( $meta_bits, $this->summarize_subject_item_assets( $attachments, $embed_urls ) ) ) );

		$meta_bits = array_values( array_filter( array_map( 'trim', $meta_bits ) ) );
		$excerpt   = wp_trim_words( wp_strip_all_tags( (string) $excerpt ), 34 );
		?>
		<a class="slms-subject-item-card" href="<?php echo esc_url( $this->subject_item_url( $page_url, $subject_id, $tab, $item_id ) ); ?>">
			<div class="slms-subject-item-card-head">
				<strong><?php echo esc_html( $title ); ?></strong>
				<span class="slms-subject-item-card-action"><?php esc_html_e( 'Open', 'simple-lms' ); ?></span>
			</div>
			<?php if ( $excerpt ) : ?>
				<p class="slms-subject-item-card-summary"><?php echo esc_html( $excerpt ); ?></p>
			<?php endif; ?>
			<?php if ( $meta_bits ) : ?>
				<div class="slms-subject-item-card-meta">
					<?php foreach ( $meta_bits as $meta_bit ) : ?>
						<span><?php echo esc_html( $meta_bit ); ?></span>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</a>
		<?php
	}

	private function render_subject_item_meta_cards( array $item_view ) {
		$post  = $item_view['post'];
		$facts = array(
			__( 'Type', 'simple-lms' )        => $item_view['config']['singular'],
			__( 'Published', 'simple-lms' )   => $item_view['published_at'],
			__( 'Author', 'simple-lms' )      => $item_view['author_name'],
			__( 'Status', 'simple-lms' )      => ucfirst( (string) $post->post_status ),
			__( 'Attachments', 'simple-lms' ) => sprintf( _n( '%d file', '%d files', count( $item_view['attachments'] ), 'simple-lms' ), count( $item_view['attachments'] ) ),
		);

		if ( in_array( $item_view['tab'], array( 'materials', 'assignments' ), true ) ) {
			$facts[ __( 'Week', 'simple-lms' ) ] = $item_view['week_label'] ?: __( 'General', 'simple-lms' );
		}

		if ( in_array( $item_view['tab'], array( 'materials', 'assignments' ), true ) && ! empty( $item_view['week_order'] ) ) {
			$facts[ __( 'Order In Week', 'simple-lms' ) ] = number_format_i18n( (int) $item_view['week_order'], 0 );
		}

		if ( 'assignments' === $item_view['tab'] ) {
			$facts[ __( 'Due', 'simple-lms' ) ]        = $this->format_assignment_deadline_display( $item_view['due_at'] ?? '' );
			$facts[ __( 'Points', 'simple-lms' ) ]     = AssignmentPointPolicy::format_max_points( $item_view['points'] );
			$facts[ __( 'Weight', 'simple-lms' ) ]     = $item_view['weight'] ? number_format_i18n( (float) $item_view['weight'], 1 ) . '%' : __( 'Not weighted', 'simple-lms' );
			$facts[ __( 'Submission', 'simple-lms' ) ] = $this->get_assignment_submission_type_label( $item_view['submission_type'] ?? 'text_file' );
		}

		if ( in_array( $item_view['tab'], array( 'materials', 'assignments', 'discussions' ), true ) && ! empty( $item_view['can_manage'] ) ) {
			$facts[ __( 'Visibility', 'simple-lms' ) ] = 'staff' === ( $item_view['visibility'] ?? 'students' ) ? __( 'Hidden from students', 'simple-lms' ) : __( 'Visible to students', 'simple-lms' );
		}

		if ( 'discussions' === $item_view['tab'] ) {
			$facts[ __( 'Replies', 'simple-lms' ) ] = sprintf( _n( '%d reply', '%d replies', (int) $item_view['reply_count'], 'simple-lms' ), (int) $item_view['reply_count'] );
		}
		?>
		<section class="slms-portal-panel slms-subject-side-card">
			<h3><?php esc_html_e( 'Overview', 'simple-lms' ); ?></h3>
			<dl class="slms-subject-meta-list">
				<?php foreach ( array_filter( $facts ) as $label => $value ) : ?>
					<div>
						<dt><?php echo esc_html( $label ); ?></dt>
						<dd><?php echo esc_html( $value ); ?></dd>
					</div>
				<?php endforeach; ?>
			</dl>
		</section>
		<?php
	}


	private function render_assignment_submission_panel( $subject_id, array $item_view, $inline = false ) {
		$submission       = $item_view['submission'];
		$has_submission   = ! empty( $submission );
		$can_edit         = $this->can_student_edit_assignment_submission( $item_view, $submission );
		$lock_message     = $this->get_assignment_submission_lock_message( $item_view, $submission );
		$deadline_passed  = $this->is_assignment_deadline_passed( $item_view['due_at'] ?? '' );
		$submission_time  = ! empty( $submission['updated_at'] ) ? AcademicClock::format_local( $submission['updated_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) : '';
		$score_label      = isset( $submission['score'] ) && null !== $submission['score'] ? number_format_i18n( (float) $submission['score'], 2 ) : '';
		$submission_type  = $this->normalize_assignment_submission_type( $item_view['submission_type'] ?? 'text_file', 'text_file' );
		$allows_text      = $this->assignment_submission_allows_text( $submission_type );
		$allows_file      = $this->assignment_submission_allows_file( $submission_type );
		$requires_text    = 'text' === $submission_type;
		$requires_file    = 'file' === $submission_type;
		$submission_text  = (string) ( $submission['submission_text'] ?? '' );
		$submission_title = $has_submission ? $this->get_assignment_submission_summary_title( $submission ) : '';
		$is_audit_submission = $has_submission && ( ! empty( $submission['is_audit_submission'] ) || 'auditor' === (string) ( $submission['submitter_type'] ?? '' ) );
		$section_class    = $inline ? 'slms-subject-detail-section slms-subject-submission-section' : 'slms-portal-panel slms-subject-side-card';
		?>
		<section class="<?php echo esc_attr( $section_class ); ?>">
			<div class="slms-submission-panel-heading">
				<h3><?php esc_html_e( 'Your Submission', 'simple-lms' ); ?></h3>
				<span class="slms-portal-badge"><?php echo esc_html( $this->get_assignment_submission_type_label( $submission_type ) ); ?></span>
				<?php if ( $is_audit_submission ) : ?>
					<span class="slms-portal-badge is-accent"><?php esc_html_e( 'Auditor', 'simple-lms' ); ?></span>
				<?php endif; ?>
			</div>
			<p class="slms-field-help"><?php echo esc_html( $this->get_assignment_submission_requirement_message( $submission_type ) ); ?></p>
			<?php if ( $has_submission ) : ?>
				<details class="slms-submission-inline-accordion">
					<summary class="slms-submission-inline-summary">
						<div class="slms-submission-inline-summary-copy">
							<strong><?php echo esc_html( $submission_title ?: __( 'Submitted Work', 'simple-lms' ) ); ?></strong>
							<div class="slms-submission-inline-summary-meta">
								<?php if ( $is_audit_submission ) : ?>
									<span><?php esc_html_e( 'Auditor', 'simple-lms' ); ?></span>
								<?php endif; ?>
								<span><?php echo esc_html( ucwords( str_replace( '_', ' ', (string) ( $submission['display_status'] ?? $submission['status'] ?? 'submitted' ) ) ) ); ?></span>
								<?php if ( $submission_time ) : ?>
									<span><?php echo esc_html( $submission_time ); ?></span>
								<?php endif; ?>
								<?php if ( ! empty( $submission['attachment_name'] ) ) : ?>
									<span><?php echo esc_html( $submission['attachment_name'] ); ?></span>
								<?php endif; ?>
							</div>
						</div>
						<span class="slms-subject-item-card-action">
							<span class="slms-subject-item-card-action-view">
								<span class="slms-subject-item-card-action-eye" aria-hidden="true"><?php echo $this->get_portal_icon_markup( 'eye' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
								<span><?php esc_html_e( 'View', 'simple-lms' ); ?></span>
							</span>
							<span class="slms-subject-item-card-action-minus" aria-hidden="true">-</span>
						</span>
					</summary>
					<div class="slms-submission-inline-body">
						<div class="slms-submission-detail-grid">
							<div class="slms-submission-detail-item">
								<span><?php esc_html_e( 'Submission Type', 'simple-lms' ); ?></span>
								<strong><?php echo esc_html( $this->get_assignment_submission_type_label( $submission_type ) ); ?></strong>
							</div>
							<?php if ( $is_audit_submission ) : ?>
								<div class="slms-submission-detail-item">
									<span><?php esc_html_e( 'Role', 'simple-lms' ); ?></span>
									<strong><?php esc_html_e( 'Auditor', 'simple-lms' ); ?></strong>
								</div>
							<?php endif; ?>
							<div class="slms-submission-detail-item">
								<span><?php esc_html_e( 'Status', 'simple-lms' ); ?></span>
								<strong><?php echo esc_html( ucwords( str_replace( '_', ' ', (string) ( $submission['display_status'] ?? $submission['status'] ?? 'submitted' ) ) ) ); ?></strong>
							</div>
							<?php if ( $score_label ) : ?>
								<div class="slms-submission-detail-item">
									<span><?php esc_html_e( 'Grade', 'simple-lms' ); ?></span>
									<strong><?php echo esc_html( sprintf( __( '%1$s / %2$s', 'simple-lms' ), $score_label, AssignmentPointPolicy::format_max_points( $item_view['points'] ) ) ); ?></strong>
								</div>
							<?php endif; ?>
							<?php if ( $submission_time ) : ?>
								<div class="slms-submission-detail-item">
									<span><?php esc_html_e( 'Submitted', 'simple-lms' ); ?></span>
									<strong><?php echo esc_html( $submission_time ); ?></strong>
								</div>
							<?php endif; ?>
						<?php if ( $has_submission ) : ?>
							<?php $submission_late_details = AssignmentLatePolicy::get_adjustment_details( $item_view, $submission ); ?>
							<?php if ( ! empty( $submission_late_details['is_late'] ) ) : ?>
								<div class="slms-submission-detail-item">
									<span><?php esc_html_e( 'Late Penalty', 'simple-lms' ); ?></span>
									<strong><?php echo esc_html( $submission_late_details['policy_short_label'] ); ?></strong>
								</div>
							<?php endif; ?>
						<?php endif; ?>
						</div>
						<?php if ( '' !== trim( wp_strip_all_tags( $submission_text ) ) ) : ?>
							<div class="slms-submission-text-response">
								<strong><?php echo esc_html( $allows_file && ! $allows_text ? __( 'Submission Note', 'simple-lms' ) : __( 'Typed Answer', 'simple-lms' ) ); ?></strong>
								<div class="slms-rich-content"><?php echo wpautop( wp_kses_post( $submission_text ) ); ?></div>
							</div>
						<?php endif; ?>
						<?php if ( ! empty( $submission['attachment_url'] ) ) : ?>
							<form method="post" action="" class="slms-inline-download-form">
								<?php wp_nonce_field( 'slms_portal_action', 'slms_portal_nonce' ); ?>
								<input type="hidden" name="slms_portal_action" value="download_assignment_submission">
								<input type="hidden" name="subject_id" value="<?php echo esc_attr( $subject_id ); ?>">
								<input type="hidden" name="assignment_id" value="<?php echo esc_attr( (int) $item_view['post']->ID ); ?>">
								<input type="hidden" name="student_user_id" value="<?php echo esc_attr( get_current_user_id() ); ?>">
								<input type="hidden" name="download_part" value="file">
								<button type="submit" class="slms-submission-download-link">
									<span><?php echo esc_html( $submission['attachment_name'] ?: __( 'Download submitted file', 'simple-lms' ) ); ?></span>
									<span class="slms-submission-download-icon" aria-hidden="true"><?php echo $this->get_portal_icon_markup( 'download' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
								</button>
							</form>
						<?php endif; ?>
						<?php if ( ! empty( $submission['feedback'] ) ) : ?>
							<div class="slms-subject-feedback-note">
								<strong><?php esc_html_e( 'Lecturer Feedback', 'simple-lms' ); ?></strong>
								<div><?php echo wpautop( wp_kses_post( $submission['feedback'] ) ); ?></div>
							</div>
						<?php endif; ?>
					</div>
				</details>
			<?php else : ?>
				<p class="slms-field-help"><?php esc_html_e( 'No submission has been sent yet.', 'simple-lms' ); ?></p>
			<?php endif; ?>

			<?php if ( ! $has_submission || $can_edit ) : ?>
				<details class="slms-submission-editor">
					<summary class="slms-submission-editor-toggle slms-assignment-open-submit-button">
						<span class="slms-submission-editor-toggle-icon" aria-hidden="true"><?php echo $this->get_portal_icon_markup( 'assignment' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<span class="slms-submission-editor-toggle-copy">
							<strong><?php echo esc_html( $has_submission ? __( 'Edit Submission', 'simple-lms' ) : __( 'Submit Assignment', 'simple-lms' ) ); ?></strong>
							<small><?php echo esc_html( $has_submission ? __( 'Update your typed answer or replace your file.', 'simple-lms' ) : __( 'Open the submission options for this assignment.', 'simple-lms' ) ); ?></small>
						</span>
						<span class="slms-submission-editor-toggle-action"><?php esc_html_e( 'Open', 'simple-lms' ); ?></span>
					</summary>
					<div class="slms-submission-editor-body">
						<?php if ( $deadline_passed ) : ?>
							<div class="slms-submission-deadline-notice">
								<strong><?php esc_html_e( 'Late Submission Notice', 'simple-lms' ); ?></strong>
								<p><?php echo esc_html( $this->get_assignment_late_submission_notice( $item_view['due_at'] ?? '' ) ); ?></p>
							</div>
						<?php endif; ?>
						<form method="post" action="" enctype="multipart/form-data" class="slms-form-stack slms-assignment-submission-form">
							<?php wp_nonce_field( 'slms_portal_action', 'slms_portal_nonce' ); ?>
							<input type="hidden" name="slms_portal_action" value="submit_assignment">
							<input type="hidden" name="subject_id" value="<?php echo esc_attr( $subject_id ); ?>">
							<input type="hidden" name="assignment_id" value="<?php echo esc_attr( (int) $item_view['post']->ID ); ?>">
							<?php if ( $allows_text ) : ?>
								<?php $this->render_portal_rich_text_editor_field( 'submission_text', __( 'Your Answer', 'simple-lms' ), $submission_text, 16, __( 'Type your answer here...', 'simple-lms' ), '', $requires_text ); ?>
							<?php else : ?>
								<?php $this->render_portal_rich_text_editor_field( 'submission_text', __( 'Optional Note', 'simple-lms' ), $submission_text, 6, __( 'Add an optional note for your lecturer...', 'simple-lms' ), '', false ); ?>
							<?php endif; ?>
							<?php if ( $allows_file ) : ?>
								<label><span><?php echo esc_html( $has_submission ? __( 'Replace File', 'simple-lms' ) : __( 'Attach File', 'simple-lms' ) ); ?></span><input type="file" name="submission_file" <?php echo ( $requires_file && ! $has_submission ) ? 'required' : ''; ?>></label>
								<?php if ( $has_submission && ! empty( $submission['attachment_name'] ) ) : ?>
									<p class="slms-field-help"><?php echo esc_html( sprintf( __( 'Current file: %s', 'simple-lms' ), $submission['attachment_name'] ) ); ?></p>
								<?php endif; ?>
								<p class="slms-field-help"><?php esc_html_e( 'Submission uploads can be up to 100MB. A new uploaded file will replace the previous one automatically.', 'simple-lms' ); ?></p>
							<?php endif; ?>
							<button type="submit" class="slms-portal-button slms-assignment-submit-button"><?php esc_html_e( 'Submit', 'simple-lms' ); ?></button>
						</form>
					</div>
				</details>
			<?php elseif ( $lock_message ) : ?>
				<p class="slms-field-help"><?php echo esc_html( $lock_message ); ?></p>
			<?php endif; ?>
			<div class="slms-assignment-late-policy-card" aria-label="<?php esc_attr_e( 'Late submission policy', 'simple-lms' ); ?>">
				<strong><?php esc_html_e( 'Late Submission Policy', 'simple-lms' ); ?></strong>
				<p><?php echo esc_html( $this->get_assignment_late_submission_notice( $item_view['due_at'] ?? '' ) ); ?></p>
			</div>
		</section>
		<?php
	}

	private function render_subject_item_inline_editor( $subject_id, $tab, array $item_view ) {
		$config      = $item_view['config'];
		$post        = $item_view['post'];
		$action_root = $config['action_root'];
		?>
		<details class="slms-subject-inline-editor">
			<summary class="slms-portal-button is-secondary slms-subject-inline-editor-toggle"><?php echo esc_html( sprintf( __( 'Edit %s', 'simple-lms' ), $config['singular'] ) ); ?></summary>
			<div class="slms-subject-inline-editor-body">
				<form method="post" action="" enctype="multipart/form-data" class="slms-form-stack">
					<?php wp_nonce_field( 'slms_portal_action', 'slms_portal_nonce' ); ?>
					<input type="hidden" name="slms_portal_action" value="<?php echo esc_attr( 'update_' . $action_root ); ?>">
					<input type="hidden" name="subject_id" value="<?php echo esc_attr( $subject_id ); ?>">
					<input type="hidden" name="item_id" value="<?php echo esc_attr( (string) $post->ID ); ?>">
					<label><span><?php esc_html_e( 'Title', 'simple-lms' ); ?></span><input type="text" name="title" value="<?php echo esc_attr( $post->post_title ); ?>" required></label>
					<?php $this->render_portal_rich_text_editor_field( 'content', $config['content_label'], $post->post_content, 6, __( 'Write the item content...', 'simple-lms' ), '', true ); ?>
					<?php if ( 'assignments' === $tab ) : ?>
						<div class="slms-form-split">
							<label><span><?php esc_html_e( 'Deadline', 'simple-lms' ); ?></span><input type="datetime-local" name="due_at" value="<?php echo esc_attr( AssignmentLatePolicy::format_datetime_local_value( $item_view['due_at'] ) ); ?>"></label>
							<label><span><?php esc_html_e( 'Weight (%)', 'simple-lms' ); ?></span><input type="number" name="weight" step="0.1" min="0" value="<?php echo esc_attr( (string) $item_view['weight'] ); ?>"></label>
						</div>
						<label><span><?php esc_html_e( 'Points', 'simple-lms' ); ?></span><input type="number" name="points" step="1" min="0" value="<?php echo esc_attr( (string) AssignmentPointPolicy::normalize_max_points( $item_view['points'] ) ); ?>"></label>
						<?php $this->render_subject_embed_url_repeater( $config['embed_label'], $item_view['embed_urls'] ); ?>
						<?php $this->render_assignment_submission_type_field( $item_view['submission_type'] ?? 'text_file' ); ?>
					<?php else : ?>
						<?php $this->render_subject_embed_url_repeater( $config['embed_label'], $item_view['embed_urls'] ); ?>
					<?php endif; ?>
					<?php if ( in_array( $tab, array( 'materials', 'assignments' ), true ) ) : ?>
						<?php $this->render_subject_item_week_fields( $subject_id, (int) ( $item_view['week_number'] ?? 0 ), (int) ( $item_view['week_order'] ?? 0 ) ); ?>
					<?php endif; ?>
					<?php $this->render_subject_item_attachment_controls( $subject_id, $tab, (int) $post->ID, $item_view['attachments'] ); ?>
					<?php $this->render_subject_file_upload_repeater( $config['upload_field'], __( 'Add More Files', 'simple-lms' ) ); ?>
					<p class="slms-field-help"><?php esc_html_e( 'New uploads are added to the current item. Each file can be up to 100MB.', 'simple-lms' ); ?></p>
					<div class="slms-inline-actions">
						<button type="submit" class="slms-portal-button"><?php esc_html_e( 'Save Changes', 'simple-lms' ); ?></button>
					</div>
				</form>

				<form method="post" action="" class="slms-form-stack slms-danger-form" onsubmit="return window.confirm('<?php echo esc_js( __( 'Delete this item and permanently remove its uploaded files?', 'simple-lms' ) ); ?>');">
					<?php wp_nonce_field( 'slms_portal_action', 'slms_portal_nonce' ); ?>
					<input type="hidden" name="slms_portal_action" value="<?php echo esc_attr( 'delete_' . $action_root ); ?>">
					<input type="hidden" name="subject_id" value="<?php echo esc_attr( $subject_id ); ?>">
					<input type="hidden" name="item_id" value="<?php echo esc_attr( (string) $post->ID ); ?>">
					<button type="submit" class="slms-portal-button is-secondary"><?php echo esc_html( 'materials' === $tab ? __( 'Delete Material Permanently', 'simple-lms' ) : sprintf( __( 'Delete %s', 'simple-lms' ), $config['singular'] ) ); ?></button>
				</form>
			</div>
		</details>
		<?php
	}


	private function render_assignment_grading_panel( $subject_id, array $item_view ) {
		$assignment_id           = (int) $item_view['post']->ID;
		$submissions             = $this->assessments->list_submissions( $assignment_id );
		$submission_type         = $this->normalize_assignment_submission_type( $item_view['submission_type'] ?? 'text_file', 'text_file' );
		$can_use_batch_download = $this->can_use_assignment_batch_download();
		?>
		<section class="slms-subject-detail-section slms-assignment-submissions-panel">
			<div class="slms-section-heading">
				<div>
					<h3><?php esc_html_e( 'Submissions', 'simple-lms' ); ?></h3>
					<p class="slms-field-help"><?php esc_html_e( 'Review student and auditor work, download submissions, then grade and leave feedback directly.', 'simple-lms' ); ?></p>
				</div>
				<span class="slms-portal-badge"><?php echo esc_html( sprintf( _n( '%d submission', '%d submissions', count( $submissions ), 'simple-lms' ), count( $submissions ) ) ); ?></span>
			</div>
			<div class="slms-assignment-review-toolbar">
				<span class="slms-portal-badge"><?php echo esc_html( $this->get_assignment_submission_type_label( $submission_type ) ); ?></span>
				<?php if ( ! empty( $submissions ) && $can_use_batch_download ) : ?>
					<form method="post" action="" class="slms-assignment-download-form slms-assignment-zip-form">
						<?php wp_nonce_field( 'slms_portal_action', 'slms_portal_nonce' ); ?>
						<input type="hidden" name="slms_portal_action" value="download_assignment_submissions_zip">
						<input type="hidden" name="subject_id" value="<?php echo esc_attr( $subject_id ); ?>">
						<input type="hidden" name="assignment_id" value="<?php echo esc_attr( (string) $assignment_id ); ?>">
						<button type="submit" class="slms-portal-button is-secondary slms-assignment-download-button">
							<span><?php esc_html_e( 'Download Batch ZIP', 'simple-lms' ); ?></span>
							<span class="slms-submission-download-icon" aria-hidden="true"><?php echo $this->get_portal_icon_markup( 'download' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						</button>
					</form>
				<?php endif; ?>
			</div>
			<?php if ( empty( $submissions ) ) : ?>
				<p class="slms-field-help"><?php esc_html_e( 'No submissions have been uploaded yet.', 'simple-lms' ); ?></p>
			<?php else : ?>
				<div class="slms-submission-review-stack">
					<?php foreach ( $submissions as $submission ) : ?>
						<?php
						$submission_text    = (string) ( $submission['submission_text'] ?? '' );
						$has_text           = '' !== trim( wp_strip_all_tags( $submission_text ) );
						$has_file           = ! empty( $submission['attachment_url'] );
						$submission_summary = $this->get_assignment_submission_summary_title( $submission, __( 'Submitted Work', 'simple-lms' ) );
						$is_audit_submission = ! empty( $submission['is_audit_submission'] ) || 'auditor' === (string) ( $submission['submitter_type'] ?? '' );
						$review_state       = $this->get_assignment_submission_review_state( $item_view, $submission );
						?>
						<details class="slms-submission-review-card <?php echo esc_attr( $review_state['card_class'] ); ?>" data-slms-submission-review-card data-slms-submission-state="<?php echo esc_attr( $review_state['state'] ); ?>">
							<summary class="slms-submission-review-summary">
								<div class="slms-submission-review-summary-copy">
									<div class="slms-submission-review-person-line">
										<strong><?php echo $this->get_person_avatar_name_markup( (int) ( $submission['student_user_id'] ?? 0 ), $submission['display_name'] ?? __( 'Student', 'simple-lms' ), 'is-compact' ); ?></strong>
										<?php if ( $is_audit_submission ) : ?>
											<?php echo $this->get_auditor_tag_markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
										<?php endif; ?>
									</div>
									<div class="slms-submission-inline-summary-meta">
										<span class="slms-submission-status-pill <?php echo esc_attr( $review_state['badge_class'] ); ?>" data-slms-submission-status-label><?php echo esc_html( $review_state['label'] ); ?></span>
										<?php if ( ! empty( $submission['updated_at'] ) ) : ?>
											<span><?php echo esc_html( AcademicClock::format_local( $submission['updated_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></span>
										<?php endif; ?>
										<span><?php echo esc_html( $has_file ? __( 'File attached', 'simple-lms' ) : __( 'Typed answer', 'simple-lms' ) ); ?></span>
										<?php if ( $submission_summary ) : ?>
											<span><?php echo esc_html( $submission_summary ); ?></span>
										<?php endif; ?>
									</div>
								</div>
								<span class="slms-subject-item-card-action">
									<span class="slms-subject-item-card-action-view">
										<span class="slms-subject-item-card-action-eye" aria-hidden="true"><?php echo $this->get_portal_icon_markup( 'eye' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
										<span><?php esc_html_e( 'View', 'simple-lms' ); ?></span>
									</span>
									<span class="slms-subject-item-card-action-minus" aria-hidden="true">-</span>
								</span>
							</summary>
							<div class="slms-submission-review-body">
								<?php if ( $has_text ) : ?>
									<div class="slms-submission-text-response">
										<strong><?php esc_html_e( 'Typed Answer / Note', 'simple-lms' ); ?></strong>
										<div class="slms-rich-content"><?php echo wpautop( wp_kses_post( $submission_text ) ); ?></div>
									</div>
								<?php endif; ?>
								<div class="slms-submission-download-actions">
									<?php if ( $has_file ) : ?>
										<form method="post" action="" class="slms-assignment-download-form">
											<?php wp_nonce_field( 'slms_portal_action', 'slms_portal_nonce' ); ?>
											<input type="hidden" name="slms_portal_action" value="download_assignment_submission">
											<input type="hidden" name="subject_id" value="<?php echo esc_attr( $subject_id ); ?>">
											<input type="hidden" name="assignment_id" value="<?php echo esc_attr( (string) $assignment_id ); ?>">
											<input type="hidden" name="student_user_id" value="<?php echo esc_attr( (string) $submission['student_user_id'] ); ?>">
											<input type="hidden" name="download_part" value="file">
											<button type="submit" class="slms-portal-button is-secondary slms-assignment-download-button">
												<span><?php echo esc_html( $submission['attachment_name'] ?: __( 'Download File', 'simple-lms' ) ); ?></span>
												<span class="slms-submission-download-icon" aria-hidden="true"><?php echo $this->get_portal_icon_markup( 'download' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
											</button>
										</form>
									<?php endif; ?>
									<?php if ( $has_text ) : ?>
										<form method="post" action="" class="slms-assignment-download-form">
											<?php wp_nonce_field( 'slms_portal_action', 'slms_portal_nonce' ); ?>
											<input type="hidden" name="slms_portal_action" value="download_assignment_submission">
											<input type="hidden" name="subject_id" value="<?php echo esc_attr( $subject_id ); ?>">
											<input type="hidden" name="assignment_id" value="<?php echo esc_attr( (string) $assignment_id ); ?>">
											<input type="hidden" name="student_user_id" value="<?php echo esc_attr( (string) $submission['student_user_id'] ); ?>">
											<input type="hidden" name="download_part" value="text">
											<button type="submit" class="slms-portal-button is-secondary slms-assignment-download-button">
												<span><?php esc_html_e( 'Download Text Answer', 'simple-lms' ); ?></span>
												<span class="slms-submission-download-icon" aria-hidden="true"><?php echo $this->get_portal_icon_markup( 'download' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
											</button>
										</form>
									<?php endif; ?>
								</div>
								<form method="post" action="" class="slms-form-stack slms-assignment-grade-form" data-slms-assignment-grade-form>
									<?php wp_nonce_field( 'slms_portal_action', 'slms_portal_nonce' ); ?>
									<input type="hidden" name="action" value="slms_grade_assignment_submission">
									<input type="hidden" name="slms_portal_action" value="grade_assignment_submission">
									<input type="hidden" name="subject_id" value="<?php echo esc_attr( $subject_id ); ?>">
									<input type="hidden" name="assignment_id" value="<?php echo esc_attr( (string) $assignment_id ); ?>">
									<input type="hidden" name="student_user_id" value="<?php echo esc_attr( (string) $submission['student_user_id'] ); ?>">
									<div class="slms-form-split slms-assignment-grade-grid">
										<label class="slms-grade-score-field">
											<span><?php echo esc_html( sprintf( __( 'Score / %s', 'simple-lms' ), AssignmentPointPolicy::format_max_points( $item_view['points'] ) ) ); ?></span>
											<input type="number" name="score" min="0" max="<?php echo esc_attr( (string) $item_view['points'] ); ?>" step="0.1" value="<?php echo esc_attr( null !== $submission['score'] ? (string) $submission['score'] : '' ); ?>">
										</label>
									</div>
									<label>
										<span><?php esc_html_e( 'Individual Feedback', 'simple-lms' ); ?></span>
										<?php $this->render_portal_rich_text_editor_field( 'feedback', __( 'Feedback', 'simple-lms' ), $submission['feedback'] ?? '', 8, $is_audit_submission ? __( 'Write feedback for the auditor...', 'simple-lms' ) : __( 'Write feedback for the student...', 'simple-lms' ), '', false ); ?>
									</label>
									<div class="slms-assignment-grade-message" data-slms-assignment-grade-message hidden></div>
									<button type="submit" class="slms-portal-button slms-assignment-grade-button" data-slms-assignment-grade-button data-saving-label="<?php esc_attr_e( 'Saving...', 'simple-lms' ); ?>"><?php esc_html_e( 'Save Feedback & Grade', 'simple-lms' ); ?></button>
								</form>
							</div>
						</details>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}

	private function get_assignment_submission_review_state( array $assignment, array $submission ) {
		$status      = sanitize_key( (string) ( $submission['status'] ?? 'submitted' ) );
		$score_value = $submission['score'] ?? null;
		$is_graded   = 'graded' === $status && null !== $score_value && '' !== (string) $score_value;
		$late_details = AssignmentLatePolicy::get_adjustment_details( $assignment, $submission );
		$is_late     = ! empty( $late_details['is_late'] );

		if ( $is_graded ) {
			return array(
				'state'       => $is_late ? 'graded-late' : 'graded',
				'label'       => $is_late ? __( 'Graded Late', 'simple-lms' ) : __( 'Graded', 'simple-lms' ),
				'card_class'  => 'slms-submission-review-card--graded',
				'badge_class' => 'is-graded',
			);
		}

		if ( $is_late ) {
			return array(
				'state'       => 'late-ungraded',
				'label'       => __( 'Late / Ungraded', 'simple-lms' ),
				'card_class'  => 'slms-submission-review-card--late',
				'badge_class' => 'is-late',
			);
		}

		if ( 'resubmitted' === $status ) {
			$label = __( 'Resubmitted / Ungraded', 'simple-lms' );
		} else {
			$label = __( 'Ungraded', 'simple-lms' );
		}

		return array(
			'state'       => 'ungraded',
			'label'       => $label,
			'card_class'  => 'slms-submission-review-card--ungraded',
			'badge_class' => 'is-ungraded',
		);
	}

	private function get_assignment_grade_response_payload( $assignment_id, $student_user_id, $viewer_user_id, $message = '' ) {
		$assignment_id   = absint( $assignment_id );
		$student_user_id = absint( $student_user_id );
		$viewer_user_id  = absint( $viewer_user_id );
		$assignment      = $this->assessments->get_assignment( $assignment_id, $viewer_user_id );
		$submission      = $this->assessments->get_submission( $assignment_id, $student_user_id );

		if ( ! $assignment || ! is_array( $submission ) ) {
			return array(
				'message' => $message ?: __( 'Submission saved.', 'simple-lms' ),
			);
		}

		$state = $this->get_assignment_submission_review_state( $assignment, $submission );

		return array(
			'message'          => $message ?: __( 'Submission feedback saved successfully.', 'simple-lms' ),
			'submission_id'    => (int) ( $submission['id'] ?? 0 ),
			'assignment_id'    => $assignment_id,
			'student_user_id'  => $student_user_id,
			'status'           => sanitize_key( (string) ( $submission['status'] ?? '' ) ),
			'display_status'   => $state['label'],
			'card_class'       => $state['card_class'],
			'badge_class'      => $state['badge_class'],
			'state'            => $state['state'],
			'score'            => null !== ( $submission['score'] ?? null ) ? (float) $submission['score'] : null,
			'feedback'         => (string) ( $submission['feedback'] ?? '' ),
			'graded_at'        => (string) ( $submission['graded_at'] ?? '' ),
		);
	}


	private function render_subject_item_manage_panel( $subject_id, $tab, array $item_view ) {
		$config      = $item_view['config'];
		$post        = $item_view['post'];
		$action_root = $config['action_root'];
		?>
		<section class="slms-portal-panel slms-subject-side-card slms-subject-manage-card">
			<h3><?php echo esc_html( sprintf( __( 'Manage %s', 'simple-lms' ), strtolower( $config['singular'] ) ) ); ?></h3>
			<form method="post" action="" enctype="multipart/form-data" class="slms-form-stack">
				<?php wp_nonce_field( 'slms_portal_action', 'slms_portal_nonce' ); ?>
				<input type="hidden" name="slms_portal_action" value="<?php echo esc_attr( 'update_' . $action_root ); ?>">
				<input type="hidden" name="subject_id" value="<?php echo esc_attr( $subject_id ); ?>">
				<input type="hidden" name="item_id" value="<?php echo esc_attr( (string) $post->ID ); ?>">
				<label><span><?php esc_html_e( 'Title', 'simple-lms' ); ?></span><input type="text" name="title" value="<?php echo esc_attr( $post->post_title ); ?>" required></label>
				<?php $this->render_portal_rich_text_editor_field( 'content', $config['content_label'], $post->post_content, 6, __( 'Write the item content...', 'simple-lms' ), '', true ); ?>
				<?php if ( 'assignments' === $tab ) : ?>
					<div class="slms-form-split">
						<label><span><?php esc_html_e( 'Deadline', 'simple-lms' ); ?></span><input type="datetime-local" name="due_at" value="<?php echo esc_attr( AssignmentLatePolicy::format_datetime_local_value( $item_view['due_at'] ) ); ?>"></label>
						<label><span><?php esc_html_e( 'Weight (%)', 'simple-lms' ); ?></span><input type="number" name="weight" step="0.1" min="0" value="<?php echo esc_attr( (string) $item_view['weight'] ); ?>"></label>
					</div>
					<label><span><?php esc_html_e( 'Points', 'simple-lms' ); ?></span><input type="number" name="points" step="1" min="0" value="<?php echo esc_attr( (string) AssignmentPointPolicy::normalize_max_points( $item_view['points'] ) ); ?>"></label>
					<?php $this->render_subject_embed_url_repeater( $config['embed_label'], $item_view['embed_urls'] ); ?>
					<?php $this->render_assignment_submission_type_field( $item_view['submission_type'] ?? 'text_file' ); ?>
				<?php else : ?>
					<?php $this->render_subject_embed_url_repeater( $config['embed_label'], $item_view['embed_urls'] ); ?>
				<?php endif; ?>
				<?php if ( in_array( $tab, array( 'materials', 'assignments' ), true ) ) : ?>
					<?php $this->render_subject_item_week_fields( $subject_id, (int) ( $item_view['week_number'] ?? 0 ), (int) ( $item_view['week_order'] ?? 0 ) ); ?>
				<?php endif; ?>
				<?php $this->render_subject_item_attachment_controls( $subject_id, $tab, (int) $post->ID, $item_view['attachments'] ); ?>
				<label><span><?php esc_html_e( 'Add More Files', 'simple-lms' ); ?></span><input type="file" name="<?php echo esc_attr( $config['upload_field'] ); ?>[]" multiple accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.png,.jpg,.jpeg,.gif,.webp,.mp3,.wav,.m4a,.ogg,.mp4,.mov,.webm"></label>
				<p class="slms-field-help"><?php esc_html_e( 'New uploads are added to the current item. Each file can be up to 100MB.', 'simple-lms' ); ?></p>
				<button type="submit" class="slms-portal-button"><?php esc_html_e( 'Save Changes', 'simple-lms' ); ?></button>
			</form>

			<form method="post" action="" class="slms-form-stack slms-danger-form" onsubmit="return window.confirm('<?php echo esc_js( __( 'Delete this item and permanently remove its uploaded files?', 'simple-lms' ) ); ?>');">
				<?php wp_nonce_field( 'slms_portal_action', 'slms_portal_nonce' ); ?>
				<input type="hidden" name="slms_portal_action" value="<?php echo esc_attr( 'delete_' . $action_root ); ?>">
				<input type="hidden" name="subject_id" value="<?php echo esc_attr( $subject_id ); ?>">
				<input type="hidden" name="item_id" value="<?php echo esc_attr( (string) $post->ID ); ?>">
				<button type="submit" class="slms-portal-button is-secondary"><?php echo esc_html( 'materials' === $tab ? __( 'Delete Material Permanently', 'simple-lms' ) : sprintf( __( 'Delete %s', 'simple-lms' ), $config['singular'] ) ); ?></button>
			</form>
		</section>
		<?php
	}

	private function render_subject_item_week_fields( $subject_id, $week_number = 0, $week_order = 0 ) {
		$week_number  = absint( $week_number );
		$week_order   = absint( $week_order );
		$defined_weeks = $this->get_subject_week_structure( $subject_id );
		$has_defined   = ! empty( $defined_weeks );
		$has_current   = false;

		if ( $has_defined ) {
			foreach ( $defined_weeks as $defined_week ) {
				if ( $week_number === (int) $defined_week['week_number'] ) {
					$has_current = true;
					break;
				}
			}
		}
		?>
		<div class="slms-form-split">
			<label>
				<span><?php echo esc_html( $has_defined ? __( 'Week', 'simple-lms' ) : __( 'Week Number', 'simple-lms' ) ); ?></span>
				<?php if ( $has_defined ) : ?>
					<select name="week_number">
						<option value="0"><?php esc_html_e( 'General', 'simple-lms' ); ?></option>
						<?php foreach ( $defined_weeks as $defined_week ) : ?>
							<option value="<?php echo esc_attr( (string) $defined_week['week_number'] ); ?>" <?php selected( $week_number, (int) $defined_week['week_number'] ); ?>>
								<?php echo esc_html( $defined_week['label'] ); ?>
							</option>
						<?php endforeach; ?>
						<?php if ( $week_number && ! $has_current ) : ?>
							<option value="<?php echo esc_attr( (string) $week_number ); ?>" selected>
								<?php echo esc_html( sprintf( __( 'Week %d', 'simple-lms' ), $week_number ) ); ?>
							</option>
						<?php endif; ?>
					</select>
				<?php else : ?>
					<input type="number" name="week_number" min="0" value="<?php echo esc_attr( $week_number ? (string) $week_number : '' ); ?>" placeholder="0">
				<?php endif; ?>
			</label>
			<label>
				<span><?php esc_html_e( 'Order In Week', 'simple-lms' ); ?></span>
				<input type="number" name="week_order" min="0" value="<?php echo esc_attr( $week_order ? (string) $week_order : '' ); ?>" placeholder="0">
			</label>
		</div>
		<p class="slms-field-help"><?php esc_html_e( 'Use 0 or leave the week blank to keep the item in General. Lower order values appear first within the same week.', 'simple-lms' ); ?></p>
		<?php
	}

	private function render_subject_item_attachment_controls( $subject_id, $tab, $item_id, array $attachments ) {
		if ( empty( $attachments ) ) {
			return;
		}
		?>
		<div class="slms-subject-attachment-controls">
			<span><?php esc_html_e( 'Current Files', 'simple-lms' ); ?></span>
			<div class="slms-subject-attachment-options">
				<?php foreach ( $attachments as $attachment ) : ?>
					<div class="slms-subject-attachment-option">
						<a class="slms-subject-attachment-link" href="<?php echo esc_url( $attachment['url'] ); ?>" target="_blank" rel="noopener">
							<span><?php echo esc_html( $attachment['name'] ); ?></span>
						</a>
						<button type="submit" class="slms-portal-button is-secondary slms-subject-attachment-delete" name="remove_attachment_tokens[]" value="<?php echo esc_attr( $attachment['token'] ); ?>" formnovalidate onclick="return window.confirm('<?php echo esc_js( __( 'Delete this file permanently?', 'simple-lms' ) ); ?>');">
							<?php esc_html_e( 'Delete File', 'simple-lms' ); ?>
						</button>
					</div>
				<?php endforeach; ?>
			</div>
			<p class="slms-field-help"><?php esc_html_e( 'Each file can be removed individually and is deleted permanently when you confirm.', 'simple-lms' ); ?></p>
		</div>
		<?php
	}

	private function render_subject_embed_url_repeater( $label, array $embed_urls = array() ) {
		$embed_urls = array_values( array_filter( array_map( 'strval', $embed_urls ) ) );

		if ( empty( $embed_urls ) ) {
			$embed_urls = array( '' );
		}
		?>
		<div class="slms-embed-url-repeater" data-slms-embed-url-repeater data-max-items="<?php echo esc_attr( (string) self::MAX_SUBJECT_EMBED_URLS ); ?>" data-remove-label="<?php esc_attr_e( 'Remove', 'simple-lms' ); ?>">
			<span class="slms-embed-url-repeater-label"><?php echo esc_html( $label ); ?></span>
			<div class="slms-embed-url-repeater-list" data-slms-embed-url-repeater-list>
				<?php foreach ( $embed_urls as $index => $embed_url ) : ?>
					<div class="slms-embed-url-repeater-row" data-slms-embed-url-row>
						<input type="url" name="embed_urls[]" value="<?php echo esc_attr( $embed_url ); ?>" placeholder="https://" data-slms-embed-url-input>
						<button type="button" class="slms-portal-button is-secondary slms-embed-url-repeater-remove" data-slms-embed-url-remove <?php echo 0 === $index ? 'hidden' : ''; ?>><?php esc_html_e( 'Remove', 'simple-lms' ); ?></button>
					</div>
				<?php endforeach; ?>
			</div>
			<button type="button" class="slms-portal-button is-secondary slms-embed-url-repeater-add" data-slms-embed-url-add>
				<span aria-hidden="true">+</span>
				<span><?php esc_html_e( 'Embed another video/audio', 'simple-lms' ); ?></span>
			</button>
			<p class="slms-field-help"><?php echo esc_html( sprintf( __( 'Add up to %d video or audio links. They will appear in this order.', 'simple-lms' ), self::MAX_SUBJECT_EMBED_URLS ) ); ?></p>
		</div>
		<?php
	}

	private function render_subject_file_upload_repeater( $field_name, $label ) {
		$field_name = sanitize_key( $field_name );
		$label      = (string) $label;

		if ( '' === $field_name ) {
			return;
		}

		$accept = '.pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.png,.jpg,.jpeg,.gif,.webp,.mp3,.wav,.m4a,.ogg,.mp4,.mov,.webm';
		?>
		<div class="slms-file-repeater" data-slms-file-repeater data-field-name="<?php echo esc_attr( $field_name ); ?>" data-accept="<?php echo esc_attr( $accept ); ?>">
			<span class="slms-file-repeater-label"><?php echo esc_html( $label ); ?></span>
			<div class="slms-file-repeater-list" data-slms-file-repeater-list>
				<label class="slms-file-repeater-row">
					<input type="file" name="<?php echo esc_attr( $field_name ); ?>[]" accept="<?php echo esc_attr( $accept ); ?>" data-slms-file-input>
				</label>
			</div>
			<a href="#" class="slms-file-repeater-add" role="button" data-slms-file-repeater-add hidden>
				<span class="slms-file-repeater-add-icon" aria-hidden="true">+</span>
				<span><?php esc_html_e( 'Add another file', 'simple-lms' ); ?></span>
			</a>
		</div>
		<?php
	}

	private function render_subject_item_media_sections( array $attachments, array $embed_urls ) {
		$images    = array();
		$audio     = array();
		$videos    = array();
		$documents = array();

		foreach ( $attachments as $attachment ) {
			if ( 'image' === $attachment['kind'] ) {
				$images[] = $attachment;
			} elseif ( 'audio' === $attachment['kind'] ) {
				$audio[] = $attachment;
			} elseif ( 'video' === $attachment['kind'] ) {
				$videos[] = $attachment;
			} else {
				$documents[] = $attachment;
			}
		}

		if ( $documents ) {
			?>
			<div class="slms-document-list slms-document-list--inline" aria-label="<?php esc_attr_e( 'Files', 'simple-lms' ); ?>">
				<?php foreach ( $documents as $document ) : ?>
					<div class="slms-document-row">
						<span class="slms-document-row-name"><?php echo esc_html( $document['name'] ); ?></span>
						<a class="slms-portal-button is-secondary slms-document-download-button" href="<?php echo esc_url( $document['url'] ); ?>" target="_blank" rel="noopener" download><?php esc_html_e( 'Download', 'simple-lms' ); ?></a>
					</div>
				<?php endforeach; ?>
			</div>
			<?php
		}

		if ( $embed_urls ) {
			?>
			<section class="slms-subject-detail-section slms-subject-media-block">
				<div class="slms-section-heading">
					<div>
						<h3><?php esc_html_e( 'Embedded Media', 'simple-lms' ); ?></h3>
						<p class="slms-field-help"><?php esc_html_e( 'Embedded media plays directly inside the expanded item.', 'simple-lms' ); ?></p>
					</div>
				</div>
				<div class="slms-embed-list">
					<?php foreach ( $embed_urls as $embed_url ) : ?>
						<?php $embed_html = $this->get_embed_markup( $embed_url ); ?>
						<div class="slms-embed-item">
							<?php if ( $embed_html ) : ?>
								<div class="slms-embed-frame">
									<?php echo $embed_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								</div>
							<?php endif; ?>
							<a class="slms-embed-source-link" href="<?php echo esc_url( $embed_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $embed_url ); ?></a>
						</div>
					<?php endforeach; ?>
				</div>
			</section>
			<?php
		}

		if ( $images ) {
			?>
			<div class="slms-media-gallery slms-media-gallery-inline">
				<?php foreach ( $images as $image ) : ?>
					<button type="button" class="slms-media-thumb slms-media-thumb-inline" data-slms-lightbox-image="<?php echo esc_url( $image['url'] ); ?>" data-slms-lightbox-alt="<?php echo esc_attr( $image['name'] ); ?>">
						<img src="<?php echo esc_url( $image['preview_url'] ?: $image['url'] ); ?>" alt="<?php echo esc_attr( $image['name'] ); ?>">
						<span><?php echo esc_html( $image['name'] ); ?></span>
					</button>
				<?php endforeach; ?>
			</div>
			<?php
		}

		if ( $videos ) {
			?>
			<section class="slms-subject-detail-section slms-subject-media-block">
				<div class="slms-section-heading">
					<div>
						<h3><?php esc_html_e( 'Video Files', 'simple-lms' ); ?></h3>
					</div>
				</div>
				<div class="slms-video-stack">
					<?php foreach ( $videos as $video ) : ?>
						<div class="slms-video-card">
							<video controls preload="metadata" playsinline src="<?php echo esc_url( $video['url'] ); ?>"></video>
							<strong><?php echo esc_html( $video['name'] ); ?></strong>
						</div>
					<?php endforeach; ?>
				</div>
			</section>
			<?php
		}

		if ( $audio ) {
			?>
			<section class="slms-subject-detail-section slms-subject-media-block">
				<div class="slms-section-heading">
					<div>
						<h3><?php esc_html_e( 'Audio Files', 'simple-lms' ); ?></h3>
						<p class="slms-field-help"><?php esc_html_e( 'Audio players support play, pause, seek, and playback speed changes.', 'simple-lms' ); ?></p>
					</div>
				</div>
				<div class="slms-audio-list">
					<?php foreach ( $audio as $track ) : ?>
						<div class="slms-audio-card" data-slms-audio-card>
							<div class="slms-audio-card-head">
								<strong><?php echo esc_html( $track['name'] ); ?></strong>
								<label>
									<span><?php esc_html_e( 'Speed', 'simple-lms' ); ?></span>
									<select data-slms-audio-rate>
										<option value="0.75">0.75x</option>
										<option value="1" selected>1x</option>
										<option value="1.25">1.25x</option>
										<option value="1.5">1.5x</option>
										<option value="2">2x</option>
									</select>
								</label>
							</div>
							<audio controls preload="metadata" src="<?php echo esc_url( $track['url'] ); ?>"></audio>
						</div>
					<?php endforeach; ?>
				</div>
			</section>
			<?php
		}

	}

	private function render_subject_media_viewer() {
		?>
		<div class="slms-image-viewer" id="slms-media-viewer" aria-hidden="true">
			<div class="slms-image-viewer-backdrop" data-slms-viewer-close></div>
			<div class="slms-image-viewer-shell">
				<button type="button" class="slms-image-viewer-close" data-slms-viewer-close aria-label="<?php esc_attr_e( 'Close image viewer', 'simple-lms' ); ?>">&times;</button>
				<img src="" alt="" data-slms-viewer-image>
				<p data-slms-viewer-caption hidden></p>
			</div>
		</div>
		<?php
	}

	private function get_subject_item_view( $user_id, $role, $subject_id, $tab, $item_id ) {
		$post   = $this->get_subject_item_post( $subject_id, $tab, $item_id );
		$config = $this->get_subject_item_config( $tab );

		if ( ! $post || empty( $config ) ) {
			return array();
		}

		if ( ! $this->enrollments->user_can_learn_section( $user_id, $subject_id ) ) {
			return array();
		}

		if ( ! $this->can_teach_subject( $subject_id, $role ) && ! in_array( $post->post_status, array( 'publish', 'private' ), true ) ) {
			return array();
		}

		if ( ! $this->can_teach_subject( $subject_id, $role ) && ! $this->is_subject_item_visible_to_students( $post->ID, $tab ) ) {
			return array();
		}

		$attachments = $this->get_subject_item_attachments( $post->ID, $tab );
		$embed_urls  = $this->get_subject_item_embed_urls( $post->ID, $config );
		$embed_url   = $embed_urls ? $embed_urls[0] : '';
		$visibility  = $this->get_subject_item_visibility( $post->ID, $tab );
		$week_data   = $this->get_subject_item_week_data( $subject_id, $post->ID, $tab );
		$item_view   = array(
			'tab'          => $tab,
			'post'         => $post,
			'config'       => $config,
			'attachments'  => $attachments,
			'embed_url'    => $embed_url,
			'embed_urls'   => $embed_urls,
			'excerpt'      => wp_trim_words( wp_strip_all_tags( $post->post_excerpt ?: $post->post_content ), 40 ),
			'published_at' => get_the_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $post ),
			'author_name'  => get_the_author_meta( 'display_name', (int) $post->post_author ) ?: __( 'Unknown', 'simple-lms' ),
			'author_id'    => (int) $post->post_author,
			'can_manage'   => $this->can_teach_subject( $subject_id, $role ),
			'visibility'   => $visibility,
			'week_number'  => $week_data['week_number'],
			'week_order'   => $week_data['week_order'],
			'week_label'   => $week_data['label'],
			'week_bucket'  => $week_data['bucket'],
			'is_welcome_thread' => 'discussions' === $tab && (bool) get_post_meta( $post->ID, '_slms_is_welcome_thread', true ),
		);

		if ( 'assignments' === $tab ) {
			$item_view['due_at']          = (string) get_post_meta( $post->ID, '_slms_due_at', true );
			$item_view['points']          = AssignmentPointPolicy::normalize_max_points( get_post_meta( $post->ID, '_slms_points', true ) );
			$item_view['weight']          = (float) get_post_meta( $post->ID, '_slms_assignment_weight', true );
			$item_view['submission_type'] = $this->normalize_assignment_submission_type( get_post_meta( $post->ID, '_slms_submission_type', true ), 'text_file' );
			$item_view['can_submit_assignment'] = $this->assessments->can_user_submit_assignment( $post->ID, $user_id );
				$item_view['submission']            = $item_view['can_submit_assignment'] ? $this->assessments->get_submission( $post->ID, $user_id ) : null;
		} elseif ( 'discussions' === $tab ) {
			$item_view['reply_count']       = get_comments_number( $post->ID );
			$item_view['author_is_auditor'] = $this->enrollments->user_can_audit_section( (int) $post->post_author, $subject_id );
		}

		return $item_view;
	}

	private function get_subject_item_config( $tab ) {
		$configs = array(
			'materials'   => array(
				'post_type'                     => 'slms_lesson',
				'subject_meta_key'              => '_slms_section_id',
				'attachment_meta_key'           => '_slms_material_attachment_ids',
				'managed_attachment_meta_key'   => '_slms_material_managed_attachments',
				'legacy_url_meta_key'           => '_slms_material_file_url',
				'legacy_name_meta_key'          => '_slms_material_file_name',
				'legacy_attachment_id_meta_key' => '',
				'embed_meta_key'                => '_slms_material_embed_url',
				'embed_urls_meta_key'           => '_slms_material_embed_urls',
				'singular'                      => __( 'Material', 'simple-lms' ),
				'plural'                        => __( 'Materials', 'simple-lms' ),
				'action_root'                   => 'material',
				'content_label'                 => __( 'Description', 'simple-lms' ),
				'embed_label'                   => __( 'Video / Audio Embed URLs', 'simple-lms' ),
				'upload_field'                  => 'material_files',
				'week_number_meta_key'          => '_slms_week_number',
				'week_order_meta_key'           => '_slms_week_order',
			),
			'assignments' => array(
				'post_type'                     => 'slms_assignment',
				'subject_meta_key'              => '_slms_section_id',
				'attachment_meta_key'           => '_slms_assignment_attachment_ids',
				'managed_attachment_meta_key'   => '_slms_assignment_managed_attachments',
				'legacy_url_meta_key'           => '_slms_assignment_file_url',
				'legacy_name_meta_key'          => '_slms_assignment_file_name',
				'legacy_attachment_id_meta_key' => '',
				'embed_meta_key'                => '_slms_assignment_embed_url',
				'embed_urls_meta_key'           => '_slms_assignment_embed_urls',
				'singular'                      => __( 'Assignment', 'simple-lms' ),
				'plural'                        => __( 'Assignments', 'simple-lms' ),
				'action_root'                   => 'assignment',
				'content_label'                 => __( 'Instructions', 'simple-lms' ),
				'embed_label'                   => __( 'Video / Audio Embed URLs', 'simple-lms' ),
				'upload_field'                  => 'assignment_files',
				'week_number_meta_key'          => '_slms_week_number',
				'week_order_meta_key'           => '_slms_week_order',
			),
			'discussions' => array(
				'post_type'                     => 'slms_discussion',
				'subject_meta_key'              => '_slms_subject_id',
				'attachment_meta_key'           => '_slms_thread_attachment_ids',
				'managed_attachment_meta_key'   => '_slms_thread_managed_attachments',
				'legacy_url_meta_key'           => '_slms_attachment_url',
				'legacy_name_meta_key'          => '',
				'legacy_attachment_id_meta_key' => '_slms_attachment_id',
				'embed_meta_key'                => '_slms_embed_url',
				'embed_urls_meta_key'           => '_slms_embed_urls',
				'singular'                      => __( 'Discussion', 'simple-lms' ),
				'plural'                        => __( 'Discussions', 'simple-lms' ),
				'action_root'                   => 'thread',
				'content_label'                 => __( 'Description', 'simple-lms' ),
				'embed_label'                   => __( 'Video / Audio Embed URLs', 'simple-lms' ),
				'upload_field'                  => 'thread_files',
				'week_number_meta_key'          => '',
				'week_order_meta_key'           => '',
			),
		);

		return $configs[ $tab ] ?? array();
	}

	private function get_subject_week_structure( $subject_id ) {
		static $cache = array();

		$subject_id = absint( $subject_id );

		if ( ! $subject_id ) {
			return array();
		}

		if ( isset( $cache[ $subject_id ] ) ) {
			return $cache[ $subject_id ];
		}

		$cache[ $subject_id ] = $this->normalize_subject_week_structure( get_post_meta( $subject_id, '_slms_week_structure', true ) );

		return $cache[ $subject_id ];
	}

	private function get_subject_item_week_data( $subject_id, $post_id, $tab ) {
		$config      = $this->get_subject_item_config( $tab );
		$is_welcome_thread = 'discussions' === $tab && (bool) get_post_meta( $post_id, '_slms_is_welcome_thread', true );
		$week_number = ! empty( $config['week_number_meta_key'] ) ? absint( get_post_meta( $post_id, $config['week_number_meta_key'], true ) ) : 0;
		$week_order  = ! empty( $config['week_order_meta_key'] ) ? absint( get_post_meta( $post_id, $config['week_order_meta_key'], true ) ) : 0;

		if ( $is_welcome_thread ) {
			$week_number = 0;
			$week_order  = 0;
		}

		$bucket      = $this->get_subject_week_bucket( $subject_id, $week_number );

		return array(
			'week_number' => $week_number,
			'week_order'  => $week_order,
			'label'       => $bucket['label'],
			'bucket'      => $bucket,
		);
	}

	private function get_subject_week_bucket( $subject_id, $week_number ) {
		$week_number = absint( $week_number );

		if ( ! $week_number ) {
			return $this->get_general_week_bucket();
		}

		foreach ( $this->get_subject_week_structure( $subject_id ) as $week ) {
			if ( $week_number === (int) $week['week_number'] ) {
				return array(
					'key'         => sanitize_key( $week['slug'] ?: 'week-' . $week_number ),
					'week_number' => $week_number,
					'label'       => $week['label'],
					'description' => $week['description'],
					'is_general'  => false,
					'is_defined'  => true,
				);
			}
		}

		return array(
			'key'         => 'week-' . $week_number,
			'week_number' => $week_number,
			'label'       => sprintf( __( 'Week %d', 'simple-lms' ), $week_number ),
			'description' => '',
			'is_general'  => false,
			'is_defined'  => false,
		);
	}

	private function get_general_week_bucket() {
		return array(
			'key'         => 'general',
			'week_number' => 0,
			'label'       => __( 'General', 'simple-lms' ),
			'description' => '',
			'is_general'  => true,
			'is_defined'  => true,
		);
	}

	private function normalize_subject_week_structure( $value ) {
		if ( empty( $value ) ) {
			return array();
		}

		if ( is_string( $value ) ) {
			$decoded = json_decode( $value, true );

			if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
				$value = $decoded;
			} else {
				$value = maybe_unserialize( $value );
			}
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$weeks           = array();
		$seen_numbers    = array();
		$fallback_number = 1;

		foreach ( $value as $row ) {
			$row = is_array( $row ) ? $row : array(
				'label' => is_scalar( $row ) ? (string) $row : '',
			);

			$week_number = absint( $row['week_number'] ?? $row['number'] ?? $row['week'] ?? 0 );
			$label       = sanitize_text_field( (string) ( $row['label'] ?? $row['title'] ?? $row['name'] ?? '' ) );
			$description = sanitize_textarea_field( (string) ( $row['description'] ?? '' ) );

			if ( ! $week_number ) {
				$week_number = $fallback_number;
			}

			$fallback_number = max( $fallback_number, $week_number + 1 );

			if ( isset( $seen_numbers[ $week_number ] ) ) {
				continue;
			}

			$seen_numbers[ $week_number ] = true;

			if ( '' === $label ) {
				$label = sprintf( __( 'Week %d', 'simple-lms' ), $week_number );
			}

			$slug = sanitize_title( (string) ( $row['slug'] ?? $label ) );

			if ( '' === $slug ) {
				$slug = 'week-' . $week_number;
			}

			$weeks[] = array(
				'week_number' => $week_number,
				'label'       => $label,
				'slug'        => $slug,
				'description' => $description,
			);
		}

		usort(
			$weeks,
			static function ( $left, $right ) {
				return (int) $left['week_number'] <=> (int) $right['week_number'];
			}
		);

		return array_values( $weeks );
	}

	private function get_subject_item_post( $subject_id, $tab, $item_id ) {
		$config = $this->get_subject_item_config( $tab );
		$post   = get_post( $item_id );

		if ( empty( $config ) || ! $post || $config['post_type'] !== $post->post_type ) {
			return null;
		}

		if ( (int) get_post_meta( $post->ID, $config['subject_meta_key'], true ) !== (int) $subject_id ) {
			return null;
		}

		return $post;
	}

	private function get_material_visibility( $post_id ) {
		$visibility = sanitize_key( (string) get_post_meta( $post_id, '_slms_lesson_visibility', true ) );

		return in_array( $visibility, array( 'students', 'staff', 'all' ), true ) ? $visibility : 'students';
	}

	private function is_material_visible_to_students( $post_id ) {
		return 'staff' !== $this->get_material_visibility( $post_id );
	}

	private function get_subject_item_visibility_meta_key( $tab ) {
		return 'materials' === $tab ? '_slms_lesson_visibility' : '_slms_subject_item_visibility';
	}

	private function get_subject_item_visibility( $post_id, $tab ) {
		if ( ! in_array( $tab, array( 'materials', 'assignments', 'discussions' ), true ) ) {
			return 'students';
		}

		$visibility = sanitize_key( (string) get_post_meta( $post_id, $this->get_subject_item_visibility_meta_key( $tab ), true ) );

		return in_array( $visibility, array( 'students', 'staff', 'all' ), true ) ? $visibility : 'students';
	}

	private function is_subject_item_visible_to_students( $post_id, $tab ) {
		return 'staff' !== $this->get_subject_item_visibility( $post_id, $tab );
	}

	private function update_subject_item_visibility( $post_id, $tab, $visibility ) {
		update_post_meta( $post_id, $this->get_subject_item_visibility_meta_key( $tab ), $visibility );
	}

	private function get_subject_item_attachments( $post_id, $tab ) {
		$config             = $this->get_subject_item_config( $tab );
		$attachment_ids     = $this->normalize_attachment_ids( get_post_meta( $post_id, $config['attachment_meta_key'], true ) );
		$managed_attachments = $this->normalize_managed_attachment_records( get_post_meta( $post_id, $config['managed_attachment_meta_key'], true ) );
		$legacy_id          = ! empty( $config['legacy_attachment_id_meta_key'] ) ? absint( get_post_meta( $post_id, $config['legacy_attachment_id_meta_key'], true ) ) : 0;
		$records            = array();
		$seen_urls          = array();

		if ( $legacy_id ) {
			array_unshift( $attachment_ids, $legacy_id );
		}

		foreach ( array_values( array_unique( array_filter( array_map( 'absint', $attachment_ids ) ) ) ) as $attachment_id ) {
			$record = $this->build_attachment_record_from_id( $attachment_id );

			if ( empty( $record['url'] ) ) {
				continue;
			}

			$seen_urls[ $record['url'] ] = true;
			$records[]                   = $record;
		}

		foreach ( $managed_attachments as $managed_attachment ) {
			$record = $this->build_managed_attachment_record( $managed_attachment );

			if ( empty( $record['url'] ) ) {
				continue;
			}

			$seen_urls[ $record['url'] ] = true;
			$records[]                   = $record;
		}

		$legacy_url = ! empty( $config['legacy_url_meta_key'] ) ? (string) get_post_meta( $post_id, $config['legacy_url_meta_key'], true ) : '';
		$legacy_url = $legacy_url ? esc_url_raw( $legacy_url ) : '';

		if ( $legacy_url && empty( $seen_urls[ $legacy_url ] ) ) {
			$records[] = $this->build_legacy_attachment_record(
				$legacy_url,
				! empty( $config['legacy_name_meta_key'] ) ? (string) get_post_meta( $post_id, $config['legacy_name_meta_key'], true ) : ''
			);
		}

		return array_values(
			array_filter(
				$records,
				static function ( $record ) {
					return ! empty( $record['url'] );
				}
			)
		);
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
			$name          = sanitize_text_field( (string) ( $record['name'] ?? '' ) );
			$mime          = sanitize_text_field( (string) ( $record['mime'] ?? '' ) );
			$key           = $relative_path ?: $url;

			if ( '' === $key || isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$records[]    = array(
				'relative_path' => $relative_path,
				'url'           => $url,
				'name'          => $name,
				'mime'          => $mime,
			);
		}

		return $records;
	}

	private function normalize_managed_upload_record( $value ) {
		$records = $this->normalize_managed_attachment_records( array( $value ) );

		return $records[0] ?? array();
	}

	private function build_attachment_record_from_id( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		$url           = $attachment_id ? wp_get_attachment_url( $attachment_id ) : '';

		if ( ! $url ) {
			return array();
		}

		$file = get_attached_file( $attachment_id );
		$name = $file ? wp_basename( $file ) : $this->get_attachment_name_from_url( $url );
		$kind = $this->get_attachment_kind( (string) get_post_mime_type( $attachment_id ), $url );

		return array(
			'token'       => 'id:' . $attachment_id,
			'id'          => $attachment_id,
			'is_legacy'   => false,
			'is_managed'  => false,
			'url'         => esc_url_raw( $url ),
			'name'        => sanitize_text_field( $name ?: __( 'Attachment', 'simple-lms' ) ),
			'mime'        => (string) get_post_mime_type( $attachment_id ),
			'kind'        => $kind,
			'preview_url' => 'image' === $kind ? ( wp_get_attachment_image_url( $attachment_id, 'large' ) ?: $url ) : '',
			'relative_path' => '',
			'stored_url'  => '',
		);
	}

	private function build_managed_attachment_record( array $record ) {
		$relative_path = ltrim( str_replace( '\\', '/', sanitize_text_field( (string) ( $record['relative_path'] ?? '' ) ) ), '/' );
		$stored_url    = esc_url_raw( (string) ( $record['url'] ?? '' ) );
		$url           = $this->get_managed_upload_url( $relative_path, $stored_url );

		if ( ! $url ) {
			return array();
		}

		$name = sanitize_text_field( (string) ( $record['name'] ?? '' ) );
		if ( '' === $name ) {
			$name = $this->get_attachment_name_from_url( $url );
		}

		$mime = sanitize_text_field( (string) ( $record['mime'] ?? '' ) );
		$kind = $this->get_attachment_kind( $mime, $url );

		return array(
			'token'         => 'managed:' . md5( $relative_path ?: $url ),
			'id'            => 0,
			'is_legacy'     => false,
			'is_managed'    => true,
			'url'           => $url,
			'name'          => $name ?: __( 'Attachment', 'simple-lms' ),
			'mime'          => $mime,
			'kind'          => $kind,
			'preview_url'   => 'image' === $kind ? $url : '',
			'relative_path' => $relative_path,
			'stored_url'    => $stored_url,
		);
	}

	private function build_legacy_attachment_record( $url, $name = '' ) {
		$url  = esc_url_raw( $url );
		$name = $name ? sanitize_text_field( $name ) : $this->get_attachment_name_from_url( $url );

		return array(
			'token'         => 'legacy:' . md5( $url ),
			'id'            => 0,
			'is_legacy'     => true,
			'is_managed'    => false,
			'url'           => $url,
			'name'          => $name ?: __( 'Attachment', 'simple-lms' ),
			'mime'          => '',
			'kind'          => $this->get_attachment_kind( '', $url ),
			'preview_url'   => '',
			'relative_path' => '',
			'stored_url'    => '',
		);
	}

	private function get_attachment_kind( $mime, $url ) {
		$mime = strtolower( (string) $mime );
		$path = wp_parse_url( $url, PHP_URL_PATH ) ?: '';
		$ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

		if ( 0 === strpos( $mime, 'image/' ) || in_array( $ext, array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg' ), true ) ) {
			return 'image';
		}

		if ( 0 === strpos( $mime, 'audio/' ) || in_array( $ext, array( 'mp3', 'wav', 'm4a', 'ogg', 'aac' ), true ) ) {
			return 'audio';
		}

		if ( 0 === strpos( $mime, 'video/' ) || in_array( $ext, array( 'mp4', 'mov', 'webm', 'm4v' ), true ) ) {
			return 'video';
		}

		if (
			false !== strpos( $mime, 'pdf' ) ||
			false !== strpos( $mime, 'word' ) ||
			false !== strpos( $mime, 'excel' ) ||
			false !== strpos( $mime, 'powerpoint' ) ||
			false !== strpos( $mime, 'text/' ) ||
			false !== strpos( $mime, 'opendocument' ) ||
			in_array( $ext, array( 'pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'txt', 'csv', 'rtf', 'odt', 'ods' ), true )
		) {
			return 'document';
		}

		return 'file';
	}

	private function get_attachment_name_from_url( $url ) {
		$path = wp_parse_url( $url, PHP_URL_PATH ) ?: '';

		return sanitize_text_field( rawurldecode( wp_basename( $path ) ) );
	}

	private function get_embed_markup( $url ) {
		$url = esc_url_raw( $url );

		if ( ! $url ) {
			return '';
		}

		return (string) wp_oembed_get(
			$url,
			array(
				'width' => 960,
			)
		);
	}

	private function get_subject_item_embed_urls( $post_id, array $config ) {
		$embed_urls = array();

		if ( ! empty( $config['embed_urls_meta_key'] ) ) {
			$stored_urls = get_post_meta( $post_id, $config['embed_urls_meta_key'], true );

			if ( is_array( $stored_urls ) ) {
				$embed_urls = $this->normalize_subject_embed_urls( $stored_urls );
			}
		}

		if ( empty( $embed_urls ) && ! empty( $config['embed_meta_key'] ) ) {
			$legacy_url = get_post_meta( $post_id, $config['embed_meta_key'], true );
			$embed_urls = $this->normalize_subject_embed_urls( array( $legacy_url ) );
		}

		return is_wp_error( $embed_urls ) ? array() : $embed_urls;
	}

	private function get_subject_embed_urls_from_request() {
		if ( array_key_exists( 'embed_urls', $_POST ) ) {
			$raw_urls = (array) wp_unslash( $_POST['embed_urls'] );
		} else {
			$raw_urls = array( wp_unslash( $_POST['embed_url'] ?? '' ) );
		}

		return $this->normalize_subject_embed_urls( $raw_urls, true );
	}

	private function normalize_subject_embed_urls( $values, $reject_invalid = false ) {
		$values = is_array( $values ) ? $values : array( $values );
		$urls   = array();

		foreach ( $values as $value ) {
			if ( ! is_scalar( $value ) ) {
				if ( $reject_invalid ) {
					return new \WP_Error( 'slms_invalid_embed_url', __( 'Please enter valid video or audio links.', 'simple-lms' ) );
				}
				continue;
			}

			$value = trim( (string) $value );

			if ( '' === $value ) {
				continue;
			}

			$url    = esc_url_raw( $value, array( 'http', 'https' ) );
			$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
			$host   = (string) wp_parse_url( $url, PHP_URL_HOST );

			if ( '' === $url || '' === $host || ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
				if ( $reject_invalid ) {
					return new \WP_Error( 'slms_invalid_embed_url', __( 'Please enter valid video or audio links beginning with http:// or https://.', 'simple-lms' ) );
				}
				continue;
			}

			if ( ! in_array( $url, $urls, true ) ) {
				$urls[] = $url;
			}
		}

		if ( count( $urls ) > self::MAX_SUBJECT_EMBED_URLS ) {
			if ( $reject_invalid ) {
				return new \WP_Error(
					'slms_too_many_embed_urls',
					sprintf(
						/* translators: %d: maximum number of embedded media links */
						__( 'Please add no more than %d video or audio links.', 'simple-lms' ),
						self::MAX_SUBJECT_EMBED_URLS
					)
				);
			}

			$urls = array_slice( $urls, 0, self::MAX_SUBJECT_EMBED_URLS );
		}

		return $urls;
	}

	private function save_subject_item_embed_urls( $post_id, array $config, array $embed_urls ) {
		if ( empty( $config['embed_meta_key'] ) || empty( $config['embed_urls_meta_key'] ) ) {
			return;
		}

		if ( empty( $embed_urls ) ) {
			delete_post_meta( $post_id, $config['embed_urls_meta_key'] );
			delete_post_meta( $post_id, $config['embed_meta_key'] );
			return;
		}

		update_post_meta( $post_id, $config['embed_urls_meta_key'], array_values( $embed_urls ) );
		update_post_meta( $post_id, $config['embed_meta_key'], $embed_urls[0] );
	}

	private function summarize_subject_item_assets( array $attachments, array $embed_urls = array() ) {
		$bits   = array();
		$counts = $this->count_subject_attachment_kinds( $attachments );

		foreach ( array( 'document', 'audio', 'video', 'image', 'file' ) as $kind ) {
			$count = (int) ( $counts[ $kind ] ?? 0 );

			if ( $count < 1 ) {
				continue;
			}

			if ( 'document' === $kind ) {
				$bits[] = sprintf( _n( '%d document', '%d documents', $count, 'simple-lms' ), $count );
			} elseif ( 'audio' === $kind ) {
				$bits[] = sprintf( _n( '%d audio', '%d audios', $count, 'simple-lms' ), $count );
			} elseif ( 'video' === $kind ) {
				$bits[] = sprintf( _n( '%d video', '%d videos', $count, 'simple-lms' ), $count );
			} elseif ( 'image' === $kind ) {
				$bits[] = sprintf( _n( '%d image', '%d images', $count, 'simple-lms' ), $count );
			} else {
				$bits[] = sprintf( _n( '%d file', '%d files', $count, 'simple-lms' ), $count );
			}
		}

		$embed_count = count( $embed_urls );

		if ( $embed_count ) {
			$bits[] = sprintf( _n( '%d embedded item', '%d embedded items', $embed_count, 'simple-lms' ), $embed_count );
		}

		return $bits;
	}

	private function count_subject_attachment_kinds( array $attachments ) {
		$counts = array(
			'document' => 0,
			'audio'    => 0,
			'video'    => 0,
			'image'    => 0,
			'file'     => 0,
		);

		foreach ( $attachments as $attachment ) {
			$kind = sanitize_key( $attachment['kind'] ?? 'file' );

			if ( ! isset( $counts[ $kind ] ) ) {
				$kind = 'file';
			}

			$counts[ $kind ]++;
		}

		return $counts;
	}

	private function get_subject_item_summary_icon( array $item_view ) {
		if ( 'assignments' === $item_view['tab'] ) {
			return $this->get_portal_icon_markup( 'assignment' );
		}

		if ( 'discussions' === $item_view['tab'] ) {
			return $this->get_portal_icon_markup( 'discussion' );
		}

		$counts    = $this->count_subject_attachment_kinds( $item_view['attachments'] );
		$icon_kind = 'document';
		$max_count = 0;

		foreach ( array( 'document', 'audio', 'video', 'image', 'file' ) as $kind ) {
			$count = (int) ( $counts[ $kind ] ?? 0 );

			if ( $count > $max_count ) {
				$max_count = $count;
				$icon_kind = $kind;
			}
		}

		if ( 0 === $max_count && ! empty( $item_view['embed_urls'] ) ) {
			$icon_kind = 'video';
		}

		return $this->get_portal_icon_markup( $icon_kind );
	}

	private function get_portal_icon_markup( $icon ) {
		switch ( $icon ) {
			case 'image-edit':
				return '<svg viewBox="0 0 1024 1024" fill="currentColor" aria-hidden="true"><path d="M834.3 705.7c0 82.2-66.8 149-149 149H325.9c-82.2 0-149-66.8-149-149V346.4c0-82.2 66.8-149 149-149h129.8v-42.7H325.9c-105.7 0-191.7 86-191.7 191.7v359.3c0 105.7 86 191.7 191.7 191.7h359.3c105.7 0 191.7-86 191.7-191.7V575.9h-42.7v129.8z"/><path d="M889.7 163.4c-22.9-22.9-53-34.4-83.1-34.4s-60.1 11.5-83.1 34.4L312 574.9c-16.9 16.9-27.9 38.8-31.2 62.5l-19 132.8c-1.6 11.4 7.3 21.3 18.4 21.3 0.9 0 1.8-0.1 2.7-0.2l132.8-19c23.7-3.4 45.6-14.3 62.5-31.2l411.5-411.5c45.9-45.9 45.9-120.3 0-166.2zM362 585.3L710.3 237 816 342.8 467.8 691.1 362 585.3zM409.7 730l-101.1 14.4L323 643.3c1.4-9.5 4.8-18.7 9.9-26.7L436.3 720c-8 5.2-17.1 8.7-26.6 10z m449.8-430.7l-13.3 13.3-105.7-105.8 13.3-13.3c14.1-14.1 32.9-21.9 52.9-21.9s38.8 7.8 52.9 21.9c29.1 29.2 29.1 76.7-0.1 105.8z"/></svg>';
			case 'eye':
				return '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M1.5 12s3.8-6.5 10.5-6.5S22.5 12 22.5 12 18.7 18.5 12 18.5 1.5 12 1.5 12Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><circle cx="12" cy="12" r="3.25" stroke="currentColor" stroke-width="1.8"/></svg>';
			case 'eye-off':
				return '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M3 3 21 21" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M10.55 5.66A11.84 11.84 0 0 1 12 5.5C18.7 5.5 22.5 12 22.5 12a20.6 20.6 0 0 1-3.6 4.43M6.18 6.19C3.58 7.7 1.5 12 1.5 12s3.8 6.5 10.5 6.5c1.56 0 3.01-.35 4.33-.9" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M9.88 9.88A3 3 0 0 0 9 12a3 3 0 0 0 4.82 2.39" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
			case 'download':
				return '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 4.5v10" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="m8.5 11.5 3.5 3.8 3.5-3.8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M4.5 18.5h15" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>';
			case 'assignment':
				return '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M7 3.5h7l4 4V20a1.5 1.5 0 0 1-1.5 1.5h-9A1.5 1.5 0 0 1 6 20V5A1.5 1.5 0 0 1 7.5 3.5Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M14 3.5V8h4" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M9 15.5 15.8 8.7a1.4 1.4 0 1 1 2 2L11 17.5 8 18l.5-3Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>';
			case 'discussion':
				return '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M6 17.5H4.5A1.5 1.5 0 0 1 3 16V5.5A1.5 1.5 0 0 1 4.5 4h10A1.5 1.5 0 0 1 16 5.5V16A1.5 1.5 0 0 1 14.5 17.5H10l-4 3v-3Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M16 8h3.5A1.5 1.5 0 0 1 21 9.5V17a1.5 1.5 0 0 1-1.5 1.5H18l-3 2.2v-3.2" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M7 8.5h5M7 12h6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>';
			case 'audio':
				return '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M14.5 4.5v12.3a3.3 3.3 0 1 1-1.8-3V8.7l7-1.7v7.8a3.3 3.3 0 1 1-1.8-3V4.5l-3.4.8Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
			case 'video':
				return '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="3" y="5.5" width="13.5" height="13" rx="2" stroke="currentColor" stroke-width="1.8"/><path d="m16.5 10 4.5-2.5v9L16.5 14" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="m9.5 10 3 2-3 2v-4Z" fill="currentColor"/></svg>';
			case 'image':
				return '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="3" y="4.5" width="18" height="15" rx="2" stroke="currentColor" stroke-width="1.8"/><circle cx="9" cy="9.5" r="1.8" stroke="currentColor" stroke-width="1.8"/><path d="m5.5 17 4.2-4.2a1 1 0 0 1 1.4 0l2.3 2.3 2.4-2.4a1 1 0 0 1 1.4 0l2.7 2.7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
			case 'file':
				return '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M7 3.5h7l4 4V20a1.5 1.5 0 0 1-1.5 1.5h-9A1.5 1.5 0 0 1 6 20V5A1.5 1.5 0 0 1 7.5 3.5Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M14 3.5V8h4" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M9 12h6M9 15.5h4.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>';
			case 'document':
			default:
				return '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M7 3.5h7l4 4V20a1.5 1.5 0 0 1-1.5 1.5h-9A1.5 1.5 0 0 1 6 20V5A1.5 1.5 0 0 1 7.5 3.5Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M14 3.5V8h4" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M9 12h6M9 15.5h6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>';
		}
	}

	private function render_attendance_tab( $subject_id, $role, $attendance_dashboard, array $assignments, $current_week_number = 0 ) {
		unset( $assignments );

		if ( is_wp_error( $attendance_dashboard ) ) {
			?>
			<section class="slms-portal-panel">
				<h2><?php esc_html_e( 'Attendance', 'simple-lms' ); ?></h2>
				<p class="slms-field-help"><?php echo esc_html( $attendance_dashboard->get_error_message() ); ?></p>
			</section>
			<?php
			return;
		}

		$can_manage        = $this->attendance->can_manage_attendance( get_current_user_id(), $subject_id );
		$sessions          = $attendance_dashboard['sessions'] ?? array();
		$students          = $attendance_dashboard['students'] ?? array();
		$total_classes     = $this->get_total_classes( $subject_id, $sessions );
		$plan_unit         = $this->get_attendance_plan_unit( $subject_id );
		$planned_label     = 'classes' === $plan_unit ? __( 'Planned Classes', 'simple-lms' ) : __( 'Planned Weeks', 'simple-lms' );
		$class_rows        = $this->build_attendance_class_rows( $attendance_dashboard, $total_classes, $plan_unit );
		$current_session_number = $this->get_attendance_focus_session_number( $attendance_dashboard, $class_rows, $current_week_number );
		$current_student   = $attendance_dashboard['student_summary_map'][ get_current_user_id() ] ?? null;
		$attendance_title  = $can_manage ? __( 'Take Attendance', 'simple-lms' ) : __( 'Attendance Progress', 'simple-lms' );
		?>
		<div class="slms-subject-tab-layout">
			<?php if ( $can_manage ) : ?>
				<details class="slms-subject-action-card">
					<summary><span class="slms-action-panel-trigger"><span class="slms-action-panel-trigger-label"><?php esc_html_e( 'Plan Class Count', 'simple-lms' ); ?></span></span></summary>
					<div class="slms-accordion-body slms-action-panel-window">
						<form method="post" action="" class="slms-form-stack">
							<?php wp_nonce_field( 'slms_portal_action', 'slms_portal_nonce' ); ?>
							<input type="hidden" name="slms_portal_action" value="save_subject_class_plan">
							<input type="hidden" name="subject_id" value="<?php echo esc_attr( $subject_id ); ?>">
							<div class="slms-form-split">
								<label>
									<span><?php esc_html_e( 'Plan By', 'simple-lms' ); ?></span>
									<select name="attendance_plan_unit">
										<option value="weeks" <?php selected( $plan_unit, 'weeks' ); ?>><?php esc_html_e( 'Weeks', 'simple-lms' ); ?></option>
										<option value="classes" <?php selected( $plan_unit, 'classes' ); ?>><?php esc_html_e( 'Classes', 'simple-lms' ); ?></option>
									</select>
								</label>
								<label>
									<span><?php esc_html_e( 'Plan Count', 'simple-lms' ); ?></span>
									<input type="number" name="total_classes" min="1" value="<?php echo esc_attr( (string) $total_classes ); ?>" required>
								</label>
								<div class="slms-field-help"><?php esc_html_e( 'Choose Weeks for weekly lectures or Classes for daily/session-based programs, then enter the planned count for the attendance board.', 'simple-lms' ); ?></div>
							</div>
							<button type="submit" class="slms-portal-button"><?php esc_html_e( 'Save Attendance Plan', 'simple-lms' ); ?></button>
						</form>
					</div>
				</details>
			<?php endif; ?>
			<section class="slms-portal-panel slms-subject-attendance-panel">
				<div class="slms-section-heading">
					<div>
						<h2><?php echo esc_html( $attendance_title ); ?></h2>
						<p class="slms-field-help">
							<?php
							echo esc_html(
								$can_manage
									? __( 'Attendance windows now open as focused modals. Each class starts in absent mode. Click a student card to toggle Present or Absent, and use the Late or Leave buttons for exceptions.', 'simple-lms' )
									: __( 'Track each class in a compact board view. Attendance and participation contributes up to 10 marks, scaled directly from the recorded attendance percentage.', 'simple-lms' )
							);
							?>
						</p>
					</div>
				</div>

				<?php if ( $can_manage ) : ?>
					<div class="slms-progress-summary-grid">
						<div class="slms-progress-summary-card"><span><?php echo esc_html( $planned_label ); ?></span><strong><?php echo esc_html( (string) $total_classes ); ?></strong></div>
						<div class="slms-progress-summary-card"><span><?php esc_html_e( 'Recorded Classes', 'simple-lms' ); ?></span><strong><?php echo esc_html( (string) ( $attendance_dashboard['completed_sessions'] ?? 0 ) ); ?></strong></div>
						<div class="slms-progress-summary-card"><span><?php esc_html_e( 'Roster Size', 'simple-lms' ); ?></span><strong><?php echo esc_html( (string) count( $students ) ); ?></strong></div>
						<div class="slms-progress-summary-card"><span><?php esc_html_e( 'Average Attendance', 'simple-lms' ); ?></span><strong><?php echo esc_html( number_format_i18n( (float) ( $attendance_dashboard['average_attendance'] ?? 0 ), 1 ) ); ?>%</strong></div>
					</div>
					<?php if ( empty( $students ) ) : ?>
						<p class="slms-field-help"><?php esc_html_e( 'No students are enrolled in this subject yet. Add students in the Students tab before recording attendance.', 'simple-lms' ); ?></p>
					<?php endif; ?>
					<?php $this->render_attendance_current_session_tools( $subject_id, $class_rows, $current_session_number, $plan_unit ); ?>
					<div class="slms-subject-attendance-session-grid" data-slms-attendance-session-grid>
						<?php foreach ( $class_rows as $class_row ) : ?>
							<?php $this->render_subject_attendance_session_manage_card( $subject_id, $class_row, $current_session_number ); ?>
						<?php endforeach; ?>
					</div>
					<?php foreach ( $class_rows as $class_row ) : ?>
						<?php $this->render_attendance_session_modal( $subject_id, $class_row, $students ); ?>
					<?php endforeach; ?>
				<?php else : ?>
					<div class="slms-progress-summary-grid">
						<div class="slms-progress-summary-card"><span><?php esc_html_e( 'Completed Classes', 'simple-lms' ); ?></span><strong><?php echo esc_html( (string) ( $current_student['eligible_sessions'] ?? 0 ) ); ?></strong></div>
						<div class="slms-progress-summary-card"><span><?php esc_html_e( 'Present', 'simple-lms' ); ?></span><strong><?php echo esc_html( (string) ( $current_student['present_sessions'] ?? 0 ) ); ?></strong></div>
						<div class="slms-progress-summary-card"><span><?php esc_html_e( 'Late', 'simple-lms' ); ?></span><strong><?php echo esc_html( (string) ( $current_student['late_sessions'] ?? 0 ) ); ?></strong></div>
						<div class="slms-progress-summary-card"><span><?php esc_html_e( 'Leave', 'simple-lms' ); ?></span><strong><?php echo esc_html( (string) ( $current_student['leave_sessions'] ?? 0 ) ); ?></strong></div>
						<div class="slms-progress-summary-card"><span><?php esc_html_e( 'Absences', 'simple-lms' ); ?></span><strong><?php echo esc_html( (string) ( $current_student['absent_sessions'] ?? 0 ) ); ?></strong></div>
						<div class="slms-progress-summary-card"><span><?php esc_html_e( 'Attendance', 'simple-lms' ); ?></span><strong><?php echo esc_html( number_format_i18n( (float) ( $current_student['attendance_percentage'] ?? 0 ), 1 ) ); ?>%</strong></div>
					</div>
					<div class="slms-subject-attendance-session-grid is-student">
						<?php foreach ( $class_rows as $class_row ) : ?>
							<?php
							$session = $class_row['session'];
							$status  = 'pending';

							if ( ! empty( $session ) && (int) ( $session['record_count'] ?? 0 ) > 0 ) {
								$record = $class_row['records_map'][ get_current_user_id() ] ?? null;
								$status = $record['attendance_status'] ?? 'absent';
						}
							?>
							<?php $this->render_subject_attendance_session_student_card( $class_row, $status ); ?>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</section>
		</div>
		<?php
	}

	private function render_attendance_session_modal( $subject_id, array $class_row, array $students ) {
		$session      = $class_row['session'];
		$session_date = $session['session_date'] ?? AcademicClock::date( 'Y-m-d' );
		$modal_id     = $this->get_attendance_modal_id( $subject_id, (int) $class_row['number'] );
		?>
		<div class="slms-attendance-modal" id="<?php echo esc_attr( $modal_id ); ?>" aria-hidden="true">
			<div class="slms-attendance-modal-backdrop" data-slms-modal-close></div>
			<div class="slms-attendance-modal-card" role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr( $modal_id . '-title' ); ?>">
				<button type="button" class="slms-attendance-modal-close" data-slms-modal-close aria-label="<?php esc_attr_e( 'Close attendance window', 'simple-lms' ); ?>">&times;</button>
				<div class="slms-attendance-modal-head">
					<div>
						<h3 id="<?php echo esc_attr( $modal_id . '-title' ); ?>"><?php echo esc_html( $class_row['label'] ); ?></h3>
						<p><?php echo esc_html( ! empty( $session['session_date'] ) ? $session['session_date'] : __( 'No date set yet', 'simple-lms' ) ); ?></p>
					</div>
					<span class="slms-portal-badge"><?php echo esc_html( sprintf( __( 'P %1$d / L %2$d / Lv %3$d / A %4$d', 'simple-lms' ), (int) ( $session['present_count'] ?? 0 ), (int) ( $session['late_count'] ?? 0 ), (int) ( $session['leave_count'] ?? 0 ), (int) ( $session['absent_count'] ?? 0 ) ) ); ?></span>
				</div>
				<div class="slms-attendance-modal-body">
					<form method="post" action="" class="slms-form-stack slms-attendance-form">
						<?php wp_nonce_field( 'slms_portal_action', 'slms_portal_nonce' ); ?>
						<input type="hidden" name="slms_portal_action" value="save_subject_attendance">
						<input type="hidden" name="subject_id" value="<?php echo esc_attr( $subject_id ); ?>">
						<input type="hidden" name="session_id" value="<?php echo esc_attr( (string) ( $session['id'] ?? 0 ) ); ?>">
						<input type="hidden" name="session_number" value="<?php echo esc_attr( (string) $class_row['number'] ); ?>">
					<div class="slms-form-split">
						<label>
							<span><?php esc_html_e( 'Class Date', 'simple-lms' ); ?></span>
							<input type="date" name="session_date" value="<?php echo esc_attr( $session_date ); ?>" required>
						</label>
						<div class="slms-field-help"><?php esc_html_e( 'Students start absent. Click a portrait card to toggle Present or Absent. Use the small Late or Leave buttons above the photo for exceptions.', 'simple-lms' ); ?></div>
					</div>
					<div class="slms-row-actions">
						<button type="button" class="slms-portal-button is-secondary" data-slms-attendance-mark="present"><?php esc_html_e( 'Mark All Present', 'simple-lms' ); ?></button>
						<button type="button" class="slms-portal-button is-secondary" data-slms-attendance-mark="absent"><?php esc_html_e( 'Reset To Absent', 'simple-lms' ); ?></button>
					</div>
					<div class="slms-attendance-portrait-grid">
						<?php foreach ( $students as $student ) : ?>
							<?php
							$student_id = (int) $student['student_user_id'];
							$status     = $class_row['records_map'][ $student_id ]['attendance_status'] ?? 'absent';
							$this->render_attendance_student_toggle( $student, $status );
							?>
						<?php endforeach; ?>
					</div>
						<button type="submit" class="slms-portal-button"><?php echo esc_html( sprintf( __( 'Save %s', 'simple-lms' ), $class_row['label'] ) ); ?></button>
					</form>
				</div>
			</div>
		</div>
		<?php
	}

	private function render_people_tab( $subject_id, $role, array $roster ) {
		$can_enroll   = $this->can_enroll_subject( $subject_id, $role );
		$can_unenroll = $this->can_unenroll_subject( $subject_id, $role );
		$auditors     = $this->enrollments->list_staff_audit_enrollments(
			array(
				'section_id' => $subject_id,
				'statuses'   => array( 'active' ),
			)
		);
		?>
		<div class="slms-subject-tab-layout">
			<?php if ( $can_enroll ) : ?>
				<details class="slms-subject-action-card">
					<summary><span class="slms-action-panel-trigger"><span class="slms-action-panel-trigger-label"><?php esc_html_e( 'Enroll Students', 'simple-lms' ); ?></span></span></summary>
					<div class="slms-accordion-body slms-action-panel-window">
						<form method="post" action="" class="slms-form-stack">
							<?php wp_nonce_field( 'slms_portal_action', 'slms_portal_nonce' ); ?>
							<input type="hidden" name="slms_portal_action" value="enroll_codes">
							<input type="hidden" name="subject_id" value="<?php echo esc_attr( $subject_id ); ?>">
							<label><span><?php esc_html_e( 'Student Codes', 'simple-lms' ); ?></span><textarea name="student_codes" rows="5" placeholder="UGPS-26001, UGPS-26015"></textarea></label>
							<button type="submit" class="slms-portal-button"><?php esc_html_e( 'Enroll by Codes', 'simple-lms' ); ?></button>
						</form>
						<form method="post" action="" enctype="multipart/form-data" class="slms-form-stack">
							<?php wp_nonce_field( 'slms_portal_action', 'slms_portal_nonce' ); ?>
							<input type="hidden" name="slms_portal_action" value="enroll_import">
							<input type="hidden" name="subject_id" value="<?php echo esc_attr( $subject_id ); ?>">
							<label><span><?php esc_html_e( 'Upload CSV / XLSX', 'simple-lms' ); ?></span><input type="file" name="enroll_file" accept=".csv,.xlsx"></label>
							<button type="submit" class="slms-portal-button is-secondary"><?php esc_html_e( 'Import Enrollments', 'simple-lms' ); ?></button>
						</form>
						<form method="post" action="" class="slms-form-stack">
							<?php wp_nonce_field( 'slms_portal_action', 'slms_portal_nonce' ); ?>
							<input type="hidden" name="slms_portal_action" value="enroll_auditors">
							<input type="hidden" name="subject_id" value="<?php echo esc_attr( $subject_id ); ?>">
							<label><span><?php esc_html_e( 'Staff IDs for Auditing', 'simple-lms' ); ?></span><textarea name="staff_codes" rows="4" placeholder="UGP-L-001, UGP-L-002"></textarea></label>
							<p class="slms-field-help"><?php esc_html_e( 'Active auditors can view materials, submit assignments, and join discussions. They are not added to attendance or official results.', 'simple-lms' ); ?></p>
							<button type="submit" class="slms-portal-button is-secondary"><?php esc_html_e( 'Enroll Auditors', 'simple-lms' ); ?></button>
						</form>
					</div>
				</details>
			<?php endif; ?>
			<section class="slms-portal-panel slms-subject-roster-panel">
				<div class="slms-section-heading">
					<div>
						<h2><?php esc_html_e( 'Students', 'simple-lms' ); ?></h2>
						<p class="slms-field-help"><?php esc_html_e( 'Each student opens as a compact card so you can scan the roster quickly and still open contact details when needed.', 'simple-lms' ); ?></p>
					</div>
					<span class="slms-status-pill"><?php echo esc_html( sprintf( _n( '%d student', '%d students', count( $roster ), 'simple-lms' ), count( $roster ) ) ); ?></span>
				</div>
				<?php if ( empty( $roster ) ) : ?>
					<p class="slms-field-help"><?php esc_html_e( 'No students are enrolled in this subject yet.', 'simple-lms' ); ?></p>
				<?php else : ?>
					<div class="slms-subject-item-stack slms-subject-roster-stack">
						<?php foreach ( $roster as $row ) : ?>
							<?php $this->render_subject_roster_card( $row, $subject_id, $can_unenroll ); ?>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</section>
			<section class="slms-portal-panel slms-subject-roster-panel slms-subject-auditor-panel">
				<div class="slms-section-heading">
					<div>
						<h2><?php esc_html_e( 'Auditors', 'simple-lms' ); ?></h2>
						<p class="slms-field-help"><?php esc_html_e( 'Auditors are lecturers/staff enrolled as learners for this subject. Their submissions can receive feedback but do not count toward official results.', 'simple-lms' ); ?></p>
					</div>
					<span class="slms-status-pill"><?php echo esc_html( sprintf( _n( '%d auditor', '%d auditors', count( $auditors ), 'simple-lms' ), count( $auditors ) ) ); ?></span>
				</div>
				<?php if ( empty( $auditors ) ) : ?>
					<p class="slms-field-help"><?php esc_html_e( 'No staff auditors are enrolled in this subject yet.', 'simple-lms' ); ?></p>
				<?php else : ?>
					<div class="slms-subject-item-stack slms-subject-roster-stack">
						<?php foreach ( $auditors as $auditor ) : ?>
							<?php $this->render_staff_auditor_card( $auditor, $subject_id, $can_unenroll ); ?>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</section>
		</div>
		<?php
	}

	private function render_results_tab( $subject_id, $role, $gradebook, array $assignments, $attendance_dashboard ) {
		unset( $gradebook );

		$current_user_id        = get_current_user_id();
		$attendance_summary_map = ! is_wp_error( $attendance_dashboard ) ? ( $attendance_dashboard['student_summary_map'] ?? array() ) : array();
		$attendance_weight      = $this->get_attendance_participation_weight();
		$assignment_weight_cap  = $this->get_results_controlled_weight_cap();
		$weight_summary         = $this->results->get_results_weight_summary( $assignments );
		$assignment_breakdown   = $this->results->get_assignment_weight_breakdown( $assignments );
		$grade_scale_payload    = wp_json_encode( $this->grade_scale->get_frontend_scale_payload() );
		?>
		<section class="slms-portal-panel slms-subject-results-panel" data-slms-grade-scale="<?php echo esc_attr( $grade_scale_payload ); ?>">
			<div class="slms-section-heading">
				<div>
					<h2><?php esc_html_e( 'Results', 'simple-lms' ); ?></h2>
					<p class="slms-field-help"><?php echo esc_html( sprintf( __( 'Assignments and exam weight should stay within %1$s%% because attendance and participation reserves %2$s%% of the final result and scales directly from the recorded attendance percentage.', 'simple-lms' ), number_format_i18n( $assignment_weight_cap, 0 ), number_format_i18n( $attendance_weight, 0 ) ) ); ?></p>
				</div>
			</div>
			<?php if ( 'student' === $role ) : ?>
				<?php $student_snapshot = $this->get_student_result_snapshot( $subject_id, $assignments, $attendance_summary_map[ $current_user_id ] ?? array(), $current_user_id ); ?>
				<div class="slms-subject-results-overview-grid">
					<div class="slms-subject-results-overview-card">
						<span><?php esc_html_e( 'Assignments', 'simple-lms' ); ?></span>
						<strong><?php echo esc_html( number_format_i18n( (float) $student_snapshot['assignment_total'], 2 ) ); ?></strong>
						<small><?php echo esc_html( sprintf( __( '%s%% allocated to assignments', 'simple-lms' ), number_format_i18n( (float) $student_snapshot['assignment_weight_total'], 1 ) ) ); ?></small>
					</div>
					<div class="slms-subject-results-overview-card">
						<span><?php esc_html_e( 'Attendance and participation', 'simple-lms' ); ?></span>
						<strong><?php echo esc_html( number_format_i18n( (float) $student_snapshot['attendance_value'], 2 ) ); ?></strong>
						<small><?php echo esc_html( sprintf( __( '%s%% attendance recorded', 'simple-lms' ), number_format_i18n( (float) ( $attendance_summary_map[ $current_user_id ]['attendance_percentage'] ?? 0 ), 1 ) ) ); ?></small>
					</div>
					<div class="slms-subject-results-overview-card">
						<span><?php esc_html_e( 'Final Exam', 'simple-lms' ); ?></span>
						<strong><?php echo null !== $student_snapshot['final_exam_score'] ? esc_html( number_format_i18n( (float) $student_snapshot['final_exam_score'], 2 ) ) : '-'; ?></strong>
						<small><?php echo esc_html( sprintf( __( 'Up to %s%% available', 'simple-lms' ), number_format_i18n( (float) $student_snapshot['remaining_exam_weight'], 1 ) ) ); ?></small>
					</div>
					<div class="slms-subject-results-overview-card is-emphasis">
						<span><?php echo esc_html( $student_snapshot['can_show_total'] ? __( 'Course Total', 'simple-lms' ) : __( 'Course Total Pending', 'simple-lms' ) ); ?></span>
						<strong><?php echo $student_snapshot['can_show_total'] ? esc_html( number_format_i18n( (float) $student_snapshot['total_score'], 2 ) ) : esc_html__( 'Awaiting final grades', 'simple-lms' ); ?></strong>
						<small><?php echo esc_html( $student_snapshot['can_show_total'] ? __( 'Final weighted course outcome', 'simple-lms' ) : __( 'Some weighted work is still incomplete', 'simple-lms' ) ); ?></small>
					</div>
					<div class="slms-subject-results-overview-card">
						<span><?php esc_html_e( 'Grade', 'simple-lms' ); ?></span>
						<strong><?php echo esc_html( $student_snapshot['can_show_grade'] ? $student_snapshot['letter_grade'] : '-' ); ?></strong>
						<small><?php echo esc_html( $student_snapshot['can_show_grade'] ? __( 'Released from the course total', 'simple-lms' ) : __( 'Available once final grading is complete', 'simple-lms' ) ); ?></small>
					</div>
				</div>
				<div class="slms-subject-results-breakdown-intro">
					<strong><?php esc_html_e( 'Your Results Breakdown', 'simple-lms' ); ?></strong>
					<p><?php esc_html_e( 'This section shows only your own attendance, assignment, and final exam contributions.', 'simple-lms' ); ?></p>
				</div>
				<div class="slms-subject-results-breakdown-stack">
					<?php foreach ( $student_snapshot['rows'] as $row ) : ?>
						<?php $this->render_subject_result_breakdown_card( $row ); ?>
					<?php endforeach; ?>
				</div>
			<?php else : ?>
				<?php $students = ! is_wp_error( $attendance_dashboard ) ? ( $attendance_dashboard['students'] ?? array() ) : array(); ?>
				<?php $this->render_results_weight_summary_panel( $weight_summary, $assignment_breakdown ); ?>
				<?php if ( empty( $students ) ) : ?>
					<p class="slms-field-help"><?php esc_html_e( 'No enrolled students are available for this results table yet.', 'simple-lms' ); ?></p>
				<?php else : ?>
					<div class="slms-subject-results-overview-grid is-staff">
						<div class="slms-subject-results-overview-card">
							<span><?php esc_html_e( 'Students', 'simple-lms' ); ?></span>
							<strong><?php echo esc_html( (string) count( $students ) ); ?></strong>
							<small><?php esc_html_e( 'Current roster ready for grading', 'simple-lms' ); ?></small>
						</div>
						<div class="slms-subject-results-overview-card">
							<span><?php esc_html_e( 'Attendance Weight', 'simple-lms' ); ?></span>
							<strong><?php echo esc_html( number_format_i18n( $attendance_weight, 0 ) ); ?>%</strong>
							<small><?php esc_html_e( 'Scaled directly from recorded attendance percentage', 'simple-lms' ); ?></small>
						</div>
						<div class="slms-subject-results-overview-card">
							<span><?php esc_html_e( 'Assignment Weight', 'simple-lms' ); ?></span>
							<strong><?php echo esc_html( number_format_i18n( $this->get_assignment_weight_total( $assignments ), 1 ) ); ?>%</strong>
							<small><?php esc_html_e( 'Weighted work already allocated', 'simple-lms' ); ?></small>
						</div>
						<div class="slms-subject-results-overview-card is-emphasis">
							<span><?php esc_html_e( 'Final Exam Weight Left', 'simple-lms' ); ?></span>
							<strong><?php echo esc_html( number_format_i18n( max( 0, $assignment_weight_cap - $this->get_assignment_weight_total( $assignments ) ), 1 ) ); ?>%</strong>
							<small><?php esc_html_e( 'Available course weight remaining', 'simple-lms' ); ?></small>
						</div>
						<div class="slms-subject-results-overview-card">
							<span><?php esc_html_e( 'Grade Release', 'simple-lms' ); ?></span>
							<strong><?php esc_html_e( 'Final Exam', 'simple-lms' ); ?></strong>
							<small><?php esc_html_e( 'Letter grades appear after the final exam score is entered.', 'simple-lms' ); ?></small>
						</div>
					</div>
					<div class="slms-subject-item-stack slms-subject-results-editor-stack">
						<?php foreach ( $students as $student ) : ?>
							<?php
							$student_id = (int) $student['student_user_id'];
							$summary    = $attendance_summary_map[ $student_id ] ?? array();
							$snapshot   = $this->get_student_result_snapshot( $subject_id, $assignments, $summary, $student_id );
							$this->render_subject_results_staff_card( $subject_id, $student, $summary, $snapshot );
							?>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</section>
		<?php
	}

	private function render_subject_roster_card( array $row, $subject_id = 0, $can_unenroll = false ) {
		$student_id         = (int) ( $row['student_user_id'] ?? 0 );
		$student_name       = $row['student_name'] ?? __( 'Student', 'simple-lms' );
		$email              = $row['student_email'] ?? '';
		$status_raw         = sanitize_key( $row['status'] ?? 'active' );
		$status             = ucfirst( $status_raw ?: 'active' );
		$profile            = $student_id ? (array) $this->profiles->get_profile( $student_id ) : array();
		$person_code        = $profile['person_code'] ?? '';
		$can_drop_enrollment = $can_unenroll && $student_id && ! in_array( $status_raw, array( 'completed', 'dropped' ), true );
		$meta_bits          = array_filter(
			array(
				$person_code,
				sprintf( __( 'Status: %s', 'simple-lms' ), $status ),
			)
		);
		?>
		<details class="slms-subject-item-accordion slms-subject-roster-card">
			<summary class="slms-subject-item-card">
				<span class="slms-subject-item-card-icon slms-subject-item-card-avatar" aria-hidden="true"><img class="slms-subject-item-card-avatar-img" src="<?php echo esc_url( $this->get_profile_photo_url( $student_id ) ); ?>" alt="" loading="lazy" decoding="async"></span>
				<div class="slms-subject-item-card-content">
					<div class="slms-subject-item-card-head">
						<div class="slms-subject-item-card-title">
							<strong><?php echo esc_html( $student_name ); ?></strong>
						</div>
					</div>
					<?php if ( $email ) : ?>
						<p class="slms-subject-item-card-summary"><?php echo esc_html( $email ); ?></p>
					<?php endif; ?>
					<?php if ( $meta_bits ) : ?>
						<div class="slms-subject-item-card-meta">
							<?php foreach ( $meta_bits as $meta_bit ) : ?>
								<span><?php echo esc_html( $meta_bit ); ?></span>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</div>
				<div class="slms-subject-item-card-actions">
					<span class="slms-subject-item-card-action">
						<span class="slms-subject-item-card-action-view"><span><?php esc_html_e( 'View', 'simple-lms' ); ?></span></span>
						<span class="slms-subject-item-card-action-close"><span><?php esc_html_e( 'Close', 'simple-lms' ); ?></span></span>
					</span>
				</div>
			</summary>
			<div class="slms-subject-item-accordion-body">
				<div class="slms-subject-roster-fact-grid">
					<div class="slms-subject-roster-fact">
						<span><?php esc_html_e( 'Full Name', 'simple-lms' ); ?></span>
						<strong><?php echo esc_html( $student_name ); ?></strong>
					</div>
					<div class="slms-subject-roster-fact">
						<span><?php esc_html_e( 'Email', 'simple-lms' ); ?></span>
						<strong><?php echo esc_html( $email ?: __( 'Not provided', 'simple-lms' ) ); ?></strong>
					</div>
					<div class="slms-subject-roster-fact">
						<span><?php esc_html_e( 'Student Code', 'simple-lms' ); ?></span>
						<strong><?php echo esc_html( $person_code ?: __( 'Not assigned', 'simple-lms' ) ); ?></strong>
					</div>
					<div class="slms-subject-roster-fact">
						<span><?php esc_html_e( 'Enrollment Status', 'simple-lms' ); ?></span>
						<strong><?php echo esc_html( $status ); ?></strong>
					</div>
				</div>
				<?php if ( $can_drop_enrollment ) : ?>
					<form method="post" action="" class="slms-row-actions slms-roster-unenroll-form" onsubmit="return confirm('<?php echo esc_js( __( 'Unenroll this student from this subject? Their enrollment will be marked as dropped and their attendance records for this subject will be deleted.', 'simple-lms' ) ); ?>');">
						<?php wp_nonce_field( 'slms_portal_action', 'slms_portal_nonce' ); ?>
						<input type="hidden" name="slms_portal_action" value="unenroll_student">
						<input type="hidden" name="subject_id" value="<?php echo esc_attr( absint( $subject_id ) ); ?>">
						<input type="hidden" name="student_user_id" value="<?php echo esc_attr( $student_id ); ?>">
						<input type="hidden" name="enrollment_id" value="<?php echo esc_attr( absint( $row['id'] ?? 0 ) ); ?>">
						<button type="submit" class="slms-portal-button is-danger"><?php esc_html_e( 'Unenroll from Subject', 'simple-lms' ); ?></button>
					</form>
				<?php endif; ?>
			</div>
		</details>
		<?php
	}

	private function render_staff_auditor_card( array $row, $subject_id = 0, $can_drop = false ) {
		$staff_user_id = (int) ( $row['staff_user_id'] ?? 0 );
		$staff_name    = $row['staff_name'] ?? __( 'Staff Auditor', 'simple-lms' );
		$email         = $row['staff_email'] ?? '';
		$person_code   = $row['staff_code'] ?? '';
		$status        = ucfirst( sanitize_key( (string) ( $row['status'] ?? 'active' ) ) );
		?>
		<details class="slms-subject-item-accordion slms-subject-roster-card slms-subject-auditor-card">
			<summary class="slms-subject-item-card">
				<span class="slms-subject-item-card-icon slms-subject-item-card-avatar" aria-hidden="true"><img class="slms-subject-item-card-avatar-img" src="<?php echo esc_url( $this->get_profile_photo_url( $staff_user_id ) ); ?>" alt="" loading="lazy" decoding="async"></span>
				<div class="slms-subject-item-card-content">
					<div class="slms-subject-item-card-head">
						<div class="slms-subject-item-card-title">
							<span class="slms-person-role-line">
								<strong><?php echo esc_html( $staff_name ); ?></strong>
								<?php echo $this->get_auditor_tag_markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							</span>
						</div>
					</div>
					<?php if ( $email ) : ?>
						<p class="slms-subject-item-card-summary"><?php echo esc_html( $email ); ?></p>
					<?php endif; ?>
					<div class="slms-subject-item-card-meta">
						<?php if ( $person_code ) : ?>
							<span><?php echo esc_html( $person_code ); ?></span>
						<?php endif; ?>
						<span><?php echo esc_html( sprintf( __( 'Status: %s', 'simple-lms' ), $status ) ); ?></span>
					</div>
				</div>
				<div class="slms-subject-item-card-actions">
					<span class="slms-subject-item-card-action">
						<span class="slms-subject-item-card-action-view"><span><?php esc_html_e( 'View', 'simple-lms' ); ?></span></span>
						<span class="slms-subject-item-card-action-close"><span><?php esc_html_e( 'Close', 'simple-lms' ); ?></span></span>
					</span>
				</div>
			</summary>
			<div class="slms-subject-item-accordion-body">
				<div class="slms-subject-roster-fact-grid slms-subject-auditor-fact-grid">
					<div class="slms-subject-roster-fact">
						<span><?php esc_html_e( 'Full Name', 'simple-lms' ); ?></span>
						<strong><?php echo esc_html( $staff_name ); ?></strong>
					</div>
					<div class="slms-subject-roster-fact">
						<span><?php esc_html_e( 'Email', 'simple-lms' ); ?></span>
						<strong><?php echo esc_html( $email ?: __( 'Not provided', 'simple-lms' ) ); ?></strong>
					</div>
					<div class="slms-subject-roster-fact">
						<span><?php esc_html_e( 'Staff Code', 'simple-lms' ); ?></span>
						<strong><?php echo esc_html( $person_code ?: __( 'Not assigned', 'simple-lms' ) ); ?></strong>
					</div>
				</div>
				<p class="slms-field-help"><?php esc_html_e( 'Auditor submissions can be graded for feedback but are excluded from official gradebook and results.', 'simple-lms' ); ?></p>
				<?php if ( $can_drop && $staff_user_id ) : ?>
					<form method="post" action="" class="slms-row-actions slms-roster-unenroll-form" onsubmit="return confirm('<?php echo esc_js( __( 'Remove this staff auditor from this subject?', 'simple-lms' ) ); ?>');">
						<?php wp_nonce_field( 'slms_portal_action', 'slms_portal_nonce' ); ?>
						<input type="hidden" name="slms_portal_action" value="drop_staff_auditor">
						<input type="hidden" name="subject_id" value="<?php echo esc_attr( absint( $subject_id ) ); ?>">
						<input type="hidden" name="staff_user_id" value="<?php echo esc_attr( $staff_user_id ); ?>">
						<input type="hidden" name="audit_enrollment_id" value="<?php echo esc_attr( absint( $row['id'] ?? 0 ) ); ?>">
						<button type="submit" class="slms-portal-button is-danger"><?php esc_html_e( 'Remove Auditor', 'simple-lms' ); ?></button>
					</form>
				<?php endif; ?>
			</div>
		</details>
		<?php
	}

	private function render_subject_result_breakdown_card( array $row ) {
		$label        = (string) ( $row['label'] ?? __( 'Result Item', 'simple-lms' ) );
		$weight       = isset( $row['weight'] ) ? number_format_i18n( (float) $row['weight'], 1 ) . '%' : '-';
		$grade        = null !== ( $row['grade'] ?? null ) ? number_format_i18n( (float) $row['grade'], 2 ) : '-';
		$contribution = null !== ( $row['contribution'] ?? null ) ? number_format_i18n( (float) $row['contribution'], 2 ) : '-';
		$score        = null !== ( $row['score'] ?? null ) ? number_format_i18n( (float) $row['score'], 2 ) : null;
		$points       = null !== ( $row['points'] ?? null ) ? number_format_i18n( (float) $row['points'], 1 ) : null;
		$status_label = (string) ( $row['status_label'] ?? '' );
		$card_classes = array( 'slms-subject-results-breakdown-card' );
		$icon_class   = 'dashicons-chart-pie';

		if ( false !== stripos( $label, 'attendance' ) ) {
			$icon_class   = 'dashicons-yes-alt';
			$status_label = $status_label ?: __( 'Attendance rollup', 'simple-lms' );
		} elseif ( false !== stripos( $label, 'final exam' ) ) {
			$icon_class   = 'dashicons-welcome-write-blog';
			$status_label = $status_label ?: ( null !== ( $row['contribution'] ?? null ) ? __( 'Recorded', 'simple-lms' ) : __( 'Pending', 'simple-lms' ) );
		} else {
			$icon_class = 'dashicons-clipboard';
		}

		if ( ! empty( $row['is_pending'] ) || null === ( $row['contribution'] ?? null ) ) {
			$card_classes[] = 'is-pending';
		}

		if ( ! empty( $row['is_late'] ) ) {
			$card_classes[] = 'is-late';
		}
		?>
		<article class="<?php echo esc_attr( implode( ' ', $card_classes ) ); ?>">
			<div class="slms-subject-results-breakdown-head">
				<span class="slms-subject-item-card-icon" aria-hidden="true"><span class="dashicons <?php echo esc_attr( $icon_class ); ?>"></span></span>
				<div class="slms-subject-results-breakdown-copy">
					<strong><?php echo esc_html( $label ); ?></strong>
					<span><?php echo esc_html( sprintf( __( 'Weight %s', 'simple-lms' ), $weight ) ); ?></span>
				</div>
				<div class="slms-subject-results-breakdown-score">
					<span><?php esc_html_e( 'Course Total', 'simple-lms' ); ?></span>
					<strong><?php echo esc_html( $contribution ); ?></strong>
				</div>
			</div>
			<div class="slms-subject-results-breakdown-meta">
				<?php if ( $status_label ) : ?>
					<span><?php echo esc_html( $status_label ); ?></span>
				<?php endif; ?>
				<?php if ( null !== $score && null !== $points ) : ?>
					<span><?php echo esc_html( sprintf( __( 'Score %1$s / %2$s', 'simple-lms' ), $score, $points ) ); ?></span>
				<?php endif; ?>
				<span><?php echo esc_html( sprintf( __( 'Grade %s', 'simple-lms' ), $grade ) ); ?></span>
				<?php if ( ! empty( $row['is_late'] ) ) : ?>
					<span><?php echo esc_html( sprintf( __( 'Raw contribution %s', 'simple-lms' ), null !== ( $row['raw_contribution'] ?? null ) ? number_format_i18n( (float) $row['raw_contribution'], 2 ) : '-' ) ); ?></span>
					<span><?php echo esc_html( sprintf( __( 'Late penalty: %s', 'simple-lms' ), $row['late_policy_label'] ?? '' ) ); ?></span>
				<?php endif; ?>
				<span><?php echo esc_html( sprintf( __( 'Contribution %s', 'simple-lms' ), $contribution ) ); ?></span>
			</div>
		</article>
		<?php
	}

	private function render_results_weight_summary_panel( array $summary, array $assignment_breakdown ) {
		$decimal_point_rows = array_filter(
			$assignment_breakdown,
			function ( $row ) {
				return ! empty( $row['has_decimal_max_points'] );
			}
		);
		?>
		<div class="slms-results-weight-summary-panel">
			<div class="slms-results-weight-summary-head">
				<div>
					<span class="slms-portal-kicker"><?php esc_html_e( 'Results Weight Summary', 'simple-lms' ); ?></span>
					<h3><?php esc_html_e( 'Current scoring setup', 'simple-lms' ); ?></h3>
					<p><?php esc_html_e( 'This summary uses the existing Results architecture: attendance is fixed, assignments use their saved weights, and the final exam uses the remaining available weight.', 'simple-lms' ); ?></p>
				</div>
			</div>
			<div class="slms-results-weight-summary-grid">
				<div class="slms-results-weight-summary-card">
					<span><?php esc_html_e( 'Attendance', 'simple-lms' ); ?></span>
					<strong><?php echo esc_html( number_format_i18n( (float) $summary['attendance_weight'], 1 ) ); ?>%</strong>
				</div>
				<div class="slms-results-weight-summary-card">
					<span><?php esc_html_e( 'Assignments total', 'simple-lms' ); ?></span>
					<strong><?php echo esc_html( number_format_i18n( (float) $summary['assignment_weight_total'], 1 ) ); ?>%</strong>
				</div>
				<div class="slms-results-weight-summary-card is-emphasis">
					<span><?php esc_html_e( 'Final Exam available', 'simple-lms' ); ?></span>
					<strong><?php echo esc_html( number_format_i18n( (float) $summary['final_exam_weight'], 1 ) ); ?>%</strong>
				</div>
				<div class="slms-results-weight-summary-card">
					<span><?php esc_html_e( 'Total planned', 'simple-lms' ); ?></span>
					<strong><?php echo esc_html( number_format_i18n( (float) $summary['total_planned_weight'], 1 ) ); ?>%</strong>
				</div>
			</div>
			<?php if ( ! empty( $summary['over_assignment_cap'] ) ) : ?>
				<div class="slms-results-weight-warning is-danger">
					<strong><?php esc_html_e( 'Assignment weights exceed the allowed cap.', 'simple-lms' ); ?></strong>
					<span><?php echo esc_html( sprintf( __( 'Assignments are %1$s%% over the %2$s%% cap. Attendance already reserves %3$s%%, so reduce assignment weights before finalizing results.', 'simple-lms' ), number_format_i18n( (float) $summary['over_cap_amount'], 1 ), number_format_i18n( (float) $summary['assignment_weight_cap'], 1 ), number_format_i18n( (float) $summary['attendance_weight'], 1 ) ) ); ?></span>
				</div>
			<?php endif; ?>
			<?php if ( ! empty( $decimal_point_rows ) ) : ?>
				<div class="slms-results-weight-warning">
					<strong><?php esc_html_e( 'Decimal assignment max points detected.', 'simple-lms' ); ?></strong>
					<span><?php esc_html_e( 'Review these assignments because Results use the normalized whole-number denominator introduced in 3.4.0.', 'simple-lms' ); ?></span>
				</div>
			<?php endif; ?>
			<?php $this->render_assignment_weight_breakdown_panel( $assignment_breakdown ); ?>
		</div>
		<?php
	}

	private function render_assignment_weight_breakdown_panel( array $assignment_breakdown ) {
		?>
		<div class="slms-results-assignment-weight-breakdown">
			<div class="slms-results-assignment-weight-head">
				<strong><?php esc_html_e( 'Assignment Weight Breakdown', 'simple-lms' ); ?></strong>
				<span><?php esc_html_e( 'Assignments with 0% weight are shown for transparency but do not affect Results.', 'simple-lms' ); ?></span>
			</div>
			<?php if ( empty( $assignment_breakdown ) ) : ?>
				<p class="slms-field-help"><?php esc_html_e( 'No assignments are available for this subject yet.', 'simple-lms' ); ?></p>
			<?php else : ?>
				<div class="slms-results-assignment-weight-list">
					<?php foreach ( $assignment_breakdown as $row ) : ?>
						<div class="slms-results-assignment-weight-row <?php echo empty( $row['counts_toward_results'] ) ? 'is-muted' : ''; ?> <?php echo ! empty( $row['has_decimal_max_points'] ) ? 'has-warning' : ''; ?>">
							<div>
								<strong><?php echo esc_html( $row['title'] ); ?></strong>
								<span><?php echo esc_html( $row['counts_toward_results_label'] ); ?> · <?php echo esc_html( sprintf( __( 'Max points: %s', 'simple-lms' ), AssignmentPointPolicy::format_max_points( $row['original_points'] ) ) ); ?></span>
							</div>
							<b><?php echo esc_html( number_format_i18n( (float) $row['weight'], 1 ) ); ?>%</b>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_subject_results_staff_card( $subject_id, array $student, array $summary, array $snapshot ) {
		$student_id             = (int) ( $student['student_user_id'] ?? 0 );
		$student_name           = $student['student_name'] ?? __( 'Student', 'simple-lms' );
		$attendance_percentage  = number_format_i18n( (float) ( $summary['attendance_percentage'] ?? 0 ), 1 );
		$absence_count          = (int) ( $summary['absent_sessions'] ?? 0 );
		$leave_count            = (int) ( $summary['leave_sessions'] ?? 0 );
		$late_count             = (int) ( $summary['late_sessions'] ?? 0 );
		$profile                = $student_id ? (array) $this->profiles->get_profile( $student_id ) : array();
		$person_code            = $profile['person_code'] ?? '';
		$form_id                = 'slms-results-row-' . $subject_id . '-' . $student_id;
		$assignment_total_value      = number_format( (float) $snapshot['assignment_total'], 2, '.', '' );
		$assignment_calculated_value = number_format( (float) ( $snapshot['assignment_calculated_total'] ?? $snapshot['assignment_total'] ), 2, '.', '' );
		$assignment_score_value      = null !== ( $snapshot['assignment_score'] ?? null ) ? number_format( (float) $snapshot['assignment_score'], 2, '.', '' ) : '';
		$assignment_score_max        = number_format( (float) $snapshot['assignment_weight_total'], 2, '.', '' );
		$total_score_value           = $snapshot['can_show_total'] ? number_format_i18n( (float) $snapshot['total_score'], 2 ) : '-';
		?>
		<details class="slms-subject-item-accordion slms-subject-results-editor-card" data-slms-results-row data-slms-results-assignments-ready="<?php echo $snapshot['all_assignments_ready'] ? '1' : '0'; ?>" data-slms-results-requires-final-exam="<?php echo $snapshot['remaining_exam_weight'] > 0 ? '1' : '0'; ?>">
			<summary class="slms-subject-item-card">
				<span class="slms-subject-item-card-icon slms-subject-item-card-avatar" aria-hidden="true"><img class="slms-subject-item-card-avatar-img" src="<?php echo esc_url( $this->get_profile_photo_url( $student_id ) ); ?>" alt="" loading="lazy" decoding="async"></span>
				<div class="slms-subject-item-card-content">
					<div class="slms-subject-item-card-head">
						<div class="slms-subject-item-card-title">
							<strong><?php echo esc_html( $student_name ); ?></strong>
						</div>
					</div>
					<p class="slms-subject-item-card-summary"><?php echo esc_html( sprintf( __( '%1$s absent | %2$s leave | %3$s late | %4$s%% attendance', 'simple-lms' ), $absence_count, $leave_count, $late_count, $attendance_percentage ) ); ?></p>
					<div class="slms-subject-item-card-meta">
						<?php if ( $person_code ) : ?>
							<span><?php echo esc_html( $person_code ); ?></span>
						<?php endif; ?>
						<span><?php echo esc_html( sprintf( __( 'Assignments %s', 'simple-lms' ), number_format_i18n( (float) $snapshot['assignment_total'], 2 ) ) ); ?></span>
						<span><?php echo esc_html( sprintf( __( 'Total %s', 'simple-lms' ), $total_score_value ) ); ?></span>
						<span><?php echo esc_html( sprintf( __( 'Grade %s', 'simple-lms' ), $snapshot['can_show_grade'] ? $snapshot['letter_grade'] : '-' ) ); ?></span>
					</div>
				</div>
				<div class="slms-subject-item-card-actions">
					<span class="slms-subject-item-card-action">
						<span class="slms-subject-item-card-action-view"><span><?php esc_html_e( 'View', 'simple-lms' ); ?></span></span>
						<span class="slms-subject-item-card-action-close"><span><?php esc_html_e( 'Close', 'simple-lms' ); ?></span></span>
					</span>
				</div>
			</summary>
			<div class="slms-subject-item-accordion-body">
				<form id="<?php echo esc_attr( $form_id ); ?>" method="post" action="" class="slms-form-stack slms-subject-results-editor-form">
					<?php wp_nonce_field( 'slms_portal_action', 'slms_portal_nonce' ); ?>
					<input type="hidden" name="slms_portal_action" value="save_subject_results_row">
					<input type="hidden" name="subject_id" value="<?php echo esc_attr( $subject_id ); ?>">
					<input type="hidden" name="student_user_id" value="<?php echo esc_attr( $student_id ); ?>">
					<div class="slms-subject-results-score-grid">
						<div class="slms-subject-results-score-card">
							<span><?php esc_html_e( 'Attendance and participation', 'simple-lms' ); ?></span>
							<input class="slms-results-edit-input" type="number" step="0.1" min="0" max="<?php echo esc_attr( (string) $this->get_attendance_participation_weight() ); ?>" name="attendance_score" value="<?php echo esc_attr( number_format( (float) $snapshot['attendance_value'], 2, '.', '' ) ); ?>" disabled data-slms-results-attendance>
							<small><?php echo esc_html( sprintf( __( '%s%% attendance recorded', 'simple-lms' ), $attendance_percentage ) ); ?></small>
						</div>
						<div class="slms-subject-results-score-card slms-subject-results-assignment-edit-card">
							<span><?php esc_html_e( 'Assignments', 'simple-lms' ); ?></span>
							<input class="slms-results-edit-input" type="number" step="0.1" min="0" max="<?php echo esc_attr( $assignment_score_max ); ?>" name="assignment_score" value="<?php echo esc_attr( $assignment_score_value ); ?>" placeholder="<?php echo esc_attr( $assignment_calculated_value ); ?>" disabled data-slms-results-assignment-score data-slms-results-calculated-assignment-total="<?php echo esc_attr( $assignment_calculated_value ); ?>">
							<small><?php echo esc_html( null !== ( $snapshot['assignment_score'] ?? null ) ? sprintf( __( 'Manual score. Calculated from submissions: %s of %s.', 'simple-lms' ), number_format_i18n( (float) ( $snapshot['assignment_calculated_total'] ?? 0 ), 2 ), number_format_i18n( (float) $snapshot['assignment_weight_total'], 1 ) ) : sprintf( __( 'Blank uses calculated submissions: %1$s of %2$s.', 'simple-lms' ), number_format_i18n( (float) ( $snapshot['assignment_calculated_total'] ?? 0 ), 2 ), number_format_i18n( (float) $snapshot['assignment_weight_total'], 1 ) ) ); ?></small>
						</div>
						<div class="slms-subject-results-score-card">
							<span><?php esc_html_e( 'Final Exam', 'simple-lms' ); ?></span>
							<input class="slms-results-edit-input" type="number" step="0.1" min="0" max="<?php echo esc_attr( number_format( (float) $snapshot['remaining_exam_weight'], 2, '.', '' ) ); ?>" name="final_exam_score" value="<?php echo esc_attr( null !== $snapshot['final_exam_score'] ? number_format( (float) $snapshot['final_exam_score'], 2, '.', '' ) : '' ); ?>" disabled data-slms-results-final-exam>
							<small><?php echo esc_html( sprintf( __( 'Up to %s%% available', 'simple-lms' ), number_format_i18n( (float) $snapshot['remaining_exam_weight'], 1 ) ) ); ?></small>
						</div>
						<div class="slms-subject-results-score-card is-total">
							<span><?php esc_html_e( 'Total', 'simple-lms' ); ?></span>
							<strong data-slms-results-total><?php echo esc_html( $total_score_value ); ?></strong>
							<small><?php echo esc_html( $snapshot['can_show_total'] ? __( 'Final weighted course outcome', 'simple-lms' ) : __( 'Weighted course score pending', 'simple-lms' ) ); ?></small>
						</div>
						<div class="slms-subject-results-score-card">
							<span><?php esc_html_e( 'Grade', 'simple-lms' ); ?></span>
							<strong data-slms-results-grade><?php echo esc_html( $snapshot['can_show_grade'] ? $snapshot['letter_grade'] : '-' ); ?></strong>
							<small><?php echo esc_html( $snapshot['can_show_grade'] ? __( 'Released from the course total', 'simple-lms' ) : __( 'Available once final grading is complete', 'simple-lms' ) ); ?></small>
						</div>
					</div>
					<div class="slms-inline-actions slms-subject-results-editor-actions">
						<button class="slms-portal-button is-secondary slms-results-edit-button" type="button" data-slms-results-edit><?php esc_html_e( 'Edit Results', 'simple-lms' ); ?></button>
					</div>
				</form>
			</div>
		</details>
		<?php
	}

	private function render_attendance_current_session_tools( $subject_id, array $class_rows, $current_session_number, $plan_unit ) {
		if ( empty( $class_rows ) ) {
			return;
		}

		$current_session_number = absint( $current_session_number );
		$current_label          = $this->get_attendance_focus_session_label( $class_rows, $current_session_number );
		$focus_target          = $current_session_number ? 'slms-attendance-session-card-' . absint( $subject_id ) . '-' . $current_session_number : '';
		$unit_label            = 'classes' === sanitize_key( (string) $plan_unit ) ? __( 'Class', 'simple-lms' ) : __( 'Week', 'simple-lms' );
		?>
		<div class="slms-attendance-current-session-tools" data-slms-attendance-week-tools>
			<div class="slms-attendance-current-session-copy">
				<span class="slms-portal-kicker"><?php echo esc_html( sprintf( __( 'Current %s Focus', 'simple-lms' ), $unit_label ) ); ?></span>
				<strong><?php echo esc_html( $current_label ?: __( 'No attendance focus detected', 'simple-lms' ) ); ?></strong>
				<p><?php esc_html_e( 'Use this shortcut to jump directly to the attendance card lecturers are most likely to need today. You can still choose any past or future week manually.', 'simple-lms' ); ?></p>
			</div>
			<div class="slms-attendance-current-session-actions">
				<?php if ( $focus_target ) : ?>
					<button type="button" class="slms-portal-button" data-slms-attendance-jump data-slms-attendance-target="<?php echo esc_attr( $focus_target ); ?>"><?php echo esc_html( sprintf( __( 'Jump to %s', 'simple-lms' ), $current_label ) ); ?></button>
				<?php endif; ?>
				<label class="slms-attendance-week-select-label">
					<span><?php esc_html_e( 'Go to', 'simple-lms' ); ?></span>
					<select data-slms-attendance-session-select>
						<?php foreach ( $class_rows as $class_row ) : ?>
							<?php $target_id = 'slms-attendance-session-card-' . absint( $subject_id ) . '-' . absint( $class_row['number'] ); ?>
							<option value="<?php echo esc_attr( $target_id ); ?>" <?php selected( (int) $class_row['number'], $current_session_number ); ?>><?php echo esc_html( $class_row['label'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<button type="button" class="slms-portal-button is-secondary" data-slms-attendance-select-go><?php esc_html_e( 'Go', 'simple-lms' ); ?></button>
			</div>
		</div>
		<?php
	}

	private function get_attendance_focus_session_label( array $class_rows, $current_session_number ) {
		$current_session_number = absint( $current_session_number );

		foreach ( $class_rows as $class_row ) {
			if ( (int) ( $class_row['number'] ?? 0 ) === $current_session_number ) {
				return (string) ( $class_row['label'] ?? '' );
			}
		}

		return '';
	}

	private function get_attendance_focus_session_number( $attendance_dashboard, array $class_rows, $current_week_number = 0 ) {
		$available_numbers = array_values(
			array_filter(
				array_map(
					static function ( $class_row ) {
						return absint( $class_row['number'] ?? 0 );
					},
					$class_rows
				)
			)
		);

		if ( empty( $available_numbers ) ) {
			return 0;
		}

		$candidates = array( absint( $current_week_number ) );

		if ( is_array( $attendance_dashboard ) ) {
			$candidates[] = absint( $attendance_dashboard['next_session_number'] ?? 0 );
		}

		foreach ( $candidates as $candidate ) {
			if ( $candidate && in_array( $candidate, $available_numbers, true ) ) {
				return $candidate;
			}
		}

		foreach ( $class_rows as $class_row ) {
			$session = $class_row['session'] ?? null;

			if ( empty( $session ) || (int) ( $session['record_count'] ?? 0 ) < 1 ) {
				return absint( $class_row['number'] ?? 0 );
			}
		}

		return (int) end( $available_numbers );
	}

	private function render_subject_attendance_session_manage_card( $subject_id, array $class_row, $current_session_number = 0 ) {
		$session      = $class_row['session'];
		$session_date = $session['session_date'] ?? '';
		$modal_id     = $this->get_attendance_modal_id( $subject_id, (int) $class_row['number'] );
		$is_current   = $current_session_number && (int) $class_row['number'] === (int) $current_session_number;
		$is_incomplete = empty( $session ) || (int) ( $session['record_count'] ?? 0 ) < 1;
		$card_classes  = array( 'slms-subject-attendance-session-card', ! empty( $session ) ? 'is-registered-week' : 'is-unregistered-week' );

		if ( $is_current ) {
			$card_classes[] = 'is-current-attendance-session';
		}

		if ( $is_current && $is_incomplete ) {
			$card_classes[] = 'is-current-attendance-session-incomplete';
		}

		$meta_bits    = array(
			! empty( $session ) ? __( 'Register ready', 'simple-lms' ) : __( 'Not recorded yet', 'simple-lms' ),
			sprintf( __( 'P %1$d / L %2$d / Lv %3$d / A %4$d', 'simple-lms' ), (int) ( $session['present_count'] ?? 0 ), (int) ( $session['late_count'] ?? 0 ), (int) ( $session['leave_count'] ?? 0 ), (int) ( $session['absent_count'] ?? 0 ) ),
		);
		?>
		<button type="button" id="slms-attendance-session-card-<?php echo esc_attr( $subject_id ); ?>-<?php echo esc_attr( (string) $class_row['number'] ); ?>" class="<?php echo esc_attr( implode( ' ', $card_classes ) ); ?>" data-slms-modal-open="<?php echo esc_attr( $modal_id ); ?>" data-slms-attendance-session-card data-slms-attendance-session-number="<?php echo esc_attr( (string) $class_row['number'] ); ?>">
			<span class="slms-subject-item-card-icon" aria-hidden="true"><span class="dashicons dashicons-calendar-alt"></span></span>
			<?php if ( $is_current ) : ?>
				<span class="slms-current-attendance-badge"><?php echo esc_html( $is_incomplete ? __( 'Current - Needs Attendance', 'simple-lms' ) : __( 'Current', 'simple-lms' ) ); ?></span>
			<?php endif; ?>
			<span class="slms-subject-attendance-session-copy">
				<strong><?php echo esc_html( $class_row['label'] ); ?></strong>
				<span class="slms-subject-attendance-session-summary"><?php echo esc_html( $session_date ?: __( 'Choose a date and open the register when you are ready to take attendance.', 'simple-lms' ) ); ?></span>
				<span class="slms-subject-item-card-meta">
					<?php foreach ( $meta_bits as $meta_bit ) : ?>
						<span><?php echo esc_html( $meta_bit ); ?></span>
					<?php endforeach; ?>
				</span>
			</span>
			<span class="slms-subject-item-card-action"><?php echo esc_html( ! empty( $session ) ? __( 'Open Register', 'simple-lms' ) : __( 'Set Up Class', 'simple-lms' ) ); ?></span>
		</button>
		<?php
	}

	private function render_subject_attendance_session_student_card( array $class_row, $status ) {
		$session   = $class_row['session'];
		$meta_bits = array(
			sprintf( __( 'Recorded %s', 'simple-lms' ), ! empty( $session['session_date'] ) ? $session['session_date'] : __( 'Not yet', 'simple-lms' ) ),
			sprintf( __( 'Status: %s', 'simple-lms' ), ucfirst( (string) $status ) ),
		);
		?>
		<article class="slms-subject-attendance-session-card is-static">
			<span class="slms-subject-item-card-icon" aria-hidden="true"><span class="dashicons dashicons-calendar-alt"></span></span>
			<?php if ( $is_current ) : ?>
				<span class="slms-current-attendance-badge"><?php echo esc_html( $is_incomplete ? __( 'Current - Needs Attendance', 'simple-lms' ) : __( 'Current', 'simple-lms' ) ); ?></span>
			<?php endif; ?>
			<span class="slms-subject-attendance-session-copy">
				<strong><?php echo esc_html( $class_row['label'] ); ?></strong>
				<span class="slms-subject-attendance-session-summary"><?php echo esc_html( ! empty( $session['session_date'] ) ? $session['session_date'] : __( 'This class has not been recorded yet.', 'simple-lms' ) ); ?></span>
				<span class="slms-subject-item-card-meta">
					<?php foreach ( $meta_bits as $meta_bit ) : ?>
						<span><?php echo esc_html( $meta_bit ); ?></span>
					<?php endforeach; ?>
				</span>
			</span>
			<span class="slms-subject-item-card-action"><?php echo esc_html( ucfirst( (string) $status ) ); ?></span>
		</article>
		<?php
	}


	private function can_use_card_issuer( $role ) {
		return 'administrator' === sanitize_key( (string) $role ) && current_user_can( 'manage_options' );
	}

	private function can_use_assignment_batch_download( $role = '' ) {
		$role = $role ? sanitize_key( (string) $role ) : RoleManager::get_primary_role( get_current_user_id() );

		return in_array( $role, array( 'administrator', 'officer', 'lecturer' ), true );
	}

	private function is_leave_application_enabled() {
		return (bool) apply_filters( 'slms_leave_application_enabled', false );
	}

	private function render_card_issuer_page( $page_url ) {
		if ( ! $this->can_use_card_issuer( RoleManager::get_primary_role( get_current_user_id() ) ) ) {
			echo '<section class="slms-portal-panel slms-flow-section"><p>' . esc_html__( 'You do not have permission to manage ID cards.', 'simple-lms' ) . '</p></section>';
			return;
		}

		$tab = sanitize_key( $_GET['issuer_tab'] ?? 'students' );
		if ( ! in_array( $tab, array( 'students', 'staff' ), true ) ) {
			$tab = 'students';
		}

		$user_id = absint( $_GET['issuer_user_id'] ?? 0 );
		?>
		<section class="slms-portal-panel slms-flow-section slms-card-issuer-panel">
			<div class="slms-section-heading">
				<div>
					<p class="slms-field-help" style="text-transform:uppercase;letter-spacing:.08em;margin:0 0 4px;"><?php esc_html_e( 'Administrator Tools', 'simple-lms' ); ?></p>
					<h2><?php esc_html_e( 'Card Issuer', 'simple-lms' ); ?></h2>
					<p class="slms-field-help"><?php esc_html_e( 'Issue, regenerate, and preview UGP ID cards for Simple LMS students and staff.', 'simple-lms' ); ?></p>
				</div>
			</div>
			<?php if ( $user_id ) : ?>
				<?php $this->render_card_issuer_preview( $page_url, $user_id, $tab ); ?>
			<?php else : ?>
				<nav class="slms-tab-strip" style="margin:0 0 16px;">
					<a class="slms-tab-link <?php echo 'students' === $tab ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'slms_view' => 'card-issuer', 'issuer_tab' => 'students' ), $page_url ) ); ?>"><span class="dashicons dashicons-welcome-learn-more" aria-hidden="true"></span><span><?php esc_html_e( 'Students', 'simple-lms' ); ?></span></a>
					<a class="slms-tab-link <?php echo 'staff' === $tab ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'slms_view' => 'card-issuer', 'issuer_tab' => 'staff' ), $page_url ) ); ?>"><span class="dashicons dashicons-businessperson" aria-hidden="true"></span><span><?php esc_html_e( 'Staff', 'simple-lms' ); ?></span></a>
				</nav>
				<?php $this->render_card_issuer_list( $page_url, 'staff' === $tab ? 'staff' : 'student' ); ?>
			<?php endif; ?>
		</section>
		<?php
	}

	private function render_card_issuer_list( $page_url, $person_type ) {
		$is_staff   = 'staff' === $person_type;
		$tab        = $is_staff ? 'staff' : 'students';
		$page       = max( 1, absint( $_GET['issuer_paged'] ?? 1 ) );
		$per_page   = 30;
		$search     = sanitize_text_field( wp_unslash( $_GET['issuer_s'] ?? '' ) );
		$program_id = absint( $_GET['program_id'] ?? 0 );
		$intake_id  = absint( $_GET['intake_term_id'] ?? 0 );
		$year       = sanitize_text_field( wp_unslash( $_GET['intake_academic_year'] ?? '' ) );
		$role       = sanitize_key( $_GET['role'] ?? '' );
		$department = sanitize_text_field( wp_unslash( $_GET['department'] ?? '' ) );
		$orderby    = sanitize_key( $_GET['orderby'] ?? ( $is_staff ? 'department' : 'program_intake' ) );

		$args = array(
			'person_type'          => $person_type,
			'search'               => $search,
			'page'                 => $page,
			'per_page'             => $per_page,
			'orderby'              => $orderby,
			'program_id'           => $program_id,
			'intake_term_id'       => $intake_id,
			'intake_academic_year' => $year,
			'role'                 => $role,
			'department'           => $department,
		);

		$profiles      = $this->profiles->list_profiles( $args );
		$total         = $this->profiles->count_profiles_matching( $args );
		$pages         = max( 1, (int) ceil( $total / $per_page ) );
		$batch_filters = array(
			'person_type'          => $person_type,
			'search'               => $search,
			'program_id'           => $program_id,
			'intake_term_id'       => $intake_id,
			'intake_academic_year' => $year,
			'role'                 => $role,
			'department'           => $department,
			'orderby'              => $orderby,
		);
		$batch_label   = $is_staff ? __( 'Download Issued Staff Cards Matching Filters', 'simple-lms' ) : __( 'Download Issued Student Cards Matching Filters', 'simple-lms' );
		?>
		<form method="get" class="slms-id-card-filters slms-form-stack" style="margin:0 0 16px;">
			<input type="hidden" name="slms_view" value="card-issuer">
			<input type="hidden" name="issuer_tab" value="<?php echo esc_attr( $tab ); ?>">
			<div class="slms-form-split" style="align-items:flex-end;">
				<label><span><?php esc_html_e( 'Search', 'simple-lms' ); ?></span><input type="search" name="issuer_s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Name, ID, email, department', 'simple-lms' ); ?>"></label>
				<?php if ( $is_staff ) : ?>
					<label><span><?php esc_html_e( 'Role', 'simple-lms' ); ?></span><?php $this->render_card_issuer_role_filter( $role ); ?></label>
					<label><span><?php esc_html_e( 'Department', 'simple-lms' ); ?></span><?php $this->render_card_issuer_department_filter( $department ); ?></label>
					<label><span><?php esc_html_e( 'Sort', 'simple-lms' ); ?></span><select name="orderby"><option value="department" <?php selected( $orderby, 'department' ); ?>><?php esc_html_e( 'Department', 'simple-lms' ); ?></option><option value="role" <?php selected( $orderby, 'role' ); ?>><?php esc_html_e( 'Role', 'simple-lms' ); ?></option><option value="name" <?php selected( $orderby, 'name' ); ?>><?php esc_html_e( 'Name', 'simple-lms' ); ?></option></select></label>
				<?php else : ?>
					<label><span><?php esc_html_e( 'Program', 'simple-lms' ); ?></span><?php $this->render_card_issuer_program_filter( $program_id ); ?></label>
					<?php $this->render_card_issuer_intake_filter( $intake_id, $year ); ?>
					<label><span><?php esc_html_e( 'Sort', 'simple-lms' ); ?></span><select name="orderby"><option value="program_intake" <?php selected( $orderby, 'program_intake' ); ?>><?php esc_html_e( 'Program then Intake', 'simple-lms' ); ?></option><option value="intake_program" <?php selected( $orderby, 'intake_program' ); ?>><?php esc_html_e( 'Intake then Program', 'simple-lms' ); ?></option><option value="name" <?php selected( $orderby, 'name' ); ?>><?php esc_html_e( 'Name', 'simple-lms' ); ?></option></select></label>
				<?php endif; ?>
				<button class="slms-portal-button is-secondary" type="submit"><?php esc_html_e( 'Filter', 'simple-lms' ); ?></button>
			</div>
		</form>
		<form method="post" action="">
			<?php wp_nonce_field( 'slms_portal_action', 'slms_portal_nonce' ); ?>
			<input type="hidden" name="slms_portal_action" value="slms_batch_issue_cards">
			<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:10px;flex-wrap:wrap;">
				<p class="slms-field-help" style="margin:0;"><?php echo esc_html( sprintf( __( '%d profile(s) found.', 'simple-lms' ), $total ) ); ?></p>
				<div class="slms-id-card-action-row" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
					<button type="button" class="slms-portal-button is-secondary ugp-id-batch-download" data-ugp-id-batch-download data-filters="<?php echo esc_attr( wp_json_encode( $batch_filters ) ); ?>"><?php echo esc_html( $batch_label ); ?></button>
					<button type="submit" class="slms-portal-button"><?php esc_html_e( 'Issue Selected', 'simple-lms' ); ?></button>
				</div>
			</div>
			<div class="slms-card-issuer-table-scroll" style="overflow:auto;">
				<table class="slms-card-issuer-table" style="width:100%;border-collapse:collapse;background:#fff;">
					<thead><tr><th style="text-align:left;padding:10px;border-bottom:1px solid #e5e7eb;"><input type="checkbox" data-slms-card-issuer-select-all></th><th style="text-align:left;padding:10px;border-bottom:1px solid #e5e7eb;"><?php esc_html_e( 'Name', 'simple-lms' ); ?></th><th style="text-align:left;padding:10px;border-bottom:1px solid #e5e7eb;"><?php esc_html_e( 'ID', 'simple-lms' ); ?></th><th style="text-align:left;padding:10px;border-bottom:1px solid #e5e7eb;"><?php echo $is_staff ? esc_html__( 'Role / Department', 'simple-lms' ) : esc_html__( 'Program / Intake', 'simple-lms' ); ?></th><th style="text-align:left;padding:10px;border-bottom:1px solid #e5e7eb;"><?php esc_html_e( 'Card', 'simple-lms' ); ?></th><th style="text-align:left;padding:10px;border-bottom:1px solid #e5e7eb;"><?php esc_html_e( 'Actions', 'simple-lms' ); ?></th></tr></thead>
					<tbody>
					<?php if ( empty( $profiles ) ) : ?>
						<tr><td colspan="6" style="padding:12px;"><?php esc_html_e( 'No matching profiles found.', 'simple-lms' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $profiles as $profile ) : ?>
							<?php $this->render_card_issuer_profile_row( $page_url, $profile, $is_staff ); ?>
						<?php endforeach; ?>
					<?php endif; ?>
					</tbody>
				</table>
			</div>
		</form>
		<?php if ( $pages > 1 ) : ?>
			<nav class="slms-card-issuer-pages" style="margin-top:16px;">
			<?php
			echo wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'issuer_paged', '%#%' ), 'format' => '', 'current' => $page, 'total' => $pages, 'prev_text' => '&laquo;', 'next_text' => '&raquo;' ) ) );
			?>
			</nav>
		<?php endif; ?>
		<?php
	}

	private function render_card_issuer_profile_row( $page_url, array $profile, $is_staff ) {
		$user_id = absint( $profile['user_id'] ?? 0 );
		$role    = RoleManager::get_primary_role( $user_id );
		$person_for_card_lookup = $this->id_cards->get_person( $user_id, $profile, $role, '' );
		$card    = $person_for_card_lookup ? $this->id_cards->get_latest_card_for_person_data( $person_for_card_lookup, true ) : null;
		$program = ! empty( $profile['program_id'] ) ? $this->get_program_name( (int) $profile['program_id'] ) : '';
		$intake  = ! empty( $profile['intake_term_id'] ) ? $this->get_term_name( (int) $profile['intake_term_id'] ) : '';
		$preview_url = add_query_arg( array( 'slms_view' => 'card-issuer', 'issuer_tab' => $is_staff ? 'staff' : 'students', 'issuer_user_id' => $user_id ), $page_url );
		?>
		<tr>
			<td data-label="<?php esc_attr_e( 'Select', 'simple-lms' ); ?>" style="padding:10px;border-bottom:1px solid #eef2f7;"><input type="checkbox" name="user_ids[]" value="<?php echo esc_attr( $user_id ); ?>"></td>
			<td data-label="<?php esc_attr_e( 'Name', 'simple-lms' ); ?>" style="padding:10px;border-bottom:1px solid #eef2f7;"><strong><?php echo $this->get_person_avatar_name_markup( $user_id, $profile['display_name'] ?? '', 'is-compact' ); ?></strong><br><span class="slms-field-help"><?php echo esc_html( $profile['user_email'] ?? '' ); ?></span></td>
			<td data-label="<?php esc_attr_e( 'ID', 'simple-lms' ); ?>" style="padding:10px;border-bottom:1px solid #eef2f7;"><?php echo esc_html( $profile['person_code'] ?? '-' ); ?></td>
			<td data-label="<?php echo $is_staff ? esc_attr__( 'Role / Department', 'simple-lms' ) : esc_attr__( 'Program / Intake', 'simple-lms' ); ?>" style="padding:10px;border-bottom:1px solid #eef2f7;">
				<?php if ( $is_staff ) : ?>
					<strong><?php echo esc_html( $profile['position_title'] ?: RoleManager::get_role_label( $role ) ); ?></strong><br><span class="slms-field-help"><?php echo esc_html( $profile['department'] ?: '-' ); ?></span>
				<?php else : ?>
					<strong><?php echo esc_html( $program ?: '-' ); ?></strong><br><span class="slms-field-help"><?php echo esc_html( $intake ?: '-' ); ?></span>
				<?php endif; ?>
			</td>
			<td data-label="<?php esc_attr_e( 'Card', 'simple-lms' ); ?>" style="padding:10px;border-bottom:1px solid #eef2f7;"><?php echo $card ? esc_html( ucfirst( $card['status'] ) . ' - ' . ( $card['issued_at'] ?? '' ) ) : esc_html__( 'Not issued', 'simple-lms' ); ?></td>
			<td data-label="<?php esc_attr_e( 'Actions', 'simple-lms' ); ?>" style="padding:10px;border-bottom:1px solid #eef2f7;white-space:nowrap;"><a class="slms-portal-button is-secondary" href="<?php echo esc_url( $preview_url ); ?>"><?php esc_html_e( 'Preview', 'simple-lms' ); ?></a> <button type="submit" class="slms-portal-button is-secondary" name="user_ids[]" value="<?php echo esc_attr( $user_id ); ?>"><?php echo $card ? esc_html__( 'Regenerate', 'simple-lms' ) : esc_html__( 'Issue', 'simple-lms' ); ?></button></td>
		</tr>
		<?php
	}

	private function render_card_issuer_preview( $page_url, $user_id, $tab ) {
		$profile = $this->profiles->get_profile( $user_id );
		if ( ! $profile ) {
			echo '<p>' . esc_html__( 'Profile not found.', 'simple-lms' ) . '</p>';
			return;
		}
		$role   = RoleManager::get_primary_role( $user_id );
		$person = $this->id_cards->get_person( $user_id, $profile, $role, '' );
		$card   = $person ? $this->id_cards->get_latest_card_for_person_data( $person, true ) : null;
		?>
		<?php $photo_url = $this->get_profile_photo_url( $user_id ); ?>
		<p><a class="slms-portal-button is-secondary" href="<?php echo esc_url( add_query_arg( array( 'slms_view' => 'card-issuer', 'issuer_tab' => $tab ), $page_url ) ); ?>">&larr; <?php esc_html_e( 'Back to Card Issuer', 'simple-lms' ); ?></a></p>
		<div class="slms-section-heading" style="align-items:center;">
			<div><h3 style="margin:0 0 4px;"><?php echo $this->get_person_avatar_name_markup( $user_id, $profile['display_name'] ?? '', 'is-large' ); ?></h3><p class="slms-field-help" style="margin:0;"><?php echo esc_html( $profile['person_code'] ?? '' ); ?></p></div>
			<div class="slms-row-actions" style="justify-content:flex-end;">
				<button type="button" class="slms-portal-button is-secondary" data-slms-open-photo-modal><?php esc_html_e( 'Upload / Edit Photo', 'simple-lms' ); ?></button>
				<form method="post" action="">
					<?php wp_nonce_field( 'slms_portal_action', 'slms_portal_nonce' ); ?>
					<input type="hidden" name="slms_portal_action" value="slms_issue_card">
					<input type="hidden" name="user_id" value="<?php echo esc_attr( $user_id ); ?>">
					<input type="hidden" name="issuer_tab" value="<?php echo esc_attr( $tab ); ?>">
					<input type="hidden" name="issuer_user_id" value="<?php echo esc_attr( $user_id ); ?>">
					<button type="submit" class="slms-portal-button"><?php echo $card ? esc_html__( 'Regenerate Card', 'simple-lms' ) : esc_html__( 'Issue Card', 'simple-lms' ); ?></button>
				</form>
			</div>
		</div>
		<?php
		if ( $person ) {
			if ( ! $card ) {
				echo '<p class="slms-field-help">' . esc_html__( 'Draft preview only. This ID card has not been issued yet.', 'simple-lms' ) . '</p>';
			}
			echo $this->id_cards->renderer()->render_card_set( $person, $card, array( 'uid' => 'slms-card-issuer-' . $user_id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} else {
			echo '<p class="slms-field-help">' . esc_html__( 'Unable to build a preview for this profile.', 'simple-lms' ) . '</p>';
		}
		?>
		<div class="slms-photo-modal" aria-hidden="true" id="slms-photo-modal">
			<div class="slms-photo-modal-backdrop" data-slms-close-photo-modal></div>
			<div class="slms-photo-modal-card" role="dialog" aria-modal="true" aria-labelledby="slms-photo-modal-title">
				<button type="button" class="slms-photo-modal-close" data-slms-close-photo-modal aria-label="<?php esc_attr_e( 'Close photo dialog', 'simple-lms' ); ?>">&times;</button>
				<h3 id="slms-photo-modal-title"><?php esc_html_e( 'Update ID photo', 'simple-lms' ); ?></h3>
				<p class="slms-field-help"><?php esc_html_e( 'Upload a student or staff ID photo up to 10MB. The full photo is saved by default; use Crop & Save only when you need manual framing.', 'simple-lms' ); ?></p>
				<p class="slms-photo-modal-error" data-slms-photo-error hidden></p>
				<form method="post" action="" enctype="multipart/form-data" class="slms-form-stack" data-slms-photo-form>
					<?php wp_nonce_field( 'slms_portal_action', 'slms_portal_nonce' ); ?>
					<input type="hidden" name="slms_portal_action" value="save_profile_photo">
					<input type="hidden" name="slms_profile_photo_user_id" value="<?php echo esc_attr( $user_id ); ?>">
					<input type="hidden" name="slms_photo_return_view" value="card-issuer">
					<input type="hidden" name="issuer_tab" value="<?php echo esc_attr( $tab ); ?>">
					<input type="hidden" name="issuer_user_id" value="<?php echo esc_attr( $user_id ); ?>">
					<input type="hidden" name="slms_photo_mode" value="original">
					<input type="hidden" name="slms_profile_photo_delete" value="0">
					<div class="slms-photo-modal-body">
						<div class="slms-photo-static-preview" data-slms-static-preview>
							<img src="<?php echo esc_url( $photo_url ); ?>" alt="<?php esc_attr_e( 'Current ID photo', 'simple-lms' ); ?>" data-slms-static-preview-image>
						</div>
						<div class="slms-photo-cropper-wrap" data-slms-crop-wrap hidden>
							<img src="" alt="" data-slms-crop-source>
						</div>
						<div class="slms-photo-modal-controls">
							<label class="slms-portal-button is-secondary slms-file-button">
								<?php esc_html_e( 'Choose Photo', 'simple-lms' ); ?>
								<input type="file" name="slms_profile_photo" accept="image/*" data-slms-photo-input>
							</label>
							<button type="submit" class="slms-portal-button" data-slms-save-original><?php esc_html_e( 'Save Photo', 'simple-lms' ); ?></button>
							<button type="submit" class="slms-portal-button is-secondary" data-slms-save-cropped><?php esc_html_e( 'Crop & Save', 'simple-lms' ); ?></button>
							<button type="submit" class="slms-portal-button is-danger" data-slms-remove-photo><?php esc_html_e( 'Remove Photo', 'simple-lms' ); ?></button>
						</div>
					</div>
				</form>
			</div>
		</div>
		<?php
	}

	private function render_card_issuer_role_filter( $selected ) {
		$roles = array( 'administrator', 'officer', 'lecturer', RoleManager::ROLE_STAFF );
		echo '<select name="role"><option value="">' . esc_html__( 'All roles', 'simple-lms' ) . '</option>';
		foreach ( $roles as $role ) {
			echo '<option value="' . esc_attr( $role ) . '" ' . selected( $selected, $role, false ) . '>' . esc_html( RoleManager::get_role_label( $role ) ) . '</option>';
		}
		echo '</select>';
	}

	private function render_card_issuer_department_filter( $selected ) {
		global $wpdb;
		$departments = $wpdb->get_col( "SELECT DISTINCT department FROM " . Schema::table( 'user_profiles' ) . " WHERE person_type = 'staff' AND department IS NOT NULL AND department <> '' ORDER BY department ASC" );
		echo '<select name="department"><option value="">' . esc_html__( 'All departments', 'simple-lms' ) . '</option>';
		foreach ( $departments as $department ) {
			echo '<option value="' . esc_attr( $department ) . '" ' . selected( $selected, $department, false ) . '>' . esc_html( $department ) . '</option>';
		}
		echo '</select>';
	}

	private function render_card_issuer_program_filter( $selected ) {
		$programs = get_terms( array( 'taxonomy' => 'slms_program', 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC' ) );
		echo '<select name="program_id"><option value="0">' . esc_html__( 'All programs', 'simple-lms' ) . '</option>';
		if ( ! is_wp_error( $programs ) ) {
			foreach ( $programs as $program ) {
				echo '<option value="' . esc_attr( $program->term_id ) . '" ' . selected( $selected, $program->term_id, false ) . '>' . esc_html( $program->name ) . '</option>';
			}
		}
		echo '</select>';
	}

	private function render_card_issuer_intake_filter( $selected_id, $selected_year ) {
		global $wpdb;
		$terms = $wpdb->get_results( 'SELECT id, name, academic_year FROM ' . Schema::table( 'terms' ) . ' ORDER BY academic_year DESC, sort_order ASC, name ASC', ARRAY_A );
		$years = $wpdb->get_col( 'SELECT DISTINCT academic_year FROM ' . Schema::table( 'terms' ) . " WHERE academic_year <> '' ORDER BY academic_year DESC" );
		echo '<label><span>' . esc_html__( 'Intake Term', 'simple-lms' ) . '</span><select name="intake_term_id"><option value="0">' . esc_html__( 'All intake terms', 'simple-lms' ) . '</option>';
		foreach ( $terms as $term ) {
			echo '<option value="' . esc_attr( $term['id'] ) . '" ' . selected( $selected_id, $term['id'], false ) . '>' . esc_html( $term['name'] . ' - ' . $term['academic_year'] ) . '</option>';
		}
		echo '</select></label><label><span>' . esc_html__( 'Intake Year', 'simple-lms' ) . '</span><select name="intake_academic_year"><option value="">' . esc_html__( 'All intake years', 'simple-lms' ) . '</option>';
		foreach ( $years as $year ) {
			echo '<option value="' . esc_attr( $year ) . '" ' . selected( $selected_year, $year, false ) . '>' . esc_html( $year ) . '</option>';
		}
		echo '</select></label>';
	}

	public function handle_action( $page_url ) {
		if ( empty( $_POST['slms_portal_action'] ) || empty( $_POST['slms_portal_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['slms_portal_nonce'] ) ), 'slms_portal_action' ) ) {
			return;
		}

		$action     = sanitize_key( wp_unslash( $_POST['slms_portal_action'] ) );
		$user_id    = get_current_user_id();
		$role       = RoleManager::get_primary_role( $user_id );
		$requires_password_change = $this->user_requires_password_change( $user_id );
		$subject_id = absint( $_POST['subject_id'] ?? 0 );

		if ( $requires_password_change && ! in_array( $action, array( 'change_password', 'toggle_greyscale_mode' ), true ) ) {
			$this->redirect_with_notice(
				$page_url,
				new \WP_Error( 'slms_password_change_required', __( 'Please change your password before using the rest of the LMS portal.', 'simple-lms' ) ),
				'',
				0,
				'materials',
				'security'
			);
		}

		switch ( $action ) {
			case 'create_email_campaign':
				$intent = sanitize_key( wp_unslash( $_POST['email_campaign_intent'] ?? 'send' ) );
				if ( 'test' === $intent ) {
					$result  = $this->email_campaigns->send_test(
						sanitize_text_field( wp_unslash( $_POST['email_campaign_subject'] ?? '' ) ),
						wp_kses_post( wp_unslash( $_POST['email_campaign_message'] ?? '' ) ),
						$user_id
					);
					$message = __( 'Test email sent to your administrator email address.', 'simple-lms' );
				} else {
					if ( empty( $_POST['email_campaign_confirm'] ) ) {
						$result = new \WP_Error( 'slms_email_campaign_confirmation_required', __( 'Confirm that you reviewed the email and recipient groups before sending.', 'simple-lms' ) );
					} else {
						$result = $this->create_email_campaign_from_request( $user_id, $role );
					}
					$message = is_array( $result )
						? sprintf(
							/* translators: 1: queued email count, 2: skipped recipient count */
							__( 'Email campaign queued for %1$d recipients. %2$d recipients were skipped.', 'simple-lms' ),
							(int) ( $result['queued_count'] ?? 0 ),
							(int) ( $result['skipped_count'] ?? 0 )
						)
						: __( 'Email campaign queued successfully.', 'simple-lms' );
				}
				$this->redirect_with_notice( $page_url, $result, $message, 0, 'materials', 'email-center' );
				break;
			case 'cancel_email_campaign':
				$result = $this->email_campaigns->cancel_campaign( absint( $_POST['email_campaign_id'] ?? 0 ), $user_id );
				$this->redirect_with_notice( $page_url, $result, __( 'Pending campaign emails cancelled.', 'simple-lms' ), 0, 'materials', 'email-center' );
				break;
			case 'retry_email_campaign':
				$result = $this->email_campaigns->retry_failed( absint( $_POST['email_campaign_id'] ?? 0 ), $user_id );
				$this->redirect_with_notice( $page_url, $result, __( 'Failed campaign emails queued for retry.', 'simple-lms' ), 0, 'materials', 'email-center' );
				break;
			case 'create_broadcast':
				$result = $this->create_broadcast_from_request( $user_id, $role );
				$message = __( 'Broadcast published successfully.', 'simple-lms' );

				if ( is_array( $result ) && isset( $result['recipient_count'] ) ) {
					$message = sprintf(
						/* translators: %d: number of in-app broadcast recipients */
						_n(
							'Broadcast published successfully to %d recipient.',
							'Broadcast published successfully to %d recipients.',
							(int) $result['recipient_count'],
							'simple-lms'
						),
						(int) $result['recipient_count']
					);
				}

				$this->redirect_with_notice( $page_url, $result, $message, 0, 'materials', 'broadcast-center' );
				break;
			case 'archive_broadcast':
				$result = $this->broadcasts->archive_broadcast( absint( $_POST['broadcast_id'] ?? 0 ), $user_id );
				$this->redirect_with_notice( $page_url, $result, __( 'Broadcast archived successfully.', 'simple-lms' ), 0, 'materials', 'broadcast-center' );
				break;
			case 'delete_broadcast':
				$result = $this->broadcasts->delete_broadcast( absint( $_POST['broadcast_id'] ?? 0 ), $user_id );
				$this->redirect_with_notice( $page_url, $result, __( 'Broadcast deleted successfully.', 'simple-lms' ), 0, 'materials', 'broadcast-center' );
				break;
			case 'slms_issue_card':
				if ( ! $this->can_use_card_issuer( $role ) ) {
					$this->redirect_with_notice( $page_url, new \WP_Error( 'slms_card_issuer_denied', __( 'You do not have permission to issue ID cards.', 'simple-lms' ) ), '', 0, 'materials', 'dashboard' );
				}
				$target_user_id = absint( $_POST['user_id'] ?? 0 );
				$result = $this->id_cards->issue_user_card( $target_user_id, $user_id );
				$this->redirect_with_notice( $page_url, $result, __( 'ID card issued or regenerated successfully.', 'simple-lms' ), 0, 'materials', 'card-issuer' );
				break;
			case 'slms_batch_issue_cards':
				if ( ! $this->can_use_card_issuer( $role ) ) {
					$this->redirect_with_notice( $page_url, new \WP_Error( 'slms_card_issuer_denied', __( 'You do not have permission to issue ID cards.', 'simple-lms' ) ), '', 0, 'materials', 'dashboard' );
				}
				$user_ids = isset( $_POST['user_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['user_ids'] ) ) : array();
				$issued = 0;
				$failed = 0;
				foreach ( array_filter( $user_ids ) as $target_user_id ) {
					$result = $this->id_cards->issue_user_card( $target_user_id, $user_id );
					if ( is_wp_error( $result ) ) {
						$failed++;
					} else {
						$issued++;
					}
				}
				$this->redirect_with_notice( $page_url, array( 'created' => $issued, 'failed' => $failed ), __( 'Batch card issuing completed.', 'simple-lms' ), 0, 'materials', 'card-issuer' );
				break;
			case 'slms_revoke_card':
				if ( ! $this->can_use_card_issuer( $role ) ) {
					$this->redirect_with_notice( $page_url, new \WP_Error( 'slms_card_issuer_denied', __( 'You do not have permission to revoke ID cards.', 'simple-lms' ) ), '', 0, 'materials', 'dashboard' );
				}
				$card_id = absint( $_POST['card_id'] ?? 0 );
				$result = $this->id_cards->repository()->revoke_card( $card_id, $user_id );
				$this->redirect_with_notice( $page_url, $result, __( 'ID card revoked successfully.', 'simple-lms' ), 0, 'materials', 'card-issuer' );
				break;
			case 'create_material':
				$result = $this->create_material( $subject_id, $role, $user_id );
				$this->redirect_with_notice( $page_url, $result, __( 'Material published successfully.', 'simple-lms' ), $subject_id, 'materials' );
				break;
			case 'update_material':
				$result = $this->update_subject_item( 'materials', $subject_id, $role, $user_id );
				$this->redirect_with_notice( $page_url, $result, __( 'Material updated successfully.', 'simple-lms' ), $subject_id, 'materials', '', absint( $_POST['item_id'] ?? 0 ) );
				break;
			case 'toggle_material_visibility':
				$item_tab = sanitize_key( wp_unslash( $_POST['item_tab'] ?? 'materials' ) );
				$result   = $this->toggle_material_visibility( $subject_id, $role );
				if ( is_wp_error( $result ) ) {
					$this->redirect_with_notice( $page_url, $result, '', $subject_id, $item_tab );
				}

				wp_safe_redirect(
					add_query_arg(
						array_filter(
							array(
								'slms_view'      => 'subject',
								'slms_subject'   => $subject_id,
								'tab'            => $item_tab,
								'slms_open_item' => ! empty( $_POST['slms_keep_open'] ) ? absint( $_POST['item_id'] ?? 0 ) : 0,
							)
						),
						remove_query_arg( array( 'slms_item', 'slms_open_item' ), $page_url )
					)
				);
				exit;
			case 'toggle_greyscale_mode':
				$this->set_user_greyscale_preference( $user_id, ! empty( $_POST['slms_greyscale_enabled'] ) );
				wp_safe_redirect( $this->view_url( $page_url, sanitize_key( wp_unslash( $_POST['slms_return_view'] ?? 'dashboard' ) ) ) );
				exit;
			case 'save_portal_header_image':
				$result = $this->save_portal_header_image( $role );
				$this->redirect_with_notice(
					$page_url,
					$result,
					__( 'LMS header image updated successfully.', 'simple-lms' ),
					0,
					'materials',
					sanitize_key( wp_unslash( $_POST['slms_header_view'] ?? 'dashboard' ) )
				);
				break;
			case 'save_subject_header_image':
				$result = $this->save_subject_header_image( $subject_id, $role );
				$this->redirect_with_notice(
					$page_url,
					$result,
					__( 'Subject header image updated successfully.', 'simple-lms' ),
					$subject_id,
					sanitize_key( wp_unslash( $_POST['slms_header_active_tab'] ?? 'materials' ) )
				);
				break;
			case 'delete_material':
				$result = $this->delete_subject_item( 'materials', $subject_id, $role );
				$this->redirect_with_notice( $page_url, $result, __( 'Material deleted successfully.', 'simple-lms' ), $subject_id, 'materials' );
				break;
			case 'create_assignment':
				$result = $this->create_assignment( $subject_id, $role, $user_id );
				$this->redirect_with_notice( $page_url, $result, __( 'Assignment published successfully.', 'simple-lms' ), $subject_id, 'assignments' );
				break;
			case 'update_assignment':
				$result = $this->update_subject_item( 'assignments', $subject_id, $role, $user_id );
				$this->redirect_with_notice( $page_url, $result, __( 'Assignment updated successfully.', 'simple-lms' ), $subject_id, 'assignments', '', absint( $_POST['item_id'] ?? 0 ) );
				break;
			case 'delete_assignment':
				$result = $this->delete_subject_item( 'assignments', $subject_id, $role );
				$this->redirect_with_notice( $page_url, $result, __( 'Assignment deleted successfully.', 'simple-lms' ), $subject_id, 'assignments' );
				break;
			case 'delete_subject_attachment':
				$item_tab = sanitize_key( wp_unslash( $_POST['item_tab'] ?? 'materials' ) );
				$result   = $this->delete_subject_item_attachment( $item_tab, $subject_id, $role );
				$this->redirect_with_notice( $page_url, $result, __( 'File deleted successfully.', 'simple-lms' ), $subject_id, $item_tab, '', absint( $_POST['item_id'] ?? 0 ) );
				break;
			case 'submit_assignment':
				$result = $this->submit_assignment( $user_id );
				$this->redirect_with_notice( $page_url, $result, __( 'Assignment submitted successfully.', 'simple-lms' ), $subject_id, 'assignments', '', absint( $_POST['assignment_id'] ?? 0 ) );
				break;
			case 'download_assignment_submission':
				$this->download_assignment_submission( $subject_id, $role, $user_id );
				break;
			case 'download_assignment_submissions_zip':
				$this->download_assignment_submissions_zip( $subject_id, $role, $user_id );
				break;
			case 'grade_assignment_submission':
				$result = $this->grade_assignment_submission( $subject_id, $role, $user_id );
				$this->redirect_with_notice( $page_url, $result, __( 'Submission feedback saved successfully.', 'simple-lms' ), $subject_id, 'assignments', '', absint( $_POST['assignment_id'] ?? 0 ) );
				break;
			case 'enroll_codes':
				$result = $this->enroll_by_codes( $subject_id, $role, $user_id );
				$this->redirect_with_notice( $page_url, $result, __( 'Students enrolled successfully.', 'simple-lms' ), $subject_id, 'people' );
				break;
			case 'enroll_import':
				$result = $this->enroll_by_import( $subject_id, $role, $user_id );
				$this->redirect_with_notice( $page_url, $result, __( 'Enrollment import completed.', 'simple-lms' ), $subject_id, 'people' );
				break;
			case 'enroll_auditors':
				$result = $this->enroll_auditors_by_codes( $subject_id, $role, $user_id );
				$this->redirect_with_notice( $page_url, $result, __( 'Auditors enrolled successfully.', 'simple-lms' ), $subject_id, 'people' );
				break;
			case 'drop_staff_auditor':
				$result = $this->drop_staff_auditor_from_subject( $subject_id, $role, $user_id );
				$this->redirect_with_notice( $page_url, $result, __( 'Auditor removed successfully.', 'simple-lms' ), $subject_id, 'people' );
				break;
			case 'unenroll_student':
				$result = $this->unenroll_student_from_subject( $subject_id, $role, $user_id );
				$this->redirect_with_notice( $page_url, $result, __( 'Student unenrolled successfully.', 'simple-lms' ), $subject_id, 'people' );
				break;
			case 'create_thread':
				$result = $this->create_thread( $subject_id, $role, $user_id );
				$this->redirect_with_notice( $page_url, $result, __( 'Discussion thread published.', 'simple-lms' ), $subject_id, 'discussions' );
				break;
			case 'update_thread':
				$result = $this->update_subject_item( 'discussions', $subject_id, $role, $user_id );
				$this->redirect_with_notice( $page_url, $result, __( 'Discussion thread updated successfully.', 'simple-lms' ), $subject_id, 'discussions', '', absint( $_POST['item_id'] ?? 0 ) );
				break;
			case 'delete_thread':
				$result = $this->delete_subject_item( 'discussions', $subject_id, $role );
				$this->redirect_with_notice( $page_url, $result, __( 'Discussion thread deleted successfully.', 'simple-lms' ), $subject_id, 'discussions' );
				break;
			case 'reply_thread':
				$result = $this->reply_thread( $user_id );
				$this->redirect_with_notice( $page_url, $result, __( 'Reply published.', 'simple-lms' ), $subject_id, 'discussions', '', absint( $_POST['thread_id'] ?? 0 ) );
				break;
			case 'download_discussion_reply_attachment':
				$this->download_discussion_reply_attachment( $subject_id, $role, $user_id );
				break;
			case 'update_discussion_reply':
				$result = $this->update_discussion_reply( $user_id, $role );
				$this->redirect_with_notice( $page_url, $result, __( 'Reply updated successfully.', 'simple-lms' ), $subject_id, 'discussions', '', absint( $_POST['thread_id'] ?? 0 ) );
				break;
			case 'delete_discussion_reply':
				$result = $this->delete_discussion_reply( $user_id, $role );
				$this->redirect_with_notice( $page_url, $result, __( 'Reply deleted successfully.', 'simple-lms' ), $subject_id, 'discussions', '', absint( $_POST['thread_id'] ?? 0 ) );
				break;
			case 'save_subject_class_plan':
				$result = $this->save_subject_class_plan( $subject_id, $role, $user_id );
				$this->redirect_with_notice( $page_url, $result, __( 'Class plan updated successfully.', 'simple-lms' ), $subject_id, 'attendance' );
				break;
			case 'save_subject_attendance':
				$result = $this->save_subject_attendance( $subject_id, $role, $user_id );
				$this->redirect_with_notice( $page_url, $result, __( 'Attendance saved successfully.', 'simple-lms' ), $subject_id, 'attendance' );
				break;
			case 'save_subject_results_row':
				$result = $this->save_subject_results_row( $subject_id, $role, $user_id );
				$this->redirect_with_notice( $page_url, $result, __( 'Results row saved successfully.', 'simple-lms' ), $subject_id, 'results' );
				break;
			case 'submit_leave_application':
				if ( ! $this->is_leave_application_enabled() ) {
					$result = new \WP_Error( 'slms_leave_disabled', __( 'Leave applications are temporarily disabled.', 'simple-lms' ) );
					$this->redirect_with_notice( $page_url, $result, __( 'Leave application sent successfully.', 'simple-lms' ), 0, 'materials', 'dashboard' );
					break;
				}
				$result = $this->submit_leave_application( $user_id, $role );
				$this->redirect_with_notice( $page_url, $result, __( 'Leave application sent successfully.', 'simple-lms' ), 0, 'materials', 'leave-application' );
				break;
			case 'save_profile_photo':
				$photo_target_user_id = absint( $_POST['slms_profile_photo_user_id'] ?? 0 );
				$photo_return_view    = sanitize_key( wp_unslash( $_POST['slms_photo_return_view'] ?? 'card-issuer' ) );

				if ( ! $this->can_use_card_issuer( $role ) ) {
					$result = new \WP_Error( 'slms_photo_forbidden', __( 'Only administrators can update ID photos from the Card Issuer preview.', 'simple-lms' ) );
				} elseif ( ! $photo_target_user_id ) {
					$result = new \WP_Error( 'slms_photo_missing_target', __( 'Please choose a student or staff profile before updating an ID photo.', 'simple-lms' ) );
				} elseif ( ! $this->profiles->get_profile( $photo_target_user_id ) ) {
					$result = new \WP_Error( 'slms_photo_missing_profile', __( 'The selected profile could not be found.', 'simple-lms' ) );
				} else {
					$result = $this->save_profile_photo( $photo_target_user_id );
				}

				$this->redirect_with_notice(
					$page_url,
					$result,
					__( 'ID photo updated successfully.', 'simple-lms' ),
					0,
					'materials',
					'card-issuer' === $photo_return_view ? 'card-issuer' : 'dashboard'
				);
				break;
			case 'change_password':
				$result = $this->change_password( $user_id );
				$this->redirect_with_notice(
					$page_url,
					$result,
					$requires_password_change ? __( 'Password updated successfully. Your account is ready to use.', 'simple-lms' ) : __( 'Password updated successfully.', 'simple-lms' ),
					0,
					'materials',
					is_wp_error( $result ) ? 'security' : ( $requires_password_change ? 'dashboard' : 'security' )
				);
				break;
		}
	}


	private function create_broadcast_from_request( $user_id, $role ) {
		if ( 'administrator' !== $role ) {
			return new \WP_Error( 'slms_broadcast_forbidden', __( 'Only administrators can use the Broadcast Center.', 'simple-lms' ) );
		}

		$data = array(
			'title'                    => sanitize_text_field( wp_unslash( $_POST['broadcast_title'] ?? '' ) ),
			'message'                  => wp_kses_post( wp_unslash( $_POST['broadcast_message'] ?? '' ) ),
			'message_type'             => sanitize_key( wp_unslash( $_POST['broadcast_type'] ?? 'normal' ) ),
			'show_popup'               => ! empty( $_POST['broadcast_popup'] ),
			'requires_acknowledgement' => ! empty( $_POST['broadcast_requires_ack'] ),
			'targets'                  => isset( $_POST['broadcast_targets'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['broadcast_targets'] ) ) : array(),
			'section_ids'              => isset( $_POST['broadcast_section_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['broadcast_section_ids'] ) ) : array(),
			'embed_url'                => esc_url_raw( wp_unslash( $_POST['broadcast_embed_url'] ?? '' ) ),
		);

		return $this->broadcasts->create_broadcast( $data, $user_id );
	}

	private function create_email_campaign_from_request( $user_id, $role ) {
		if ( 'administrator' !== $role ) {
			return new \WP_Error( 'slms_email_campaign_forbidden', __( 'Only administrators can use the Email Center.', 'simple-lms' ) );
		}

		$data = array(
			'subject'     => sanitize_text_field( wp_unslash( $_POST['email_campaign_subject'] ?? '' ) ),
			'message'     => wp_kses_post( wp_unslash( $_POST['email_campaign_message'] ?? '' ) ),
			'targets'     => isset( $_POST['email_campaign_targets'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['email_campaign_targets'] ) ) : array(),
			'section_ids' => isset( $_POST['email_campaign_section_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['email_campaign_section_ids'] ) ) : array(),
		);

		return $this->email_campaigns->create_campaign( $data, $user_id );
	}

	public function handle_broadcast_mark_seen_ajax() {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'You must be signed in to read announcements.', 'simple-lms' ) ), 403 );
		}

		check_ajax_referer( 'slms_portal_action', 'nonce' );

		$broadcast_id = absint( $_POST['broadcast_id'] ?? 0 );
		$popup        = ! empty( $_POST['popup'] );
		$result       = $this->broadcasts->mark_seen( $broadcast_id, get_current_user_id(), $popup );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success( array( 'message' => __( 'Announcement marked as read.', 'simple-lms' ) ) );
	}

	public function handle_broadcast_acknowledge_ajax() {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'You must be signed in to acknowledge announcements.', 'simple-lms' ) ), 403 );
		}

		check_ajax_referer( 'slms_portal_action', 'nonce' );

		$broadcast_id = absint( $_POST['broadcast_id'] ?? 0 );
		$result       = $this->broadcasts->acknowledge( $broadcast_id, get_current_user_id() );

		if ( is_wp_error( $result ) || false === $result ) {
			wp_send_json_error( array( 'message' => is_wp_error( $result ) ? $result->get_error_message() : __( 'The acknowledgement could not be saved.', 'simple-lms' ) ), 400 );
		}

		wp_send_json_success( array( 'message' => __( 'Announcement acknowledged.', 'simple-lms' ) ) );
	}

	public function handle_toggle_material_visibility_ajax() {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error(
				array(
					'message' => __( 'You must be signed in to update material visibility.', 'simple-lms' ),
				),
				403
			);
		}

		check_ajax_referer( 'slms_portal_action', 'slms_portal_nonce' );

		$user_id    = get_current_user_id();
		$role       = RoleManager::get_primary_role( $user_id );
		$subject_id = absint( $_POST['subject_id'] ?? 0 );
		$item_id    = absint( $_POST['item_id'] ?? 0 );
		$item_tab   = sanitize_key( wp_unslash( $_POST['item_tab'] ?? 'materials' ) );
		$keep_open  = ! empty( $_POST['slms_keep_open'] );
		$page_url   = esc_url_raw( wp_unslash( $_POST['page_url'] ?? '' ) );
		$result     = $this->toggle_material_visibility( $subject_id, $role );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
				),
				400
			);
		}

		$is_visible_to_students = isset( $result['is_visible_to_students'] ) ? (bool) $result['is_visible_to_students'] : false;
		$page_url               = $page_url ?: ( wp_get_referer() ?: home_url( '/' ) );
		$section_html           = $this->get_subject_tab_layout_markup( $page_url, $subject_id, $role, $user_id, $item_tab, $keep_open ? $item_id : 0 );

		if ( '' === trim( $section_html ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'The updated section could not be loaded.', 'simple-lms' ),
				),
				404
			);
		}

		wp_send_json_success(
			array(
				'sectionHtml'          => $section_html,
				'itemId'               => $item_id,
				'isVisibleToStudents'  => $is_visible_to_students,
			)
		);
	}

	public function handle_grade_assignment_submission_ajax() {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error(
				array(
					'message' => __( 'You must be signed in to grade submissions.', 'simple-lms' ),
				),
				403
			);
		}

		check_ajax_referer( 'slms_portal_action', 'slms_portal_nonce' );

		$user_id    = get_current_user_id();
		$role       = RoleManager::get_primary_role( $user_id );
		$subject_id = absint( $_POST['subject_id'] ?? 0 );
		$result     = $this->grade_assignment_submission( $subject_id, $role, $user_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
				),
				400
			);
		}

		$payload = $this->get_assignment_grade_response_payload(
			absint( $_POST['assignment_id'] ?? 0 ),
			absint( $_POST['student_user_id'] ?? 0 ),
			$user_id,
			__( 'Submission feedback saved successfully.', 'simple-lms' )
		);

		wp_send_json_success( $payload );
	}


	private function get_subject_tab_layout_markup( $page_url, $subject_id, $role, $user_id, $tab, $open_item_id = 0 ) {
		ob_start();

		switch ( $tab ) {
			case 'assignments':
				$this->render_assignments_tab( $page_url, $subject_id, $role, $this->get_visible_subject_assignments( $user_id, $subject_id, $role ), $open_item_id );
				break;
			case 'discussions':
				$this->render_discussions_tab( $page_url, $subject_id, $role, $this->get_discussion_threads( $subject_id, $role ), $open_item_id );
				break;
			case 'materials':
			default:
				$this->render_materials_tab( $page_url, $subject_id, $role, $this->get_materials( $subject_id, $role ), $open_item_id );
				break;
		}

		return (string) ob_get_clean();
	}

	private function render_header_image_modal() {
		$default_opacity = $this->get_default_header_image_opacity_percent();
		?>
		<div class="slms-photo-modal slms-header-image-modal" aria-hidden="true" id="slms-header-image-modal">
			<div class="slms-photo-modal-backdrop" data-slms-close-header-image-modal></div>
			<div class="slms-photo-modal-card" role="dialog" aria-modal="true" aria-labelledby="slms-header-image-modal-title">
				<button type="button" class="slms-photo-modal-close" data-slms-close-header-image-modal aria-label="<?php esc_attr_e( 'Close header image dialog', 'simple-lms' ); ?>">&times;</button>
				<h3 id="slms-header-image-modal-title" data-slms-header-image-modal-title><?php esc_html_e( 'Edit header image', 'simple-lms' ); ?></h3>
				<p class="slms-field-help"><?php esc_html_e( 'Upload a wide image for the LMS header or subject header. The preview fills the header frame automatically. Drag to reposition, use your mouse wheel or trackpad to zoom, and adjust the opacity slider before saving.', 'simple-lms' ); ?></p>
				<p class="slms-photo-modal-error" data-slms-header-image-error hidden></p>
				<form method="post" action="" enctype="multipart/form-data" class="slms-form-stack" data-slms-header-image-form>
					<?php wp_nonce_field( 'slms_portal_action', 'slms_portal_nonce' ); ?>
					<input type="hidden" name="slms_portal_action" value="save_portal_header_image" data-slms-header-action-field>
					<input type="hidden" name="subject_id" value="0" data-slms-header-subject-field>
					<input type="hidden" name="slms_header_active_tab" value="materials" data-slms-header-tab-field>
					<input type="hidden" name="slms_header_view" value="dashboard" data-slms-header-view-field>
					<input type="hidden" name="slms_header_image_delete" value="0" data-slms-header-delete-field>
					<input type="hidden" name="slms_header_image_mode" value="crop" data-slms-header-mode-field>
					<input type="hidden" name="slms_header_image_opacity" value="<?php echo esc_attr( (string) $default_opacity ); ?>" data-slms-header-opacity-field>
					<div class="slms-photo-modal-body">
						<div class="slms-photo-static-preview" data-slms-header-image-preview-wrap hidden>
							<img src="" alt="<?php esc_attr_e( 'Current header image preview', 'simple-lms' ); ?>" data-slms-header-image-preview>
						</div>
						<div class="slms-photo-cropper-wrap" data-slms-header-crop-wrap hidden>
							<img src="" alt="" data-slms-header-crop-source>
						</div>
						<div class="slms-photo-modal-controls">
							<label class="slms-portal-button is-secondary slms-file-button">
								<?php esc_html_e( 'Choose Image', 'simple-lms' ); ?>
								<input type="file" name="slms_header_image" accept="image/*" data-slms-header-image-input>
							</label>
							<button type="submit" class="slms-portal-button" data-slms-save-header-cropped><?php esc_html_e( 'Crop & Save', 'simple-lms' ); ?></button>
							<button type="button" class="slms-portal-button is-danger" data-slms-remove-header-image><?php esc_html_e( 'Remove Image', 'simple-lms' ); ?></button>
							<div class="slms-header-opacity-control">
								<label class="slms-header-opacity-label" for="slms-header-image-opacity"><?php esc_html_e( 'Opacity', 'simple-lms' ); ?></label>
								<div class="slms-header-opacity-input-row">
									<input type="range" id="slms-header-image-opacity" min="0" max="100" step="1" value="<?php echo esc_attr( (string) $default_opacity ); ?>" data-slms-header-opacity-input>
									<output class="slms-header-opacity-value" data-slms-header-opacity-value><?php echo esc_html( $default_opacity . '%' ); ?></output>
								</div>
							</div>
						</div>
					</div>
				</form>
			</div>
		</div>
		<?php
	}

	private function render_id_card( $user_id, $role, ?array $profile ) {
		$photo_url = $this->get_profile_photo_url( $user_id );
		?>
		<section class="slms-portal-panel slms-id-panel slms-flow-section">
			<div class="slms-id-card-shell">
				<?php echo IdCardsModule::instance()->render_portal_card( $user_id, $role, $profile, $photo_url ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
		</section>
		<?php
	}

	public function render_transcript( $user_id ) {
		$profile               = $this->profiles->get_profile( $user_id );
		$rows                  = $this->get_completed_transcript_rows( $user_id );
		$grouped               = array();
		$total_score_sum       = 0.0;
		$completed_subjects    = 0;

		foreach ( $rows as $row ) {
			$key = $row['term_label'] ?: __( 'Completed Subjects', 'simple-lms' );

			if ( ! isset( $grouped[ $key ] ) ) {
				$grouped[ $key ] = array(
					'label' => $key,
					'items' => array(),
				);
			}

			$grouped[ $key ]['items'][] = $row;
			$total_score_sum         += (float) $row['total_score'];
			$completed_subjects++;
		}

		$overall_average  = $completed_subjects > 0 ? ( $total_score_sum / $completed_subjects ) : null;
		$overall_grade    = null !== $overall_average ? (string) ( $this->grade_scale->resolve( $overall_average )['letter'] ?? '' ) : '';
		$logo_url         = $this->get_brand_logo_url();
		$institution_name = $this->settings->get( 'institution_name', get_bloginfo( 'name' ) );
		$institution_code = $this->settings->get( 'institution_code', 'UGP' );
		$issued_on        = AcademicClock::date( 'F j, Y' );
		$student_name     = $profile['display_name'] ?? wp_get_current_user()->display_name;
		$student_code     = $profile['person_code'] ?? $this->generate_fallback_person_code( $user_id, 'student' );
		$program_name     = ! empty( $profile['program_id'] ) ? $this->get_program_name( (int) $profile['program_id'] ) : '-';
		?>
		<section class="slms-portal-panel slms-transcript-panel slms-flow-section">
			<div class="slms-transcript-screen-sheet">
				<header class="slms-transcript-document-header">
					<div class="slms-transcript-brand">
						<?php if ( $logo_url ) : ?>
							<img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( $institution_name ); ?>" class="slms-transcript-logo">
						<?php endif; ?>
						<div class="slms-transcript-document-copy">
							<p class="slms-portal-kicker"><?php echo esc_html( $institution_name ); ?></p>
							<h2><?php esc_html_e( 'Academic Transcript', 'simple-lms' ); ?></h2>
							<p class="slms-field-help"><?php esc_html_e( 'Official record of completed courses and academic performance', 'simple-lms' ); ?></p>
							<div class="slms-transcript-document-meta">
								<span><?php echo esc_html( sprintf( __( 'Institution Code: %s', 'simple-lms' ), $institution_code ) ); ?></span>
								<span><?php echo esc_html( sprintf( __( 'Issued: %s', 'simple-lms' ), $issued_on ) ); ?></span>
							</div>
						</div>
					</div>
					<button type="button" class="slms-portal-button is-secondary slms-transcript-print" data-slms-transcript-print-trigger><?php esc_html_e( 'Print / Save PDF', 'simple-lms' ); ?></button>
				</header>

				<section class="slms-transcript-student-card">
					<div class="slms-transcript-student-grid">
						<div><span><?php esc_html_e( 'Name', 'simple-lms' ); ?></span><strong><?php echo $this->get_person_avatar_name_markup( $user_id, $student_name, 'is-compact' ); ?></strong></div>
						<div><span><?php esc_html_e( 'Student ID', 'simple-lms' ); ?></span><strong><?php echo esc_html( $student_code ); ?></strong></div>
						<div><span><?php esc_html_e( 'Program', 'simple-lms' ); ?></span><strong><?php echo esc_html( $program_name ); ?></strong></div>
						<div><span><?php esc_html_e( 'Generated', 'simple-lms' ); ?></span><strong><?php echo esc_html( $issued_on ); ?></strong></div>
					</div>
				</section>

				<section class="slms-transcript-body">
					<?php if ( empty( $rows ) ) : ?>
						<div class="slms-empty-state">
							<h3><?php esc_html_e( 'No completed courses yet', 'simple-lms' ); ?></h3>
							<p><?php esc_html_e( 'Completed subjects will appear here once final results are available or the subject status is marked completed.', 'simple-lms' ); ?></p>
						</div>
					<?php else : ?>
						<?php foreach ( $grouped as $group ) : ?>
							<div class="slms-transcript-term-block">
								<div class="slms-transcript-term-header">
									<h3><?php echo esc_html( $group['label'] ); ?></h3>
									<span class="slms-portal-badge"><?php echo esc_html( sprintf( _n( '%d subject', '%d subjects', count( $group['items'] ), 'simple-lms' ), count( $group['items'] ) ) ); ?></span>
								</div>
								<table class="slms-portal-table slms-transcript-table">
									<thead>
										<tr>
											<th><?php esc_html_e( 'Code', 'simple-lms' ); ?></th>
											<th><?php esc_html_e( 'Subject', 'simple-lms' ); ?></th>
											<th><?php esc_html_e( 'Credits', 'simple-lms' ); ?></th>
											<th><?php esc_html_e( 'Total Score', 'simple-lms' ); ?></th>
											<th><?php esc_html_e( 'Grade', 'simple-lms' ); ?></th>
										</tr>
									</thead>
									<tbody>
										<?php foreach ( $group['items'] as $row ) : ?>
											<tr>
												<td data-label="<?php esc_attr_e( 'Code', 'simple-lms' ); ?>"><?php echo esc_html( $row['code'] ); ?></td>
												<td data-label="<?php esc_attr_e( 'Subject', 'simple-lms' ); ?>"><?php echo esc_html( $row['title'] ); ?></td>
												<td data-label="<?php esc_attr_e( 'Credits', 'simple-lms' ); ?>"><?php echo esc_html( number_format_i18n( (float) $row['credits'], 1 ) ); ?></td>
												<td data-label="<?php esc_attr_e( 'Total Score', 'simple-lms' ); ?>"><?php echo esc_html( number_format_i18n( (float) $row['total_score'], 2 ) ); ?></td>
												<td data-label="<?php esc_attr_e( 'Grade', 'simple-lms' ); ?>"><?php echo esc_html( $row['letter_grade'] ); ?></td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							</div>
						<?php endforeach; ?>
					<?php endif; ?>
				</section>

				<section class="slms-transcript-summary">
					<div><span><?php esc_html_e( 'Overall Average', 'simple-lms' ); ?></span><strong><?php echo esc_html( null !== $overall_average ? number_format_i18n( $overall_average, 2 ) : '-' ); ?></strong></div>
					<div><span><?php esc_html_e( 'Overall Grade', 'simple-lms' ); ?></span><strong><?php echo esc_html( '' !== $overall_grade ? $overall_grade : '-' ); ?></strong></div>
					<div><span><?php esc_html_e( 'Completed Subjects', 'simple-lms' ); ?></span><strong><?php echo esc_html( (string) count( $rows ) ); ?></strong></div>
				</section>
			</div>

			<section class="slms-transcript-print-sheet" aria-hidden="true">
				<header class="slms-transcript-print-sheet-header">
					<?php if ( $logo_url ) : ?>
						<img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( $institution_name ); ?>" class="slms-transcript-print-sheet-logo">
					<?php endif; ?>
					<div class="slms-transcript-print-sheet-brand">
						<h2><?php echo esc_html( $institution_name ); ?></h2>
						<p><?php esc_html_e( 'Digital Academic Transcript', 'simple-lms' ); ?></p>
					</div>
				</header>

				<section class="slms-transcript-print-student-grid">
					<div><span><?php esc_html_e( 'Student Name', 'simple-lms' ); ?></span><strong><?php echo $this->get_person_avatar_name_markup( $user_id, $student_name, 'is-compact' ); ?></strong></div>
					<div><span><?php esc_html_e( 'Student ID', 'simple-lms' ); ?></span><strong><?php echo esc_html( $student_code ); ?></strong></div>
					<div><span><?php esc_html_e( 'Program', 'simple-lms' ); ?></span><strong><?php echo esc_html( $program_name ); ?></strong></div>
				</section>

				<table class="slms-transcript-print-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Code', 'simple-lms' ); ?></th>
							<th><?php esc_html_e( 'Completed Course', 'simple-lms' ); ?></th>
							<th><?php esc_html_e( 'Credits', 'simple-lms' ); ?></th>
							<th><?php esc_html_e( 'Total Score', 'simple-lms' ); ?></th>
							<th><?php esc_html_e( 'Grade', 'simple-lms' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $rows ) ) : ?>
							<tr>
								<td colspan="5"><?php esc_html_e( 'No completed courses recorded yet.', 'simple-lms' ); ?></td>
							</tr>
						<?php else : ?>
							<?php foreach ( $rows as $row ) : ?>
								<tr>
									<td><?php echo esc_html( $row['code'] ); ?></td>
									<td><?php echo esc_html( $row['title'] ); ?></td>
									<td><?php echo esc_html( number_format_i18n( (float) $row['credits'], 1 ) ); ?></td>
									<td><?php echo esc_html( number_format_i18n( (float) $row['total_score'], 2 ) ); ?></td>
									<td><?php echo esc_html( $row['letter_grade'] ); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
					<tfoot>
						<tr>
							<th colspan="4"><?php esc_html_e( 'Overall Average', 'simple-lms' ); ?></th>
							<th><?php echo esc_html( null !== $overall_average ? number_format_i18n( $overall_average, 2 ) : '-' ); ?></th>
						</tr>
						<tr>
							<th colspan="4"><?php esc_html_e( 'Overall Grade', 'simple-lms' ); ?></th>
							<th><?php echo esc_html( '' !== $overall_grade ? $overall_grade : '-' ); ?></th>
						</tr>
					</tfoot>
				</table>
			</section>
		</section>
		<?php
	}

	private function get_completed_transcript_rows( $user_id ) {
		$enrollments = $this->enrollments->list_enrollments( array( 'student_user_id' => $user_id ) );
		$rows        = array();

		foreach ( $enrollments as $enrollment ) {
			$subject_id = (int) $enrollment['section_id'];
			$snapshot   = $this->results->get_student_result_snapshot( $user_id, $subject_id, $user_id );
			$is_completed = 'completed' === $enrollment['status'] || $snapshot['can_show_total'];

			if ( ! $is_completed || ! $snapshot['can_show_total'] ) {
				continue;
			}

			$subject = $this->enrollments->get_section_summary( $subject_id );
			$credits = (float) get_post_meta( $subject_id, '_slms_credits', true );

			$rows[] = array(
				'code'         => get_post_meta( $subject_id, '_slms_subject_code', true ),
				'title'        => $subject['subject_title'] ?? ( $subject['title'] ?? $enrollment['subject_title'] ),
				'credits'      => $credits,
				'total_score'  => (float) $snapshot['total_score'],
				'status'       => $enrollment['status'],
				'completed_at' => $enrollment['completed_at'] ?? '',
				'sort_key'     => $enrollment['completed_at'] ?? ( $enrollment['updated_at'] ?? ( $enrollment['enrolled_at'] ?? '' ) ),
				'term_label'   => $subject['term_name'] ?? '',
				'letter_grade' => $snapshot['can_show_grade'] ? $snapshot['letter_grade'] : '-',
			);
		}

		usort(
			$rows,
			static function ( $left, $right ) {
				$left_key  = (string) ( $left['sort_key'] ?? '' );
				$right_key = (string) ( $right['sort_key'] ?? '' );

				if ( $left_key !== $right_key ) {
					return strcmp( $left_key, $right_key );
				}

				return strcmp( (string) $left['term_label'], (string) $right['term_label'] );
			}
		);

		return $rows;
	}

	private function get_materials( $subject_id, $role = '' ) {
		$post_statuses = $this->can_teach_subject( $subject_id, $role ) ? array( 'publish', 'private', 'draft', 'pending' ) : array( 'publish' );
		$args          = array(
			'post_type'      => 'slms_lesson',
			'post_status'    => $post_statuses,
			'posts_per_page' => 50,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_query'     => array(
				array(
					'key'     => '_slms_section_id',
					'value'   => $subject_id,
					'compare' => '=',
				),
			),
		);

		if ( ! $this->can_teach_subject( $subject_id, $role ) ) {
			$args['meta_query'][] = array(
				'relation' => 'OR',
				array(
					'key'     => '_slms_lesson_visibility',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => '_slms_lesson_visibility',
					'value'   => array( '', 'students', 'all' ),
					'compare' => 'IN',
				),
			);
		}

		return get_posts( $args );
	}

	private function get_visible_subject_assignments( $user_id, $subject_id, $role = '' ) {
		$assignments = $this->assessments->get_section_assignments( $user_id, $subject_id, 50 );

		if ( $this->can_teach_subject( $subject_id, $role ) ) {
			return $assignments;
		}

		return array_values(
			array_filter(
				(array) $assignments,
				function ( $assignment ) {
					$assignment_id = absint( $assignment['id'] ?? 0 );

					return $assignment_id && $this->is_subject_item_visible_to_students( $assignment_id, 'assignments' );
				}
			)
		);
	}

	private function get_discussion_threads( $subject_id, $role = '' ) {
		$post_statuses = $this->can_teach_subject( $subject_id, $role ) ? array( 'publish', 'private', 'draft', 'pending' ) : array( 'publish' );

		$threads = get_posts(
			array(
				'post_type'      => 'slms_discussion',
				'post_status'    => $post_statuses,
				'posts_per_page' => 50,
				'meta_key'       => '_slms_subject_id',
				'meta_value'     => $subject_id,
				'orderby'        => array(
					'date' => 'DESC',
					'ID'   => 'DESC',
				),
				'order'          => 'DESC',
			)
		);
		if ( $this->can_teach_subject( $subject_id, $role ) ) {
			return $threads;
		}

		return array_values(
			array_filter(
				$threads,
				function ( $thread ) {
					return $thread instanceof \WP_Post && $this->is_subject_item_visible_to_students( $thread->ID, 'discussions' );
				}
			)
		);
	}

	private function render_thread_replies( $thread_id ) {
		$thread_id  = absint( $thread_id );
		$comments   = get_comments( array( 'post_id' => $thread_id, 'status' => 'approve', 'order' => 'ASC', 'orderby' => 'comment_date_gmt' ) );
		$subject_id = absint( get_post_meta( $thread_id, '_slms_subject_id', true ) );
		$user_id    = get_current_user_id();
		$role       = RoleManager::get_primary_role( $user_id );

		if ( empty( $comments ) ) {
			echo '<p class="slms-field-help">' . esc_html__( 'No replies yet.', 'simple-lms' ) . '</p>';
			return;
		}

		$comment_tree = $this->build_thread_reply_tree( $comments );

		echo '<div class="slms-reply-stack slms-thread-reply-tree">';
		$this->render_thread_reply_branch( $comment_tree[0] ?? array(), $comment_tree, $thread_id, $subject_id, $user_id, $role, 0 );
		echo '</div>';
	}

	private function build_thread_reply_tree( array $comments ) {
		$known_ids = array();
		$tree      = array( 0 => array() );

		foreach ( $comments as $comment ) {
			if ( $comment instanceof \WP_Comment ) {
				$known_ids[ (int) $comment->comment_ID ] = true;
			}
		}

		foreach ( $comments as $comment ) {
			if ( ! $comment instanceof \WP_Comment ) {
				continue;
			}

			$parent_id = absint( $comment->comment_parent );
			if ( $parent_id && empty( $known_ids[ $parent_id ] ) ) {
				$parent_id = 0;
			}

			if ( ! isset( $tree[ $parent_id ] ) ) {
				$tree[ $parent_id ] = array();
			}

			$tree[ $parent_id ][] = $comment;
		}

		return $tree;
	}

	private function render_thread_reply_branch( array $comments, array $comment_tree, $thread_id, $subject_id, $user_id, $role, $depth = 0 ) {
		foreach ( $comments as $comment ) {
			$this->render_thread_reply_card( $comment, $comment_tree, $thread_id, $subject_id, $user_id, $role, $depth );
		}
	}

	private function render_thread_reply_card( \WP_Comment $comment, array $comment_tree, $thread_id, $subject_id, $user_id, $role, $depth = 0 ) {
		$comment_id       = (int) $comment->comment_ID;
		$can_manage_reply = $this->can_manage_discussion_reply( $comment, $user_id, $role, $subject_id );
		$edited_at        = get_comment_meta( $comment_id, '_slms_reply_edited_at', true );
		$attachment       = get_comment_meta( $comment_id, '_slms_attachment_url', true );
		$date_source      = $comment->comment_date_gmt ?: $comment->comment_date;
		$date_label       = $comment->comment_date_gmt
			? AcademicClock::format_utc( $date_source, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) )
			: AcademicClock::format_local( $date_source, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
		$child_comments   = $comment_tree[ $comment_id ] ?? array();
		$depth_class      = ' slms-thread-reply-depth-' . min( 6, max( 0, (int) $depth ) );
		$is_auditor       = $this->enrollments->user_can_audit_section( (int) $comment->user_id, $subject_id );
		$author_markup    = $this->get_person_name_with_auditor_tag_markup(
			$this->get_person_avatar_name_markup( (int) $comment->user_id, $comment->comment_author, 'is-compact' ),
			$is_auditor
		);

		echo '<div class="slms-thread-reply-node' . esc_attr( $depth_class ) . '" data-slms-reply-node="' . esc_attr( $comment_id ) . '">';
		echo '<article class="slms-reply-card slms-thread-reply-card" data-slms-record-body>';
		echo '<div class="slms-thread-reply-head"><strong>' . $author_markup . '</strong><div class="slms-thread-reply-meta"><span>' . esc_html( $date_label ) . '</span>';
		if ( $can_manage_reply ) {
			$this->render_discussion_reply_actions( $comment, $thread_id, $subject_id );
		}
		echo '</div></div>';

		echo '<div class="slms-thread-reply-content" data-slms-record-view>';
		$this->render_discussion_reply_content( $comment->comment_content );

		if ( $edited_at ) {
			echo '<p class="slms-thread-reply-edited">' . esc_html( sprintf( __( 'Edited %s', 'simple-lms' ), AcademicClock::format_local( $edited_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ) ) . '</p>';
		}

		if ( $attachment ) {
			$file_name = $this->get_attachment_name_from_url( $attachment );
			echo '<div class="slms-thread-reply-attachment"><span>' . esc_html( $file_name ) . '</span><form method="post" action="" class="slms-inline-download-form">';
			echo wp_nonce_field( 'slms_portal_action', 'slms_portal_nonce', true, false );
			echo '<input type="hidden" name="slms_portal_action" value="download_discussion_reply_attachment">';
			echo '<input type="hidden" name="subject_id" value="' . esc_attr( $subject_id ) . '">';
			echo '<input type="hidden" name="comment_id" value="' . esc_attr( $comment_id ) . '">';
			echo '<button type="submit" class="slms-portal-button is-secondary">' . esc_html__( 'Download', 'simple-lms' ) . '</button>';
			echo '</form></div>';
		}
		echo '</div>';

		if ( $can_manage_reply ) {
			$this->render_discussion_reply_edit_form( $comment, $thread_id, $subject_id );
		}

		$this->render_discussion_child_reply_form( $comment, $thread_id, $subject_id );

		echo '</article>';

		if ( ! empty( $child_comments ) ) {
			echo '<div class="slms-thread-reply-children">';
			$this->render_thread_reply_branch( $child_comments, $comment_tree, $thread_id, $subject_id, $user_id, $role, (int) $depth + 1 );
			echo '</div>';
		}

		echo '</div>';
	}

	private function render_discussion_reply_content( $content ) {
		$content = (string) $content;

		if ( '' === trim( wp_strip_all_tags( $content ) ) ) {
			return;
		}

		echo '<div class="slms-rich-content slms-discussion-reply-body">' . wpautop( wp_kses_post( $content ) ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	private function render_portal_rich_text_editor_field( $name, $label, $content = '', $rows = 4, $placeholder = '', $help = '', $required = false ) {
		$textarea_id = wp_unique_id( 'slms-plain-text-editor-' );
		$value       = $this->prepare_content_for_plain_text_editor( $content );
		$placeholder = $placeholder ?: __( 'Write here...', 'simple-lms' );
		$help        = (string) $help;
		if ( false !== stripos( $help, 'Formatting is applied' ) ) {
			$help = '';
		}
		?>
		<label class="slms-rich-reply-editor slms-rich-text-editor slms-plain-text-editor">
			<span id="<?php echo esc_attr( $textarea_id ); ?>-label"><?php echo esc_html( $label ); ?></span>
			<textarea id="<?php echo esc_attr( $textarea_id ); ?>" name="<?php echo esc_attr( $name ); ?>" class="slms-rich-textarea" rows="<?php echo esc_attr( max( 2, (int) $rows ) ); ?>" placeholder="<?php echo esc_attr( $placeholder ); ?>" aria-labelledby="<?php echo esc_attr( $textarea_id ); ?>-label" <?php echo $required ? 'required' : ''; ?>><?php echo esc_textarea( $value ); ?></textarea>
			<?php if ( '' !== trim( $help ) ) : ?>
				<span class="slms-field-help slms-reply-format-help"><?php echo esc_html( $help ); ?></span>
			<?php endif; ?>
		</label>
		<?php
	}

	private function prepare_content_for_plain_text_editor( $content ) {
		$content = (string) $content;

		if ( '' === $content ) {
			return '';
		}

		if ( ! preg_match( '/<[a-z][^>]*>/i', $content ) ) {
			return $content;
		}

		$content = preg_replace( '/<br\s*\/?>(\s*)/i', "\n", $content );
		$content = preg_replace( '/<li[^>]*>/i', '- ', $content );
		$content = preg_replace( '/<\/(p|div|li|h[1-6]|blockquote|ul|ol)>/i', "\n", $content );
		$content = wp_strip_all_tags( $content );
		$content = html_entity_decode( $content, ENT_QUOTES, get_bloginfo( 'charset' ) );
		$content = preg_replace( "/\r\n|\r/", "\n", $content );
		$content = preg_replace( "/[ 	]+\n/", "\n", $content );
		$content = preg_replace( "/\n{3,}/", "\n\n", $content );

		return trim( $content );
	}

	private function render_discussion_reply_editor_field( $label, $content = '', $rows = 4 ) {
		$this->render_portal_rich_text_editor_field(
			'reply_content',
			$label,
			$content,
			$rows,
			__( 'Write your reply...', 'simple-lms' ),
			'',
			true
		);
	}

	private function render_discussion_reply_actions( \WP_Comment $comment, $thread_id, $subject_id ) {
		$delete_form_id = 'slms-delete-reply-' . absint( $comment->comment_ID );
		?>
		<details class="slms-thread-reply-menu" data-slms-reply-menu>
			<summary class="slms-thread-reply-menu-toggle" aria-label="<?php esc_attr_e( 'Reply actions', 'simple-lms' ); ?>"><span aria-hidden="true">&hellip;</span></summary>
			<div class="slms-thread-reply-menu-panel">
				<button type="button" class="slms-thread-reply-menu-item" data-slms-record-edit-toggle><?php esc_html_e( 'Edit Reply', 'simple-lms' ); ?></button>
				<form method="post" action="" id="<?php echo esc_attr( $delete_form_id ); ?>" class="slms-thread-reply-delete-form" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this reply? This cannot be undone.', 'simple-lms' ) ); ?>');">
					<?php wp_nonce_field( 'slms_portal_action', 'slms_portal_nonce' ); ?>
					<input type="hidden" name="slms_portal_action" value="delete_discussion_reply">
					<input type="hidden" name="comment_id" value="<?php echo esc_attr( $comment->comment_ID ); ?>">
					<input type="hidden" name="thread_id" value="<?php echo esc_attr( $thread_id ); ?>">
					<input type="hidden" name="subject_id" value="<?php echo esc_attr( $subject_id ); ?>">
					<button type="submit" class="slms-thread-reply-menu-item is-danger"><?php esc_html_e( 'Delete Reply', 'simple-lms' ); ?></button>
				</form>
			</div>
		</details>
		<?php
	}

	private function render_discussion_reply_edit_form( \WP_Comment $comment, $thread_id, $subject_id ) {
		$edit_form_id = 'slms-edit-reply-' . absint( $comment->comment_ID );
		?>
		<form method="post" action="" id="<?php echo esc_attr( $edit_form_id ); ?>" class="slms-form-stack slms-thread-reply-edit-form" data-slms-record-edit-form hidden>
			<?php wp_nonce_field( 'slms_portal_action', 'slms_portal_nonce' ); ?>
			<input type="hidden" name="slms_portal_action" value="update_discussion_reply">
			<input type="hidden" name="comment_id" value="<?php echo esc_attr( $comment->comment_ID ); ?>">
			<input type="hidden" name="thread_id" value="<?php echo esc_attr( $thread_id ); ?>">
			<input type="hidden" name="subject_id" value="<?php echo esc_attr( $subject_id ); ?>">
			<?php $this->render_discussion_reply_editor_field( __( 'Reply Text', 'simple-lms' ), $comment->comment_content, 4 ); ?>
			<div class="slms-row-actions slms-thread-reply-edit-buttons">
				<button type="submit" class="slms-portal-button is-secondary"><?php esc_html_e( 'Save', 'simple-lms' ); ?></button>
				<button type="button" class="slms-portal-button is-ghost" data-slms-record-edit-cancel><?php esc_html_e( 'Cancel', 'simple-lms' ); ?></button>
			</div>
		</form>
		<?php
	}

	private function render_discussion_child_reply_form( \WP_Comment $comment, $thread_id, $subject_id ) {
		$panel_id = 'slms-child-reply-panel-' . absint( $comment->comment_ID );
		?>
		<div class="slms-thread-child-reply-form-wrap" data-slms-child-reply-wrap>
			<button type="button" class="slms-thread-child-reply-toggle" data-slms-child-reply-toggle aria-expanded="false" aria-controls="<?php echo esc_attr( $panel_id ); ?>"><?php esc_html_e( 'Reply', 'simple-lms' ); ?></button>
			<div id="<?php echo esc_attr( $panel_id ); ?>" class="slms-thread-child-reply-panel" data-slms-child-reply-panel hidden>
				<form method="post" action="" enctype="multipart/form-data" class="slms-form-stack slms-subject-reply-form slms-thread-child-reply-form">
					<?php wp_nonce_field( 'slms_portal_action', 'slms_portal_nonce' ); ?>
					<input type="hidden" name="slms_portal_action" value="reply_thread">
					<input type="hidden" name="thread_id" value="<?php echo esc_attr( $thread_id ); ?>">
					<input type="hidden" name="subject_id" value="<?php echo esc_attr( $subject_id ); ?>">
					<input type="hidden" name="parent_comment_id" value="<?php echo esc_attr( $comment->comment_ID ); ?>">
					<?php $this->render_discussion_reply_editor_field( sprintf( __( 'Reply to %s', 'simple-lms' ), $comment->comment_author ), '', 3 ); ?>
					<label><span><?php esc_html_e( 'Attach File', 'simple-lms' ); ?></span><input type="file" name="reply_file"></label>
					<p class="slms-field-help"><?php esc_html_e( 'Reply attachments can be up to 100MB.', 'simple-lms' ); ?></p>
					<button type="submit" class="slms-portal-button is-secondary"><?php esc_html_e( 'Publish Reply', 'simple-lms' ); ?></button>
				</form>
			</div>
		</div>
		<?php
	}

	private function save_subject_class_plan( $subject_id, $role, $user_id ) {
		if ( ! $this->can_teach_subject( $subject_id, $role ) ) {
			return new \WP_Error( 'slms_forbidden_class_plan', __( 'You do not have permission to manage the class plan for this subject.', 'simple-lms' ) );
		}

		$total_classes = max( 1, absint( $_POST['total_classes'] ?? 0 ) );
		$plan_unit     = sanitize_key( wp_unslash( $_POST['attendance_plan_unit'] ?? 'weeks' ) );

		if ( ! in_array( $plan_unit, array( 'weeks', 'classes' ), true ) ) {
			$plan_unit = 'weeks';
		}

		update_post_meta( $subject_id, '_slms_total_classes', $total_classes );
		update_post_meta( $subject_id, '_slms_attendance_plan_unit', $plan_unit );

		return array(
			'updated' => 1,
		);
	}

	private function save_subject_attendance( $subject_id, $role, $user_id ) {
		if ( ! $this->attendance->can_manage_attendance( $user_id, $subject_id ) ) {
			return new \WP_Error( 'slms_forbidden_attendance', __( 'You do not have permission to record attendance for this subject.', 'simple-lms' ) );
		}

		$session_number = max( 1, absint( $_POST['session_number'] ?? 0 ) );
		$session_id     = absint( $_POST['session_id'] ?? 0 );
		$session_date   = sanitize_text_field( wp_unslash( $_POST['session_date'] ?? '' ) );
		$existing       = $session_id ? $this->attendance->get_session( $session_id ) : $this->attendance->get_session_by_number( $subject_id, $session_number );
		$session_label = $this->get_attendance_session_label( $subject_id, $session_number );
		$session_result = $this->attendance->save_session(
			$subject_id,
			array(
				'session_id'     => $existing['id'] ?? 0,
				'session_number' => $session_number,
				'session_title'  => $session_label,
				'week_label'     => $session_label,
				'session_date'   => $session_date ?: ( $existing['session_date'] ?? AcademicClock::date( 'Y-m-d' ) ),
				'status'         => 'completed',
			),
			$user_id
		);

		if ( is_wp_error( $session_result ) ) {
			return $session_result;
		}

		$records = array();

		foreach ( (array) ( $_POST['attendance'] ?? array() ) as $student_user_id => $record ) {
			$records[ absint( $student_user_id ) ] = array(
				'status' => sanitize_key( $record['status'] ?? 'absent' ),
			);
		}

		$saved = $this->attendance->save_session_records( (int) $session_result, $records, $user_id );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return array(
			'updated' => (int) $saved,
		);
	}

	private function save_subject_results_row( $subject_id, $role, $user_id ) {
		if ( ! $this->can_teach_subject( $subject_id, $role ) ) {
			return new \WP_Error( 'slms_forbidden_results', __( 'You do not have permission to manage results for this subject.', 'simple-lms' ) );
		}

		$student_user_id = absint( $_POST['student_user_id'] ?? 0 );

		if ( ! $student_user_id || 'student' !== RoleManager::get_primary_role( $student_user_id ) || ! $this->enrollments->user_can_access_section( $student_user_id, $subject_id ) ) {
			return new \WP_Error( 'slms_invalid_results_student', __( 'The selected student is not enrolled in this subject.', 'simple-lms' ) );
		}

		$attendance_score_raw = trim( (string) wp_unslash( $_POST['attendance_score'] ?? '' ) );
		$assignment_score_raw = trim( (string) wp_unslash( $_POST['assignment_score'] ?? '' ) );
		$final_exam_score_raw = trim( (string) wp_unslash( $_POST['final_exam_score'] ?? '' ) );
		$attendance_score     = '' === $attendance_score_raw ? null : (float) $attendance_score_raw;
		$assignment_score     = '' === $assignment_score_raw ? null : (float) $assignment_score_raw;
		$final_exam_score     = '' === $final_exam_score_raw ? null : (float) $final_exam_score_raw;
		$attendance_weight    = $this->get_attendance_participation_weight();
		$assignments          = $this->assessments->get_section_assignments( $user_id, $subject_id, 200 );
		$assignment_weight_total = $this->get_assignment_weight_total( $assignments );
		$remaining_exam_weight = max( 0.0, $this->get_results_controlled_weight_cap() - min( $this->get_results_controlled_weight_cap(), $assignment_weight_total ) );

		if ( null !== $attendance_score && ( $attendance_score < 0 || $attendance_score > $attendance_weight ) ) {
			return new \WP_Error( 'slms_invalid_attendance_result', sprintf( __( 'Attendance and participation must stay between 0 and %s.', 'simple-lms' ), number_format_i18n( $attendance_weight, 1 ) ) );
		}

		if ( null !== $assignment_score && ( $assignment_score < 0 || $assignment_score > $assignment_weight_total ) ) {
			return new \WP_Error( 'slms_invalid_assignment_result', sprintf( __( 'Assignments must stay between 0 and %s for this subject.', 'simple-lms' ), number_format_i18n( $assignment_weight_total, 1 ) ) );
		}

		if ( null !== $final_exam_score && ( $final_exam_score < 0 || $final_exam_score > $remaining_exam_weight ) ) {
			return new \WP_Error( 'slms_invalid_final_exam_result', sprintf( __( 'Final Exam must stay between 0 and %s for this subject.', 'simple-lms' ), number_format_i18n( $remaining_exam_weight, 1 ) ) );
		}

		$this->save_subject_results_override( $subject_id, $student_user_id, $attendance_score, $assignment_score, $final_exam_score, $user_id );

		return true;
	}

	private function validate_assignment_weight_budget( $subject_id, $proposed_weight, $exclude_assignment_id = 0 ) {
		$subject_id            = absint( $subject_id );
		$exclude_assignment_id = absint( $exclude_assignment_id );
		$cap                   = $this->get_results_controlled_weight_cap();
		$current_total         = 0.0;
		$assignments           = $this->assessments->get_section_assignments( get_current_user_id(), $subject_id, 200 );

		foreach ( $assignments as $assignment ) {
			if ( $exclude_assignment_id && (int) $assignment['id'] === $exclude_assignment_id ) {
				continue;
			}

			$current_total += max( 0, (float) ( $assignment['weight'] ?? 0 ) );
		}

		if ( $current_total + max( 0, (float) $proposed_weight ) > $cap ) {
			return new \WP_Error( 'slms_assignment_weight_limit', sprintf( __( 'Assignments in this subject can use up to %1$s%% total because %2$s%% is reserved for attendance and participation.', 'simple-lms' ), number_format_i18n( $cap, 0 ), number_format_i18n( $this->get_attendance_participation_weight(), 0 ) ) );
		}

		return true;
	}

	private function delete_submission_attachment_asset( $submission ) {
		$url = esc_url_raw( (string) ( $submission['attachment_url'] ?? '' ) );

		if ( '' === $url ) {
			return;
		}

		$path = $this->map_upload_url_to_path( $url );

		if ( $path && file_exists( $path ) ) {
			wp_delete_file( $path );
		}
	}

	private function send_assignment_submission_confirmation_email( $assignment_id, $student_user_id, array $submission ) {
		$this->assignment_notifications->send_submission_confirmation( $assignment_id, $student_user_id, $submission );
	}

	private function send_assignment_graded_email( $assignment_id, $student_user_id ) {
		$this->assignment_notifications->send_grade_posted( $assignment_id, $student_user_id );
	}

	private function get_attendance_plan_unit( $subject_id ) {
		$unit = sanitize_key( (string) get_post_meta( $subject_id, '_slms_attendance_plan_unit', true ) );

		return in_array( $unit, array( 'weeks', 'classes' ), true ) ? $unit : 'weeks';
	}

	private function get_attendance_session_label( $subject_id, $number ) {
		$number = max( 1, absint( $number ) );

		if ( 'classes' === $this->get_attendance_plan_unit( $subject_id ) ) {
			return sprintf( __( 'Class %d', 'simple-lms' ), $number );
		}

		return sprintf( __( 'Week %d', 'simple-lms' ), $number );
	}

	private function get_total_classes( $subject_id, array $sessions = array() ) {
		$configured         = absint( get_post_meta( $subject_id, '_slms_total_classes', true ) );
		$max_session_number = 0;

		foreach ( $sessions as $session ) {
			$max_session_number = max( $max_session_number, (int) ( $session['session_number'] ?? 0 ) );
		}

		if ( $configured > 0 ) {
			return max( $configured, $max_session_number );
		}

		if ( $max_session_number > 0 ) {
			return $max_session_number;
		}

		return 1;
	}

	private function build_attendance_class_rows( array $attendance_dashboard, $total_classes, $plan_unit = 'weeks' ) {
		$class_rows         = array();
		$sessions_by_number = array();

		foreach ( $attendance_dashboard['sessions'] ?? array() as $session ) {
			$sessions_by_number[ (int) $session['session_number'] ] = $session;
		}

		$plan_unit = 'classes' === sanitize_key( (string) $plan_unit ) ? 'classes' : 'weeks';

		for ( $number = 1; $number <= $total_classes; $number++ ) {
			$session = $sessions_by_number[ $number ] ?? null;
			$label   = 'classes' === $plan_unit ? sprintf( __( 'Class %d', 'simple-lms' ), $number ) : sprintf( __( 'Week %d', 'simple-lms' ), $number );

			$class_rows[] = array(
				'number'      => $number,
				'label'       => $label,
				'session'     => $session,
				'records_map' => $session ? $this->index_attendance_records( $this->attendance->get_session_records( (int) $session['id'] ) ) : array(),
			);
		}

		return $class_rows;
	}

	private function index_attendance_records( array $records ) {
		$map = array();

		foreach ( $records as $record ) {
			$map[ (int) $record['student_user_id'] ] = $record;
		}

		return $map;
	}

	private function render_attendance_student_toggle( array $student, $status ) {
		$student_id  = (int) $student['student_user_id'];
		$status      = sanitize_key( (string) $status );
		if ( 'excused' === $status ) {
			$status = 'present';
		}
		if ( ! in_array( $status, array( 'present', 'late', 'leave', 'absent' ), true ) ) {
			$status = 'absent';
		}
		$name        = $student['student_name'] ?? __( 'Student', 'simple-lms' );
		$profile     = $this->profiles->get_profile( $student_id );
		$person_code = $profile['person_code'] ?? '';
		$meta_text   = $person_code ? $person_code : __( 'No student ID assigned', 'simple-lms' );
		?>
		<div class="slms-attendance-student is-<?php echo esc_attr( $status ); ?>" data-slms-attendance-card data-state="<?php echo esc_attr( $status ); ?>">
			<input type="hidden" name="attendance[<?php echo esc_attr( (string) $student_id ); ?>][status]" value="<?php echo esc_attr( $status ); ?>">
			<div class="slms-attendance-exception-actions" aria-label="<?php echo esc_attr( sprintf( __( 'Attendance exceptions for %s', 'simple-lms' ), $name ) ); ?>">
				<button type="button" class="slms-attendance-exception-button is-late<?php echo 'late' === $status ? ' is-active' : ''; ?>" data-slms-attendance-special="late" aria-pressed="<?php echo 'late' === $status ? 'true' : 'false'; ?>"><?php esc_html_e( 'Late', 'simple-lms' ); ?></button>
				<button type="button" class="slms-attendance-exception-button is-leave<?php echo 'leave' === $status ? ' is-active' : ''; ?>" data-slms-attendance-special="leave" aria-pressed="<?php echo 'leave' === $status ? 'true' : 'false'; ?>"><?php esc_html_e( 'Leave', 'simple-lms' ); ?></button>
			</div>
			<button type="button" class="slms-attendance-student-toggle" data-slms-attendance-toggle aria-pressed="<?php echo in_array( $status, array( 'present', 'late' ), true ) ? 'true' : 'false'; ?>">
				<span class="slms-attendance-student-portrait">
					<img src="<?php echo esc_url( $this->get_profile_photo_url( $student_id ) ); ?>" alt="<?php echo esc_attr( $name ); ?>">
				</span>
				<span class="slms-attendance-student-name"><?php echo esc_html( $name ); ?></span>
				<span class="slms-attendance-student-meta"><?php echo esc_html( $meta_text ); ?></span>
			</button>
		</div>
		<?php
	}

	private function find_gradebook_student_row( $gradebook, $student_user_id ) {
		if ( is_wp_error( $gradebook ) || empty( $gradebook['students'] ) ) {
			return null;
		}

		foreach ( $gradebook['students'] as $student ) {
			if ( (int) $student['student_user_id'] === (int) $student_user_id ) {
				return $student;
			}
		}

		return null;
	}

	private function calculate_student_assignment_progress( array $assignments ) {
		$summary = array(
			'assigned_weight' => 0.0,
			'graded_weight'   => 0.0,
			'weighted_score'  => 0.0,
		);

		foreach ( $assignments as $assignment ) {
			$weight = (float) ( $assignment['weight'] ?? 0 );

			if ( $weight > 0 ) {
				$summary['assigned_weight'] += $weight;
			}

			$submission    = $assignment['submission'] ?? null;
			$contribution = $this->calculate_assignment_weighted_contribution( $assignment, $submission );

			if ( null !== $contribution ) {
				$summary['graded_weight']  += $weight;
				$summary['weighted_score'] += $contribution;
			}
		}

		return $summary;
	}

	private function calculate_assignment_weighted_contribution( array $assignment, $submission ) {
		if ( empty( $submission ) || ! isset( $submission['score'] ) || null === $submission['score'] ) {
			return null;
		}

		$points = (float) ( $assignment['points'] ?? 0 );
		$weight = (float) ( $assignment['weight'] ?? 0 );

		if ( $points <= 0 || $weight <= 0 ) {
			return null;
		}

		$raw_contribution = ( (float) $submission['score'] / $points ) * $weight;
		$late_details     = AssignmentLatePolicy::get_adjustment_details( $assignment, $submission );

		return $raw_contribution * (float) $late_details['late_multiplier'];
	}

	private function get_attendance_requirement_percentage() {
		return $this->results->get_attendance_requirement_percentage();
	}

	private function get_attendance_participation_weight() {
		return $this->results->get_attendance_participation_weight();
	}

	private function get_results_controlled_weight_cap() {
		return $this->results->get_results_controlled_weight_cap();
	}

	private function get_assignment_weight_total( array $assignments ) {
		return $this->results->get_assignment_weight_total( $assignments );
	}

	private function get_student_result_snapshot( $subject_id, array $assignments, array $attendance_summary, $student_user_id ) {
		return $this->results->get_student_result_snapshot( get_current_user_id(), $subject_id, $student_user_id, $assignments, $attendance_summary );
	}

	private function save_subject_results_override( $subject_id, $student_user_id, $attendance_score, $assignment_score, $final_exam_score, $updated_by = 0 ) {
		$subject_id    = absint( $subject_id );
		$student_key   = (string) absint( $student_user_id );
		$updated_by    = absint( $updated_by );
		$all_overrides = get_post_meta( $subject_id, '_slms_subject_results_overrides', true );
		$all_overrides = is_array( $all_overrides ) ? $all_overrides : array();

		$all_overrides[ $student_key ] = array(
			'attendance_score' => null !== $attendance_score ? round( (float) $attendance_score, 2 ) : null,
			'assignment_score' => null !== $assignment_score ? round( (float) $assignment_score, 2 ) : null,
			'final_exam_score' => null !== $final_exam_score ? round( (float) $final_exam_score, 2 ) : null,
			'updated_by'       => $updated_by,
			'updated_at'       => AcademicClock::mysql(),
		);

		update_post_meta( $subject_id, '_slms_subject_results_overrides', $all_overrides );
	}

	private function can_teach_subject( $subject_id, $role ) {
		if ( in_array( $role, array( 'administrator', 'officer' ), true ) ) {
			return true;
		}

		return 'lecturer' === $role && $this->enrollments->user_can_teach_section( get_current_user_id(), $subject_id );
	}

	private function can_start_discussion_topic( $user_id, $subject_id, $role ) {
		if ( $this->can_teach_subject( $subject_id, $role ) ) {
			return true;
		}

		return ( 'student' === $role || 'lecturer' === $role ) && $this->enrollments->user_can_learn_section( $user_id, $subject_id );
	}

	private function can_student_edit_assignment_submission( array $item_view, $submission ) {
		return ! empty( $submission ) && '' === $this->get_assignment_submission_lock_message( $item_view, $submission );
	}

	private function get_assignment_submission_lock_message( array $item_view, $submission ) {
		if ( 'graded' === (string) ( $submission['status'] ?? '' ) ) {
			return __( 'This submission has already been graded and can no longer be edited.', 'simple-lms' );
		}


		return '';
	}


	private function get_assignment_late_submission_notice( $due_at ) {
		$details = AssignmentLatePolicy::get_adjustment_details( array( 'due_at' => $due_at ), array( 'submitted_at' => AcademicClock::mysql() ) );

		if ( ! empty( $details['is_over_late_limit'] ) ) {
			return __( 'This assignment is more than 10 days late. You may still submit it, but it will not count toward your final score.', 'simple-lms' );
		}

		if ( ! empty( $details['is_late'] ) ) {
			return sprintf(
				/* translators: %s: late policy label */
				__( 'This assignment is past the due date. Late penalties will apply to the final score: %s.', 'simple-lms' ),
				$details['policy_short_label']
			);
		}

		return __( 'Late policy: 5% deduction per started late day after the deadline. Submissions more than 10 days late receive 0 contribution toward the final score.', 'simple-lms' );
	}

	private function format_assignment_deadline_display( $due_at ) {
		$due_at = trim( (string) $due_at );

		if ( '' === $due_at ) {
			return __( 'No deadline', 'simple-lms' );
		}

		$normalized = AssignmentLatePolicy::normalize_datetime( $due_at );

		if ( '' === $normalized ) {
			return $due_at;
		}

		$display = AssignmentLatePolicy::format_datetime_display( $normalized );

		return '' !== $display ? $display : $due_at;
	}

	private function is_assignment_deadline_passed( $due_at ) {
		$due_at = trim( (string) $due_at );

		if ( '' === $due_at ) {
			return false;
		}

		$normalized = AssignmentLatePolicy::normalize_datetime( $due_at );

		if ( '' === $normalized ) {
			return false;
		}

		return AcademicClock::timestamp() > AcademicClock::timestamp_from_local( $normalized );
	}

	private function get_student_upcoming_assignments( $user_id ) {
		$result = $this->assessments->list_assignments_for_user(
			$user_id,
			array(
				'due_filter' => 'upcoming',
				'per_page'   => 100,
				'page'       => 1,
			)
		);

		$items = is_array( $result['items'] ?? null ) ? $result['items'] : array();

		return array_values(
			array_filter(
				$items,
				function ( $assignment ) {
					$assignment_id = absint( $assignment['id'] ?? 0 );

					return $assignment_id && $this->is_subject_item_visible_to_students( $assignment_id, 'assignments' );
				}
			)
		);
	}

	private function get_attendance_modal_id( $subject_id, $session_number ) {
		return 'slms-attendance-modal-' . absint( $subject_id ) . '-' . absint( $session_number );
	}

	private function can_enroll_subject( $subject_id, $role ) {
		return $this->can_teach_subject( $subject_id, $role ) || in_array( $role, array( 'administrator', 'officer' ), true );
	}

	private function can_unenroll_subject( $subject_id, $role ) {
		return $this->can_teach_subject( $subject_id, $role );
	}

	private function can_manage_auditors( $subject_id, $role ) {
		return $this->can_teach_subject( $subject_id, $role ) || in_array( $role, array( 'administrator', 'officer' ), true );
	}

	private function create_material( $subject_id, $role, $user_id ) {
		if ( ! $this->can_teach_subject( $subject_id, $role ) ) {
			return new \WP_Error( 'slms_forbidden_material', __( 'You do not have permission to publish materials for this subject.', 'simple-lms' ) );
		}

		$config     = $this->get_subject_item_config( 'materials' );
		$embed_urls = $this->get_subject_embed_urls_from_request();

		if ( is_wp_error( $embed_urls ) ) {
			return $embed_urls;
		}

		$post_id = wp_insert_post(
			array(
				'post_type'    => 'slms_lesson',
				'post_status'  => 'publish',
				'post_title'   => sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) ),
				'post_content' => wp_kses_post( wp_unslash( $_POST['content'] ?? '' ) ),
				'post_author'  => $user_id,
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$attachments = $this->upload_subject_item_files( 'materials', $post_id );

		if ( is_wp_error( $attachments ) ) {
			wp_delete_post( $post_id, true );
			return $attachments;
		}

		update_post_meta( $post_id, '_slms_section_id', $subject_id );
		$this->save_subject_item_embed_urls( $post_id, $config, $embed_urls );
		update_post_meta( $post_id, '_slms_lesson_visibility', 'students' );
		$this->save_subject_item_week_meta( $post_id, 'materials' );
		$this->save_subject_item_attachments( $post_id, 'materials', array(), $attachments );

		return $post_id;
	}

	private function toggle_material_visibility( $subject_id, $role ) {
		if ( ! $this->can_teach_subject( $subject_id, $role ) ) {
			return new \WP_Error( 'slms_forbidden_material_visibility', __( 'You do not have permission to update material visibility for this subject.', 'simple-lms' ) );
		}

		$item_tab = sanitize_key( wp_unslash( $_POST['item_tab'] ?? 'materials' ) );
		if ( ! in_array( $item_tab, array( 'materials', 'assignments', 'discussions' ), true ) ) {
			$item_tab = 'materials';
		}

		$item_id = absint( $_POST['item_id'] ?? 0 );
		$post    = $this->get_subject_item_post( $subject_id, $item_tab, $item_id );

		if ( ! $post ) {
			return new \WP_Error( 'slms_missing_material', __( 'The selected item could not be found.', 'simple-lms' ) );
		}

		$next_visibility = $this->is_subject_item_visible_to_students( $post->ID, $item_tab ) ? 'staff' : 'students';
		$this->update_subject_item_visibility( $post->ID, $item_tab, $next_visibility );
		clean_post_cache( $post->ID );

		return array(
			'updated'                => 1,
			'is_visible_to_students' => $this->is_subject_item_visible_to_students( $post->ID, $item_tab ),
		);
	}

	private function create_assignment( $subject_id, $role, $user_id ) {
		if ( ! $this->can_teach_subject( $subject_id, $role ) ) {
			return new \WP_Error( 'slms_forbidden_assignment', __( 'You do not have permission to publish assignments for this subject.', 'simple-lms' ) );
		}

		$config     = $this->get_subject_item_config( 'assignments' );
		$embed_urls = $this->get_subject_embed_urls_from_request();

		if ( is_wp_error( $embed_urls ) ) {
			return $embed_urls;
		}

		$weight            = max( 0, (float) wp_unslash( $_POST['weight'] ?? 0 ) );
		$weight_validation = $this->validate_assignment_weight_budget( $subject_id, $weight );

		if ( is_wp_error( $weight_validation ) ) {
			return $weight_validation;
		}

		$post_id = wp_insert_post( array( 'post_type' => 'slms_assignment', 'post_status' => 'publish', 'post_title' => sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) ), 'post_content' => wp_kses_post( wp_unslash( $_POST['content'] ?? '' ) ), 'post_author' => $user_id ), true );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$attachments = $this->upload_subject_item_files( 'assignments', $post_id );

		if ( is_wp_error( $attachments ) ) {
			wp_delete_post( $post_id, true );
			return $attachments;
		}

		update_post_meta( $post_id, '_slms_section_id', $subject_id );
		update_post_meta( $post_id, '_slms_due_at', AssignmentLatePolicy::normalize_datetime( sanitize_text_field( wp_unslash( $_POST['due_at'] ?? '' ) ) ) );
		update_post_meta( $post_id, '_slms_points', AssignmentPointPolicy::normalize_max_points( wp_unslash( $_POST['points'] ?? 100 ) ) );
		update_post_meta( $post_id, '_slms_assignment_weight', $weight );
		update_post_meta( $post_id, '_slms_submission_type', $this->normalize_assignment_submission_type( wp_unslash( $_POST['submission_type'] ?? 'text_file' ), 'text_file' ) );
		$this->save_subject_item_embed_urls( $post_id, $config, $embed_urls );
		update_post_meta( $post_id, '_slms_subject_item_visibility', 'students' );
		$this->save_subject_item_week_meta( $post_id, 'assignments' );
		$this->save_subject_item_attachments( $post_id, 'assignments', array(), $attachments );

		return $post_id;
	}


	private function submit_assignment( $user_id ) {
		$assignment_id       = absint( $_POST['assignment_id'] ?? 0 );


		$assignment_type     = $this->normalize_assignment_submission_type( get_post_meta( $assignment_id, '_slms_submission_type', true ), 'text_file' );
		$allows_file         = $this->assignment_submission_allows_file( $assignment_type );
		$existing_submission = $this->assessments->get_submission( $assignment_id, $user_id );
		$upload              = array();

		if ( $allows_file ) {
			$upload = $this->handle_upload( 'submission_file', 'submissions' );

			if ( is_wp_error( $upload ) ) {
				return $upload;
			}
		} elseif ( ! empty( $_FILES['submission_file'] ) && UPLOAD_ERR_NO_FILE !== (int) ( $_FILES['submission_file']['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			return new \WP_Error( 'slms_submission_file_not_allowed', __( 'This assignment accepts typed answers only. Please remove the file and submit your answer in the text box.', 'simple-lms' ) );
		}

		$submission_text = wp_kses_post( wp_unslash( $_POST['submission_text'] ?? '' ) );
		$attachment_url  = $allows_file ? ( $upload['url'] ?? ( $existing_submission['attachment_url'] ?? '' ) ) : '';
		$attachment_name = $allows_file ? ( $upload['name'] ?? ( $existing_submission['attachment_name'] ?? '' ) ) : '';

		$result = $this->assessments->submit_assignment(
			$assignment_id,
			$user_id,
			array(
				'submission_text' => $submission_text,
				'attachment_url'  => $attachment_url,
				'attachment_name' => $attachment_name,
			)
		);

		if ( is_wp_error( $result ) ) {
			if ( ! empty( $upload['url'] ) ) {
				$this->delete_submission_attachment_asset(
					array(
						'attachment_url' => $upload['url'],
					)
				);
			}

			return $result;
		}

		if ( ! $allows_file && ! empty( $existing_submission['attachment_url'] ) ) {
			$this->delete_submission_attachment_asset( $existing_submission );
		} elseif ( ! empty( $upload['url'] ) && ! empty( $existing_submission['attachment_url'] ) && $existing_submission['attachment_url'] !== $upload['url'] ) {
			$this->delete_submission_attachment_asset( $existing_submission );
		}

		$current_submission = $this->assessments->get_submission( $assignment_id, $user_id );
		$this->send_assignment_submission_confirmation_email( $assignment_id, $user_id, $current_submission ?: array() );

		return $result;
	}


	private function submit_leave_application( $user_id, $role ) {
		if ( 'student' !== $role ) {
			return new \WP_Error( 'slms_leave_forbidden', __( 'Only students can submit leave applications.', 'simple-lms' ) );
		}

		$attachment = $this->upload_leave_application_attachment();
		if ( is_wp_error( $attachment ) ) {
			return $attachment;
		}

		$attachment_record = array();
		if ( ! empty( $attachment ) ) {
			$attachment_record = $attachment;
			$attachment_record['path'] = $this->get_managed_upload_path(
				$attachment['relative_path'] ?? '',
				$attachment['url'] ?? ''
			);
		}

		try {
			return $this->leave_applications->submit_application(
				$user_id,
				array(
					'subject_id' => absint( $_POST['subject_id'] ?? 0 ),
					'leave_type' => sanitize_key( wp_unslash( $_POST['leave_type'] ?? '' ) ),
					'leave_date' => sanitize_text_field( wp_unslash( $_POST['leave_date'] ?? '' ) ),
					'reason'     => wp_unslash( $_POST['leave_reason'] ?? '' ),
				),
				$attachment_record
			);
		} finally {
			if ( ! empty( $attachment_record ) ) {
				$this->delete_managed_upload_file( $attachment_record );
			}
		}
	}


	private function download_assignment_submission( $subject_id, $role, $user_id ) {
		$assignment_id   = absint( $_POST['assignment_id'] ?? 0 );
		$student_user_id = absint( $_POST['student_user_id'] ?? 0 );
		$download_part   = sanitize_key( wp_unslash( $_POST['download_part'] ?? 'file' ) );
		$assignment      = $this->assessments->get_assignment( $assignment_id, $user_id );

		if ( empty( $assignment ) || (int) ( $assignment['section_id'] ?? 0 ) !== (int) $subject_id ) {
			wp_die( esc_html__( 'Invalid assignment download request.', 'simple-lms' ), 400 );
		}

		$can_grade_submission = $this->can_teach_subject( $subject_id, $role );
		$can_download_own     = $student_user_id === (int) $user_id && $this->assessments->can_user_submit_assignment( $assignment_id, $user_id );

		if ( ! $can_grade_submission && ! $can_download_own ) {
			wp_die( esc_html__( 'You do not have permission to download this submission.', 'simple-lms' ), 403 );
		}

		$submission = $this->assessments->get_submission( $assignment_id, $student_user_id );

		if ( empty( $submission ) ) {
			wp_die( esc_html__( 'The requested submission could not be found.', 'simple-lms' ), 404 );
		}

		if ( 'text' === $download_part ) {
			$text = trim( wp_strip_all_tags( (string) ( $submission['submission_text'] ?? '' ) ) );

			if ( '' === $text ) {
				wp_die( esc_html__( 'This submission does not include a typed answer.', 'simple-lms' ), 404 );
			}

			$this->stream_assignment_submission_text( $assignment, $submission );
		}

		$file_path = $this->map_upload_url_to_path( $submission['attachment_url'] ?? '' );

		if ( ! $file_path || ! is_readable( $file_path ) ) {
			$text = trim( wp_strip_all_tags( (string) ( $submission['submission_text'] ?? '' ) ) );

			if ( '' !== $text ) {
				$this->stream_assignment_submission_text( $assignment, $submission );
			}

			wp_die( esc_html__( 'The submitted file is no longer available.', 'simple-lms' ), 404 );
		}

		$this->stream_assignment_submission_file( $file_path, (string) ( $submission['attachment_name'] ?? '' ) );
	}

	private function download_discussion_reply_attachment( $subject_id, $role, $user_id ) {
		$comment_id = absint( $_POST['comment_id'] ?? 0 );
		$comment    = $comment_id ? get_comment( $comment_id ) : null;

		if ( ! $comment instanceof \WP_Comment ) {
			wp_die( esc_html__( 'The requested discussion attachment could not be found.', 'simple-lms' ), 404 );
		}

		$thread_id          = absint( $comment->comment_post_ID );
		$thread_subject_id  = absint( get_post_meta( $thread_id, '_slms_subject_id', true ) );
		$can_teach_subject  = $this->can_teach_subject( $thread_subject_id, $role );
		$can_access_subject = $this->enrollments->user_can_learn_section( $user_id, $thread_subject_id ) || $can_teach_subject || $this->is_discussion_reply_administrator( $role );

		if ( ! $thread_subject_id || (int) $thread_subject_id !== (int) $subject_id || ! $can_access_subject ) {
			wp_die( esc_html__( 'You do not have permission to download this discussion attachment.', 'simple-lms' ), 403 );
		}

		if ( ! $can_teach_subject && ! $this->is_discussion_reply_administrator( $role ) && ! $this->is_subject_item_visible_to_students( $thread_id, 'discussions' ) ) {
			wp_die( esc_html__( 'You do not have permission to download this discussion attachment.', 'simple-lms' ), 403 );
		}

		$attachment = get_comment_meta( $comment_id, '_slms_attachment_url', true );
		$file_path  = $this->map_upload_url_to_path( $attachment );

		if ( ! $file_path || ! is_readable( $file_path ) || ! is_file( $file_path ) ) {
			wp_die( esc_html__( 'The discussion attachment is no longer available.', 'simple-lms' ), 404 );
		}

		$this->stream_assignment_submission_file( $file_path, $this->get_attachment_name_from_url( $attachment ) );
	}

	private function download_assignment_submissions_zip( $subject_id, $role, $user_id ) {
		if ( ! $this->can_use_assignment_batch_download( $role ) ) {
			wp_die(
				esc_html__( 'Batch ZIP downloads are available only to authorized academic staff.', 'simple-lms' ),
				esc_html__( 'Batch Download Restricted', 'simple-lms' ),
				array( 'response' => 403 )
			);
		}

		if ( ! $this->can_teach_subject( $subject_id, $role ) ) {
			wp_die( esc_html__( 'You do not have permission to download these submissions.', 'simple-lms' ), 403 );
		}

		$assignment_id = absint( $_POST['assignment_id'] ?? 0 );
		$assignment    = $this->assessments->get_assignment( $assignment_id, $user_id );

		if ( empty( $assignment ) || (int) ( $assignment['section_id'] ?? 0 ) !== (int) $subject_id ) {
			wp_die( esc_html__( 'Invalid assignment download request.', 'simple-lms' ), 400 );
		}

		$submissions = $this->assessments->list_submissions( $assignment_id );
		$zip_entries = array();
		$used_names  = array();

		foreach ( $submissions as $submission ) {
			$is_audit_submission = ! empty( $submission['is_audit_submission'] ) || 'auditor' === (string) ( $submission['submitter_type'] ?? '' );
			$student_name   = sanitize_file_name( preg_replace( '/\s+/', '-', trim( (string) ( $submission['display_name'] ?? 'student' ) ) ) );
			$assignment_key = sanitize_file_name( preg_replace( '/\s+/', '-', trim( (string) ( $assignment['title'] ?? 'assignment' ) ) ) );
			$student_name   = $student_name ?: 'student';
			$assignment_key = $assignment_key ?: 'assignment';
			$entry_prefix   = $is_audit_submission ? 'Auditor-' : '';

			if ( ! empty( $submission['attachment_url'] ) ) {
				$file_path = $this->map_upload_url_to_path( $submission['attachment_url'] ?? '' );

				if ( $file_path && is_readable( $file_path ) && is_file( $file_path ) ) {
					$file_name = sanitize_file_name( (string) ( $submission['attachment_name'] ?? wp_basename( $file_path ) ) );
					$file_name = $file_name ?: sanitize_file_name( wp_basename( $file_path ) );
					$zip_name  = $this->get_unique_zip_entry_name( $used_names, $entry_prefix . $student_name . '-' . $assignment_key . '-' . $file_name );

					$zip_entries[] = array(
						'name' => $zip_name,
						'path' => $file_path,
					);
				}
			}

			if ( '' !== trim( wp_strip_all_tags( (string) ( $submission['submission_text'] ?? '' ) ) ) ) {
				$zip_name = $this->get_unique_zip_entry_name( $used_names, $entry_prefix . $student_name . '-' . $assignment_key . '-typed-answer.txt' );

				$zip_entries[] = array(
					'name'    => $zip_name,
					'content' => $this->build_assignment_submission_text_download( $assignment, $submission ),
				);
			}
		}

		if ( empty( $zip_entries ) ) {
			wp_die( esc_html__( 'There are no submission files or typed answers available to download yet.', 'simple-lms' ), 404 );
		}

		$temp_file = $this->get_assignment_submissions_zip_temp_file();

		if ( ! $temp_file ) {
			wp_die( esc_html__( 'The submissions ZIP could not be created.', 'simple-lms' ), 500 );
		}

		$zip_result = $this->create_assignment_submissions_zip_file( $temp_file, $zip_entries );

		if ( is_wp_error( $zip_result ) ) {
			if ( file_exists( $temp_file ) ) {
				wp_delete_file( $temp_file );
			}

			wp_die( esc_html( $zip_result->get_error_message() ), 500 );
		}

		$download_name = sanitize_file_name( ( $assignment['title'] ?? 'assignment' ) . '-submissions.zip' );

		$this->clean_output_buffers_before_download();
		nocache_headers();
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $download_name . '"' );
		header( 'Content-Length: ' . (string) filesize( $temp_file ) );
		readfile( $temp_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		wp_delete_file( $temp_file );
		exit;
	}

	private function get_assignment_submissions_zip_temp_file() {
		if ( ! function_exists( 'wp_tempnam' ) && defined( 'ABSPATH' ) ) {
			$wp_file_helpers = trailingslashit( ABSPATH ) . 'wp-admin/includes/file.php';

			if ( is_readable( $wp_file_helpers ) ) {
				require_once $wp_file_helpers;
			}
		}

		if ( function_exists( 'wp_tempnam' ) ) {
			$temp_file = \wp_tempnam( 'slms-assignment-submissions.zip' );

			if ( $temp_file ) {
				return $temp_file;
			}
		}

		$temp_dir = function_exists( 'get_temp_dir' ) ? \get_temp_dir() : sys_get_temp_dir();
		$temp_dir = trailingslashit( $temp_dir );

		if ( ! is_dir( $temp_dir ) || ! is_writable( $temp_dir ) ) {
			$uploads = wp_upload_dir();

			if ( empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) ) {
				$temp_dir = trailingslashit( $uploads['basedir'] ) . 'simple-lms-temp/';

				if ( ! is_dir( $temp_dir ) ) {
					wp_mkdir_p( $temp_dir );
				}
			}
		}

		if ( ! is_dir( $temp_dir ) || ! is_writable( $temp_dir ) ) {
			return false;
		}

		$temp_file = tempnam( $temp_dir, 'slms-zip-' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_tempnam

		return $temp_file ?: false;
	}

	private function create_assignment_submissions_zip_file( $temp_file, array $entries ) {
		if ( class_exists( '\\ZipArchive' ) ) {
			return $this->create_assignment_submissions_zip_with_ziparchive( $temp_file, $entries );
		}

		return $this->create_assignment_submissions_zip_stored( $temp_file, $entries );
	}

	private function create_assignment_submissions_zip_with_ziparchive( $temp_file, array $entries ) {
		$zip = new \ZipArchive();

		if ( true !== $zip->open( $temp_file, \ZipArchive::OVERWRITE ) ) {
			return new \WP_Error( 'slms_zip_create_failed', __( 'The submissions ZIP could not be created.', 'simple-lms' ) );
		}

		foreach ( $entries as $entry ) {
			$zip_name = $this->normalize_zip_entry_name( $entry['name'] ?? '' );

			if ( '' === $zip_name ) {
				continue;
			}

			if ( ! empty( $entry['path'] ) ) {
				$file_path = wp_normalize_path( (string) $entry['path'] );

				if ( ! is_readable( $file_path ) || ! is_file( $file_path ) || ! $zip->addFile( $file_path, $zip_name ) ) {
					$zip->close();
					return new \WP_Error( 'slms_zip_add_file_failed', __( 'One or more submission files could not be added to the ZIP.', 'simple-lms' ) );
				}
			} else {
				if ( ! $zip->addFromString( $zip_name, (string) ( $entry['content'] ?? '' ) ) ) {
					$zip->close();
					return new \WP_Error( 'slms_zip_add_text_failed', __( 'One or more typed answers could not be added to the ZIP.', 'simple-lms' ) );
				}
			}
		}

		if ( true !== $zip->close() ) {
			return new \WP_Error( 'slms_zip_close_failed', __( 'The submissions ZIP could not be finalized.', 'simple-lms' ) );
		}

		return true;
	}

	private function create_assignment_submissions_zip_stored( $temp_file, array $entries ) {
		$handle = fopen( $temp_file, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( ! $handle ) {
			return new \WP_Error( 'slms_zip_create_failed', __( 'The submissions ZIP could not be created.', 'simple-lms' ) );
		}

		$central_directory = array();

		foreach ( $entries as $entry ) {
			$zip_name = $this->normalize_zip_entry_name( $entry['name'] ?? '' );

			if ( '' === $zip_name ) {
				continue;
			}

			$result = ! empty( $entry['path'] )
				? $this->write_stored_zip_file_entry( $handle, $zip_name, wp_normalize_path( (string) $entry['path'] ) )
				: $this->write_stored_zip_string_entry( $handle, $zip_name, (string) ( $entry['content'] ?? '' ) );

			if ( is_wp_error( $result ) ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				return $result;
			}

			$central_directory[] = $result;
		}

		if ( empty( $central_directory ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return new \WP_Error( 'slms_zip_empty', __( 'There are no submission files or typed answers available to download yet.', 'simple-lms' ) );
		}

		$central_offset = ftell( $handle );

		foreach ( $central_directory as $entry ) {
			$header = pack(
				'VvvvvvvVVVvvvvvVV',
				0x02014b50,
				20,
				20,
				0,
				0,
				$entry['time'],
				$entry['date'],
				$entry['crc'],
				$entry['size'],
				$entry['size'],
				strlen( $entry['name'] ),
				0,
				0,
				0,
				0,
				32,
				$entry['offset']
			);

			if ( ! $this->write_zip_bytes( $handle, $header . $entry['name'] ) ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				return new \WP_Error( 'slms_zip_write_failed', __( 'The submissions ZIP could not be written.', 'simple-lms' ) );
			}
		}

		$central_size = ftell( $handle ) - $central_offset;
		$entry_count  = count( $central_directory );
		$footer       = pack( 'VvvvvVVv', 0x06054b50, 0, 0, $entry_count, $entry_count, $central_size, $central_offset, 0 );

		if ( ! $this->write_zip_bytes( $handle, $footer ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return new \WP_Error( 'slms_zip_write_failed', __( 'The submissions ZIP could not be written.', 'simple-lms' ) );
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return true;
	}

	private function write_stored_zip_file_entry( $handle, $zip_name, $file_path ) {
		if ( ! is_readable( $file_path ) || ! is_file( $file_path ) ) {
			return new \WP_Error( 'slms_zip_file_unreadable', __( 'One or more submission files could not be read.', 'simple-lms' ) );
		}

		$size = filesize( $file_path );
		$crc  = hash_file( 'crc32b', $file_path );

		if ( false === $size || false === $crc || $size > 0xffffffff ) {
			return new \WP_Error( 'slms_zip_file_invalid', __( 'One or more submission files are too large for this ZIP download.', 'simple-lms' ) );
		}

		$mtime = filemtime( $file_path );
		$meta  = $this->write_stored_zip_local_header( $handle, $zip_name, (int) $size, hexdec( $crc ), $mtime ?: time() );

		if ( is_wp_error( $meta ) ) {
			return $meta;
		}

		$source = fopen( $file_path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( ! $source ) {
			return new \WP_Error( 'slms_zip_file_unreadable', __( 'One or more submission files could not be read.', 'simple-lms' ) );
		}

		while ( ! feof( $source ) ) {
			$chunk = fread( $source, 1048576 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread

			if ( false === $chunk || ! $this->write_zip_bytes( $handle, $chunk ) ) {
				fclose( $source ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				return new \WP_Error( 'slms_zip_write_failed', __( 'The submissions ZIP could not be written.', 'simple-lms' ) );
			}
		}

		fclose( $source ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return $meta;
	}

	private function write_stored_zip_string_entry( $handle, $zip_name, $content ) {
		$size = strlen( $content );
		$meta = $this->write_stored_zip_local_header( $handle, $zip_name, $size, hexdec( hash( 'crc32b', $content ) ), time() );

		if ( is_wp_error( $meta ) ) {
			return $meta;
		}

		if ( ! $this->write_zip_bytes( $handle, $content ) ) {
			return new \WP_Error( 'slms_zip_write_failed', __( 'The submissions ZIP could not be written.', 'simple-lms' ) );
		}

		return $meta;
	}

	private function write_stored_zip_local_header( $handle, $zip_name, $size, $crc, $timestamp ) {
		if ( $size > 0xffffffff ) {
			return new \WP_Error( 'slms_zip_file_invalid', __( 'One or more submission files are too large for this ZIP download.', 'simple-lms' ) );
		}

		$offset   = ftell( $handle );
		$dos_time = $this->get_zip_dos_time( $timestamp );
		$header   = pack(
			'VvvvvvVVVvv',
			0x04034b50,
			20,
			0,
			0,
			$dos_time['time'],
			$dos_time['date'],
			$crc,
			$size,
			$size,
			strlen( $zip_name ),
			0
		);

		if ( false === $offset || ! $this->write_zip_bytes( $handle, $header . $zip_name ) ) {
			return new \WP_Error( 'slms_zip_write_failed', __( 'The submissions ZIP could not be written.', 'simple-lms' ) );
		}

		return array(
			'name'   => $zip_name,
			'crc'    => $crc,
			'size'   => $size,
			'offset' => $offset,
			'time'   => $dos_time['time'],
			'date'   => $dos_time['date'],
		);
	}

	private function write_zip_bytes( $handle, $bytes ) {
		$length  = strlen( $bytes );
		$written = 0;

		while ( $written < $length ) {
			$result = fwrite( $handle, substr( $bytes, $written ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

			if ( false === $result || 0 === $result ) {
				return false;
			}

			$written += $result;
		}

		return true;
	}

	private function get_zip_dos_time( $timestamp ) {
		$parts = getdate( max( 0, (int) $timestamp ) );
		$year  = max( 1980, (int) ( $parts['year'] ?? 1980 ) );

		return array(
			'time' => ( (int) ( $parts['hours'] ?? 0 ) << 11 ) | ( (int) ( $parts['minutes'] ?? 0 ) << 5 ) | ( (int) ( ( $parts['seconds'] ?? 0 ) / 2 ) ),
			'date' => ( ( $year - 1980 ) << 9 ) | ( (int) ( $parts['mon'] ?? 1 ) << 5 ) | (int) ( $parts['mday'] ?? 1 ),
		);
	}

	private function normalize_zip_entry_name( $name ) {
		$name  = str_replace( '\\', '/', sanitize_text_field( (string) $name ) );
		$parts = array_filter(
			explode( '/', $name ),
			static function ( $part ) {
				return '' !== $part && '.' !== $part && '..' !== $part;
			}
		);

		return implode( '/', $parts );
	}

	private function clean_output_buffers_before_download() {
		while ( ob_get_level() > 0 ) {
			if ( ! @ob_end_clean() ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				break;
			}
		}
	}

	private function stream_assignment_submission_file( $file_path, $file_name = '' ) {
		$file_path = wp_normalize_path( (string) $file_path );

		if ( ! $file_path || ! is_readable( $file_path ) ) {
			wp_die( esc_html__( 'The submitted file is no longer available.', 'simple-lms' ), 404 );
		}

		$file_name = sanitize_file_name( $file_name ?: wp_basename( $file_path ) );
		$file_type = wp_check_filetype( $file_name );
		$mime_type = ! empty( $file_type['type'] ) ? $file_type['type'] : 'application/octet-stream';

		$this->clean_output_buffers_before_download();
		nocache_headers();
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Type: ' . $mime_type );
		header( 'Content-Disposition: attachment; filename="' . $file_name . '"' );
		header( 'Content-Length: ' . (string) filesize( $file_path ) );
		readfile( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	private function stream_assignment_submission_text( array $assignment, array $submission ) {
		$content       = $this->build_assignment_submission_text_download( $assignment, $submission );
		$student_user  = ! empty( $submission['student_user_id'] ) ? get_userdata( (int) $submission['student_user_id'] ) : false;
		$is_audit_submission = ! empty( $submission['is_audit_submission'] ) || 'auditor' === (string) ( $submission['submitter_type'] ?? '' );
		$student_name  = sanitize_file_name( preg_replace( '/\s+/', '-', trim( (string) ( $submission['display_name'] ?? ( $student_user ? $student_user->display_name : 'student' ) ) ) ) );
		$assignment_key = sanitize_file_name( preg_replace( '/\s+/', '-', trim( (string) ( $assignment['title'] ?? 'assignment' ) ) ) );
		$file_name     = sanitize_file_name( ( $is_audit_submission ? 'Auditor-' : '' ) . ( $student_name ?: 'student' ) . '-' . ( $assignment_key ?: 'assignment' ) . '-typed-answer.txt' );

		$this->clean_output_buffers_before_download();
		nocache_headers();
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $file_name . '"' );
		header( 'Content-Length: ' . (string) strlen( $content ) );
		echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	private function build_assignment_submission_text_download( array $assignment, array $submission ) {
		$student_user = ! empty( $submission['student_user_id'] ) ? get_userdata( (int) $submission['student_user_id'] ) : false;
		$student_name = sanitize_text_field( (string) ( $submission['display_name'] ?? ( $student_user ? $student_user->display_name : __( 'Student', 'simple-lms' ) ) ) );
		$student_email = sanitize_email( (string) ( $submission['user_email'] ?? ( $student_user ? $student_user->user_email : '' ) ) );
		$is_audit_submission = ! empty( $submission['is_audit_submission'] ) || 'auditor' === (string) ( $submission['submitter_type'] ?? '' );
		$person_label  = $is_audit_submission ? __( 'Auditor', 'simple-lms' ) : __( 'Student', 'simple-lms' );
		$submitted_at = ! empty( $submission['submitted_at'] ) ? $submission['submitted_at'] : ( $submission['updated_at'] ?? '' );
		$submitted_label = $submitted_at ? AcademicClock::format_local( $submitted_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) : __( 'Not recorded', 'simple-lms' );
		$text = trim( wp_strip_all_tags( (string) ( $submission['submission_text'] ?? '' ) ) );

		$lines = array(
			sprintf( __( 'Assignment: %s', 'simple-lms' ), sanitize_text_field( (string) ( $assignment['title'] ?? '' ) ) ),
			sprintf( __( '%1$s: %2$s', 'simple-lms' ), $person_label, $student_name ),
			sprintf( __( 'Email: %s', 'simple-lms' ), $student_email ?: __( 'Not available', 'simple-lms' ) ),
			sprintf( __( 'Submitted: %s', 'simple-lms' ), $submitted_label ),
			'',
			__( 'Typed Answer / Note:', 'simple-lms' ),
			$text,
			'',
		);

		return implode( "\n", $lines );
	}

	private function get_unique_zip_entry_name( array &$used_names, $name ) {
		$name = sanitize_file_name( (string) $name );

		if ( '' === $name ) {
			$name = 'submission.txt';
		}

		$path_info = pathinfo( $name );
		$extension = ! empty( $path_info['extension'] ) ? '.' . $path_info['extension'] : '';
		$file_stem = $path_info['filename'] ?? $name;
		$zip_name  = $name;
		$counter   = 2;

		while ( isset( $used_names[ $zip_name ] ) ) {
			$zip_name = sanitize_file_name( $file_stem . '-' . $counter . $extension );
			$counter++;
		}

		$used_names[ $zip_name ] = true;

		return $zip_name;
	}

	private function grade_assignment_submission( $subject_id, $role, $user_id ) {
		if ( ! $this->can_teach_subject( $subject_id, $role ) ) {
			return new \WP_Error( 'slms_forbidden_grade', __( 'You do not have permission to grade submissions for this subject.', 'simple-lms' ) );
		}

		$assignment_id   = absint( $_POST['assignment_id'] ?? 0 );
		$student_user_id = absint( $_POST['student_user_id'] ?? 0 );
		$result          = $this->assessments->grade_submission(
			$assignment_id,
			$student_user_id,
			array(
				'score'     => wp_unslash( $_POST['score'] ?? '' ),
				'feedback'  => wp_kses_post( wp_unslash( $_POST['feedback'] ?? '' ) ),
				'graded_by' => $user_id,
			)
		);

		if ( ! is_wp_error( $result ) && is_array( $result ) && 'graded' === ( $result['status'] ?? '' ) && null !== ( $result['score'] ?? null ) ) {
			$this->send_assignment_graded_email( $assignment_id, $student_user_id );
		}

		return $result;
	}

	private function enroll_by_codes( $subject_id, $role, $user_id ) {
		if ( ! $this->can_enroll_subject( $subject_id, $role ) ) {
			return new \WP_Error( 'slms_forbidden_enrollment', __( 'You do not have permission to enroll students for this subject.', 'simple-lms' ) );
		}
		$codes  = preg_split( '/[,\s]+/', (string) wp_unslash( $_POST['student_codes'] ?? '' ) );
		$stats  = array( 'enrolled' => 0, 'failed' => 0, 'errors' => array() );
		foreach ( array_filter( array_map( 'trim', $codes ) ) as $code ) {
			$result = $this->enrollments->enroll_student_by_code( $subject_id, $code, array( 'created_by' => $user_id, 'source' => 'manual_code' ) );
			if ( is_wp_error( $result ) ) { $stats['failed']++; $stats['errors'][] = $result->get_error_message(); } else { $stats['enrolled']++; }
		}
		return $stats;
	}

	private function enroll_by_import( $subject_id, $role, $user_id ) {
		if ( ! $this->can_enroll_subject( $subject_id, $role ) ) {
			return new \WP_Error( 'slms_forbidden_import', __( 'You do not have permission to import enrollments for this subject.', 'simple-lms' ) );
		}
		$rows = $this->imports->read_rows_from_request( '', 'enroll_file' );
		if ( is_wp_error( $rows ) ) { return $rows; }
		return $this->imports->import_enrollments_by_code( $subject_id, $rows, array( 'created_by' => $user_id, 'source' => 'file_import' ) );
	}

	private function enroll_auditors_by_codes( $subject_id, $role, $user_id ) {
		if ( ! $this->can_manage_auditors( $subject_id, $role ) ) {
			return new \WP_Error( 'slms_forbidden_auditor_enrollment', __( 'You do not have permission to enroll auditors for this subject.', 'simple-lms' ) );
		}

		$codes = preg_split( '/[,\s]+/', (string) wp_unslash( $_POST['staff_codes'] ?? '' ) );
		$codes = array_values( array_unique( array_filter( array_map( 'trim', (array) $codes ) ) ) );

		if ( empty( $codes ) ) {
			return new \WP_Error( 'slms_missing_staff_codes', __( 'Please enter at least one staff ID.', 'simple-lms' ) );
		}

		$stats = array(
			'enrolled' => 0,
			'failed'   => 0,
			'errors'   => array(),
		);

		foreach ( $codes as $code ) {
			$result = $this->enrollments->enroll_staff_auditor_by_code(
				$subject_id,
				$code,
				array(
					'created_by' => $user_id,
					'source'     => 'manual_staff_code',
				)
			);

			if ( is_wp_error( $result ) ) {
				$stats['failed']++;
				$stats['errors'][] = sprintf( '%1$s: %2$s', $code, $result->get_error_message() );
			} else {
				$stats['enrolled']++;
			}
		}

		return $stats;
	}

	private function drop_staff_auditor_from_subject( $subject_id, $role, $user_id ) {
		unset( $user_id );

		if ( ! $this->can_manage_auditors( $subject_id, $role ) ) {
			return new \WP_Error( 'slms_forbidden_auditor_drop', __( 'You do not have permission to remove auditors from this subject.', 'simple-lms' ) );
		}

		$staff_user_id = absint( $_POST['staff_user_id'] ?? 0 );
		$posted_id     = absint( $_POST['audit_enrollment_id'] ?? 0 );

		if ( ! $staff_user_id ) {
			return new \WP_Error( 'slms_missing_staff_auditor', __( 'A valid staff auditor was not provided.', 'simple-lms' ) );
		}

		$existing_rows = $this->enrollments->list_staff_audit_enrollments(
			array(
				'section_id'     => $subject_id,
				'staff_user_id'  => $staff_user_id,
				'statuses'       => array( 'active' ),
			)
		);
		$existing = ! empty( $existing_rows ) ? $existing_rows[0] : array();

		if ( empty( $existing ) ) {
			return new \WP_Error( 'slms_missing_staff_audit_enrollment', __( 'That staff auditor is not actively auditing this subject.', 'simple-lms' ) );
		}

		if ( $posted_id && $posted_id !== absint( $existing['id'] ?? 0 ) ) {
			return new \WP_Error( 'slms_staff_audit_mismatch', __( 'The auditor enrollment record no longer matches the selected staff member.', 'simple-lms' ) );
		}

		return $this->enrollments->drop_staff_auditor_from_section( $subject_id, $staff_user_id );
	}

	private function unenroll_student_from_subject( $subject_id, $role, $user_id ) {
		if ( ! $this->can_unenroll_subject( $subject_id, $role ) ) {
			return new \WP_Error( 'slms_forbidden_unenrollment', __( 'You do not have permission to unenroll students from this subject.', 'simple-lms' ) );
		}

		$student_user_id = absint( $_POST['student_user_id'] ?? 0 );

		if ( ! $student_user_id ) {
			return new \WP_Error( 'slms_missing_student', __( 'A valid student was not provided.', 'simple-lms' ) );
		}

		$existing_rows = $this->enrollments->list_enrollments(
			array(
				'section_id'      => $subject_id,
				'student_user_id' => $student_user_id,
			)
		);
		$existing      = ! empty( $existing_rows ) ? $existing_rows[0] : array();
		$posted_id     = absint( $_POST['enrollment_id'] ?? 0 );

		if ( empty( $existing ) ) {
			return new \WP_Error( 'slms_missing_enrollment', __( 'That student is not enrolled in this subject.', 'simple-lms' ) );
		}

		if ( $posted_id && $posted_id !== absint( $existing['id'] ?? 0 ) ) {
			return new \WP_Error( 'slms_enrollment_mismatch', __( 'The enrollment record no longer matches the selected student.', 'simple-lms' ) );
		}

		return $this->enrollments->drop_student_from_section( $subject_id, $student_user_id );
	}

	private function create_thread( $subject_id, $role, $user_id ) {
		if ( ! $this->can_start_discussion_topic( $user_id, $subject_id, $role ) ) {
			return new \WP_Error( 'slms_forbidden_thread', __( 'You do not have permission to publish discussion topics for this subject.', 'simple-lms' ) );
		}

		$config     = $this->get_subject_item_config( 'discussions' );
		$embed_urls = $this->get_subject_embed_urls_from_request();

		if ( is_wp_error( $embed_urls ) ) {
			return $embed_urls;
		}

		$post_id = wp_insert_post( array( 'post_type' => 'slms_discussion', 'post_status' => 'publish', 'post_title' => sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) ), 'post_content' => wp_kses_post( wp_unslash( $_POST['content'] ?? '' ) ), 'post_author' => $user_id, 'comment_status' => 'open' ), true );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$attachments = $this->upload_subject_item_files( 'discussions', $post_id );

		if ( is_wp_error( $attachments ) ) {
			wp_delete_post( $post_id, true );
			return $attachments;
		}

		update_post_meta( $post_id, '_slms_subject_id', $subject_id );
		$this->save_subject_item_embed_urls( $post_id, $config, $embed_urls );
		update_post_meta( $post_id, '_slms_subject_item_visibility', 'students' );
		$this->save_subject_item_week_meta( $post_id, 'discussions' );
		$this->save_subject_item_attachments( $post_id, 'discussions', array(), $attachments );

		return $post_id;
	}

	private function reply_thread( $user_id ) {
		$thread_id = absint( $_POST['thread_id'] ?? 0 );
		$thread    = get_post( $thread_id );
		if ( ! $thread || 'slms_discussion' !== $thread->post_type ) { return new \WP_Error( 'slms_invalid_thread', __( 'Invalid discussion thread.', 'simple-lms' ) ); }
		$subject_id = absint( get_post_meta( $thread_id, '_slms_subject_id', true ) );
		if ( ! $this->enrollments->user_can_learn_section( $user_id, $subject_id ) && ! $this->can_teach_subject( $subject_id, RoleManager::get_primary_role( $user_id ) ) ) { return new \WP_Error( 'slms_forbidden_thread', __( 'You do not have access to this subject discussion.', 'simple-lms' ) ); }
		$content = wp_kses_post( wp_unslash( $_POST['reply_content'] ?? '' ) );
		if ( '' === trim( wp_strip_all_tags( $content ) ) ) { return new \WP_Error( 'slms_empty_reply', __( 'Please write a reply before publishing.', 'simple-lms' ) ); }

		$parent_comment_id = absint( $_POST['parent_comment_id'] ?? 0 );
		if ( $parent_comment_id ) {
			$parent_comment = get_comment( $parent_comment_id );
			if ( ! $parent_comment || (int) $parent_comment->comment_post_ID !== $thread_id || '1' !== (string) $parent_comment->comment_approved ) {
				return new \WP_Error( 'slms_invalid_parent_reply', __( 'The reply you are responding to could not be found.', 'simple-lms' ) );
			}
		}

		$upload    = $this->handle_upload( 'reply_file', 'discussion-replies' );
		if ( is_wp_error( $upload ) ) { return $upload; }
		$comment_id = wp_insert_comment( array( 'comment_post_ID' => $thread_id, 'comment_parent' => $parent_comment_id, 'comment_content' => $content, 'user_id' => $user_id, 'comment_author' => wp_get_current_user()->display_name, 'comment_author_email' => wp_get_current_user()->user_email, 'comment_approved' => 1 ) );
		if ( ! $comment_id ) { return new \WP_Error( 'slms_reply_failed', __( 'The reply could not be saved.', 'simple-lms' ) ); }
		if ( ! empty( $upload['url'] ) ) { update_comment_meta( $comment_id, '_slms_attachment_url', $upload['url'] ); }
		return $comment_id;
	}

	private function update_discussion_reply( $user_id, $role ) {
		$reply_context = $this->get_discussion_reply_management_context( $user_id, $role );
		if ( is_wp_error( $reply_context ) ) { return $reply_context; }

		$content = wp_kses_post( wp_unslash( $_POST['reply_content'] ?? '' ) );
		if ( '' === trim( wp_strip_all_tags( $content ) ) ) { return new \WP_Error( 'slms_empty_reply', __( 'Please write a reply before saving.', 'simple-lms' ) ); }

		$updated = wp_update_comment(
			array(
				'comment_ID'      => (int) $reply_context['comment']->comment_ID,
				'comment_content' => $content,
			)
		);

		if ( false === $updated ) {
			return new \WP_Error( 'slms_reply_update_failed', __( 'The reply could not be updated.', 'simple-lms' ) );
		}

		update_comment_meta( (int) $reply_context['comment']->comment_ID, '_slms_reply_edited_at', AcademicClock::mysql() );

		return true;
	}

	private function delete_discussion_reply( $user_id, $role ) {
		$reply_context = $this->get_discussion_reply_management_context( $user_id, $role );
		if ( is_wp_error( $reply_context ) ) { return $reply_context; }

		$comment_id = (int) $reply_context['comment']->comment_ID;
		$this->delete_discussion_reply_attachment_file( $comment_id );

		$deleted = wp_delete_comment( $comment_id, true );
		if ( ! $deleted ) {
			return new \WP_Error( 'slms_reply_delete_failed', __( 'The reply could not be deleted.', 'simple-lms' ) );
		}

		return true;
	}

	private function get_discussion_reply_management_context( $user_id, $role ) {
		$comment_id = absint( $_POST['comment_id'] ?? 0 );
		$thread_id  = absint( $_POST['thread_id'] ?? 0 );
		$comment    = get_comment( $comment_id );

		if ( ! $comment || ! $thread_id || (int) $comment->comment_post_ID !== $thread_id ) {
			return new \WP_Error( 'slms_invalid_reply', __( 'Invalid discussion reply.', 'simple-lms' ) );
		}

		$thread = get_post( $thread_id );
		if ( ! $thread || 'slms_discussion' !== $thread->post_type ) {
			return new \WP_Error( 'slms_invalid_thread', __( 'Invalid discussion thread.', 'simple-lms' ) );
		}

		$subject_id = absint( get_post_meta( $thread_id, '_slms_subject_id', true ) );
		if ( ! $subject_id ) {
			return new \WP_Error( 'slms_invalid_subject', __( 'Invalid subject discussion.', 'simple-lms' ) );
		}

		if ( ! $this->can_manage_discussion_reply( $comment, $user_id, $role, $subject_id ) ) {
			return new \WP_Error( 'slms_forbidden_reply', __( 'You do not have permission to manage this reply.', 'simple-lms' ) );
		}

		return array(
			'comment'    => $comment,
			'thread_id'  => $thread_id,
			'subject_id' => $subject_id,
		);
	}

	private function can_manage_discussion_reply( $comment, $user_id, $role, $subject_id ) {
		if ( ! $comment instanceof \WP_Comment ) {
			return false;
		}

		if ( $this->is_discussion_reply_administrator( $role ) ) {
			return true;
		}

		return (int) $comment->user_id === (int) $user_id && ( $this->enrollments->user_can_learn_section( $user_id, $subject_id ) || $this->can_teach_subject( $subject_id, $role ) );
	}

	private function is_discussion_reply_administrator( $role ) {
		return 'administrator' === sanitize_key( (string) $role ) || current_user_can( 'manage_options' );
	}

	private function delete_discussion_reply_attachment_file( $comment_id ) {
		$attachment = get_comment_meta( $comment_id, '_slms_attachment_url', true );
		if ( ! $attachment ) {
			return;
		}

		$file_path = $this->map_upload_url_to_path( $attachment );
		if ( $file_path && file_exists( $file_path ) ) {
			wp_delete_file( $file_path );
		}
	}

	private function update_subject_item( $tab, $subject_id, $role, $user_id ) {
		$post_id = absint( $_POST['item_id'] ?? 0 );
		$post    = $this->get_subject_item_post( $subject_id, $tab, $post_id );
		$config  = $this->get_subject_item_config( $tab );

		if ( ! $post || empty( $config ) ) {
			return new \WP_Error( 'slms_invalid_subject_item', __( 'The selected subject item could not be found.', 'simple-lms' ) );
		}

		if ( ! $this->can_teach_subject( $subject_id, $role ) ) {
			return new \WP_Error( 'slms_forbidden_subject_item', __( 'You do not have permission to manage this subject item.', 'simple-lms' ) );
		}

		$embed_urls = $this->get_subject_embed_urls_from_request();

		if ( is_wp_error( $embed_urls ) ) {
			return $embed_urls;
		}

		$updated = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_title'   => sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) ),
				'post_content' => wp_kses_post( wp_unslash( $_POST['content'] ?? '' ) ),
				'post_author'  => $user_id,
			),
			true
		);

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		if ( 'assignments' === $tab ) {
			$weight            = max( 0, (float) wp_unslash( $_POST['weight'] ?? 0 ) );
			$weight_validation = $this->validate_assignment_weight_budget( $subject_id, $weight, $post_id );

			if ( is_wp_error( $weight_validation ) ) {
				return $weight_validation;
			}

			update_post_meta( $post_id, '_slms_due_at', AssignmentLatePolicy::normalize_datetime( sanitize_text_field( wp_unslash( $_POST['due_at'] ?? '' ) ) ) );
			update_post_meta( $post_id, '_slms_points', AssignmentPointPolicy::normalize_max_points( wp_unslash( $_POST['points'] ?? 100 ) ) );
			update_post_meta( $post_id, '_slms_assignment_weight', $weight );
			update_post_meta( $post_id, '_slms_submission_type', $this->normalize_assignment_submission_type( wp_unslash( $_POST['submission_type'] ?? 'text_file' ), 'text_file' ) );
		}

		$this->save_subject_item_embed_urls( $post_id, $config, $embed_urls );
		$this->save_subject_item_week_meta( $post_id, $tab );

		$attachment_state = $this->merge_subject_item_attachments( $post_id, $tab );

		if ( is_wp_error( $attachment_state ) ) {
			return $attachment_state;
		}

		$this->save_subject_item_attachments( $post_id, $tab, $attachment_state['attachment_ids'], $attachment_state['managed_attachments'], $attachment_state['legacy_attachment'] );
		$this->delete_subject_item_media_attachments( $attachment_state['removed_attachments'] );

		return $post_id;
	}

	private function save_subject_item_week_meta( $post_id, $tab ) {
		$config = $this->get_subject_item_config( $tab );

		if ( empty( $config ) ) {
			return;
		}

		$force_general = 'discussions' === $tab && (bool) get_post_meta( $post_id, '_slms_is_welcome_thread', true );
		$week_number   = $force_general ? 0 : absint( wp_unslash( $_POST['week_number'] ?? 0 ) );
		$week_order    = $force_general ? 0 : absint( wp_unslash( $_POST['week_order'] ?? 0 ) );

		if ( ! empty( $config['week_number_meta_key'] ) ) {
			update_post_meta( $post_id, $config['week_number_meta_key'], $week_number );
		} elseif ( 'discussions' === $tab ) {
			delete_post_meta( $post_id, '_slms_week_number' );
		}

		if ( ! empty( $config['week_order_meta_key'] ) ) {
			update_post_meta( $post_id, $config['week_order_meta_key'], $week_order );
		} elseif ( 'discussions' === $tab ) {
			delete_post_meta( $post_id, '_slms_week_order' );
		}
	}

	private function get_subject_current_week_number( array $weeks, $attendance_dashboard ) {
		$defined_numbers = array_map(
			static function ( $week ) {
				return absint( $week['week_number'] ?? 0 );
			},
			$weeks
		);
		$defined_numbers = array_values( array_filter( $defined_numbers ) );
		$candidate       = 0;

		if ( is_array( $attendance_dashboard ) ) {
			$scheduled_numbers = array();
			$completed_numbers = array();

			foreach ( $attendance_dashboard['sessions'] ?? array() as $session ) {
				if ( 'cancelled' === ( $session['status'] ?? '' ) ) {
					continue;
				}

				$session_number = absint( $session['session_number'] ?? 0 );

				if ( ! $session_number ) {
					continue;
				}

				if ( 'completed' === ( $session['status'] ?? '' ) || (int) ( $session['record_count'] ?? 0 ) > 0 ) {
					$completed_numbers[] = $session_number;
				} else {
					$scheduled_numbers[] = $session_number;
				}
			}

			if ( ! empty( $scheduled_numbers ) ) {
				sort( $scheduled_numbers, SORT_NUMERIC );
				$candidate = (int) $scheduled_numbers[0];
			} elseif ( ! empty( $completed_numbers ) ) {
				sort( $completed_numbers, SORT_NUMERIC );
				$candidate = (int) end( $completed_numbers ) + 1;
			} else {
				$candidate = absint( $attendance_dashboard['next_session_number'] ?? 0 );
			}
		}

		if ( ! $candidate && ! empty( $defined_numbers ) ) {
			$candidate = (int) min( $defined_numbers );
		}

		return $this->align_subject_current_week_number( $candidate, $defined_numbers );
	}

	private function align_subject_current_week_number( $candidate, array $defined_numbers ) {
		$candidate = absint( $candidate );

		if ( ! $candidate ) {
			return 0;
		}

		if ( empty( $defined_numbers ) ) {
			return $candidate;
		}

		sort( $defined_numbers, SORT_NUMERIC );

		if ( in_array( $candidate, $defined_numbers, true ) ) {
			return $candidate;
		}

		if ( $candidate <= (int) $defined_numbers[0] ) {
			return (int) $defined_numbers[0];
		}

		foreach ( $defined_numbers as $defined_number ) {
			if ( $defined_number >= $candidate ) {
				return (int) $defined_number;
			}
		}

		return (int) end( $defined_numbers );
	}

	private function get_subject_week_group_empty_message( $tab, array $bucket ) {
		if ( ! empty( $bucket['is_general'] ) ) {
			return __( 'This general area is ready for unassigned items, welcome content, or catch-all resources.', 'simple-lms' );
		}

		switch ( $tab ) {
			case 'assignments':
				return __( 'No assignments have been added to this week yet.', 'simple-lms' );
			case 'discussions':
				return __( 'No discussions have been started in this week yet.', 'simple-lms' );
			case 'materials':
			default:
				return __( 'No materials have been added to this week yet.', 'simple-lms' );
		}
	}

	private function delete_subject_item_attachment( $tab, $subject_id, $role ) {
		$post_id = absint( $_POST['item_id'] ?? 0 );
		$post    = $this->get_subject_item_post( $subject_id, $tab, $post_id );

		if ( ! $post ) {
			return new \WP_Error( 'slms_invalid_subject_item', __( 'The selected subject item could not be found.', 'simple-lms' ) );
		}

		if ( ! $this->can_teach_subject( $subject_id, $role ) ) {
			return new \WP_Error( 'slms_forbidden_subject_item', __( 'You do not have permission to manage files for this subject item.', 'simple-lms' ) );
		}

		$attachment_token = sanitize_text_field( wp_unslash( $_POST['attachment_token'] ?? '' ) );
		$attachment_state = $this->partition_subject_item_attachments( $post_id, $tab, array( $attachment_token ) );

		if ( empty( $attachment_state['removed_attachments'] ) ) {
			return new \WP_Error( 'slms_missing_subject_attachment', __( 'The selected file could not be found.', 'simple-lms' ) );
		}

		$this->save_subject_item_attachments( $post_id, $tab, $attachment_state['attachment_ids'], $attachment_state['managed_attachments'], $attachment_state['legacy_attachment'] );
		$this->delete_subject_item_media_attachments( $attachment_state['removed_attachments'] );

		return true;
	}

	private function delete_subject_item( $tab, $subject_id, $role ) {
		$post_id = absint( $_POST['item_id'] ?? 0 );
		$post    = $this->get_subject_item_post( $subject_id, $tab, $post_id );

		if ( ! $post ) {
			return new \WP_Error( 'slms_invalid_subject_item', __( 'The selected subject item could not be found.', 'simple-lms' ) );
		}

		if ( ! $this->can_teach_subject( $subject_id, $role ) ) {
			return new \WP_Error( 'slms_forbidden_subject_item', __( 'You do not have permission to delete this subject item.', 'simple-lms' ) );
		}

		$this->delete_subject_item_media_attachments( $this->get_subject_item_attachments( $post_id, $tab ) );
		$deleted = wp_delete_post( $post_id, true );

		if ( ! $deleted ) {
			return new \WP_Error( 'slms_delete_failed', __( 'The subject item could not be deleted.', 'simple-lms' ) );
		}

		return true;
	}

	private function delete_subject_item_media_attachments( array $attachments ) {
		$deleted_ids    = array();
		$deleted_tokens = array();

		foreach ( $attachments as $attachment ) {
			$token = sanitize_text_field( (string) ( $attachment['token'] ?? '' ) );

			if ( $token && in_array( $token, $deleted_tokens, true ) ) {
				continue;
			}

			$attachment_id = absint( $attachment['id'] ?? 0 );

			if ( $attachment_id ) {
				if ( ! in_array( $attachment_id, $deleted_ids, true ) ) {
					wp_delete_attachment( $attachment_id, true );
					$deleted_ids[] = $attachment_id;
				}
			} else {
				$this->delete_managed_upload_file( $attachment );
			}

			if ( $token ) {
				$deleted_tokens[] = $token;
			}
		}
	}

	private function merge_subject_item_attachments( $post_id, $tab ) {
		$attachment_state = $this->partition_subject_item_attachments(
			$post_id,
			$tab,
			array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['remove_attachment_tokens'] ?? array() ) )
		);

		$new_uploads = $this->upload_subject_item_files( $tab, $post_id );

		if ( is_wp_error( $new_uploads ) ) {
			return $new_uploads;
		}

		$attachment_state['managed_attachments'] = array_values( array_merge( $attachment_state['managed_attachments'], $new_uploads ) );

		return $attachment_state;
	}

	private function partition_subject_item_attachments( $post_id, $tab, array $remove_tokens = array() ) {
		$current_files      = $this->get_subject_item_attachments( $post_id, $tab );
		$attachment_ids     = array();
		$managed_attachments = array();
		$legacy_attachment  = array();
		$removed_attachments = array();
		$remove_tokens      = array_values( array_filter( array_map( 'sanitize_text_field', $remove_tokens ) ) );

		foreach ( $current_files as $attachment ) {
			if ( in_array( $attachment['token'], $remove_tokens, true ) ) {
				$removed_attachments[] = $attachment;
				continue;
			}

			if ( ! empty( $attachment['id'] ) ) {
				$attachment_ids[] = (int) $attachment['id'];
			} elseif ( ! empty( $attachment['is_managed'] ) ) {
				$managed_attachments[] = $this->extract_managed_attachment_meta( $attachment );
			} elseif ( empty( $legacy_attachment['url'] ) ) {
				$legacy_attachment = array(
					'url'  => (string) $attachment['url'],
					'name' => (string) $attachment['name'],
				);
			}
		}

		return array(
			'attachment_ids'     => array_values( array_unique( $attachment_ids ) ),
			'managed_attachments' => $managed_attachments,
			'legacy_attachment'  => $legacy_attachment,
			'removed_attachments' => $removed_attachments,
		);
	}

	private function extract_managed_attachment_meta( array $attachment ) {
		return array(
			'relative_path' => ltrim( str_replace( '\\', '/', sanitize_text_field( (string) ( $attachment['relative_path'] ?? '' ) ) ), '/' ),
			'url'           => esc_url_raw( (string) ( $attachment['stored_url'] ?? $attachment['url'] ?? '' ) ),
			'name'          => sanitize_text_field( (string) ( $attachment['name'] ?? '' ) ),
			'mime'          => sanitize_text_field( (string) ( $attachment['mime'] ?? '' ) ),
		);
	}

	private function save_subject_item_attachments( $post_id, $tab, array $attachment_ids, array $managed_attachments = array(), array $legacy_attachment = array() ) {
		$config              = $this->get_subject_item_config( $tab );
		$managed_attachments = $this->normalize_managed_attachment_records( $managed_attachments );

		$attachment_ids = array_values( array_unique( array_filter( array_map( 'absint', $attachment_ids ) ) ) );

		if ( $attachment_ids ) {
			update_post_meta( $post_id, $config['attachment_meta_key'], $attachment_ids );
		} else {
			delete_post_meta( $post_id, $config['attachment_meta_key'] );
		}

		if ( $managed_attachments ) {
			update_post_meta( $post_id, $config['managed_attachment_meta_key'], $managed_attachments );
		} else {
			delete_post_meta( $post_id, $config['managed_attachment_meta_key'] );
		}

		$primary_attachment = array();
		if ( ! empty( $attachment_ids[0] ) ) {
			$primary_attachment = $this->build_attachment_record_from_id( (int) $attachment_ids[0] );
		} elseif ( ! empty( $managed_attachments[0] ) ) {
			$primary_attachment = $this->build_managed_attachment_record( $managed_attachments[0] );
		}

		$primary_url  = ! empty( $legacy_attachment['url'] ) ? esc_url_raw( $legacy_attachment['url'] ) : ( $primary_attachment['url'] ?? '' );
		$primary_name = ! empty( $legacy_attachment['name'] ) ? sanitize_text_field( $legacy_attachment['name'] ) : ( $primary_attachment['name'] ?? '' );
		$primary_id   = ! empty( $attachment_ids[0] ) ? (int) $attachment_ids[0] : 0;

		if ( ! empty( $config['legacy_url_meta_key'] ) ) {
			if ( $primary_url ) {
				update_post_meta( $post_id, $config['legacy_url_meta_key'], $primary_url );
			} else {
				delete_post_meta( $post_id, $config['legacy_url_meta_key'] );
			}
		}

		if ( ! empty( $config['legacy_name_meta_key'] ) ) {
			if ( $primary_name ) {
				update_post_meta( $post_id, $config['legacy_name_meta_key'], $primary_name );
			} else {
				delete_post_meta( $post_id, $config['legacy_name_meta_key'] );
			}
		}

		if ( ! empty( $config['legacy_attachment_id_meta_key'] ) ) {
			if ( $primary_id ) {
				update_post_meta( $post_id, $config['legacy_attachment_id_meta_key'], $primary_id );
			} else {
				delete_post_meta( $post_id, $config['legacy_attachment_id_meta_key'] );
			}
		}
	}

	private function upload_subject_item_files( $tab, $post_id ) {
		unset( $post_id );

		$config = $this->get_subject_item_config( $tab );

		return $this->handle_multiple_uploads( $config['upload_field'] ?? '', 'subject-' . sanitize_key( $tab ) );
	}

	private function handle_multiple_uploads( $field_name, $upload_subdirectory = '' ) {
		if ( ! $field_name || empty( $_FILES[ $field_name ] ) ) {
			return array();
		}

		$files = $this->normalize_uploaded_files( $_FILES[ $field_name ] );

		if ( empty( $files ) ) {
			return array();
		}

		$uploads = array();

		foreach ( $files as $file ) {
			if ( UPLOAD_ERR_NO_FILE === (int) $file['error'] ) {
				continue;
			}

			$uploaded = $this->upload_managed_file( $file, $upload_subdirectory, 100 * MB_IN_BYTES );

			if ( is_wp_error( $uploaded ) ) {
				return $uploaded;
			}

			if ( ! empty( $uploaded ) ) {
				$uploads[] = $uploaded;
			}
		}

		return $uploads;
	}

	private function normalize_uploaded_files( array $file_group ) {
		if ( empty( $file_group['name'] ) ) {
			return array();
		}

		if ( ! is_array( $file_group['name'] ) ) {
			return array( $file_group );
		}

		$files = array();

		foreach ( array_keys( $file_group['name'] ) as $index ) {
			$files[] = array(
				'name'     => $file_group['name'][ $index ] ?? '',
				'type'     => $file_group['type'][ $index ] ?? '',
				'tmp_name' => $file_group['tmp_name'][ $index ] ?? '',
				'error'    => $file_group['error'][ $index ] ?? UPLOAD_ERR_NO_FILE,
				'size'     => $file_group['size'][ $index ] ?? 0,
			);
		}

		return $files;
	}

	private function upload_managed_file( array $file, $upload_subdirectory = '', $max_size = 100 * MB_IN_BYTES, array $allowed_mimes = array() ) {
		if ( empty( $file['tmp_name'] ) && UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			return new \WP_Error( 'slms_upload_failed', __( 'One of the files could not be uploaded.', 'simple-lms' ) );
		}

		if ( empty( $file['tmp_name'] ) || UPLOAD_ERR_NO_FILE === (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			return array();
		}

		if ( UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			return new \WP_Error( 'slms_upload_failed', __( 'One of the files could not be uploaded.', 'simple-lms' ) );
		}

		if ( ! empty( $file['size'] ) && (int) $file['size'] > $max_size ) {
			return new \WP_Error( 'slms_upload_too_large', sprintf( __( 'Each uploaded file must be %s or smaller.', 'simple-lms' ), size_format( $max_size ) ) );
		}

		if ( ! empty( $allowed_mimes ) ) {
			$filetype = wp_check_filetype_and_ext( $file['tmp_name'], (string) ( $file['name'] ?? '' ), $allowed_mimes );

			if ( empty( $filetype['ext'] ) || empty( $filetype['type'] ) ) {
				return new \WP_Error( 'slms_upload_invalid_type', __( 'That file type is not allowed for this upload.', 'simple-lms' ) );
			}
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		$upload_file = $file;

		if ( preg_match( '#^(submissions|discussion-replies)(/|$)#', trim( (string) $upload_subdirectory, "/\\" ) ) ) {
			$extension = strtolower( (string) pathinfo( (string) ( $file['name'] ?? '' ), PATHINFO_EXTENSION ) );
			$upload_file['name'] = wp_generate_uuid4() . ( $extension ? '.' . sanitize_key( $extension ) : '' );
		}

		$uploaded = $this->with_managed_upload_subdirectory(
			$upload_subdirectory,
			static function () use ( $upload_file, $allowed_mimes ) {
				$overrides = array( 'test_form' => false );

				if ( ! empty( $allowed_mimes ) ) {
					$overrides['mimes'] = $allowed_mimes;
				}

				return wp_handle_upload( $upload_file, $overrides );
			}
		);

		if ( ! empty( $uploaded['error'] ) ) {
			return new \WP_Error( 'slms_upload_failed', sanitize_text_field( $uploaded['error'] ) );
		}

		$protection = $this->protect_managed_upload_directory( (string) ( $uploaded['file'] ?? '' ) );

		if ( is_wp_error( $protection ) ) {
			if ( ! empty( $uploaded['file'] ) && is_file( $uploaded['file'] ) ) {
				wp_delete_file( $uploaded['file'] );
			}

			return $protection;
		}

		return $this->create_managed_upload_meta( $uploaded, (string) ( $file['name'] ?? '' ) );
	}

	private function protect_managed_upload_directory( $file_path ) {
		$file_path = ManagedUploadPath::canonical_file( $file_path );
		$directory = $file_path ? dirname( $file_path ) : '';

		if ( ! $directory || ! is_dir( $directory ) ) {
			return new \WP_Error( 'slms_upload_directory_missing', __( 'The managed upload directory could not be verified.', 'simple-lms' ) );
		}

		$uploads = wp_get_upload_dir();
		$basedir = wp_normalize_path( trailingslashit( (string) ( $uploads['basedir'] ?? '' ) ) );

		$directory = wp_normalize_path( trailingslashit( $directory ) );

		if ( '' === $basedir || 0 !== strpos( $directory, $basedir . 'simple-lms/' ) ) {
			return true;
		}

		$relative_directory = ltrim( substr( $directory, strlen( $basedir ) ), '/' );

		if ( ! preg_match( '#^simple-lms/(submissions|discussion-replies)(/|$)#', $relative_directory ) ) {
			return true;
		}

		$guard_files = array(
			'index.php'  => "<?php\nhttp_response_code( 403 );\nexit;\n",
			'index.html' => '',
			'.htaccess'  => "Require all denied\nDeny from all\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><security><authorization><remove users=\"*\" roles=\"\" verbs=\"\" /><add accessType=\"Deny\" users=\"*\" /></authorization></security></system.webServer></configuration>\n",
		);

		foreach ( $guard_files as $name => $contents ) {
			$guard_path = trailingslashit( $directory ) . $name;

			if ( ! file_exists( $guard_path ) && false === file_put_contents( $guard_path, $contents, LOCK_EX ) ) {
				return new \WP_Error( 'slms_upload_protection_failed', __( 'The upload was rejected because its private storage directory could not be protected.', 'simple-lms' ) );
			}
		}

		return true;
	}

	private function create_managed_upload_meta( array $uploaded, $original_name = '' ) {
		$file_path     = wp_normalize_path( (string) ( $uploaded['file'] ?? '' ) );
		$relative_path = $this->get_managed_upload_relative_path( $file_path );
		$url           = esc_url_raw( (string) ( $uploaded['url'] ?? '' ) );
		$name          = sanitize_file_name( $original_name ?: wp_basename( $file_path ) );
		$mime          = sanitize_text_field( (string) ( $uploaded['type'] ?? '' ) );

		return array(
			'relative_path' => $relative_path,
			'url'           => $url,
			'name'          => $name,
			'mime'          => $mime,
		);
	}

	private function with_managed_upload_subdirectory( $upload_subdirectory, callable $callback ) {
		$this->active_upload_subdirectory = trim( (string) $upload_subdirectory, "/\\" );
		add_filter( 'upload_dir', array( $this, 'filter_managed_upload_dir' ) );

		try {
			return $callback();
		} finally {
			remove_filter( 'upload_dir', array( $this, 'filter_managed_upload_dir' ) );
			$this->active_upload_subdirectory = '';
		}
	}

	public function filter_managed_upload_dir( $uploads ) {
		if ( '' === $this->active_upload_subdirectory ) {
			return $uploads;
		}

		$subdir          = '/simple-lms/' . ltrim( $this->active_upload_subdirectory, '/' );
		$uploads['subdir'] = $subdir;
		$uploads['path']   = trailingslashit( $uploads['basedir'] ) . ltrim( $subdir, '/' );
		$uploads['url']    = trailingslashit( $uploads['baseurl'] ) . ltrim( $subdir, '/' );

		return $uploads;
	}

	private function get_managed_upload_relative_path( $file_path ) {
		$file_path = wp_normalize_path( (string) $file_path );

		if ( '' === $file_path ) {
			return '';
		}

		$uploads = wp_get_upload_dir();
		$basedir = wp_normalize_path( trailingslashit( (string) ( $uploads['basedir'] ?? '' ) ) );

		if ( '' !== $basedir && 0 === strpos( $file_path, $basedir ) ) {
			return ltrim( substr( $file_path, strlen( $basedir ) ), '/' );
		}

		return '';
	}

	private function get_managed_upload_url( $relative_path, $stored_url = '' ) {
		$relative_path = ltrim( str_replace( '\\', '/', (string) $relative_path ), '/' );

		if ( '' !== $relative_path ) {
			$uploads = wp_get_upload_dir();
			$baseurl = trailingslashit( (string) ( $uploads['baseurl'] ?? '' ) );

			if ( '' !== $baseurl ) {
				return esc_url_raw( $baseurl . $relative_path );
			}
		}

		return $stored_url ? esc_url_raw( $stored_url ) : '';
	}

	private function get_managed_upload_path( $relative_path, $stored_url = '' ) {
		$relative_path = ltrim( str_replace( '\\', '/', (string) $relative_path ), '/' );

		if ( '' !== $relative_path ) {
			$path = ManagedUploadPath::from_relative( $relative_path );

			if ( $path ) {
				return $path;
			}
		}

		return $this->map_upload_url_to_path( $stored_url );
	}

	private function map_upload_url_to_path( $url ) {
		return ManagedUploadPath::from_url( $url );
	}

	private function delete_managed_upload_file( array $attachment ) {
		$path = $this->get_managed_upload_path( $attachment['relative_path'] ?? '', $attachment['stored_url'] ?? $attachment['url'] ?? '' );

		if ( $path && file_exists( $path ) ) {
			wp_delete_file( $path );
		}
	}

	private function handle_upload( $field_name, $upload_subdirectory = '' ) {
		if ( empty( $_FILES[ $field_name ]['tmp_name'] ) ) {
			return array();
		}

		$uploaded = $this->upload_managed_file( $_FILES[ $field_name ], $upload_subdirectory, 100 * MB_IN_BYTES );

		if ( is_wp_error( $uploaded ) ) {
			return $uploaded;
		}

		return array(
			'url'  => esc_url_raw( $uploaded['url'] ?? '' ),
			'name' => sanitize_file_name( $uploaded['name'] ?? ( $_FILES[ $field_name ]['name'] ?? '' ) ),
		);
	}

	private function upload_leave_application_attachment() {
		if ( empty( $_FILES['leave_attachment'] ) || UPLOAD_ERR_NO_FILE === (int) ( $_FILES['leave_attachment']['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			return array();
		}

		return $this->upload_managed_file(
			$_FILES['leave_attachment'],
			'leave-applications',
			10 * MB_IN_BYTES,
			array(
				'pdf'  => 'application/pdf',
				'jpg'  => 'image/jpeg',
				'jpeg' => 'image/jpeg',
				'png'  => 'image/png',
				'webp' => 'image/webp',
				'doc'  => 'application/msword',
				'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			)
		);
	}

	private function save_profile_photo( $user_id ) {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return new \WP_Error( 'slms_invalid_user', __( 'Invalid user account.', 'simple-lms' ) );
		}

		$delete = ! empty( $_POST['slms_profile_photo_delete'] );

		if ( $delete ) {
			$this->delete_profile_photo_asset( $user_id );
			delete_user_meta( $user_id, 'slms_profile_photo_upload' );
			delete_user_meta( $user_id, 'slms_profile_photo_id' );
			return true;
		}

		if ( empty( $_FILES['slms_profile_photo']['tmp_name'] ) ) {
			return new \WP_Error( 'slms_missing_photo', __( 'Please choose a profile photo to upload.', 'simple-lms' ) );
		}

		if ( ! empty( $_FILES['slms_profile_photo']['size'] ) && (int) $_FILES['slms_profile_photo']['size'] > 10 * MB_IN_BYTES ) {
			return new \WP_Error( 'slms_photo_too_large', __( 'Profile photos must be 10MB or smaller.', 'simple-lms' ) );
		}

		$image_size = @getimagesize( $_FILES['slms_profile_photo']['tmp_name'] );
		if ( empty( $image_size[0] ) ) {
			return new \WP_Error( 'slms_invalid_photo', __( 'Please upload a valid image file.', 'simple-lms' ) );
		}

		$photo = $this->upload_managed_file( $_FILES['slms_profile_photo'], 'profile-photos', 10 * MB_IN_BYTES );

		if ( is_wp_error( $photo ) ) {
			return $photo;
		}

		$this->delete_profile_photo_asset( $user_id );
		update_user_meta( $user_id, 'slms_profile_photo_upload', $photo );
		delete_user_meta( $user_id, 'slms_profile_photo_id' );

		return true;
	}

	private function delete_profile_photo_asset( $user_id ) {
		$photo = $this->normalize_managed_upload_record( get_user_meta( $user_id, 'slms_profile_photo_upload', true ) );

		if ( ! empty( $photo ) ) {
			$this->delete_managed_upload_file(
				array(
					'relative_path' => $photo['relative_path'] ?? '',
					'stored_url'    => $photo['url'] ?? '',
					'url'           => $photo['url'] ?? '',
				)
			);
		}

		$attachment_id = absint( get_user_meta( $user_id, 'slms_profile_photo_id', true ) );

		if ( $attachment_id ) {
			wp_delete_attachment( $attachment_id, true );
		}
	}

	private function can_manage_portal_header( $role ) {
		return in_array( $role, array( 'administrator', 'officer' ), true );
	}

	private function can_manage_subject_header( $subject_id, $role ) {
		$subject_id = absint( $subject_id );

		if ( ! $subject_id ) {
			return false;
		}

		if ( in_array( $role, array( 'administrator', 'officer' ), true ) ) {
			return true;
		}

		if ( 'lecturer' === $role ) {
			return $this->is_user_assigned_lecturer_for_subject( get_current_user_id(), $subject_id );
		}

		return false;
	}

	private function is_user_assigned_lecturer_for_subject( $user_id, $subject_id ) {
		$user_id    = absint( $user_id );
		$subject_id = absint( $subject_id );

		if ( ! $user_id || ! $subject_id ) {
			return false;
		}

		foreach ( $this->enrollments->get_section_staff( $subject_id, 'lecturer' ) as $staff_row ) {
			if ( $user_id === (int) ( $staff_row['user_id'] ?? 0 ) && 'active' === ( $staff_row['status'] ?? 'active' ) ) {
				return true;
			}
		}

		return false;
	}

	private function get_default_header_image_opacity_percent() {
		return 38;
	}

	private function sanitize_header_image_opacity_percent( $value ) {
		$opacity = is_numeric( $value ) ? (int) round( (float) $value ) : $this->get_default_header_image_opacity_percent();

		if ( $opacity < 0 ) {
			return 0;
		}

		if ( $opacity > 100 ) {
			return 100;
		}

		return $opacity;
	}

	private function format_header_image_opacity_decimal( $percent ) {
		return number_format( $this->sanitize_header_image_opacity_percent( $percent ) / 100, 2, '.', '' );
	}

	private function get_portal_header_image_record() {
		return $this->normalize_managed_upload_record( get_option( 'slms_portal_header_image_upload', array() ) );
	}

	private function get_portal_header_image_opacity_percent() {
		return $this->sanitize_header_image_opacity_percent( get_option( 'slms_portal_header_image_opacity', $this->get_default_header_image_opacity_percent() ) );
	}

	private function get_portal_header_image_url() {
		$record = $this->get_portal_header_image_record();

		if ( empty( $record ) ) {
			return '';
		}

		return $this->get_managed_upload_url( $record['relative_path'] ?? '', $record['url'] ?? '' );
	}

	private function delete_portal_header_image_asset() {
		$record = $this->get_portal_header_image_record();

		if ( empty( $record ) ) {
			return;
		}

		$this->delete_managed_upload_file(
			array(
				'relative_path' => $record['relative_path'] ?? '',
				'stored_url'    => $record['url'] ?? '',
				'url'           => $record['url'] ?? '',
			)
		);
	}

	private function get_subject_header_image_record( $subject_id ) {
		return $this->normalize_managed_upload_record( get_post_meta( absint( $subject_id ), '_slms_subject_header_image_upload', true ) );
	}

	private function get_subject_header_image_opacity_percent( $subject_id ) {
		return $this->sanitize_header_image_opacity_percent( get_post_meta( absint( $subject_id ), '_slms_subject_header_image_opacity', true ) );
	}

	private function get_subject_header_image_url( $subject_id ) {
		$record = $this->get_subject_header_image_record( $subject_id );

		if ( empty( $record ) ) {
			return '';
		}

		return $this->get_managed_upload_url( $record['relative_path'] ?? '', $record['url'] ?? '' );
	}

	private function delete_subject_header_image_asset( $subject_id ) {
		$record = $this->get_subject_header_image_record( $subject_id );

		if ( empty( $record ) ) {
			return;
		}

		$this->delete_managed_upload_file(
			array(
				'relative_path' => $record['relative_path'] ?? '',
				'stored_url'    => $record['url'] ?? '',
				'url'           => $record['url'] ?? '',
			)
		);
	}

	private function upload_header_image_asset( $upload_subdirectory ) {
		if ( empty( $_FILES['slms_header_image']['tmp_name'] ) ) {
			return new \WP_Error( 'slms_missing_header_image', __( 'Please choose a header image to upload.', 'simple-lms' ) );
		}

		if ( ! empty( $_FILES['slms_header_image']['size'] ) && (int) $_FILES['slms_header_image']['size'] > 12 * MB_IN_BYTES ) {
			return new \WP_Error( 'slms_header_image_too_large', __( 'Header images must be 12MB or smaller.', 'simple-lms' ) );
		}

		$image_size = @getimagesize( $_FILES['slms_header_image']['tmp_name'] );
		if ( empty( $image_size[0] ) ) {
			return new \WP_Error( 'slms_invalid_header_image', __( 'Please upload a valid image file.', 'simple-lms' ) );
		}

		return $this->upload_managed_file( $_FILES['slms_header_image'], $upload_subdirectory, 12 * MB_IN_BYTES );
	}

	private function save_portal_header_image( $role ) {
		if ( ! $this->can_manage_portal_header( $role ) ) {
			return new \WP_Error( 'slms_forbidden_header_image', __( 'You do not have permission to update the LMS header image.', 'simple-lms' ) );
		}

		$opacity = $this->sanitize_header_image_opacity_percent( wp_unslash( $_POST['slms_header_image_opacity'] ?? $this->get_default_header_image_opacity_percent() ) );

		if ( ! empty( $_POST['slms_header_image_delete'] ) ) {
			$this->delete_portal_header_image_asset();
			delete_option( 'slms_portal_header_image_upload' );
			delete_option( 'slms_portal_header_image_opacity' );
			return true;
		}

		$current_record = $this->get_portal_header_image_record();

		if ( empty( $_FILES['slms_header_image']['tmp_name'] ) ) {
			if ( empty( $current_record ) ) {
				return new \WP_Error( 'slms_missing_header_image', __( 'Please choose a header image to upload.', 'simple-lms' ) );
			}

			update_option( 'slms_portal_header_image_opacity', $opacity, false );
			return true;
		}

		$upload = $this->upload_header_image_asset( 'portal-header-images' );

		if ( is_wp_error( $upload ) ) {
			return $upload;
		}

		$this->delete_portal_header_image_asset();
		update_option( 'slms_portal_header_image_upload', $upload, false );
		update_option( 'slms_portal_header_image_opacity', $opacity, false );

		return true;
	}

	private function save_subject_header_image( $subject_id, $role ) {
		$subject_id = absint( $subject_id );

		if ( ! $subject_id || ! get_post( $subject_id ) ) {
			return new \WP_Error( 'slms_invalid_subject', __( 'The selected subject could not be found.', 'simple-lms' ) );
		}

		if ( ! $this->can_manage_subject_header( $subject_id, $role ) ) {
			return new \WP_Error( 'slms_forbidden_subject_header_image', __( 'You do not have permission to update this subject header image.', 'simple-lms' ) );
		}

		$opacity = $this->sanitize_header_image_opacity_percent( wp_unslash( $_POST['slms_header_image_opacity'] ?? $this->get_default_header_image_opacity_percent() ) );

		if ( ! empty( $_POST['slms_header_image_delete'] ) ) {
			$this->delete_subject_header_image_asset( $subject_id );
			delete_post_meta( $subject_id, '_slms_subject_header_image_upload' );
			delete_post_meta( $subject_id, '_slms_subject_header_image_opacity' );
			return true;
		}

		$current_record = $this->get_subject_header_image_record( $subject_id );

		if ( empty( $_FILES['slms_header_image']['tmp_name'] ) ) {
			if ( empty( $current_record ) ) {
				return new \WP_Error( 'slms_missing_header_image', __( 'Please choose a header image to upload.', 'simple-lms' ) );
			}

			update_post_meta( $subject_id, '_slms_subject_header_image_opacity', $opacity );
			return true;
		}

		$upload = $this->upload_header_image_asset( 'subject-header-images' );

		if ( is_wp_error( $upload ) ) {
			return $upload;
		}

		$this->delete_subject_header_image_asset( $subject_id );
		update_post_meta( $subject_id, '_slms_subject_header_image_upload', $upload );
		update_post_meta( $subject_id, '_slms_subject_header_image_opacity', $opacity );

		return true;
	}

	private function change_password( $user_id ) {
		$user_id                 = absint( $user_id );
		$requires_password_change = $this->user_requires_password_change( $user_id );
		$user                    = get_userdata( $user_id );

		if ( ! $user ) {
			return new \WP_Error( 'slms_invalid_user', __( 'Invalid user account.', 'simple-lms' ) );
		}

		$current_password = (string) wp_unslash( $_POST['current_password'] ?? '' );
		$new_password     = (string) wp_unslash( $_POST['new_password'] ?? '' );
		$confirm_password = (string) wp_unslash( $_POST['confirm_password'] ?? '' );

		if ( '' === $current_password || '' === $new_password || '' === $confirm_password ) {
			return new \WP_Error( 'slms_missing_password_fields', __( 'Please complete all password fields.', 'simple-lms' ) );
		}

		if ( ! wp_check_password( $current_password, $user->user_pass, $user_id ) ) {
			return new \WP_Error( 'slms_invalid_current_password', __( 'Your current password is incorrect.', 'simple-lms' ) );
		}

		if ( $new_password !== $confirm_password ) {
			return new \WP_Error( 'slms_password_mismatch', __( 'The new password and confirmation do not match.', 'simple-lms' ) );
		}

		if ( strlen( $new_password ) < 8 ) {
			return new \WP_Error( 'slms_password_too_short', __( 'Your new password must be at least 8 characters long.', 'simple-lms' ) );
		}

		if ( hash_equals( $current_password, $new_password ) ) {
			return new \WP_Error( 'slms_password_reused', __( 'Please choose a password different from your current password.', 'simple-lms' ) );
		}

		$updated = wp_update_user(
			array(
				'ID'        => $user_id,
				'user_pass' => $new_password,
			)
		);

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		delete_user_meta( $user_id, 'slms_force_password_reset' );
		delete_user_option( $user_id, 'default_password_nag', true );

		if ( $requires_password_change ) {
			$this->profiles->mark_password_setup_complete( $user_id );
		}

		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, false, is_ssl() );
		do_action( 'wp_login', $user->user_login, get_userdata( $user_id ) );

		return true;
	}

	private function redirect_with_notice( $page_url, $result, $message, $subject_id = 0, $tab = 'materials', $view = '', $item_id = 0 ) {
		$notice_type = 'success';
		$notice_text = $message;

		if ( is_wp_error( $result ) ) {
			$notice_type = 'error';
			$notice_text = $result->get_error_message();
		} elseif ( is_array( $result ) ) {
			$bits = array();
			foreach ( array( 'created', 'updated', 'enrolled', 'unenrolled', 'failed' ) as $key ) {
				if ( isset( $result[ $key ] ) ) {
					$bits[] = sprintf( '%s: %d', ucfirst( $key ), (int) $result[ $key ] );
				}
			}
			if ( $bits ) {
				$notice_text .= ' ' . implode( ' | ', $bits );
			}
			if ( ! empty( $result['errors'] ) ) {
				$notice_type = ! empty( $result['failed'] ) ? 'warning' : 'success';
				$notice_text .= ' ' . implode( ' ', array_slice( $result['errors'], 0, 3 ) );
			}
		}

		$args = array( 'slms_notice_type' => $notice_type, 'slms_notice' => rawurlencode( $notice_text ) );
		if ( $subject_id && $item_id ) {
			$args['slms_view']      = 'subject';
			$args['slms_subject'] = $subject_id;
			$args['slms_open_item'] = $item_id;
			$args['tab']            = $tab;
		} elseif ( $subject_id ) {
			$args['slms_view']    = 'subject';
			$args['slms_subject'] = $subject_id;
			$args['tab']          = $tab;
		} elseif ( $view ) {
			$args['slms_view'] = $view;
			if ( 'card-issuer' === $view ) {
				$issuer_tab = sanitize_key( wp_unslash( $_REQUEST['issuer_tab'] ?? '' ) );
				if ( in_array( $issuer_tab, array( 'students', 'staff' ), true ) ) {
					$args['issuer_tab'] = $issuer_tab;
				}

				$issuer_user_id = absint( $_REQUEST['issuer_user_id'] ?? 0 );
				if ( $issuer_user_id ) {
					$args['issuer_user_id'] = $issuer_user_id;
				}
			}
		}
		wp_safe_redirect( add_query_arg( $args, $page_url ) );
		exit;
	}

	private function render_notice() {
		$notice = isset( $_GET['slms_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['slms_notice'] ) ) : '';
		if ( '' === $notice ) { return; }
		$type = sanitize_key( wp_unslash( $_GET['slms_notice_type'] ?? 'success' ) );
		echo '<div class="slms-portal-alert is-' . esc_attr( $type ) . '"><p>' . esc_html( rawurldecode( $notice ) ) . '</p></div>';
	}

	private function render_workspace_card( $title, $description, array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'eyebrow' => '',
				'code'    => '',
				'meta'    => '',
				'url'     => '',
				'icon'    => '',
			)
		);

		$tag   = $args['url'] ? 'a' : 'article';
		$class = 'slms-workspace-card' . ( $args['url'] ? ' is-link' : '' ) . ( $args['icon'] ? ' is-' . sanitize_html_class( $args['icon'] ) : '' );
		$icon_class = $this->get_workspace_dashicon_class( $args['icon'] );

		echo '<' . esc_html( $tag ) . ' class="' . esc_attr( $class ) . '"' . ( $args['url'] ? ' href="' . esc_url( $args['url'] ) . '"' : '' ) . '>';

		echo '<div class="slms-workspace-card-headline">';
		if ( $icon_class ) {
			echo '<span class="slms-workspace-card-icon dashicons ' . esc_attr( $icon_class ) . '" aria-hidden="true"></span>';
		}
		echo '<div class="slms-workspace-card-title-stack">';
		echo '<h3>' . esc_html( $title ) . '</h3>';
		if ( $args['eyebrow'] ) {
			echo '<span class="slms-workspace-card-kicker">' . esc_html( $args['eyebrow'] ) . '</span>';
		}
		echo '</div>';
		echo '</div>';

		if ( $args['code'] ) {
			echo '<span class="slms-workspace-card-code">' . esc_html( $args['code'] ) . '</span>';
		}

		echo '<p>' . esc_html( $description ) . '</p>';

		if ( $args['meta'] ) {
			echo '<span class="slms-workspace-card-meta">' . esc_html( $args['meta'] ) . '</span>';
		}

		echo '</' . esc_html( $tag ) . '>';
	}

	private function get_workspace_dashicon_class( $icon ) {
		$map = array(
			'id'             => 'dashicons-id',
			'card-issuer'    => 'dashicons-id-alt',
			'leave'          => 'dashicons-media-text',
			'management'     => 'dashicons-portfolio',
			'profile'        => 'dashicons-admin-users',
			'security'       => 'dashicons-shield',
			'subject'        => 'dashicons-book-alt',
			'subjects'       => 'dashicons-book-alt',
			'subjects-empty' => 'dashicons-book',
			'transcript'     => 'dashicons-media-spreadsheet',
			'attendance-review' => 'dashicons-visibility',
			'broadcast'      => 'dashicons-megaphone',
			'announcements'  => 'dashicons-megaphone',
			'email-center'   => 'dashicons-email-alt',
		);

		return $map[ $icon ] ?? '';
	}

	private function subject_url( $page_url, $subject_id, $tab = 'materials' ) {
		return add_query_arg( array( 'slms_view' => 'subject', 'slms_subject' => $subject_id, 'tab' => $tab ), remove_query_arg( array( 'slms_item', 'slms_open_item' ), $page_url ) );
	}

	private function subject_item_url( $page_url, $subject_id, $tab, $item_id ) {
		return add_query_arg(
			array(
				'slms_view'      => 'subject',
				'slms_subject'   => $subject_id,
				'slms_open_item' => $item_id,
				'tab'            => $tab,
			),
			remove_query_arg( array( 'slms_item', 'slms_open_item' ), $page_url )
		);
	}

	private function view_url( $page_url, $view ) {
		return add_query_arg( array( 'slms_view' => $view ), remove_query_arg( array( 'slms_subject', 'slms_item', 'slms_open_item', 'tab' ), $page_url ) );
	}

	private function get_profile_photo_url( $user_id ) {
		return $this->profiles->get_profile_photo_url( $user_id, 400 );
	}

	private function get_person_avatar_name_markup( $user_id, $name, $class = '' ) {
		$name    = trim( (string) $name );
		$name    = '' !== $name ? $name : __( 'Unknown', 'simple-lms' );
		$classes = trim( 'slms-person-inline ' . (string) $class );

		return sprintf(
			'<span class="%1$s"><img class="slms-person-avatar" src="%2$s" alt="" loading="lazy" decoding="async"><span class="slms-person-name">%3$s</span></span>',
			esc_attr( $classes ),
			esc_url( $this->get_profile_photo_url( $user_id ) ),
			esc_html( $name )
		);
	}

	private function get_person_icon_name_markup( $name, $class = '' ) {
		$name    = trim( (string) $name );
		$name    = '' !== $name ? $name : __( 'Unknown', 'simple-lms' );
		$classes = trim( 'slms-person-inline slms-person-inline--icon ' . (string) $class );

		return sprintf(
			'<span class="%1$s"><span class="dashicons dashicons-admin-users slms-person-avatar-icon" aria-hidden="true"></span><span class="slms-person-name">%2$s</span></span>',
			esc_attr( $classes ),
			esc_html( $name )
		);
	}

	private function get_auditor_tag_markup() {
		return '<span class="slms-auditor-tag">' . esc_html__( 'Auditor', 'simple-lms' ) . '</span>';
	}

	private function get_person_name_with_auditor_tag_markup( $name_markup, $is_auditor = false ) {
		if ( ! $is_auditor ) {
			return (string) $name_markup;
		}

		return '<span class="slms-person-role-line">' . (string) $name_markup . $this->get_auditor_tag_markup() . '</span>';
	}

	private function get_brand_logo_url() {
		if ( function_exists( 'has_custom_logo' ) && has_custom_logo() ) {
			$logo_id = (int) get_theme_mod( 'custom_logo' );

			if ( $logo_id ) {
				$logo_url = wp_get_attachment_image_url( $logo_id, 'full' );

				if ( $logo_url ) {
					return $logo_url;
				}
			}
		}

		return get_site_icon_url( 192 ) ?: '';
	}

	private function get_program_name( $program_id ) {
		$program = get_term( $program_id, 'slms_program' );

		if ( ! $program || is_wp_error( $program ) ) {
			return '';
		}

		return $program->name;
	}

	private function get_term_name( $term_id ) {
		global $wpdb;

		$term_id = absint( $term_id );

		if ( ! $term_id ) {
			return '';
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT name, academic_year FROM ' . Schema::table( 'terms' ) . ' WHERE id = %d LIMIT 1',
				$term_id
			),
			ARRAY_A
		);

		if ( empty( $row ) ) {
			return '';
		}

		return trim( $row['name'] . ( ! empty( $row['academic_year'] ) ? ' | ' . $row['academic_year'] : '' ) );
	}

	private function get_active_term_label() {
		$term = $this->terms->get_active_term();

		if ( empty( $term['name'] ) ) {
			return '';
		}

		return trim( $term['name'] . ( ! empty( $term['academic_year'] ) ? ' | ' . $term['academic_year'] : '' ) );
	}

	private function generate_fallback_person_code( $user_id, $role ) {
		$prefix = 'student' === $role ? 'STU' : 'STA';

		return sprintf( '%s-%05d', $prefix, absint( $user_id ) );
	}

	private function render_id_detail_row( $label, $value ) {
		?>
		<div class="slms-id-detail-row">
			<span class="slms-id-detail-label"><?php echo esc_html( $label ); ?></span>
			<span class="slms-id-detail-sep">:</span>
			<span class="slms-id-detail-value"><?php echo esc_html( $value ?: '-' ); ?></span>
		</div>
		<?php
	}
}
