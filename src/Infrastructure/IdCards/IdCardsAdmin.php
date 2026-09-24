<?php

namespace SimpleLMS\Infrastructure\IdCards;

use SimpleLMS\Domain\Users\ProfileService;
use SimpleLMS\Domain\Users\RoleManager;
use SimpleLMS\Infrastructure\Database\Schema;

defined( 'ABSPATH' ) || exit;

class IdCardsAdmin {
	/** @var ProfileService */
	private $profiles;

	/** @var IdCardsModule */
	private $module;

	public function __construct( ProfileService $profiles, IdCardsModule $module ) {
		$this->profiles = $profiles;
		$this->module   = $module;
	}

	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 45 );
		add_action( 'admin_init', array( $this, 'handle_admin_actions' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	public function register_menu() {
		add_submenu_page(
			'slms-dashboard',
			__( 'Card Issuer', 'simple-lms' ),
			__( 'Card Issuer', 'simple-lms' ),
			'manage_options',
			'slms-id-cards',
			array( $this, 'render_page' )
		);
	}

	public function enqueue_admin_assets( $hook ) {
		if ( false === strpos( (string) $hook, 'slms-id-cards' ) ) {
			return;
		}

		$this->module->renderer()->enqueue_assets();

		wp_add_inline_script(
			'slms-id-card',
			"document.addEventListener('change',function(event){var input=event.target&&event.target.closest?event.target.closest('[data-slms-admin-id-photo-input]'):null;if(!input||!input.files||!input.files.length){return;}var form=input.closest('form');if(form){form.submit();}});"
		);
	}

	public function handle_admin_actions() {
		if ( empty( $_POST['slms_id_cards_action'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage ID cards.', 'simple-lms' ), 403 );
		}

		$action = sanitize_key( wp_unslash( $_POST['slms_id_cards_action'] ) );

		switch ( $action ) {
			case 'save_photo':
				check_admin_referer( 'slms_id_cards_photo' );
				$user_id = absint( $_POST['user_id'] ?? 0 );
				$result  = $this->save_profile_photo( $user_id );
				$this->redirect_after_action( $result, array( 'user_id' => $user_id ) );
				break;

			case 'issue':
				check_admin_referer( 'slms_id_cards_issue' );
				$user_id = absint( $_POST['user_id'] ?? 0 );
				$result  = $this->module->issue_user_card( $user_id, get_current_user_id() );
				$this->redirect_after_action( $result, array( 'user_id' => $user_id ) );
				break;

			case 'batch_issue':
				check_admin_referer( 'slms_id_cards_batch_issue' );
				$user_ids = isset( $_POST['user_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['user_ids'] ) ) : array();
				$count    = 0;
				foreach ( array_filter( $user_ids ) as $user_id ) {
					$result = $this->module->issue_user_card( $user_id, get_current_user_id() );
					if ( ! is_wp_error( $result ) ) {
						$count++;
					}
				}
				$this->redirect_after_action( true, array( 'issued' => $count ) );
				break;

			case 'revoke':
				check_admin_referer( 'slms_id_cards_revoke' );
				$card_id = absint( $_POST['card_id'] ?? 0 );
				$user_id = absint( $_POST['user_id'] ?? 0 );
				$result  = $this->module->repository()->revoke_card( $card_id, get_current_user_id() );
				$this->redirect_after_action( $result, array( 'user_id' => $user_id ) );
				break;
		}
	}

	private function save_profile_photo( $user_id ) {
		$user_id = absint( $user_id );

		if ( ! $user_id || ! $this->profiles->get_profile( $user_id ) ) {
			return new \WP_Error( 'slms_id_photo_missing_profile', __( 'The selected profile could not be found.', 'simple-lms' ) );
		}

		if ( empty( $_FILES['slms_profile_photo']['tmp_name'] ) ) {
			return new \WP_Error( 'slms_id_photo_missing_file', __( 'Please choose an ID photo to upload.', 'simple-lms' ) );
		}

		if ( ! empty( $_FILES['slms_profile_photo']['error'] ) && UPLOAD_ERR_OK !== (int) $_FILES['slms_profile_photo']['error'] ) {
			return new \WP_Error( 'slms_id_photo_upload_error', __( 'The ID photo could not be uploaded. Please try again.', 'simple-lms' ) );
		}

		if ( ! empty( $_FILES['slms_profile_photo']['size'] ) && (int) $_FILES['slms_profile_photo']['size'] > 10 * MB_IN_BYTES ) {
			return new \WP_Error( 'slms_id_photo_too_large', __( 'ID photos must be 10MB or smaller.', 'simple-lms' ) );
		}

		$image_size = @getimagesize( $_FILES['slms_profile_photo']['tmp_name'] );
		if ( empty( $image_size[0] ) ) {
			return new \WP_Error( 'slms_id_photo_invalid', __( 'Please upload a valid image file.', 'simple-lms' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$previous_attachment_id = absint( get_user_meta( $user_id, 'slms_profile_photo_id', true ) );
		$previous_managed_photo = get_user_meta( $user_id, 'slms_profile_photo_upload', true );
		$attachment_id          = media_handle_upload( 'slms_profile_photo', 0, array(), array( 'test_form' => false ) );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		if ( $previous_attachment_id && $previous_attachment_id !== (int) $attachment_id ) {
			wp_delete_attachment( $previous_attachment_id, true );
		}

		$this->delete_managed_profile_photo_asset( $previous_managed_photo );
		update_user_meta( $user_id, 'slms_profile_photo_id', (int) $attachment_id );
		delete_user_meta( $user_id, 'slms_profile_photo_upload' );

		return true;
	}

	private function delete_managed_profile_photo_asset( $photo ) {
		if ( ! is_array( $photo ) || empty( $photo['relative_path'] ) ) {
			return;
		}

		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) || empty( $upload_dir['basedir'] ) ) {
			return;
		}

		$base_dir = wp_normalize_path( $upload_dir['basedir'] );
		$file    = wp_normalize_path( trailingslashit( $base_dir ) . ltrim( (string) $photo['relative_path'], '/' ) );

		if ( 0 === strpos( $file, $base_dir ) && is_file( $file ) ) {
			@unlink( $file );
		}
	}

	private function redirect_after_action( $result, array $args = array() ) {
		$args['page'] = 'slms-id-cards';
		$args['tab']  = sanitize_key( $_REQUEST['tab'] ?? 'students' );

		if ( is_wp_error( $result ) ) {
			$args['slms_id_cards_error'] = rawurlencode( $result->get_error_message() );
		} else {
			$args['slms_id_cards_notice'] = '1';
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage ID cards.', 'simple-lms' ), 403 );
		}

		$tab = sanitize_key( $_GET['tab'] ?? 'students' );
		if ( ! in_array( $tab, array( 'students', 'staff' ), true ) ) {
			$tab = 'students';
		}
		?>
		<div class="wrap slms-admin-page slms-id-cards-page">
			<div class="slms-page-header">
				<div>
					<p class="slms-page-kicker"><?php esc_html_e( 'Card Issuer', 'simple-lms' ); ?></p>
					<h1 class="slms-page-title"><?php esc_html_e( 'ID Cards', 'simple-lms' ); ?></h1>
					<p class="slms-page-description"><?php esc_html_e( 'Issue and regenerate UGP ID cards directly from Simple LMS student and staff profiles.', 'simple-lms' ); ?></p>
				</div>
			</div>
			<?php $this->render_notices(); ?>
			<nav class="nav-tab-wrapper" style="margin-bottom:20px;">
				<a class="nav-tab <?php echo 'students' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=slms-id-cards&tab=students' ) ); ?>"><?php esc_html_e( 'Students', 'simple-lms' ); ?></a>
				<a class="nav-tab <?php echo 'staff' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=slms-id-cards&tab=staff' ) ); ?>"><?php esc_html_e( 'Staff', 'simple-lms' ); ?></a>
			</nav>
			<?php $this->render_cards_tab( 'staff' === $tab ? 'staff' : 'student' ); ?>
		</div>
		<?php
	}

	private function render_notices() {
		if ( ! empty( $_GET['slms_id_cards_notice'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Done.', 'simple-lms' ) . '</p></div>';
		}
		if ( ! empty( $_GET['issued'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( __( '%d card(s) issued or regenerated.', 'simple-lms' ), absint( $_GET['issued'] ) ) ) . '</p></div>';
		}
		if ( ! empty( $_GET['slms_id_cards_error'] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['slms_id_cards_error'] ) ) ) . '</p></div>';
		}
	}

	private function render_cards_tab( $person_type ) {
		$user_id = absint( $_GET['user_id'] ?? 0 );
		if ( $user_id ) {
			$this->render_person_preview( $user_id );
			return;
		}

		$is_staff   = 'staff' === $person_type;
		$page       = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$per_page   = 30;
		$search     = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
		$program_id = absint( $_GET['program_id'] ?? 0 );
		$intake_id  = absint( $_GET['intake_term_id'] ?? 0 );
		$year       = sanitize_text_field( wp_unslash( $_GET['intake_academic_year'] ?? '' ) );
		$role       = sanitize_key( $_GET['role'] ?? '' );
		$department = sanitize_text_field( wp_unslash( $_GET['department'] ?? '' ) );
		$orderby    = sanitize_key( $_GET['orderby'] ?? ( $is_staff ? 'department' : 'program_intake' ) );

		$args = array(
			'person_type'          => $person_type,
			'search'               => $search,
			'page'                 => $page,
			'per_page'             => $per_page,
			'orderby'              => $orderby,
			'program_id'           => $program_id,
			'intake_term_id'       => $intake_id,
			'intake_academic_year' => $year,
			'role'                 => $role,
			'department'           => $department,
		);

		$profiles      = $this->profiles->list_profiles( $args );
		$total         = $this->profiles->count_profiles_matching( $args );
		$pages         = max( 1, (int) ceil( $total / $per_page ) );
		$batch_filters = array(
			'person_type'          => $person_type,
			'search'               => $search,
			'program_id'           => $program_id,
			'intake_term_id'       => $intake_id,
			'intake_academic_year' => $year,
			'role'                 => $role,
			'department'           => $department,
			'orderby'              => $orderby,
		);
		$batch_label   = $is_staff ? __( 'Download Issued Staff Cards Matching Filters', 'simple-lms' ) : __( 'Download Issued Student Cards Matching Filters', 'simple-lms' );
		?>
		<form method="get" class="slms-id-card-filters" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:0 0 16px;">
			<input type="hidden" name="page" value="slms-id-cards">
			<input type="hidden" name="tab" value="<?php echo esc_attr( $is_staff ? 'staff' : 'students' ); ?>">
			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search name, ID, email, department', 'simple-lms' ); ?>">
			<?php if ( $is_staff ) : ?>
				<?php $this->render_role_filter( $role ); ?>
				<?php $this->render_department_filter( $department ); ?>
				<select name="orderby">
					<option value="department" <?php selected( $orderby, 'department' ); ?>><?php esc_html_e( 'Sort by Department', 'simple-lms' ); ?></option>
					<option value="role" <?php selected( $orderby, 'role' ); ?>><?php esc_html_e( 'Sort by Role', 'simple-lms' ); ?></option>
					<option value="name" <?php selected( $orderby, 'name' ); ?>><?php esc_html_e( 'Sort by Name', 'simple-lms' ); ?></option>
				</select>
			<?php else : ?>
				<?php $this->render_program_filter( $program_id ); ?>
				<?php $this->render_intake_filter( $intake_id, $year ); ?>
				<select name="orderby">
					<option value="program_intake" <?php selected( $orderby, 'program_intake' ); ?>><?php esc_html_e( 'Sort by Program then Intake', 'simple-lms' ); ?></option>
					<option value="intake_program" <?php selected( $orderby, 'intake_program' ); ?>><?php esc_html_e( 'Sort by Intake then Program', 'simple-lms' ); ?></option>
					<option value="name" <?php selected( $orderby, 'name' ); ?>><?php esc_html_e( 'Sort by Name', 'simple-lms' ); ?></option>
				</select>
			<?php endif; ?>
			<button class="button button-secondary" type="submit"><?php esc_html_e( 'Filter', 'simple-lms' ); ?></button>
		</form>

		<form method="post">
			<?php wp_nonce_field( 'slms_id_cards_batch_issue' ); ?>
			<input type="hidden" name="slms_id_cards_action" value="batch_issue">
			<input type="hidden" name="tab" value="<?php echo esc_attr( $is_staff ? 'staff' : 'students' ); ?>">
			<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:10px;flex-wrap:wrap;">
				<p style="margin:0;"><?php echo esc_html( sprintf( __( '%d profile(s) found.', 'simple-lms' ), $total ) ); ?></p>
				<div class="slms-id-card-action-row" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
					<button type="button" class="slms-portal-button is-secondary ugp-id-batch-download" data-ugp-id-batch-download data-filters="<?php echo esc_attr( wp_json_encode( $batch_filters ) ); ?>"><?php echo esc_html( $batch_label ); ?></button>
					<button type="submit" class="slms-portal-button"><?php esc_html_e( 'Issue Selected', 'simple-lms' ); ?></button>
				</div>
			</div>
			<table class="widefat striped">
				<thead>
					<tr>
						<td class="manage-column column-cb check-column"><input type="checkbox" data-ugp-id-select-all></td>
						<th><?php esc_html_e( 'Name', 'simple-lms' ); ?></th>
						<th><?php esc_html_e( 'ID', 'simple-lms' ); ?></th>
						<th><?php echo $is_staff ? esc_html__( 'Role / Department', 'simple-lms' ) : esc_html__( 'Program / Intake', 'simple-lms' ); ?></th>
						<th><?php esc_html_e( 'Contact', 'simple-lms' ); ?></th>
						<th><?php esc_html_e( 'Card Status', 'simple-lms' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'simple-lms' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $profiles ) ) : ?>
						<tr><td colspan="7"><?php esc_html_e( 'No matching profiles found.', 'simple-lms' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $profiles as $profile ) : ?>
							<?php $this->render_profile_row( $profile, $is_staff ); ?>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</form>
		<?php if ( $pages > 1 ) : ?>
			<div class="tablenav"><div class="tablenav-pages">
				<?php
				echo wp_kses_post(
					paginate_links(
						array(
							'base'      => add_query_arg( 'paged', '%#%' ),
							'format'    => '',
							'current'   => $page,
							'total'     => $pages,
							'prev_text' => '&laquo;',
							'next_text' => '&raquo;',
						)
					)
				);
				?>
			</div></div>
		<?php endif; ?>
		<?php
	}

	private function render_profile_row( array $profile, $is_staff ) {
		$user_id = absint( $profile['user_id'] ?? 0 );
		$role    = RoleManager::get_primary_role( $user_id );
		$person  = $this->module->get_person( $user_id, $profile, $role, '' );
		$card    = $person ? $this->module->get_latest_card_for_person_data( $person, true ) : null;
		$program = ! empty( $profile['program_id'] ) ? $this->get_program_name( (int) $profile['program_id'] ) : '';
		$intake  = ! empty( $profile['intake_term_id'] ) ? $this->get_intake_label( (int) $profile['intake_term_id'] ) : '';
		?>
		<tr>
			<th scope="row" class="check-column"><input type="checkbox" name="user_ids[]" value="<?php echo esc_attr( $user_id ); ?>"></th>
			<td><strong><?php echo $this->get_profile_avatar_name_markup( $profile, 'is-compact' ); ?></strong><br><span class="description"><?php echo esc_html( $profile['user_email'] ?? '' ); ?></span></td>
			<td><?php echo esc_html( $profile['person_code'] ?? '-' ); ?></td>
			<td>
				<?php if ( $is_staff ) : ?>
					<strong><?php echo esc_html( $profile['position_title'] ?: RoleManager::get_role_label( $role ) ); ?></strong><br><span class="description"><?php echo esc_html( $profile['department'] ?: '-' ); ?></span>
				<?php else : ?>
					<strong><?php echo esc_html( $program ?: '-' ); ?></strong><br><span class="description"><?php echo esc_html( $intake ?: '-' ); ?></span>
				<?php endif; ?>
			</td>
			<td><?php echo esc_html( $profile['phone'] ?? '-' ); ?></td>
			<td>
				<?php if ( $card ) : ?>
					<strong><?php echo esc_html( ucfirst( $card['status'] ) ); ?></strong><br><span class="description"><?php echo esc_html( $card['issued_at'] ?? '' ); ?></span>
				<?php else : ?>
					<span class="description"><?php esc_html_e( 'Not issued', 'simple-lms' ); ?></span>
				<?php endif; ?>
			</td>
			<td>
				<a class="button button-secondary" href="<?php echo esc_url( add_query_arg( array( 'page' => 'slms-id-cards', 'tab' => $is_staff ? 'staff' : 'students', 'user_id' => $user_id ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Preview', 'simple-lms' ); ?></a>
				<button type="submit" class="button" name="user_ids[]" value="<?php echo esc_attr( $user_id ); ?>"><?php echo $card ? esc_html__( 'Regenerate', 'simple-lms' ) : esc_html__( 'Issue', 'simple-lms' ); ?></button>
			</td>
		</tr>
		<?php
	}

	private function render_person_preview( $user_id ) {
		$profile = $this->profiles->get_profile( $user_id );
		if ( ! $profile ) {
			echo '<p>' . esc_html__( 'Profile not found.', 'simple-lms' ) . '</p>';
			return;
		}

		$role   = RoleManager::get_primary_role( $user_id );
		$person = $this->module->get_person( $user_id, $profile, $role, '' );
		$card   = $person ? $this->module->get_latest_card_for_person_data( $person, true ) : null;
		?>
		<p><a class="button button-secondary" href="<?php echo esc_url( remove_query_arg( 'user_id' ) ); ?>">&larr; <?php esc_html_e( 'Back to list', 'simple-lms' ); ?></a></p>
		<div class="ugp-id-preview-header">
			<div>
				<h2 style="margin:0 0 4px;"><?php echo $this->get_profile_avatar_name_markup( $profile, 'is-large' ); ?></h2>
				<p style="margin:0;" class="description"><?php echo esc_html( $profile['person_code'] ?? '' ); ?></p>
			</div>
			<div class="ugp-id-preview-actions" style="display:flex;gap:10px;align-items:center;justify-content:flex-end;flex-wrap:wrap;">
				<form method="post" enctype="multipart/form-data" class="slms-admin-id-photo-form" style="margin:0;">
					<?php wp_nonce_field( 'slms_id_cards_photo' ); ?>
					<input type="hidden" name="slms_id_cards_action" value="save_photo">
					<input type="hidden" name="user_id" value="<?php echo esc_attr( $user_id ); ?>">
					<label class="button button-secondary" style="cursor:pointer;">
						<?php esc_html_e( 'Upload / Edit Photo', 'simple-lms' ); ?>
						<input type="file" name="slms_profile_photo" accept="image/*" data-slms-admin-id-photo-input style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0);opacity:0;">
					</label>
				</form>
				<form method="post" style="margin:0;">
					<?php wp_nonce_field( 'slms_id_cards_issue' ); ?>
					<input type="hidden" name="slms_id_cards_action" value="issue">
					<input type="hidden" name="user_id" value="<?php echo esc_attr( $user_id ); ?>">
					<button type="submit" class="button button-primary"><?php echo $card ? esc_html__( 'Regenerate Card', 'simple-lms' ) : esc_html__( 'Issue Card', 'simple-lms' ); ?></button>
				</form>
			</div>
		</div>
		<?php
		if ( $person ) {
			if ( ! $card ) {
				echo '<p class="description">' . esc_html__( 'Draft preview only. This ID card has not been issued yet.', 'simple-lms' ) . '</p>';
			}
			echo $this->module->renderer()->render_card_set( $person, $card, array( 'uid' => 'slms-id-admin-' . $user_id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} else {
			echo '<p>' . esc_html__( 'Unable to build a preview for this profile.', 'simple-lms' ) . '</p>';
		}
	}

	private function render_role_filter( $selected ) {
		$roles = array( 'administrator', 'officer', 'lecturer', RoleManager::ROLE_STAFF );
		?>
		<select name="role">
			<option value=""><?php esc_html_e( 'All roles', 'simple-lms' ); ?></option>
			<?php foreach ( $roles as $role ) : ?>
				<option value="<?php echo esc_attr( $role ); ?>" <?php selected( $selected, $role ); ?>><?php echo esc_html( RoleManager::get_role_label( $role ) ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	private function render_department_filter( $selected ) {
		$departments = $this->get_departments();
		?>
		<select name="department">
			<option value=""><?php esc_html_e( 'All departments', 'simple-lms' ); ?></option>
			<?php foreach ( $departments as $department ) : ?>
				<option value="<?php echo esc_attr( $department ); ?>" <?php selected( $selected, $department ); ?>><?php echo esc_html( $department ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	private function render_program_filter( $selected ) {
		$programs = get_terms(
			array(
				'taxonomy'   => 'slms_program',
				'hide_empty' => false,
			)
		);
		?>
		<select name="program_id">
			<option value="0"><?php esc_html_e( 'All programs', 'simple-lms' ); ?></option>
			<?php if ( ! is_wp_error( $programs ) ) : ?>
				<?php foreach ( $programs as $program ) : ?>
					<option value="<?php echo esc_attr( $program->term_id ); ?>" <?php selected( $selected, $program->term_id ); ?>><?php echo esc_html( $program->name ); ?></option>
				<?php endforeach; ?>
			<?php endif; ?>
		</select>
		<?php
	}

	private function render_intake_filter( $selected_id, $selected_year ) {
		global $wpdb;
		$terms = $wpdb->get_results( 'SELECT id, name, academic_year FROM ' . Schema::table( 'terms' ) . ' ORDER BY academic_year DESC, sort_order ASC, name ASC', ARRAY_A );
		$years = $wpdb->get_col( 'SELECT DISTINCT academic_year FROM ' . Schema::table( 'terms' ) . " WHERE academic_year <> '' ORDER BY academic_year DESC" );
		?>
		<select name="intake_term_id">
			<option value="0"><?php esc_html_e( 'All intake terms', 'simple-lms' ); ?></option>
			<?php foreach ( $terms as $term ) : ?>
				<option value="<?php echo esc_attr( $term['id'] ); ?>" <?php selected( $selected_id, $term['id'] ); ?>><?php echo esc_html( $term['name'] . ' - ' . $term['academic_year'] ); ?></option>
			<?php endforeach; ?>
		</select>
		<select name="intake_academic_year">
			<option value=""><?php esc_html_e( 'All intake years', 'simple-lms' ); ?></option>
			<?php foreach ( $years as $year ) : ?>
				<option value="<?php echo esc_attr( $year ); ?>" <?php selected( $selected_year, $year ); ?>><?php echo esc_html( $year ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}


	private function get_profile_avatar_name_markup( array $profile, $class = '' ) {
		$user_id = absint( $profile['user_id'] ?? 0 );
		$name    = trim( (string) ( $profile['display_name'] ?? '' ) );
		$name    = '' !== $name ? $name : __( 'Unknown', 'simple-lms' );
		$classes = trim( 'slms-person-inline ' . (string) $class );

		return sprintf(
			'<span class="%1$s"><img class="slms-person-avatar" src="%2$s" alt="" loading="lazy" decoding="async"><span class="slms-person-name">%3$s</span></span>',
			esc_attr( $classes ),
			esc_url( $this->profiles->get_profile_photo_url( $user_id, 96 ) ),
			esc_html( $name )
		);
	}

	private function get_departments() {
		global $wpdb;
		return $wpdb->get_col( "SELECT DISTINCT department FROM " . Schema::table( 'user_profiles' ) . " WHERE person_type = 'staff' AND department IS NOT NULL AND department <> '' ORDER BY department ASC" );
	}

	private function get_program_name( $term_id ) {
		$term = get_term( absint( $term_id ), 'slms_program' );
		return ( $term && ! is_wp_error( $term ) ) ? $term->name : '';
	}

	private function get_intake_label( $term_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT name, academic_year FROM ' . Schema::table( 'terms' ) . ' WHERE id = %d LIMIT 1', absint( $term_id ) ), ARRAY_A );
		return $row ? trim( $row['name'] . ' ' . $row['academic_year'] ) : '';
	}
}
