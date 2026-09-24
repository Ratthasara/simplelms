<?php

namespace SimpleLMS\Infrastructure\Admin;

use SimpleLMS\Domain\Academic\EnrollmentService;
use SimpleLMS\Domain\Assessment\GradebookService;
use SimpleLMS\Domain\Assessment\ResultsService;
use SimpleLMS\Domain\Users\RoleManager;

defined( 'ABSPATH' ) || exit;

class GradebookAdmin {
	/**
	 * @var GradebookService
	 */
	private $gradebook;

	/**
	 * @var EnrollmentService
	 */
	private $enrollments;

	/**
	 * @var ResultsService
	 */
	private $results;

	public function __construct( GradebookService $gradebook, EnrollmentService $enrollments, ResultsService $results ) {
		$this->gradebook   = $gradebook;
		$this->enrollments = $enrollments;
		$this->results     = $results;
	}

	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_slms_save_grade_category', array( $this, 'handle_save_category' ) );
		add_action( 'admin_post_slms_delete_grade_category', array( $this, 'handle_delete_category' ) );
		add_action( 'admin_post_slms_save_grade_item', array( $this, 'handle_save_item' ) );
		add_action( 'admin_post_slms_delete_grade_item', array( $this, 'handle_delete_item' ) );
		add_action( 'admin_post_slms_save_grade_score', array( $this, 'handle_save_score' ) );
		add_action( 'admin_post_slms_sync_assignment_items', array( $this, 'handle_sync_assignment_items' ) );
	}

	public function register_menu() {
		$role = RoleManager::get_primary_role();

		if ( ! in_array( $role, array( 'administrator', 'officer', 'lecturer' ), true ) ) {
			return;
		}

		add_submenu_page(
			'slms-dashboard',
			__( 'Gradebook', 'simple-lms' ),
			__( 'Gradebook', 'simple-lms' ),
			RoleManager::CAP_RECEIVE_NOTIFICATIONS,
			'slms-gradebook',
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

		if ( ! $section_id && ! empty( $sections ) ) {
			$section_id = (int) $sections[0]['id'];
		}

		$gradebook = $section_id ? $this->gradebook->get_gradebook( $section_id, $user_id ) : null;
		?>
		<div class="wrap slms-admin-page slms-gradebook-page">
			<div class="slms-page-header">
				<div>
					<p class="slms-page-kicker"><?php esc_html_e( 'Phase 5', 'simple-lms' ); ?></p>
					<h1 class="slms-page-title"><?php esc_html_e( 'Weighted Gradebook', 'simple-lms' ); ?></h1>
					<p class="slms-page-description"><?php esc_html_e( 'Design the grading map for each section, link assignment results automatically, and let lecturers control attendance, participation, presentations, and other manual components from one academic workspace.', 'simple-lms' ); ?></p>
				</div>
				<div class="slms-page-actions">
					<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=slms-attendance' ) ); ?>"><?php esc_html_e( 'Open Attendance Center', 'simple-lms' ); ?></a>
					<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=slms-assessments' ) ); ?>"><?php esc_html_e( 'Open Assessment Center', 'simple-lms' ); ?></a>
					<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=slms-workspace' ) ); ?>"><?php esc_html_e( 'Open Workspace', 'simple-lms' ); ?></a>
				</div>
			</div>

			<?php $this->render_notice(); ?>

			<div class="slms-surface slms-surface-tight">
				<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="slms-inline-form">
					<input type="hidden" name="page" value="slms-gradebook">
					<label for="slms_gradebook_section_id" class="slms-field-label"><?php esc_html_e( 'Section', 'simple-lms' ); ?></label>
					<select id="slms_gradebook_section_id" name="section_id" class="regular-text">
						<?php foreach ( $sections as $section ) : ?>
							<option value="<?php echo esc_attr( $section['id'] ); ?>" <?php selected( $section_id, (int) $section['id'] ); ?>>
								<?php echo esc_html( $section['title'] . ( $section['subject_title'] ? ' - ' . $section['subject_title'] : '' ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<?php submit_button( __( 'Open Gradebook', 'simple-lms' ), 'secondary', 'submit', false ); ?>
				</form>
			</div>

			<?php if ( empty( $sections ) ) : ?>
				<div class="slms-empty-state">
					<h2><?php esc_html_e( 'No sections are ready for grading yet.', 'simple-lms' ); ?></h2>
					<p><?php esc_html_e( 'Assign a lecturer to a section or create a section first, then return here to design its grading structure.', 'simple-lms' ); ?></p>
				</div>
				<?php
				return;
			endif;
			?>

			<?php if ( is_wp_error( $gradebook ) ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $gradebook->get_error_message() ); ?></p></div>
				<?php
				return;
			endif;
			?>

			<?php $this->render_summary_cards( $gradebook ); ?>
			<?php $this->render_weight_notice( $gradebook ); ?>

			<div class="slms-grid-main">
				<div class="slms-column-primary">
					<div class="slms-surface">
						<h2><?php esc_html_e( 'Grading Structure', 'simple-lms' ); ?></h2>
						<p><?php esc_html_e( 'Start with broad categories like Attendance, Assignments, Presentation, and Exam. Then add the grade items inside each category and set the weights you want the LMS to calculate.', 'simple-lms' ); ?></p>
						<?php $this->render_new_category_form( $section_id ); ?>
					</div>

					<?php foreach ( $gradebook['categories'] as $category ) : ?>
						<?php $this->render_category_card( $section_id, $category, $gradebook['assignment_options'] ); ?>
					<?php endforeach; ?>
				</div>

				<div class="slms-column-secondary">
					<div class="slms-surface">
						<h2><?php esc_html_e( 'Student Totals', 'simple-lms' ); ?></h2>
						<p><?php esc_html_e( 'Totals update automatically from the configured weights. Assignment-linked items read their scores from submissions, while manual items are entered here by the lecturer.', 'simple-lms' ); ?></p>
						<?php $this->render_roster_table( $section_id, $gradebook ); ?>
					</div>

					<div class="slms-surface">
						<h2><?php esc_html_e( 'Manual Scoring', 'simple-lms' ); ?></h2>
						<p><?php esc_html_e( 'Use these forms for participation, presentations, viva, practical work, or any custom item that is not already coming from the Assessment Center. Attendance rollup categories update automatically from the Attendance Center.', 'simple-lms' ); ?></p>
						<?php $this->render_manual_scoring( $section_id, $gradebook ); ?>
					</div>

					<div class="slms-surface">
						<h2><?php esc_html_e( 'Assessment-linked Items', 'simple-lms' ); ?></h2>
						<p><?php esc_html_e( 'These items pull scores from graded assignment submissions. You can still save a manual override for any student if you need to adjust a score.', 'simple-lms' ); ?></p>
						<?php $this->render_assignment_items_panel( $gradebook ); ?>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	public function handle_save_category() {
		$this->assert_can_access();
		check_admin_referer( 'slms_save_grade_category' );

		$section_id = absint( $_POST['section_id'] ?? 0 );
		$result     = $this->gradebook->save_category( $section_id, wp_unslash( $_POST ), get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			$this->redirect_to_section( $section_id, '', $result->get_error_message() );
		}

		$this->redirect_to_section( $section_id, 'grade_category_saved' );
	}

	public function handle_delete_category() {
		$this->assert_can_access();

		$section_id  = absint( $_POST['section_id'] ?? 0 );
		$category_id = absint( $_POST['category_id'] ?? 0 );

		check_admin_referer( 'slms_delete_grade_category_' . $category_id );

		$result = $this->gradebook->delete_category( $category_id, get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			$this->redirect_to_section( $section_id, '', $result->get_error_message() );
		}

		$this->redirect_to_section( $section_id, 'grade_category_deleted' );
	}

	public function handle_save_item() {
		$this->assert_can_access();
		check_admin_referer( 'slms_save_grade_item' );

		$section_id = absint( $_POST['section_id'] ?? 0 );
		$result     = $this->gradebook->save_item( $section_id, wp_unslash( $_POST ), get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			$this->redirect_to_section( $section_id, '', $result->get_error_message() );
		}

		$this->redirect_to_section( $section_id, 'grade_item_saved' );
	}

	public function handle_delete_item() {
		$this->assert_can_access();

		$section_id = absint( $_POST['section_id'] ?? 0 );
		$item_id    = absint( $_POST['item_id'] ?? 0 );

		check_admin_referer( 'slms_delete_grade_item_' . $item_id );

		$result = $this->gradebook->delete_item( $item_id, get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			$this->redirect_to_section( $section_id, '', $result->get_error_message() );
		}

		$this->redirect_to_section( $section_id, 'grade_item_deleted' );
	}

	public function handle_save_score() {
		$this->assert_can_access();
		check_admin_referer( 'slms_save_grade_score' );

		$section_id      = absint( $_POST['section_id'] ?? 0 );
		$item_id         = absint( $_POST['item_id'] ?? 0 );
		$student_user_id = absint( $_POST['student_user_id'] ?? 0 );
		$result          = $this->gradebook->save_score(
			$item_id,
			$student_user_id,
			array(
				'score'    => wp_unslash( $_POST['score'] ?? '' ),
				'feedback' => wp_unslash( $_POST['feedback'] ?? '' ),
			),
			get_current_user_id()
		);

		if ( is_wp_error( $result ) ) {
			$this->redirect_to_section( $section_id, '', $result->get_error_message() );
		}

		$this->redirect_to_section( $section_id, 'grade_score_saved' );
	}

	public function handle_sync_assignment_items() {
		$this->assert_can_access();
		check_admin_referer( 'slms_sync_assignment_items' );

		$section_id  = absint( $_POST['section_id'] ?? 0 );
		$category_id = absint( $_POST['category_id'] ?? 0 );
		$result      = $this->gradebook->sync_assignment_items( $section_id, $category_id, get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			$this->redirect_to_section( $section_id, '', $result->get_error_message() );
		}

		$this->redirect_to_section( $section_id, 0 === (int) $result ? 'grade_items_already_synced' : 'grade_items_synced' );
	}

	private function render_summary_cards( array $gradebook ) {
		$item_count        = 0;
		$assignment_count  = count( $gradebook['assignment_items'] );

		foreach ( $gradebook['categories'] as $category ) {
			$item_count += count( $category['items'] );
		}
		?>
		<div class="slms-card-grid">
			<div class="slms-stat-card">
				<p class="slms-stat-label"><?php esc_html_e( 'Configured Weight', 'simple-lms' ); ?></p>
				<p class="slms-stat-value"><span data-slms-weight-total data-slms-weight-scope="section"><?php echo esc_html( number_format_i18n( $gradebook['weight_total'], 2 ) ); ?></span>%</p>
			</div>
			<div class="slms-stat-card">
				<p class="slms-stat-label"><?php esc_html_e( 'Categories', 'simple-lms' ); ?></p>
				<p class="slms-stat-value"><?php echo esc_html( (string) count( $gradebook['categories'] ) ); ?></p>
			</div>
			<div class="slms-stat-card">
				<p class="slms-stat-label"><?php esc_html_e( 'Grade Items', 'simple-lms' ); ?></p>
				<p class="slms-stat-value"><?php echo esc_html( (string) $item_count ); ?></p>
			</div>
			<div class="slms-stat-card">
				<p class="slms-stat-label"><?php esc_html_e( 'Students', 'simple-lms' ); ?></p>
				<p class="slms-stat-value"><?php echo esc_html( (string) count( $gradebook['students'] ) ); ?></p>
			</div>
			<div class="slms-stat-card">
				<p class="slms-stat-label"><?php esc_html_e( 'Linked Assignments', 'simple-lms' ); ?></p>
				<p class="slms-stat-value"><?php echo esc_html( (string) $assignment_count ); ?></p>
			</div>
		</div>
		<?php
	}

	private function render_weight_notice( array $gradebook ) {
		if ( $gradebook['is_weight_balanced'] ) {
			?>
			<div class="slms-weight-banner is-balanced">
				<strong><?php esc_html_e( 'Weighting is balanced.', 'simple-lms' ); ?></strong>
				<span><?php esc_html_e( 'Your section currently adds up to 100%, so the final totals can be treated as official percentages.', 'simple-lms' ); ?></span>
			</div>
			<?php
			return;
		}

		$difference = 100 - (float) $gradebook['weight_total'];
		?>
		<div class="slms-weight-banner is-warning">
			<strong><?php esc_html_e( 'Weighting still needs adjustment.', 'simple-lms' ); ?></strong>
			<span>
				<?php
				echo esc_html(
					$difference > 0
						? sprintf( __( 'Add %s%% more category weight to reach 100%%.', 'simple-lms' ), number_format_i18n( $difference, 2 ) )
						: sprintf( __( 'Reduce category weight by %s%% to return to 100%%.', 'simple-lms' ), number_format_i18n( abs( $difference ), 2 ) )
				);
				?>
			</span>
		</div>
		<?php
	}

	private function render_new_category_form( $section_id ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="slms-stack">
			<input type="hidden" name="action" value="slms_save_grade_category">
			<input type="hidden" name="section_id" value="<?php echo esc_attr( $section_id ); ?>">
			<?php wp_nonce_field( 'slms_save_grade_category' ); ?>
			<div class="slms-form-grid">
				<div>
					<label class="slms-field-label" for="slms_new_category_title"><?php esc_html_e( 'Category Title', 'simple-lms' ); ?></label>
					<input id="slms_new_category_title" type="text" name="title" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Assignments', 'simple-lms' ); ?>" required>
				</div>
				<div>
					<label class="slms-field-label" for="slms_new_category_type"><?php esc_html_e( 'Type', 'simple-lms' ); ?></label>
					<select id="slms_new_category_type" name="category_type">
						<?php foreach ( GradebookService::CATEGORY_TYPES as $type ) : ?>
							<option value="<?php echo esc_attr( $type ); ?>"><?php echo esc_html( ucfirst( $type ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div>
					<label class="slms-field-label" for="slms_new_category_weight"><?php esc_html_e( 'Weight %', 'simple-lms' ); ?></label>
					<input id="slms_new_category_weight" data-slms-weight-input data-slms-weight-scope="section" type="number" min="0" max="100" step="0.5" name="weight_percent" value="0">
				</div>
				<div>
					<label class="slms-field-label" for="slms_new_category_method"><?php esc_html_e( 'Calculation', 'simple-lms' ); ?></label>
					<select id="slms_new_category_method" name="scoring_method">
						<?php foreach ( GradebookService::SCORING_METHODS as $method ) : ?>
							<option value="<?php echo esc_attr( $method ); ?>"><?php echo esc_html( ucfirst( str_replace( '_', ' ', $method ) ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
			</div>
			<label><input type="checkbox" name="publish_to_students" value="1"> <?php esc_html_e( 'Students can see this category when grade publishing is enabled later.', 'simple-lms' ); ?></label>
			<?php submit_button( __( 'Add Category', 'simple-lms' ), 'primary', 'submit', false ); ?>
		</form>
		<?php
	}

	private function render_category_card( $section_id, array $category, array $assignment_options ) {
		$scope = 'category-' . $category['id'];
		?>
		<div class="slms-surface slms-category-card">
			<div class="slms-surface-header">
				<div>
					<h3><?php echo esc_html( $category['title'] ); ?></h3>
					<div class="slms-chip-row">
						<span class="slms-chip"><?php echo esc_html( ucfirst( $category['category_type'] ) ); ?></span>
						<span class="slms-chip"><?php echo esc_html( sprintf( __( '%s%% of final grade', 'simple-lms' ), number_format_i18n( $category['weight_percent'], 2 ) ) ); ?></span>
						<span class="slms-chip"><?php echo esc_html( sprintf( __( '%s%% item weight assigned', 'simple-lms' ), number_format_i18n( $category['item_weight_total'], 2 ) ) ); ?></span>
					</div>
				</div>
				<div class="slms-page-actions">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="slms_delete_grade_category">
						<input type="hidden" name="section_id" value="<?php echo esc_attr( $section_id ); ?>">
						<input type="hidden" name="category_id" value="<?php echo esc_attr( $category['id'] ); ?>">
						<?php wp_nonce_field( 'slms_delete_grade_category_' . (int) $category['id'] ); ?>
						<button type="submit" class="button button-link-delete" onclick="return confirm('<?php echo esc_js( __( 'Delete this category and its items?', 'simple-lms' ) ); ?>');"><?php esc_html_e( 'Delete Category', 'simple-lms' ); ?></button>
					</form>
				</div>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="slms-stack">
				<input type="hidden" name="action" value="slms_save_grade_category">
				<input type="hidden" name="section_id" value="<?php echo esc_attr( $section_id ); ?>">
				<input type="hidden" name="category_id" value="<?php echo esc_attr( $category['id'] ); ?>">
				<?php wp_nonce_field( 'slms_save_grade_category' ); ?>
				<div class="slms-form-grid">
					<div>
						<label class="slms-field-label"><?php esc_html_e( 'Title', 'simple-lms' ); ?></label>
						<input type="text" name="title" value="<?php echo esc_attr( $category['title'] ); ?>" class="regular-text">
					</div>
					<div>
						<label class="slms-field-label"><?php esc_html_e( 'Type', 'simple-lms' ); ?></label>
						<select name="category_type">
							<?php foreach ( GradebookService::CATEGORY_TYPES as $type ) : ?>
								<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $category['category_type'], $type ); ?>><?php echo esc_html( ucfirst( $type ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div>
						<label class="slms-field-label"><?php esc_html_e( 'Weight %', 'simple-lms' ); ?></label>
						<input type="number" data-slms-weight-input data-slms-weight-scope="section" min="0" max="100" step="0.5" name="weight_percent" value="<?php echo esc_attr( $category['weight_percent'] ); ?>">
					</div>
					<div>
						<label class="slms-field-label"><?php esc_html_e( 'Calculation', 'simple-lms' ); ?></label>
						<select name="scoring_method">
							<?php foreach ( GradebookService::SCORING_METHODS as $method ) : ?>
								<option value="<?php echo esc_attr( $method ); ?>" <?php selected( $category['scoring_method'], $method ); ?>><?php echo esc_html( ucfirst( str_replace( '_', ' ', $method ) ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div>
						<label class="slms-field-label"><?php esc_html_e( 'Sort Order', 'simple-lms' ); ?></label>
						<input type="number" name="sort_order" value="<?php echo esc_attr( $category['sort_order'] ); ?>">
					</div>
				</div>
				<label><input type="checkbox" name="publish_to_students" value="1" <?php checked( $category['publish_to_students'], 1 ); ?>> <?php esc_html_e( 'Visible to students when published later', 'simple-lms' ); ?></label>
				<div class="slms-inline-actions">
					<?php submit_button( __( 'Update Category', 'simple-lms' ), 'secondary', 'submit', false ); ?>
				</div>
			</form>
			<?php if ( 'attendance_rollup' === $category['scoring_method'] ) : ?>
				<div class="slms-weight-banner is-balanced">
					<strong><?php esc_html_e( 'Attendance is rolling in automatically.', 'simple-lms' ); ?></strong>
					<span><?php esc_html_e( 'This category reads from the Attendance Center for the section, so lecturers can manage real class meetings instead of re-entering attendance scores manually.', 'simple-lms' ); ?></span>
				</div>
			<?php endif; ?>
			<?php if ( 'assignment' === $category['category_type'] ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="slms-inline-form">
					<input type="hidden" name="action" value="slms_sync_assignment_items">
					<input type="hidden" name="section_id" value="<?php echo esc_attr( $section_id ); ?>">
					<input type="hidden" name="category_id" value="<?php echo esc_attr( $category['id'] ); ?>">
					<?php wp_nonce_field( 'slms_sync_assignment_items' ); ?>
					<button type="submit" class="button button-secondary"><?php esc_html_e( 'Sync Section Assignments', 'simple-lms' ); ?></button>
				</form>
			<?php endif; ?>

			<div class="slms-subsection">
				<div class="slms-subsection-header">
					<h4><?php esc_html_e( 'Items', 'simple-lms' ); ?></h4>
					<span class="slms-subtle-text"><?php echo esc_html( sprintf( __( 'Current item weight: %s%%', 'simple-lms' ), number_format_i18n( $category['item_weight_total'], 2 ) ) ); ?></span>
					<span data-slms-weight-total data-slms-weight-scope="<?php echo esc_attr( $scope ); ?>" style="display:none;"><?php echo esc_html( number_format_i18n( $category['item_weight_total'], 2 ) ); ?></span>
				</div>

				<?php if ( empty( $category['items'] ) ) : ?>
					<p class="slms-empty-copy"><?php esc_html_e( 'No items yet. Add the actual pieces of work that should feed this category.', 'simple-lms' ); ?></p>
				<?php else : ?>
					<?php foreach ( $category['items'] as $item ) : ?>
						<?php $this->render_item_card( $section_id, $category, $item, $assignment_options, $scope ); ?>
					<?php endforeach; ?>
				<?php endif; ?>

				<details class="slms-disclosure">
					<summary><?php esc_html_e( 'Add Item', 'simple-lms' ); ?></summary>
					<div class="slms-disclosure-body">
						<?php $this->render_new_item_form( $section_id, $category, $assignment_options, $scope ); ?>
					</div>
				</details>
			</div>
		</div>
		<?php
	}

	private function render_item_card( $section_id, array $category, array $item, array $assignment_options, $scope ) {
		?>
		<div class="slms-item-card">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="slms-stack">
				<input type="hidden" name="action" value="slms_save_grade_item">
				<input type="hidden" name="section_id" value="<?php echo esc_attr( $section_id ); ?>">
				<input type="hidden" name="category_id" value="<?php echo esc_attr( $category['id'] ); ?>">
				<input type="hidden" name="item_id" value="<?php echo esc_attr( $item['id'] ); ?>">
				<?php wp_nonce_field( 'slms_save_grade_item' ); ?>
				<div class="slms-form-grid">
					<div>
						<label class="slms-field-label"><?php esc_html_e( 'Title', 'simple-lms' ); ?></label>
						<input type="text" name="title" value="<?php echo esc_attr( $item['title'] ); ?>" class="regular-text">
					</div>
					<div>
						<label class="slms-field-label"><?php esc_html_e( 'Type', 'simple-lms' ); ?></label>
						<select name="item_type">
							<?php foreach ( GradebookService::ITEM_TYPES as $type ) : ?>
								<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $item['item_type'], $type ); ?>><?php echo esc_html( ucfirst( $type ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div>
						<label class="slms-field-label"><?php esc_html_e( 'Weight %', 'simple-lms' ); ?></label>
						<input type="number" data-slms-weight-input data-slms-weight-scope="<?php echo esc_attr( $scope ); ?>" min="0" max="100" step="0.5" name="weight_percent" value="<?php echo esc_attr( $item['weight_percent'] ); ?>">
					</div>
					<div>
						<label class="slms-field-label"><?php esc_html_e( 'Max Points', 'simple-lms' ); ?></label>
						<input type="number" min="0" step="0.5" name="max_points" value="<?php echo esc_attr( $item['max_points'] ); ?>">
					</div>
					<div>
						<label class="slms-field-label"><?php esc_html_e( 'Assignment Link', 'simple-lms' ); ?></label>
						<select name="linked_post_id">
							<option value=""><?php esc_html_e( 'Manual item', 'simple-lms' ); ?></option>
							<?php foreach ( $assignment_options as $assignment ) : ?>
								<option value="<?php echo esc_attr( $assignment['id'] ); ?>" <?php selected( $item['linked_post_id'], (int) $assignment['id'] ); ?>>
									<?php echo esc_html( $assignment['title'] . ' (' . ( $assignment['points'] ?: 0 ) . ' pts)' ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>
					<div>
						<label class="slms-field-label"><?php esc_html_e( 'Sort Order', 'simple-lms' ); ?></label>
						<input type="number" name="sort_order" value="<?php echo esc_attr( $item['sort_order'] ); ?>">
					</div>
				</div>
				<label><input type="checkbox" name="publish_to_students" value="1" <?php checked( $item['publish_to_students'], 1 ); ?>> <?php esc_html_e( 'Visible to students when publishing is enabled', 'simple-lms' ); ?></label>
				<div class="slms-inline-actions">
					<?php submit_button( __( 'Update Item', 'simple-lms' ), 'secondary', 'submit', false ); ?>
					<?php if ( ! empty( $item['linked_post_id'] ) ) : ?>
						<a class="button button-secondary" href="<?php echo esc_url( add_query_arg( array( 'page' => 'slms-assessments', 'assignment_id' => $item['linked_post_id'] ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Open Assignment', 'simple-lms' ); ?></a>
					<?php endif; ?>
				</div>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="slms_delete_grade_item">
				<input type="hidden" name="section_id" value="<?php echo esc_attr( $section_id ); ?>">
				<input type="hidden" name="item_id" value="<?php echo esc_attr( $item['id'] ); ?>">
				<?php wp_nonce_field( 'slms_delete_grade_item_' . (int) $item['id'] ); ?>
				<button type="submit" class="button button-link-delete" onclick="return confirm('<?php echo esc_js( __( 'Delete this grade item?', 'simple-lms' ) ); ?>');"><?php esc_html_e( 'Delete Item', 'simple-lms' ); ?></button>
			</form>
		</div>
		<?php
	}

	private function render_new_item_form( $section_id, array $category, array $assignment_options, $scope ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="slms-stack">
			<input type="hidden" name="action" value="slms_save_grade_item">
			<input type="hidden" name="section_id" value="<?php echo esc_attr( $section_id ); ?>">
			<input type="hidden" name="category_id" value="<?php echo esc_attr( $category['id'] ); ?>">
			<?php wp_nonce_field( 'slms_save_grade_item' ); ?>
			<div class="slms-form-grid">
				<div>
					<label class="slms-field-label"><?php esc_html_e( 'Title', 'simple-lms' ); ?></label>
					<input type="text" name="title" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Midterm Presentation', 'simple-lms' ); ?>">
				</div>
				<div>
					<label class="slms-field-label"><?php esc_html_e( 'Type', 'simple-lms' ); ?></label>
					<select name="item_type">
						<?php foreach ( GradebookService::ITEM_TYPES as $type ) : ?>
							<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $category['category_type'], $type ); ?>><?php echo esc_html( ucfirst( $type ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div>
					<label class="slms-field-label"><?php esc_html_e( 'Weight %', 'simple-lms' ); ?></label>
					<input type="number" data-slms-weight-input data-slms-weight-scope="<?php echo esc_attr( $scope ); ?>" min="0" max="100" step="0.5" name="weight_percent" value="0">
				</div>
				<div>
					<label class="slms-field-label"><?php esc_html_e( 'Max Points', 'simple-lms' ); ?></label>
					<input type="number" min="0" step="0.5" name="max_points" value="100">
				</div>
				<div>
					<label class="slms-field-label"><?php esc_html_e( 'Assignment Link', 'simple-lms' ); ?></label>
					<select name="linked_post_id">
						<option value=""><?php esc_html_e( 'Manual item', 'simple-lms' ); ?></option>
						<?php foreach ( $assignment_options as $assignment ) : ?>
							<option value="<?php echo esc_attr( $assignment['id'] ); ?>"><?php echo esc_html( $assignment['title'] . ' (' . ( $assignment['points'] ?: 0 ) . ' pts)' ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div>
					<label class="slms-field-label"><?php esc_html_e( 'Publish', 'simple-lms' ); ?></label>
					<label><input type="checkbox" name="publish_to_students" value="1"> <?php esc_html_e( 'Visible later', 'simple-lms' ); ?></label>
				</div>
			</div>
			<?php submit_button( __( 'Add Item', 'simple-lms' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}

	private function render_roster_table( $section_id, array $gradebook ) {
		if ( empty( $gradebook['students'] ) ) {
			echo '<p class="slms-empty-copy">' . esc_html__( 'No students are enrolled in this section yet.', 'simple-lms' ) . '</p>';
			return;
		}

		$student_ids = array_map(
			static function ( $student ) {
				return (int) ( $student['student_user_id'] ?? 0 );
			},
			$gradebook['students']
		);
		$snapshots = $this->results->get_section_student_snapshot_map( get_current_user_id(), $section_id, $student_ids );
		?>
		<div class="slms-table-wrap">
			<table class="widefat striped slms-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Student', 'simple-lms' ); ?></th>
						<?php foreach ( $gradebook['categories'] as $category ) : ?>
							<th><?php echo esc_html( $category['title'] ); ?></th>
						<?php endforeach; ?>
						<th><?php esc_html_e( 'Weighted Total', 'simple-lms' ); ?></th>
						<th><?php esc_html_e( 'Total Score', 'simple-lms' ); ?></th>
						<th><?php esc_html_e( 'Grade', 'simple-lms' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $gradebook['students'] as $student ) : ?>
						<?php $snapshot = $snapshots[ (int) ( $student['student_user_id'] ?? 0 ) ] ?? array(); ?>
						<tr>
							<td>
								<strong><?php echo esc_html( $student['student_name'] ); ?></strong><br>
								<span class="slms-subtle-text"><?php echo esc_html( $student['student_email'] ); ?></span>
							</td>
							<?php foreach ( $gradebook['categories'] as $category ) : ?>
								<?php $score = $student['category_scores'][ $category['id'] ] ?? array( 'percentage' => 0, 'weighted_points' => 0 ); ?>
								<td>
									<strong><?php echo esc_html( number_format_i18n( $score['percentage'], 2 ) ); ?>%</strong><br>
									<span class="slms-subtle-text"><?php echo esc_html( number_format_i18n( $score['weighted_points'], 2 ) ); ?></span>
								</td>
							<?php endforeach; ?>
							<td><?php echo esc_html( number_format_i18n( $student['weighted_total'], 2 ) ); ?></td>
							<td><strong><?php echo ! empty( $snapshot['can_show_total'] ) ? esc_html( number_format_i18n( (float) ( $snapshot['total_score'] ?? 0 ), 2 ) ) : '-'; ?></strong></td>
							<td><strong><?php echo ! empty( $snapshot['can_show_grade'] ) ? esc_html( (string) ( $snapshot['letter_grade'] ?? '' ) ) : '-'; ?></strong></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private function render_manual_scoring( $section_id, array $gradebook ) {
		if ( empty( $gradebook['manual_items'] ) ) {
			echo '<p class="slms-empty-copy">' . esc_html__( 'All current items are coming from linked assignments, so there are no manual scoring forms yet.', 'simple-lms' ) . '</p>';
			return;
		}

		foreach ( $gradebook['manual_items'] as $item ) {
			?>
			<details class="slms-disclosure">
				<summary><?php echo esc_html( $item['title'] . ' (' . number_format_i18n( $item['max_points'], 2 ) . ' pts)' ); ?></summary>
				<div class="slms-disclosure-body">
					<div class="slms-table-wrap">
						<table class="widefat striped slms-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Student', 'simple-lms' ); ?></th>
									<th><?php esc_html_e( 'Score', 'simple-lms' ); ?></th>
									<th><?php esc_html_e( 'Feedback', 'simple-lms' ); ?></th>
									<th><?php esc_html_e( 'Save', 'simple-lms' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $gradebook['students'] as $student ) : ?>
									<?php $current = $student['item_scores'][ $item['id'] ] ?? array( 'score' => null, 'feedback' => '' ); ?>
									<?php $form_id = 'slms-score-' . (int) $item['id'] . '-' . (int) $student['student_user_id']; ?>
									<tr>
										<td>
											<strong><?php echo esc_html( $student['student_name'] ); ?></strong><br>
											<span class="slms-subtle-text"><?php echo esc_html( $student['enrollment_status'] ); ?></span>
										</td>
										<td><input type="number" min="0" max="<?php echo esc_attr( $item['max_points'] ); ?>" step="0.5" name="score" value="<?php echo esc_attr( null !== $current['score'] ? $current['score'] : '' ); ?>" form="<?php echo esc_attr( $form_id ); ?>"></td>
										<td><textarea name="feedback" rows="2" class="large-text" form="<?php echo esc_attr( $form_id ); ?>"><?php echo esc_textarea( $current['feedback'] ); ?></textarea></td>
										<td>
											<form id="<?php echo esc_attr( $form_id ); ?>" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
												<input type="hidden" name="action" value="slms_save_grade_score">
												<input type="hidden" name="section_id" value="<?php echo esc_attr( $section_id ); ?>">
												<input type="hidden" name="item_id" value="<?php echo esc_attr( $item['id'] ); ?>">
												<input type="hidden" name="student_user_id" value="<?php echo esc_attr( $student['student_user_id'] ); ?>">
												<?php wp_nonce_field( 'slms_save_grade_score' ); ?>
											</form>
											<?php submit_button( __( 'Save', 'simple-lms' ), 'secondary', 'submit', false, array( 'form' => $form_id ) ); ?>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</div>
			</details>
			<?php
		}
	}

	private function render_assignment_items_panel( array $gradebook ) {
		if ( empty( $gradebook['assignment_items'] ) ) {
			echo '<p class="slms-empty-copy">' . esc_html__( 'No grade items are linked to assignments yet. Add an Assignments category and sync the section assignments into it.', 'simple-lms' ) . '</p>';
			return;
		}

		foreach ( $gradebook['assignment_items'] as $item ) {
			$url = add_query_arg(
				array(
					'page'          => 'slms-assessments',
					'assignment_id' => $item['linked_post_id'],
				),
				admin_url( 'admin.php' )
			);
			?>
			<div class="slms-inline-panel">
				<div>
					<strong><?php echo esc_html( $item['title'] ); ?></strong><br>
					<span class="slms-subtle-text"><?php echo esc_html( sprintf( __( '%1$s pts | %2$s%% category weight', 'simple-lms' ), number_format_i18n( $item['max_points'], 2 ), number_format_i18n( $item['weight_percent'], 2 ) ) ); ?></span>
				</div>
				<div class="slms-page-actions">
					<a class="button button-secondary" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Open Assessment', 'simple-lms' ); ?></a>
				</div>
			</div>
			<?php
		}
	}

	private function can_access_page() {
		return current_user_can( RoleManager::CAP_MANAGE_GRADEBOOK ) || current_user_can( RoleManager::CAP_MANAGE_ACADEMICS );
	}

	private function assert_can_access() {
		if ( ! $this->can_access_page() ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'simple-lms' ), 403 );
		}
	}

	private function redirect_to_section( $section_id, $notice = '', $error = '' ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => 'slms-gradebook',
					'section_id'  => $section_id ?: false,
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
			'grade_category_saved'       => __( 'Grading category saved.', 'simple-lms' ),
			'grade_category_deleted'     => __( 'Grading category deleted.', 'simple-lms' ),
			'grade_item_saved'           => __( 'Grade item saved.', 'simple-lms' ),
			'grade_item_deleted'         => __( 'Grade item deleted.', 'simple-lms' ),
			'grade_score_saved'          => __( 'Score saved.', 'simple-lms' ),
			'grade_items_synced'         => __( 'Section assignments were linked into the gradebook.', 'simple-lms' ),
			'grade_items_already_synced' => __( 'All current section assignments are already linked.', 'simple-lms' ),
		);

		if ( empty( $map[ $notice ] ) ) {
			return;
		}
		?>
		<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $map[ $notice ] ); ?></p></div>
		<?php
	}
}
