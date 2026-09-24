<?php

namespace SimpleLMS\Infrastructure\Import;

use SimpleLMS\Core\AcademicClock;
use SimpleLMS\Domain\Academic\EnrollmentService;
use SimpleLMS\Domain\Academic\TermService;
use SimpleLMS\Domain\Users\ProfileService;
use SimpleLMS\Domain\Users\ProvisioningService;
use WP_Error;

defined( 'ABSPATH' ) || exit;

class ImportService {
	/**
	 * @var ProvisioningService
	 */
	private $provisioning;

	/**
	 * @var ProfileService
	 */
	private $profiles;

	/**
	 * @var EnrollmentService
	 */
	private $enrollments;

	/**
	 * @var TermService
	 */
	private $terms;

	public function __construct( ProvisioningService $provisioning, ProfileService $profiles, EnrollmentService $enrollments, TermService $terms ) {
		$this->provisioning = $provisioning;
		$this->profiles     = $profiles;
		$this->enrollments  = $enrollments;
		$this->terms        = $terms;
	}

	public function read_rows_from_request( $sheet_url, $file_field_name ) {
		$rows = array();

		if ( $sheet_url ) {
			$sheet_url = trim( (string) $sheet_url );
			$sheet_url = $this->normalize_google_sheet_csv_url( $sheet_url );

			if ( is_wp_error( $sheet_url ) ) {
				return $sheet_url;
			}

			$response = wp_remote_get(
				$sheet_url,
				array(
					'timeout' => 20,
				)
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code = wp_remote_retrieve_response_code( $response );

			if ( $code < 200 || $code >= 300 ) {
				return new WP_Error( 'slms_import_http', sprintf( __( 'Unable to fetch spreadsheet data. HTTP %d.', 'simple-lms' ), $code ) );
			}

			$body = wp_remote_retrieve_body( $response );

			if ( ! $body ) {
				return new WP_Error( 'slms_import_empty', __( 'The spreadsheet response was empty.', 'simple-lms' ) );
			}

			$handle = fopen( 'php://temp', 'r+' );

			if ( ! $handle ) {
				return new WP_Error( 'slms_import_stream', __( 'Unable to read spreadsheet data.', 'simple-lms' ) );
			}

			fwrite( $handle, $body );
			rewind( $handle );

			while ( ( $row = fgetcsv( $handle ) ) !== false ) {
				$rows[] = $row;
			}

			fclose( $handle );

			return $rows;
		}

		if ( empty( $_FILES[ $file_field_name ]['tmp_name'] ) ) {
			return new WP_Error( 'slms_import_missing_file', __( 'Please provide a published Google Sheet CSV link or upload a CSV/XLSX file.', 'simple-lms' ) );
		}

		$tmp_name = $_FILES[ $file_field_name ]['tmp_name'];
		$name     = $_FILES[ $file_field_name ]['name'] ?? '';
		$ext      = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );

		if ( 'csv' === $ext ) {
			$handle = fopen( $tmp_name, 'r' );

			if ( ! $handle ) {
				return new WP_Error( 'slms_import_csv_read', __( 'Unable to read the uploaded CSV file.', 'simple-lms' ) );
			}

			while ( ( $row = fgetcsv( $handle ) ) !== false ) {
				$rows[] = $row;
			}

			fclose( $handle );

			return $rows;
		}

		if ( 'xlsx' === $ext ) {
			return $this->read_xlsx_rows( $tmp_name );
		}

		return new WP_Error( 'slms_import_file_type', __( 'Unsupported file type. Please upload CSV or XLSX.', 'simple-lms' ) );
	}

	private function normalize_google_sheet_csv_url( $sheet_url ) {
		$parts = wp_parse_url( $sheet_url );

		if ( empty( $parts['scheme'] ) || 'https' !== strtolower( (string) $parts['scheme'] ) ) {
			return new WP_Error( 'slms_import_url_scheme', __( 'Spreadsheet imports must use a secure Google Sheets URL.', 'simple-lms' ) );
		}

		$host = strtolower( (string) ( $parts['host'] ?? '' ) );

		if ( 'docs.google.com' !== $host ) {
			return new WP_Error( 'slms_import_url_host', __( 'Remote imports are restricted to Google Sheets spreadsheet URLs.', 'simple-lms' ) );
		}

		$path = (string) ( $parts['path'] ?? '' );

		if ( ! preg_match( '#^/spreadsheets/d/([A-Za-z0-9_-]+)/(edit|export)#', $path, $matches ) ) {
			return new WP_Error( 'slms_import_url_path', __( 'Please provide a valid Google Sheets spreadsheet edit or CSV export URL.', 'simple-lms' ) );
		}

		$query = array();

		if ( ! empty( $parts['query'] ) ) {
			wp_parse_str( $parts['query'], $query );
		}

		$gid = isset( $query['gid'] ) ? preg_replace( '/[^0-9]/', '', (string) $query['gid'] ) : '';

		return 'https://docs.google.com/spreadsheets/d/' . rawurlencode( $matches[1] ) . '/export?format=csv' . ( '' !== $gid ? '&gid=' . rawurlencode( $gid ) : '' );
	}

	public function import_students( array $rows, array $defaults = array() ) {
		return $this->import_people( $rows, 'student', $defaults );
	}

	public function import_staff( array $rows, array $defaults = array() ) {
		return $this->import_people( $rows, 'staff', $defaults );
	}

	public function import_subjects( array $rows, array $defaults = array() ) {
		$mapped = $this->rows_to_assoc( $rows );

		if ( is_wp_error( $mapped ) ) {
			return $mapped;
		}

		$stats = array(
			'created' => 0,
			'updated' => 0,
			'failed'  => 0,
			'errors'  => array(),
		);

		foreach ( $mapped as $index => $row ) {
			$title = $this->get_value(
				$row,
				array(
					'title',
					'name',
					'subject',
					'subject_name',
					'course_name',
				)
			);
			$code  = strtoupper(
				$this->get_value(
					$row,
					array(
						'code',
						'subject_code',
						'course_code',
					)
				)
			);

			if ( '' === $title ) {
				$stats['failed']++;
				$stats['errors'][] = sprintf( __( 'Row %d: subject title is required.', 'simple-lms' ), $index + 2 );
				continue;
			}

			$program_ids = $this->resolve_subject_program_ids( $row );

			if ( is_wp_error( $program_ids ) ) {
				$stats['failed']++;
				$stats['errors'][] = sprintf( __( 'Row %1$d: %2$s', 'simple-lms' ), $index + 2, $program_ids->get_error_message() );
				continue;
			}

			$post_id = $this->find_subject_by_code_or_title( $code, $title );
			$payload = array(
				'post_type'    => 'slms_subject',
				'post_title'   => $title,
				'post_content' => wp_kses_post(
					$this->get_value(
						$row,
						array(
							'description',
							'summary',
							'details',
						)
					)
				),
				'post_excerpt' => sanitize_text_field(
					$this->get_value(
						$row,
						array(
							'excerpt',
							'short_description',
						)
					)
				),
				'post_status'  => sanitize_key( $this->get_value( $row, array( 'post_status' ), 'publish' ) ),
			);

			if ( $post_id ) {
				$payload['ID'] = $post_id;
				$result        = wp_update_post( $payload, true );
			} else {
				$result = wp_insert_post( $payload, true );
			}

			if ( is_wp_error( $result ) ) {
				$stats['failed']++;
				$stats['errors'][] = sprintf( __( 'Row %1$d: %2$s', 'simple-lms' ), $index + 2, $result->get_error_message() );
				continue;
			}

			$subject_id    = (int) $result;
			$term_id       = $this->resolve_term_id( $row, $defaults );
			$credits       = (float) $this->get_value( $row, array( 'credits', 'credit' ), $defaults['credits'] ?? 0 );
			$subject_type  = sanitize_key( $this->get_value( $row, array( 'type', 'subject_type' ), $defaults['subject_type'] ?? 'core' ) );
			$subject_state = sanitize_key( $this->get_value( $row, array( 'status', 'subject_status' ), $defaults['status'] ?? 'active' ) );
			$delivery_mode = sanitize_key( $this->get_value( $row, array( 'delivery_mode', 'mode' ), $defaults['delivery_mode'] ?? 'onsite' ) );
			$capacity      = absint( $this->get_value( $row, array( 'capacity', 'limit' ), $defaults['capacity'] ?? 0 ) );
			$total_classes = absint( $this->get_value( $row, array( 'total_classes', 'class_count', 'weeks', 'total_weeks' ), $defaults['total_classes'] ?? 0 ) );

			update_post_meta( $subject_id, '_slms_subject_code', $code );
			update_post_meta( $subject_id, '_slms_credits', max( 0, $credits ) );
			update_post_meta( $subject_id, '_slms_subject_type', in_array( $subject_type, array( 'core', 'elective' ), true ) ? $subject_type : 'core' );
			update_post_meta( $subject_id, '_slms_subject_status', in_array( $subject_state, array( 'active', 'inactive', 'archived' ), true ) ? $subject_state : 'active' );
			update_post_meta( $subject_id, '_slms_term_id', $term_id );
			update_post_meta( $subject_id, '_slms_delivery_mode', in_array( $delivery_mode, array( 'onsite', 'online', 'hybrid' ), true ) ? $delivery_mode : 'onsite' );
			update_post_meta( $subject_id, '_slms_capacity', $capacity );
			update_post_meta( $subject_id, '_slms_total_classes', $total_classes );

			if ( $program_ids ) {
				wp_set_object_terms( $subject_id, $program_ids, 'slms_program', false );
			}

			$lecturer_codes = $this->split_multi_value(
				$this->get_value(
					$row,
					array(
						'lecturer_codes',
						'lecturer_code',
						'staff_codes',
						'staff_code',
					)
				)
			);

			if ( $lecturer_codes ) {
				$lecturer_ids = array();

				foreach ( $lecturer_codes as $lecturer_code ) {
					$lecturer_id = $this->profiles->find_user_id_by_person_code( $lecturer_code );

					if ( $lecturer_id ) {
						$lecturer_ids[] = $lecturer_id;
					}
				}

				if ( $lecturer_ids ) {
					$this->enrollments->assign_section_staff( $subject_id, $lecturer_ids, 'lecturer', get_current_user_id() );
				}
			}

			if ( $post_id ) {
				$stats['updated']++;
			} else {
				$stats['created']++;
			}
		}

		return $stats;
	}

	public function import_enrollments_by_code( $subject_id, array $rows, array $args = array() ) {
		$mapped = $this->rows_to_assoc( $rows );

		if ( is_wp_error( $mapped ) ) {
			return $mapped;
		}

		$stats = array(
			'enrolled' => 0,
			'failed'   => 0,
			'errors'   => array(),
		);

		foreach ( $mapped as $index => $row ) {
			$student_code = $this->get_value(
				$row,
				array(
					'student_code',
					'code',
					'person_code',
					'student_id',
				)
			);

			if ( '' === $student_code ) {
				$stats['failed']++;
				$stats['errors'][] = sprintf( __( 'Row %d: student code is required.', 'simple-lms' ), $index + 2 );
				continue;
			}

			$result = $this->enrollments->enroll_student_by_code( $subject_id, $student_code, $args );

			if ( is_wp_error( $result ) ) {
				$stats['failed']++;
				$stats['errors'][] = sprintf( __( 'Row %1$d: %2$s', 'simple-lms' ), $index + 2, $result->get_error_message() );
				continue;
			}

			$stats['enrolled']++;
		}

		return $stats;
	}

	public function rows_to_assoc( array $rows ) {
		$rows = array_values( array_filter( $rows, 'is_array' ) );

		if ( empty( $rows ) ) {
			return new WP_Error( 'slms_import_no_rows', __( 'The import file did not contain any rows.', 'simple-lms' ) );
		}

		$headers = array_shift( $rows );
		$headers = array_map( array( $this, 'normalize_header' ), $headers );

		$results = array();

		foreach ( $rows as $row ) {
			if ( empty( array_filter( $row, static function ( $value ) {
				return '' !== trim( (string) $value );
			} ) ) ) {
				continue;
			}

			$assoc = array();

			foreach ( $headers as $index => $header ) {
				if ( '' === $header ) {
					continue;
				}

				$assoc[ $header ] = isset( $row[ $index ] ) ? trim( (string) $row[ $index ] ) : '';
			}

			$results[] = $assoc;
		}

		return $results;
	}

	private function import_people( array $rows, $person_type, array $defaults = array() ) {
		$mapped = $this->rows_to_assoc( $rows );

		if ( is_wp_error( $mapped ) ) {
			return $mapped;
		}

		$stats = array(
			'created' => 0,
			'failed'  => 0,
			'errors'  => array(),
		);

		foreach ( $mapped as $index => $row ) {
			$full_name = $this->get_value( $row, array( 'full_name', 'name' ) );
			$email     = $this->get_value( $row, array( 'email', 'email_address' ) );
			$person_code = strtoupper(
				$this->get_value(
					$row,
					array(
						'person_code',
						'student_code',
						'staff_code',
						'code',
					)
				)
			);

			$data = array_merge(
				$defaults,
				array(
					'full_name'          => $full_name,
					'email'              => $email,
					'username'           => $this->get_value( $row, array( 'username', 'login' ) ),
					'person_code'        => $person_code,
					'nrc_number'         => $this->get_value(
						$row,
						array(
							'nrc_number',
							'nrc',
							'nrc_no',
							'nrc_card_no',
							'nrc_card_number',
							'national_registration_card',
							'national_registration_number',
						)
					),
					'phone'              => $this->get_value( $row, array( 'phone', 'phone_number', 'mobile' ) ),
					'department'         => $this->get_value( $row, array( 'department' ) ),
					'position_title'     => $this->get_value( $row, array( 'position', 'position_title' ) ),
					'notes'              => $this->get_value( $row, array( 'notes' ) ),
					'send_welcome_email' => $this->normalize_boolean( $this->get_value( $row, array( 'send_welcome_email', 'send_invite' ), $defaults['send_welcome_email'] ?? 1 ) ),
				)
			);

			if ( 'student' === $person_type ) {
				$data['program_id']     = $this->resolve_program_id( $row, $defaults );
				$data['intake_term_id'] = $this->resolve_term_id( $row, $defaults, 'intake' );
				$result                 = $this->provisioning->create_student( $data );
			} else {
				$data['role'] = ProvisioningService::normalize_staff_role( $this->get_value( $row, array( 'role', 'staff_role' ), $defaults['role'] ?? 'lecturer' ) );

				if ( ! empty( $defaults['allowed_staff_roles'] ) && is_array( $defaults['allowed_staff_roles'] ) && ! in_array( $data['role'], $defaults['allowed_staff_roles'], true ) ) {
					$stats['failed']++;
					$stats['errors'][] = sprintf( __( 'Row %1$d: %2$s', 'simple-lms' ), $index + 2, __( 'That staff role is not allowed for this import.', 'simple-lms' ) );
					continue;
				}

				$result       = $this->provisioning->create_staff( $data );
			}

			if ( is_wp_error( $result ) ) {
				$stats['failed']++;
				$stats['errors'][] = sprintf( __( 'Row %1$d: %2$s', 'simple-lms' ), $index + 2, $result->get_error_message() );
				continue;
			}

			$stats['created']++;
		}

		return $stats;
	}

	private function read_xlsx_rows( $file_path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'slms_import_zip', __( 'XLSX import requires ZipArchive, which is not available on this server.', 'simple-lms' ) );
		}

		$zip = new \ZipArchive();

		if ( true !== $zip->open( $file_path ) ) {
			return new WP_Error( 'slms_import_xlsx_open', __( 'Unable to open the XLSX file.', 'simple-lms' ) );
		}

		$shared_strings = array();
		$shared_xml     = $zip->getFromName( 'xl/sharedStrings.xml' );

		if ( ! $shared_xml ) {
			$shared_xml = $zip->getFromName( 'xl\\sharedStrings.xml' );
		}

		if ( $shared_xml ) {
			$xml = simplexml_load_string( $shared_xml );

			if ( $xml ) {
				foreach ( $this->xlsx_xpath( $xml, '//*[local-name()="si"]' ) as $string_item ) {
					$text = '';

					foreach ( $this->xlsx_xpath( $string_item, './/*[local-name()="t"]' ) as $text_node ) {
						$text .= (string) $text_node;
					}

					$shared_strings[] = $text;
				}
			}
		}

		$sheet_xml = $zip->getFromName( 'xl/worksheets/sheet1.xml' );

		if ( ! $sheet_xml ) {
			$sheet_xml = $zip->getFromName( 'xl\\worksheets\\sheet1.xml' );
		}

		if ( ! $sheet_xml ) {
			$zip->close();
			return new WP_Error( 'slms_import_xlsx_sheet', __( 'The first worksheet could not be found in the XLSX file.', 'simple-lms' ) );
		}

		$sheet = simplexml_load_string( $sheet_xml );

		if ( ! $sheet ) {
			$zip->close();
			return new WP_Error( 'slms_import_xlsx_parse', __( 'Unable to parse the XLSX worksheet.', 'simple-lms' ) );
		}

		$rows = array();

		foreach ( $this->xlsx_xpath( $sheet, '//*[local-name()="sheetData"]/*[local-name()="row"]' ) as $row_xml ) {
			$row_arr = array();
			$max_col = -1;

			foreach ( $this->xlsx_xpath( $row_xml, './*[local-name()="c"]' ) as $cell ) {
				$reference = (string) $cell['r'];
				$col_idx   = $this->column_letters_to_index( $reference );
				$type      = (string) $cell['t'];
				$value     = $this->xlsx_first_text( $cell, './*[local-name()="v"]' );

				if ( 's' === $type ) {
					$value = $shared_strings[ (int) $value ] ?? '';
				} elseif ( 'inlineStr' === $type ) {
					$value = $this->xlsx_first_text( $cell, './*[local-name()="is"]/*[local-name()="t"]' );
				}

				$row_arr[ $col_idx ] = $value;
				$max_col             = max( $max_col, $col_idx );
			}

			$normalized = array();

			for ( $i = 0; $i <= $max_col; $i++ ) {
				$normalized[] = $row_arr[ $i ] ?? '';
			}

			$rows[] = $normalized;
		}

		$zip->close();

		return $rows;
	}

	private function xlsx_xpath( $xml, $query ) {
		if ( ! $xml ) {
			return array();
		}

		$result = $xml->xpath( $query );

		return is_array( $result ) ? $result : array();
	}

	private function xlsx_first_text( $xml, $query ) {
		$matches = $this->xlsx_xpath( $xml, $query );

		return isset( $matches[0] ) ? (string) $matches[0] : '';
	}

	private function column_letters_to_index( $reference ) {
		if ( ! preg_match( '/([A-Z]+)/', (string) $reference, $matches ) ) {
			return 0;
		}

		$letters = $matches[1];
		$index   = 0;

		for ( $i = 0, $length = strlen( $letters ); $i < $length; $i++ ) {
			$index = ( $index * 26 ) + ( ord( $letters[ $i ] ) - 64 );
		}

		return max( 0, $index - 1 );
	}

	private function normalize_header( $value ) {
		$value = strtolower( trim( (string) $value ) );
		$value = preg_replace( '/[^a-z0-9]+/', '_', $value );

		return trim( (string) $value, '_' );
	}

	private function get_value( array $row, array $aliases, $default = '' ) {
		foreach ( $aliases as $alias ) {
			$normalized = $this->normalize_header( $alias );

			if ( isset( $row[ $normalized ] ) && '' !== trim( (string) $row[ $normalized ] ) ) {
				return trim( (string) $row[ $normalized ] );
			}
		}

		return $default;
	}

	private function resolve_program_id( array $row, array $defaults = array() ) {
		$program_id = absint( $this->get_value( $row, array( 'program_id' ), $defaults['program_id'] ?? 0 ) );

		if ( $program_id ) {
			return $program_id;
		}

		$program_name = $this->get_value( $row, array( 'program', 'program_name', 'programme' ) );

		if ( '' === $program_name ) {
			return 0;
		}

		$term = term_exists( $program_name, 'slms_program' );

		if ( ! $term ) {
			$term = wp_insert_term( $program_name, 'slms_program' );
		}

		if ( is_wp_error( $term ) ) {
			return 0;
		}

		return ! empty( $term['term_id'] ) ? (int) $term['term_id'] : 0;
	}

	private function resolve_subject_program_ids( array $row ) {
		$program_codes = $this->split_multi_value(
			$this->get_value(
				$row,
				array(
					'program_code',
					'program_codes',
					'program',
					'programs',
				)
			)
		);

		if ( empty( $program_codes ) ) {
			return array();
		}

		$program_ids = array();

		foreach ( $program_codes as $program_code ) {
			$program_id = $this->find_program_term_id_by_code( $program_code );

			if ( ! $program_id ) {
				return new WP_Error(
					'slms_import_program_code_missing',
					sprintf( __( 'Program code "%s" could not be matched to an existing program.', 'simple-lms' ), $program_code )
				);
			}

			$program_ids[] = $program_id;
		}

		return array_values( array_unique( array_map( 'intval', $program_ids ) ) );
	}

	private function find_program_term_id_by_code( $program_code ) {
		$program_code = strtoupper( trim( (string) $program_code ) );

		if ( '' === $program_code ) {
			return 0;
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'slms_program',
				'hide_empty' => false,
				'number'     => 1,
				'meta_query' => array(
					array(
						'key'     => '_slms_program_code',
						'value'   => $program_code,
						'compare' => '=',
					),
				),
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return 0;
		}

		return isset( $terms[0]->term_id ) ? (int) $terms[0]->term_id : 0;
	}

	private function resolve_term_id( array $row, array $defaults = array(), $prefix = '' ) {
		$field_prefix = $prefix ? $prefix . '_' : '';
		$term_id      = absint( $this->get_value( $row, array( $field_prefix . 'term_id' ), $defaults[ $field_prefix . 'term_id' ] ?? 0 ) );

		if ( $term_id ) {
			return $term_id;
		}

		$term_code = strtoupper( $this->get_value( $row, array( $field_prefix . 'term_code', $field_prefix . 'semester_code', $field_prefix . 'semester' ) ) );

		if ( $term_code ) {
			$term = $this->terms->get_term_by_code( $term_code );

			if ( $term ) {
				return (int) $term['id'];
			}
		}

		$term_name      = $this->get_value( $row, array( $field_prefix . 'term_name', $field_prefix . 'semester_name', $field_prefix . 'term' ) );
		$academic_year  = $this->get_value( $row, array( 'academic_year', $field_prefix . 'academic_year' ), $defaults['academic_year'] ?? '' );

		if ( '' === $term_name && '' === $term_code ) {
			return 0;
		}

		foreach ( $this->terms->list_terms() as $term_row ) {
			if ( $term_code && strtoupper( (string) $term_row['code'] ) === $term_code ) {
				return (int) $term_row['id'];
			}

			if ( $term_name && 0 === strcasecmp( (string) $term_row['name'], $term_name ) && ( '' === $academic_year || 0 === strcasecmp( (string) $term_row['academic_year'], $academic_year ) ) ) {
				return (int) $term_row['id'];
			}
		}

		if ( '' === $term_name ) {
			$term_name = $term_code;
		}

		$created = $this->terms->create_term(
			array(
				'code'          => $term_code ?: strtoupper( sanitize_title( $term_name ) ),
				'name'          => $term_name,
				'academic_year' => $academic_year ?: AcademicClock::date( 'Y' ) . '/' . ( (int) AcademicClock::date( 'Y' ) + 1 ),
				'status'        => 'planned',
			)
		);

		return is_wp_error( $created ) ? 0 : (int) $created;
	}

	private function split_multi_value( $value ) {
		$raw = preg_split( '/[,\n;|]+/', (string) $value );

		return array_values(
			array_filter(
				array_map(
					static function ( $item ) {
						return trim( (string) $item );
					},
					$raw
				)
			)
		);
	}

	private function normalize_boolean( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}

		$normalized = strtolower( trim( (string) $value ) );

		if ( in_array( $normalized, array( '0', 'false', 'no', 'off' ), true ) ) {
			return 0;
		}

		return 1;
	}

	private function find_subject_by_code_or_title( $code, $title ) {
		$args = array(
			'post_type'      => 'slms_subject',
			'post_status'    => array( 'publish', 'private', 'draft', 'pending' ),
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		);

		if ( $code ) {
			$args['meta_query'] = array(
				array(
					'key'   => '_slms_subject_code',
					'value' => $code,
				),
			);

			$query = get_posts( $args );

			if ( ! empty( $query[0] ) ) {
				return (int) $query[0];
			}
		}

		$query = get_posts(
			array(
				'post_type'      => 'slms_subject',
				'post_status'    => array( 'publish', 'private', 'draft', 'pending' ),
				'posts_per_page' => 20,
				's'              => $title,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		foreach ( $query as $post_id ) {
			if ( 0 === strcasecmp( get_the_title( $post_id ), $title ) ) {
				return (int) $post_id;
			}
		}

		return 0;
	}
}
