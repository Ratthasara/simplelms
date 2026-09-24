<?php

namespace SimpleLMS\Infrastructure\Admin;

use SimpleLMS\Core\AcademicClock;
use SimpleLMS\Domain\Academic\EnrollmentService;
use SimpleLMS\Domain\Assessment\AssessmentService;
use SimpleLMS\Domain\Assessment\AssignmentLatePolicy;
use SimpleLMS\Domain\Users\RoleManager;
use SimpleLMS\Infrastructure\Files\ManagedUploadPath;
use SimpleLMS\Infrastructure\Notifications\AssignmentNotificationService;

defined( 'ABSPATH' ) || exit;

class AssessmentsAdmin {
	/**
	 * @var AssessmentService
	 */
	private $assessments;

	/**
	 * @var EnrollmentService
	 */
	private $enrollments;

	/**
	 * @var AssignmentNotificationService
	 */
	private $assignment_notifications;

	public function __construct( AssessmentService $assessments, EnrollmentService $enrollments, AssignmentNotificationService $assignment_notifications ) {
		$this->assessments               = $assessments;
		$this->enrollments               = $enrollments;
		$this->assignment_notifications = $assignment_notifications;
	}

	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_slms_submit_assignment', array( $this, 'handle_submit_assignment' ) );
		add_action( 'admin_post_slms_grade_submission', array( $this, 'handle_grade_submission' ) );
		add_action( 'admin_post_slms_download_submission', array( $this, 'handle_download_submission' ) );
	}

	public function register_menu() {
		$role = RoleManager::get_primary_role();

		if ( ! in_array( $role, array( 'administrator', 'officer', 'lecturer', 'student' ), true ) ) {
			return;
		}

		add_submenu_page(
			'slms-dashboard',
			__( 'Assessments', 'simple-lms' ),
			__( 'Assessments', 'simple-lms' ),
			RoleManager::CAP_RECEIVE_NOTIFICATIONS,
			'slms-assessments',
			array( $this, 'render_page' )
		);
	}

	public function render_page() {
		if ( ! current_user_can( RoleManager::CAP_RECEIVE_NOTIFICATIONS ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'simple-lms' ), 403 );
		}

		$user_id      = get_current_user_id();
		$role         = RoleManager::get_primary_role( $user_id );
		$assignment_id = absint( $_GET['assignment_id'] ?? 0 );
		$section_id    = absint( $_GET['section_id'] ?? 0 );
		$search        = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
		$sections      = $this->enrollments->get_sections_for_user( $user_id );
		$results       = $this->assessments->list_assignments_for_user(
			$user_id,
			array(
				'section_id' => $section_id,
				'search'     => $search,
				'per_page'   => 50,
				'page'       => 1,
			)
		);
		$assignments   = $results['items'];
		$assignment    = $assignment_id ? $this->assessments->get_assignment( $assignment_id, $user_id ) : null;
		$submissions   = $assignment_id && in_array( $role, array( 'administrator', 'officer', 'lecturer' ), true ) ? $this->assessments->list_submissions( $assignment_id ) : array();
		$grading_queue = in_array( $role, array( 'administrator', 'officer', 'lecturer' ), true ) ? $this->assessments->get_grading_queue( $user_id ) : array();
		?>
		<div class="wrap slms-admin-page slms-assessments-page">
			<div class="slms-page-header">
				<div>
					<p class="slms-page-kicker"><?php esc_html_e( 'Teaching & Assessment', 'simple-lms' ); ?></p>
					<h1 class="slms-page-title"><?php esc_html_e( 'Assessment Center', 'simple-lms' ); ?></h1>
					<p class="slms-page-description"><?php esc_html_e( 'Handle assignments, submissions, grading, and feedback from the same dashboard-first workspace used by staff and students.', 'simple-lms' ); ?></p>
				</div>
				<div class="slms-page-actions">
					<?php if ( in_array( $role, array( 'administrator', 'officer' ), true ) ) : ?>
						<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=slms-records' ) ); ?>"><?php esc_html_e( 'Open Records Hub', 'simple-lms' ); ?></a>
					<?php endif; ?>
					<?php if ( in_array( $role, array( 'administrator', 'officer', 'lecturer' ), true ) ) : ?>
						<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=slms-gradebook' ) ); ?>"><?php esc_html_e( 'Open Gradebook', 'simple-lms' ); ?></a>
					<?php endif; ?>
				</div>
			</div>
			<?php $this->render_notice(); ?>
			<p><?php esc_html_e( 'Assignments, submissions, and grading now live in one dashboard-first workspace designed for day-to-day LMS use.', 'simple-lms' ); ?></p>

			<?php $this->render_summary_cards( $role, $assignments, $grading_queue ); ?>

			<div style="display:grid;grid-template-columns:minmax(320px,420px) 1fr;gap:20px;align-items:start;">
				<div style="background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:18px;">
					<h2 style="margin-top:0;"><?php esc_html_e( 'Browse Assignments', 'simple-lms' ); ?></h2>
					<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="margin-bottom:16px;">
						<input type="hidden" name="page" value="slms-assessments">
						<label for="slms_assess_section" style="display:block;font-weight:600;margin-bottom:6px;"><?php esc_html_e( 'Section', 'simple-lms' ); ?></label>
						<select id="slms_assess_section" name="section_id" style="width:100%;margin-bottom:12px;">
							<option value=""><?php esc_html_e( 'All accessible sections', 'simple-lms' ); ?></option>
							<?php foreach ( $sections as $section ) : ?>
								<option value="<?php echo esc_attr( $section['id'] ); ?>" <?php selected( $section_id, (int) $section['id'] ); ?>>
									<?php echo esc_html( $section['title'] . ( $section['subject_title'] ? ' - ' . $section['subject_title'] : '' ) ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<label for="slms_assess_search" style="display:block;font-weight:600;margin-bottom:6px;"><?php esc_html_e( 'Search', 'simple-lms' ); ?></label>
						<input id="slms_assess_search" name="s" type="text" value="<?php echo esc_attr( $search ); ?>" class="regular-text" style="width:100%;margin-bottom:12px;" placeholder="<?php esc_attr_e( 'Search assignments', 'simple-lms' ); ?>">
						<?php submit_button( __( 'Apply Filters', 'simple-lms' ), 'secondary', 'submit', false ); ?>
					</form>

					<?php if ( current_user_can( 'create_slms_assignments' ) ) : ?>
						<p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=slms_assignment' ) ); ?>"><?php esc_html_e( 'Create Assignment', 'simple-lms' ); ?></a></p>
					<?php endif; ?>

					<?php if ( empty( $assignments ) ) : ?>
						<p><?php esc_html_e( 'No assignments were found for the current filters.', 'simple-lms' ); ?></p>
					<?php else : ?>
						<?php foreach ( $assignments as $item ) : ?>
							<?php $this->render_assignment_card( $item, $role ); ?>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>

				<div>
					<?php if ( $assignment ) : ?>
						<div style="background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:18px;margin-bottom:20px;">
							<h2 style="margin-top:0;"><?php echo esc_html( $assignment['title'] ); ?></h2>
							<p style="color:#50575e;margin-top:0;"><?php echo esc_html( $assignment['section_title'] ?: __( 'Section not assigned', 'simple-lms' ) ); ?><?php echo esc_html( $assignment['subject_title'] ? ' - ' . $assignment['subject_title'] : '' ); ?></p>
							<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px;">
								<span style="background:#eff6ff;color:#1d4ed8;padding:6px 10px;border-radius:999px;font-size:12px;font-weight:600;"><?php echo esc_html( sprintf( __( '%s pts', 'simple-lms' ), $assignment['points'] ?: 0 ) ); ?></span>
								<span style="background:#f8fafc;color:#334155;padding:6px 10px;border-radius:999px;font-size:12px;font-weight:600;"><?php echo esc_html( ( $assignment['due_at_display'] ?? '' ) ?: __( 'No due date', 'simple-lms' ) ); ?></span>
								<span style="background:#f8fafc;color:#334155;padding:6px 10px;border-radius:999px;font-size:12px;font-weight:600;"><?php echo esc_html( ucfirst( str_replace( '_', ' ', $assignment['submission_type'] ) ) ); ?></span>
							</div>
							<?php echo wp_kses_post( wpautop( $assignment['instructions'] ) ); ?>
						</div>

						<?php if ( 'student' === $role ) : ?>
							<?php $this->render_student_submission_panel( $assignment ); ?>
						<?php else : ?>
							<?php $this->render_grading_panel( $assignment, $submissions ); ?>
						<?php endif; ?>
					<?php else : ?>
						<div style="background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:18px;margin-bottom:20px;">
							<h2 style="margin-top:0;"><?php esc_html_e( 'Assessment Workspace', 'simple-lms' ); ?></h2>
							<p><?php esc_html_e( 'Choose an assignment from the left to view instructions, submit work, or grade student responses.', 'simple-lms' ); ?></p>
						</div>

						<?php if ( ! empty( $grading_queue ) ) : ?>
							<div style="background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:18px;">
								<h2 style="margin-top:0;"><?php esc_html_e( 'Pending Grading Queue', 'simple-lms' ); ?></h2>
								<table class="widefat striped">
									<thead>
										<tr>
											<th><?php esc_html_e( 'Student', 'simple-lms' ); ?></th>
											<th><?php esc_html_e( 'Status', 'simple-lms' ); ?></th>
											<th><?php esc_html_e( 'Submitted', 'simple-lms' ); ?></th>
											<th><?php esc_html_e( 'Open', 'simple-lms' ); ?></th>
										</tr>
									</thead>
									<tbody>
										<?php foreach ( $grading_queue as $row ) : ?>
											<tr>
												<td><?php echo esc_html( $row['display_name'] . ' (' . $row['user_email'] . ')' ); ?></td>
												<td><?php echo esc_html( ucwords( str_replace( '_', ' ', (string) ( $row['display_status'] ?? $row['status'] ) ) ) ); ?></td>
												<td><?php echo esc_html( AcademicClock::format_local( $row['submitted_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $row['submitted_at'] ) ); ?></td>
												<td><a class="button button-small" href="<?php echo esc_url( add_query_arg( array( 'page' => 'slms-assessments', 'assignment_id' => (int) $row['assignment_id'] ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Review', 'simple-lms' ); ?></a></td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							</div>
						<?php endif; ?>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	public function handle_submit_assignment() {
		$this->assert_role( array( 'student' ) );
		check_admin_referer( 'slms_submit_assignment' );

		$assignment_id = absint( $_POST['assignment_id'] ?? 0 );
		$text          = wp_unslash( $_POST['submission_text'] ?? '' );
		$file_data     = $this->maybe_handle_submission_upload( 'submission_file' );

		if ( is_wp_error( $file_data ) ) {
			$this->redirect_with_notice( $assignment_id, '', $file_data->get_error_message() );
		}

		$result = $this->assessments->submit_assignment(
			$assignment_id,
			get_current_user_id(),
			array(
				'submission_text' => $text,
				'attachment_url'  => $file_data['url'] ?? '',
				'attachment_name' => $file_data['name'] ?? '',
			)
		);

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( $assignment_id, '', $result->get_error_message() );
		}

		$current_submission = $this->assessments->get_submission( $assignment_id, get_current_user_id() );
		$this->assignment_notifications->send_submission_confirmation( $assignment_id, get_current_user_id(), $current_submission ?: array() );

		$this->redirect_with_notice( $assignment_id, 'submission_saved' );
	}

	public function handle_grade_submission() {
		$this->assert_role( array( 'administrator', 'officer', 'lecturer' ) );
		check_admin_referer( 'slms_grade_submission' );

		$assignment_id   = absint( $_POST['assignment_id'] ?? 0 );
		$student_user_id = absint( $_POST['student_user_id'] ?? 0 );
		$score           = wp_unslash( $_POST['score'] ?? '' );
		$feedback        = wp_unslash( $_POST['feedback'] ?? '' );

		$result = $this->assessments->grade_submission(
			$assignment_id,
			$student_user_id,
			array(
				'score'     => $score,
				'feedback'  => $feedback,
				'graded_by' => get_current_user_id(),
			)
		);

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( $assignment_id, '', $result->get_error_message() );
		}

		$this->assignment_notifications->send_grade_posted( $assignment_id, $student_user_id );

		$this->redirect_with_notice( $assignment_id, 'submission_graded' );
	}

	public function handle_download_submission() {
		$assignment_id   = absint( $_REQUEST['assignment_id'] ?? 0 );
		$student_user_id = absint( $_REQUEST['student_user_id'] ?? 0 );

		check_admin_referer( 'slms_download_submission_' . $assignment_id . '_' . $student_user_id );

		$current_user_id  = get_current_user_id();
		$is_own_submission = $current_user_id === $student_user_id
			&& $this->assessments->can_user_submit_assignment( $assignment_id, $current_user_id );
		$can_grade         = $this->assessments->can_user_grade_assignment( $assignment_id, $current_user_id );

		if ( ! $is_own_submission && ! $can_grade ) {
			wp_die( esc_html__( 'You do not have permission to download this submission.', 'simple-lms' ), 403 );
		}

		$submission = $this->assessments->get_submission( $assignment_id, $student_user_id );

		if ( empty( $submission ) || empty( $submission['attachment_url'] ) ) {
			wp_die( esc_html__( 'The requested submission file could not be found.', 'simple-lms' ), 404 );
		}

		$file_path = $this->map_upload_url_to_path( $submission['attachment_url'] );

		if ( ! $file_path || ! is_readable( $file_path ) || ! is_file( $file_path ) ) {
			wp_die( esc_html__( 'The submitted file is no longer available.', 'simple-lms' ), 404 );
		}

		$this->stream_submission_file( $file_path, (string) ( $submission['attachment_name'] ?? '' ) );
	}

	private function render_summary_cards( $role, array $assignments, array $grading_queue ) {
		$submitted = 0;
		$graded    = 0;

		foreach ( $assignments as $assignment ) {
			if ( empty( $assignment['submission'] ) ) {
				continue;
			}

			$submitted++;

			if ( 'graded' === $assignment['submission']['status'] ) {
				$graded++;
			}
		}
		?>
		<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin:20px 0;">
			<div style="background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:16px;">
				<h2 style="margin:0 0 8px;"><?php esc_html_e( 'Assignments', 'simple-lms' ); ?></h2>
				<p style="font-size:24px;margin:0;"><?php echo esc_html( (string) count( $assignments ) ); ?></p>
			</div>
			<?php if ( 'student' === $role ) : ?>
				<div style="background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:16px;">
					<h2 style="margin:0 0 8px;"><?php esc_html_e( 'Submitted', 'simple-lms' ); ?></h2>
					<p style="font-size:24px;margin:0;"><?php echo esc_html( (string) $submitted ); ?></p>
				</div>
				<div style="background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:16px;">
					<h2 style="margin:0 0 8px;"><?php esc_html_e( 'Graded', 'simple-lms' ); ?></h2>
					<p style="font-size:24px;margin:0;"><?php echo esc_html( (string) $graded ); ?></p>
				</div>
			<?php else : ?>
				<div style="background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:16px;">
					<h2 style="margin:0 0 8px;"><?php esc_html_e( 'Pending Grading', 'simple-lms' ); ?></h2>
					<p style="font-size:24px;margin:0;"><?php echo esc_html( (string) count( $grading_queue ) ); ?></p>
				</div>
				<div style="background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:16px;">
					<h2 style="margin:0 0 8px;"><?php esc_html_e( 'Sections', 'simple-lms' ); ?></h2>
					<p style="font-size:24px;margin:0;"><?php echo esc_html( (string) count( $this->enrollments->get_sections_for_user( get_current_user_id() ) ) ); ?></p>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_assignment_card( array $item, $role ) {
		$url = add_query_arg(
			array(
				'page'          => 'slms-assessments',
				'assignment_id' => (int) $item['id'],
			),
			admin_url( 'admin.php' )
		);
		$status_text = __( 'Pending', 'simple-lms' );

		if ( 'student' === $role && ! empty( $item['submission']['status'] ) ) {
			$status_text = ucwords( str_replace( '_', ' ', (string) ( $item['submission']['display_status'] ?? $item['submission']['status'] ) ) );
		} elseif ( 'student' === $role && ! empty( $item['due_at'] ) && AcademicClock::timestamp_from_local( $item['due_at'] ) < AcademicClock::timestamp() ) {
			$status_text = __( 'Overdue', 'simple-lms' );
		}
		?>
		<div style="border:1px solid #dcdcde;border-radius:10px;padding:14px;margin-bottom:12px;background:#fff;">
			<strong><?php echo esc_html( $item['title'] ); ?></strong><br>
			<span style="color:#50575e;"><?php echo esc_html( $item['section_title'] ?: __( 'Section pending', 'simple-lms' ) ); ?></span><br>
			<span style="color:#50575e;"><?php echo esc_html( ( $item['due_at_display'] ?? '' ) ?: __( 'No due date', 'simple-lms' ) ); ?></span><br>
			<span style="display:inline-block;margin-top:8px;background:#f8fafc;color:#334155;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:600;"><?php echo esc_html( $status_text ); ?></span>
			<a class="button button-link" style="display:block;padding-left:0;margin-top:10px;" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Open assignment', 'simple-lms' ); ?></a>
		</div>
		<?php
	}

	private function render_student_submission_panel( array $assignment ) {
		$submission = $assignment['submission'];
		?>
		<div style="background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:18px;">
			<h2 style="margin-top:0;"><?php esc_html_e( 'My Submission', 'simple-lms' ); ?></h2>
			<?php if ( $submission ) : ?>
			<p><strong><?php esc_html_e( 'Status:', 'simple-lms' ); ?></strong> <?php echo esc_html( ucwords( str_replace( '_', ' ', (string) ( $submission['display_status'] ?? $submission['status'] ) ) ) ); ?></p>
				<p><strong><?php esc_html_e( 'Attempts:', 'simple-lms' ); ?></strong> <?php echo esc_html( (string) $submission['attempt_number'] ); ?></p>
				<p><strong><?php esc_html_e( 'Submitted:', 'simple-lms' ); ?></strong> <?php echo esc_html( AcademicClock::format_local( $submission['submitted_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $submission['submitted_at'] ) ); ?></p>
				<?php $late_details = AssignmentLatePolicy::get_adjustment_details( $assignment, $submission ); ?>
				<?php if ( ! empty( $late_details['is_late'] ) ) : ?>
					<p><strong><?php esc_html_e( 'Late Penalty:', 'simple-lms' ); ?></strong> <?php echo esc_html( $late_details['policy_short_label'] ); ?></p>
				<?php endif; ?>
				<?php if ( '' !== (string) $submission['score'] && null !== $submission['score'] ) : ?>
					<p><strong><?php esc_html_e( 'Score:', 'simple-lms' ); ?></strong> <?php echo esc_html( (string) $submission['score'] ); ?> / <?php echo esc_html( (string) $assignment['points'] ); ?></p>
				<?php endif; ?>
				<?php if ( ! empty( $submission['feedback'] ) ) : ?>
					<div style="background:#f8fafc;border-radius:8px;padding:12px;margin-bottom:16px;">
						<strong><?php esc_html_e( 'Feedback', 'simple-lms' ); ?></strong>
						<?php echo wp_kses_post( wpautop( $submission['feedback'] ) ); ?>
					</div>
				<?php endif; ?>
			<?php else : ?>
				<p><?php esc_html_e( 'You have not submitted this assignment yet.', 'simple-lms' ); ?></p>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<input type="hidden" name="action" value="slms_submit_assignment">
				<input type="hidden" name="assignment_id" value="<?php echo esc_attr( $assignment['id'] ); ?>">
				<?php wp_nonce_field( 'slms_submit_assignment' ); ?>
				<label for="slms_submission_text" style="display:block;font-weight:600;margin-bottom:6px;"><?php esc_html_e( 'Written Response', 'simple-lms' ); ?></label>
				<textarea id="slms_submission_text" name="submission_text" class="large-text" rows="7" placeholder="<?php esc_attr_e( 'Write your response here', 'simple-lms' ); ?>"><?php echo esc_textarea( $submission['submission_text'] ?? '' ); ?></textarea>
				<label for="slms_submission_file" style="display:block;font-weight:600;margin:14px 0 6px;"><?php esc_html_e( 'Attachment', 'simple-lms' ); ?></label>
				<input id="slms_submission_file" type="file" name="submission_file" class="regular-text">
				<?php if ( ! empty( $submission['attachment_url'] ) ) : ?>
					<?php $this->render_submission_download_button( (int) $assignment['id'], (int) $submission['student_user_id'], $submission['attachment_name'] ?: __( 'View current file', 'simple-lms' ) ); ?>
				<?php endif; ?>
				<?php submit_button( __( 'Submit', 'simple-lms' ) ); ?>
			</form>
		</div>
		<?php
	}

	private function render_grading_panel( array $assignment, array $submissions ) {
		?>
		<div style="background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:18px;">
			<h2 style="margin-top:0;"><?php esc_html_e( 'Submissions', 'simple-lms' ); ?></h2>
			<?php if ( empty( $submissions ) ) : ?>
				<p><?php esc_html_e( 'No submissions have been received yet.', 'simple-lms' ); ?></p>
			<?php else : ?>
				<?php foreach ( $submissions as $submission ) : ?>
					<div style="border:1px solid #dcdcde;border-radius:10px;padding:14px;margin-bottom:16px;">
						<div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;">
							<div>
								<strong><?php echo esc_html( $submission['display_name'] ); ?></strong><br>
								<span style="color:#50575e;"><?php echo esc_html( $submission['user_email'] ); ?></span><br>
								<span style="color:#50575e;"><?php echo esc_html( ucwords( str_replace( '_', ' ', (string) ( $submission['display_status'] ?? $submission['status'] ) ) ) . ' | ' . AcademicClock::format_local( $submission['submitted_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $submission['submitted_at'] ) ); ?></span>
								<?php $late_details = AssignmentLatePolicy::get_adjustment_details( $assignment, $submission ); ?>
								<?php if ( ! empty( $late_details['is_late'] ) ) : ?>
									<br><span style="color:#b45309;"><?php echo esc_html( sprintf( __( 'Late penalty: %s', 'simple-lms' ), $late_details['policy_short_label'] ) ); ?></span>
								<?php endif; ?>
							</div>
							<div style="text-align:right;">
								<span style="display:inline-block;background:#f8fafc;color:#334155;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:600;"><?php echo esc_html( sprintf( __( 'Attempt %d', 'simple-lms' ), (int) $submission['attempt_number'] ) ); ?></span>
							</div>
						</div>

						<?php if ( ! empty( $submission['submission_text'] ) ) : ?>
							<div style="background:#f8fafc;border-radius:8px;padding:12px;margin-top:12px;">
								<?php echo wp_kses_post( wpautop( $submission['submission_text'] ) ); ?>
							</div>
						<?php endif; ?>

						<?php if ( ! empty( $submission['attachment_url'] ) ) : ?>
							<div style="margin-top:12px;"><?php $this->render_submission_download_button( (int) $assignment['id'], (int) $submission['student_user_id'], $submission['attachment_name'] ?: __( 'Download file', 'simple-lms' ) ); ?></div>
						<?php endif; ?>

						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:14px;">
							<input type="hidden" name="action" value="slms_grade_submission">
							<input type="hidden" name="assignment_id" value="<?php echo esc_attr( $assignment['id'] ); ?>">
							<input type="hidden" name="student_user_id" value="<?php echo esc_attr( $submission['student_user_id'] ); ?>">
							<?php wp_nonce_field( 'slms_grade_submission' ); ?>
							<div style="display:grid;grid-template-columns:140px 1fr;gap:12px;align-items:start;">
								<div>
									<label for="slms_score_<?php echo esc_attr( $submission['id'] ); ?>" style="display:block;font-weight:600;margin-bottom:6px;"><?php esc_html_e( 'Score', 'simple-lms' ); ?></label>
									<input id="slms_score_<?php echo esc_attr( $submission['id'] ); ?>" type="number" step="0.1" min="0" max="<?php echo esc_attr( $assignment['points'] ); ?>" name="score" value="<?php echo esc_attr( $submission['score'] ); ?>" style="width:100%;">
								</div>
								<div>
									<label for="slms_feedback_<?php echo esc_attr( $submission['id'] ); ?>" style="display:block;font-weight:600;margin-bottom:6px;"><?php esc_html_e( 'Feedback', 'simple-lms' ); ?></label>
									<textarea id="slms_feedback_<?php echo esc_attr( $submission['id'] ); ?>" name="feedback" rows="4" class="large-text"><?php echo esc_textarea( $submission['feedback'] ); ?></textarea>
								</div>
							</div>
							<?php submit_button( __( 'Save Grade', 'simple-lms' ), 'primary', 'submit', false, array( 'style' => 'margin-top:12px;' ) ); ?>
						</form>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_submission_download_button( $assignment_id, $student_user_id, $label ) {
		$assignment_id   = absint( $assignment_id );
		$student_user_id = absint( $student_user_id );
		$download_url    = wp_nonce_url(
			add_query_arg(
				array(
					'action'          => 'slms_download_submission',
					'assignment_id'   => $assignment_id,
					'student_user_id' => $student_user_id,
				),
				admin_url( 'admin-post.php' )
			),
			'slms_download_submission_' . $assignment_id . '_' . $student_user_id
		);
		?>
		<a class="button button-link" href="<?php echo esc_url( $download_url ); ?>"><?php echo esc_html( $label ); ?></a>
		<?php
	}

	private function map_upload_url_to_path( $url ) {
		return ManagedUploadPath::from_url( $url );
	}

	private function stream_submission_file( $file_path, $download_name = '' ) {
		$file_path     = wp_normalize_path( (string) $file_path );
		$download_name = sanitize_file_name( $download_name ?: wp_basename( $file_path ) );
		$mime_type     = wp_check_filetype( $download_name );
		$content_type  = ! empty( $mime_type['type'] ) ? $mime_type['type'] : 'application/octet-stream';

		nocache_headers();
		header( 'Content-Type: ' . $content_type );
		header( 'Content-Disposition: attachment; filename="' . $download_name . '"' );
		header( 'Content-Length: ' . filesize( $file_path ) );
		readfile( $file_path );
		exit;
	}

	private function maybe_handle_submission_upload( $field_name ) {
		if ( empty( $_FILES[ $field_name ]['name'] ) ) {
			return array();
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		$upload = wp_handle_upload( $_FILES[ $field_name ], array( 'test_form' => false ) );

		if ( ! empty( $upload['error'] ) ) {
			return new \WP_Error( 'slms_upload_failed', sanitize_text_field( $upload['error'] ) );
		}

		return array(
			'url'  => esc_url_raw( $upload['url'] ),
			'name' => sanitize_file_name( wp_basename( $upload['file'] ) ),
		);
	}

	private function assert_role( array $allowed_roles ) {
		if ( ! in_array( RoleManager::get_primary_role(), $allowed_roles, true ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'simple-lms' ), 403 );
		}
	}

	private function redirect_with_notice( $assignment_id, $notice = '', $error = '' ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'          => 'slms-assessments',
					'assignment_id' => $assignment_id ?: false,
					'slms_notice'   => $notice ?: false,
					'slms_error'    => $error ?: false,
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
			'submission_saved'  => __( 'Submission saved.', 'simple-lms' ),
			'submission_graded' => __( 'Grade saved.', 'simple-lms' ),
		);

		if ( empty( $map[ $notice ] ) ) {
			return;
		}
		?>
		<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $map[ $notice ] ); ?></p></div>
		<?php
	}
}
