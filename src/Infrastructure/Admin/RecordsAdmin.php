<?php

namespace SimpleLMS\Infrastructure\Admin;

use SimpleLMS\Domain\Reporting\RecordsService;
use SimpleLMS\Domain\Users\RoleManager;

defined( 'ABSPATH' ) || exit;

class RecordsAdmin {
	/**
	 * @var RecordsService
	 */
	private $records;

	public function __construct( RecordsService $records ) {
		$this->records = $records;
	}

	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
	}

	public function register_menu() {
		$role = RoleManager::get_primary_role();

		if ( ! in_array( $role, array( 'administrator', 'officer' ), true ) ) {
			return;
		}

		add_submenu_page(
			'slms-dashboard',
			__( 'Records Hub', 'simple-lms' ),
			__( 'Records Hub', 'simple-lms' ),
			RoleManager::CAP_VIEW_REPORTS,
			'slms-records',
			array( $this, 'render_page' )
		);
	}

	public function render_page() {
		if ( ! $this->records->can_view_records( get_current_user_id() ) ) {
			wp_die( esc_html__( 'You do not have permission to access the records hub.', 'simple-lms' ), 403 );
		}

		$filters           = $this->records->sanitize_filters(
			array(
				'academic_year'  => wp_unslash( $_GET['academic_year'] ?? '' ),
				'term_id'        => wp_unslash( $_GET['term_id'] ?? 0 ),
				'section_status' => wp_unslash( $_GET['section_status'] ?? '' ),
				'search'         => wp_unslash( $_GET['s'] ?? '' ),
			)
		);
		$sections          = $this->records->get_sections( $filters, get_current_user_id() );
		$selected_section  = absint( $_GET['section_id'] ?? 0 );
		$options           = $this->records->get_filter_options();

		if ( ! $selected_section && is_array( $sections ) && ! empty( $sections ) ) {
			$selected_section = (int) $sections[0]['id'];
		}

		$detail = $selected_section ? $this->records->get_section_record( $selected_section, get_current_user_id() ) : null;
		?>
		<div class="wrap slms-admin-page slms-records-page">
			<div class="slms-page-header">
				<div>
					<p class="slms-page-kicker"><?php esc_html_e( 'Long-term Oversight', 'simple-lms' ); ?></p>
					<h1 class="slms-page-title"><?php esc_html_e( 'Academic Records Hub', 'simple-lms' ); ?></h1>
					<p class="slms-page-description"><?php esc_html_e( 'Browse historical section records by year, term, status, and search. Designed for registrars and academic officers who need to review rosters, attendance history, and grade outcomes years after teaching has finished.', 'simple-lms' ); ?></p>
				</div>
				<div class="slms-page-actions">
					<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=slms-terms' ) ); ?>"><?php esc_html_e( 'Manage Terms', 'simple-lms' ); ?></a>
					<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=slms-directory' ) ); ?>"><?php esc_html_e( 'Open People Directory', 'simple-lms' ); ?></a>
				</div>
			</div>

			<?php if ( is_wp_error( $sections ) ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $sections->get_error_message() ); ?></p></div>
				<?php
				return;
			endif;
			?>

			<?php $this->render_summary_cards( $sections ); ?>

			<div class="slms-record-shell">
				<div class="slms-sidebar-stack">
					<div class="slms-surface">
						<h2><?php esc_html_e( 'Find Records', 'simple-lms' ); ?></h2>
						<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="slms-stack">
							<input type="hidden" name="page" value="slms-records">
							<div class="slms-form-grid">
								<div>
									<label class="slms-field-label" for="slms_record_year"><?php esc_html_e( 'Academic Year', 'simple-lms' ); ?></label>
									<select id="slms_record_year" name="academic_year">
										<option value=""><?php esc_html_e( 'All years', 'simple-lms' ); ?></option>
										<?php foreach ( $options['academic_years'] as $year ) : ?>
											<option value="<?php echo esc_attr( $year ); ?>" <?php selected( $filters['academic_year'], $year ); ?>><?php echo esc_html( $year ); ?></option>
										<?php endforeach; ?>
									</select>
								</div>
								<div>
									<label class="slms-field-label" for="slms_record_term"><?php esc_html_e( 'Term', 'simple-lms' ); ?></label>
									<select id="slms_record_term" name="term_id">
										<option value=""><?php esc_html_e( 'All terms', 'simple-lms' ); ?></option>
										<?php foreach ( $options['terms'] as $term ) : ?>
											<option value="<?php echo esc_attr( $term['id'] ); ?>" <?php selected( $filters['term_id'], (int) $term['id'] ); ?>><?php echo esc_html( $term['name'] . ' (' . $term['academic_year'] . ')' ); ?></option>
										<?php endforeach; ?>
									</select>
								</div>
								<div>
									<label class="slms-field-label" for="slms_record_status"><?php esc_html_e( 'Section Status', 'simple-lms' ); ?></label>
									<select id="slms_record_status" name="section_status">
										<option value=""><?php esc_html_e( 'All statuses', 'simple-lms' ); ?></option>
										<?php foreach ( $options['section_statuses'] as $status ) : ?>
											<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $filters['section_status'], $status ); ?>><?php echo esc_html( ucfirst( $status ) ); ?></option>
										<?php endforeach; ?>
									</select>
								</div>
								<div>
									<label class="slms-field-label" for="slms_record_search"><?php esc_html_e( 'Search', 'simple-lms' ); ?></label>
									<input id="slms_record_search" type="text" name="s" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="<?php esc_attr_e( 'Section, code, subject', 'simple-lms' ); ?>">
								</div>
							</div>
							<div class="slms-inline-actions">
								<?php submit_button( __( 'Apply Filters', 'simple-lms' ), 'primary', 'submit', false ); ?>
								<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=slms-records' ) ); ?>"><?php esc_html_e( 'Reset', 'simple-lms' ); ?></a>
							</div>
						</form>
					</div>

					<div class="slms-surface">
						<div class="slms-surface-header">
							<div>
								<h2><?php esc_html_e( 'Section Archive', 'simple-lms' ); ?></h2>
								<p class="slms-empty-copy"><?php echo esc_html( sprintf( _n( '%d section matches the current filters.', '%d sections match the current filters.', count( $sections ), 'simple-lms' ), count( $sections ) ) ); ?></p>
							</div>
						</div>
						<?php $this->render_section_list( $sections, $selected_section, $filters ); ?>
					</div>
				</div>

				<div class="slms-content-stack">
					<?php if ( $detail && is_wp_error( $detail ) ) : ?>
						<div class="notice notice-error"><p><?php echo esc_html( $detail->get_error_message() ); ?></p></div>
					<?php elseif ( $detail ) : ?>
						<?php $this->render_detail_panel( $detail ); ?>
					<?php else : ?>
						<div class="slms-empty-state">
							<h2><?php esc_html_e( 'No record selected.', 'simple-lms' ); ?></h2>
							<p><?php esc_html_e( 'Choose a section from the archive list to inspect rosters, attendance history, and grade outcomes.', 'simple-lms' ); ?></p>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	private function render_summary_cards( array $sections ) {
		$enrollments = 0;
		$sessions    = 0;
		$closed      = 0;

		foreach ( $sections as $section ) {
			$enrollments += (int) $section['active_enrollment_count'];
			$sessions    += (int) $section['attendance_session_count'];

			if ( in_array( $section['section_status'], array( 'closed', 'archived' ), true ) ) {
				$closed++;
			}
		}
		?>
		<div class="slms-card-grid">
			<div class="slms-stat-card">
				<p class="slms-stat-label"><?php esc_html_e( 'Filtered Sections', 'simple-lms' ); ?></p>
				<p class="slms-stat-value"><?php echo esc_html( (string) count( $sections ) ); ?></p>
			</div>
			<div class="slms-stat-card">
				<p class="slms-stat-label"><?php esc_html_e( 'Active Roster Entries', 'simple-lms' ); ?></p>
				<p class="slms-stat-value"><?php echo esc_html( (string) $enrollments ); ?></p>
			</div>
			<div class="slms-stat-card">
				<p class="slms-stat-label"><?php esc_html_e( 'Recorded Class Meetings', 'simple-lms' ); ?></p>
				<p class="slms-stat-value"><?php echo esc_html( (string) $sessions ); ?></p>
			</div>
			<div class="slms-stat-card">
				<p class="slms-stat-label"><?php esc_html_e( 'Closed / Archived', 'simple-lms' ); ?></p>
				<p class="slms-stat-value"><?php echo esc_html( (string) $closed ); ?></p>
			</div>
		</div>
		<?php
	}

	private function render_section_list( array $sections, $selected_section, array $filters ) {
		if ( empty( $sections ) ) {
			echo '<p class="slms-empty-copy">' . esc_html__( 'No sections were found for the selected filters.', 'simple-lms' ) . '</p>';
			return;
		}

		foreach ( $sections as $section ) {
			$url = add_query_arg(
				array(
					'page'           => 'slms-records',
					'academic_year'  => $filters['academic_year'] ?: false,
					'term_id'        => $filters['term_id'] ?: false,
					'section_status' => $filters['section_status'] ?: false,
					's'              => $filters['search'] ?: false,
					'section_id'     => (int) $section['id'],
				),
				admin_url( 'admin.php' )
			);
			$current_class = (int) $selected_section === (int) $section['id'] ? ' is-active' : '';
			?>
			<a class="slms-list-card<?php echo esc_attr( $current_class ); ?>" href="<?php echo esc_url( $url ); ?>">
				<div class="slms-list-card-head">
					<div>
						<strong><?php echo esc_html( $section['title'] ); ?></strong>
						<div class="slms-subtle-text"><?php echo esc_html( $section['subject_title'] ?: $section['section_code'] ); ?></div>
					</div>
					<span class="slms-badge slms-badge--<?php echo esc_attr( sanitize_html_class( $section['section_status'] ?: 'planned' ) ); ?>"><?php echo esc_html( ucfirst( $section['section_status'] ?: 'planned' ) ); ?></span>
				</div>
				<div class="slms-kv-grid">
					<div>
						<span class="slms-subtle-text"><?php esc_html_e( 'Term', 'simple-lms' ); ?></span>
						<div><?php echo esc_html( $section['term_name'] ?: __( 'Unassigned', 'simple-lms' ) ); ?></div>
					</div>
					<div>
						<span class="slms-subtle-text"><?php esc_html_e( 'Year', 'simple-lms' ); ?></span>
						<div><?php echo esc_html( $section['term_academic_year'] ?: '-' ); ?></div>
					</div>
					<div>
						<span class="slms-subtle-text"><?php esc_html_e( 'Students', 'simple-lms' ); ?></span>
						<div><?php echo esc_html( (string) (int) $section['active_enrollment_count'] ); ?></div>
					</div>
					<div>
						<span class="slms-subtle-text"><?php esc_html_e( 'Meetings', 'simple-lms' ); ?></span>
						<div><?php echo esc_html( (string) (int) $section['attendance_session_count'] ); ?></div>
					</div>
				</div>
			</a>
			<?php
		}
	}

	private function render_detail_panel( array $detail ) {
		$section    = $detail['section'];
		$attendance = $detail['attendance'];
		$gradebook  = $detail['gradebook'];
		$term       = $detail['term'];
		?>
		<div class="slms-surface">
			<div class="slms-surface-header">
				<div>
					<p class="slms-page-kicker"><?php esc_html_e( 'Section Record', 'simple-lms' ); ?></p>
					<h2 class="slms-panel-title"><?php echo esc_html( $section['title'] ); ?></h2>
					<p class="slms-page-description"><?php echo esc_html( $section['subject_title'] ?: $section['section_code'] ); ?></p>
				</div>
				<div class="slms-page-actions">
					<a class="button button-secondary" href="<?php echo esc_url( add_query_arg( array( 'page' => 'slms-gradebook', 'section_id' => (int) $section['id'] ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Open Gradebook', 'simple-lms' ); ?></a>
					<a class="button button-secondary" href="<?php echo esc_url( add_query_arg( array( 'page' => 'slms-attendance', 'section_id' => (int) $section['id'] ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Open Attendance', 'simple-lms' ); ?></a>
					<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'post.php?post=' . (int) $section['id'] . '&action=edit' ) ); ?>"><?php esc_html_e( 'Edit Section', 'simple-lms' ); ?></a>
				</div>
			</div>

			<div class="slms-kv-grid slms-kv-grid-wide">
				<div>
					<span class="slms-subtle-text"><?php esc_html_e( 'Section Code', 'simple-lms' ); ?></span>
					<div><?php echo esc_html( $section['section_code'] ?: '-' ); ?></div>
				</div>
				<div>
					<span class="slms-subtle-text"><?php esc_html_e( 'Status', 'simple-lms' ); ?></span>
					<div><span class="slms-badge slms-badge--<?php echo esc_attr( sanitize_html_class( $section['section_status'] ?: 'planned' ) ); ?>"><?php echo esc_html( ucfirst( $section['section_status'] ?: 'planned' ) ); ?></span></div>
				</div>
				<div>
					<span class="slms-subtle-text"><?php esc_html_e( 'Term', 'simple-lms' ); ?></span>
					<div><?php echo esc_html( $section['term_name'] ?: __( 'Unassigned', 'simple-lms' ) ); ?></div>
				</div>
				<div>
					<span class="slms-subtle-text"><?php esc_html_e( 'Academic Year', 'simple-lms' ); ?></span>
					<div><?php echo esc_html( $section['term_academic_year'] ?: ( $term['academic_year'] ?? '-' ) ); ?></div>
				</div>
				<div>
					<span class="slms-subtle-text"><?php esc_html_e( 'Delivery Mode', 'simple-lms' ); ?></span>
					<div><?php echo esc_html( ucfirst( $section['delivery_mode'] ?: '-' ) ); ?></div>
				</div>
				<div>
					<span class="slms-subtle-text"><?php esc_html_e( 'Capacity', 'simple-lms' ); ?></span>
					<div><?php echo esc_html( (string) (int) $section['capacity'] ); ?></div>
				</div>
			</div>
		</div>

		<div class="slms-card-grid">
			<div class="slms-stat-card">
				<p class="slms-stat-label"><?php esc_html_e( 'Roster Size', 'simple-lms' ); ?></p>
				<p class="slms-stat-value"><?php echo esc_html( (string) count( $detail['roster'] ) ); ?></p>
			</div>
			<div class="slms-stat-card">
				<p class="slms-stat-label"><?php esc_html_e( 'Recorded Meetings', 'simple-lms' ); ?></p>
				<p class="slms-stat-value"><?php echo esc_html( (string) (int) $attendance['completed_sessions'] ); ?></p>
			</div>
			<div class="slms-stat-card">
				<p class="slms-stat-label"><?php esc_html_e( 'Average Attendance', 'simple-lms' ); ?></p>
				<p class="slms-stat-value"><?php echo esc_html( number_format_i18n( (float) $attendance['average_attendance'], 2 ) ); ?>%</p>
			</div>
			<div class="slms-stat-card">
				<p class="slms-stat-label"><?php esc_html_e( 'Grade Categories', 'simple-lms' ); ?></p>
				<p class="slms-stat-value"><?php echo esc_html( (string) count( $gradebook['categories'] ) ); ?></p>
			</div>
		</div>

		<div class="slms-detail-grid">
			<div class="slms-surface">
				<h2><?php esc_html_e( 'Roster Snapshot', 'simple-lms' ); ?></h2>
				<p class="slms-empty-copy"><?php esc_html_e( 'This combines official enrollment, attendance rollup, and current gradebook totals for a quick academic history check.', 'simple-lms' ); ?></p>
				<?php $this->render_roster_table( $detail['roster'] ); ?>
			</div>

			<div class="slms-surface">
				<h2><?php esc_html_e( 'Recent Class Meetings', 'simple-lms' ); ?></h2>
				<p class="slms-empty-copy"><?php esc_html_e( 'The latest meetings are shown first so older sections can still be audited quickly years later.', 'simple-lms' ); ?></p>
				<?php $this->render_recent_sessions( $attendance['sessions'] ); ?>
			</div>
		</div>

		<div class="slms-surface">
			<h2><?php esc_html_e( 'Grade Structure on Record', 'simple-lms' ); ?></h2>
			<?php $this->render_grade_structure( $gradebook['categories'] ); ?>
		</div>
		<?php
	}

	private function render_roster_table( array $roster ) {
		if ( empty( $roster ) ) {
			echo '<p class="slms-empty-copy">' . esc_html__( 'No roster entries are available for this section.', 'simple-lms' ) . '</p>';
			return;
		}
		?>
		<div class="slms-table-wrap">
			<table class="widefat striped slms-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Student', 'simple-lms' ); ?></th>
						<th><?php esc_html_e( 'Enrollment', 'simple-lms' ); ?></th>
						<th><?php esc_html_e( 'Attendance', 'simple-lms' ); ?></th>
						<th><?php esc_html_e( 'Total Score', 'simple-lms' ); ?></th>
						<th><?php esc_html_e( 'Grade', 'simple-lms' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $roster as $student ) : ?>
						<tr>
							<td>
								<strong><?php echo esc_html( $student['student_name'] ); ?></strong><br>
								<span class="slms-subtle-text"><?php echo esc_html( $student['student_email'] ); ?></span>
							</td>
							<td>
								<?php echo esc_html( ucfirst( $student['enrollment_status'] ) ); ?><br>
								<span class="slms-subtle-text"><?php echo esc_html( $student['enrolled_at'] ); ?></span>
							</td>
							<td>
								<strong><?php echo esc_html( number_format_i18n( $student['attendance_percentage'], 2 ) ); ?>%</strong><br>
								<span class="slms-subtle-text"><?php echo esc_html( sprintf( __( '%d counted meetings', 'simple-lms' ), (int) $student['eligible_sessions'] ) ); ?></span>
							</td>
							<td><?php echo null !== $student['total_score'] ? esc_html( number_format_i18n( (float) $student['total_score'], 2 ) ) : '-'; ?></td>
							<td><strong><?php echo esc_html( '' !== $student['letter_grade'] ? $student['letter_grade'] : '-' ); ?></strong></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private function render_recent_sessions( array $sessions ) {
		if ( empty( $sessions ) ) {
			echo '<p class="slms-empty-copy">' . esc_html__( 'No attendance meetings have been recorded for this section.', 'simple-lms' ) . '</p>';
			return;
		}
		?>
		<div class="slms-table-wrap">
			<table class="widefat striped slms-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Meeting', 'simple-lms' ); ?></th>
						<th><?php esc_html_e( 'Date', 'simple-lms' ); ?></th>
						<th><?php esc_html_e( 'Recorded', 'simple-lms' ); ?></th>
						<th><?php esc_html_e( 'Breakdown', 'simple-lms' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( array_slice( $sessions, 0, 8 ) as $session ) : ?>
						<tr>
							<td>
								<strong><?php echo esc_html( $session['session_title'] ); ?></strong><br>
								<span class="slms-subtle-text"><?php echo esc_html( sprintf( __( 'Class %d', 'simple-lms' ), (int) $session['session_number'] ) ); ?></span>
							</td>
							<td><?php echo esc_html( $session['session_date'] ); ?></td>
							<td><?php echo esc_html( ucfirst( $session['status'] ) ); ?></td>
							<td><?php echo esc_html( sprintf( __( '%1$d present, %2$d late, %3$d leave, %4$d absent', 'simple-lms' ), (int) $session['present_count'], (int) $session['late_count'], (int) $session['leave_count'], (int) $session['absent_count'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private function render_grade_structure( array $categories ) {
		if ( empty( $categories ) ) {
			echo '<p class="slms-empty-copy">' . esc_html__( 'No grade structure has been configured for this section yet.', 'simple-lms' ) . '</p>';
			return;
		}
		?>
		<div class="slms-grade-structure-grid">
			<?php foreach ( $categories as $category ) : ?>
				<div class="slms-item-card">
					<div class="slms-surface-header">
						<div>
							<h3><?php echo esc_html( $category['title'] ); ?></h3>
							<div class="slms-chip-row">
								<span class="slms-chip"><?php echo esc_html( ucfirst( $category['category_type'] ) ); ?></span>
								<span class="slms-chip"><?php echo esc_html( sprintf( __( '%s%% weight', 'simple-lms' ), number_format_i18n( $category['weight_percent'], 2 ) ) ); ?></span>
							</div>
						</div>
					</div>
					<p class="slms-empty-copy"><?php echo esc_html( ucfirst( str_replace( '_', ' ', $category['scoring_method'] ) ) ); ?></p>
					<?php if ( ! empty( $category['items'] ) ) : ?>
						<ul class="slms-plain-list">
							<?php foreach ( $category['items'] as $item ) : ?>
								<li>
									<strong><?php echo esc_html( $item['title'] ); ?></strong>
									<span class="slms-subtle-text"><?php echo esc_html( sprintf( __( '%1$s%% | %2$s pts', 'simple-lms' ), number_format_i18n( $item['weight_percent'], 2 ), number_format_i18n( $item['max_points'], 2 ) ) ); ?></span>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php else : ?>
						<p class="slms-empty-copy"><?php esc_html_e( 'No items have been defined inside this category.', 'simple-lms' ); ?></p>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}
}
