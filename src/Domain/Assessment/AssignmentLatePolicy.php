<?php

namespace SimpleLMS\Domain\Assessment;

use SimpleLMS\Core\AcademicClock;

defined( 'ABSPATH' ) || exit;

/**
 * Centralized late-submission policy for assignment scoring.
 *
 * The raw lecturer score is preserved. The late multiplier is applied only when
 * calculating weighted contribution or an adjusted gradebook score.
 */
class AssignmentLatePolicy {
	const DAILY_DEDUCTION_RATE    = 0.05;
	const MAX_PENALTY_DAYS        = 10;
	const DISPLAY_DATETIME_FORMAT = 'd-m-Y g:i A';

	public static function get_late_days( $due_at, $submitted_at = '' ) {
		$due_timestamp       = self::parse_datetime( $due_at );
		$submitted_timestamp = self::parse_datetime( $submitted_at ?: AcademicClock::mysql() );

		if ( ! $due_timestamp || ! $submitted_timestamp || $submitted_timestamp <= $due_timestamp ) {
			return 0;
		}

		$day_seconds = defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400;

		return max( 0, (int) ceil( ( $submitted_timestamp - $due_timestamp ) / $day_seconds ) );
	}

	public static function get_late_multiplier( $due_at, $submitted_at = '' ) {
		$late_days = self::get_late_days( $due_at, $submitted_at );

		if ( $late_days <= 0 ) {
			return 1.0;
		}

		if ( $late_days > self::MAX_PENALTY_DAYS ) {
			return 0.0;
		}

		return max( 0.0, 1.0 - ( $late_days * self::DAILY_DEDUCTION_RATE ) );
	}

	public static function get_adjustment_details( array $assignment, $submission = null ) {
		$due_at       = (string) ( $assignment['due_at'] ?? '' );
		$submitted_at = '';

		if ( is_array( $submission ) ) {
			$submitted_at = (string) ( $submission['submitted_at'] ?? ( $submission['updated_at'] ?? '' ) );
		}

		if ( '' === $submitted_at ) {
			$submitted_at = AcademicClock::mysql();
		}

		$late_days          = self::get_late_days( $due_at, $submitted_at );
		$late_multiplier    = self::get_late_multiplier( $due_at, $submitted_at );
		$penalty_percentage = round( ( 1.0 - $late_multiplier ) * 100, 2 );

		return array(
			'late_days'              => $late_days,
			'late_multiplier'        => $late_multiplier,
			'late_penalty_percent'   => $penalty_percentage,
			'is_late'                => $late_days > 0,
			'is_over_late_limit'     => $late_days > self::MAX_PENALTY_DAYS,
			'counts_toward_final'    => $late_multiplier > 0,
			'policy_label'           => self::format_policy_label( $late_days, $late_multiplier ),
			'policy_short_label'     => self::format_short_policy_label( $late_days, $late_multiplier ),
		);
	}

	public static function format_policy_label( $late_days, $late_multiplier ) {
		$late_days       = max( 0, (int) $late_days );
		$late_multiplier = max( 0.0, min( 1.0, (float) $late_multiplier ) );

		if ( $late_days <= 0 ) {
			return __( 'On time. No late deduction.', 'simple-lms' );
		}

		if ( $late_days > self::MAX_PENALTY_DAYS ) {
			return __( 'More than 10 days late. This assignment can be graded, but it contributes 0.00 toward the final score.', 'simple-lms' );
		}

		$penalty_percentage = round( ( 1.0 - $late_multiplier ) * 100, 2 );

		return sprintf(
			/* translators: 1: late days, 2: deduction percentage, 3: multiplier percentage */
			_n( '%1$d day late. %2$s%% deduction; %3$s%% of the assignment contribution counts.', '%1$d days late. %2$s%% deduction; %3$s%% of the assignment contribution counts.', $late_days, 'simple-lms' ),
			$late_days,
			number_format_i18n( $penalty_percentage, 0 ),
			number_format_i18n( $late_multiplier * 100, 0 )
		);
	}

	public static function format_short_policy_label( $late_days, $late_multiplier ) {
		$late_days       = max( 0, (int) $late_days );
		$late_multiplier = max( 0.0, min( 1.0, (float) $late_multiplier ) );

		if ( $late_days <= 0 ) {
			return __( 'No late deduction', 'simple-lms' );
		}

		if ( $late_days > self::MAX_PENALTY_DAYS ) {
			return __( 'More than 10 days late: 0 contribution', 'simple-lms' );
		}

		return sprintf(
			/* translators: 1: late days, 2: deduction percentage */
			_n( '%1$d day late, %2$s%% deduction', '%1$d days late, %2$s%% deduction', $late_days, 'simple-lms' ),
			$late_days,
			number_format_i18n( round( ( 1.0 - $late_multiplier ) * 100, 2 ), 0 )
		);
	}

	public static function normalize_datetime( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		return AcademicClock::normalize_mysql( $value );
	}

	public static function format_datetime_display( $value, $empty_label = '' ) {
		$normalized = self::normalize_datetime( $value );

		if ( '' === $normalized ) {
			return (string) $empty_label;
		}

		return AcademicClock::format_local( $normalized, self::DISPLAY_DATETIME_FORMAT, $empty_label );
	}

	public static function format_datetime_local_value( $value ) {
		$normalized = self::normalize_datetime( $value );

		if ( '' === $normalized ) {
			return '';
		}

		return str_replace( ' ', 'T', substr( $normalized, 0, 16 ) );
	}

	private static function parse_datetime( $value ) {
		$normalized = self::normalize_datetime( $value );

		if ( '' === $normalized ) {
			return 0;
		}

		return AcademicClock::timestamp_from_local( $normalized );
	}
}
