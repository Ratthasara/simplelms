<?php

namespace SimpleLMS\Domain\Settings;

use SimpleLMS\Core\AcademicClock;
use SimpleLMS\Domain\Assessment\GradeScaleService;
use SimpleLMS\Infrastructure\Logging\AuditLogger;

defined( 'ABSPATH' ) || exit;

class SettingsManager {
	const OPTION_KEY = 'slms_settings';
	const ACADEMIC_TIMEZONE = AcademicClock::TIMEZONE;
	const ACADEMIC_GMT_OFFSET = 6.5;

	/**
	 * @var AuditLogger
	 */
	private $audit_logger;

	public function __construct( AuditLogger $audit_logger ) {
		$this->audit_logger = $audit_logger;
	}

	public function register_hooks() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'update_option_' . self::OPTION_KEY, array( $this, 'handle_settings_updated' ), 10, 3 );
	}

	public function register_settings() {
		register_setting(
			'slms_settings_group',
			self::OPTION_KEY,
			array( $this, 'sanitize' )
		);
	}

	public function all() {
		$settings = wp_parse_args( get_option( self::OPTION_KEY, array() ), self::defaults() );

		if ( empty( $settings['grading_scale_rows'] ) || ! is_array( $settings['grading_scale_rows'] ) ) {
			$settings['grading_scale_rows'] = GradeScaleService::build_default_rows(
				$settings['grading_system_mode'] ?? GradeScaleService::MODE_PLUS_MINUS,
				$settings['grading_fail_letter'] ?? GradeScaleService::FAIL_F
			);
		}

		return $settings;
	}

	public function get( $key, $default = null ) {
		$settings = $this->all();

		if ( array_key_exists( $key, $settings ) ) {
			return $settings[ $key ];
		}

		return $default;
	}

	public function sanitize( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$defaults = self::defaults();
		$existing = wp_parse_args( get_option( self::OPTION_KEY, array() ), $defaults );
		$output   = $existing;
		$section  = sanitize_key( (string) ( $input['settings_section'] ?? 'general' ) );

		if ( 'grades' === $section ) {
			$mode          = sanitize_key( $input['grading_system_mode'] ?? $existing['grading_system_mode'] );
			$mode          = GradeScaleService::is_valid_mode( $mode ) ? $mode : GradeScaleService::MODE_PLUS_MINUS;
			$fail_letter   = strtoupper( sanitize_text_field( (string) ( $input['grading_fail_letter'] ?? $existing['grading_fail_letter'] ) ) );
			$fail_letter   = GradeScaleService::is_valid_fail_letter( $fail_letter ) ? $fail_letter : GradeScaleService::FAIL_F;
			$points_enabled = ! empty( $input['grading_points_enabled'] ) ? 1 : 0;
			$rows          = GradeScaleService::normalize_rows(
				$input['grading_scale_rows'] ?? array(),
				$mode,
				$fail_letter,
				$points_enabled
			);

			if ( is_wp_error( $rows ) ) {
				add_settings_error( self::OPTION_KEY, 'slms_grade_scale_invalid', $rows->get_error_message(), 'error' );
				return $output;
			}

			$output['grading_scale_configured'] = 1;
			$output['grading_system_mode']      = $mode;
			$output['grading_fail_letter']      = $fail_letter;
			$output['grading_points_enabled']   = $points_enabled;
			$output['grading_scale_rows']       = $rows;

			return $output;
		}

		$output['institution_name']                 = sanitize_text_field( $input['institution_name'] ?? $existing['institution_name'] );
		$output['institution_code']                 = sanitize_text_field( $input['institution_code'] ?? $existing['institution_code'] );
		$output['academic_timezone']                = self::ACADEMIC_TIMEZONE;
		$output['notification_email']               = sanitize_email( $input['notification_email'] ?? $existing['notification_email'] );
		$output['leave_registrar_email']            = sanitize_email( $input['leave_registrar_email'] ?? $existing['leave_registrar_email'] );
		$output['leave_academic_coordinator_email'] = sanitize_email( $input['leave_academic_coordinator_email'] ?? $existing['leave_academic_coordinator_email'] );
		$output['enable_email_notifications']       = ! empty( $input['enable_email_notifications'] ) ? 1 : 0;
		$output['enable_rest_api']                  = ! empty( $input['enable_rest_api'] ) ? 1 : 0;
		$output['enable_audit_log']                 = ! empty( $input['enable_audit_log'] ) ? 1 : 0;
		$output['enable_dashboard_redirect']        = ! empty( $input['enable_dashboard_redirect'] ) ? 1 : 0;
		$output['send_welcome_emails']              = ! empty( $input['send_welcome_emails'] ) ? 1 : 0;
		$output['student_code_prefix']              = strtoupper( sanitize_text_field( $input['student_code_prefix'] ?? $existing['student_code_prefix'] ) );
		$output['staff_code_prefix']                = strtoupper( sanitize_text_field( $input['staff_code_prefix'] ?? $existing['staff_code_prefix'] ) );
		$output['smtp_enabled']                     = ! empty( $input['smtp_enabled'] ) ? 1 : 0;
		$output['smtp_host']                        = sanitize_text_field( $input['smtp_host'] ?? $existing['smtp_host'] );
		$output['smtp_port']                        = max( 1, min( 65535, absint( $input['smtp_port'] ?? $existing['smtp_port'] ) ?: (int) $defaults['smtp_port'] ) );

		$smtp_encryption = sanitize_key( $input['smtp_encryption'] ?? $existing['smtp_encryption'] );
		if ( ! in_array( $smtp_encryption, array( 'none', 'tls', 'ssl' ), true ) ) {
			$smtp_encryption = $defaults['smtp_encryption'];
		}

		$output['smtp_encryption'] = $smtp_encryption;
		$output['smtp_auth']       = ! empty( $input['smtp_auth'] ) ? 1 : 0;
		$output['smtp_username']   = sanitize_text_field( $input['smtp_username'] ?? $existing['smtp_username'] );

		$smtp_password_raw = isset( $input['smtp_password'] ) ? (string) wp_unslash( $input['smtp_password'] ) : '';
		$output['smtp_password'] = '' !== trim( $smtp_password_raw )
			? $smtp_password_raw
			: (string) ( $existing['smtp_password'] ?? $defaults['smtp_password'] );

		$smtp_from_email = sanitize_email( $input['smtp_from_email'] ?? $existing['smtp_from_email'] );
		$output['smtp_from_email'] = $smtp_from_email && is_email( $smtp_from_email ) ? $smtp_from_email : $defaults['smtp_from_email'];
		$output['smtp_from_name']  = sanitize_text_field( $input['smtp_from_name'] ?? $existing['smtp_from_name'] );

		if ( in_array( $output['student_code_prefix'], array( 'STU', 'UGPS' ), true ) ) {
			$output['student_code_prefix'] = 'S';
		}

		if ( in_array( $output['staff_code_prefix'], array( 'STA', 'STF', 'UGPF' ), true ) ) {
			$output['staff_code_prefix'] = 'F';
		}
		$output['notification_batch_size'] = max( 1, min( 200, absint( $input['notification_batch_size'] ?? $existing['notification_batch_size'] ) ) );

		return $output;
	}

	public function render_fields() {
		$settings = $this->all();
		?>
		<table class="form-table" role="presentation">
			<tr style="display:none;">
				<td colspan="2"><input type="hidden" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[settings_section]" value="general"></td>
			</tr>
			<tr>
				<th scope="row"><label for="slms_institution_name"><?php esc_html_e( 'Institution Name', 'simple-lms' ); ?></label></th>
				<td><input name="<?php echo esc_attr( self::OPTION_KEY ); ?>[institution_name]" id="slms_institution_name" type="text" class="regular-text" value="<?php echo esc_attr( $settings['institution_name'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="slms_institution_code"><?php esc_html_e( 'Institution Code', 'simple-lms' ); ?></label></th>
				<td><input name="<?php echo esc_attr( self::OPTION_KEY ); ?>[institution_code]" id="slms_institution_code" type="text" class="regular-text" value="<?php echo esc_attr( $settings['institution_code'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Academic Timezone', 'simple-lms' ); ?></th>
				<td>
					<input name="<?php echo esc_attr( self::OPTION_KEY ); ?>[academic_timezone]" type="hidden" value="<?php echo esc_attr( self::ACADEMIC_TIMEZONE ); ?>">
					<strong><?php echo esc_html( self::ACADEMIC_TIMEZONE ); ?></strong>
					<p class="description"><?php esc_html_e( 'Simple LMS uses Yangon time (UTC/GMT +6:30) for assignment deadlines, attendance dates, notifications, and all academic timestamps. No manual timezone setting is required.', 'simple-lms' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="slms_notification_email"><?php esc_html_e( 'Notification Email', 'simple-lms' ); ?></label></th>
				<td><input name="<?php echo esc_attr( self::OPTION_KEY ); ?>[notification_email]" id="slms_notification_email" type="email" class="regular-text" value="<?php echo esc_attr( $settings['notification_email'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Leave Application Routing', 'simple-lms' ); ?></th>
				<td>
					<p>
						<label for="slms_leave_registrar_email"><?php esc_html_e( 'Registrar Email', 'simple-lms' ); ?></label>
						<br>
						<input name="<?php echo esc_attr( self::OPTION_KEY ); ?>[leave_registrar_email]" id="slms_leave_registrar_email" type="email" class="regular-text" value="<?php echo esc_attr( $settings['leave_registrar_email'] ); ?>">
					</p>
					<p>
						<label for="slms_leave_academic_coordinator_email"><?php esc_html_e( 'Academic Coordinator Email', 'simple-lms' ); ?></label>
						<br>
						<input name="<?php echo esc_attr( self::OPTION_KEY ); ?>[leave_academic_coordinator_email]" id="slms_leave_academic_coordinator_email" type="email" class="regular-text" value="<?php echo esc_attr( $settings['leave_academic_coordinator_email'] ); ?>">
					</p>
					<p class="description"><?php esc_html_e( 'Student leave applications are emailed to these addresses and to the assigned lecturer(s) of the selected subject.', 'simple-lms' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="slms_notification_batch_size"><?php esc_html_e( 'Notification Batch Size', 'simple-lms' ); ?></label></th>
				<td><input name="<?php echo esc_attr( self::OPTION_KEY ); ?>[notification_batch_size]" id="slms_notification_batch_size" type="number" min="1" max="200" class="small-text" value="<?php echo esc_attr( $settings['notification_batch_size'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Outgoing Mail', 'simple-lms' ); ?></th>
				<td>
					<label><input name="<?php echo esc_attr( self::OPTION_KEY ); ?>[smtp_enabled]" type="checkbox" value="1" <?php checked( (int) $settings['smtp_enabled'], 1 ); ?>> <?php esc_html_e( 'Send queued emails through SMTP', 'simple-lms' ); ?></label>
					<p class="description"><?php esc_html_e( 'Configure SMTP to send welcome emails and other queued notifications from a custom mailbox such as support@ugp.edu.mm.', 'simple-lms' ); ?></p>

					<p>
						<label for="slms_smtp_from_email"><?php esc_html_e( 'From Email', 'simple-lms' ); ?></label>
						<br>
						<input name="<?php echo esc_attr( self::OPTION_KEY ); ?>[smtp_from_email]" id="slms_smtp_from_email" type="email" class="regular-text" value="<?php echo esc_attr( $settings['smtp_from_email'] ); ?>">
					</p>

					<p>
						<label for="slms_smtp_from_name"><?php esc_html_e( 'From Name', 'simple-lms' ); ?></label>
						<br>
						<input name="<?php echo esc_attr( self::OPTION_KEY ); ?>[smtp_from_name]" id="slms_smtp_from_name" type="text" class="regular-text" value="<?php echo esc_attr( $settings['smtp_from_name'] ); ?>">
					</p>

					<p>
						<label for="slms_smtp_host"><?php esc_html_e( 'SMTP Host', 'simple-lms' ); ?></label>
						<br>
						<input name="<?php echo esc_attr( self::OPTION_KEY ); ?>[smtp_host]" id="slms_smtp_host" type="text" class="regular-text" value="<?php echo esc_attr( $settings['smtp_host'] ); ?>">
					</p>

					<p>
						<label for="slms_smtp_port"><?php esc_html_e( 'SMTP Port', 'simple-lms' ); ?></label>
						<br>
						<input name="<?php echo esc_attr( self::OPTION_KEY ); ?>[smtp_port]" id="slms_smtp_port" type="number" min="1" max="65535" class="small-text" value="<?php echo esc_attr( $settings['smtp_port'] ); ?>">
					</p>

					<p>
						<label for="slms_smtp_encryption"><?php esc_html_e( 'Encryption', 'simple-lms' ); ?></label>
						<br>
						<select name="<?php echo esc_attr( self::OPTION_KEY ); ?>[smtp_encryption]" id="slms_smtp_encryption">
							<option value="tls" <?php selected( $settings['smtp_encryption'], 'tls' ); ?>><?php esc_html_e( 'TLS', 'simple-lms' ); ?></option>
							<option value="ssl" <?php selected( $settings['smtp_encryption'], 'ssl' ); ?>><?php esc_html_e( 'SSL', 'simple-lms' ); ?></option>
							<option value="none" <?php selected( $settings['smtp_encryption'], 'none' ); ?>><?php esc_html_e( 'None', 'simple-lms' ); ?></option>
						</select>
					</p>

					<p>
						<label><input name="<?php echo esc_attr( self::OPTION_KEY ); ?>[smtp_auth]" type="checkbox" value="1" <?php checked( (int) $settings['smtp_auth'], 1 ); ?>> <?php esc_html_e( 'SMTP server requires authentication', 'simple-lms' ); ?></label>
					</p>

					<p>
						<label for="slms_smtp_username"><?php esc_html_e( 'SMTP Username', 'simple-lms' ); ?></label>
						<br>
						<input name="<?php echo esc_attr( self::OPTION_KEY ); ?>[smtp_username]" id="slms_smtp_username" type="text" class="regular-text" value="<?php echo esc_attr( $settings['smtp_username'] ); ?>">
					</p>

					<p>
						<label for="slms_smtp_password"><?php esc_html_e( 'SMTP Password', 'simple-lms' ); ?></label>
						<br>
						<input name="<?php echo esc_attr( self::OPTION_KEY ); ?>[smtp_password]" id="slms_smtp_password" type="password" class="regular-text" value="">
						<span class="description"><?php esc_html_e( 'Leave blank to keep the saved password.', 'simple-lms' ); ?></span>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Feature Toggles', 'simple-lms' ); ?></th>
				<td>
					<label><input name="<?php echo esc_attr( self::OPTION_KEY ); ?>[enable_email_notifications]" type="checkbox" value="1" <?php checked( (int) $settings['enable_email_notifications'], 1 ); ?>> <?php esc_html_e( 'Enable email notifications', 'simple-lms' ); ?></label>
					<br>
					<label><input name="<?php echo esc_attr( self::OPTION_KEY ); ?>[enable_rest_api]" type="checkbox" value="1" <?php checked( (int) $settings['enable_rest_api'], 1 ); ?>> <?php esc_html_e( 'Enable Simple LMS REST endpoints', 'simple-lms' ); ?></label>
					<br>
					<label><input name="<?php echo esc_attr( self::OPTION_KEY ); ?>[enable_audit_log]" type="checkbox" value="1" <?php checked( (int) $settings['enable_audit_log'], 1 ); ?>> <?php esc_html_e( 'Enable audit log recording', 'simple-lms' ); ?></label>
					<br>
					<label><input name="<?php echo esc_attr( self::OPTION_KEY ); ?>[enable_dashboard_redirect]" type="checkbox" value="1" <?php checked( (int) $settings['enable_dashboard_redirect'], 1 ); ?>> <?php esc_html_e( 'Redirect LMS users into the Simple LMS dashboard after login', 'simple-lms' ); ?></label>
					<br>
					<label><input name="<?php echo esc_attr( self::OPTION_KEY ); ?>[send_welcome_emails]" type="checkbox" value="1" <?php checked( (int) $settings['send_welcome_emails'], 1 ); ?>> <?php esc_html_e( 'Send welcome emails and password setup links for new students and staff', 'simple-lms' ); ?></label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Onboarding', 'simple-lms' ); ?></th>
				<td>
					<label for="slms_student_code_prefix"><?php esc_html_e( 'Student Code Prefix', 'simple-lms' ); ?></label>
					<br>
					<input name="<?php echo esc_attr( self::OPTION_KEY ); ?>[student_code_prefix]" id="slms_student_code_prefix" type="text" class="small-text" value="<?php echo esc_attr( $settings['student_code_prefix'] ); ?>">
					<p class="description"><?php esc_html_e( 'Used as the type segment in auto-generated student identifiers, for example UGPS-26787.', 'simple-lms' ); ?></p>

					<label for="slms_staff_code_prefix"><?php esc_html_e( 'Staff Code Prefix', 'simple-lms' ); ?></label>
					<br>
					<input name="<?php echo esc_attr( self::OPTION_KEY ); ?>[staff_code_prefix]" id="slms_staff_code_prefix" type="text" class="small-text" value="<?php echo esc_attr( $settings['staff_code_prefix'] ); ?>">
					<p class="description"><?php esc_html_e( 'Used as the type segment in auto-generated staff identifiers, for example UGPF-26843.', 'simple-lms' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	public function render_grade_fields() {
		$settings        = $this->all();
		$configured      = ! empty( $settings['grading_scale_configured'] );
		$mode            = GradeScaleService::is_valid_mode( $settings['grading_system_mode'] ?? '' ) ? $settings['grading_system_mode'] : GradeScaleService::MODE_PLUS_MINUS;
		$fail_letter     = GradeScaleService::is_valid_fail_letter( $settings['grading_fail_letter'] ?? '' ) ? $settings['grading_fail_letter'] : GradeScaleService::FAIL_F;
		$points_enabled  = ! empty( $settings['grading_points_enabled'] );
		$default_rows    = GradeScaleService::build_default_rows( $mode, $fail_letter );
		$saved_rows      = ! empty( $settings['grading_scale_rows'] ) && is_array( $settings['grading_scale_rows'] ) ? $settings['grading_scale_rows'] : array();
		?>
		<input type="hidden" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[settings_section]" value="grades">
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="slms_grading_system_mode"><?php esc_html_e( 'Grading System', 'simple-lms' ); ?></label></th>
				<td>
					<select name="<?php echo esc_attr( self::OPTION_KEY ); ?>[grading_system_mode]" id="slms_grading_system_mode" data-slms-grade-mode>
						<option value="<?php echo esc_attr( GradeScaleService::MODE_STANDARD ); ?>" <?php selected( $mode, GradeScaleService::MODE_STANDARD ); ?>><?php esc_html_e( 'No Plus/Minus System', 'simple-lms' ); ?></option>
						<option value="<?php echo esc_attr( GradeScaleService::MODE_PLUS_MINUS ); ?>" <?php selected( $mode, GradeScaleService::MODE_PLUS_MINUS ); ?>><?php esc_html_e( 'Plus/Minus System', 'simple-lms' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'This grade scale will drive transcript grades and both student and teacher Results views.', 'simple-lms' ); ?></p>
					<?php if ( ! $configured ) : ?>
						<p class="description"><?php esc_html_e( 'Grades are still using the legacy transcript scale until this page is saved for the first time.', 'simple-lms' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="slms_grading_fail_letter"><?php esc_html_e( 'Fail Grade Letter', 'simple-lms' ); ?></label></th>
				<td>
					<select name="<?php echo esc_attr( self::OPTION_KEY ); ?>[grading_fail_letter]" id="slms_grading_fail_letter" data-slms-grade-fail-letter>
						<option value="E" <?php selected( $fail_letter, 'E' ); ?>>E</option>
						<option value="F" <?php selected( $fail_letter, 'F' ); ?>>F</option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Grade Points', 'simple-lms' ); ?></th>
				<td>
					<label><input name="<?php echo esc_attr( self::OPTION_KEY ); ?>[grading_points_enabled]" type="checkbox" value="1" <?php checked( $points_enabled, true ); ?>> <?php esc_html_e( 'Use custom grade points in transcript summaries', 'simple-lms' ); ?></label>
					<p class="description"><?php esc_html_e( 'Turn this off if you only want percentage and letter grades. Transcript summaries will then use a cumulative average instead of a point-based index.', 'simple-lms' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Grade Bands', 'simple-lms' ); ?></th>
				<td>
					<div class="slms-grade-settings-table-wrap">
						<table class="widefat striped slms-grade-settings-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Band', 'simple-lms' ); ?></th>
									<th><?php esc_html_e( 'Letter', 'simple-lms' ); ?></th>
									<th><?php esc_html_e( 'Min %', 'simple-lms' ); ?></th>
									<th><?php esc_html_e( 'Max %', 'simple-lms' ); ?></th>
									<th><?php esc_html_e( 'Points', 'simple-lms' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( GradeScaleService::get_slots( GradeScaleService::MODE_PLUS_MINUS ) as $slot ) : ?>
									<?php
									$is_visible = GradeScaleService::MODE_PLUS_MINUS === $mode || in_array( $slot, GradeScaleService::get_slots( GradeScaleService::MODE_STANDARD ), true );
									$row_value  = isset( $saved_rows[ $slot ] ) && is_array( $saved_rows[ $slot ] )
										? $saved_rows[ $slot ]
										: ( $default_rows[ $slot ] ?? GradeScaleService::build_default_rows( GradeScaleService::MODE_PLUS_MINUS, $fail_letter )[ $slot ] );
									$label      = 'fail' === $slot ? $fail_letter : (string) ( $row_value['label'] ?? '' );
									?>
									<tr data-slms-grade-band-row="<?php echo esc_attr( $slot ); ?>" data-slms-grade-mode-row="<?php echo esc_attr( in_array( $slot, GradeScaleService::get_slots( GradeScaleService::MODE_STANDARD ), true ) ? 'standard' : 'plus_minus' ); ?>"<?php echo $is_visible ? '' : ' hidden'; ?>>
										<td><strong><?php echo esc_html( strtoupper( str_replace( '_', ' ', $slot ) ) ); ?></strong></td>
										<td>
											<?php if ( 'fail' === $slot ) : ?>
												<input type="hidden" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[grading_scale_rows][<?php echo esc_attr( $slot ); ?>][label]" value="<?php echo esc_attr( $fail_letter ); ?>" data-slms-grade-fail-input>
												<span data-slms-grade-fail-preview><?php echo esc_html( $fail_letter ); ?></span>
											<?php else : ?>
												<input type="text" class="small-text slms-grade-band-letter" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[grading_scale_rows][<?php echo esc_attr( $slot ); ?>][label]" value="<?php echo esc_attr( $label ); ?>">
											<?php endif; ?>
										</td>
										<td><input type="number" step="0.01" min="0" max="100" class="small-text slms-grade-band-input" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[grading_scale_rows][<?php echo esc_attr( $slot ); ?>][min]" value="<?php echo esc_attr( number_format( (float) ( $row_value['min'] ?? 0 ), 2, '.', '' ) ); ?>"></td>
										<td><input type="number" step="0.01" min="0" max="100" class="small-text slms-grade-band-input" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[grading_scale_rows][<?php echo esc_attr( $slot ); ?>][max]" value="<?php echo esc_attr( number_format( (float) ( $row_value['max'] ?? 0 ), 2, '.', '' ) ); ?>"></td>
										<td><input type="number" step="0.01" min="0" class="small-text slms-grade-band-input" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[grading_scale_rows][<?php echo esc_attr( $slot ); ?>][points]" value="<?php echo '' !== (string) ( $row_value['points'] ?? '' ) ? esc_attr( number_format( (float) $row_value['points'], 2, '.', '' ) ) : ''; ?>"></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
					<p class="description"><?php esc_html_e( 'Use descending bands from highest to lowest. The fail band is always the last row and starts at 0.', 'simple-lms' ); ?></p>
				</td>
			</tr>
		</table>
		<script>
			document.addEventListener('DOMContentLoaded', function () {
				var modeSelect = document.querySelector('[data-slms-grade-mode]');
				var failSelect = document.querySelector('[data-slms-grade-fail-letter]');
				var failPreview = document.querySelector('[data-slms-grade-fail-preview]');
				var failInput = document.querySelector('[data-slms-grade-fail-input]');

				function refreshRows() {
					var mode = modeSelect ? modeSelect.value : '<?php echo esc_js( GradeScaleService::MODE_PLUS_MINUS ); ?>';

					document.querySelectorAll('[data-slms-grade-band-row]').forEach(function (row) {
						var rowMode = row.getAttribute('data-slms-grade-mode-row');
						row.hidden = 'plus_minus' !== mode && 'plus_minus' === rowMode;
					});
				}

				function refreshFailLabel() {
					var value = failSelect ? failSelect.value : 'F';
					if (failPreview) {
						failPreview.textContent = value;
					}
					if (failInput) {
						failInput.value = value;
					}
				}

				if (modeSelect) {
					modeSelect.addEventListener('change', refreshRows);
				}

				if (failSelect) {
					failSelect.addEventListener('change', refreshFailLabel);
				}

				refreshRows();
				refreshFailLabel();
			});
		</script>
		<?php
	}

	public function handle_settings_updated( $old_value, $new_value, $option ) {
		$this->audit_logger->log(
			'settings_updated',
			array(
				'level'       => 'info',
				'object_type' => 'settings',
				'message'     => 'Simple LMS settings were updated.',
				'context'     => array(
					'old_value' => is_array( $old_value ) ? $this->redact_sensitive_settings( $old_value ) : array(),
					'new_value' => is_array( $new_value ) ? $this->redact_sensitive_settings( $new_value ) : array(),
					'option'    => $option,
				),
			)
		);
	}

	private function redact_sensitive_settings( array $settings ) {
		foreach ( array( 'smtp_password' ) as $key ) {
			if ( array_key_exists( $key, $settings ) ) {
				$settings[ $key ] = '' === (string) $settings[ $key ] ? '' : '[redacted]';
			}
		}

		return $settings;
	}

	public static function defaults() {
		$timezone = self::ACADEMIC_TIMEZONE;

		return array(
			'institution_name'           => get_bloginfo( 'name' ),
			'institution_code'           => 'UGP',
			'academic_timezone'          => $timezone,
			'notification_email'         => get_option( 'admin_email' ),
			'leave_registrar_email'      => get_option( 'admin_email' ),
			'leave_academic_coordinator_email' => '',
			'enable_email_notifications' => 1,
			'enable_rest_api'            => 1,
			'enable_audit_log'           => 1,
			'enable_dashboard_redirect'  => 1,
			'send_welcome_emails'        => 1,
			'student_code_prefix'        => 'S',
			'staff_code_prefix'          => 'F',
			'notification_batch_size'    => 20,
			'smtp_enabled'               => 0,
			'smtp_host'                  => '',
			'smtp_port'                  => 587,
			'smtp_encryption'            => 'tls',
			'smtp_auth'                  => 1,
			'smtp_username'              => '',
			'smtp_password'              => '',
			'smtp_from_email'            => 'support@ugp.edu.mm',
			'smtp_from_name'             => get_bloginfo( 'name' ),
			'grading_scale_configured'   => 0,
			'grading_system_mode'        => GradeScaleService::MODE_PLUS_MINUS,
			'grading_fail_letter'        => GradeScaleService::FAIL_F,
			'grading_points_enabled'     => 1,
			'grading_scale_rows'         => GradeScaleService::build_default_rows( GradeScaleService::MODE_PLUS_MINUS, GradeScaleService::FAIL_F ),
		);
	}
}
