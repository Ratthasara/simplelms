<?php

namespace SimpleLMS\Infrastructure\Learning;

use SimpleLMS\Domain\Academic\EnrollmentService;
use SimpleLMS\Domain\Users\RoleManager;
use SimpleLMS\Infrastructure\Logging\AuditLogger;

defined( 'ABSPATH' ) || exit;

class DiscussionRegistrar {
	const MAX_EMBED_URLS = 10;

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
		add_action( 'init', array( $this, 'register_discussion_content' ) );
		add_action( 'add_meta_boxes', array( $this, 'register_meta_boxes' ) );
		add_action( 'save_post_slms_discussion', array( $this, 'save_discussion_meta' ), 10, 2 );
		add_action( 'transition_post_status', array( $this, 'maybe_create_welcome_thread' ), 10, 3 );
	}

	public function register_meta_boxes() {
		add_meta_box(
			'slms_discussion_details',
			__( 'Discussion Details', 'simple-lms' ),
			array( $this, 'render_discussion_meta_box' ),
			'slms_discussion',
			'side',
			'high'
		);
	}

	public function register_discussion_content() {
		$discussion_caps = RoleManager::custom_post_type_args( 'slms_discussion', 'slms_discussions' );

		register_post_type(
			'slms_discussion',
			array_merge(
				array(
					'labels' => array(
						'name'               => __( 'Subject Discussions', 'simple-lms' ),
						'singular_name'      => __( 'Discussion Thread', 'simple-lms' ),
						'add_new_item'       => __( 'Add New Discussion Thread', 'simple-lms' ),
						'edit_item'          => __( 'Edit Discussion Thread', 'simple-lms' ),
						'new_item'           => __( 'New Discussion Thread', 'simple-lms' ),
						'view_item'          => __( 'View Discussion Thread', 'simple-lms' ),
						'search_items'       => __( 'Search Discussion Threads', 'simple-lms' ),
						'not_found'          => __( 'No discussion threads found.', 'simple-lms' ),
						'not_found_in_trash' => __( 'No discussion threads found in Trash.', 'simple-lms' ),
					),
					'public'              => false,
					'show_ui'             => true,
					'show_in_menu'        => 'slms-dashboard',
					'show_in_rest'        => true,
					'supports'            => array( 'title', 'editor', 'author', 'comments', 'revisions' ),
					'has_archive'         => false,
					'exclude_from_search' => true,
				),
				$discussion_caps
			)
		);

		register_post_meta(
			'slms_discussion',
			'_slms_subject_id',
			array(
				'show_in_rest'      => true,
				'single'            => true,
				'type'              => 'integer',
				'auth_callback'     => array( $this, 'can_participate' ),
				'sanitize_callback' => 'absint',
			)
		);

		register_post_meta(
			'slms_discussion',
			'_slms_embed_url',
			array(
				'show_in_rest'      => true,
				'single'            => true,
				'type'              => 'string',
				'auth_callback'     => array( $this, 'can_participate' ),
				'sanitize_callback' => 'esc_url_raw',
			)
		);

		register_post_meta(
			'slms_discussion',
			'_slms_embed_urls',
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
				'auth_callback'     => array( $this, 'can_participate' ),
				'sanitize_callback' => array( $this, 'sanitize_embed_urls' ),
			)
		);

		register_post_meta(
			'slms_discussion',
			'_slms_attachment_id',
			array(
				'show_in_rest'      => true,
				'single'            => true,
				'type'              => 'integer',
				'auth_callback'     => array( $this, 'can_participate' ),
				'sanitize_callback' => 'absint',
			)
		);

		register_post_meta(
			'slms_discussion',
			'_slms_is_welcome_thread',
			array(
				'show_in_rest'      => true,
				'single'            => true,
				'type'              => 'integer',
				'auth_callback'     => array( $this, 'can_participate' ),
				'sanitize_callback' => 'absint',
			)
		);

	}

	public function render_discussion_meta_box( $post ) {
		wp_nonce_field( 'slms_save_discussion_meta', 'slms_discussion_meta_nonce' );

		$workspace_id      = absint( get_post_meta( $post->ID, '_slms_subject_id', true ) );
		$embed_urls        = get_post_meta( $post->ID, '_slms_embed_urls', true );
		$is_welcome_thread = (int) get_post_meta( $post->ID, '_slms_is_welcome_thread', true );
		$workspaces        = $this->get_available_workspaces_for_current_user();
		$repeater_id       = wp_unique_id( 'slms-discussion-embed-urls-' );

		if ( ! is_array( $embed_urls ) || empty( $embed_urls ) ) {
			$legacy_url = get_post_meta( $post->ID, '_slms_embed_url', true );
			$embed_urls = $legacy_url ? array( $legacy_url ) : array( '' );
		}
		?>
		<p>
			<label for="slms_discussion_workspace_id"><?php esc_html_e( 'Workspace', 'simple-lms' ); ?></label><br>
			<select id="slms_discussion_workspace_id" name="slms_subject_id" style="width:100%;">
				<option value=""><?php esc_html_e( 'Select a workspace', 'simple-lms' ); ?></option>
				<?php foreach ( $workspaces as $workspace ) : ?>
					<option value="<?php echo esc_attr( $workspace['id'] ); ?>" <?php selected( $workspace_id, (int) $workspace['id'] ); ?>>
						<?php echo esc_html( $this->format_workspace_option_label( $workspace ) ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>
		<div id="<?php echo esc_attr( $repeater_id ); ?>" data-max-items="<?php echo esc_attr( (string) self::MAX_EMBED_URLS ); ?>" style="display:grid;gap:8px;margin:12px 0;">
			<strong><?php esc_html_e( 'Video / Audio Embed URLs', 'simple-lms' ); ?></strong>
			<div data-embed-list style="display:grid;gap:8px;">
				<?php foreach ( $embed_urls as $embed_url ) : ?>
					<div data-embed-row style="display:grid;grid-template-columns:minmax(0,1fr) auto;gap:8px;">
						<input type="url" name="slms_embed_urls[]" value="<?php echo esc_attr( $embed_url ); ?>" style="width:100%;" placeholder="https://">
						<button type="button" class="button" data-remove-embed><?php esc_html_e( 'Remove', 'simple-lms' ); ?></button>
					</div>
				<?php endforeach; ?>
			</div>
			<button type="button" class="button" data-add-embed><?php esc_html_e( 'Embed another video/audio', 'simple-lms' ); ?></button>
			<span class="description"><?php echo esc_html( sprintf( __( 'Add up to %d links.', 'simple-lms' ), self::MAX_EMBED_URLS ) ); ?></span>
		</div>
		<script>
			(function () {
				var repeater = document.getElementById(<?php echo wp_json_encode( $repeater_id ); ?>);
				if (!repeater || repeater.dataset.bound) {
					return;
				}
				repeater.dataset.bound = '1';
				var list = repeater.querySelector('[data-embed-list]');
				var addButton = repeater.querySelector('[data-add-embed]');
				var maxItems = parseInt(repeater.getAttribute('data-max-items') || '10', 10);
				function rows() {
					return Array.prototype.slice.call(list.querySelectorAll('[data-embed-row]'));
				}
				function sync() {
					var currentRows = rows();
					currentRows.forEach(function (row) {
						row.querySelector('[data-remove-embed]').hidden = currentRows.length < 2;
					});
					addButton.hidden = currentRows.length >= maxItems;
				}
				addButton.addEventListener('click', function () {
					if (rows().length >= maxItems) {
						return;
					}
					var row = rows()[0].cloneNode(true);
					var input = row.querySelector('input');
					input.value = '';
					list.appendChild(row);
					sync();
					input.focus();
				});
				list.addEventListener('click', function (event) {
					var button = event.target.closest('[data-remove-embed]');
					if (!button || rows().length < 2) {
						return;
					}
					button.closest('[data-embed-row]').remove();
					sync();
				});
				sync();
			}());
		</script>
		<?php if ( $is_welcome_thread ) : ?>
			<p>
				<span class="description"><?php esc_html_e( 'This thread is marked as the workspace welcome thread.', 'simple-lms' ); ?></span>
			</p>
		<?php endif; ?>
		<?php
	}

	public function save_discussion_meta( $post_id, $post ) {
		if ( ! $this->can_save_post_meta( $post_id, 'slms_discussion_meta_nonce', 'slms_save_discussion_meta' ) ) {
			return;
		}

		$workspace_id = absint( $_POST['slms_subject_id'] ?? 0 );
		$raw_urls     = array_key_exists( 'slms_embed_urls', $_POST )
			? (array) wp_unslash( $_POST['slms_embed_urls'] )
			: array( wp_unslash( $_POST['slms_embed_url'] ?? '' ) );
		$embed_urls   = $this->sanitize_embed_urls( $raw_urls );
		$embed_url    = $embed_urls ? $embed_urls[0] : '';
		if ( ! $this->current_user_can_reference_workspace( $workspace_id ) ) {
			$workspace_id = 0;
		}

		update_post_meta( $post_id, '_slms_subject_id', $workspace_id );
		if ( $embed_urls ) {
			update_post_meta( $post_id, '_slms_embed_urls', $embed_urls );
			update_post_meta( $post_id, '_slms_embed_url', $embed_url );
		} else {
			delete_post_meta( $post_id, '_slms_embed_urls' );
			delete_post_meta( $post_id, '_slms_embed_url' );
		}
		delete_post_meta( $post_id, '_slms_week_number' );
		delete_post_meta( $post_id, '_slms_week_order' );

		$this->audit_logger->log(
			'discussion_saved',
			array(
				'object_type' => 'discussion',
				'object_id'   => $post_id,
				'message'     => sprintf( 'Discussion "%s" was saved.', $post->post_title ),
				'context'     => array(
					'subject_id' => $workspace_id,
					'embed_url'  => $embed_url,
					'embed_urls' => $embed_urls,
				),
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

			if ( count( $urls ) >= self::MAX_EMBED_URLS ) {
				break;
			}
		}

		return $urls;
	}

	public function maybe_create_welcome_thread( $new_status, $old_status, $post ) {
		if ( ! $post || 'slms_subject' !== $post->post_type ) {
			return;
		}

		if ( 'publish' !== $new_status || 'publish' === $old_status ) {
			return;
		}

		$existing = get_posts(
			array(
				'post_type'      => 'slms_discussion',
				'post_status'    => array( 'publish', 'private', 'draft' ),
				'posts_per_page' => 1,
				'meta_query'     => array(
					array(
						'key'   => '_slms_subject_id',
						'value' => $post->ID,
					),
					array(
						'key'   => '_slms_is_welcome_thread',
						'value' => 1,
					),
				),
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		if ( ! empty( $existing ) ) {
			return;
		}

		$thread_id = wp_insert_post(
			array(
				'post_type'    => 'slms_discussion',
				'post_status'  => 'publish',
				'post_title'   => sprintf( __( 'Welcome to %s', 'simple-lms' ), $post->post_title ),
				'post_content' => __( 'Welcome to this subject discussion space. Feel free to introduce yourself, ask questions, and support one another throughout the semester.', 'simple-lms' ),
				'post_author'  => $post->post_author ?: get_current_user_id(),
				'comment_status' => 'open',
			),
			true
		);

		if ( is_wp_error( $thread_id ) ) {
			return;
		}

		update_post_meta( $thread_id, '_slms_subject_id', $post->ID );
		update_post_meta( $thread_id, '_slms_is_welcome_thread', 1 );
	}

	public function can_participate() {
		return current_user_can( RoleManager::CAP_PARTICIPATE_LEARNING ) || current_user_can( RoleManager::CAP_MANAGE_OWN_SECTIONS ) || current_user_can( RoleManager::CAP_MANAGE_ACADEMICS ) || current_user_can( RoleManager::CAP_MANAGE_COMMUNICATIONS );
	}

	private function get_available_workspaces_for_current_user() {
		return $this->enrollments->get_teaching_sections_for_user( get_current_user_id() );
	}

	private function format_workspace_option_label( array $workspace ) {
		$label = (string) ( $workspace['title'] ?? '' );

		if ( 'slms_section' === ( $workspace['post_type'] ?? '' ) && ! empty( $workspace['subject_title'] ) ) {
			$label .= ' - ' . $workspace['subject_title'];
		}

		if ( ! empty( $workspace['section_code'] ) ) {
			$label .= ' (' . $workspace['section_code'] . ')';
		}

		return $label;
	}

	private function current_user_can_reference_workspace( $workspace_id ) {
		return $workspace_id && $this->enrollments->user_can_teach_section( get_current_user_id(), $workspace_id );
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
