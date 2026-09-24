<?php

namespace SimpleLMS\Domain\Academic;

use SimpleLMS\Core\AcademicClock;
use SimpleLMS\Domain\Settings\SettingsManager;
use SimpleLMS\Domain\Users\ProfileService;
use SimpleLMS\Domain\Users\RoleManager;
use SimpleLMS\Infrastructure\Logging\AuditLogger;
use SimpleLMS\Infrastructure\Notifications\NotificationManager;

defined( 'ABSPATH' ) || exit;

class LeaveApplicationService {
	/**
	 * @var SettingsManager
	 */
	private $settings;

	/**
	 * @var EnrollmentService
	 */
	private $enrollments;

	/**
	 * @var ProfileService
	 */
	private $profiles;

	/**
	 * @var NotificationManager
	 */
	private $notifications;

	/**
	 * @var AuditLogger
	 */
	private $audit_logger;

	public function __construct( SettingsManager $settings, EnrollmentService $enrollments, ProfileService $profiles, NotificationManager $notifications, AuditLogger $audit_logger ) {
		$this->settings      = $settings;
		$this->enrollments   = $enrollments;
		$this->profiles      = $profiles;
		$this->notifications = $notifications;
		$this->audit_logger  = $audit_logger;
	}

	public function get_leave_types() {
		return array(
			'medical'       => __( 'Medical', 'simple-lms' ),
			'personal'      => __( 'Personal', 'simple-lms' ),
			'academic'      => __( 'Academic / Conference', 'simple-lms' ),
			'compassionate' => __( 'Compassionate', 'simple-lms' ),
		);
	}

	public function submit_application( $student_user_id, array $data, array $attachment = array() ) {
		$student_user_id = absint( $student_user_id );
		$role            = RoleManager::get_primary_role( $student_user_id );

		if ( ! $student_user_id || 'student' !== $role ) {
			return new \WP_Error( 'slms_leave_forbidden', __( 'Only students can submit leave applications.', 'simple-lms' ) );
		}

		$student = get_userdata( $student_user_id );
		if ( ! $student || ! is_email( $student->user_email ) ) {
			return new \WP_Error( 'slms_leave_missing_student_email', __( 'A valid student email address is required before a leave application can be sent.', 'simple-lms' ) );
		}

		$subject_id  = absint( $data['subject_id'] ?? 0 );
		$leave_type  = sanitize_key( $data['leave_type'] ?? '' );
		$leave_date  = sanitize_text_field( $data['leave_date'] ?? '' );
		$reason      = trim( sanitize_textarea_field( $data['reason'] ?? '' ) );
		$leave_types = $this->get_leave_types();

		if ( ! isset( $leave_types[ $leave_type ] ) ) {
			return new \WP_Error( 'slms_invalid_leave_type', __( 'Choose a valid leave type.', 'simple-lms' ) );
		}

		if ( ! $subject_id || ! $this->enrollments->user_can_access_section( $student_user_id, $subject_id ) ) {
			return new \WP_Error( 'slms_invalid_leave_subject', __( 'Choose one of your enrolled subjects.', 'simple-lms' ) );
		}

		$subject = $this->enrollments->get_section_summary( $subject_id );
		if ( empty( $subject ) ) {
			return new \WP_Error( 'slms_missing_leave_subject', __( 'The selected subject could not be found.', 'simple-lms' ) );
		}

		$date = $this->normalize_leave_date( $leave_date );
		if ( is_wp_error( $date ) ) {
			return $date;
		}

		if ( '' === $reason ) {
			return new \WP_Error( 'slms_missing_leave_reason', __( 'Please add a brief reason for the leave application.', 'simple-lms' ) );
		}

		$recipient_emails = $this->get_recipient_emails( $subject_id );
		if ( empty( $recipient_emails ) ) {
			return new \WP_Error( 'slms_leave_missing_recipients', __( 'No leave application recipients are configured yet. Add the registrar or coordinator email in Simple LMS settings, or assign a lecturer to this subject.', 'simple-lms' ) );
		}

		$profile        = (array) $this->profiles->get_profile( $student_user_id );
		$subject_title  = sanitize_text_field( (string) ( $subject['subject_title'] ?? $subject['title'] ?? __( 'Selected Subject', 'simple-lms' ) ) );
		$subject_code   = sanitize_text_field( (string) ( $subject['section_code'] ?? $subject['subject_code'] ?? '' ) );
		$student_name   = sanitize_text_field( $profile['display_name'] ?? $student->display_name ?? __( 'Student', 'simple-lms' ) );
		$student_code   = sanitize_text_field( (string) ( $profile['person_code'] ?? '' ) );
		$program_name   = ! empty( $profile['program_id'] ) ? $this->get_program_name( (int) $profile['program_id'] ) : '';
		$attachment_name = sanitize_file_name( (string) ( $attachment['name'] ?? '' ) );
		$subject_line   = sprintf(
			/* translators: 1: subject title, 2: leave start date */
			__( 'Leave Application for %1$s Class on %2$s', 'simple-lms' ),
			$subject_title,
			$date['label']
		);
		$email_message = $this->build_email_message(
			array(
				'student_name'    => $student_name,
				'student_email'   => $student->user_email,
				'student_code'    => $student_code,
				'program_name'    => $program_name,
				'subject_title'   => $subject_title,
				'subject_code'    => $subject_code,
				'leave_type'      => $leave_types[ $leave_type ],
				'leave_date'      => $date['label'],
				'reason'          => $reason,
				'attachment_name' => $attachment_name,
			)
		);

		$send_result = $this->notifications->send_immediate_email(
			$recipient_emails,
			$subject_line,
			$email_message,
			array(
				'from_email'    => $student->user_email,
				'from_name'     => $student_name,
				'reply_to_email'=> $student->user_email,
				'reply_to_name' => $student_name,
				'attachments'   => ! empty( $attachment['path'] ) ? array( $attachment['path'] ) : array(),
			)
		);

		if ( is_wp_error( $send_result ) ) {
			$this->audit_logger->log(
				'leave_application_failed',
				array(
					'level'         => 'error',
					'actor_user_id' => $student_user_id,
					'object_type'   => 'leave_application',
					'message'       => 'A leave application email failed to send.',
					'context'       => array(
						'subject_id'        => $subject_id,
						'subject_title'     => $subject_title,
						'leave_type'        => $leave_type,
						'leave_date'        => $date['value'],
						'recipient_emails'  => $recipient_emails,
						'attachment_name'   => $attachment_name,
						'error_message'     => $send_result->get_error_message(),
					),
				)
			);

			return $send_result;
		}

		$this->audit_logger->log(
			'leave_application_submitted',
			array(
				'actor_user_id' => $student_user_id,
				'object_type'   => 'leave_application',
				'object_id'     => $subject_id,
				'message'       => 'A student leave application was submitted from the frontend portal.',
				'context'       => array(
					'subject_id'       => $subject_id,
					'subject_title'    => $subject_title,
					'subject_code'     => $subject_code,
					'leave_type'       => $leave_type,
					'leave_date'       => $date['value'],
					'recipient_emails' => $recipient_emails,
					'attachment_name'  => $attachment_name,
				),
			)
		);

		return array(
			'subject_line'     => $subject_line,
			'recipient_emails' => $recipient_emails,
			'leave_date'       => $date['value'],
		);
	}

	private function normalize_leave_date( $leave_date ) {
		$timezone = AcademicClock::timezone();

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $leave_date ) ) {
			return new \WP_Error( 'slms_invalid_leave_date', __( 'Choose a valid class date.', 'simple-lms' ) );
		}

		try {
			$date = new \DateTimeImmutable( $leave_date, $timezone );
		} catch ( \Exception $exception ) {
			return new \WP_Error( 'slms_invalid_leave_date', __( 'Choose a valid class date.', 'simple-lms' ) );
		}

		$date = $date->setTime( 0, 0, 0 );

		return array(
			'value' => $date->format( 'Y-m-d' ),
			'label' => AcademicClock::date( get_option( 'date_format' ), $date->getTimestamp() ),
		);
	}

	private function get_recipient_emails( $subject_id ) {
		$emails   = array();
		$settings = array(
			$this->settings->get( 'leave_registrar_email', '' ),
			$this->settings->get( 'leave_academic_coordinator_email', '' ),
		);

		foreach ( $settings as $email ) {
			$email = sanitize_email( $email );

			if ( $email && is_email( $email ) ) {
				$emails[] = $email;
			}
		}

		foreach ( $this->enrollments->get_section_staff( $subject_id, 'lecturer' ) as $staff_row ) {
			$email = sanitize_email( (string) ( $staff_row['user_email'] ?? '' ) );

			if ( $email && is_email( $email ) ) {
				$emails[] = $email;
			}
		}

		return array_values( array_unique( $emails ) );
	}

	private function build_email_message( array $data ) {
		$rows = array(
			__( 'Student Name', 'simple-lms' ) => $data['student_name'],
			__( 'Student Email', 'simple-lms' ) => $data['student_email'],
			__( 'Student ID', 'simple-lms' ) => $data['student_code'] ?: __( 'Not assigned', 'simple-lms' ),
			__( 'Program', 'simple-lms' ) => $data['program_name'] ?: __( 'Not assigned', 'simple-lms' ),
			__( 'Class', 'simple-lms' ) => trim( $data['subject_title'] . ( $data['subject_code'] ? ' (' . $data['subject_code'] . ')' : '' ) ),
			__( 'Leave Type', 'simple-lms' ) => $data['leave_type'],
			__( 'Class Date', 'simple-lms' ) => $data['leave_date'],
			__( 'Supporting Document', 'simple-lms' ) => $data['attachment_name'] ?: __( 'No attachment included', 'simple-lms' ),
		);

		$message = '<p>' . esc_html__( 'Dear Registrar, Academic Coordinator, and Teaching Team,', 'simple-lms' ) . '</p>';
		$message .= '<p>' . esc_html__( 'Please review the following leave application submitted through the LMS portal.', 'simple-lms' ) . '</p>';
		$message .= '<table cellspacing="0" cellpadding="8" style="border-collapse:collapse;width:100%;max-width:720px;">';

		foreach ( $rows as $label => $value ) {
			$message .= '<tr>';
			$message .= '<td style="border:1px solid #d7e1ef;background:#f7faff;font-weight:700;width:34%;">' . esc_html( $label ) . '</td>';
			$message .= '<td style="border:1px solid #d7e1ef;background:#ffffff;">' . esc_html( $value ) . '</td>';
			$message .= '</tr>';
		}

		$message .= '<tr>';
		$message .= '<td style="border:1px solid #d7e1ef;background:#f7faff;font-weight:700;vertical-align:top;">' . esc_html__( 'Reason / Description', 'simple-lms' ) . '</td>';
		$message .= '<td style="border:1px solid #d7e1ef;background:#ffffff;white-space:pre-line;">' . esc_html( $data['reason'] ) . '</td>';
		$message .= '</tr>';
		$message .= '</table>';
		$message .= '<p>' . esc_html__( 'Regards,', 'simple-lms' ) . '<br>' . esc_html( $data['student_name'] ) . '</p>';

		return $message;
	}

	private function get_program_name( $program_id ) {
		$program = get_term( $program_id, 'slms_program' );

		if ( ! $program || is_wp_error( $program ) ) {
			return '';
		}

		return $program->name;
	}
}
