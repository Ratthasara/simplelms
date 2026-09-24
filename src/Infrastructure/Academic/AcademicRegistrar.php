<?php

namespace SimpleLMS\Infrastructure\Academic;

use SimpleLMS\Domain\Academic\EnrollmentService;
use SimpleLMS\Domain\Academic\TermService;
use SimpleLMS\Domain\Users\RoleManager;
use SimpleLMS\Infrastructure\Logging\AuditLogger;

defined( 'ABSPATH' ) || exit;

class AcademicRegistrar {
	/**
	 * @var TermService
	 */
	private $terms;

	/**
	 * @var EnrollmentService
	 */
	private $enrollments;

	/**
	 * @var AuditLogger
	 */
	private $audit_logger;

	public function __construct( TermService $terms, EnrollmentService $enrollments, AuditLogger $audit_logger ) {
		$this->terms        = $terms;
		$this->enrollments  = $enrollments;
		$this->audit_logger = $audit_logger;
	}

	public function register_hooks() {
		add_action( 'init', array( $this, 'register_academic_content' ) );
		add_action( 'add_meta_boxes', array( $this, 'register_meta_boxes' ) );
		add_action( 'save_post_slms_subject', array( $this, 'save_subject_meta' ), 10, 2 );
		add_action( 'save_post_slms_section', array( $this, 'save_section_meta' ), 10, 2 );
	}

	public function register_academic_content() {
		$subject_caps = RoleManager::custom_post_type_args( 'slms_subject', 'slms_subjects' );
		$section_caps = RoleManager::custom_post_type_args( 'slms_section', 'slms_sections' );

		register_post_type(
			'slms_subject',
			array_merge(
				array(
					'labels' => array(
						'name'               => __( 'Subjects', 'simple-lms' ),
						'singular_name'      => __( 'Subject', 'simple-lms' ),
						'add_new_item'       => __( 'Add New Subject', 'simple-lms' ),
						'edit_item'          => __( 'Edit Subject', 'simple-lms' ),
						'new_item'           => __( 'New Subject', 'simple-lms' ),
						'view_item'          => __( 'View Subject', 'simple-lms' ),
						'search_items'       => __( 'Search Subjects', 'simple-lms' ),
						'not_found'          => __( 'No subjects found.', 'simple-lms' ),
						'not_found_in_trash' => __( 'No subjects found in Trash.', 'simple-lms' ),
					),
					'public'             => false,
					'show_ui'            => true,
					'show_in_menu'       => 'slms-dashboard',
					'show_in_rest'       => true,
					'menu_icon'          => 'dashicons-welcome-learn-more',
					'supports'           => array( 'title', 'editor', 'excerpt' ),
					'has_archive'        => false,
					'exclude_from_search'=> true,
				),
				$subject_caps
			)
		);

		register_post_type(
			'slms_section',
			array_merge(
				array(
					'labels' => array(
						'name'               => __( 'Sections', 'simple-lms' ),
						'singular_name'      => __( 'Section', 'simple-lms' ),
						'add_new_item'       => __( 'Add New Section', 'simple-lms' ),
						'edit_item'          => __( 'Edit Section', 'simple-lms' ),
						'new_item'           => __( 'New Section', 'simple-lms' ),
						'view_item'          => __( 'View Section', 'simple-lms' ),
						'search_items'       => __( 'Search Sections', 'simple-lms' ),
						'not_found'          => __( 'No sections found.', 'simple-lms' ),
						'not_found_in_trash' => __( 'No sections found in Trash.', 'simple-lms' ),
					),
					'public'              => false,
					'show_ui'             => true,
					'show_in_menu'        => 'slms-dashboard',
					'show_in_rest'        => true,
					'menu_icon'           => 'dashicons-groups',
					'supports'            => array( 'title', 'editor' ),
					'has_archive'         => false,
					'exclude_from_search' => true,
				),
				$section_caps
			)
		);

		register_taxonomy(
			'slms_program',
			array( 'slms_subject' ),
			array(
				'hierarchical'      => true,
				'labels'            => array(
					'name'          => __( 'Programs', 'simple-lms' ),
					'singular_name' => __( 'Program', 'simple-lms' ),
					'search_items'  => __( 'Search Programs', 'simple-lms' ),
					'all_items'     => __( 'All Programs', 'simple-lms' ),
					'edit_item'     => __( 'Edit Program', 'simple-lms' ),
					'update_item'   => __( 'Update Program', 'simple-lms' ),
					'add_new_item'  => __( 'Add New Program', 'simple-lms' ),
					'new_item_name' => __( 'New Program Name', 'simple-lms' ),
				),
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'capabilities'      => array(
					'manage_terms' => RoleManager::CAP_MANAGE_ACADEMICS,
					'edit_terms'   => RoleManager::CAP_MANAGE_ACADEMICS,
					'delete_terms' => RoleManager::CAP_MANAGE_ACADEMICS,
					'assign_terms' => RoleManager::CAP_MANAGE_ACADEMICS,
				),
			)
		);

		$this->register_meta();
	}

	public function register_meta_boxes() {
		add_meta_box(
			'slms_subject_details',
			__( 'Subject Details', 'simple-lms' ),
			array( $this, 'render_subject_meta_box' ),
			'slms_subject',
			'normal',
			'high'
		);

		add_meta_box(
			'slms_subject_staff',
			__( 'Assigned Lecturers', 'simple-lms' ),
			array( $this, 'render_subject_staff_meta_box' ),
			'slms_subject',
			'side',
			'default'
		);

		add_meta_box(
			'slms_section_details',
			__( 'Section Details', 'simple-lms' ),
			array( $this, 'render_section_details_meta_box' ),
			'slms_section',
			'normal',
			'high'
		);

		add_meta_box(
			'slms_section_staff',
			__( 'Assigned Lecturers', 'simple-lms' ),
			array( $this, 'render_section_staff_meta_box' ),
			'slms_section',
			'side',
			'default'
		);
	}

	public function render_subject_meta_box( $post ) {
		wp_nonce_field( 'slms_save_subject_meta', 'slms_subject_meta_nonce' );

		$subject_code   = get_post_meta( $post->ID, '_slms_subject_code', true );
		$credits        = get_post_meta( $post->ID, '_slms_credits', true );
		$subject_type   = get_post_meta( $post->ID, '_slms_subject_type', true ) ?: 'core';
		$subject_status = get_post_meta( $post->ID, '_slms_subject_status', true ) ?: 'active';
		$term_id        = absint( get_post_meta( $post->ID, '_slms_term_id', true ) );
		$capacity       = absint( get_post_meta( $post->ID, '_slms_capacity', true ) );
		$delivery_mode  = get_post_meta( $post->ID, '_slms_delivery_mode', true ) ?: 'onsite';
		$terms          = $this->terms->list_terms();
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="slms_subject_code"><?php esc_html_e( 'Subject Code', 'simple-lms' ); ?></label></th>
				<td><input type="text" id="slms_subject_code" name="slms_subject_code" class="regular-text" value="<?php echo esc_attr( $subject_code ); ?>" placeholder="e.g. BUD-501"></td>
			</tr>
			<tr>
				<th scope="row"><label for="slms_credits"><?php esc_html_e( 'Credits', 'simple-lms' ); ?></label></th>
				<td><input type="number" id="slms_credits" name="slms_credits" class="small-text" step="0.5" min="0" value="<?php echo esc_attr( $credits ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="slms_subject_type"><?php esc_html_e( 'Type', 'simple-lms' ); ?></label></th>
				<td>
					<select id="slms_subject_type" name="slms_subject_type">
						<option value="core" <?php selected( $subject_type, 'core' ); ?>><?php esc_html_e( 'Core', 'simple-lms' ); ?></option>
						<option value="elective" <?php selected( $subject_type, 'elective' ); ?>><?php esc_html_e( 'Elective', 'simple-lms' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="slms_subject_status"><?php esc_html_e( 'Catalog Status', 'simple-lms' ); ?></label></th>
				<td>
					<select id="slms_subject_status" name="slms_subject_status">
						<option value="active" <?php selected( $subject_status, 'active' ); ?>><?php esc_html_e( 'Active', 'simple-lms' ); ?></option>
						<option value="inactive" <?php selected( $subject_status, 'inactive' ); ?>><?php esc_html_e( 'Inactive', 'simple-lms' ); ?></option>
						<option value="archived" <?php selected( $subject_status, 'archived' ); ?>><?php esc_html_e( 'Archived', 'simple-lms' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="slms_term_id"><?php esc_html_e( 'Semester / Term', 'simple-lms' ); ?></label></th>
				<td>
					<select id="slms_term_id" name="slms_term_id" style="min-width:320px;">
						<option value=""><?php esc_html_e( 'Select a term', 'simple-lms' ); ?></option>
						<?php foreach ( $terms as $term ) : ?>
							<option value="<?php echo esc_attr( $term['id'] ); ?>" <?php selected( $term_id, (int) $term['id'] ); ?>>
								<?php echo esc_html( $term['name'] . ' (' . $term['academic_year'] . ')' ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="slms_capacity"><?php esc_html_e( 'Enrollment Capacity', 'simple-lms' ); ?></label></th>
				<td><input type="number" id="slms_capacity" name="slms_capacity" class="small-text" min="0" value="<?php echo esc_attr( $capacity ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="slms_delivery_mode"><?php esc_html_e( 'Delivery Mode', 'simple-lms' ); ?></label></th>
				<td>
					<select id="slms_delivery_mode" name="slms_delivery_mode">
						<option value="onsite" <?php selected( $delivery_mode, 'onsite' ); ?>><?php esc_html_e( 'Onsite', 'simple-lms' ); ?></option>
						<option value="online" <?php selected( $delivery_mode, 'online' ); ?>><?php esc_html_e( 'Online', 'simple-lms' ); ?></option>
						<option value="hybrid" <?php selected( $delivery_mode, 'hybrid' ); ?>><?php esc_html_e( 'Hybrid', 'simple-lms' ); ?></option>
					</select>
				</td>
			</tr>
		</table>
		<p><?php esc_html_e( 'Assign the subject to one or more programs using the Programs box in the sidebar. In the simplified model, each subject record acts as the live course offering for its semester.', 'simple-lms' ); ?></p>
		<?php $this->render_week_structure_editor( $post->ID, 'subject' ); ?>
		<?php
	}

	public function render_subject_staff_meta_box( $post ) {
		wp_nonce_field( 'slms_save_subject_staff_meta', 'slms_subject_staff_meta_nonce' );

		$assigned      = wp_list_pluck( $this->enrollments->get_section_staff( $post->ID, 'lecturer' ), 'user_id' );
		$lecturer_pool = get_users(
			array(
				'role__in' => array( 'lecturer', 'officer', 'administrator' ),
				'orderby'  => 'display_name',
				'order'    => 'ASC',
			)
		);

		if ( empty( $lecturer_pool ) ) {
			echo '<p>' . esc_html__( 'No eligible teaching staff accounts were found.', 'simple-lms' ) . '</p>';
			return;
		}

		foreach ( $lecturer_pool as $user ) {
			?>
			<label style="display:block;margin-bottom:6px;">
				<input type="checkbox" name="slms_subject_staff[]" value="<?php echo esc_attr( $user->ID ); ?>" <?php checked( in_array( $user->ID, array_map( 'intval', $assigned ), true ) ); ?>>
				<?php echo esc_html( $user->display_name . ' (' . $user->user_email . ')' ); ?>
			</label>
			<?php
		}

		echo '<p style="margin-top:8px;color:#50575e;">' . esc_html__( 'Assigned lecturers will manage materials, assignments, discussions, attendance, and grades for this subject from the frontend workspace.', 'simple-lms' ) . '</p>';
	}

	public function render_section_details_meta_box( $post ) {
		wp_nonce_field( 'slms_save_section_meta', 'slms_section_meta_nonce' );

		$section_code   = get_post_meta( $post->ID, '_slms_section_code', true );
		$subject_id     = absint( get_post_meta( $post->ID, '_slms_subject_id', true ) );
		$term_id        = absint( get_post_meta( $post->ID, '_slms_term_id', true ) );
		$capacity       = absint( get_post_meta( $post->ID, '_slms_capacity', true ) );
		$delivery_mode  = get_post_meta( $post->ID, '_slms_delivery_mode', true ) ?: 'onsite';
		$section_status = get_post_meta( $post->ID, '_slms_section_status', true ) ?: 'planned';
		$subjects       = get_posts(
			array(
				'post_type'      => 'slms_subject',
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		$terms = $this->terms->list_terms();
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="slms_section_code"><?php esc_html_e( 'Section Code', 'simple-lms' ); ?></label></th>
				<td><input type="text" id="slms_section_code" name="slms_section_code" class="regular-text" value="<?php echo esc_attr( $section_code ); ?>" placeholder="e.g. BUD-501-A"></td>
			</tr>
			<tr>
				<th scope="row"><label for="slms_subject_id"><?php esc_html_e( 'Subject', 'simple-lms' ); ?></label></th>
				<td>
					<select id="slms_subject_id" name="slms_subject_id" style="min-width:320px;">
						<option value=""><?php esc_html_e( 'Select a subject', 'simple-lms' ); ?></option>
						<?php foreach ( $subjects as $subject ) : ?>
							<option value="<?php echo esc_attr( $subject->ID ); ?>" <?php selected( $subject_id, $subject->ID ); ?>><?php echo esc_html( $subject->post_title ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="slms_term_id"><?php esc_html_e( 'Term', 'simple-lms' ); ?></label></th>
				<td>
					<select id="slms_term_id" name="slms_term_id" style="min-width:320px;">
						<option value=""><?php esc_html_e( 'Select a term', 'simple-lms' ); ?></option>
						<?php foreach ( $terms as $term ) : ?>
							<option value="<?php echo esc_attr( $term['id'] ); ?>" <?php selected( $term_id, (int) $term['id'] ); ?>>
								<?php echo esc_html( $term['name'] . ' (' . $term['academic_year'] . ')' ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="slms_capacity"><?php esc_html_e( 'Capacity', 'simple-lms' ); ?></label></th>
				<td><input type="number" id="slms_capacity" name="slms_capacity" class="small-text" min="0" value="<?php echo esc_attr( $capacity ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="slms_delivery_mode"><?php esc_html_e( 'Delivery Mode', 'simple-lms' ); ?></label></th>
				<td>
					<select id="slms_delivery_mode" name="slms_delivery_mode">
						<option value="onsite" <?php selected( $delivery_mode, 'onsite' ); ?>><?php esc_html_e( 'Onsite', 'simple-lms' ); ?></option>
						<option value="online" <?php selected( $delivery_mode, 'online' ); ?>><?php esc_html_e( 'Online', 'simple-lms' ); ?></option>
						<option value="hybrid" <?php selected( $delivery_mode, 'hybrid' ); ?>><?php esc_html_e( 'Hybrid', 'simple-lms' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="slms_section_status"><?php esc_html_e( 'Section Status', 'simple-lms' ); ?></label></th>
				<td>
					<select id="slms_section_status" name="slms_section_status">
						<option value="planned" <?php selected( $section_status, 'planned' ); ?>><?php esc_html_e( 'Planned', 'simple-lms' ); ?></option>
						<option value="active" <?php selected( $section_status, 'active' ); ?>><?php esc_html_e( 'Active', 'simple-lms' ); ?></option>
						<option value="closed" <?php selected( $section_status, 'closed' ); ?>><?php esc_html_e( 'Closed', 'simple-lms' ); ?></option>
						<option value="archived" <?php selected( $section_status, 'archived' ); ?>><?php esc_html_e( 'Archived', 'simple-lms' ); ?></option>
					</select>
				</td>
			</tr>
		</table>
		<?php if ( empty( $terms ) ) : ?>
			<p><em><?php esc_html_e( 'No terms exist yet. Create terms from Simple LMS > Terms first.', 'simple-lms' ); ?></em></p>
		<?php endif; ?>
		<?php $this->render_week_structure_editor( $post->ID, 'section' ); ?>
		<?php
	}

	public function render_section_staff_meta_box( $post ) {
		wp_nonce_field( 'slms_save_section_staff_meta', 'slms_section_staff_meta_nonce' );

		$assigned      = wp_list_pluck( $this->enrollments->get_section_staff( $post->ID, 'lecturer' ), 'user_id' );
		$lecturer_pool = get_users(
			array(
				'role__in' => array( 'lecturer', 'officer', 'administrator' ),
				'orderby'  => 'display_name',
				'order'    => 'ASC',
			)
		);

		if ( empty( $lecturer_pool ) ) {
			echo '<p>' . esc_html__( 'No eligible teaching staff accounts were found.', 'simple-lms' ) . '</p>';
			return;
		}

		foreach ( $lecturer_pool as $user ) {
			?>
			<label style="display:block;margin-bottom:6px;">
				<input type="checkbox" name="slms_section_staff[]" value="<?php echo esc_attr( $user->ID ); ?>" <?php checked( in_array( $user->ID, array_map( 'intval', $assigned ), true ) ); ?>>
				<?php echo esc_html( $user->display_name . ' (' . $user->user_email . ')' ); ?>
			</label>
			<?php
		}

		echo '<p style="margin-top:8px;color:#50575e;">' . esc_html__( 'Assigned lecturers gain scoped ownership of this section in later phases.', 'simple-lms' ) . '</p>';
	}

	public function save_subject_meta( $post_id, $post ) {
		if ( ! $this->can_save_post_meta( $post_id, 'slms_subject_meta_nonce', 'slms_save_subject_meta' ) ) {
			return;
		}

		$subject_code   = strtoupper( sanitize_text_field( $_POST['slms_subject_code'] ?? '' ) );
		$credits        = isset( $_POST['slms_credits'] ) ? (float) wp_unslash( $_POST['slms_credits'] ) : 0;
		$subject_type   = sanitize_key( $_POST['slms_subject_type'] ?? 'core' );
		$subject_status = sanitize_key( $_POST['slms_subject_status'] ?? 'active' );
		$term_id        = absint( $_POST['slms_term_id'] ?? 0 );
		$capacity       = absint( $_POST['slms_capacity'] ?? 0 );
		$delivery_mode  = sanitize_key( $_POST['slms_delivery_mode'] ?? 'onsite' );
		$staff_ids      = isset( $_POST['slms_subject_staff'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['slms_subject_staff'] ) ) : array();
		$week_structure = $this->sanitize_week_structure_meta( wp_unslash( $_POST['slms_week_structure'] ?? array() ) );

		if ( ! in_array( $subject_type, array( 'core', 'elective' ), true ) ) {
			$subject_type = 'core';
		}

		if ( ! in_array( $subject_status, array( 'active', 'inactive', 'archived' ), true ) ) {
			$subject_status = 'active';
		}

		if ( ! in_array( $delivery_mode, array( 'onsite', 'online', 'hybrid' ), true ) ) {
			$delivery_mode = 'onsite';
		}

		update_post_meta( $post_id, '_slms_subject_code', $subject_code );
		update_post_meta( $post_id, '_slms_credits', max( 0, $credits ) );
		update_post_meta( $post_id, '_slms_subject_type', $subject_type );
		update_post_meta( $post_id, '_slms_subject_status', $subject_status );
		update_post_meta( $post_id, '_slms_term_id', $term_id );
		update_post_meta( $post_id, '_slms_capacity', $capacity );
		update_post_meta( $post_id, '_slms_delivery_mode', $delivery_mode );
		update_post_meta( $post_id, '_slms_week_structure', $week_structure );

		if ( isset( $_POST['slms_subject_staff_meta_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['slms_subject_staff_meta_nonce'] ) ), 'slms_save_subject_staff_meta' ) ) {
			$this->enrollments->assign_section_staff( $post_id, $staff_ids, 'lecturer', get_current_user_id() );
		}

		$this->audit_logger->log(
			'subject_saved',
			array(
				'object_type' => 'subject',
				'object_id'   => $post_id,
				'message'     => sprintf( 'Subject "%s" was saved.', $post->post_title ),
				'context'     => array(
					'subject_code' => $subject_code,
					'credits'      => $credits,
					'type'         => $subject_type,
					'status'       => $subject_status,
					'term_id'      => $term_id,
					'capacity'     => $capacity,
					'delivery_mode'=> $delivery_mode,
					'staff_ids'    => $staff_ids,
					'week_count'   => count( $week_structure ),
				),
			)
		);
	}

	public function save_section_meta( $post_id, $post ) {
		if ( ! $this->can_save_post_meta( $post_id, 'slms_section_meta_nonce', 'slms_save_section_meta' ) ) {
			return;
		}

		$section_code   = strtoupper( sanitize_text_field( $_POST['slms_section_code'] ?? '' ) );
		$subject_id     = absint( $_POST['slms_subject_id'] ?? 0 );
		$term_id        = absint( $_POST['slms_term_id'] ?? 0 );
		$capacity       = absint( $_POST['slms_capacity'] ?? 0 );
		$delivery_mode  = sanitize_key( $_POST['slms_delivery_mode'] ?? 'onsite' );
		$section_status = sanitize_key( $_POST['slms_section_status'] ?? 'planned' );
		$staff_ids      = isset( $_POST['slms_section_staff'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['slms_section_staff'] ) ) : array();
		$week_structure = $this->sanitize_week_structure_meta( wp_unslash( $_POST['slms_week_structure'] ?? array() ) );

		if ( ! in_array( $delivery_mode, array( 'onsite', 'online', 'hybrid' ), true ) ) {
			$delivery_mode = 'onsite';
		}

		if ( ! in_array( $section_status, array( 'planned', 'active', 'closed', 'archived' ), true ) ) {
			$section_status = 'planned';
		}

		update_post_meta( $post_id, '_slms_section_code', $section_code );
		update_post_meta( $post_id, '_slms_subject_id', $subject_id );
		update_post_meta( $post_id, '_slms_term_id', $term_id );
		update_post_meta( $post_id, '_slms_capacity', $capacity );
		update_post_meta( $post_id, '_slms_delivery_mode', $delivery_mode );
		update_post_meta( $post_id, '_slms_section_status', $section_status );
		update_post_meta( $post_id, '_slms_week_structure', $week_structure );

		if ( isset( $_POST['slms_section_staff_meta_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['slms_section_staff_meta_nonce'] ) ), 'slms_save_section_staff_meta' ) ) {
			$this->enrollments->assign_section_staff( $post_id, $staff_ids, 'lecturer', get_current_user_id() );
		}

		$this->audit_logger->log(
			'section_saved',
			array(
				'object_type' => 'section',
				'object_id'   => $post_id,
				'message'     => sprintf( 'Section "%s" was saved.', $post->post_title ),
				'context'     => array(
					'section_code'   => $section_code,
					'subject_id'     => $subject_id,
					'term_id'        => $term_id,
					'capacity'       => $capacity,
					'delivery_mode'  => $delivery_mode,
					'section_status' => $section_status,
					'staff_ids'      => $staff_ids,
					'week_count'     => count( $week_structure ),
				),
			)
		);
	}

	private function render_week_structure_editor( $post_id, $workspace_type ) {
		$weeks           = $this->sanitize_week_structure_meta( get_post_meta( $post_id, '_slms_week_structure', true ) );
		$workspace_label = 'section' === $workspace_type ? __( 'section workspace', 'simple-lms' ) : __( 'subject workspace', 'simple-lms' );
		?>
		<div class="slms-week-structure-editor" data-slms-week-structure-editor data-next-index="<?php echo esc_attr( (string) count( $weeks ) ); ?>">
			<h3><?php esc_html_e( 'Teaching Weeks', 'simple-lms' ); ?></h3>
			<p><?php echo esc_html( sprintf( __( 'Define the week groups shown in Materials, Assignments, and Discussions for this %s. Items without a week assignment stay in General.', 'simple-lms' ), $workspace_label ) ); ?></p>
			<div class="slms-week-structure-empty<?php echo ! empty( $weeks ) ? ' is-hidden' : ''; ?>" data-slms-week-empty>
				<?php esc_html_e( 'No teaching weeks are defined yet. Add weeks here to replace the fallback numeric week field with a managed list in the frontend workspace.', 'simple-lms' ); ?>
			</div>
			<div class="slms-table-wrap">
				<table class="widefat slms-week-structure-table">
					<thead>
						<tr>
							<th class="slms-week-structure-number-column" scope="col"><?php esc_html_e( 'Week', 'simple-lms' ); ?></th>
							<th class="slms-week-structure-label-column" scope="col"><?php esc_html_e( 'Label', 'simple-lms' ); ?></th>
							<th class="slms-week-structure-description-column" scope="col"><?php esc_html_e( 'Description', 'simple-lms' ); ?></th>
							<th class="slms-week-structure-actions-column" scope="col"><?php esc_html_e( 'Actions', 'simple-lms' ); ?></th>
						</tr>
					</thead>
					<tbody data-slms-week-rows>
						<?php foreach ( $weeks as $index => $week ) : ?>
							<?php echo $this->get_week_structure_row_markup( $index, $week ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<p class="description"><?php esc_html_e( 'Week numbers control the group order. Labels are shown in the subject portal, and descriptions appear under the week heading when present.', 'simple-lms' ); ?></p>
			<p>
				<button type="button" class="button button-secondary" data-slms-add-week><?php esc_html_e( 'Add Week', 'simple-lms' ); ?></button>
			</p>
			<template data-slms-week-row-template>
				<?php echo $this->get_week_structure_row_markup( '__index__' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</template>
		</div>
		<?php
	}

	private function get_week_structure_row_markup( $index, array $week = array() ) {
		$field_root         = sprintf( 'slms_week_structure[%s]', (string) $index );
		$week_number_value  = isset( $week['week_number'] ) ? (string) absint( $week['week_number'] ) : '';
		$label_value        = isset( $week['label'] ) ? (string) $week['label'] : '';
		$description_value  = isset( $week['description'] ) ? (string) $week['description'] : '';
		$slug_value         = isset( $week['slug'] ) ? (string) $week['slug'] : '';

		ob_start();
		?>
		<tr class="slms-week-structure-row" data-slms-week-row>
			<td class="slms-week-structure-cell slms-week-structure-number">
				<input type="hidden" name="<?php echo esc_attr( $field_root . '[slug]' ); ?>" value="<?php echo esc_attr( $slug_value ); ?>">
				<input type="number" name="<?php echo esc_attr( $field_root . '[week_number]' ); ?>" min="1" step="1" class="small-text" value="<?php echo esc_attr( $week_number_value ); ?>" data-slms-week-number>
			</td>
			<td class="slms-week-structure-cell slms-week-structure-label">
				<input type="text" name="<?php echo esc_attr( $field_root . '[label]' ); ?>" class="regular-text" value="<?php echo esc_attr( $label_value ); ?>" placeholder="<?php esc_attr_e( 'Week label', 'simple-lms' ); ?>">
			</td>
			<td class="slms-week-structure-cell slms-week-structure-description">
				<textarea name="<?php echo esc_attr( $field_root . '[description]' ); ?>" rows="2" class="large-text" placeholder="<?php esc_attr_e( 'Optional helper text for this week group', 'simple-lms' ); ?>"><?php echo esc_textarea( $description_value ); ?></textarea>
			</td>
			<td class="slms-week-structure-cell slms-week-structure-actions">
				<button type="button" class="button button-link-delete" data-slms-remove-week><?php esc_html_e( 'Remove', 'simple-lms' ); ?></button>
			</td>
		</tr>
		<?php

		return (string) ob_get_clean();
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

	private function register_meta() {
		$subject_meta = array(
			'_slms_subject_code'   => array(
				'type'              => 'string',
				'show_in_rest'      => true,
				'sanitize_callback' => array( $this, 'sanitize_upper_text_meta' ),
			),
			'_slms_credits'        => array(
				'type'              => 'number',
				'show_in_rest'      => true,
				'sanitize_callback' => array( $this, 'sanitize_credit_value' ),
			),
			'_slms_subject_type'   => array(
				'type'              => 'string',
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_key',
			),
			'_slms_subject_status' => array(
				'type'              => 'string',
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_key',
			),
			'_slms_term_id'        => array(
				'type'              => 'integer',
				'show_in_rest'      => true,
				'sanitize_callback' => 'absint',
			),
			'_slms_capacity'       => array(
				'type'              => 'integer',
				'show_in_rest'      => true,
				'sanitize_callback' => 'absint',
			),
			'_slms_delivery_mode'  => array(
				'type'              => 'string',
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_key',
			),
			'_slms_week_structure' => array(
				'type'              => 'array',
				'show_in_rest'      => array(
					'schema' => $this->get_week_structure_meta_schema(),
				),
				'sanitize_callback' => array( $this, 'sanitize_week_structure_meta' ),
			),
		);
		$section_meta = array(
			'_slms_section_code'   => array(
				'type'              => 'string',
				'show_in_rest'      => true,
				'sanitize_callback' => array( $this, 'sanitize_upper_text_meta' ),
			),
			'_slms_subject_id'     => array(
				'type'              => 'integer',
				'show_in_rest'      => true,
				'sanitize_callback' => 'absint',
			),
			'_slms_term_id'        => array(
				'type'              => 'integer',
				'show_in_rest'      => true,
				'sanitize_callback' => 'absint',
			),
			'_slms_capacity'       => array(
				'type'              => 'integer',
				'show_in_rest'      => true,
				'sanitize_callback' => 'absint',
			),
			'_slms_delivery_mode'  => array(
				'type'              => 'string',
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_key',
			),
			'_slms_section_status' => array(
				'type'              => 'string',
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_key',
			),
			'_slms_week_structure' => array(
				'type'              => 'array',
				'show_in_rest'      => array(
					'schema' => $this->get_week_structure_meta_schema(),
				),
				'sanitize_callback' => array( $this, 'sanitize_week_structure_meta' ),
			),
		);

		foreach ( $subject_meta as $key => $args ) {
			register_post_meta(
				'slms_subject',
				$key,
				array(
					'show_in_rest'       => $args['show_in_rest'] ?? true,
					'single'             => true,
					'type'               => $args['type'],
					'auth_callback'      => array( $this, 'can_manage_academics' ),
					'sanitize_callback'  => $args['sanitize_callback'],
				)
			);
		}

		foreach ( $section_meta as $key => $args ) {
			register_post_meta(
				'slms_section',
				$key,
				array(
					'show_in_rest'       => $args['show_in_rest'] ?? true,
					'single'             => true,
					'type'               => $args['type'],
					'auth_callback'      => array( $this, 'can_manage_academics' ),
					'sanitize_callback'  => $args['sanitize_callback'],
				)
			);
		}
	}

	public function sanitize_upper_text_meta( $value ) {
		return strtoupper( sanitize_text_field( (string) $value ) );
	}

	public function sanitize_credit_value( $value ) {
		return max( 0, (float) $value );
	}

	public function sanitize_week_structure_meta( $value ) {
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
			$description = sanitize_textarea_field( (string) ( $row['description'] ?? '' ) );

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

			$slug = sanitize_title( (string) ( $row['slug'] ?? $label ) );

			if ( '' === $slug ) {
				$slug = 'week-' . $week_number;
			}

			$weeks[] = array(
				'week_number' => $week_number,
				'label'       => $label,
				'slug'        => $slug,
				'description' => $description,
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

	private function get_week_structure_meta_schema() {
		return array(
			'type'  => 'array',
			'items' => array(
				'type'                 => 'object',
				'properties'           => array(
					'week_number' => array(
						'type' => 'integer',
					),
					'label'       => array(
						'type' => 'string',
					),
					'slug'        => array(
						'type' => 'string',
					),
					'description' => array(
						'type' => 'string',
					),
				),
				'additionalProperties' => false,
			),
		);
	}

	public function can_manage_academics() {
		return current_user_can( RoleManager::CAP_MANAGE_ACADEMICS ) || current_user_can( RoleManager::CAP_MANAGE_COMMUNICATIONS );
	}
}
