<?php

namespace SimpleLMS\Domain\Assessment;

defined( 'ABSPATH' ) || exit;

class AssignmentPointPolicy {
	/**
	 * Assignment maximum points are the denominator used in Results.
	 * Keep this value whole-numbered so the UI and calculation cannot disagree.
	 * Decimal scores for students are still allowed separately.
	 *
	 * @param mixed $value Raw point value.
	 * @return float
	 */
	public static function normalize_max_points( $value ) {
		if ( '' === $value || null === $value ) {
			return 0.0;
		}

		return max( 0.0, (float) round( (float) $value ) );
	}

	/**
	 * Format assignment max points without hiding legacy decimals.
	 * Existing decimal values should be visible until repaired, not rounded in the UI.
	 *
	 * @param mixed $value Raw point value.
	 * @return string
	 */
	public static function format_max_points( $value ) {
		$points = (float) $value;

		if ( abs( $points - round( $points ) ) < 0.001 ) {
			return number_format_i18n( $points, 0 );
		}

		return number_format_i18n( $points, 1 );
	}

	/**
	 * Whether the stored assignment max points contain a meaningful decimal value.
	 *
	 * @param mixed $value Raw point value.
	 * @return bool
	 */
	public static function has_decimal_max_points( $value ) {
		$points = (float) $value;

		return abs( $points - round( $points ) ) >= 0.001;
	}
}
