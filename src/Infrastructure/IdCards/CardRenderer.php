<?php

namespace SimpleLMS\Infrastructure\IdCards;

defined( 'ABSPATH' ) || exit;

class CardRenderer {
	/** @var CardRepository */
	private $repository;

	public function __construct( CardRepository $repository ) {
		$this->repository = $repository;
	}

	public static function default_settings() {
		return array(
			'front_template_url'       => SLMS_URL . 'assets/id-cards/images/front-template.png',
			'back_template_url'        => SLMS_URL . 'assets/id-cards/images/back-template.png',
			'website_text'             => 'ugp.edu.mm',
			'default_staff_department' => '',
			'default_student_program'  => '',
			'show_dynamic_qr'          => '0',
			'verification_page_title'  => 'UGP ID Verification',
		);
	}

	public static function card_dimensions() {
		$width_mm  = 54.0;
		$height_mm = 85.6;
		$dpi       = 300;

		return array(
			'width_mm'         => $width_mm,
			'height_mm'        => $height_mm,
			'dpi'              => $dpi,
			'export_width_px'  => (int) round( $width_mm / 25.4 * $dpi ),
			'export_height_px' => (int) round( $height_mm / 25.4 * $dpi ),
		);
	}

	public function get_settings() {
		$current = get_option( 'slms_id_card_settings', array() );
		if ( ! is_array( $current ) ) {
			$current = array();
		}

		return array_merge( self::default_settings(), $current );
	}

	public function update_settings( array $settings ) {
		$current = $this->get_settings();
		$clean   = array(
			'front_template_url'       => esc_url_raw( $settings['front_template_url'] ?? $current['front_template_url'] ),
			'back_template_url'        => esc_url_raw( $settings['back_template_url'] ?? $current['back_template_url'] ),
			'website_text'             => sanitize_text_field( $settings['website_text'] ?? $current['website_text'] ),
			'default_staff_department' => sanitize_text_field( $settings['default_staff_department'] ?? $current['default_staff_department'] ),
			'default_student_program'  => sanitize_text_field( $settings['default_student_program'] ?? $current['default_student_program'] ),
			'show_dynamic_qr'          => ! empty( $settings['show_dynamic_qr'] ) ? '1' : '0',
			'verification_page_title'  => sanitize_text_field( $settings['verification_page_title'] ?? $current['verification_page_title'] ),
		);

		update_option( 'slms_id_card_settings', $clean, false );

		return $clean;
	}

	public function normalize_person_for_card( array $person, $card = null ) {
		$settings   = $this->get_settings();
		$is_staff   = 'student' !== ( $person['person_type'] ?? 'student' );
		$card       = is_array( $card ) ? $card : array();
		$id_number  = sanitize_text_field( $card['person_code'] ?? ( $person['id_number'] ?? ( $person['person_code'] ?? '' ) ) );
		$token      = sanitize_text_field( $card['verification_token'] ?? '' );
		$verify_url = $token ? $this->get_verification_url( $token ) : '';
		$program    = sanitize_text_field( $person['program'] ?? ( $person['program_label'] ?? '' ) );
		$department = sanitize_text_field( $person['department'] ?? ( $person['department_label'] ?? '' ) );

		if ( ! $is_staff && '' === $program ) {
			$program = sanitize_text_field( $settings['default_student_program'] );
		}

		if ( $is_staff && '' === $department ) {
			$department = sanitize_text_field( $settings['default_staff_department'] );
		}

		$data = array(
			'user_id'     => absint( $person['user_id'] ?? ( $card['person_user_id'] ?? 0 ) ),
			'person_type' => $is_staff ? 'staff' : 'student',
			'is_staff'    => $is_staff,
			'id_number'   => $id_number,
			'full_name'   => sanitize_text_field( $card['full_name'] ?? ( $person['full_name'] ?? '' ) ),
			'role'        => sanitize_text_field( $card['role_label'] ?? ( $person['role'] ?? ( $is_staff ? 'Staff' : 'Student' ) ) ),
			'program'     => $program,
			'department'  => $department,
			'nrc_number'  => sanitize_text_field( $card['nrc_number'] ?? ( $person['nrc_number'] ?? '' ) ),
			'phone'       => sanitize_text_field( $card['phone'] ?? ( $person['phone'] ?? '' ) ),
			'email'       => sanitize_email( $card['email'] ?? ( $person['email'] ?? '' ) ),
			'photo_url'   => esc_url_raw( $card['photo_url'] ?? ( $person['photo_url'] ?? '' ) ),
			'verify_url'  => esc_url_raw( $verify_url ),
			'qr_url'      => $verify_url ? $this->get_qr_image_url( $verify_url ) : '',
			'card_status' => sanitize_key( $card['status'] ?? 'draft' ),
			'issued_at'   => sanitize_text_field( $card['issued_at'] ?? '' ),
		);

		return apply_filters( 'slms_id_card_data', $data, $person, $card, $this );
	}

	public function render_card_set( array $person, $card = null, array $args = array() ) {
		$this->enqueue_assets();

		$data     = $this->normalize_person_for_card( $person, $card );
		$settings = $this->get_settings();
		$uid            = sanitize_html_class( $args['uid'] ?? ( 'slms-id-card-' . ( $data['user_id'] ?: wp_rand( 1000, 9999 ) ) ) );
		$flattened_only = ! empty( $args['flattened_only'] );
		$set_class      = $flattened_only ? 'ugp-id-card-set ugp-id-flattened-only' : 'ugp-id-card-set';

		ob_start();
		?>
		<div class="ugp-id-card-toolbar slms-id-card-action-row" data-ugp-id-toolbar>
			<button type="button" class="slms-portal-button is-secondary ugp-id-action-button ugp-id-download" data-ugp-id-download="front" data-target="<?php echo esc_attr( $uid ); ?>-front" data-filename="<?php echo esc_attr( sanitize_file_name( $data['id_number'] . '-front' ) ); ?>"><?php esc_html_e( 'Download Front PNG', 'simple-lms' ); ?></button>
			<button type="button" class="slms-portal-button is-secondary ugp-id-action-button ugp-id-download" data-ugp-id-download="back" data-target="<?php echo esc_attr( $uid ); ?>-back" data-filename="<?php echo esc_attr( sanitize_file_name( $data['id_number'] . '-back' ) ); ?>"><?php esc_html_e( 'Download Back PNG', 'simple-lms' ); ?></button>
			<button type="button" class="slms-portal-button ugp-id-action-button" data-ugp-id-print><?php esc_html_e( 'Print / Save PDF', 'simple-lms' ); ?></button>
		</div>
		<div class="<?php echo esc_attr( $set_class ); ?>" data-ugp-id-card-set<?php echo $flattened_only ? ' data-ugp-id-display-mode="flattened-only"' : ''; ?>>
			<?php echo $this->render_front_card( $data, $settings, $uid . '-front' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php echo $this->render_back_card( $data, $settings, $uid . '-back' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	public function render_front_card( array $data, ?array $settings = null, $element_id = '' ) {
		$settings   = $settings ?: $this->get_settings();
		$subline    = $data['is_staff'] ? $data['department'] : $data['program'];
		$dimensions = self::card_dimensions();

		ob_start();
		?>
		<div class="ugp-id-card ugp-id-front <?php echo $data['is_staff'] ? 'is-staff' : 'is-student'; ?>" data-export-width="<?php echo esc_attr( $dimensions['export_width_px'] ); ?>" data-export-height="<?php echo esc_attr( $dimensions['export_height_px'] ); ?>" <?php echo $element_id ? 'id="' . esc_attr( $element_id ) . '"' : ''; ?>>
			<img class="ugp-id-template" src="<?php echo esc_url( $settings['front_template_url'] ); ?>" alt="">
			<div class="ugp-id-photo-box">
				<?php if ( ! empty( $data['photo_url'] ) ) : ?>
					<img src="<?php echo esc_url( $data['photo_url'] ); ?>" alt="<?php echo esc_attr( $data['full_name'] ); ?>">
				<?php endif; ?>
			</div>
			<div class="ugp-id-front-name ugp-id-fit-text"><?php echo esc_html( $data['full_name'] ); ?></div>
			<div class="ugp-id-front-role ugp-id-fit-text"><?php echo esc_html( $data['role'] ); ?></div>
			<div class="ugp-id-front-subline ugp-id-fit-text"><?php echo esc_html( $subline ); ?></div>
			<div class="ugp-id-front-rector ugp-id-fit-text"><?php esc_html_e( 'Rector', 'simple-lms' ); ?></div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	public function render_back_card( array $data, ?array $settings = null, $element_id = '' ) {
		$settings   = $settings ?: $this->get_settings();
		$dimensions = self::card_dimensions();
		ob_start();
		?>
		<div class="ugp-id-card ugp-id-back" data-export-width="<?php echo esc_attr( $dimensions['export_width_px'] ); ?>" data-export-height="<?php echo esc_attr( $dimensions['export_height_px'] ); ?>" <?php echo $element_id ? 'id="' . esc_attr( $element_id ) . '"' : ''; ?>>
			<img class="ugp-id-template" src="<?php echo esc_url( $settings['back_template_url'] ); ?>" alt="">
			<div class="ugp-id-back-website ugp-id-fit-text"><?php echo esc_html( $settings['website_text'] ); ?></div>
			<?php if ( ! empty( $settings['show_dynamic_qr'] ) && '1' === (string) $settings['show_dynamic_qr'] && ! empty( $data['qr_url'] ) ) : ?>
				<img class="ugp-id-dynamic-qr" src="<?php echo esc_url( $data['qr_url'] ); ?>" alt="<?php esc_attr_e( 'Verification QR code', 'simple-lms' ); ?>">
			<?php endif; ?>
			<div class="ugp-id-back-id ugp-id-fit-text"><?php echo esc_html( sprintf( 'ID: %s', $data['id_number'] ) ); ?></div>
			<div class="ugp-id-back-nrc ugp-id-fit-text"><?php echo esc_html( sprintf( 'NRC: %s', $data['nrc_number'] ?: '-' ) ); ?></div>
			<div class="ugp-id-back-phone ugp-id-fit-text"><?php echo esc_html( sprintf( 'Contact: %s', $data['phone'] ?: '-' ) ); ?></div>
			<div class="ugp-id-back-email ugp-id-fit-text"><?php echo esc_html( sprintf( 'Email: %s', $data['email'] ?: '-' ) ); ?></div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	public function get_verification_url( $token ) {
		$token = sanitize_text_field( $token );
		return add_query_arg( 'slms_id_verify', rawurlencode( $token ), home_url( '/' ) );
	}

	public function get_qr_image_url( $payload ) {
		$payload = esc_url_raw( $payload );
		return add_query_arg(
			array(
				'size' => '430x430',
				'data' => $payload,
			),
			'https://api.qrserver.com/v1/create-qr-code/'
		);
	}

	public function enqueue_assets() {
		wp_enqueue_style( 'slms-id-card' );
		wp_enqueue_script( 'slms-id-card' );
	}
}
