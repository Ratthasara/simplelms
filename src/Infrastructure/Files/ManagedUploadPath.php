<?php

namespace SimpleLMS\Infrastructure\Files;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves existing WordPress uploads without allowing traversal or symlink escape.
 */
class ManagedUploadPath {
	public static function from_url( $url ) {
		$url = esc_url_raw( (string) $url );

		if ( '' === $url ) {
			return '';
		}

		$uploads = wp_get_upload_dir();
		$baseurl = (string) ( $uploads['baseurl'] ?? '' );
		$base    = wp_parse_url( $baseurl );
		$target  = wp_parse_url( $url );

		if ( ! is_array( $base ) || ! is_array( $target ) ) {
			return '';
		}

		foreach ( array( 'scheme', 'host', 'port' ) as $component ) {
			if ( (string) ( $base[ $component ] ?? '' ) !== (string) ( $target[ $component ] ?? '' ) ) {
				return '';
			}
		}

		$base_path   = trailingslashit( (string) ( $base['path'] ?? '' ) );
		$target_path = (string) ( $target['path'] ?? '' );

		if ( '' === $base_path || 0 !== strpos( $target_path, $base_path ) ) {
			return '';
		}

		return self::from_relative( rawurldecode( substr( $target_path, strlen( $base_path ) ) ) );
	}

	public static function from_relative( $relative_path ) {
		$relative_path = str_replace( '\\', '/', (string) $relative_path );
		$relative_path = ltrim( $relative_path, '/' );

		if ( '' === $relative_path || self::has_unsafe_segment( $relative_path ) ) {
			return '';
		}

		$uploads = wp_get_upload_dir();
		$basedir = (string) ( $uploads['basedir'] ?? '' );

		if ( '' === $basedir ) {
			return '';
		}

		return self::canonical_file( trailingslashit( $basedir ) . $relative_path );
	}

	public static function canonical_file( $path ) {
		$uploads = wp_get_upload_dir();
		$basedir = realpath( (string) ( $uploads['basedir'] ?? '' ) );
		$target  = realpath( (string) $path );

		if ( false === $basedir || false === $target || ! is_file( $target ) ) {
			return '';
		}

		$basedir = wp_normalize_path( trailingslashit( $basedir ) );
		$target  = wp_normalize_path( $target );

		if ( ! self::has_path_prefix( $target, $basedir ) ) {
			return '';
		}

		return $target;
	}

	private static function has_unsafe_segment( $relative_path ) {
		if ( false !== strpos( $relative_path, "\0" ) ) {
			return true;
		}

		foreach ( explode( '/', $relative_path ) as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				return true;
			}
		}

		return false;
	}

	private static function has_path_prefix( $path, $prefix ) {
		if ( '\\' === DIRECTORY_SEPARATOR ) {
			return 0 === stripos( $path, $prefix );
		}

		return 0 === strpos( $path, $prefix );
	}
}
