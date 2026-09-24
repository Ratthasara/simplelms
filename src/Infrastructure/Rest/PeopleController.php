<?php

namespace SimpleLMS\Infrastructure\Rest;

use SimpleLMS\Domain\Users\ProfileService;
use SimpleLMS\Domain\Users\ProvisioningService;
use SimpleLMS\Domain\Users\RoleManager;

defined( 'ABSPATH' ) || exit;

class PeopleController {
	/**
	 * @var ProfileService
	 */
	private $profiles;

	/**
	 * @var ProvisioningService
	 */
	private $provisioning;

	public function __construct( ProfileService $profiles, ProvisioningService $provisioning ) {
		$this->profiles     = $profiles;
		$this->provisioning = $provisioning;
	}

	public function register_hooks() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			'simple-lms/v1',
			'/people/profiles',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_profiles' ),
				'permission_callback' => array( $this, 'can_manage_people' ),
			)
		);

		register_rest_route(
			'simple-lms/v1',
			'/people/students',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_student' ),
				'permission_callback' => array( $this, 'can_manage_people' ),
			)
		);

		register_rest_route(
			'simple-lms/v1',
			'/people/staff',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_staff' ),
				'permission_callback' => array( $this, 'can_manage_people' ),
			)
		);
	}

	public function get_profiles( $request ) {
		return rest_ensure_response(
			array(
				'items' => $this->profiles->list_profiles(
					array(
						'person_type' => $request->get_param( 'person_type' ),
						'status'      => $request->get_param( 'status' ),
						'search'      => $request->get_param( 'search' ),
						'page'        => $request->get_param( 'page' ),
						'per_page'    => $request->get_param( 'per_page' ),
					)
				),
			)
		);
	}

	public function create_student( $request ) {
		$params = $request->get_json_params();

		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}

		$result = $this->provisioning->create_student( $params );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'user_id' => (int) $result,
				'profile' => $this->profiles->get_profile( $result ),
			)
		);
	}

	public function create_staff( $request ) {
		$params = $request->get_json_params();

		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}

		$result = $this->provisioning->create_staff( $params );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'user_id' => (int) $result,
				'profile' => $this->profiles->get_profile( $result ),
			)
		);
	}

	public function can_manage_people() {
		return current_user_can( RoleManager::CAP_MANAGE_USERS );
	}
}
