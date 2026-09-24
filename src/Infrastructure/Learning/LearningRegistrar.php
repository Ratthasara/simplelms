<?php

namespace SimpleLMS\Infrastructure\Learning;

use SimpleLMS\Domain\Academic\EnrollmentService;
use SimpleLMS\Domain\Users\RoleManager;
use SimpleLMS\Infrastructure\Logging\AuditLogger;

defined( 'ABSPATH' ) || exit;

class LearningRegistrar {
	/**
	 * @var EnrollmentService
	 */
	private $enrollments;

	/**
	 * @var AuditLogger
	 */
	private $audit_logger;

	public function __construct( EnrollmentService $enrollments, AuditLogger $audit_logger ) {
		$this->enrollments = $enrollments;
		$this->audit_logger = $audit_logger;
	}

	public function register_hooks() {
		add_action( 'init', array( $this, 'register_learning_content' ) );
		add_action( 'add_meta_boxes', array( $this, 'register_meta_boxes' ) );
		add_action( 'save_post_slms_lesson', array( $this, 'save_lesson_meta' ), 10, 2 );
		add_action( 'save_post_slms_announcement', array( $this, 'save_announcement_meta' ), 10, 2 );
	}

	public function register_learning_content() {
		$lesson_caps       = RoleManager::custom_post_type_args( 'slms_lesson', 'slms_lessons' );
		$announcement_caps = RoleManager::custom_post_type_args( 'slms_announcement', 'slms_announcements' );

		register_post_type(
			'slms_lesson',
			array_merge(
				array(
					'labels' => array(
						'name'               => __( 'Lessons', 'simple-lms' ),
						'singular_name'      => __( 'Lesson', 'simple-lms' ),
						'add_new_item'       => __( 'Add New Lesson', 'simple-lms' ),
						'edit_item'          => __( 'Edit Lesson', 'simple-lms' ),
						'new_item'           => __( 'New Lesson', 'simple-lms' ),
						'view_item'          => __( 'View Lesson', 'simple-lms' ),
						'search_items'       => __( 'Search Lessons', 'simple-lms' ),
						'not_found'          => __( 'No lessons found.', 'simple-lms' ),
						'not_found_in_trash' => __( 'No lessons found in Trash.', 'simple-lms' ),
					),
					'public'              => false,
					'show_ui'             => true,
					'show_in_menu'        => 'slms-dashboard',
					'show_in_rest'        => true,
					'supports'            => array( 'title', 'editor', 'excerpt', 'author', 'revisions' ),
					'has_archive'         => false,
					'exclude_from_search' => true,
				),
				$lesson_caps
			)
		);

		register_post_type(
			'slms_announcement',
			array_merge(
				array(
					'labels' => array(
						'name'               => __( 'Announcements', 'simple-lms' ),
						'singular_name'      => __( 'Announcement', 'simple-lms' ),
						'add_new_item'       => __( 'Add New Announcement', 'simple-lms' ),
						'edit_item'          => __( 'Edit Announcement', 'simple-lms' ),
						'new_item'           => __( 'New Announcement', 'simple-lms' ),
						'view_item'          => __( 'View Announcement', 'simple-lms' ),
						'search_items'       => __( 'Search Announcements', 'simple-lms' ),
						'not_found'          => __( 'No announcements found.', 'simple-lms' ),
						'not_found_in_trash' => __( 'No announcements found in Trash.', 'simple-lms' ),
					),
					'public'              => false,
					'show_ui'             => true,
					'show_in_menu'        => 'slms-dashboard',
					'show_in_rest'        => true,
					'supports'            => array( 'title', 'editor', 'excerpt', 'author', 'revisions' ),
					'has_archive'         => false,
					'exclude_from_search' => true,
				),
				$announcement_caps
			)
		);

		$this->register_meta();
	}

	public function register_meta_boxes() {
		add_meta_box(
			'slms_lesson_details',
			__( 'Lesson Details', 'simple-lms' ),
			array( $this, 'render_lesson_meta_box' ),
			'slms_lesson',
			'side',
			'high'
		);

		add_meta_box(
			'slms_announcement_details',
			__( 'Announcement Details', 'simple-lms' ),
			array( $this, 'render_announcement_meta_box' ),
			'slms_announcement',
			'side',
			'high'
		);
	}

	public function render_lesson_meta_box( $post ) {
		wp_nonce_field( 'slms_save_lesson_meta', 'slms_lesson_meta_nonce' );

		$section_id   = absint( get_post_meta( $post->ID, '_slms_section_id', true ) );
		$lesson_kind  = get_post_meta( $post->ID, '_slms_lesson_kind', true ) ?: 'lesson';
		$visibility   = get_post_meta( $post->ID, '_slms_lesson_visibility', true ) ?: 'students';
		$available_at = get_post_meta( $post->ID, '_slms_available_from', true );
		$week_number  = absint( get_post_meta( $post->ID, '_slms_week_number', true ) );
		$week_order   = absint( get_post_meta( $post->ID, '_slms_week_order', true ) );
		$sections     = $this->get_available_sections_for_current_user();
		?>
		<p>
			<label for="slms_lesson_section_id"><?php esc_html_e( 'Section', 'simple-lms' ); ?></label><br>
			<select id="slms_lesson_section_id" name="slms_section_id" style="width:100%;">
				<option value=""><?php esc_html_e( 'Select a section', 'simple-lms' ); ?></option>
				<?php foreach ( $sections as $section ) : ?>
					<option value="<?php echo esc_attr( $section['id'] ); ?>" <?php selected( $section_id, (int) $section['id'] ); ?>>
						<?php echo esc_html( $section['title'] . ( $section['subject_title'] ? ' - ' . $section['subject_title'] : '' ) ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label for="slms_lesson_kind"><?php esc_html_e( 'Kind', 'simple-lms' ); ?></label><br>
			<select id="slms_lesson_kind" name="slms_lesson_kind" style="width:100%;">
				<option value="lesson" <?php selected( $lesson_kind, 'lesson' ); ?>><?php esc_html_e( 'Lesson', 'simple-lms' ); ?></option>
				<option value="resource" <?php selected( $lesson_kind, 'resource' ); ?>><?php esc_html_e( 'Resource', 'simple-lms' ); ?></option>
				<option value="video" <?php selected( $lesson_kind, 'video' ); ?>><?php esc_html_e( 'Video', 'simple-lms' ); ?></option>
			</select>
		</p>
		<p>
			<label for="slms_lesson_visibility"><?php esc_html_e( 'Visibility', 'simple-lms' ); ?></label><br>
			<select id="slms_lesson_visibility" name="slms_lesson_visibility" style="width:100%;">
				<option value="students" <?php selected( $visibility, 'students' ); ?>><?php esc_html_e( 'Students', 'simple-lms' ); ?></option>
				<option value="staff" <?php selected( $visibility, 'staff' ); ?>><?php esc_html_e( 'Staff Only', 'simple-lms' ); ?></option>
				<option value="all" <?php selected( $visibility, 'all' ); ?>><?php esc_html_e( 'All Enrolled Users', 'simple-lms' ); ?></option>
			</select>
		</p>
		<p>
			<label for="slms_available_from"><?php esc_html_e( 'Available From', 'simple-lms' ); ?></label><br>
			<input type="datetime-local" id="slms_available_from" name="slms_available_from" value="<?php echo esc_attr( $available_at ); ?>" style="width:100%;">
		</p>
		<?php $this->render_week_assignment_fields( $section_id, $week_number, $week_order ); ?>
		<?php
	}

	public function render_announcement_meta_box( $post ) {
		wp_nonce_field( 'slms_save_announcement_meta', 'slms_announcement_meta_nonce' );

		$section_id = absint( get_post_meta( $post->ID, '_slms_section_id', true ) );
		$scope      = get_post_meta( $post->ID, '_slms_announcement_scope', true ) ?: 'section';
		$pinned     = (int) get_post_meta( $post->ID, '_slms_pinned', true );
		$sections   = $this->get_available_sections_for_current_user();
		$can_global = current_user_can( RoleManager::CAP_MANAGE_ACADEMICS ) || current_user_can( RoleManager::CAP_MANAGE_COMMUNICATIONS );
		?>
		<p>
			<label for="slms_announcement_scope"><?php esc_html_e( 'Scope', 'simple-lms' ); ?></label><br>
			<select id="slms_announcement_scope" name="slms_announcement_scope" style="width:100%;">
				<option value="section" <?php selected( $scope, 'section' ); ?>><?php esc_html_e( 'Section Announcement', 'simple-lms' ); ?></option>
				<?php if ( $can_global ) : ?>
					<option value="global" <?php selected( $scope, 'global' ); ?>><?php esc_html_e( 'Global Announcement', 'simple-lms' ); ?></option>
				<?php endif; ?>
			</select>
		</p>
		<p>
			<label for="slms_announcement_section_id"><?php esc_html_e( 'Section', 'simple-lms' ); ?></label><br>
			<select id="slms_announcement_section_id" name="slms_section_id" style="width:100%;">
				<option value=""><?php esc_html_e( 'Select a section', 'simple-lms' ); ?></option>
				<?php foreach ( $sections as $section ) : ?>
					<option value="<?php echo esc_attr( $section['id'] ); ?>" <?php selected( $section_id, (int) $section['id'] ); ?>>
						<?php echo esc_html( $section['title'] . ( $section['subject_title'] ? ' - ' . $section['subject_title'] : '' ) ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label><input type="checkbox" name="slms_pinned" value="1" <?php checked( $pinned, 1 ); ?>> <?php esc_html_e( 'Pin this announcement in the workspace', 'simple-lms' ); ?></label>
		</p>
		<?php
	}

	public function save_lesson_meta( $post_id, $post ) {
		if ( ! $this->can_save_post_meta( $post_id, 'slms_lesson_meta_nonce', 'slms_save_lesson_meta' ) ) {
			return;
		}

		$section_id   = absint( $_POST['slms_section_id'] ?? 0 );
		$lesson_kind  = sanitize_key( $_POST['slms_lesson_kind'] ?? 'lesson' );
		$visibility   = sanitize_key( $_POST['slms_lesson_visibility'] ?? 'students' );
		$available_at = sanitize_text_field( $_POST['slms_available_from'] ?? '' );

		if ( ! in_array( $lesson_kind, array( 'lesson', 'resource', 'video' ), true ) ) {
			$lesson_kind = 'lesson';
		}

		if ( ! in_array( $visibility, array( 'students', 'staff', 'all' ), true ) ) {
			$visibility = 'students';
		}

		if ( ! $this->current_user_can_reference_section( $section_id ) ) {
			$section_id = 0;
		}

		update_post_meta( $post_id, '_slms_section_id', $section_id );
		update_post_meta( $post_id, '_slms_lesson_kind', $lesson_kind );
		update_post_meta( $post_id, '_slms_lesson_visibility', $visibility );
		update_post_meta( $post_id, '_slms_available_from', $available_at ?: null );

		if ( array_key_exists( 'slms_week_number', $_POST ) ) {
			update_post_meta( $post_id, '_slms_week_number', absint( wp_unslash( $_POST['slms_week_number'] ) ) );
		}

		if ( array_key_exists( 'slms_week_order', $_POST ) ) {
			update_post_meta( $post_id, '_slms_week_order', absint( wp_unslash( $_POST['slms_week_order'] ) ) );
		}

		$this->audit_logger->log(
			'lesson_saved',
			array(
				'object_type' => 'lesson',
				'object_id'   => $post_id,
				'message'     => sprintf( 'Lesson "%s" was saved.', $post->post_title ),
				'context'     => array(
					'section_id'   => $section_id,
					'lesson_kind'  => $lesson_kind,
					'visibility'   => $visibility,
					'week_number'  => array_key_exists( 'slms_week_number', $_POST ) ? absint( wp_unslash( $_POST['slms_week_number'] ) ) : 0,
					'week_order'   => array_key_exists( 'slms_week_order', $_POST ) ? absint( wp_unslash( $_POST['slms_week_order'] ) ) : 0,
				),
			)
		);
	}

	public function save_announcement_meta( $post_id, $post ) {
		if ( ! $this->can_save_post_meta( $post_id, 'slms_announcement_meta_nonce', 'slms_save_announcement_meta' ) ) {
			return;
		}

		$scope      = sanitize_key( $_POST['slms_announcement_scope'] ?? 'section' );
		$section_id = absint( $_POST['slms_section_id'] ?? 0 );
		$pinned     = ! empty( $_POST['slms_pinned'] ) ? 1 : 0;

		if ( ! in_array( $scope, array( 'section', 'global' ), true ) ) {
			$scope = 'section';
		}

		if ( ! current_user_can( RoleManager::CAP_MANAGE_ACADEMICS ) && ! current_user_can( RoleManager::CAP_MANAGE_OWN_SECTIONS ) ) {
			$scope      = 'global';
			$section_id = 0;
		}

		if ( 'global' !== $scope && ! $this->current_user_can_reference_section( $section_id ) ) {
			$section_id = 0;
		}

		update_post_meta( $post_id, '_slms_section_id', 'global' === $scope ? 0 : $section_id );
		update_post_meta( $post_id, '_slms_announcement_scope', $scope );
		update_post_meta( $post_id, '_slms_pinned', $pinned );

		$this->audit_logger->log(
			'announcement_saved',
			array(
				'object_type' => 'announcement',
				'object_id'   => $post_id,
				'message'     => sprintf( 'Announcement "%s" was saved.', $post->post_title ),
				'context'     => array(
					'section_id' => $section_id,
					'scope'      => $scope,
					'pinned'     => $pinned,
				),
			)
		);
	}

	private function register_meta() {
		$meta = array(
			'_slms_section_id'          => 'absint',
			'_slms_lesson_kind'         => 'sanitize_key',
			'_slms_lesson_visibility'   => 'sanitize_key',
			'_slms_available_from'      => 'sanitize_text_field',
			'_slms_announcement_scope'  => 'sanitize_key',
			'_slms_pinned'              => 'absint',
		);

		foreach ( array( 'slms_lesson', 'slms_announcement' ) as $post_type ) {
			foreach ( $meta as $key => $sanitizer ) {
				register_post_meta(
					$post_type,
					$key,
					array(
						'show_in_rest'      => true,
						'single'            => true,
						'type'              => 'absint' === $sanitizer ? 'integer' : 'string',
						'auth_callback'     => array( $this, 'can_manage_learning' ),
						'sanitize_callback' => $sanitizer,
					)
				);
			}
		}

		foreach ( array( '_slms_week_number', '_slms_week_order' ) as $key ) {
			register_post_meta(
				'slms_lesson',
				$key,
				array(
					'show_in_rest'      => true,
					'single'            => true,
					'type'              => 'integer',
					'auth_callback'     => array( $this, 'can_manage_learning' ),
					'sanitize_callback' => 'absint',
				)
			);
		}

		register_post_meta(
			'slms_lesson',
			'_slms_material_embed_url',
			array(
				'show_in_rest'      => true,
				'single'            => true,
				'type'              => 'string',
				'auth_callback'     => array( $this, 'can_manage_learning' ),
				'sanitize_callback' => 'esc_url_raw',
			)
		);

		register_post_meta(
			'slms_lesson',
			'_slms_material_embed_urls',
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
				'auth_callback'     => array( $this, 'can_manage_learning' ),
				'sanitize_callback' => array( $this, 'sanitize_embed_urls' ),
			)
		);
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

	private function get_available_sections_for_current_user() {
		return $this->enrollments->get_teaching_sections_for_user( get_current_user_id() );
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
			<span class="description"><?php esc_html_e( 'Use 0 or leave blank to keep this lesson in General.', 'simple-lms' ); ?></span>
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

	private function current_user_can_reference_section( $section_id ) {
		return $section_id && $this->enrollments->user_can_teach_section( get_current_user_id(), $section_id );
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

	public function can_manage_learning() {
		return current_user_can( RoleManager::CAP_MANAGE_ACADEMICS ) || current_user_can( RoleManager::CAP_MANAGE_OWN_SECTIONS ) || current_user_can( RoleManager::CAP_MANAGE_COMMUNICATIONS );
	}
}
