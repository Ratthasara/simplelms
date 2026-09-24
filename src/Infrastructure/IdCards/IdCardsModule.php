<?php

namespace SimpleLMS\Infrastructure\IdCards;

use SimpleLMS\Core\AcademicClock;
use SimpleLMS\Domain\Users\ProfileService;
use SimpleLMS\Domain\Users\RoleManager;

defined( 'ABSPATH' ) || exit;

class IdCardsModule {
	/** @var IdCardsModule|null */
	private static $instance = null;

	/** @var ProfileService */
	private $profiles;

	/** @var CardRepository */
	private $repository;

	/** @var CardRenderer */
	private $renderer;

	public function __construct( ?ProfileService $profiles = null, ?CardRepository $repository = null, ?CardRenderer $renderer = null ) {
		$this->profiles   = $profiles ?: new ProfileService();
		$this->repository = $repository ?: new CardRepository();
		$this->renderer   = $renderer ?: new CardRenderer( $this->repository );
		self::$instance   = $this;
	}

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function register_hooks() {
		add_action( 'init', array( $this, 'register_assets' ) );
		add_action( 'init', array( $this, 'repair_corrupted_card_statuses' ), 20 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render_verification_page' ) );
		add_shortcode( 'slms_id_card', array( $this, 'render_shortcode' ) );
		add_action( 'wp_ajax_slms_id_cards_batch_render', array( $this, 'handle_batch_render_ajax' ) );
	}

	public function repair_corrupted_card_statuses() {
		$this->repository->repair_corrupted_statuses();
	}

	public function repository() {
		return $this->repository;
	}

	public function renderer() {
		return $this->renderer;
	}

	public function register_assets() {
		wp_register_style( 'slms-id-card', SLMS_URL . 'assets/id-cards/css/id-card.css', array(), SLMS_VERSION . '.' . SLMS_DB_VERSION );
		wp_register_script( 'slms-id-html2canvas', SLMS_URL . 'assets/vendor/html2canvas/html2canvas.min.js', array(), SLMS_VERSION . '.' . SLMS_DB_VERSION, true );
		wp_register_script( 'slms-id-jszip', SLMS_URL . 'assets/vendor/jszip/jszip.min.js', array(), '3.10.1', true );
		wp_register_script( 'slms-id-card', SLMS_URL . 'assets/id-cards/js/id-card.js', array( 'slms-id-html2canvas', 'slms-id-jszip' ), SLMS_VERSION . '.' . SLMS_DB_VERSION, true );
		wp_localize_script(
			'slms-id-card',
			'SLMS_ID_CARD_BATCH',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'slms_id_cards_batch_download' ),
			)
		);
	}

	public function enqueue_frontend_assets() {
		if ( is_user_logged_in() ) {
			$this->renderer->enqueue_assets();
		}
	}

	public function render_shortcode( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'user_id' => get_current_user_id(),
				'issue'   => '0',
			),
			(array) $atts,
			'slms_id_card'
		);

		$user_id = absint( $atts['user_id'] );
		if ( ! $user_id || ( get_current_user_id() !== $user_id && ! current_user_can( RoleManager::CAP_MANAGE_USERS ) && ! current_user_can( 'manage_options' ) ) ) {
			return '<p>' . esc_html__( 'You do not have permission to view this ID card.', 'simple-lms' ) . '</p>';
		}

		$profile = $this->profiles->get_profile( $user_id );
		$role    = RoleManager::get_primary_role( $user_id );
		$person  = $this->get_person( $user_id, $profile, $role, '' );
		if ( ! $person ) {
			return '<p>' . esc_html__( 'LMS profile not found.', 'simple-lms' ) . '</p>';
		}

		$card = $this->get_latest_card_for_person_data( $person, true );
		if ( ! $card && '1' === (string) $atts['issue'] && ( current_user_can( RoleManager::CAP_MANAGE_USERS ) || current_user_can( 'manage_options' ) ) ) {
			$card = $this->repository->issue_card( $person, get_current_user_id() );
			if ( is_wp_error( $card ) ) {
				$card = null;
			}
		}

		if ( ! $card || 'active' !== ( $card['status'] ?? '' ) ) {
			return '<p>' . esc_html__( 'No issued ID card is available for this account yet.', 'simple-lms' ) . '</p>';
		}

		return $this->renderer->render_card_set( $person, $card, array( 'uid' => 'slms-id-shortcode-' . $user_id, 'flattened_only' => true ) );
	}

	public function render_user_card( $user_id, array $args = array() ) {
		$user_id   = absint( $user_id );
		$profile   = isset( $args['profile'] ) && is_array( $args['profile'] ) ? $args['profile'] : $this->profiles->get_profile( $user_id );
		$role      = sanitize_key( $args['role'] ?? RoleManager::get_primary_role( $user_id ) );
		$photo_url = esc_url_raw( $args['photo_url'] ?? '' );
		$person    = $this->get_person( $user_id, $profile, $role, $photo_url );

		if ( ! $person ) {
			return '<div class="slms-id-card-empty"><p>' . esc_html__( 'LMS profile not found.', 'simple-lms' ) . '</p></div>';
		}

		$card = $this->get_latest_card_for_person_data( $person, true );
		if ( ! $card || 'active' !== ( $card['status'] ?? '' ) ) {
			return '<div class="slms-id-card-empty"><p>' . esc_html__( 'No issued ID card is available yet. Please ask an administrator to issue one from the Card Issuer.', 'simple-lms' ) . '</p></div>';
		}

		return $this->renderer->render_card_set( $person, $card, array( 'uid' => 'slms-id-user-' . $user_id, 'flattened_only' => true ) );
	}

	public function render_portal_card( $user_id, $role, ?array $profile, $photo_url = '' ) {
		return $this->render_user_card(
			$user_id,
			array(
				'role'      => $role,
				'profile'   => $profile,
				'photo_url' => $photo_url,
			)
		);
	}

	public function issue_user_card( $user_id, $actor_user_id = 0 ) {
		$user_id  = absint( $user_id );
		$profile  = $this->profiles->get_profile( $user_id );
		$role     = RoleManager::get_primary_role( $user_id );
		$person   = $this->get_person( $user_id, $profile, $role, '' );

		if ( ! $person ) {
			return new \WP_Error( 'slms_id_missing_person', __( 'Person not found.', 'simple-lms' ) );
		}

		return $this->repository->issue_card( $person, $actor_user_id ?: get_current_user_id() );
	}

	public function get_person( $user_id, ?array $profile = null, $role = '', $photo_url = '' ) {
		$user_id = absint( $user_id );
		$user    = get_userdata( $user_id );

		if ( ! $user_id || ! $user ) {
			return null;
		}

		$profile = is_array( $profile ) ? $profile : $this->profiles->get_profile( $user_id );
		if ( ! $profile ) {
			return null;
		}

		$person_type = sanitize_key( $profile['person_type'] ?? '' );
		$is_staff    = 'student' !== $person_type;
		$program     = '';
		$department  = sanitize_text_field( $profile['department'] ?? '' );

		if ( ! empty( $profile['program_id'] ) ) {
			$term = get_term( absint( $profile['program_id'] ), 'slms_program' );
			if ( $term && ! is_wp_error( $term ) ) {
				$program = $term->name;
			}
		}

		if ( '' === $role ) {
			$role = RoleManager::get_primary_role( $user_id );
		}

		$role_label = $is_staff ? ( $profile['position_title'] ?? '' ) : __( 'Student', 'simple-lms' );
		if ( '' === trim( (string) $role_label ) ) {
			$role_label = $role ? RoleManager::get_role_label( $role ) : ( $is_staff ? __( 'Staff', 'simple-lms' ) : __( 'Student', 'simple-lms' ) );
		}

		if ( '' === $photo_url ) {
			$photo_url = $this->get_profile_photo_url( $user_id );
		}

		return array(
			'user_id'     => $user_id,
			'person_type' => $is_staff ? 'staff' : 'student',
			'id_number'   => sanitize_text_field( $profile['person_code'] ?? '' ),
			'person_code' => sanitize_text_field( $profile['person_code'] ?? '' ),
			'full_name'   => sanitize_text_field( $profile['display_name'] ?? $user->display_name ),
			'role'        => sanitize_text_field( $role_label ),
			'program'     => sanitize_text_field( $program ),
			'department'  => sanitize_text_field( $department ),
			'nrc_number'  => sanitize_text_field( $profile['nrc_number'] ?? '' ),
			'phone'       => sanitize_text_field( $profile['phone'] ?? '' ),
			'email'       => sanitize_email( $profile['user_email'] ?? $user->user_email ),
			'photo_url'   => esc_url_raw( $photo_url ),
		);
	}

	public function get_latest_card_for_person_data( array $person, $active_only = false ) {
		$user_id     = absint( $person['user_id'] ?? 0 );
		$person_code = sanitize_text_field( $person['person_code'] ?? ( $person['id_number'] ?? '' ) );
		$email       = sanitize_email( $person['email'] ?? '' );

		if ( $active_only ) {
			return $this->repository->get_latest_active_card_for_person( $user_id, $person_code, $email );
		}

		return $this->repository->get_latest_card_for_person( $user_id, $person_code, $email, false );
	}


	public function handle_batch_render_ajax() {
		check_ajax_referer( 'slms_id_cards_batch_download', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to download issued ID cards.', 'simple-lms' ) ), 403 );
		}

		$filters = array();
		if ( isset( $_POST['filters'] ) ) {
			$decoded = json_decode( wp_unslash( (string) $_POST['filters'] ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( is_array( $decoded ) ) {
				$filters = $decoded;
			}
		}

		$args        = $this->sanitize_batch_download_filters( $filters );
		$person_type = 'staff' === $args['person_type'] ? 'staff' : 'student';
		$label       = 'staff' === $person_type ? __( 'staff', 'simple-lms' ) : __( 'student', 'simple-lms' );
		$settings    = $this->renderer->get_settings();
		$cards       = array();
		$page        = 1;
		$per_page    = 100;
		$max_cards   = (int) apply_filters( 'slms_id_card_batch_download_limit', 500 );
		$truncated   = false;

		do {
			$query_args             = $args;
			$query_args['page']     = $page;
			$query_args['per_page'] = $per_page;
			$profiles               = $this->profiles->list_profiles( $query_args );

			foreach ( $profiles as $profile ) {
				$user_id = absint( $profile['user_id'] ?? 0 );
				$role    = RoleManager::get_primary_role( $user_id );
				$person  = $this->get_person( $user_id, $profile, $role, '' );
				$card    = $person ? $this->get_latest_card_for_person_data( $person, true ) : null;

				if ( ! $person || ! $card || 'active' !== ( $card['status'] ?? '' ) ) {
					continue;
				}

				$base = sanitize_file_name( ( $card['person_code'] ?? $person['id_number'] ?? $user_id ) . '-' . ( $card['full_name'] ?? $person['full_name'] ?? 'id-card' ) );
				if ( '' === $base ) {
					$base = 'id-card-' . $user_id;
				}

				$uid  = 'slms-batch-' . $person_type . '-' . $user_id . '-' . absint( $card['id'] ?? 0 );
				$html = '<div class="ugp-id-card-set ugp-id-batch-card-set" data-ugp-id-card-set>';
				$html .= $this->renderer->render_front_card( $this->renderer->normalize_person_for_card( $person, $card ), $settings, $uid . '-front' );
				$html .= $this->renderer->render_back_card( $this->renderer->normalize_person_for_card( $person, $card ), $settings, $uid . '-back' );
				$html .= '</div>';

				$cards[] = array(
					'front_id'       => $uid . '-front',
					'back_id'        => $uid . '-back',
					'front_filename' => $person_type . '/' . $base . '-front.png',
					'back_filename'  => $person_type . '/' . $base . '-back.png',
					'html'           => $html,
				);

				if ( count( $cards ) >= $max_cards ) {
					$truncated = true;
					break 2;
				}
			}

			$page++;
		} while ( count( $profiles ) === $per_page );

		wp_send_json_success(
			array(
				'cards'     => $cards,
				'count'     => count( $cards ),
				'truncated' => $truncated,
				'filename'  => sanitize_file_name( 'issued-' . $label . '-id-cards-' . AcademicClock::date( 'Y-m-d' ) . '.zip' ),
				'message'   => $truncated ? sprintf( __( 'The ZIP was limited to the first %d issued cards for safety. Narrow the filters to download the remaining cards.', 'simple-lms' ), $max_cards ) : '',
			)
		);
	}

	private function sanitize_batch_download_filters( array $filters ) {
		$person_type = sanitize_key( $filters['person_type'] ?? 'student' );
		if ( 'students' === $person_type ) {
			$person_type = 'student';
		}
		if ( ! in_array( $person_type, array( 'student', 'staff' ), true ) ) {
			$person_type = 'student';
		}

		return array(
			'person_type'          => $person_type,
			'search'               => sanitize_text_field( $filters['search'] ?? '' ),
			'orderby'              => sanitize_key( $filters['orderby'] ?? ( 'staff' === $person_type ? 'department' : 'program_intake' ) ),
			'program_id'           => absint( $filters['program_id'] ?? 0 ),
			'intake_term_id'       => absint( $filters['intake_term_id'] ?? 0 ),
			'intake_academic_year' => sanitize_text_field( $filters['intake_academic_year'] ?? '' ),
			'role'                 => sanitize_key( $filters['role'] ?? '' ),
			'department'           => sanitize_text_field( $filters['department'] ?? '' ),
		);
	}

	public function get_profile_photo_url( $user_id ) {
		return $this->profiles->get_profile_photo_url( $user_id, 400 );
	}

	public function maybe_render_verification_page() {
		if ( is_admin() || empty( $_GET['slms_id_verify'] ) ) {
			return;
		}

		$token    = sanitize_text_field( wp_unslash( $_GET['slms_id_verify'] ) );
		$card     = $this->repository->get_card_by_token( $token );
		$status   = $card && 'active' === ( $card['status'] ?? '' );
		$settings = $this->renderer->get_settings();

		status_header( $card ? 200 : 404 );
		nocache_headers();
		?>
		<!doctype html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title><?php echo esc_html( $settings['verification_page_title'] ); ?></title>
			<?php wp_head(); ?>
			<style>
				.slms-id-verify-page{min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f1f5f9;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;padding:24px;box-sizing:border-box}.slms-id-verify-card{width:min(100%,560px);background:#fff;border-radius:24px;box-shadow:0 22px 50px rgba(15,23,42,.16);padding:32px}.slms-id-verify-badge{display:inline-flex;align-items:center;border-radius:999px;padding:8px 14px;font-weight:800;background:#e0f2fe;color:#075985}.slms-id-verify-badge.is-invalid{background:#fee2e2;color:#991b1b}.slms-id-verify-card h1{margin:18px 0 10px;font-size:32px}.slms-id-verify-table{width:100%;border-collapse:collapse;margin-top:18px}.slms-id-verify-table th,.slms-id-verify-table td{text-align:left;border-top:1px solid #e2e8f0;padding:12px 0}.slms-id-verify-table th{width:38%;color:#475569}
			</style>
		</head>
		<body>
			<main class="slms-id-verify-page">
				<section class="slms-id-verify-card">
					<span class="slms-id-verify-badge <?php echo $status ? '' : 'is-invalid'; ?>"><?php echo $status ? esc_html__( 'Valid UGP ID', 'simple-lms' ) : esc_html__( 'Invalid or Revoked ID', 'simple-lms' ); ?></span>
					<h1><?php echo esc_html( $settings['verification_page_title'] ); ?></h1>
					<?php if ( $card ) : ?>
						<table class="slms-id-verify-table">
							<tr><th><?php esc_html_e( 'Name', 'simple-lms' ); ?></th><td><?php echo esc_html( $card['full_name'] ); ?></td></tr>
							<tr><th><?php esc_html_e( 'ID Number', 'simple-lms' ); ?></th><td><?php echo esc_html( $card['person_code'] ); ?></td></tr>
							<tr><th><?php esc_html_e( 'Role', 'simple-lms' ); ?></th><td><?php echo esc_html( $card['role_label'] ); ?></td></tr>
							<?php if ( ! empty( $card['program_label'] ) ) : ?><tr><th><?php esc_html_e( 'Program', 'simple-lms' ); ?></th><td><?php echo esc_html( $card['program_label'] ); ?></td></tr><?php endif; ?>
							<?php if ( ! empty( $card['department_label'] ) ) : ?><tr><th><?php esc_html_e( 'Department', 'simple-lms' ); ?></th><td><?php echo esc_html( $card['department_label'] ); ?></td></tr><?php endif; ?>
							<tr><th><?php esc_html_e( 'Status', 'simple-lms' ); ?></th><td><?php echo esc_html( ucfirst( $card['status'] ) ); ?></td></tr>
							<tr><th><?php esc_html_e( 'Issued', 'simple-lms' ); ?></th><td><?php echo esc_html( $card['issued_at'] ); ?></td></tr>
						</table>
					<?php else : ?>
						<p><?php esc_html_e( 'No matching ID card record was found.', 'simple-lms' ); ?></p>
					<?php endif; ?>
				</section>
			</main>
			<?php wp_footer(); ?>
		</body>
		</html>
		<?php
		exit;
	}
}
