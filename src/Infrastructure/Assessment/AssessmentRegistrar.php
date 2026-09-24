<?php

namespace SimpleLMS\Infrastructure\Assessment;

use SimpleLMS\Domain\Academic\EnrollmentService;
use SimpleLMS\Domain\Assessment\AssignmentLatePolicy;
use SimpleLMS\Domain\Assessment\AssignmentPointPolicy;
use SimpleLMS\Domain\Users\RoleManager;
use SimpleLMS\Infrastructure\Logging\AuditLogger;

defined( 'ABSPATH' ) || exit;

class AssessmentRegistrar {
	/**
	 * @var EnrollmentService
	 */
	private $enrollments;

	/**
	 * @var AuditLogger
	 */
	private $audit_logger;

	public function __construct( EnrollmentService $enrollments, AuditLogger $audit_logger ) {
		$this->enrollments  = $enrollments;
		$this->audit_logger = $audit_logger;
	}

	public function register_hooks() {
		add_action( 'init', array( $this, 'register_assessment_content' ) );
		add_action( 'add_meta_boxes', array( $this, 'register_meta_boxes' ) );
		add_action( 'save_post_slms_assignment', array( $this, 'save_assignment_meta' ), 10, 2 );
	}

	public function register_assessment_content() {
		$assignment_caps = RoleManager::custom_post_type_args( 'slms_assignment', 'slms_assignments' );

		register_post_type(
			'slms_assignment',
			array_merge(
				array(
					'labels' => array(
						'name'               => __( 'Assignments', 'simple-lms' ),
						'singular_name'      => __( 'Assignment', 'simple-lms' ),
						'add_new_item'       => __( 'Add New Assignment', 'simple-lms' ),
						'edit_item'          => __( 'Edit Assignment', 'simple-lms' ),
						'new_item'           => __( 'New Assignment', 'simple-lms' ),
						'view_item'          => __( 'View Assignment', 'simple-lms' ),
						'search_items'       => __( 'Search Assignments', 'simple-lms' ),
						'not_found'          => __( 'No assignments found.', 'simple-lms' ),
						'not_found_in_trash' => __( 'No assignments found in Trash.', 'simple-lms' ),
					),
					'public'              => false,
					'show_ui'             => true,
					'show_in_menu'        => 'slms-dashboard',
					'show_in_rest'        => true,
					'supports'            => array( 'title', 'editor', 'excerpt', 'author', 'revisions' ),
					'has_archive'         => false,
					'exclude_from_search' => true,
				),
				$assignment_caps
			)
		);

		$this->register_meta();
	}

	public function register_meta_boxes() {
		add_meta_box(
			'slms_assignment_details',
			__( 'Assignment Details', 'simple-lms' ),
			array( $this, 'render_assignment_meta_box' ),
			'slms_assignment',
			'side',
			'high'
		);
	}

	public function render_assignment_meta_box( $post ) {
		wp_nonce_field( 'slms_save_assignment_meta', 'slms_assignment_meta_nonce' );

		$section_id      = absint( get_post_meta( $post->ID, '_slms_section_id', true ) );
		$due_at          = AssignmentLatePolicy::format_datetime_local_value( get_post_meta( $post->ID, '_slms_due_at', true ) );
		$points          = get_post_meta( $post->ID, '_slms_points', true );
		$submission_type = get_post_meta( $post->ID, '_slms_submission_type', true ) ?: 'text_file';
		$allow_late      = (int) get_post_meta( $post->ID, '_slms_allow_late', true );
		$max_attempts    = absint( get_post_meta( $post->ID, '_slms_max_attempts', true ) );
		$week_number     = absint( get_post_meta( $post->ID, '_slms_week_number', true ) );
		$week_order      = absint( get_post_meta( $post->ID, '_slms_week_order', true ) );
		$sections        = $this->enrollments->get_teaching_sections_for_user( get_current_user_id() );
		?>
		<p>
			<label for="slms_assignment_section_id"><?php esc_html_e( 'Section', 'simple-lms' ); ?></label><br>
			<select id="slms_assignment_section_id" name="slms_section_id" style="width:100%;">
				<option value=""><?php esc_html_e( 'Select a section', 'simple-lms' ); ?></option>
				<?php foreach ( $sections as $section ) : ?>
					<option value="<?php echo esc_attr( $section['id'] ); ?>" <?php selected( $section_id, (int) $section['id'] ); ?>>
						<?php echo esc_html( $section['title'] . ( $section['subject_title'] ? ' - ' . $section['subject_title'] : '' ) ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label for="slms_assignment_due_at"><?php esc_html_e( 'Due At', 'simple-lms' ); ?></label><br>
			<input id="slms_assignment_due_at" type="datetime-local" name="slms_due_at" value="<?php echo esc_attr( $due_at ); ?>" style="width:100%;">
		</p>
		<p>
			<label for="slms_assignment_points"><?php esc_html_e( 'Points', 'simple-lms' ); ?></label><br>
			<input id="slms_assignment_points" type="number" step="1" min="0" name="slms_points" value="<?php echo esc_attr( AssignmentPointPolicy::normalize_max_points( $points ) ); ?>" style="width:100%;">
		</p>
		<p>
			<label for="slms_submission_type"><?php esc_html_e( 'Submission Type', 'simple-lms' ); ?></label><br>
			<select id="slms_submission_type" name="slms_submission_type" style="width:100%;">
				<option value="text" <?php selected( $submission_type, 'text' ); ?>><?php esc_html_e( 'Text Response', 'simple-lms' ); ?></option>
				<option value="file" <?php selected( $submission_type, 'file' ); ?>><?php esc_html_e( 'File Upload', 'simple-lms' ); ?></option>
				<option value="text_file" <?php selected( $submission_type, 'text_file' ); ?>><?php esc_html_e( 'Text or File', 'simple-lms' ); ?></option>
			</select>
		</p>
		<p>
			<label><input type="checkbox" name="slms_allow_late" value="1" <?php checked( $allow_late, 1 ); ?>> <?php esc_html_e( 'Allow late submissions', 'simple-lms' ); ?></label>
		</p>
		<p>
			<label for="slms_max_attempts"><?php esc_html_e( 'Max Attempts', 'simple-lms' ); ?></label><br>
			<input id="slms_max_attempts" type="number" min="0" name="slms_max_attempts" value="<?php echo esc_attr( $max_attempts ); ?>" style="width:100%;">
			<span class="description"><?php esc_html_e( 'Use 0 for unlimited resubmissions.', 'simple-lms' ); ?></span>
		</p>
		<?php $this->render_week_assignment_fields( $section_id, $week_number, $week_order ); ?>
		<?php
	}

	public function save_assignment_meta( $post_id, $post ) {
		if ( ! $this->can_save_post_meta( $post_id, 'slms_assignment_meta_nonce', 'slms_save_assignment_meta' ) ) {
			return;
		}

		$section_id      = absint( $_POST['slms_section_id'] ?? 0 );
		$due_at          = AssignmentLatePolicy::normalize_datetime( sanitize_text_field( $_POST['slms_due_at'] ?? '' ) );
		$points          = AssignmentPointPolicy::normalize_max_points( $_POST['slms_points'] ?? 0 );
		$submission_type = sanitize_key( $_POST['slms_submission_type'] ?? 'text_file' );
		$allow_late      = ! empty( $_POST['slms_allow_late'] ) ? 1 : 0;
		$max_attempts    = absint( $_POST['slms_max_attempts'] ?? 0 );

		if ( ! in_array( $submission_type, array( 'text', 'file', 'text_file' ), true ) ) {
			$submission_type = 'text_file';
		}

		if ( ! $this->enrollments->user_can_teach_section( get_current_user_id(), $section_id ) && ! current_user_can( RoleManager::CAP_MANAGE_ACADEMICS ) ) {
			$section_id = 0;
		}

		update_post_meta( $post_id, '_slms_section_id', $section_id );
		update_post_meta( $post_id, '_slms_due_at', $due_at ?: null );
		update_post_meta( $post_id, '_slms_points', $points );
		update_post_meta( $post_id, '_slms_submission_type', $submission_type );
		update_post_meta( $post_id, '_slms_allow_late', $allow_late );
		update_post_meta( $post_id, '_slms_max_attempts', $max_attempts );

		if ( array_key_exists( 'slms_week_number', $_POST ) ) {
			update_post_meta( $post_id, '_slms_week_number', absint( wp_unslash( $_POST['slms_week_number'] ) ) );
		}

		if ( array_key_exists( 'slms_week_order', $_POST ) ) {
			update_post_meta( $post_id, '_slms_week_order', absint( wp_unslash( $_POST['slms_week_order'] ) ) );
		}

		$this->audit_logger->log(
			'assignment_saved',
			array(
				'object_type' => 'assignment',
				'object_id'   => $post_id,
				'message'     => sprintf( 'Assignment "%s" was saved.', $post->post_title ),
				'context'     => array(
					'section_id'      => $section_id,
					'due_at'          => $due_at,
					'points'          => $points,
					'submission_type' => $submission_type,
					'allow_late'      => $allow_late,
					'max_attempts'    => $max_attempts,
					'week_number'     => array_key_exists( 'slms_week_number', $_POST ) ? absint( wp_unslash( $_POST['slms_week_number'] ) ) : 0,
					'week_order'      => array_key_exists( 'slms_week_order', $_POST ) ? absint( wp_unslash( $_POST['slms_week_order'] ) ) : 0,
				),
			)
		);
	}

	private function register_meta() {
		$meta = array(
			'_slms_section_id'       => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
			'_slms_due_at'           => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
			'_slms_points'           => array( 'type' => 'number', 'sanitize_callback' => array( $this, 'sanitize_points' ) ),
			'_slms_submission_type'  => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
			'_slms_allow_late'       => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
			'_slms_max_attempts'     => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
			'_slms_week_number'      => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
			'_slms_week_order'       => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
		);

		foreach ( $meta as $key => $args ) {
			register_post_meta(
				'slms_assignment',
				$key,
				array(
					'show_in_rest'      => true,
					'single'            => true,
					'type'              => $args['type'],
					'auth_callback'     => array( $this, 'can_manage_assignments' ),
					'sanitize_callback' => $args['sanitize_callback'],
				)
			);
		}

		register_post_meta(
			'slms_assignment',
			'_slms_assignment_embed_url',
			array(
				'show_in_rest'      => true,
				'single'            => true,
				'type'              => 'string',
				'auth_callback'     => array( $this, 'can_manage_assignments' ),
				'sanitize_callback' => 'esc_url_raw',
			)
		);

		register_post_meta(
			'slms_assignment',
			'_slms_assignment_embed_urls',
			array(
				'show_in_rest'      => array(
					'schema' => array(
						'type'  => 'array',
						'items' => array(
							'type'   => 'string',
							'format' => 'uri',
						),
					),
				),
				'single'            => true,
				'type'              => 'array',
				'default'           => array(),
				'auth_callback'     => array( $this, 'can_manage_assignments' ),
				'sanitize_callback' => array( $this, 'sanitize_embed_urls' ),
			)
		);
	}

	public function sanitize_points( $value ) {
		return AssignmentPointPolicy::normalize_max_points( $value );
	}

	public function sanitize_embed_urls( $values ) {
		$urls = array();

		foreach ( is_array( $values ) ? $values : array() as $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}

			$url = esc_url_raw( trim( (string) $value ), array( 'http', 'https' ) );

			if ( $url && wp_parse_url( $url, PHP_URL_HOST ) && ! in_array( $url, $urls, true ) ) {
				$urls[] = $url;
			}

			if ( count( $urls ) >= 10 ) {
				break;
			}
		}

		return $urls;
	}

	public function can_manage_assignments() {
		return current_user_can( RoleManager::CAP_MANAGE_ACADEMICS ) || current_user_can( RoleManager::CAP_MANAGE_OWN_SECTIONS );
	}

	private function render_week_assignment_fields( $workspace_id, $week_number = 0, $week_order = 0 ) {
		$week_number  = absint( $week_number );
		$week_order   = absint( $week_order );
		$defined_weeks = $this->get_workspace_week_structure( $workspace_id );
		$has_defined   = ! empty( $defined_weeks );
		$has_current   = false;

		if ( $has_defined ) {
			foreach ( $defined_weeks as $defined_week ) {
				if ( $week_number === (int) $defined_week['week_number'] ) {
					$has_current = true;
					break;
				}
			}
		}
		?>
		<p>
			<label for="slms_week_number"><?php echo esc_html( $has_defined ? __( 'Week', 'simple-lms' ) : __( 'Week Number', 'simple-lms' ) ); ?></label><br>
			<?php if ( $has_defined ) : ?>
				<select id="slms_week_number" name="slms_week_number" style="width:100%;">
					<option value="0"><?php esc_html_e( 'General', 'simple-lms' ); ?></option>
					<?php foreach ( $defined_weeks as $defined_week ) : ?>
						<option value="<?php echo esc_attr( (string) $defined_week['week_number'] ); ?>" <?php selected( $week_number, (int) $defined_week['week_number'] ); ?>>
							<?php echo esc_html( $defined_week['label'] ); ?>
						</option>
					<?php endforeach; ?>
					<?php if ( $week_number && ! $has_current ) : ?>
						<option value="<?php echo esc_attr( (string) $week_number ); ?>" selected>
							<?php echo esc_html( sprintf( __( 'Week %d', 'simple-lms' ), $week_number ) ); ?>
						</option>
					<?php endif; ?>
				</select>
			<?php else : ?>
				<input type="number" id="slms_week_number" name="slms_week_number" min="0" value="<?php echo esc_attr( $week_number ? (string) $week_number : '' ); ?>" style="width:100%;" placeholder="0">
			<?php endif; ?>
			<span class="description"><?php esc_html_e( 'Use 0 or leave blank to keep this assignment in General.', 'simple-lms' ); ?></span>
		</p>
		<p>
			<label for="slms_week_order"><?php esc_html_e( 'Order In Week', 'simple-lms' ); ?></label><br>
			<input type="number" id="slms_week_order" name="slms_week_order" min="0" value="<?php echo esc_attr( $week_order ? (string) $week_order : '' ); ?>" style="width:100%;" placeholder="0">
			<span class="description"><?php esc_html_e( 'Lower numbers appear first within the same week.', 'simple-lms' ); ?></span>
		</p>
		<?php
	}

	private function get_workspace_week_structure( $workspace_id ) {
		$workspace_id = absint( $workspace_id );

		if ( ! $workspace_id ) {
			return array();
		}

		return $this->normalize_week_structure( get_post_meta( $workspace_id, '_slms_week_structure', true ) );
	}

	private function normalize_week_structure( $value ) {
		if ( empty( $value ) ) {
			return array();
		}

		if ( is_string( $value ) ) {
			$decoded = json_decode( $value, true );

			if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
				$value = $decoded;
			} else {
				$value = maybe_unserialize( $value );
			}
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$weeks           = array();
		$seen_numbers    = array();
		$fallback_number = 1;

		foreach ( $value as $row ) {
			$row = is_array( $row ) ? $row : array(
				'label' => is_scalar( $row ) ? (string) $row : '',
			);

			$week_number = absint( $row['week_number'] ?? $row['number'] ?? $row['week'] ?? 0 );
			$label       = sanitize_text_field( (string) ( $row['label'] ?? $row['title'] ?? $row['name'] ?? '' ) );

			if ( ! $week_number ) {
				$week_number = $fallback_number;
			}

			$fallback_number = max( $fallback_number, $week_number + 1 );

			if ( isset( $seen_numbers[ $week_number ] ) ) {
				continue;
			}

			$seen_numbers[ $week_number ] = true;

			if ( '' === $label ) {
				$label = sprintf( __( 'Week %d', 'simple-lms' ), $week_number );
			}

			$weeks[] = array(
				'week_number' => $week_number,
				'label'       => $label,
			);
		}

		usort(
			$weeks,
			static function ( $left, $right ) {
				return (int) $left['week_number'] <=> (int) $right['week_number'];
			}
		);

		return array_values( $weeks );
	}

	private function can_save_post_meta( $post_id, $nonce_key, $nonce_action ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return false;
		}

		if ( wp_is_post_revision( $post_id ) ) {
			return false;
		}

		if ( empty( $_POST[ $nonce_key ] ) ) {
			return false;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ $nonce_key ] ) ), $nonce_action ) ) {
			return false;
		}

		return current_user_can( 'edit_post', $post_id );
	}
}
