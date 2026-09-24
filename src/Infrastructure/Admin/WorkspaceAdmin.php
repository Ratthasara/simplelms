<?php

namespace SimpleLMS\Infrastructure\Admin;

use SimpleLMS\Domain\Learning\WorkspaceService;
use SimpleLMS\Domain\Settings\SettingsManager;
use SimpleLMS\Domain\Users\RoleManager;

defined( 'ABSPATH' ) || exit;

class WorkspaceAdmin {
	/**
	 * @var WorkspaceService
	 */
	private $workspace;

	/**
	 * @var SettingsManager
	 */
	private $settings;

	public function __construct( WorkspaceService $workspace, SettingsManager $settings ) {
		$this->workspace = $workspace;
		$this->settings  = $settings;
	}

	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_filter( 'login_redirect', array( $this, 'handle_login_redirect' ), 10, 3 );
	}

	public function register_menu() {
		add_submenu_page(
			'slms-dashboard',
			__( 'Workspace', 'simple-lms' ),
			__( 'Workspace', 'simple-lms' ),
			RoleManager::CAP_RECEIVE_NOTIFICATIONS,
			'slms-workspace',
			array( $this, 'render_workspace_page' )
		);
	}

	public function render_workspace_page() {
		if ( ! current_user_can( RoleManager::CAP_RECEIVE_NOTIFICATIONS ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'simple-lms' ), 403 );
		}

		$user_id      = get_current_user_id();
		$snapshot     = $this->workspace->get_dashboard_snapshot( $user_id );
		$section_id   = absint( $_GET['section_id'] ?? 0 );
		$content_id   = absint( $_GET['content_id'] ?? 0 );
		$can_access_assessments = $this->can_access_assessment_center();
		$section_view = $section_id ? $this->workspace->get_section_workspace( $user_id, $section_id ) : null;
		$content      = $content_id ? $this->workspace->get_content_item( $user_id, $content_id ) : null;
		?>
		<div class="wrap slms-admin-page slms-workspace-page">
			<div class="slms-page-header">
				<div>
					<p class="slms-page-kicker"><?php esc_html_e( 'Personal Hub', 'simple-lms' ); ?></p>
					<h1 class="slms-page-title"><?php esc_html_e( 'My Workspace', 'simple-lms' ); ?></h1>
					<p class="slms-page-description"><?php esc_html_e( 'A role-aware workspace for teaching, learning, grading, communication, and day-to-day academic activity inside Simple LMS.', 'simple-lms' ); ?></p>
				</div>
				<div class="slms-page-actions">
					<?php if ( $can_access_assessments ) : ?>
						<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=slms-assessments' ) ); ?>"><?php esc_html_e( 'Assessment Center', 'simple-lms' ); ?></a>
					<?php endif; ?>
					<?php if ( current_user_can( RoleManager::CAP_MANAGE_GRADEBOOK ) || current_user_can( RoleManager::CAP_MANAGE_ACADEMICS ) ) : ?>
						<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=slms-gradebook' ) ); ?>"><?php esc_html_e( 'Gradebook', 'simple-lms' ); ?></a>
					<?php endif; ?>
					<?php if ( current_user_can( RoleManager::CAP_MANAGE_ATTENDANCE ) || current_user_can( RoleManager::CAP_MANAGE_ACADEMICS ) ) : ?>
						<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=slms-attendance' ) ); ?>"><?php esc_html_e( 'Attendance', 'simple-lms' ); ?></a>
					<?php endif; ?>
				</div>
			</div>

			<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin:20px 0;">
				<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;">
					<h2 style="margin:0 0 8px;"><?php esc_html_e( 'Role', 'simple-lms' ); ?></h2>
					<p style="font-size:20px;margin:0;"><?php echo esc_html( ucfirst( $snapshot['role'] ?: 'User' ) ); ?></p>
				</div>
				<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;">
					<h2 style="margin:0 0 8px;"><?php esc_html_e( 'Sections', 'simple-lms' ); ?></h2>
					<p style="font-size:20px;margin:0;"><?php echo esc_html( (string) $snapshot['section_count'] ); ?></p>
				</div>
				<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;">
					<h2 style="margin:0 0 8px;"><?php esc_html_e( 'Recent Lessons', 'simple-lms' ); ?></h2>
					<p style="font-size:20px;margin:0;"><?php echo esc_html( (string) count( $snapshot['recent_lessons'] ) ); ?></p>
				</div>
				<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;">
					<h2 style="margin:0 0 8px;"><?php esc_html_e( 'Announcements', 'simple-lms' ); ?></h2>
					<p style="font-size:20px;margin:0;"><?php echo esc_html( (string) count( $snapshot['recent_announcements'] ) ); ?></p>
				</div>
				<?php if ( $can_access_assessments ) : ?>
					<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;">
						<h2 style="margin:0 0 8px;"><?php esc_html_e( 'Assignments', 'simple-lms' ); ?></h2>
						<p style="font-size:20px;margin:0;"><?php echo esc_html( (string) count( $snapshot['recent_assignments'] ) ); ?></p>
					</div>
				<?php endif; ?>
			</div>

			<div style="display:grid;grid-template-columns:minmax(320px,420px) 1fr;gap:20px;align-items:start;">
				<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;">
					<h2 style="margin-top:0;"><?php esc_html_e( 'My Sections', 'simple-lms' ); ?></h2>
					<?php if ( empty( $snapshot['sections'] ) ) : ?>
						<p><?php esc_html_e( 'No sections are assigned to your workspace yet.', 'simple-lms' ); ?></p>
					<?php else : ?>
						<?php foreach ( $snapshot['sections'] as $section ) : ?>
							<div style="border:1px solid #dcdcde;border-radius:8px;padding:12px;margin-bottom:10px;">
								<strong><?php echo esc_html( $section['title'] ); ?></strong><br>
								<span style="color:#50575e;"><?php echo esc_html( $section['subject_title'] ?: $section['section_code'] ); ?></span><br>
								<span style="color:#50575e;"><?php echo esc_html( $section['term_name'] ?: __( 'No term assigned', 'simple-lms' ) ); ?></span><br>
							<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'slms-workspace', 'section_id' => (int) $section['id'] ), admin_url( 'admin.php' ) ) ); ?>" class="button button-secondary" style="margin-top:10px;"><?php esc_html_e( 'Open Section', 'simple-lms' ); ?></a>
						</div>
					<?php endforeach; ?>
					<?php endif; ?>

					<div style="display:flex;flex-wrap:wrap;gap:10px;margin-top:16px;">
						<?php if ( $can_access_assessments ) : ?>
							<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=slms-assessments' ) ); ?>"><?php esc_html_e( 'Open Assessment Center', 'simple-lms' ); ?></a>
						<?php endif; ?>
						<?php if ( current_user_can( 'create_slms_lessons' ) ) : ?>
							<a class="button button-primary" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=slms_lesson' ) ); ?>"><?php esc_html_e( 'Create Lesson', 'simple-lms' ); ?></a>
						<?php endif; ?>
						<?php if ( current_user_can( 'create_slms_assignments' ) ) : ?>
							<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=slms_assignment' ) ); ?>"><?php esc_html_e( 'Create Assignment', 'simple-lms' ); ?></a>
						<?php endif; ?>
						<?php if ( current_user_can( RoleManager::CAP_MANAGE_GRADEBOOK ) || current_user_can( RoleManager::CAP_MANAGE_ACADEMICS ) ) : ?>
							<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=slms-gradebook' ) ); ?>"><?php esc_html_e( 'Open Gradebook', 'simple-lms' ); ?></a>
						<?php endif; ?>
						<?php if ( current_user_can( RoleManager::CAP_MANAGE_ATTENDANCE ) || current_user_can( RoleManager::CAP_MANAGE_ACADEMICS ) ) : ?>
							<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=slms-attendance' ) ); ?>"><?php esc_html_e( 'Open Attendance Center', 'simple-lms' ); ?></a>
						<?php endif; ?>
						<?php if ( current_user_can( 'create_slms_announcements' ) ) : ?>
							<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=slms_announcement' ) ); ?>"><?php esc_html_e( 'Create Announcement', 'simple-lms' ); ?></a>
						<?php endif; ?>
						<?php if ( current_user_can( RoleManager::CAP_MANAGE_COMMUNICATIONS ) ) : ?>
							<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=slms-editor-tools' ) ); ?>"><?php esc_html_e( 'Open Editor Tools', 'simple-lms' ); ?></a>
						<?php endif; ?>
					</div>
				</div>

				<div>
					<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;margin-bottom:20px;">
						<h2 style="margin-top:0;"><?php echo $section_id ? esc_html__( 'Section Workspace', 'simple-lms' ) : esc_html__( 'Recent Activity', 'simple-lms' ); ?></h2>
						<?php if ( $section_id && is_wp_error( $section_view ) ) : ?>
							<p><?php echo esc_html( $section_view->get_error_message() ); ?></p>
						<?php elseif ( $section_id && ! empty( $section_view['section'] ) ) : ?>
							<p><strong><?php echo esc_html( $section_view['section']['title'] ); ?></strong> <?php echo esc_html( $section_view['section']['subject_title'] ? ' - ' . $section_view['section']['subject_title'] : '' ); ?></p>
							<?php if ( $can_access_assessments ) : ?>
								<?php $this->render_assignment_list( $section_view['assignments'], __( 'Assignments', 'simple-lms' ) ); ?>
							<?php endif; ?>
							<?php $this->render_content_list( $section_view['announcements'], __( 'Announcements', 'simple-lms' ) ); ?>
							<?php $this->render_content_list( $section_view['lessons'], __( 'Lessons', 'simple-lms' ) ); ?>
						<?php else : ?>
							<?php if ( $can_access_assessments ) : ?>
								<?php $this->render_assignment_list( $snapshot['recent_assignments'], __( 'Recent Assignments', 'simple-lms' ) ); ?>
							<?php endif; ?>
							<?php $this->render_content_list( $snapshot['recent_announcements'], __( 'Recent Announcements', 'simple-lms' ) ); ?>
							<?php $this->render_content_list( $snapshot['recent_lessons'], __( 'Recent Lessons', 'simple-lms' ) ); ?>
						<?php endif; ?>
					</div>

					<?php if ( $content ) : ?>
						<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;">
							<h2 style="margin-top:0;"><?php echo esc_html( $content['title'] ); ?></h2>
							<p style="color:#50575e;"><?php echo esc_html( $content['section_title'] ?: __( 'Global', 'simple-lms' ) ); ?> | <?php echo esc_html( $content['published_at'] ); ?></p>
							<?php
							$post = get_post( (int) $content['id'] );
							echo wp_kses_post( wpautop( $post ? $post->post_content : '' ) );
							?>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	public function handle_login_redirect( $redirect_to, $requested_redirect_to, $user ) {
		if ( ! (int) $this->settings->get( 'enable_dashboard_redirect', 1 ) ) {
			return $redirect_to;
		}

		if ( ! $user || empty( $user->ID ) ) {
			return $redirect_to;
		}

		if ( ! empty( $requested_redirect_to ) ) {
			return $requested_redirect_to;
		}

		if ( RoleManager::is_plugin_user( $user->ID ) ) {
			return $this->get_user_destination_url( $user->ID );
		}

		return $redirect_to;
	}

	public function redirect_default_dashboard() {
		if ( ! is_user_logged_in() || ! is_admin() || ! (int) $this->settings->get( 'enable_dashboard_redirect', 1 ) ) {
			return;
		}

		global $pagenow;

		if ( 'index.php' !== $pagenow ) {
			return;
		}

		if ( ! RoleManager::is_plugin_user( get_current_user_id() ) ) {
			return;
		}

		wp_safe_redirect( $this->get_user_destination_url( get_current_user_id() ) );
		exit;
	}

	public function get_user_destination_url( $user_id = 0 ) {
		$user_id = $user_id ?: get_current_user_id();

		if ( ! RoleManager::is_plugin_user( $user_id ) ) {
			return admin_url();
		}

		$role = RoleManager::get_primary_role( $user_id );

		if ( in_array( $role, array( 'lecturer', RoleManager::ROLE_STAFF, 'student' ), true ) ) {
			return apply_filters( 'slms_user_destination_url', admin_url( 'admin.php?page=slms-workspace' ), $user_id, $role );
		}

		return apply_filters( 'slms_user_destination_url', admin_url( 'admin.php?page=slms-dashboard' ), $user_id, $role );
	}

	private function render_content_list( array $items, $title ) {
		?>
		<h3><?php echo esc_html( $title ); ?></h3>
		<?php if ( empty( $items ) ) : ?>
			<p><?php esc_html_e( 'Nothing has been published here yet.', 'simple-lms' ); ?></p>
			<?php
			return;
		endif;

		foreach ( $items as $item ) :
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
				<a href="<?php echo esc_url( $url ); ?>" class="button button-link" style="padding-left:0;"><?php esc_html_e( 'Read more', 'simple-lms' ); ?></a>
			</div>
		<?php
		endforeach;
	}

	private function render_assignment_list( array $items, $title ) {
		?>
		<h3><?php echo esc_html( $title ); ?></h3>
		<?php if ( empty( $items ) ) : ?>
			<p><?php esc_html_e( 'No assignments are available here yet.', 'simple-lms' ); ?></p>
			<?php
			return;
		endif;

		foreach ( $items as $item ) :
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
				<a href="<?php echo esc_url( $url ); ?>" class="button button-link" style="padding-left:0;"><?php esc_html_e( 'Open assignment', 'simple-lms' ); ?></a>
			</div>
		<?php
		endforeach;
	}

	private function can_access_assessment_center() {
		return current_user_can( RoleManager::CAP_SUBMIT_WORK ) || current_user_can( RoleManager::CAP_MANAGE_GRADEBOOK ) || current_user_can( RoleManager::CAP_MANAGE_ACADEMICS );
	}
}
