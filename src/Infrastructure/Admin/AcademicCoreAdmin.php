<?php

namespace SimpleLMS\Infrastructure\Admin;

use SimpleLMS\Domain\Academic\EnrollmentService;
use SimpleLMS\Domain\Academic\TermService;
use SimpleLMS\Domain\Users\RoleManager;
use SimpleLMS\Infrastructure\Logging\AuditLogger;

defined( 'ABSPATH' ) || exit;

class AcademicCoreAdmin {
	/**
	 * @var TermService
	 */
	private $terms;

	/**
	 * @var EnrollmentService
	 */
	private $enrollments;

	/**
	 * @var AuditLogger
	 */
	private $audit_logger;

	public function __construct( TermService $terms, EnrollmentService $enrollments, AuditLogger $audit_logger ) {
		$this->terms        = $terms;
		$this->enrollments  = $enrollments;
		$this->audit_logger = $audit_logger;
	}

	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_slms_save_term', array( $this, 'handle_save_term' ) );
		add_action( 'admin_post_slms_delete_term', array( $this, 'handle_delete_term' ) );
		add_action( 'admin_post_slms_save_enrollment', array( $this, 'handle_save_enrollment' ) );
		add_action( 'admin_post_slms_change_enrollment_status', array( $this, 'handle_change_enrollment_status' ) );
	}

	public function register_menu() {
		add_submenu_page(
			'slms-dashboard',
			__( 'Programs', 'simple-lms' ),
			__( 'Programs', 'simple-lms' ),
			RoleManager::CAP_MANAGE_ACADEMICS,
			'edit-tags.php?taxonomy=slms_program&post_type=slms_subject'
		);

		add_submenu_page(
			'slms-dashboard',
			__( 'Subjects', 'simple-lms' ),
			__( 'Subjects', 'simple-lms' ),
			'edit_slms_subjects',
			'edit.php?post_type=slms_subject'
		);

		add_submenu_page(
			'slms-dashboard',
			__( 'Sections', 'simple-lms' ),
			__( 'Sections', 'simple-lms' ),
			'edit_slms_sections',
			'edit.php?post_type=slms_section'
		);

		add_submenu_page(
			'slms-dashboard',
			__( 'Terms', 'simple-lms' ),
			__( 'Terms', 'simple-lms' ),
			RoleManager::CAP_MANAGE_ACADEMICS,
			'slms-terms',
			array( $this, 'render_terms_page' )
		);

		add_submenu_page(
			'slms-dashboard',
			__( 'Enrollments', 'simple-lms' ),
			__( 'Enrollments', 'simple-lms' ),
			RoleManager::CAP_MANAGE_ENROLLMENTS,
			'slms-enrollments',
			array( $this, 'render_enrollments_page' )
		);
	}

	public function render_terms_page() {
		if ( ! current_user_can( RoleManager::CAP_MANAGE_ACADEMICS ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'simple-lms' ), 403 );
		}

		$edit_term = null;

		if ( ! empty( $_GET['term_id'] ) ) {
			$edit_term = $this->terms->get_term( absint( $_GET['term_id'] ) );
		}

		$terms = $this->terms->list_terms();
		?>
		<div class="wrap slms-admin-page slms-terms-page">
			<div class="slms-page-header">
				<div>
					<p class="slms-page-kicker"><?php esc_html_e( 'Registrar', 'simple-lms' ); ?></p>
					<h1 class="slms-page-title"><?php esc_html_e( 'Academic Terms', 'simple-lms' ); ?></h1>
					<p class="slms-page-description"><?php esc_html_e( 'Define academic periods that control when sections run, open for teaching, and roll into official records.', 'simple-lms' ); ?></p>
				</div>
				<div class="slms-page-actions">
					<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=slms-records' ) ); ?>"><?php esc_html_e( 'Open Records Hub', 'simple-lms' ); ?></a>
				</div>
			</div>
			<?php $this->render_notice(); ?>
			<p><?php esc_html_e( 'Terms define academic periods that sections run inside.', 'simple-lms' ); ?></p>

			<div style="display:grid;grid-template-columns:minmax(360px,460px) 1fr;gap:20px;align-items:start;">
				<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;">
					<h2 style="margin-top:0;"><?php echo $edit_term ? esc_html__( 'Edit Term', 'simple-lms' ) : esc_html__( 'Add New Term', 'simple-lms' ); ?></h2>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="slms_save_term">
						<?php wp_nonce_field( 'slms_save_term' ); ?>
						<?php if ( $edit_term ) : ?>
							<input type="hidden" name="term_id" value="<?php echo esc_attr( $edit_term['id'] ); ?>">
						<?php endif; ?>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><label for="slms_term_code"><?php esc_html_e( 'Code', 'simple-lms' ); ?></label></th>
								<td><input type="text" class="regular-text" id="slms_term_code" name="code" value="<?php echo esc_attr( $edit_term['code'] ?? '' ); ?>" placeholder="e.g. 2026-T1" required></td>
							</tr>
							<tr>
								<th scope="row"><label for="slms_term_name"><?php esc_html_e( 'Name', 'simple-lms' ); ?></label></th>
								<td><input type="text" class="regular-text" id="slms_term_name" name="name" value="<?php echo esc_attr( $edit_term['name'] ?? '' ); ?>" placeholder="e.g. Semester 1" required></td>
							</tr>
							<tr>
								<th scope="row"><label for="slms_academic_year"><?php esc_html_e( 'Academic Year', 'simple-lms' ); ?></label></th>
								<td><input type="text" class="regular-text" id="slms_academic_year" name="academic_year" value="<?php echo esc_attr( $edit_term['academic_year'] ?? '' ); ?>" placeholder="e.g. 2026" required></td>
							</tr>
							<tr>
								<th scope="row"><label for="slms_start_date"><?php esc_html_e( 'Start Date', 'simple-lms' ); ?></label></th>
								<td><input type="date" id="slms_start_date" name="start_date" value="<?php echo esc_attr( $edit_term['start_date'] ?? '' ); ?>"></td>
							</tr>
							<tr>
								<th scope="row"><label for="slms_end_date"><?php esc_html_e( 'End Date', 'simple-lms' ); ?></label></th>
								<td><input type="date" id="slms_end_date" name="end_date" value="<?php echo esc_attr( $edit_term['end_date'] ?? '' ); ?>"></td>
							</tr>
							<tr>
								<th scope="row"><label for="slms_term_status"><?php esc_html_e( 'Status', 'simple-lms' ); ?></label></th>
								<td>
									<select id="slms_term_status" name="status">
										<?php foreach ( TermService::VALID_STATUSES as $status ) : ?>
											<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $edit_term['status'] ?? 'planned', $status ); ?>><?php echo esc_html( ucfirst( $status ) ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="slms_sort_order"><?php esc_html_e( 'Sort Order', 'simple-lms' ); ?></label></th>
								<td><input type="number" class="small-text" id="slms_sort_order" name="sort_order" value="<?php echo esc_attr( $edit_term['sort_order'] ?? 0 ); ?>"></td>
							</tr>
						</table>
						<?php submit_button( $edit_term ? __( 'Update Term', 'simple-lms' ) : __( 'Save Term', 'simple-lms' ) ); ?>
						<?php if ( $edit_term ) : ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=slms-terms' ) ); ?>" class="button button-secondary"><?php esc_html_e( 'Cancel', 'simple-lms' ); ?></a>
						<?php endif; ?>
					</form>
				</div>

				<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;">
					<h2 style="margin-top:0;"><?php esc_html_e( 'Existing Terms', 'simple-lms' ); ?></h2>
					<?php if ( empty( $terms ) ) : ?>
						<p><?php esc_html_e( 'No terms have been created yet.', 'simple-lms' ); ?></p>
					<?php else : ?>
						<table class="widefat striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Code', 'simple-lms' ); ?></th>
									<th><?php esc_html_e( 'Name', 'simple-lms' ); ?></th>
									<th><?php esc_html_e( 'Academic Year', 'simple-lms' ); ?></th>
									<th><?php esc_html_e( 'Status', 'simple-lms' ); ?></th>
									<th><?php esc_html_e( 'Dates', 'simple-lms' ); ?></th>
									<th><?php esc_html_e( 'Actions', 'simple-lms' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $terms as $term ) : ?>
									<tr>
										<td><?php echo esc_html( $term['code'] ); ?></td>
										<td><?php echo esc_html( $term['name'] ); ?></td>
										<td><?php echo esc_html( $term['academic_year'] ); ?></td>
										<td><?php echo esc_html( ucfirst( $term['status'] ) ); ?></td>
										<td><?php echo esc_html( sprintf( '%s to %s', $term['start_date'] ?: '-', $term['end_date'] ?: '-' ) ); ?></td>
										<td>
											<a class="button button-small" href="<?php echo esc_url( add_query_arg( array( 'page' => 'slms-terms', 'term_id' => (int) $term['id'] ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Edit', 'simple-lms' ); ?></a>
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-left:6px;">
												<input type="hidden" name="action" value="slms_delete_term">
												<input type="hidden" name="term_id" value="<?php echo esc_attr( $term['id'] ); ?>">
												<?php wp_nonce_field( 'slms_delete_term_' . (int) $term['id'] ); ?>
												<button type="submit" class="button button-small" onclick="return confirm('<?php echo esc_js( __( 'Delete this term?', 'simple-lms' ) ); ?>');"><?php esc_html_e( 'Delete', 'simple-lms' ); ?></button>
											</form>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	public function render_enrollments_page() {
		if ( ! current_user_can( RoleManager::CAP_MANAGE_ENROLLMENTS ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'simple-lms' ), 403 );
		}

		$status_filter = '';

		if ( ! empty( $_GET['status'] ) && in_array( sanitize_key( $_GET['status'] ), EnrollmentService::VALID_ENROLLMENT_STATUSES, true ) ) {
			$status_filter = sanitize_key( $_GET['status'] );
		}

		$students = get_users(
			array(
				'role'    => 'student',
				'orderby' => 'display_name',
				'order'   => 'ASC',
				'number'  => 500,
			)
		);
		$sections = get_posts(
			array(
				'post_type'      => 'slms_section',
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		$rows = $this->enrollments->list_enrollments(
			array(
				'status' => $status_filter,
			)
		);
		?>
		<div class="wrap slms-admin-page slms-enrollments-page">
			<div class="slms-page-header">
				<div>
					<p class="slms-page-kicker"><?php esc_html_e( 'Registrar', 'simple-lms' ); ?></p>
					<h1 class="slms-page-title"><?php esc_html_e( 'Section Enrollments', 'simple-lms' ); ?></h1>
					<p class="slms-page-description"><?php esc_html_e( 'Manage official section access from one place so rosters, assignments, gradebooks, and attendance all follow the same enrollment record.', 'simple-lms' ); ?></p>
				</div>
				<div class="slms-page-actions">
					<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=slms-directory' ) ); ?>"><?php esc_html_e( 'Open People Directory', 'simple-lms' ); ?></a>
					<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=slms-records' ) ); ?>"><?php esc_html_e( 'Open Records Hub', 'simple-lms' ); ?></a>
				</div>
			</div>
			<?php $this->render_notice(); ?>
			<p><?php esc_html_e( 'Use enrollments to grant students official access to class sections. This will become the canonical access model for the LMS.', 'simple-lms' ); ?></p>

			<div style="display:grid;grid-template-columns:minmax(360px,460px) 1fr;gap:20px;align-items:start;">
				<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;">
					<h2 style="margin-top:0;"><?php esc_html_e( 'Enroll Student', 'simple-lms' ); ?></h2>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="slms_save_enrollment">
						<?php wp_nonce_field( 'slms_save_enrollment' ); ?>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><label for="slms_student_user_id"><?php esc_html_e( 'Student', 'simple-lms' ); ?></label></th>
								<td>
									<select id="slms_student_user_id" name="student_user_id" style="min-width:320px;" required>
										<option value=""><?php esc_html_e( 'Select a student', 'simple-lms' ); ?></option>
										<?php foreach ( $students as $student ) : ?>
											<option value="<?php echo esc_attr( $student->ID ); ?>"><?php echo esc_html( $student->display_name . ' (' . $student->user_email . ')' ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="slms_section_id"><?php esc_html_e( 'Section', 'simple-lms' ); ?></label></th>
								<td>
									<select id="slms_section_id" name="section_id" style="min-width:320px;" required>
										<option value=""><?php esc_html_e( 'Select a section', 'simple-lms' ); ?></option>
										<?php foreach ( $sections as $section ) : ?>
											<option value="<?php echo esc_attr( $section->ID ); ?>"><?php echo esc_html( $section->post_title ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="slms_enrollment_status"><?php esc_html_e( 'Status', 'simple-lms' ); ?></label></th>
								<td>
									<select id="slms_enrollment_status" name="status">
										<?php foreach ( EnrollmentService::VALID_ENROLLMENT_STATUSES as $status ) : ?>
											<option value="<?php echo esc_attr( $status ); ?>"><?php echo esc_html( ucfirst( $status ) ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="slms_enrollment_source"><?php esc_html_e( 'Source', 'simple-lms' ); ?></label></th>
								<td><input type="text" class="regular-text" id="slms_enrollment_source" name="source" value="manual"></td>
							</tr>
							<tr>
								<th scope="row"><label for="slms_enrollment_notes"><?php esc_html_e( 'Notes', 'simple-lms' ); ?></label></th>
								<td><textarea class="large-text" rows="3" id="slms_enrollment_notes" name="notes"></textarea></td>
							</tr>
						</table>
						<?php submit_button( __( 'Save Enrollment', 'simple-lms' ) ); ?>
					</form>
				</div>

				<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;">
					<h2 style="margin-top:0;"><?php esc_html_e( 'Current Enrollments', 'simple-lms' ); ?></h2>
					<div style="margin-bottom:12px;">
						<?php foreach ( array_merge( array( '' => __( 'All', 'simple-lms' ) ), array_combine( EnrollmentService::VALID_ENROLLMENT_STATUSES, array_map( 'ucfirst', EnrollmentService::VALID_ENROLLMENT_STATUSES ) ) ) as $status => $label ) : ?>
							<?php
							$url = add_query_arg(
								array(
									'page'   => 'slms-enrollments',
									'status' => $status ?: false,
								),
								admin_url( 'admin.php' )
							);
							?>
							<a class="button <?php echo $status_filter === $status ? 'button-primary' : 'button-secondary'; ?>" href="<?php echo esc_url( $url ); ?>" style="margin-right:6px;margin-bottom:6px;"><?php echo esc_html( $label ); ?></a>
						<?php endforeach; ?>
					</div>
					<?php if ( empty( $rows ) ) : ?>
						<p><?php esc_html_e( 'No enrollments found.', 'simple-lms' ); ?></p>
					<?php else : ?>
						<table class="widefat striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Student', 'simple-lms' ); ?></th>
									<th><?php esc_html_e( 'Section', 'simple-lms' ); ?></th>
									<th><?php esc_html_e( 'Subject', 'simple-lms' ); ?></th>
									<th><?php esc_html_e( 'Status', 'simple-lms' ); ?></th>
									<th><?php esc_html_e( 'Source', 'simple-lms' ); ?></th>
									<th><?php esc_html_e( 'Enrolled', 'simple-lms' ); ?></th>
									<th><?php esc_html_e( 'Actions', 'simple-lms' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $rows as $row ) : ?>
									<tr>
										<td><?php echo esc_html( $row['student_name'] . ' (' . $row['student_email'] . ')' ); ?></td>
										<td><?php echo esc_html( $row['section_title'] ); ?></td>
										<td><?php echo esc_html( $row['subject_title'] ?: '-' ); ?></td>
										<td><?php echo esc_html( ucfirst( $row['status'] ) ); ?></td>
										<td><?php echo esc_html( $row['source'] ); ?></td>
										<td><?php echo esc_html( $row['enrolled_at'] ); ?></td>
										<td>
											<?php foreach ( EnrollmentService::VALID_ENROLLMENT_STATUSES as $status ) : ?>
												<?php if ( $status === $row['status'] ) : ?>
													<?php continue; ?>
												<?php endif; ?>
												<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin:0 4px 4px 0;">
													<input type="hidden" name="action" value="slms_change_enrollment_status">
													<input type="hidden" name="enrollment_id" value="<?php echo esc_attr( $row['id'] ); ?>">
													<input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>">
													<?php wp_nonce_field( 'slms_change_enrollment_status_' . (int) $row['id'] ); ?>
													<button type="submit" class="button button-small"><?php echo esc_html( ucfirst( $status ) ); ?></button>
												</form>
											<?php endforeach; ?>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	public function handle_save_term() {
		if ( ! current_user_can( RoleManager::CAP_MANAGE_ACADEMICS ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'simple-lms' ), 403 );
		}

		check_admin_referer( 'slms_save_term' );

		$term_id = absint( $_POST['term_id'] ?? 0 );
		$data    = array(
			'code'          => wp_unslash( $_POST['code'] ?? '' ),
			'name'          => wp_unslash( $_POST['name'] ?? '' ),
			'academic_year' => wp_unslash( $_POST['academic_year'] ?? '' ),
			'start_date'    => wp_unslash( $_POST['start_date'] ?? '' ),
			'end_date'      => wp_unslash( $_POST['end_date'] ?? '' ),
			'status'        => wp_unslash( $_POST['status'] ?? '' ),
			'sort_order'    => wp_unslash( $_POST['sort_order'] ?? 0 ),
		);

		$result = $term_id ? $this->terms->update_term( $term_id, $data ) : $this->terms->create_term( $data );

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'slms-terms', '', $result->get_error_message(), array( 'term_id' => $term_id ?: false ) );
		}

		$this->audit_logger->log(
			$term_id ? 'term_updated' : 'term_created',
			array(
				'object_type' => 'term',
				'object_id'   => (int) $result,
				'message'     => $term_id ? 'Academic term was updated.' : 'Academic term was created.',
			)
		);

		$this->redirect_with_notice( 'slms-terms', $term_id ? 'term_updated' : 'term_created' );
	}

	public function handle_delete_term() {
		if ( ! current_user_can( RoleManager::CAP_MANAGE_ACADEMICS ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'simple-lms' ), 403 );
		}

		$term_id = absint( $_POST['term_id'] ?? 0 );

		check_admin_referer( 'slms_delete_term_' . $term_id );

		$result = $this->terms->delete_term( $term_id );

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'slms-terms', '', $result->get_error_message() );
		}

		$this->audit_logger->log(
			'term_deleted',
			array(
				'object_type' => 'term',
				'object_id'   => $term_id,
				'message'     => 'Academic term was deleted.',
			)
		);

		$this->redirect_with_notice( 'slms-terms', 'term_deleted' );
	}

	public function handle_save_enrollment() {
		if ( ! current_user_can( RoleManager::CAP_MANAGE_ENROLLMENTS ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'simple-lms' ), 403 );
		}

		check_admin_referer( 'slms_save_enrollment' );

		$result = $this->enrollments->enroll_student(
			absint( $_POST['section_id'] ?? 0 ),
			absint( $_POST['student_user_id'] ?? 0 ),
			array(
				'status'     => wp_unslash( $_POST['status'] ?? 'enrolled' ),
				'source'     => wp_unslash( $_POST['source'] ?? 'manual' ),
				'notes'      => wp_unslash( $_POST['notes'] ?? '' ),
				'created_by' => get_current_user_id(),
			)
		);

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'slms-enrollments', '', $result->get_error_message() );
		}

		$this->audit_logger->log(
			'enrollment_saved',
			array(
				'object_type' => 'enrollment',
				'object_id'   => (int) $result,
				'message'     => 'A section enrollment was saved.',
			)
		);

		$this->redirect_with_notice( 'slms-enrollments', 'enrollment_saved' );
	}

	public function handle_change_enrollment_status() {
		if ( ! current_user_can( RoleManager::CAP_MANAGE_ENROLLMENTS ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'simple-lms' ), 403 );
		}

		$enrollment_id = absint( $_POST['enrollment_id'] ?? 0 );
		$status        = sanitize_key( $_POST['status'] ?? '' );

		check_admin_referer( 'slms_change_enrollment_status_' . $enrollment_id );

		$result = $this->enrollments->update_enrollment_status( $enrollment_id, $status );

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'slms-enrollments', '', $result->get_error_message() );
		}

		$this->audit_logger->log(
			'enrollment_status_updated',
			array(
				'object_type' => 'enrollment',
				'object_id'   => $enrollment_id,
				'message'     => sprintf( 'Enrollment status changed to %s.', $status ),
			)
		);

		$this->redirect_with_notice( 'slms-enrollments', 'enrollment_status_updated' );
	}

	private function redirect_with_notice( $page, $notice = '', $error = '', array $extra_args = array() ) {
		$args = array_merge(
			array(
				'page'        => $page,
				'slms_notice' => $notice ?: false,
				'slms_error'  => $error ?: false,
			),
			$extra_args
		);

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
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
			'term_created'               => __( 'Term created.', 'simple-lms' ),
			'term_updated'               => __( 'Term updated.', 'simple-lms' ),
			'term_deleted'               => __( 'Term deleted.', 'simple-lms' ),
			'enrollment_saved'           => __( 'Enrollment saved.', 'simple-lms' ),
			'enrollment_status_updated'  => __( 'Enrollment status updated.', 'simple-lms' ),
		);

		if ( empty( $map[ $notice ] ) ) {
			return;
		}
		?>
		<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $map[ $notice ] ); ?></p></div>
		<?php
	}
}
