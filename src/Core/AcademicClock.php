<?php

namespace SimpleLMS\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Single source of truth for Simple LMS academic dates and times.
 *
 * LMS DATETIME values are stored as Yangon wall-clock values. This keeps the
 * plugin independent from the WordPress site timezone without changing global
 * WordPress settings.
 */
class AcademicClock {
	const TIMEZONE = 'Asia/Yangon';

	/**
	 * @var \DateTimeZone|null
	 */
	private static $timezone = null;

	public static function timezone() {
		if ( null === self::$timezone ) {
			self::$timezone = new \DateTimeZone( self::TIMEZONE );
		}

		return self::$timezone;
	}

	public static function now() {
		return new \DateTimeImmutable( 'now', self::timezone() );
	}

	public static function timestamp() {
		return self::now()->getTimestamp();
	}

	public static function mysql() {
		return self::now()->format( 'Y-m-d H:i:s' );
	}

	public static function date( $format = 'Y-m-d', $timestamp = null ) {
		$timestamp = null === $timestamp ? self::timestamp() : (int) $timestamp;

		return wp_date( (string) $format, $timestamp, self::timezone() );
	}

	public static function parse( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return null;
		}

		$normalized = preg_replace( '/\s+/', ' ', str_replace( 'T', ' ', $value ) );

		foreach ( array( '!Y-m-d H:i:s', '!Y-m-d H:i', '!Y-m-d' ) as $format ) {
			$date = \DateTimeImmutable::createFromFormat( $format, $normalized, self::timezone() );

			if ( $date instanceof \DateTimeImmutable ) {
				$errors = \DateTimeImmutable::getLastErrors();

				if ( false === $errors || ( empty( $errors['warning_count'] ) && empty( $errors['error_count'] ) ) ) {
					return $date;
				}
			}
		}

		try {
			return new \DateTimeImmutable( $normalized, self::timezone() );
		} catch ( \Exception $exception ) {
			return null;
		}
	}

	public static function normalize_mysql( $value ) {
		$date = self::parse( $value );

		return $date ? $date->setTimezone( self::timezone() )->format( 'Y-m-d H:i:s' ) : '';
	}

	public static function timestamp_from_local( $value ) {
		$date = self::parse( $value );

		return $date ? $date->getTimestamp() : 0;
	}

	public static function format_local( $value, $format, $empty_label = '' ) {
		$date = self::parse( $value );

		if ( ! $date ) {
			return (string) $empty_label;
		}

		return wp_date( (string) $format, $date->getTimestamp(), self::timezone() );
	}

	public static function timestamp_from_utc( $value ) {
		try {
			$date = new \DateTimeImmutable( trim( (string) $value ), new \DateTimeZone( 'UTC' ) );
		} catch ( \Exception $exception ) {
			return 0;
		}

		return $date->getTimestamp();
	}

	public static function format_utc( $value, $format, $empty_label = '' ) {
		$timestamp = self::timestamp_from_utc( $value );

		return $timestamp ? self::date( $format, $timestamp ) : (string) $empty_label;
	}
}
