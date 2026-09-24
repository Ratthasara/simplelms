<?php

namespace SimpleLMS\Core;

defined( 'ABSPATH' ) || exit;

use InvalidArgumentException;

class ServiceContainer {
	/**
	 * @var array<string, callable>
	 */
	private $bindings = array();

	/**
	 * @var array<string, mixed>
	 */
	private $instances = array();

	public function singleton( $id, callable $factory ) {
		$this->bindings[ $id ] = $factory;
	}

	public function get( $id ) {
		if ( isset( $this->instances[ $id ] ) ) {
			return $this->instances[ $id ];
		}

		if ( ! isset( $this->bindings[ $id ] ) ) {
			throw new InvalidArgumentException( sprintf( 'Service "%s" is not registered.', $id ) );
		}

		$this->instances[ $id ] = call_user_func( $this->bindings[ $id ], $this );

		return $this->instances[ $id ];
	}

	public function has( $id ) {
		return isset( $this->instances[ $id ] ) || isset( $this->bindings[ $id ] );
	}
}
