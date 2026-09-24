<?php

namespace SimpleLMS\Infrastructure\Admin;

use SimpleLMS\Core\AcademicClock;
use SimpleLMS\Domain\Academic\AttendanceService;
use SimpleLMS\Domain\Academic\EnrollmentService;
use SimpleLMS\Domain\Users\RoleManager;

defined( 'ABSPATH' ) || exit;

class AttendanceAdmin {
	/**
	 * @var AttendanceService
	 */
	private $attendance;

	/**
	 * @var EnrollmentService
	 */
	private $enrollments;

	public function __construct( AttendanceService $attendance, EnrollmentService $enrollments ) {
		$this->attendance  = $attendance;
		$this->enrollments = $enrollments;
	}

	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_slms_save_attendance_session', array( $this, 'handle_save_session' ) );
		add_action( 'admin_post_slms_delete_attendance_session', array( $this, 'handle_delete_session' ) );
		add_action( 'admin_post_slms_save_attendance_records', array( $this, 'handle_save_records' ) );
	}

	public function register_menu() {
		$role = RoleManager::get_primary_role();

		if ( ! in_array( $role, array( 'administrator', 'officer', 'lecturer' ), true ) ) {
			return;
		}

		add_submenu_page(
			'slms-dashboard',
			__( 'Attendance Center', 'simple-lms' ),
			__( 'Attendance', 'simple-lms' ),
			RoleManager::CAP_RECEIVE_NOTIFICATIONS,
			'slms-attendance',
			array( $this, 'render_page' )
		);
	}

	public function render_page() {
		if ( ! $this->can_access_page() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'simple-lms' ), 403 );
		}

		$user_id    = get_current_user_id();
		$sections   = $this->enrollments->get_sections_for_user( $user_id );
		$section_id = absint( $_GET['section_id'] ?? 0 );
		$session_id = absint( $_GET['session_id'] ?? 0 );

		if ( ! $section_id && ! empty( $sections ) ) {
			$section_id = (int) $sections[0]['id'];
		}

		$dashboard = $section_id ? $this->attendance->get_section_attendance_dashboard( $section_id, $user_id ) : null;

		if ( is_array( $dashboard ) && ! $session_id && ! empty( $dashboard['sessions'] ) ) {
			$session_id = (int) $dashboard['sessions'][0]['id'];
		}

		$selected_session = $session_id ? $this->attendance->get_session( $session_id ) : null;

		if ( $selected_session && (int) $selected_session['section_id'] !== $section_id ) {
			$selected_session = null;
			$session_id       = 0;
		}

		$record_map       = $selected_session ? $this->build_record_map( $this->attendance->get_session_records( $session_id ) ) : array();
		?>
		<div class="wrap slms-admin-page slms-attendance-page">
			<div class="slms-page-header">
				<div>
					<p class="slms-page-kicker"><?php esc_html_e( 'Phase 6', 'simple-lms' ); ?></p>
					<h1 class="slms-page-title"><?php esc_html_e( 'Attendance Center', 'simple-lms' ); ?></h1>
					<p class="slms-page-description"><?php esc_html_e( 'Track attendance by section offering, not by a fixed semester template. Every class meeting is created per section, so different offerings can run with different numbers of meetings and still roll into grades correctly.', 'simple-lms' ); ?></p>
				</div>
				<div class="slms-page-actions">
					<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=slms-gradebook' ) ); ?>"><?php esc_html_e( 'Open Gradebook', 'simple-lms' ); ?></a>
					<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=slms-workspace' ) ); ?>"><?php esc_html_e( 'Open Workspace', 'simple-lms' ); ?></a>
				</div>
			</div>

			<?php $this->render_notice(); ?>

			<div class="slms-surface slms-surface-tight">
				<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="slms-inline-form">
					<input type="hidden" name="page" value="slms-attendance">
					<label for="slms_attendance_section_id" class="slms-field-label"><?php esc_html_e( 'Section', 'simple-lms' ); ?></label>
					<select id="slms_attendance_section_id" name="section_id" class="regular-text">
						<?php foreach ( $sections as $section ) : ?>
							<option value="<?php echo esc_attr( $section['id'] ); ?>" <?php selected( $section_id, (int) $section['id'] ); ?>>
								<?php echo esc_html( $section['title'] . ( $section['subject_title'] ? ' - ' . $section['subject_title'] : '' ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<?php submit_button( __( 'Open Attendance', 'simple-lms' ), 'secondary', 'submit', false ); ?>
				</form>
			</div>

			<?php if ( empty( $sections ) ) : ?>
				<div class="slms-empty-state">
					<h2><?php esc_html_e( 'No sections are ready for attendance yet.', 'simple-lms' ); ?></h2>
					<p><?php esc_html_e( 'Assign a lecturer to a section or create the section first, then return here to record class meetings and attendance.', 'simple-lms' ); ?></p>
				</div>
				<?php
				return;
			endif;
			?>

			<?php if ( is_wp_error( $dashboard ) ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $dashboard->get_error_message() ); ?></p></div>
				<?php
				return;
			endif;
			?>

			<?php $this->render_summary_cards( $dashboard ); ?>

			<div class="slms-grid-main">
				<div class="slms-column-primary">
					<div class="slms-surface">
						<h2><?php echo $selected_session ? esc_html__( 'Edit Class Meeting', 'simple-lms' ) : esc_html__( 'Create Class Meeting', 'simple-lms' ); ?></h2>
						<p><?php esc_html_e( 'Create each meeting only when that section actually teaches it. This keeps attendance aligned with the real delivery pattern for the offering.', 'simple-lms' ); ?></p>
						<?php $this->render_session_form( $section_id, $selected_session, $dashboard ); ?>
					</div>

					<div class="slms-surface">
						<div class="slms-surface-header">
							<div>
								<h2><?php esc_html_e( 'Class Meetings', 'simple-lms' ); ?></h2>
								<p class="slms-empty-copy"><?php esc_html_e( 'Open any session to correct attendance, update timings, or review class-level patterns.', 'simple-lms' ); ?></p>
							</div>
						</div>
						<?php $this->render_sessions_list( $dashboard['sessions'], $section_id, $session_id ); ?>
					</div>
				</div>

				<div class="slms-column-secondary">
					<div class="slms-surface">
						<h2><?php echo $selected_session ? esc_html( sprintf( __( 'Register for %s', 'simple-lms' ), $selected_session['session_title'] ) ) : esc_html__( 'Attendance Register', 'simple-lms' ); ?></h2>
						<p><?php esc_html_e( 'Every student starts as Present in the form below so lecturers can update only the exceptions. Admins and officers can review and edit any section from the same interface.', 'simple-lms' ); ?></p>
						<?php $this->render_attendance_register( $section_id, $selected_session, $record_map, $dashboard['students'] ); ?>
					</div>

					<div class="slms-surface">
						<h2><?php esc_html_e( 'Attendance Snapshot', 'simple-lms' ); ?></h2>
						<p><?php esc_html_e( 'This roster view makes it easy to spot risk quickly while also giving officers and admins full access to attendance evidence across the university.', 'simple-lms' ); ?></p>
						<?php $this->render_student_summary_table( $dashboard['students'] ); ?>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	public function handle_save_session() {
		$this->assert_can_access();
		check_admin_referer( 'slms_save_attendance_session' );

		$section_id = absint( $_POST['section_id'] ?? 0 );
		$result     = $this->attendance->save_session( $section_id, wp_unslash( $_POST ), get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			$this->redirect_to_context( $section_id, 0, '', $result->get_error_message() );
		}

		$this->redirect_to_context( $section_id, (int) $result, 'attendance_session_saved' );
	}

	public function handle_delete_session() {
		$this->assert_can_access();

		$section_id = absint( $_POST['section_id'] ?? 0 );
		$session_id = absint( $_POST['session_id'] ?? 0 );

		check_admin_referer( 'slms_delete_attendance_session_' . $session_id );

		$result = $this->attendance->delete_session( $session_id, get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			$this->redirect_to_context( $section_id, $session_id, '', $result->get_error_message() );
		}

		$this->redirect_to_context( $section_id, 0, 'attendance_session_deleted' );
	}

	public function handle_save_records() {
		$this->assert_can_access();
		check_admin_referer( 'slms_save_attendance_records' );

		$section_id = absint( $_POST['section_id'] ?? 0 );
		$session_id = absint( $_POST['session_id'] ?? 0 );
		$records    = isset( $_POST['records'] ) && is_array( $_POST['records'] ) ? wp_unslash( $_POST['records'] ) : array();
		$result     = $this->attendance->save_session_records( $session_id, $records, get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			$this->redirect_to_context( $section_id, $session_id, '', $result->get_error_message() );
		}

		$this->redirect_to_context( $section_id, $session_id, 'attendance_records_saved' );
	}

	private function render_summary_cards( array $dashboard ) {
		?>
		<div class="slms-card-grid">
			<div class="slms-stat-card">
				<p class="slms-stat-label"><?php esc_html_e( 'Class Meetings', 'simple-lms' ); ?></p>
				<p class="slms-stat-value"><?php echo esc_html( (string) $dashboard['total_sessions'] ); ?></p>
			</div>
			<div class="slms-stat-card">
				<p class="slms-stat-label"><?php esc_html_e( 'Recorded Meetings', 'simple-lms' ); ?></p>
				<p class="slms-stat-value"><?php echo esc_html( (string) $dashboard['completed_sessions'] ); ?></p>
			</div>
			<div class="slms-stat-card">
				<p class="slms-stat-label"><?php esc_html_e( 'Students', 'simple-lms' ); ?></p>
				<p class="slms-stat-value"><?php echo esc_html( (string) count( $dashboard['students'] ) ); ?></p>
			</div>
			<div class="slms-stat-card">
				<p class="slms-stat-label"><?php esc_html_e( 'Average Attendance', 'simple-lms' ); ?></p>
				<p class="slms-stat-value"><?php echo esc_html( number_format_i18n( $dashboard['average_attendance'], 2 ) ); ?>%</p>
			</div>
		</div>
		<?php
	}

	private function render_session_form( $section_id, $session, array $dashboard ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="slms-stack">
			<input type="hidden" name="action" value="slms_save_attendance_session">
			<input type="hidden" name="section_id" value="<?php echo esc_attr( $section_id ); ?>">
			<input type="hidden" name="session_id" value="<?php echo esc_attr( $session['id'] ?? 0 ); ?>">
			<?php wp_nonce_field( 'slms_save_attendance_session' ); ?>
			<div class="slms-form-grid">
				<div>
					<label class="slms-field-label" for="slms_session_title"><?php esc_html_e( 'Session Title', 'simple-lms' ); ?></label>
					<input id="slms_session_title" type="text" name="session_title" class="regular-text" value="<?php echo esc_attr( $session['session_title'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'e.g. Lab 4 or Class 12', 'simple-lms' ); ?>">
				</div>
				<div>
					<label class="slms-field-label" for="slms_session_date"><?php esc_html_e( 'Date', 'simple-lms' ); ?></label>
					<input id="slms_session_date" type="date" name="session_date" value="<?php echo esc_attr( $session['session_date'] ?? AcademicClock::date( 'Y-m-d' ) ); ?>" required>
				</div>
				<div>
					<label class="slms-field-label" for="slms_session_start"><?php esc_html_e( 'Start Time', 'simple-lms' ); ?></label>
					<input id="slms_session_start" type="time" name="starts_at" value="<?php echo esc_attr( ! empty( $session['starts_at'] ) ? substr( $session['starts_at'], 0, 5 ) : '' ); ?>">
				</div>
				<div>
					<label class="slms-field-label" for="slms_session_end"><?php esc_html_e( 'End Time', 'simple-lms' ); ?></label>
					<input id="slms_session_end" type="time" name="ends_at" value="<?php echo esc_attr( ! empty( $session['ends_at'] ) ? substr( $session['ends_at'], 0, 5 ) : '' ); ?>">
				</div>
				<div>
					<label class="slms-field-label" for="slms_session_week"><?php esc_html_e( 'Week Label', 'simple-lms' ); ?></label>
					<input id="slms_session_week" type="text" name="week_label" class="regular-text" value="<?php echo esc_attr( $session['week_label'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Optional', 'simple-lms' ); ?>">
				</div>
				<div>
					<label class="slms-field-label" for="slms_session_status"><?php esc_html_e( 'Status', 'simple-lms' ); ?></label>
					<select id="slms_session_status" name="status">
						<?php foreach ( AttendanceService::SESSION_STATUSES as $status ) : ?>
							<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $session['status'] ?? 'scheduled', $status ); ?>><?php echo esc_html( ucfirst( $status ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
			</div>
			<div>
				<label class="slms-field-label" for="slms_session_notes"><?php esc_html_e( 'Notes', 'simple-lms' ); ?></label>
				<textarea id="slms_session_notes" name="notes" rows="3" class="large-text"><?php echo esc_textarea( $session['notes'] ?? '' ); ?></textarea>
			</div>
			<div class="slms-inline-actions">
				<?php submit_button( $session ? __( 'Update Session', 'simple-lms' ) : __( 'Create Session', 'simple-lms' ), 'primary', 'submit', false ); ?>
				<?php if ( $session ) : ?>
					<a class="button button-secondary" href="<?php echo esc_url( add_query_arg( array( 'page' => 'slms-attendance', 'section_id' => $section_id ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'New Session', 'simple-lms' ); ?></a>
				<?php else : ?>
					<span class="slms-subtle-text"><?php echo esc_html( sprintf( __( 'The next suggested class number is %d.', 'simple-lms' ), (int) $dashboard['next_session_number'] ) ); ?></span>
				<?php endif; ?>
			</div>
		</form>
		<?php if ( $session ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px;">
				<input type="hidden" name="action" value="slms_delete_attendance_session">
				<input type="hidden" name="section_id" value="<?php echo esc_attr( $section_id ); ?>">
				<input type="hidden" name="session_id" value="<?php echo esc_attr( $session['id'] ); ?>">
				<?php wp_nonce_field( 'slms_delete_attendance_session_' . (int) $session['id'] ); ?>
				<button type="submit" class="button button-link-delete" onclick="return confirm('<?php echo esc_js( __( 'Delete this class meeting and its attendance records?', 'simple-lms' ) ); ?>');"><?php esc_html_e( 'Delete Session', 'simple-lms' ); ?></button>
			</form>
		<?php endif; ?>
		<?php
	}

	private function render_sessions_list( array $sessions, $section_id, $session_id ) {
		if ( empty( $sessions ) ) {
			echo '<p class="slms-empty-copy">' . esc_html__( 'No class meetings have been created for this offering yet.', 'simple-lms' ) . '</p>';
			return;
		}

		foreach ( $sessions as $session ) {
			$url = add_query_arg(
				array(
					'page'       => 'slms-attendance',
					'section_id' => $section_id,
					'session_id' => (int) $session['id'],
				),
				admin_url( 'admin.php' )
			);
			$is_current = (int) $session_id === (int) $session['id'];
			?>
			<div class="slms-item-card<?php echo $is_current ? ' is-current' : ''; ?>">
				<div class="slms-surface-header">
					<div>
						<h3><?php echo esc_html( $session['session_title'] ); ?></h3>
						<div class="slms-chip-row">
							<span class="slms-chip"><?php echo esc_html( sprintf( __( 'Class %d', 'simple-lms' ), (int) $session['session_number'] ) ); ?></span>
							<span class="slms-chip"><?php echo esc_html( $session['session_date'] ); ?></span>
							<span class="slms-chip"><?php echo esc_html( ucfirst( $session['status'] ) ); ?></span>
						</div>
					</div>
					<div class="slms-page-actions">
						<a class="button button-secondary" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $is_current ? __( 'Open Now', 'simple-lms' ) : __( 'Open Session', 'simple-lms' ) ); ?></a>
					</div>
				</div>
				<p class="slms-empty-copy"><?php echo esc_html( sprintf( __( '%1$d records | %2$d present | %3$d late | %4$d leave | %5$d absent', 'simple-lms' ), (int) $session['record_count'], (int) $session['present_count'], (int) $session['late_count'], (int) $session['leave_count'], (int) $session['absent_count'] ) ); ?></p>
			</div>
			<?php
		}
	}

	private function render_attendance_register( $section_id, $session, array $record_map, array $students ) {
		if ( ! $session ) {
			echo '<p class="slms-empty-copy">' . esc_html__( 'Create or open a class meeting to start marking attendance.', 'simple-lms' ) . '</p>';
			return;
		}

		if ( empty( $students ) ) {
			echo '<p class="slms-empty-copy">' . esc_html__( 'No students are enrolled in this section yet.', 'simple-lms' ) . '</p>';
			return;
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="slms-stack">
			<input type="hidden" name="action" value="slms_save_attendance_records">
			<input type="hidden" name="section_id" value="<?php echo esc_attr( $section_id ); ?>">
			<input type="hidden" name="session_id" value="<?php echo esc_attr( $session['id'] ); ?>">
			<?php wp_nonce_field( 'slms_save_attendance_records' ); ?>
			<div class="slms-table-wrap">
				<table class="widefat striped slms-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Student', 'simple-lms' ); ?></th>
							<th><?php esc_html_e( 'Status', 'simple-lms' ); ?></th>
							<th><?php esc_html_e( 'Late Minutes', 'simple-lms' ); ?></th>
							<th><?php esc_html_e( 'Session Note', 'simple-lms' ); ?></th>
							<th><?php esc_html_e( 'Overall Attendance', 'simple-lms' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $students as $student ) : ?>
							<?php
							$student_id = (int) $student['student_user_id'];
							$record     = $record_map[ $student_id ] ?? array(
								'attendance_status' => 'present',
								'minutes_late'      => 0,
								'notes'             => '',
							);
							$summary = $student['attendance_summary'] ?? array(
								'attendance_percentage' => 0,
								'eligible_sessions'     => 0,
							);
							$current_status = sanitize_key( $record['attendance_status'] ?? 'present' );
							if ( 'excused' === $current_status ) {
								$current_status = 'present';
							}
							?>
							<tr>
								<td>
									<strong><?php echo esc_html( $student['student_name'] ); ?></strong><br>
									<span class="slms-subtle-text"><?php echo esc_html( $student['student_email'] ); ?></span>
								</td>
								<td>
									<select name="records[<?php echo esc_attr( $student_id ); ?>][status]" data-slms-attendance-status>
										<?php foreach ( AttendanceService::ATTENDANCE_STATUSES as $status ) : ?>
											<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $current_status, $status ); ?>><?php echo esc_html( ucfirst( $status ) ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
								<td><input type="number" min="0" step="1" data-slms-late-minutes name="records[<?php echo esc_attr( $student_id ); ?>][minutes_late]" value="<?php echo esc_attr( (int) ( $record['minutes_late'] ?? 0 ) ); ?>" <?php disabled( 'late' !== $current_status ); ?>></td>
								<td><textarea name="records[<?php echo esc_attr( $student_id ); ?>][notes]" rows="2" class="large-text"><?php echo esc_textarea( $record['notes'] ?? '' ); ?></textarea></td>
								<td>
									<strong><?php echo esc_html( number_format_i18n( (float) ( $summary['attendance_percentage'] ?? 0 ), 2 ) ); ?>%</strong><br>
									<span class="slms-subtle-text"><?php echo esc_html( sprintf( __( '%d counted classes', 'simple-lms' ), (int) ( $summary['eligible_sessions'] ?? 0 ) ) ); ?></span>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php submit_button( __( 'Save Attendance Register', 'simple-lms' ), 'primary', 'submit', false ); ?>
		</form>
		<?php
	}

	private function render_student_summary_table( array $students ) {
		if ( empty( $students ) ) {
			echo '<p class="slms-empty-copy">' . esc_html__( 'No student data is available yet.', 'simple-lms' ) . '</p>';
			return;
		}
		?>
		<div class="slms-table-wrap">
			<table class="widefat striped slms-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Student', 'simple-lms' ); ?></th>
						<th><?php esc_html_e( 'Attendance %', 'simple-lms' ); ?></th>
						<th><?php esc_html_e( 'Present', 'simple-lms' ); ?></th>
						<th><?php esc_html_e( 'Late', 'simple-lms' ); ?></th>
						<th><?php esc_html_e( 'Leave', 'simple-lms' ); ?></th>
						<th><?php esc_html_e( 'Absent', 'simple-lms' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $students as $student ) : ?>
						<?php $summary = $student['attendance_summary'] ?? array(); ?>
						<tr>
							<td>
								<strong><?php echo esc_html( $student['student_name'] ); ?></strong><br>
								<span class="slms-subtle-text"><?php echo esc_html( $student['status'] ); ?></span>
							</td>
							<td><strong><?php echo esc_html( number_format_i18n( (float) ( $summary['attendance_percentage'] ?? 0 ), 2 ) ); ?>%</strong></td>
							<td><?php echo esc_html( (string) (int) ( $summary['present_sessions'] ?? 0 ) ); ?></td>
							<td><?php echo esc_html( (string) (int) ( $summary['late_sessions'] ?? 0 ) ); ?></td>
							<td><?php echo esc_html( (string) (int) ( $summary['leave_sessions'] ?? 0 ) ); ?></td>
							<td><?php echo esc_html( (string) (int) ( $summary['absent_sessions'] ?? 0 ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private function build_record_map( array $records ) {
		$map = array();

		foreach ( $records as $record ) {
			$map[ (int) $record['student_user_id'] ] = $record;
		}

		return $map;
	}

	private function can_access_page() {
		return current_user_can( RoleManager::CAP_MANAGE_ATTENDANCE ) || current_user_can( RoleManager::CAP_MANAGE_ACADEMICS );
	}

	private function assert_can_access() {
		if ( ! $this->can_access_page() ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'simple-lms' ), 403 );
		}
	}

	private function redirect_to_context( $section_id, $session_id, $notice = '', $error = '' ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => 'slms-attendance',
					'section_id' => $section_id ?: false,
					'session_id' => $session_id ?: false,
					'slms_notice' => $notice ?: false,
					'slms_error'  => $error ?: false,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private function render_notice() {
		if ( ! empty( $_GET['slms_error'] ) ) {
			?>
			<div class="notice notice-error is-dismissible"><p><?php echo esc_html( wp_unslash( $_GET['slms_error'] ) ); ?></p></div>
			<?php
			return;
		}

		if ( empty( $_GET['slms_notice'] ) ) {
			return;
		}

		$notice = sanitize_key( wp_unslash( $_GET['slms_notice'] ) );
		$map    = array(
			'attendance_session_saved'   => __( 'Class meeting saved.', 'simple-lms' ),
			'attendance_session_deleted' => __( 'Class meeting deleted.', 'simple-lms' ),
			'attendance_records_saved'   => __( 'Attendance register saved.', 'simple-lms' ),
		);

		if ( empty( $map[ $notice ] ) ) {
			return;
		}
		?>
		<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $map[ $notice ] ); ?></p></div>
		<?php
	}
}
