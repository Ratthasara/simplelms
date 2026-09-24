<?php

namespace SimpleLMS\Infrastructure\Frontend;

use SimpleLMS\Domain\Settings\SettingsManager;
use SimpleLMS\Domain\Users\RoleManager;
use SimpleLMS\Infrastructure\Admin\WorkspaceAdmin;

defined( 'ABSPATH' ) || exit;

class PortalGateway {
	const SHORTCODE = 'simple_lms_portal';
	const PAGE_FREE_INLINE_STYLE = 'box-sizing:border-box;display:block;width:min(100%,1240px);max-width:1240px;margin:0 auto;padding:clamp(1rem,2vw,1.45rem) 1rem 2.2rem;';

	/**
	 * @var SettingsManager
	 */
	private $settings;

	/**
	 * @var WorkspaceAdmin
	 */
	private $workspace_admin;

	/**
	 * @var PortalExperience
	 */
	private $experience;

	public function __construct( SettingsManager $settings, WorkspaceAdmin $workspace_admin, PortalExperience $experience ) {
		$this->settings        = $settings;
		$this->workspace_admin = $workspace_admin;
		$this->experience      = $experience;
	}

	public function register_hooks() {
		add_shortcode( self::SHORTCODE, array( $this, 'render_shortcode' ) );
		add_shortcode( 'slms_portal', array( $this, 'render_shortcode' ) );
		add_action( 'template_redirect', array( $this, 'maybe_redirect_gateway_page' ) );
		add_action( 'wp_head', array( $this, 'print_critical_host_styles' ), 1 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_slms_toggle_material_visibility', array( $this->experience, 'handle_toggle_material_visibility_ajax' ) );
		add_action( 'wp_ajax_slms_grade_assignment_submission', array( $this->experience, 'handle_grade_assignment_submission_ajax' ) );
		add_action( 'wp_ajax_slms_broadcast_mark_seen', array( $this->experience, 'handle_broadcast_mark_seen_ajax' ) );
		add_action( 'wp_ajax_slms_broadcast_acknowledge', array( $this->experience, 'handle_broadcast_acknowledge_ajax' ) );
		add_filter( 'body_class', array( $this, 'filter_body_class' ) );
		add_filter( 'slms_user_destination_url', array( $this, 'filter_destination_url' ), 10, 3 );
	}

	public function render_shortcode( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'title'    => __( 'Simple LMS Portal', 'simple-lms' ),
				'subtitle' => __( 'One secure academic access point for administrators, officers, lecturers, staff, and students.', 'simple-lms' ),
			),
			(array) $atts,
			self::SHORTCODE
		);

		$institution = $this->settings->get( 'institution_name', get_bloginfo( 'name' ) );
		$page_url       = $this->get_gateway_page_url();
		$is_plugin_user = is_user_logged_in() && RoleManager::is_plugin_user( get_current_user_id() );
		$page_free_style = esc_attr( self::PAGE_FREE_INLINE_STYLE );

		if ( $is_plugin_user ) {
			return '<div class="slms-portal-page-free" style="' . $page_free_style . '">' . $this->experience->render( $page_url ) . '</div>';
		}

		$destination = $is_plugin_user
			? $this->workspace_admin->get_user_destination_url( get_current_user_id() )
			: wp_login_url( $page_url );
		$button_label = $is_plugin_user ? __( 'Continue to Simple LMS', 'simple-lms' ) : __( 'Log In to Continue', 'simple-lms' );
		$status_title = $is_plugin_user ? __( 'Redirecting you to your academic workspace.', 'simple-lms' ) : __( 'Sign in to access your academic dashboard.', 'simple-lms' );
		$status_body  = $is_plugin_user
			? __( 'Your role will determine whether you land in the institutional dashboard or your personal workspace.', 'simple-lms' )
			: __( 'After authentication, Simple LMS will route you to the right experience for your role automatically.', 'simple-lms' );

		ob_start();
		?>
		<div class="slms-portal-page-free" style="<?php echo $page_free_style; ?>">
			<section class="slms-portal-gateway">
				<div class="slms-portal-hero">
					<div class="slms-portal-copy">
						<p class="slms-portal-kicker"><?php echo esc_html( $institution ); ?></p>
						<h1 class="slms-portal-title"><?php echo esc_html( $atts['title'] ); ?></h1>
						<p class="slms-portal-subtitle"><?php echo esc_html( $atts['subtitle'] ); ?></p>
						<div class="slms-portal-actions">
							<a class="slms-portal-button" href="<?php echo esc_url( $destination ); ?>"><?php echo esc_html( $button_label ); ?></a>
							<?php if ( $is_plugin_user ) : ?>
								<a class="slms-portal-button is-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=slms-dashboard' ) ); ?>"><?php esc_html_e( 'Open Dashboard', 'simple-lms' ); ?></a>
							<?php endif; ?>
						</div>
					</div>
					<div class="slms-portal-status-card">
						<span class="slms-portal-badge"><?php esc_html_e( 'Secure Access', 'simple-lms' ); ?></span>
						<h2><?php echo esc_html( $status_title ); ?></h2>
						<p><?php echo esc_html( $status_body ); ?></p>
						<ul class="slms-portal-feature-list">
							<li><?php esc_html_e( 'Admins and officers land in the institutional control dashboard.', 'simple-lms' ); ?></li>
							<li><?php esc_html_e( 'Lecturers, staff, and students land in their role-appropriate daily workspace.', 'simple-lms' ); ?></li>
						</ul>
					</div>
				</div>
			</section>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	public function maybe_redirect_gateway_page() {
		if ( is_admin() || is_preview() || wp_doing_ajax() || wp_doing_cron() || $this->is_rest_request() ) {
			return;
		}

		$post = $this->get_gateway_post();

		if ( ! $post ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( get_permalink( $post ) ) );
			exit;
		}

		if ( ! RoleManager::is_plugin_user( get_current_user_id() ) ) {
			return;
		}

		$this->experience->handle_action( get_permalink( $post ) );
	}

	public function enqueue_assets() {
		if ( ! $this->get_gateway_post() ) {
			return;
		}

		wp_enqueue_style( 'dashicons' );

		if ( is_user_logged_in() && current_user_can( 'upload_files' ) ) {
			wp_enqueue_media();
		}

		wp_enqueue_style(
			'slms-cropper',
			SLMS_URL . 'assets/vendor/cropper/cropper.min.css',
			array(),
			SLMS_VERSION
		);

		wp_enqueue_style(
			'slms-portal',
			SLMS_URL . 'assets/portal.css',
			array( 'slms-cropper' ),
			SLMS_VERSION . '.' . SLMS_DB_VERSION
		);

		wp_enqueue_style(
			'slms-portal-mobile',
			SLMS_URL . 'assets/portal-mobile.css',
			array( 'slms-portal' ),
			SLMS_VERSION . '.' . SLMS_DB_VERSION
		);

		wp_add_inline_style(
			'slms-portal',
			<<<'CSS'
.ugp-section:has(.slms-portal-page-free),
.ugp-portal-section:has(.slms-portal-page-free),
.ugp-section-inner:has(.slms-portal-page-free),
.ugp-portal-section-inner:has(.slms-portal-page-free),
.ugp-card:has(.slms-portal-page-free),
.ugb-card:has(.slms-portal-page-free),
.ugp-card-text:has(.slms-portal-page-free),
.ugb-card__content:has(.slms-portal-page-free) {
	margin: 0 !important;
	padding: 0 !important;
	border: 0 !important;
	border-radius: 0 !important;
	background: transparent !important;
	box-shadow: none !important;
}

.ugp-card:has(.slms-portal-page-free) > .ugp-card-title,
.ugb-card:has(.slms-portal-page-free) > .ugb-card-title {
	display: none !important;
}

body.slms-portal-page .ugp-section,
body.slms-portal-page .ugp-portal-section,
body.slms-portal-page .ugp-section-inner,
body.slms-portal-page .ugp-portal-section-inner,
body.slms-portal-page .ugp-card,
body.slms-portal-page .ugb-card,
body.slms-portal-page .ugp-card-text,
body.slms-portal-page .ugb-card__content {
	margin: 0 !important;
	padding: 0 !important;
	border: 0 !important;
	background: transparent !important;
	box-shadow: none !important;
	max-width: none !important;
}

body.slms-portal-page .ugp-card > .ugp-card-title,
body.slms-portal-page .ugb-card > .ugb-card-title {
	display: none !important;
	margin: 0 !important;
}
CSS
		);

		wp_enqueue_script(
			'slms-cropper',
			SLMS_URL . 'assets/vendor/cropper/cropper.min.js',
			array(),
			SLMS_VERSION,
			true
		);

		wp_enqueue_script(
			'slms-html2canvas',
			SLMS_URL . 'assets/vendor/html2canvas/html2canvas.min.js',
			array(),
			SLMS_VERSION,
			true
		);

		wp_enqueue_script(
			'slms-portal',
			SLMS_URL . 'assets/portal.js',
			array( 'slms-cropper', 'slms-html2canvas' ),
			SLMS_VERSION . '.' . SLMS_DB_VERSION,
			true
		);

		wp_localize_script(
			'slms-portal',
			'slmsPortalConfig',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'slms_portal_action' ),
			)
		);
	}

	public function print_critical_host_styles() {
		if ( ! $this->get_gateway_post() ) {
			return;
		}
		?>
		<style id="slms-portal-critical-host-styles">
			body.slms-portal-page .ugp-section,
			body.slms-portal-page .ugp-portal-section,
			body.slms-portal-page .ugp-section-inner,
			body.slms-portal-page .ugp-portal-section-inner,
			body.slms-portal-page .ugp-card,
			body.slms-portal-page .ugb-card,
			body.slms-portal-page .ugp-card-text,
			body.slms-portal-page .ugb-card__content {
				box-sizing: border-box;
				margin: 0 !important;
				padding: 0 !important;
				border: 0 !important;
				background: transparent !important;
				box-shadow: none !important;
				max-width: none !important;
			}

			body.slms-portal-page .ugp-card,
			body.slms-portal-page .ugb-card {
				width: 100% !important;
			}

			body.slms-portal-page .ugp-card > .ugp-card-title,
			body.slms-portal-page .ugb-card > .ugb-card-title {
				display: none !important;
				margin: 0 !important;
			}

			body.slms-portal-page .slms-portal-page-free {
				box-sizing: border-box;
				display: block;
				width: min(100%, 1240px);
				max-width: 1240px;
				margin: 0 auto;
				padding: clamp(1rem, 2vw, 1.45rem) 1rem 2.2rem;
			}
		</style>
		<?php
	}

	public function filter_body_class( $classes ) {
		if ( ! $this->get_gateway_post() ) {
			return $classes;
		}

		$classes[] = 'slms-portal-page';

		return $classes;
	}

	private function get_gateway_post() {
		if ( ! is_singular() ) {
			return null;
		}

		$post = get_queried_object();

		if ( ! ( $post instanceof \WP_Post ) ) {
			return null;
		}

		foreach ( $this->get_shortcodes() as $shortcode ) {
			if ( has_shortcode( (string) $post->post_content, $shortcode ) ) {
				return $post;
			}
		}

		return null;
	}

	private function get_gateway_page_url() {
		$post = $this->get_gateway_post();

		if ( $post ) {
			return get_permalink( $post );
		}

		$fallback_id = $this->find_gateway_page_id();

		if ( $fallback_id ) {
			return get_permalink( $fallback_id );
		}

		return home_url( '/' );
	}

	private function get_shortcodes() {
		return array(
			self::SHORTCODE,
			'slms_portal',
		);
	}

	private function is_rest_request() {
		return defined( 'REST_REQUEST' ) && REST_REQUEST;
	}

	private function find_gateway_page_id() {
		global $wpdb;

		$shortcodes = $this->get_shortcodes();
		$clauses    = array();
		$params     = array();

		foreach ( $shortcodes as $shortcode ) {
			$clauses[] = 'post_content LIKE %s';
			$params[]  = '%[' . $wpdb->esc_like( $shortcode ) . '%';
		}

		$sql = "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('page', 'post') AND post_status = 'publish' AND (" . implode( ' OR ', $clauses ) . ') ORDER BY ID ASC LIMIT 1';

		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
	}

	public function filter_destination_url( $default_url, $user_id, $role ) {
		$page_url = $this->get_gateway_page_url();

		if ( ! $page_url || home_url( '/' ) === $page_url ) {
			return $default_url;
		}

		if ( $this->user_requires_password_change( $user_id ) ) {
			return add_query_arg( 'slms_view', 'security', $page_url );
		}

		if ( 'student' === $role ) {
			return add_query_arg( 'slms_view', 'dashboard', $page_url );
		}

		if ( in_array( $role, array( 'lecturer', 'administrator', 'officer', RoleManager::ROLE_STAFF ), true ) ) {
			return add_query_arg( 'slms_view', 'dashboard', $page_url );
		}

		return $default_url;
	}

	private function user_requires_password_change( $user_id ) {
		return (bool) get_user_meta( absint( $user_id ), 'slms_force_password_reset', true );
	}
}
