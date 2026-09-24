<?php

namespace SimpleLMS;

defined( 'ABSPATH' ) || exit;

class Autoloader {
	public static function register() {
		spl_autoload_register( array( __CLASS__, 'autoload' ) );
	}

	public static function autoload( $class ) {
		$prefix = __NAMESPACE__ . '\\';

		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		$relative_class = substr( $class, strlen( $prefix ) );
		$relative_path  = str_replace( '\\', DIRECTORY_SEPARATOR, $relative_class );
		$file           = SLMS_PATH . 'src' . DIRECTORY_SEPARATOR . $relative_path . '.php';

		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
}
