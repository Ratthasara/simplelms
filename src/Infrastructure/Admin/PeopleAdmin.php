<?php

namespace SimpleLMS\Infrastructure\Admin;

use SimpleLMS\Domain\Academic\TermService;
use SimpleLMS\Domain\Users\ProfileService;
use SimpleLMS\Domain\Users\ProvisioningService;
use SimpleLMS\Domain\Users\RoleManager;

defined( 'ABSPATH' ) || exit;

class PeopleAdmin {
	/**
	 * @var ProfileService
	 */
	private $profiles;

	/**
	 * @var ProvisioningService
	 */
	private $provisioning;

	/**
	 * @var TermService
	 */
	private $terms;

	public function __construct( ProfileService $profiles, ProvisioningService $provisioning, TermService $terms ) {
		$this->profiles     = $profiles;
		$this->provisioning = $provisioning;
		$this->terms        = $terms;
	}

	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_slms_create_student', array( $this, 'handle_create_student' ) );
		add_action( 'admin_post_slms_create_staff', array( $this, 'handle_create_staff' ) );
		add_action( 'admin_post_slms_batch_create_students', array( $this, 'handle_batch_create_students' ) );
		add_action( 'admin_post_slms_batch_create_staff', array( $this, 'handle_batch_create_staff' ) );
	}

	public function register_menu() {
		add_submenu_page(
			'slms-dashboard',
			__( 'People Workflow', 'simple-lms' ),
			__( 'People Workflow', 'simple-lms' ),
			RoleManager::CAP_MANAGE_USERS,
			'slms-people',
			array( $this, 'render_page' )
		);

		add_submenu_page(
			'slms-dashboard',
			__( 'People Directory', 'simple-lms' ),
			__( 'People Directory', 'simple-lms' ),
			RoleManager::CAP_MANAGE_USERS,
			'slms-directory',
			array( $this, 'render_directory_page' )
		);
	}

	public function render_page() {
		if ( ! current_user_can( RoleManager::CAP_MANAGE_USERS ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'simple-lms' ), 403 );
		}

		$student_count = $this->profiles->count_profiles( 'student' );
		$staff_count   = $this->profiles->count_profiles( 'staff' );
		$recent        = $this->profiles->list_profiles(
			array(
				'page'     => 1,
				'per_page' => 12,
			)
		);
		$programs = get_terms(
			array(
				'taxonomy'   => 'slms_program',
				'hide_empty' => false,
			)
		);
		$programs = is_wp_error( $programs ) ? array() : $programs;
		$terms = $this->terms->list_terms();
		?>
		<div class="wrap slms-admin-page slms-people-page">
			<div class="slms-page-header">
				<div>
					<p class="slms-page-kicker"><?php esc_html_e( 'Admissions & Staffing', 'simple-lms' ); ?></p>
					<h1 class="slms-page-title"><?php esc_html_e( 'People Workflow', 'simple-lms' ); ?></h1>
					<p class="slms-page-description"><?php esc_html_e( 'Provision students, staff, lecturers, and officers from one academic intake workspace with quick-add and batch tools.', 'simple-lms' ); ?></p>
				</div>
				<div class="slms-page-actions">
					<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=slms-directory' ) ); ?>"><?php esc_html_e( 'Open People Directory', 'simple-lms' ); ?></a>
				</div>
			</div>
			<?php $this->render_result_notice(); ?>
			<p><?php esc_html_e( 'Use this dashboard-first workflow to provision students and staff, generate institutional codes, and send welcome access from one place.', 'simple-lms' ); ?></p>

			<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin:20px 0;">
				<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;">
					<h2 style="margin:0 0 8px;"><?php esc_html_e( 'Students', 'simple-lms' ); ?></h2>
					<p style="font-size:28px;margin:0;"><?php echo esc_html( (string) $student_count ); ?></p>
				</div>
				<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;">
					<h2 style="margin:0 0 8px;"><?php esc_html_e( 'Staff', 'simple-lms' ); ?></h2>
					<p style="font-size:28px;margin:0;"><?php echo esc_html( (string) $staff_count ); ?></p>
				</div>
			</div>

			<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(360px,1fr));gap:20px;align-items:start;">
				<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;">
					<h2 style="margin-top:0;"><?php esc_html_e( 'Quick Add Student', 'simple-lms' ); ?></h2>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="slms_create_student">
						<?php wp_nonce_field( 'slms_create_student' ); ?>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><label for="slms_student_name"><?php esc_html_e( 'Full Name', 'simple-lms' ); ?></label></th>
								<td><input id="slms_student_name" name="full_name" type="text" class="regular-text" required></td>
							</tr>
							<tr>
								<th scope="row"><label for="slms_student_email"><?php esc_html_e( 'Email', 'simple-lms' ); ?></label></th>
								<td><input id="slms_student_email" name="email" type="email" class="regular-text" required></td>
							</tr>
							<tr>
								<th scope="row"><label for="slms_student_code"><?php esc_html_e( 'Student Code', 'simple-lms' ); ?></label></th>
								<td><input id="slms_student_code" name="person_code" type="text" class="regular-text" placeholder="<?php esc_attr_e( 'Optional', 'simple-lms' ); ?>"></td>
							</tr>
							<tr>
								<th scope="row"><label for="slms_student_nrc"><?php esc_html_e( 'NRC Number', 'simple-lms' ); ?></label></th>
								<td><input id="slms_student_nrc" name="nrc_number" type="text" class="regular-text" maxlength="100" autocomplete="off" placeholder="<?php esc_attr_e( 'Optional', 'simple-lms' ); ?>"></td>
							</tr>
							<tr>
								<th scope="row"><label for="slms_student_phone"><?php esc_html_e( 'Phone', 'simple-lms' ); ?></label></th>
								<td><input id="slms_student_phone" name="phone" type="text" class="regular-text"></td>
							</tr>
							<tr>
								<th scope="row"><label for="slms_student_program"><?php esc_html_e( 'Program', 'simple-lms' ); ?></label></th>
								<td>
									<select id="slms_student_program" name="program_id" style="min-width:280px;">
										<option value=""><?php esc_html_e( 'Select a program', 'simple-lms' ); ?></option>
										<?php foreach ( $programs as $program ) : ?>
											<option value="<?php echo esc_attr( $program->term_id ); ?>"><?php echo esc_html( $program->name ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="slms_student_term"><?php esc_html_e( 'Intake Term', 'simple-lms' ); ?></label></th>
								<td>
									<select id="slms_student_term" name="intake_term_id" style="min-width:280px;">
										<option value=""><?php esc_html_e( 'Select a term', 'simple-lms' ); ?></option>
										<?php foreach ( $terms as $term ) : ?>
											<option value="<?php echo esc_attr( $term['id'] ); ?>"><?php echo esc_html( $term['name'] . ' (' . $term['academic_year'] . ')' ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Options', 'simple-lms' ); ?></th>
								<td><label><input type="checkbox" name="send_welcome_email" value="1" checked> <?php esc_html_e( 'Send welcome email', 'simple-lms' ); ?></label></td>
							</tr>
						</table>
						<?php submit_button( __( 'Create Student', 'simple-lms' ) ); ?>
					</form>
				</div>

				<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;">
					<h2 style="margin-top:0;"><?php esc_html_e( 'Quick Add Staff', 'simple-lms' ); ?></h2>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="slms_create_staff">
						<?php wp_nonce_field( 'slms_create_staff' ); ?>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><label for="slms_staff_name"><?php esc_html_e( 'Full Name', 'simple-lms' ); ?></label></th>
								<td><input id="slms_staff_name" name="full_name" type="text" class="regular-text" required></td>
							</tr>
							<tr>
								<th scope="row"><label for="slms_staff_email"><?php esc_html_e( 'Email', 'simple-lms' ); ?></label></th>
								<td><input id="slms_staff_email" name="email" type="email" class="regular-text" required></td>
							</tr>
							<tr>
								<th scope="row"><label for="slms_staff_role"><?php esc_html_e( 'Role', 'simple-lms' ); ?></label></th>
								<td>
									<select id="slms_staff_role" name="role">
										<option value="lecturer"><?php esc_html_e( 'Lecturer', 'simple-lms' ); ?></option>
										<option value="<?php echo esc_attr( RoleManager::ROLE_STAFF ); ?>"><?php esc_html_e( 'Staff (minimum access)', 'simple-lms' ); ?></option>
										<option value="officer"><?php esc_html_e( 'Officer', 'simple-lms' ); ?></option>
										<?php if ( current_user_can( 'manage_options' ) ) : ?>
											<option value="administrator"><?php esc_html_e( 'Administrator', 'simple-lms' ); ?></option>
										<?php endif; ?>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="slms_staff_code"><?php esc_html_e( 'Staff Code', 'simple-lms' ); ?></label></th>
								<td><input id="slms_staff_code" name="person_code" type="text" class="regular-text" placeholder="<?php esc_attr_e( 'Optional', 'simple-lms' ); ?>"></td>
							</tr>
							<tr>
								<th scope="row"><label for="slms_staff_nrc"><?php esc_html_e( 'NRC Number', 'simple-lms' ); ?></label></th>
								<td><input id="slms_staff_nrc" name="nrc_number" type="text" class="regular-text" maxlength="100" autocomplete="off" placeholder="<?php esc_attr_e( 'Optional', 'simple-lms' ); ?>"></td>
							</tr>
							<tr>
								<th scope="row"><label for="slms_staff_department"><?php esc_html_e( 'Department', 'simple-lms' ); ?></label></th>
								<td><input id="slms_staff_department" name="department" type="text" class="regular-text"></td>
							</tr>
							<tr>
								<th scope="row"><label for="slms_staff_position"><?php esc_html_e( 'Position', 'simple-lms' ); ?></label></th>
								<td><input id="slms_staff_position" name="position_title" type="text" class="regular-text"></td>
							</tr>
							<tr>
								<th scope="row"><label for="slms_staff_phone"><?php esc_html_e( 'Phone', 'simple-lms' ); ?></label></th>
								<td><input id="slms_staff_phone" name="phone" type="text" class="regular-text"></td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Options', 'simple-lms' ); ?></th>
								<td><label><input type="checkbox" name="send_welcome_email" value="1" checked> <?php esc_html_e( 'Send welcome email', 'simple-lms' ); ?></label></td>
							</tr>
						</table>
						<?php submit_button( __( 'Create Staff', 'simple-lms' ) ); ?>
					</form>
				</div>
			</div>

			<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(360px,1fr));gap:20px;align-items:start;margin-top:20px;">
				<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;">
					<h2 style="margin-top:0;"><?php esc_html_e( 'Batch Add Students', 'simple-lms' ); ?></h2>
					<p><?php esc_html_e( 'One person per line: Full Name, Email, Code, NRC Number, Program ID, Intake Term ID, Phone', 'simple-lms' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="slms_batch_create_students">
						<?php wp_nonce_field( 'slms_batch_create_students' ); ?>
						<textarea name="batch_input" class="large-text code" rows="8" placeholder="Aye Aye, student1@example.com, STU-001, 12/LAMANA(N)123456, 12, 3, 099999999"></textarea>
						<?php submit_button( __( 'Batch Create Students', 'simple-lms' ) ); ?>
					</form>
				</div>

				<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;">
					<h2 style="margin-top:0;"><?php esc_html_e( 'Batch Add Staff', 'simple-lms' ); ?></h2>
					<p><?php esc_html_e( 'One person per line: Full Name, Email, Code, NRC Number, Role, Department, Position, Phone', 'simple-lms' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="slms_batch_create_staff">
						<?php wp_nonce_field( 'slms_batch_create_staff' ); ?>
						<textarea name="batch_input" class="large-text code" rows="8" placeholder="Dr Lin, lecturer@example.com, STF-001, 12/LAMANA(N)654321, lecturer, Economics, Senior Lecturer, 098888888"></textarea>
						<?php submit_button( __( 'Batch Create Staff', 'simple-lms' ) ); ?>
					</form>
				</div>
			</div>

			<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;margin-top:20px;">
				<h2 style="margin-top:0;"><?php esc_html_e( 'Recent People Records', 'simple-lms' ); ?></h2>
				<?php if ( empty( $recent ) ) : ?>
					<p><?php esc_html_e( 'No profiles have been created yet.', 'simple-lms' ); ?></p>
				<?php else : ?>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Code', 'simple-lms' ); ?></th>
								<th><?php esc_html_e( 'Name', 'simple-lms' ); ?></th>
								<th><?php esc_html_e( 'Email', 'simple-lms' ); ?></th>
								<th><?php esc_html_e( 'Type', 'simple-lms' ); ?></th>
								<th><?php esc_html_e( 'Status', 'simple-lms' ); ?></th>
								<th><?php esc_html_e( 'Role/Track', 'simple-lms' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $recent as $row ) : ?>
								<tr>
									<td><?php echo esc_html( $row['person_code'] ?: '-' ); ?></td>
									<td><?php echo $this->get_profile_avatar_name_markup( $row, 'is-compact' ); ?></td>
									<td><?php echo esc_html( $row['user_email'] ); ?></td>
									<td><?php echo esc_html( ucfirst( $row['person_type'] ) ); ?></td>
									<td><?php echo esc_html( ucfirst( $row['status'] ) ); ?></td>
									<td><?php $row_role = RoleManager::get_primary_role( (int) $row['user_id'] ); echo esc_html( 'student' === $row['person_type'] ? ( $row['program_id'] ? sprintf( __( 'Program #%d', 'simple-lms' ), (int) $row['program_id'] ) : __( 'Unassigned', 'simple-lms' ) ) : ( $row_role ? RoleManager::get_role_label( $row_role ) : '-' ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	public function render_directory_page() {
		if ( ! current_user_can( RoleManager::CAP_MANAGE_USERS ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'simple-lms' ), 403 );
		}

		$page = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$filters = array(
			'person_type' => sanitize_key( wp_unslash( $_GET['person_type'] ?? '' ) ),
			'status'      => sanitize_key( wp_unslash( $_GET['status'] ?? '' ) ),
			'search'      => sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ),
			'page'        => $page,
			'per_page'    => 25,
		);
		$profiles       = $this->profiles->list_profiles( $filters );
		$total_profiles = $this->profiles->count_profiles_matching( $filters );
		$total_pages    = max( 1, (int) ceil( $total_profiles / $filters['per_page'] ) );
		$student_count  = $this->profiles->count_profiles( 'student' );
		$staff_count    = $this->profiles->count_profiles( 'staff' );
		$invited_count  = $this->profiles->count_profiles_matching(
			array(
				'status' => 'invited',
			)
		);
		?>
		<div class="wrap slms-admin-page slms-directory-page">
			<div class="slms-page-header">
				<div>
					<p class="slms-page-kicker"><?php esc_html_e( 'Directory & Oversight', 'simple-lms' ); ?></p>
					<h1 class="slms-page-title"><?php esc_html_e( 'People Directory', 'simple-lms' ); ?></h1>
					<p class="slms-page-description"><?php esc_html_e( 'Browse every student and staff member in one organized university directory, with filters that make it easy for admins and officers to audit academic identity data quickly.', 'simple-lms' ); ?></p>
				</div>
				<div class="slms-page-actions">
					<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=slms-people' ) ); ?>"><?php esc_html_e( 'Open People Workflow', 'simple-lms' ); ?></a>
				</div>
			</div>

			<div class="slms-card-grid">
				<div class="slms-stat-card">
					<p class="slms-stat-label"><?php esc_html_e( 'Students', 'simple-lms' ); ?></p>
					<p class="slms-stat-value"><?php echo esc_html( (string) $student_count ); ?></p>
				</div>
				<div class="slms-stat-card">
					<p class="slms-stat-label"><?php esc_html_e( 'Staff', 'simple-lms' ); ?></p>
					<p class="slms-stat-value"><?php echo esc_html( (string) $staff_count ); ?></p>
				</div>
				<div class="slms-stat-card">
					<p class="slms-stat-label"><?php esc_html_e( 'Invited', 'simple-lms' ); ?></p>
					<p class="slms-stat-value"><?php echo esc_html( (string) $invited_count ); ?></p>
				</div>
				<div class="slms-stat-card">
					<p class="slms-stat-label"><?php esc_html_e( 'Filtered Results', 'simple-lms' ); ?></p>
					<p class="slms-stat-value"><?php echo esc_html( (string) $total_profiles ); ?></p>
				</div>
			</div>

			<div class="slms-surface">
				<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="slms-stack">
					<input type="hidden" name="page" value="slms-directory">
					<div class="slms-form-grid">
						<div>
							<label class="slms-field-label" for="slms_directory_search"><?php esc_html_e( 'Search', 'simple-lms' ); ?></label>
							<input id="slms_directory_search" type="text" name="s" class="regular-text" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="<?php esc_attr_e( 'Name, email, code, department', 'simple-lms' ); ?>">
						</div>
						<div>
							<label class="slms-field-label" for="slms_directory_type"><?php esc_html_e( 'Type', 'simple-lms' ); ?></label>
							<select id="slms_directory_type" name="person_type">
								<option value=""><?php esc_html_e( 'All people', 'simple-lms' ); ?></option>
								<?php foreach ( ProfileService::PERSON_TYPES as $type ) : ?>
									<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $filters['person_type'], $type ); ?>><?php echo esc_html( ucfirst( $type ) ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<div>
							<label class="slms-field-label" for="slms_directory_status"><?php esc_html_e( 'Status', 'simple-lms' ); ?></label>
							<select id="slms_directory_status" name="status">
								<option value=""><?php esc_html_e( 'All statuses', 'simple-lms' ); ?></option>
								<?php foreach ( ProfileService::STATUSES as $status ) : ?>
									<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $filters['status'], $status ); ?>><?php echo esc_html( ucfirst( $status ) ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
					</div>
					<div class="slms-inline-actions">
						<?php submit_button( __( 'Filter Directory', 'simple-lms' ), 'primary', 'submit', false ); ?>
						<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=slms-directory' ) ); ?>"><?php esc_html_e( 'Reset Filters', 'simple-lms' ); ?></a>
					</div>
				</form>
			</div>

			<div class="slms-surface">
				<h2><?php esc_html_e( 'University People Records', 'simple-lms' ); ?></h2>
				<?php if ( empty( $profiles ) ) : ?>
					<p class="slms-empty-copy"><?php esc_html_e( 'No people match the current filters.', 'simple-lms' ); ?></p>
				<?php else : ?>
					<div class="slms-table-wrap">
						<table class="widefat striped slms-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Code', 'simple-lms' ); ?></th>
									<th><?php esc_html_e( 'NRC', 'simple-lms' ); ?></th>
									<th><?php esc_html_e( 'Person', 'simple-lms' ); ?></th>
									<th><?php esc_html_e( 'Type', 'simple-lms' ); ?></th>
									<th><?php esc_html_e( 'Role / Program', 'simple-lms' ); ?></th>
									<th><?php esc_html_e( 'Department', 'simple-lms' ); ?></th>
									<th><?php esc_html_e( 'Status', 'simple-lms' ); ?></th>
									<th><?php esc_html_e( 'Invite', 'simple-lms' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $profiles as $row ) : ?>
									<tr>
										<td><?php echo esc_html( $row['person_code'] ?: '-' ); ?></td>
										<td><?php echo esc_html( ( $row['nrc_number'] ?? '' ) ?: '-' ); ?></td>
										<td>
											<strong><?php echo $this->get_profile_avatar_name_markup( $row, 'is-compact' ); ?></strong><br>
											<span class="slms-subtle-text"><?php echo esc_html( $row['user_email'] ); ?></span>
										</td>
										<td><?php echo esc_html( ucfirst( $row['person_type'] ) ); ?></td>
										<td><?php echo esc_html( $this->get_role_track_label( $row ) ); ?></td>
										<td><?php echo esc_html( $row['department'] ?: '-' ); ?></td>
										<td><?php echo esc_html( ucfirst( $row['status'] ) ); ?></td>
										<td><?php echo esc_html( $row['invite_sent_at'] ?: __( 'Not sent', 'simple-lms' ) ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>

					<?php
					echo wp_kses_post(
						paginate_links(
							array(
								'base'      => add_query_arg( 'paged', '%#%' ),
								'format'    => '',
								'current'   => $page,
								'total'     => $total_pages,
								'type'      => 'plain',
								'prev_text' => __( 'Previous', 'simple-lms' ),
								'next_text' => __( 'Next', 'simple-lms' ),
							)
						)
					);
					?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	public function handle_create_student() {
		$this->assert_manage_users();
		check_admin_referer( 'slms_create_student' );

		$result = $this->provisioning->create_student(
			array(
				'full_name'          => wp_unslash( $_POST['full_name'] ?? '' ),
				'email'              => wp_unslash( $_POST['email'] ?? '' ),
				'person_code'        => wp_unslash( $_POST['person_code'] ?? '' ),
				'nrc_number'         => wp_unslash( $_POST['nrc_number'] ?? '' ),
				'phone'              => wp_unslash( $_POST['phone'] ?? '' ),
				'program_id'         => absint( $_POST['program_id'] ?? 0 ),
				'intake_term_id'     => absint( $_POST['intake_term_id'] ?? 0 ),
				'send_welcome_email' => ! empty( $_POST['send_welcome_email'] ),
			)
		);

		$this->redirect_with_result(
			is_wp_error( $result )
				? array( 'created' => 0, 'failed' => 1, 'errors' => array( $result->get_error_message() ) )
				: array( 'created' => 1, 'failed' => 0, 'errors' => array() )
		);
	}

	public function handle_create_staff() {
		$this->assert_manage_users();
		check_admin_referer( 'slms_create_staff' );

		$result = $this->provisioning->create_staff(
			array(
				'full_name'          => wp_unslash( $_POST['full_name'] ?? '' ),
				'email'              => wp_unslash( $_POST['email'] ?? '' ),
				'person_code'        => wp_unslash( $_POST['person_code'] ?? '' ),
				'nrc_number'         => wp_unslash( $_POST['nrc_number'] ?? '' ),
				'role'               => wp_unslash( $_POST['role'] ?? 'lecturer' ),
				'department'         => wp_unslash( $_POST['department'] ?? '' ),
				'position_title'     => wp_unslash( $_POST['position_title'] ?? '' ),
				'phone'              => wp_unslash( $_POST['phone'] ?? '' ),
				'send_welcome_email' => ! empty( $_POST['send_welcome_email'] ),
			)
		);

		$this->redirect_with_result(
			is_wp_error( $result )
				? array( 'created' => 0, 'failed' => 1, 'errors' => array( $result->get_error_message() ) )
				: array( 'created' => 1, 'failed' => 0, 'errors' => array() )
		);
	}

	public function handle_batch_create_students() {
		$this->assert_manage_users();
		check_admin_referer( 'slms_batch_create_students' );

		$result = $this->provisioning->batch_create_students( wp_unslash( $_POST['batch_input'] ?? '' ) );
		$this->redirect_with_result( $result );
	}

	public function handle_batch_create_staff() {
		$this->assert_manage_users();
		check_admin_referer( 'slms_batch_create_staff' );

		$result = $this->provisioning->batch_create_staff( wp_unslash( $_POST['batch_input'] ?? '' ) );
		$this->redirect_with_result( $result );
	}

	private function assert_manage_users() {
		if ( ! current_user_can( RoleManager::CAP_MANAGE_USERS ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'simple-lms' ), 403 );
		}
	}

	private function redirect_with_result( array $summary ) {
		$key = 'slms_people_result_' . get_current_user_id() . '_' . time();
		set_transient( $key, $summary, 10 * MINUTE_IN_SECONDS );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => 'slms-people',
					'slms_result' => $key,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private function render_result_notice() {
		$key = sanitize_text_field( wp_unslash( $_GET['slms_result'] ?? '' ) );

		if ( empty( $key ) ) {
			return;
		}

		$result = get_transient( $key );

		if ( ! is_array( $result ) ) {
			return;
		}

		delete_transient( $key );

		$created = absint( $result['created'] ?? 0 );
		$failed  = absint( $result['failed'] ?? 0 );
		$errors  = array_slice( (array) ( $result['errors'] ?? array() ), 0, 5 );
		?>
		<div class="notice <?php echo $failed ? 'notice-warning' : 'notice-success'; ?> is-dismissible">
			<p><?php echo esc_html( sprintf( __( 'Workflow complete: %1$d created, %2$d failed.', 'simple-lms' ), $created, $failed ) ); ?></p>
			<?php if ( $errors ) : ?>
				<ul style="margin:8px 0 0 18px;">
					<?php foreach ( $errors as $error ) : ?>
						<li><?php echo esc_html( $error ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
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

	private function get_role_track_label( array $row ) {
		if ( 'student' === $row['person_type'] ) {
			if ( ! empty( $row['program_id'] ) ) {
				$program = get_term( (int) $row['program_id'], 'slms_program' );

				if ( $program && ! is_wp_error( $program ) ) {
					return $program->name;
				}
			}

			return __( 'Unassigned Program', 'simple-lms' );
		}

		$role = RoleManager::get_primary_role( (int) $row['user_id'] );

		if ( ! empty( $row['position_title'] ) ) {
			return $row['position_title'] . ( $role ? ' - ' . RoleManager::get_role_label( $role ) : '' );
		}

		return $role ? RoleManager::get_role_label( $role ) : __( 'Staff', 'simple-lms' );
	}
}
