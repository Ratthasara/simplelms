<?php

namespace SimpleLMS\Domain\Learning;

use SimpleLMS\Domain\Academic\EnrollmentService;
use SimpleLMS\Domain\Assessment\AssessmentService;
use SimpleLMS\Domain\Users\ProfileService;
use SimpleLMS\Domain\Users\RoleManager;
use WP_Error;
use WP_Query;

defined( 'ABSPATH' ) || exit;

class WorkspaceService {
	/**
	 * @var EnrollmentService
	 */
	private $enrollments;

	/**
	 * @var ProfileService
	 */
	private $profiles;

	/**
	 * @var AssessmentService
	 */
	private $assessments;

	public function __construct( EnrollmentService $enrollments, ProfileService $profiles, AssessmentService $assessments ) {
		$this->enrollments = $enrollments;
		$this->profiles    = $profiles;
		$this->assessments = $assessments;
	}

	public function get_dashboard_snapshot( $user_id = 0 ) {
		$user_id      = absint( $user_id ?: get_current_user_id() );
		$sections     = $this->enrollments->get_sections_for_user( $user_id );
		$section_ids  = array_map( 'absint', wp_list_pluck( $sections, 'id' ) );
		$role         = RoleManager::get_primary_role( $user_id );
		$profile      = $this->profiles->get_profile( $user_id );
		$recent_lessons = $this->get_recent_content( 'slms_lesson', $user_id, $section_ids, 5 );
		$recent_announcements = $this->get_recent_content( 'slms_announcement', $user_id, $section_ids, 5 );
		$recent_assignments = $this->assessments->get_recent_assignments( $user_id, 5 );

		return array(
			'role'                 => $role,
			'profile'              => $profile,
			'section_count'        => count( $sections ),
			'sections'             => array_slice( $sections, 0, 6 ),
			'recent_lessons'       => $recent_lessons,
			'recent_announcements' => $recent_announcements,
			'recent_assignments'   => $recent_assignments,
		);
	}

	public function get_section_workspace( $user_id, $section_id ) {
		$user_id    = absint( $user_id );
		$section_id = absint( $section_id );

		if ( ! $this->enrollments->user_can_learn_section( $user_id, $section_id ) ) {
			return new WP_Error( 'slms_forbidden_section', __( 'You do not have access to that section.', 'simple-lms' ) );
		}

		return array(
			'section'       => $this->enrollments->get_section_summary( $section_id ),
			'lessons'       => $this->get_section_content( 'slms_lesson', $user_id, $section_id, 20 ),
			'announcements' => $this->get_section_content( 'slms_announcement', $user_id, $section_id, 20, true ),
			'assignments'   => $this->assessments->get_section_assignments( $user_id, $section_id, 20 ),
		);
	}

	public function get_content_item( $user_id, $post_id ) {
		$post_id = absint( $post_id );
		$post    = get_post( $post_id );

		if ( ! $post || ! in_array( $post->post_type, array( 'slms_lesson', 'slms_announcement' ), true ) ) {
			return null;
		}

		$section_id = absint( get_post_meta( $post_id, '_slms_section_id', true ) );
		$scope      = get_post_meta( $post_id, '_slms_announcement_scope', true );

		if ( 'slms_announcement' === $post->post_type && 'global' === $scope ) {
			if ( ! in_array( $post->post_status, $this->get_visible_post_statuses( $user_id ), true ) ) {
				return null;
			}

			return $this->format_content_item( $post, 0, true );
		}

		if ( ! $section_id || ! $this->enrollments->user_can_learn_section( $user_id, $section_id ) ) {
			return null;
		}

		if ( ! in_array( $post->post_status, $this->get_visible_post_statuses_for_section( $user_id, $section_id ), true ) ) {
			return null;
		}

		return $this->format_content_item( $post, $section_id, false );
	}

	private function get_section_content( $post_type, $user_id, $section_id, $limit = 20, $allow_global = false ) {
		$items  = array();
		$query  = new WP_Query(
			array(
				'post_type'      => $post_type,
				'post_status'    => $this->get_visible_post_statuses_for_section( $user_id, $section_id ),
				'posts_per_page' => $limit,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		foreach ( $query->posts as $post ) {
			$item_section_id = absint( get_post_meta( $post->ID, '_slms_section_id', true ) );
			$scope           = get_post_meta( $post->ID, '_slms_announcement_scope', true );

			if ( $allow_global && 'slms_announcement' === $post_type && 'global' === $scope ) {
				$items[] = $this->format_content_item( $post, 0, true );
				continue;
			}

			if ( $item_section_id !== $section_id ) {
				continue;
			}

			$items[] = $this->format_content_item( $post, $item_section_id, false );
		}

		return $items;
	}

	private function get_recent_content( $post_type, $user_id, array $section_ids, $limit = 5 ) {
		$items = array();
		$query = new WP_Query(
			array(
				'post_type'      => $post_type,
				'post_status'    => $this->get_visible_post_statuses( $user_id ),
				'posts_per_page' => 25,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		foreach ( $query->posts as $post ) {
			$section_id = absint( get_post_meta( $post->ID, '_slms_section_id', true ) );
			$scope      = get_post_meta( $post->ID, '_slms_announcement_scope', true );

			if ( 'slms_announcement' === $post_type && 'global' === $scope ) {
				$items[] = $this->format_content_item( $post, 0, true );
			} elseif ( in_array( $section_id, $section_ids, true ) ) {
				$items[] = $this->format_content_item( $post, $section_id, false );
			}

			if ( count( $items ) >= $limit ) {
				break;
			}
		}

		return $items;
	}

	private function get_visible_post_statuses_for_section( $user_id, $section_id ) {
		$user_id    = absint( $user_id );
		$section_id = absint( $section_id );

		if ( $section_id && $this->enrollments->user_can_teach_section( $user_id, $section_id ) ) {
			return array( 'publish', 'private', 'draft', 'pending' );
		}

		return array( 'publish', 'private' );
	}

	private function get_visible_post_statuses( $user_id ) {
		$role = RoleManager::get_primary_role( $user_id );

		if ( in_array( $role, array( 'administrator', 'officer', 'lecturer' ), true ) ) {
			return array( 'publish', 'private', 'draft', 'pending' );
		}

		return array( 'publish' );
	}

	private function format_content_item( $post, $section_id, $is_global ) {
		$section = $section_id ? $this->enrollments->get_section_summary( $section_id ) : null;

		return array(
			'id'            => $post->ID,
			'post_type'     => $post->post_type,
			'title'         => $post->post_title,
			'excerpt'       => wp_trim_words( wp_strip_all_tags( $post->post_excerpt ?: $post->post_content ), 28 ),
			'post_status'   => $post->post_status,
			'section_id'    => $section_id,
			'section_title' => $section['title'] ?? '',
			'is_global'     => $is_global,
			'published_at'  => mysql2date( 'Y-m-d H:i', $post->post_date ),
		);
	}
}
