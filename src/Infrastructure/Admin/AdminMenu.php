<?php

namespace SimpleLMS\Infrastructure\Admin;

use SimpleLMS\Core\AcademicClock;
use SimpleLMS\Domain\Academic\AttendanceService;
use SimpleLMS\Domain\Academic\EnrollmentService;
use SimpleLMS\Domain\Academic\TermService;
use SimpleLMS\Domain\Learning\WorkspaceService;
use SimpleLMS\Domain\Settings\SettingsManager;
use SimpleLMS\Domain\Users\ProfileService;
use SimpleLMS\Domain\Users\RoleManager;
use SimpleLMS\Infrastructure\Database\Schema;
use SimpleLMS\Infrastructure\Logging\AuditLogger;
use SimpleLMS\Infrastructure\Notifications\NotificationManager;

defined( 'ABSPATH' ) || exit;

class AdminMenu {
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

	/**
	 * @var TermService
	 */
	private $terms;

	/**
	 * @var EnrollmentService
	 */
	private $enrollments;

	/**
	 * @var AttendanceService
	 */
	private $attendance;

	/**
	 * @var ProfileService
	 */
	private $profiles;

	/**
	 * @var WorkspaceService
	 */
	private $workspace;

	public function __construct( SettingsManager $settings, AuditLogger $audit_logger, NotificationManager $notifications, TermService $terms, EnrollmentService $enrollments, AttendanceService $attendance, ProfileService $profiles, WorkspaceService $workspace ) {
		$this->settings      = $settings;
		$this->audit_logger  = $audit_logger;
		$this->notifications = $notifications;
		$this->terms         = $terms;
		$this->enrollments   = $enrollments;
		$this->attendance    = $attendance;
		$this->profiles      = $profiles;
		$this->workspace     = $workspace;
	}

	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_menu', array( $this, 'cleanup_menu' ), 999 );
	}

	public function register_menu() {
		add_menu_page(
			__( 'Simple LMS', 'simple-lms' ),
			__( 'Simple LMS', 'simple-lms' ),
			RoleManager::CAP_RECEIVE_NOTIFICATIONS,
			'slms-dashboard',
			array( $this, 'render_overview_page' ),
			'dashicons-welcome-learn-more',
			25
		);

		add_submenu_page(
			'slms-dashboard',
			__( 'Dashboard', 'simple-lms' ),
			__( 'Dashboard', 'simple-lms' ),
			RoleManager::CAP_RECEIVE_NOTIFICATIONS,
			'slms-dashboard',
			array( $this, 'render_overview_page' )
		);

		add_submenu_page(
			'slms-dashboard',
			__( 'Settings', 'simple-lms' ),
			__( 'Settings', 'simple-lms' ),
			RoleManager::CAP_MANAGE_SETTINGS,
			'slms-settings',
			array( $this, 'render_settings_page' )
		);

		add_submenu_page(
			'slms-dashboard',
			__( 'Grades', 'simple-lms' ),
			__( 'Grades', 'simple-lms' ),
			RoleManager::CAP_MANAGE_SETTINGS,
			'slms-grades',
			array( $this, 'render_grade_settings_page' )
		);
	}

	public function cleanup_menu() {
		$obsolete_submenus = array(
			'edit-tags.php?taxonomy=slms_program&post_type=slms_subject',
			'edit.php?post_type=slms_subject',
			'edit.php?post_type=slms_section',
			'edit.php?post_type=slms_lesson',
			'edit.php?post_type=slms_announcement',
			'edit.php?post_type=slms_assignment',
			'edit.php?post_type=slms_discussion',
			'slms-terms',
			'slms-enrollments',
			'slms-people',
			'slms-directory',
			'slms-workspace',
			'slms-records',
			'slms-assessments',
			'slms-gradebook',
			'slms-attendance',
		);

		foreach ( $obsolete_submenus as $submenu_slug ) {
			remove_submenu_page( 'slms-dashboard', $submenu_slug );
		}
	}

	public function render_overview_page() {
		if ( ! current_user_can( RoleManager::CAP_RECEIVE_NOTIFICATIONS ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'simple-lms' ), 403 );
		}

		$settings = $this->settings->all();
		$snapshot = $this->workspace->get_dashboard_snapshot( get_current_user_id() );
		$role     = $snapshot['role'];
		?>
		<div class="wrap slms-admin-page slms-dashboard-page">
			<div class="slms-page-header">
				<div>
					<p class="slms-page-kicker"><?php esc_html_e( 'Unified Dashboard', 'simple-lms' ); ?></p>
					<h1 class="slms-page-title"><?php esc_html_e( 'Simple LMS Dashboard', 'simple-lms' ); ?></h1>
					<p class="slms-page-description"><?php esc_html_e( 'The single entry point for academic operations, teaching workflows, communications, and learner activity across the institution.', 'simple-lms' ); ?></p>
				</div>
			</div>
			<?php $this->render_notice(); ?>
			<?php if ( $this->is_admin_workspace() ) : ?>
				<?php $this->render_admin_dashboard( $settings, $snapshot ); ?>
			<?php else : ?>
				<?php $this->render_user_dashboard( $role, $snapshot ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_admin_dashboard( array $settings, array $snapshot ) {
		global $wpdb;

		$migrations        = Schema::get_installed_migrations();
		$pending_count     = $this->notifications->count_pending();
		$audit_entry_count = $this->audit_logger->count_entries();
		$cron_timestamp    = wp_next_scheduled( NotificationManager::CRON_HOOK );
		$subject_count     = $this->count_posts_for_type( 'slms_subject' );
		$section_count     = $this->count_posts_for_type( 'slms_section' );
		$lesson_count      = $this->count_posts_for_type( 'slms_lesson' );
		$announcement_count = $this->count_posts_for_type( 'slms_announcement' );
		$term_count        = $this->terms->count_terms();
		$archived_terms    = count( $this->terms->list_terms( array( 'status' => 'archived' ) ) );
		$enrollment_count  = $this->enrollments->count_enrollments();
		$attendance_count  = $this->attendance->count_sessions();
		$student_count     = $this->profiles->count_profiles( 'student' );
		$staff_count       = $this->profiles->count_profiles( 'staff' );
		$program_count     = wp_count_terms(
			array(
				'taxonomy'   => 'slms_program',
				'hide_empty' => false,
			)
		);
		$program_count     = is_wp_error( $program_count ) ? 0 : (int) $program_count;
		$id_card_table     = Schema::table( 'id_cards' );
		$id_card_count     = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $id_card_table ) ) ? (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $id_card_table . " WHERE status = 'active'" ) : 0;
		$attendance_review_days = isset( $_GET['attendance_review_days'] ) ? absint( wp_unslash( $_GET['attendance_review_days'] ) ) : 7;
		if ( ! in_array( $attendance_review_days, array( 7, 28, 112 ), true ) ) {
			$attendance_review_days = 7;
		}
		$attendance_review_include_late = ! empty( $_GET['attendance_review_include_late'] );
		$attendance_review_rows = current_user_can( RoleManager::CAP_MANAGE_ATTENDANCE ) ? $this->attendance->get_attendance_review( $attendance_review_days, $attendance_review_include_late, 50 ) : array();
		?>
		<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px;margin:20px 0;">
			<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;">
				<h2 style="margin-top:0;"><?php esc_html_e( 'Platform', 'simple-lms' ); ?></h2>
				<p><strong><?php esc_html_e( 'Plugin Version:', 'simple-lms' ); ?></strong> <?php echo esc_html( SLMS_VERSION ); ?></p>
				<p><strong><?php esc_html_e( 'DB Version:', 'simple-lms' ); ?></strong> <?php echo esc_html( Schema::get_db_version() ); ?></p>
				<p><strong><?php esc_html_e( 'Institution:', 'simple-lms' ); ?></strong> <?php echo esc_html( $settings['institution_name'] ); ?></p>
				<p><strong><?php esc_html_e( 'REST API:', 'simple-lms' ); ?></strong> <?php echo (int) $settings['enable_rest_api'] ? esc_html__( 'Enabled', 'simple-lms' ) : esc_html__( 'Disabled', 'simple-lms' ); ?></p>
			</div>

			<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;">
				<h2 style="margin-top:0;"><?php esc_html_e( 'Academic Core', 'simple-lms' ); ?></h2>
				<p><strong><?php esc_html_e( 'Programs:', 'simple-lms' ); ?></strong> <?php echo esc_html( (string) $program_count ); ?></p>
				<p><strong><?php esc_html_e( 'Subjects:', 'simple-lms' ); ?></strong> <?php echo esc_html( (string) $subject_count ); ?></p>
				<p><strong><?php esc_html_e( 'Sections:', 'simple-lms' ); ?></strong> <?php echo esc_html( (string) $section_count ); ?></p>
				<p><strong><?php esc_html_e( 'Terms:', 'simple-lms' ); ?></strong> <?php echo esc_html( (string) $term_count ); ?></p>
				<p><strong><?php esc_html_e( 'Enrollments:', 'simple-lms' ); ?></strong> <?php echo esc_html( (string) $enrollment_count ); ?></p>
			</div>

			<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;">
				<h2 style="margin-top:0;"><?php esc_html_e( 'People Directory', 'simple-lms' ); ?></h2>
				<p><strong><?php esc_html_e( 'Students:', 'simple-lms' ); ?></strong> <?php echo esc_html( (string) $student_count ); ?></p>
				<p><strong><?php esc_html_e( 'Staff:', 'simple-lms' ); ?></strong> <?php echo esc_html( (string) $staff_count ); ?></p>
				<p><strong><?php esc_html_e( 'Welcome Emails:', 'simple-lms' ); ?></strong> <?php echo (int) $settings['send_welcome_emails'] ? esc_html__( 'Enabled', 'simple-lms' ) : esc_html__( 'Disabled', 'simple-lms' ); ?></p>
				<p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=slms-directory' ) ); ?>"><?php esc_html_e( 'Open People Directory', 'simple-lms' ); ?></a></p>
				<p><a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=slms-people' ) ); ?>"><?php esc_html_e( 'Open People Workflow', 'simple-lms' ); ?></a></p>
			</div>

			<?php if ( current_user_can( 'manage_options' ) ) : ?>
				<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;">
					<h2 style="margin-top:0;"><?php esc_html_e( 'Card Issuer', 'simple-lms' ); ?></h2>
					<p><strong><?php esc_html_e( 'Issued Cards:', 'simple-lms' ); ?></strong> <?php echo esc_html( (string) $id_card_count ); ?></p>
					<p><?php esc_html_e( 'Issue, regenerate, and preview student and staff ID cards from the native Simple LMS card issuer.', 'simple-lms' ); ?></p>
					<p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=slms-id-cards' ) ); ?>"><?php esc_html_e( 'Open Card Issuer', 'simple-lms' ); ?></a></p>
				</div>
			<?php endif; ?>

			<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;">
				<h2 style="margin-top:0;"><?php esc_html_e( 'Learning Delivery', 'simple-lms' ); ?></h2>
				<p><strong><?php esc_html_e( 'Lessons:', 'simple-lms' ); ?></strong> <?php echo esc_html( (string) $lesson_count ); ?></p>
				<p><strong><?php esc_html_e( 'Announcements:', 'simple-lms' ); ?></strong> <?php echo esc_html( (string) $announcement_count ); ?></p>
				<p><strong><?php esc_html_e( 'Assignments:', 'simple-lms' ); ?></strong> <?php echo esc_html( (string) $this->count_posts_for_type( 'slms_assignment' ) ); ?></p>
				<p><strong><?php esc_html_e( 'My Recent Sections:', 'simple-lms' ); ?></strong> <?php echo esc_html( (string) count( $snapshot['sections'] ) ); ?></p>
				<p><a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=slms-workspace' ) ); ?>"><?php esc_html_e( 'Open Workspace', 'simple-lms' ); ?></a></p>
			</div>

			<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;">
				<h2 style="margin-top:0;"><?php esc_html_e( 'Attendance Oversight', 'simple-lms' ); ?></h2>
				<p><strong><?php esc_html_e( 'Class Meetings:', 'simple-lms' ); ?></strong> <?php echo esc_html( (string) $attendance_count ); ?></p>
				<p><strong><?php esc_html_e( 'Editing Access:', 'simple-lms' ); ?></strong> <?php esc_html_e( 'Admins and officers can review all sections.', 'simple-lms' ); ?></p>
				<p><a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=slms-attendance' ) ); ?>"><?php esc_html_e( 'Open Attendance Center', 'simple-lms' ); ?></a></p>
			</div>

			<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;">
				<h2 style="margin-top:0;"><?php esc_html_e( 'Records & Archives', 'simple-lms' ); ?></h2>
				<p><strong><?php esc_html_e( 'Tracked Terms:', 'simple-lms' ); ?></strong> <?php echo esc_html( (string) $term_count ); ?></p>
				<p><strong><?php esc_html_e( 'Archived Terms:', 'simple-lms' ); ?></strong> <?php echo esc_html( (string) $archived_terms ); ?></p>
				<p><strong><?php esc_html_e( 'Purpose:', 'simple-lms' ); ?></strong> <?php esc_html_e( 'Review older rosters, grades, and attendance quickly.', 'simple-lms' ); ?></p>
				<p><a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=slms-records' ) ); ?>"><?php esc_html_e( 'Open Records Hub', 'simple-lms' ); ?></a></p>
			</div>

			<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;">
				<h2 style="margin-top:0;"><?php esc_html_e( 'Audit & Queue', 'simple-lms' ); ?></h2>
				<p><strong><?php esc_html_e( 'Audit Entries:', 'simple-lms' ); ?></strong> <?php echo esc_html( (string) $audit_entry_count ); ?></p>
				<p><strong><?php esc_html_e( 'Pending Notifications:', 'simple-lms' ); ?></strong> <?php echo esc_html( (string) $pending_count ); ?></p>
				<p><strong><?php esc_html_e( 'Next Queue Run:', 'simple-lms' ); ?></strong> <?php echo $cron_timestamp ? esc_html( AcademicClock::date( 'Y-m-d H:i:s', $cron_timestamp ) ) : esc_html__( 'Not scheduled', 'simple-lms' ); ?></p>
			</div>

			<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;">
				<h2 style="margin-top:0;"><?php esc_html_e( 'Installed Migrations', 'simple-lms' ); ?></h2>
				<?php if ( ! empty( $migrations ) ) : ?>
					<ul style="margin:0;padding-left:0;">
						<?php foreach ( $migrations as $migration ) : ?>
							<li><?php echo esc_html( $migration ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php else : ?>
					<p><?php esc_html_e( 'No migrations recorded yet.', 'simple-lms' ); ?></p>
				<?php endif; ?>
			</div>

		</div>

			<?php if ( current_user_can( RoleManager::CAP_MANAGE_ATTENDANCE ) ) : ?>
				<?php $this->render_attendance_review_block( $attendance_review_days, $attendance_review_include_late, $attendance_review_rows ); ?>
			<?php endif; ?>

		<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;margin-top:20px;">
			<h2 style="margin-top:0;"><?php esc_html_e( 'Recent Audit Log', 'simple-lms' ); ?></h2>
			<?php $rows = $this->audit_logger->query( array( 'page' => 1, 'per_page' => 10 ) ); ?>
			<?php if ( empty( $rows ) ) : ?>
				<p><?php esc_html_e( 'No audit records yet.', 'simple-lms' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'When', 'simple-lms' ); ?></th>
							<th><?php esc_html_e( 'Event', 'simple-lms' ); ?></th>
							<th><?php esc_html_e( 'Level', 'simple-lms' ); ?></th>
							<th><?php esc_html_e( 'Message', 'simple-lms' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $row ) : ?>
							<tr>
								<td><?php echo esc_html( $row['created_at'] ); ?></td>
								<td><?php echo esc_html( $row['event_type'] ); ?></td>
								<td><?php echo esc_html( $row['level'] ); ?></td>
								<td><?php echo esc_html( wp_strip_all_tags( $row['message'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_attendance_review_block( $days, $include_late, array $rows ) {
		$days = in_array( (int) $days, array( 7, 28, 112 ), true ) ? (int) $days : 7;
		?>
		<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;margin-top:20px;">
			<div style="display:flex;justify-content:space-between;gap:16px;align-items:flex-start;flex-wrap:wrap;">
				<div>
					<h2 style="margin-top:0;"><?php esc_html_e( 'Attendance Review', 'simple-lms' ); ?></h2>
					<p style="color:#50575e;margin-top:0;"><?php esc_html_e( 'Review students with absent or leave records in the selected period. Late records can be included when needed.', 'simple-lms' ); ?></p>
				</div>
				<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
					<input type="hidden" name="page" value="slms-dashboard">
					<label>
						<span class="screen-reader-text"><?php esc_html_e( 'Attendance review range', 'simple-lms' ); ?></span>
						<select name="attendance_review_days">
							<option value="7" <?php selected( $days, 7 ); ?>><?php esc_html_e( 'Last 7 days', 'simple-lms' ); ?></option>
							<option value="28" <?php selected( $days, 28 ); ?>><?php esc_html_e( 'Last 4 weeks', 'simple-lms' ); ?></option>
							<option value="112" <?php selected( $days, 112 ); ?>><?php esc_html_e( 'Last 16 weeks', 'simple-lms' ); ?></option>
						</select>
					</label>
					<label style="display:flex;align-items:center;gap:4px;">
						<input type="checkbox" name="attendance_review_include_late" value="1" <?php checked( $include_late ); ?>>
						<?php esc_html_e( 'Include late', 'simple-lms' ); ?>
					</label>
					<button type="submit" class="button button-secondary"><?php esc_html_e( 'Filter', 'simple-lms' ); ?></button>
				</form>
			</div>
			<?php if ( empty( $rows ) ) : ?>
				<p><?php esc_html_e( 'No absent or leave records were found for this period.', 'simple-lms' ); ?></p>
			<?php else : ?>
				<table class="widefat striped" style="margin-top:12px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Student', 'simple-lms' ); ?></th>
							<th><?php esc_html_e( 'Info', 'simple-lms' ); ?></th>
							<th><?php esc_html_e( 'Counts', 'simple-lms' ); ?></th>
							<th><?php esc_html_e( 'Latest Record', 'simple-lms' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $row ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $row['student_name'] ); ?></strong><br><span style="color:#50575e;"><?php echo esc_html( $row['student_email'] ); ?></span></td>
								<td><?php echo esc_html( $row['person_code'] ?: __( 'No student ID', 'simple-lms' ) ); ?></td>
								<td>
									<span style="display:inline-block;margin-right:8px;color:#b42318;font-weight:700;"><?php echo esc_html( sprintf( __( 'Absent: %d', 'simple-lms' ), (int) $row['absent_count'] ) ); ?></span>
									<span style="display:inline-block;margin-right:8px;color:#1d4ed8;font-weight:700;"><?php echo esc_html( sprintf( __( 'Leave: %d', 'simple-lms' ), (int) $row['leave_count'] ) ); ?></span>
									<?php if ( $include_late ) : ?>
										<span style="display:inline-block;color:#9a6700;font-weight:700;"><?php echo esc_html( sprintf( __( 'Late: %d', 'simple-lms' ), (int) $row['late_count'] ) ); ?></span>
									<?php endif; ?>
								</td>
								<td><strong><?php echo esc_html( ucfirst( (string) $row['latest_status'] ) ); ?></strong><br><span style="color:#50575e;"><?php echo esc_html( trim( (string) $row['latest_subject'] ) ?: __( 'Subject unavailable', 'simple-lms' ) ); ?> - <?php echo esc_html( $row['latest_date'] ); ?></span></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_user_dashboard( $role, array $snapshot ) {
		$profile                = $snapshot['profile'];
		$workspace_url          = admin_url( 'admin.php?page=slms-workspace' );
		$can_access_assessments = $this->can_access_assessment_center();
		?>
		<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin:20px 0;">
			<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;">
				<h2 style="margin-top:0;"><?php esc_html_e( 'Role', 'simple-lms' ); ?></h2>
				<p style="font-size:24px;margin:0;"><?php echo esc_html( $role ? RoleManager::get_role_label( $role ) : __( 'User', 'simple-lms' ) ); ?></p>
			</div>
			<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;">
				<h2 style="margin-top:0;"><?php esc_html_e( 'Code', 'simple-lms' ); ?></h2>
				<p style="font-size:24px;margin:0;"><?php echo esc_html( $profile['person_code'] ?? '-' ); ?></p>
			</div>
			<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;">
				<h2 style="margin-top:0;"><?php esc_html_e( 'My Sections', 'simple-lms' ); ?></h2>
				<p style="font-size:24px;margin:0;"><?php echo esc_html( (string) $snapshot['section_count'] ); ?></p>
			</div>
			<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;">
				<h2 style="margin-top:0;"><?php esc_html_e( 'Workspace', 'simple-lms' ); ?></h2>
				<p><a class="button button-primary" href="<?php echo esc_url( $workspace_url ); ?>"><?php esc_html_e( 'Open My Workspace', 'simple-lms' ); ?></a></p>
				<?php if ( current_user_can( RoleManager::CAP_MANAGE_GRADEBOOK ) || current_user_can( RoleManager::CAP_MANAGE_ACADEMICS ) ) : ?>
					<p><a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=slms-gradebook' ) ); ?>"><?php esc_html_e( 'Open Gradebook', 'simple-lms' ); ?></a></p>
				<?php endif; ?>
				<?php if ( current_user_can( RoleManager::CAP_MANAGE_ATTENDANCE ) || current_user_can( RoleManager::CAP_MANAGE_ACADEMICS ) ) : ?>
					<p><a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=slms-attendance' ) ); ?>"><?php esc_html_e( 'Open Attendance Center', 'simple-lms' ); ?></a></p>
				<?php endif; ?>
			</div>
			<?php if ( $can_access_assessments ) : ?>
				<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;">
					<h2 style="margin-top:0;"><?php esc_html_e( 'Assessments', 'simple-lms' ); ?></h2>
					<p style="font-size:24px;margin:0;"><?php echo esc_html( (string) count( $snapshot['recent_assignments'] ) ); ?></p>
					<p><a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=slms-assessments' ) ); ?>"><?php esc_html_e( 'Open Assessment Center', 'simple-lms' ); ?></a></p>
				</div>
			<?php endif; ?>
		</div>

		<div style="display:grid;grid-template-columns:minmax(320px,420px) 1fr;gap:20px;align-items:start;">
			<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;">
				<h2 style="margin-top:0;"><?php esc_html_e( 'Assigned Sections', 'simple-lms' ); ?></h2>
				<?php if ( empty( $snapshot['sections'] ) ) : ?>
					<p><?php esc_html_e( 'No sections are available in your dashboard yet.', 'simple-lms' ); ?></p>
				<?php else : ?>
					<?php foreach ( $snapshot['sections'] as $section ) : ?>
						<div style="border:1px solid #dcdcde;border-radius:8px;padding:12px;margin-bottom:10px;">
							<strong><?php echo esc_html( $section['title'] ); ?></strong><br>
							<span style="color:#50575e;"><?php echo esc_html( $section['subject_title'] ?: $section['section_code'] ); ?></span><br>
							<span style="color:#50575e;"><?php echo esc_html( $section['term_name'] ?: __( 'No term assigned', 'simple-lms' ) ); ?></span><br>
							<a class="button button-secondary" style="margin-top:10px;" href="<?php echo esc_url( add_query_arg( array( 'page' => 'slms-workspace', 'section_id' => (int) $section['id'] ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Open', 'simple-lms' ); ?></a>
						</div>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>

			<div>
				<?php if ( current_user_can( RoleManager::CAP_MANAGE_COMMUNICATIONS ) ) : ?>
					<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;margin-bottom:20px;">
						<h2 style="margin-top:0;"><?php esc_html_e( 'Publishing Tools', 'simple-lms' ); ?></h2>
						<p><?php esc_html_e( 'Legacy Editor Tools remain available from inside the new dashboard while the publishing workflow is rebuilt.', 'simple-lms' ); ?></p>
						<p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=slms-editor-tools' ) ); ?>"><?php esc_html_e( 'Open Editor Tools', 'simple-lms' ); ?></a></p>
					</div>
				<?php endif; ?>

				<?php if ( $can_access_assessments ) : ?>
					<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;margin-bottom:20px;">
						<h2 style="margin-top:0;"><?php esc_html_e( 'Recent Assignments', 'simple-lms' ); ?></h2>
						<?php $this->render_assignment_list( $snapshot['recent_assignments'] ); ?>
					</div>
				<?php endif; ?>

				<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;margin-bottom:20px;">
					<h2 style="margin-top:0;"><?php esc_html_e( 'Recent Announcements', 'simple-lms' ); ?></h2>
					<?php $this->render_activity_list( $snapshot['recent_announcements'] ); ?>
				</div>

				<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;">
					<h2 style="margin-top:0;"><?php esc_html_e( 'Recent Lessons', 'simple-lms' ); ?></h2>
					<?php $this->render_activity_list( $snapshot['recent_lessons'] ); ?>
				</div>
			</div>
		</div>
		<?php
	}

	private function render_activity_list( array $items ) {
		if ( empty( $items ) ) {
			echo '<p>' . esc_html__( 'Nothing has been published here yet.', 'simple-lms' ) . '</p>';
			return;
		}

		foreach ( $items as $item ) {
			$url = add_query_arg(
				array(
					'page'       => 'slms-workspace',
					'section_id' => $item['section_id'] ?: false,
					'content_id' => $item['id'],
				),
				admin_url( 'admin.php' )
			);
			?>
			<div style="border:1px solid #dcdcde;border-radius:8px;padding:12px;margin-bottom:10px;">
				<strong><?php echo esc_html( $item['title'] ); ?></strong><br>
				<span style="color:#50575e;"><?php echo esc_html( $item['section_title'] ?: __( 'Global', 'simple-lms' ) ); ?></span><br>
				<span style="color:#50575e;"><?php echo esc_html( $item['excerpt'] ); ?></span><br>
				<a class="button button-link" style="padding-left:0;" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'View in workspace', 'simple-lms' ); ?></a>
			</div>
			<?php
		}
	}

	private function render_assignment_list( array $items ) {
		if ( empty( $items ) ) {
			echo '<p>' . esc_html__( 'No assignments are visible in your dashboard yet.', 'simple-lms' ) . '</p>';
			return;
		}

		foreach ( $items as $item ) {
			$url = add_query_arg(
				array(
					'page'          => 'slms-assessments',
					'assignment_id' => $item['id'],
				),
				admin_url( 'admin.php' )
			);
			?>
			<div style="border:1px solid #dcdcde;border-radius:8px;padding:12px;margin-bottom:10px;">
				<strong><?php echo esc_html( $item['title'] ); ?></strong><br>
				<span style="color:#50575e;"><?php echo esc_html( $item['section_title'] ?: __( 'Section pending', 'simple-lms' ) ); ?></span><br>
				<span style="color:#50575e;"><?php echo esc_html( ( $item['due_at_display'] ?? '' ) ?: __( 'No due date', 'simple-lms' ) ); ?></span><br>
				<a class="button button-link" style="padding-left:0;" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Open in Assessment Center', 'simple-lms' ); ?></a>
			</div>
			<?php
		}
	}

	private function is_admin_workspace() {
		return current_user_can( RoleManager::CAP_MANAGE_ACADEMICS ) || current_user_can( RoleManager::CAP_MANAGE_USERS ) || current_user_can( RoleManager::CAP_MANAGE_SETTINGS );
	}

	private function count_posts_for_type( $post_type ) {
		$counts = (array) wp_count_posts( $post_type );
		$total  = 0;

		foreach ( $counts as $status => $count ) {
			if ( 'auto-draft' === $status ) {
				continue;
			}

			$total += (int) $count;
		}

		return $total;
	}

	private function can_access_assessment_center() {
		return current_user_can( RoleManager::CAP_SUBMIT_WORK ) || current_user_can( RoleManager::CAP_MANAGE_GRADEBOOK ) || current_user_can( RoleManager::CAP_MANAGE_ACADEMICS );
	}

	public function render_settings_page() {
		if ( ! current_user_can( RoleManager::CAP_MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'simple-lms' ), 403 );
		}
		$current_user       = wp_get_current_user();
		$default_test_email = $current_user && ! empty( $current_user->user_email ) ? $current_user->user_email : get_option( 'admin_email' );
		?>
		<div class="wrap slms-admin-page slms-settings-page">
			<div class="slms-page-header">
				<div>
					<p class="slms-page-kicker"><?php esc_html_e( 'Platform Configuration', 'simple-lms' ); ?></p>
					<h1 class="slms-page-title"><?php esc_html_e( 'Simple LMS Settings', 'simple-lms' ); ?></h1>
					<p class="slms-page-description"><?php esc_html_e( 'Institution-wide configuration for notifications, dashboard behavior, and the core platform foundation.', 'simple-lms' ); ?></p>
				</div>
			</div>
			<?php $this->render_notice(); ?>
			<?php settings_errors( SettingsManager::OPTION_KEY ); ?>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'slms_settings_group' );
				$this->settings->render_fields();
				submit_button( __( 'Save Settings', 'simple-lms' ) );
				?>
			</form>
			<div style="margin-top:20px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;max-width:780px;">
				<h2 style="margin-top:0;"><?php esc_html_e( 'SMTP Tools', 'simple-lms' ); ?></h2>
				<p><?php esc_html_e( 'These tools use the currently saved SMTP settings. Save changes first, then test the connection or send a test email.', 'simple-lms' ); ?></p>
				<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px;align-items:start;">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0;">
						<input type="hidden" name="action" value="slms_test_smtp_connection">
						<?php wp_nonce_field( 'slms_test_smtp_connection' ); ?>
						<p><?php esc_html_e( 'Verify that the saved host, port, encryption, and credentials can establish an SMTP session.', 'simple-lms' ); ?></p>
						<?php submit_button( __( 'Test SMTP Connection', 'simple-lms' ), 'secondary', 'submit', false ); ?>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0;">
						<input type="hidden" name="action" value="slms_send_smtp_test_email">
						<?php wp_nonce_field( 'slms_send_smtp_test_email' ); ?>
						<p>
							<label for="slms_test_email_recipient"><strong><?php esc_html_e( 'Test Email Address', 'simple-lms' ); ?></strong></label>
							<br>
							<input type="email" name="recipient_email" id="slms_test_email_recipient" class="regular-text" value="<?php echo esc_attr( $default_test_email ); ?>" required>
						</p>
						<p><?php esc_html_e( 'Send a real test message through the saved SMTP configuration so you can confirm delivery.', 'simple-lms' ); ?></p>
						<?php submit_button( __( 'Send Test Email', 'simple-lms' ), 'secondary', 'submit', false ); ?>
					</form>
				</div>
			</div>
		</div>
		<?php
	}

	public function render_grade_settings_page() {
		if ( ! current_user_can( RoleManager::CAP_MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'simple-lms' ), 403 );
		}
		?>
		<div class="wrap slms-admin-page slms-settings-page slms-grade-settings-page">
			<div class="slms-page-header">
				<div>
					<p class="slms-page-kicker"><?php esc_html_e( 'Assessment Configuration', 'simple-lms' ); ?></p>
					<h1 class="slms-page-title"><?php esc_html_e( 'Simple LMS Grades', 'simple-lms' ); ?></h1>
					<p class="slms-page-description"><?php esc_html_e( 'Configure the institution-wide letter-grade system used across transcript exports and subject Results pages.', 'simple-lms' ); ?></p>
				</div>
			</div>
			<?php $this->render_notice(); ?>
			<?php settings_errors( SettingsManager::OPTION_KEY ); ?>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'slms_settings_group' );
				$this->settings->render_grade_fields();
				submit_button( __( 'Save Grade Settings', 'simple-lms' ) );
				?>
			</form>
		</div>
		<?php
	}

	private function render_notice() {
		if ( empty( $_GET['slms_notice'] ) ) {
			return;
		}

		$notice = sanitize_text_field( wp_unslash( $_GET['slms_notice'] ) );
		$type   = sanitize_key( wp_unslash( $_GET['slms_notice_type'] ?? 'success' ) );
		$class  = 'notice-info';

		if ( 'success' === $type ) {
			$class = 'notice-success';
		} elseif ( in_array( $type, array( 'warning', 'error' ), true ) ) {
			$class = 'notice-error';
		}

		if ( 'test_notification_queued' === $notice ) {
			$notice = __( 'A test notification has been queued for your account.', 'simple-lms' );
		} else {
			$notice = rawurldecode( $notice );
		}
		?>
		<div class="notice <?php echo esc_attr( $class ); ?> is-dismissible">
			<p><?php echo esc_html( $notice ); ?></p>
		</div>
		<?php
	}
}
