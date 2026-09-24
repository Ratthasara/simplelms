<?php

namespace SimpleLMS\Domain\Assessment;

use SimpleLMS\Domain\Settings\SettingsManager;

defined( 'ABSPATH' ) || exit;

class GradeScaleService {
	const MODE_STANDARD   = 'standard';
	const MODE_PLUS_MINUS = 'plus_minus';
	const FAIL_E          = 'E';
	const FAIL_F          = 'F';

	/**
	 * @var SettingsManager
	 */
	private $settings;

	public function __construct( SettingsManager $settings ) {
		$this->settings = $settings;
	}

	public static function is_valid_mode( $mode ) {
		return in_array( $mode, array( self::MODE_STANDARD, self::MODE_PLUS_MINUS ), true );
	}

	public static function is_valid_fail_letter( $letter ) {
		return in_array( strtoupper( (string) $letter ), array( self::FAIL_E, self::FAIL_F ), true );
	}

	public static function get_slots( $mode ) {
		if ( self::MODE_PLUS_MINUS === $mode ) {
			return array(
				'a_plus',
				'a',
				'a_minus',
				'b_plus',
				'b',
				'b_minus',
				'c_plus',
				'c',
				'c_minus',
				'd_plus',
				'd',
				'd_minus',
				'fail',
			);
		}

		return array( 'a', 'b', 'c', 'd', 'fail' );
	}

	public static function build_default_rows( $mode, $fail_letter ) {
		$fail_letter = self::is_valid_fail_letter( $fail_letter ) ? strtoupper( (string) $fail_letter ) : self::FAIL_F;

		if ( self::MODE_PLUS_MINUS === $mode ) {
			return array(
				'a_plus'  => array( 'label' => 'A+', 'min' => 97.00, 'max' => 100.00, 'points' => 4.00 ),
				'a'       => array( 'label' => 'A', 'min' => 93.00, 'max' => 96.99, 'points' => 4.00 ),
				'a_minus' => array( 'label' => 'A-', 'min' => 90.00, 'max' => 92.99, 'points' => 3.70 ),
				'b_plus'  => array( 'label' => 'B+', 'min' => 87.00, 'max' => 89.99, 'points' => 3.30 ),
				'b'       => array( 'label' => 'B', 'min' => 83.00, 'max' => 86.99, 'points' => 3.00 ),
				'b_minus' => array( 'label' => 'B-', 'min' => 80.00, 'max' => 82.99, 'points' => 2.70 ),
				'c_plus'  => array( 'label' => 'C+', 'min' => 77.00, 'max' => 79.99, 'points' => 2.30 ),
				'c'       => array( 'label' => 'C', 'min' => 73.00, 'max' => 76.99, 'points' => 2.00 ),
				'c_minus' => array( 'label' => 'C-', 'min' => 70.00, 'max' => 72.99, 'points' => 1.70 ),
				'd_plus'  => array( 'label' => 'D+', 'min' => 67.00, 'max' => 69.99, 'points' => 1.30 ),
				'd'       => array( 'label' => 'D', 'min' => 63.00, 'max' => 66.99, 'points' => 1.00 ),
				'd_minus' => array( 'label' => 'D-', 'min' => 60.00, 'max' => 62.99, 'points' => 0.70 ),
				'fail'    => array( 'label' => $fail_letter, 'min' => 0.00, 'max' => 59.99, 'points' => 0.00 ),
			);
		}

		return array(
			'a'    => array( 'label' => 'A', 'min' => 85.00, 'max' => 100.00, 'points' => 4.00 ),
			'b'    => array( 'label' => 'B', 'min' => 75.00, 'max' => 84.99, 'points' => 3.00 ),
			'c'    => array( 'label' => 'C', 'min' => 65.00, 'max' => 74.99, 'points' => 2.00 ),
			'd'    => array( 'label' => 'D', 'min' => 50.00, 'max' => 64.99, 'points' => 1.00 ),
			'fail' => array( 'label' => $fail_letter, 'min' => 0.00, 'max' => 49.99, 'points' => 0.00 ),
		);
	}

	public static function normalize_rows( $rows_input, $mode, $fail_letter, $points_enabled ) {
		$mode          = self::is_valid_mode( $mode ) ? $mode : self::MODE_STANDARD;
		$fail_letter   = self::is_valid_fail_letter( $fail_letter ) ? strtoupper( (string) $fail_letter ) : self::FAIL_F;
		$points_enabled = ! empty( $points_enabled );
		$defaults      = self::build_default_rows( $mode, $fail_letter );
		$rows_input    = is_array( $rows_input ) ? $rows_input : array();
		$rows          = array();
		$labels_seen   = array();
		$previous_min  = null;

		foreach ( self::get_slots( $mode ) as $slot ) {
			$default_row = $defaults[ $slot ];
			$input_row   = isset( $rows_input[ $slot ] ) && is_array( $rows_input[ $slot ] ) ? $rows_input[ $slot ] : array();

			$label = 'fail' === $slot
				? $fail_letter
				: sanitize_text_field( (string) ( $input_row['label'] ?? $default_row['label'] ) );
			$min   = isset( $input_row['min'] ) && is_numeric( $input_row['min'] ) ? round( (float) $input_row['min'], 2 ) : null;
			$max   = isset( $input_row['max'] ) && is_numeric( $input_row['max'] ) ? round( (float) $input_row['max'], 2 ) : null;

			if ( '' === $label ) {
				return new \WP_Error( 'slms_grade_label_empty', __( 'Each grade band needs a label.', 'simple-lms' ) );
			}

			if ( isset( $labels_seen[ strtolower( $label ) ] ) ) {
				return new \WP_Error( 'slms_grade_label_duplicate', __( 'Grade band labels must be unique.', 'simple-lms' ) );
			}

			if ( null === $min || null === $max ) {
				return new \WP_Error( 'slms_grade_range_invalid', __( 'Every grade band needs numeric minimum and maximum percentages.', 'simple-lms' ) );
			}

			if ( $min < 0 || $max > 100 || $min > $max ) {
				return new \WP_Error( 'slms_grade_range_bounds', __( 'Grade band percentages must stay within 0 to 100 and minimum cannot exceed maximum.', 'simple-lms' ) );
			}

			if ( null !== $previous_min && $min >= $previous_min ) {
				return new \WP_Error( 'slms_grade_order_invalid', __( 'Grade bands must be ordered from highest minimum percentage to lowest.', 'simple-lms' ) );
			}

			$points = '';
			if ( $points_enabled ) {
				if ( ! isset( $input_row['points'] ) || '' === trim( (string) $input_row['points'] ) || ! is_numeric( $input_row['points'] ) ) {
					return new \WP_Error( 'slms_grade_points_invalid', __( 'Every grade band needs numeric grade points when points are enabled.', 'simple-lms' ) );
				}

				$points = round( (float) $input_row['points'], 2 );
			}

			$rows[ $slot ] = array(
				'label'  => $label,
				'min'    => $min,
				'max'    => $max,
				'points' => $points,
			);

			$labels_seen[ strtolower( $label ) ] = true;
			$previous_min                         = $min;
		}

		$ordered_rows = array_values( $rows );
		$first_row    = $ordered_rows[0];
		$last_row     = $ordered_rows[ count( $ordered_rows ) - 1 ];

		if ( 100.0 !== (float) $first_row['max'] ) {
			return new \WP_Error( 'slms_grade_top_band_invalid', __( 'The highest grade band must end at 100.', 'simple-lms' ) );
		}

		if ( 0.0 !== (float) $last_row['min'] ) {
			return new \WP_Error( 'slms_grade_fail_band_invalid', __( 'The fail grade band must begin at 0.', 'simple-lms' ) );
		}

		foreach ( $ordered_rows as $index => $row ) {
			if ( ! isset( $ordered_rows[ $index + 1 ] ) ) {
				continue;
			}

			$next_row = $ordered_rows[ $index + 1 ];

			if ( (float) $next_row['max'] >= (float) $row['min'] ) {
				return new \WP_Error( 'slms_grade_overlap', __( 'Grade band ranges cannot overlap. Lower bands must end below the next higher band.', 'simple-lms' ) );
			}
		}

		return $rows;
	}

	public function is_configured() {
		return ! empty( $this->settings->get( 'grading_scale_configured', 0 ) );
	}

	public function uses_points() {
		if ( ! $this->is_configured() ) {
			return true;
		}

		return ! empty( $this->settings->get( 'grading_points_enabled', 0 ) );
	}

	public function get_summary_metric_key() {
		if ( ! $this->is_configured() ) {
			return 'cgpa';
		}

		return $this->uses_points() ? 'grade_index' : 'cumulative_average';
	}

	public function get_scale_rows() {
		if ( ! $this->is_configured() ) {
			return self::build_legacy_rows();
		}

		$mode         = $this->settings->get( 'grading_system_mode', self::MODE_PLUS_MINUS );
		$fail_letter  = $this->settings->get( 'grading_fail_letter', self::FAIL_F );
		$saved_rows   = $this->settings->get( 'grading_scale_rows', array() );
		$default_rows = self::build_default_rows( $mode, $fail_letter );
		$rows         = array();

		foreach ( self::get_slots( $mode ) as $slot ) {
			$row = isset( $saved_rows[ $slot ] ) && is_array( $saved_rows[ $slot ] ) ? $saved_rows[ $slot ] : $default_rows[ $slot ];

			$rows[ $slot ] = array(
				'label'  => (string) ( $row['label'] ?? $default_rows[ $slot ]['label'] ),
				'min'    => round( (float) ( $row['min'] ?? $default_rows[ $slot ]['min'] ), 2 ),
				'max'    => round( (float) ( $row['max'] ?? $default_rows[ $slot ]['max'] ), 2 ),
				'points' => $this->uses_points() ? round( (float) ( $row['points'] ?? $default_rows[ $slot ]['points'] ), 2 ) : null,
			);
		}

		return $rows;
	}

	public function get_frontend_scale_payload() {
		$bands = array();

		foreach ( $this->get_scale_rows() as $row ) {
			$bands[] = array(
				'label'  => (string) $row['label'],
				'min'    => round( (float) $row['min'], 2 ),
				'max'    => round( (float) $row['max'], 2 ),
				'points' => null !== $row['points'] ? round( (float) $row['points'], 2 ) : null,
			);
		}

		return array(
			'bands'          => $bands,
			'points_enabled' => $this->uses_points(),
		);
	}

	public function resolve( $percentage ) {
		$percentage = round( max( 0, min( 100, (float) $percentage ) ), 2 );
		$rows       = array_values( $this->get_scale_rows() );

		foreach ( $rows as $row ) {
			if ( $percentage >= (float) $row['min'] && $percentage <= (float) $row['max'] ) {
				return $this->format_resolution( $row );
			}
		}

		foreach ( $rows as $row ) {
			if ( $percentage >= (float) $row['min'] ) {
				return $this->format_resolution( $row );
			}
		}

		return $this->format_resolution( $rows[ count( $rows ) - 1 ] );
	}

	private function format_resolution( array $row ) {
		return array(
			'letter' => (string) $row['label'],
			'points' => null !== $row['points'] ? round( (float) $row['points'], 2 ) : null,
			'min'    => round( (float) $row['min'], 2 ),
			'max'    => round( (float) $row['max'], 2 ),
		);
	}

	private static function build_legacy_rows() {
		return array(
			'a'       => array( 'label' => 'A', 'min' => 93.00, 'max' => 100.00, 'points' => 4.00 ),
			'a_minus' => array( 'label' => 'A-', 'min' => 90.00, 'max' => 92.99, 'points' => 3.70 ),
			'b_plus'  => array( 'label' => 'B+', 'min' => 87.00, 'max' => 89.99, 'points' => 3.30 ),
			'b'       => array( 'label' => 'B', 'min' => 83.00, 'max' => 86.99, 'points' => 3.00 ),
			'b_minus' => array( 'label' => 'B-', 'min' => 80.00, 'max' => 82.99, 'points' => 2.70 ),
			'c_plus'  => array( 'label' => 'C+', 'min' => 77.00, 'max' => 79.99, 'points' => 2.30 ),
			'c'       => array( 'label' => 'C', 'min' => 73.00, 'max' => 76.99, 'points' => 2.00 ),
			'c_minus' => array( 'label' => 'C-', 'min' => 70.00, 'max' => 72.99, 'points' => 1.70 ),
			'd_plus'  => array( 'label' => 'D+', 'min' => 67.00, 'max' => 69.99, 'points' => 1.30 ),
			'd'       => array( 'label' => 'D', 'min' => 63.00, 'max' => 66.99, 'points' => 1.00 ),
			'd_minus' => array( 'label' => 'D-', 'min' => 60.00, 'max' => 62.99, 'points' => 0.70 ),
			'fail'    => array( 'label' => 'F', 'min' => 0.00, 'max' => 59.99, 'points' => 0.00 ),
		);
	}
}
