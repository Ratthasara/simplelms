<?php

namespace SimpleLMS\Infrastructure\Notifications;

use SimpleLMS\Core\AcademicClock;
use SimpleLMS\Domain\Assessment\AssessmentService;
use SimpleLMS\Domain\Assessment\AssignmentLatePolicy;

defined( 'ABSPATH' ) || exit;

class AssignmentNotificationService {
	/**
	 * @var AssessmentService
	 */
	private $assessments;

	/**
	 * @var NotificationManager
	 */
	private $notifications;

	public function __construct( AssessmentService $assessments, NotificationManager $notifications ) {
		$this->assessments   = $assessments;
		$this->notifications = $notifications;
	}

	public function send_submission_confirmation( $assignment_id, $student_user_id, array $submission ) {
		$context = $this->get_assignment_email_context( $assignment_id, $student_user_id );

		if ( empty( $context ) ) {
			return;
		}

		$file_name       = sanitize_text_field( (string) ( $submission['attachment_name'] ?? '' ) );
		$submitted_at    = ! empty( $submission['submitted_at'] ) ? $submission['submitted_at'] : ( $submission['updated_at'] ?? AcademicClock::mysql() );
		$submitted_label = $this->format_assignment_email_datetime( $submitted_at );
		$attempt_label   = ! empty( $submission['attempt_number'] ) ? sprintf( __( 'Attempt %d', 'simple-lms' ), absint( $submission['attempt_number'] ) ) : __( 'Attempt not recorded', 'simple-lms' );
		$late_details    = AssignmentLatePolicy::get_adjustment_details( $context, $submission );
		$section_label   = $this->format_section_label( $context );

		$details = array(
			sprintf( __( 'Subject: %s', 'simple-lms' ), $context['subject_title'] ),
			sprintf( __( 'Assignment: %s', 'simple-lms' ), $context['assignment_title'] ),
		);

		if ( $section_label ) {
			$details[] = sprintf( __( 'Section/Class: %s', 'simple-lms' ), $section_label );
		}

		$details[] = sprintf( __( 'Submission Time: %s', 'simple-lms' ), $submitted_label );
		$details[] = sprintf( __( 'Submission Type: %s', 'simple-lms' ), $this->format_assignment_submission_type( $submission ) );

		if ( $file_name ) {
			$details[] = sprintf( __( 'Submitted File: %s', 'simple-lms' ), $file_name );
		}

		$details[] = sprintf( __( 'Attempt: %s', 'simple-lms' ), $attempt_label );

		if ( ! empty( $late_details['is_late'] ) ) {
			$details[] = sprintf( __( 'Late Policy: %s', 'simple-lms' ), $late_details['policy_short_label'] );
		}


		$message = wpautop(
			sprintf(
				/* translators: 1: student name, 2: assignment details block */
				__( 'Dear %1$s,%3$sYour assignment submission has been received successfully.%3$s%2$s%3$sPlease keep this email as confirmation of your submission.%3$sRegards,%3$sAcademic Support Team', 'simple-lms' ),
				$context['student_name'],
				implode( "\n", $details ),
				"\n\n"
			)
		);

		$this->notifications->queue(
			$student_user_id,
			$this->build_assignment_email_subject( __( 'Submission Received', 'simple-lms' ), $context ),
			$message,
			array(
				'context'  => $this->build_notification_context( 'assignment_submission_confirmation', $context, $student_user_id ),
				'send_now' => true,
			)
		);
	}

	public function send_grade_posted( $assignment_id, $student_user_id ) {
		$context    = $this->get_assignment_email_context( $assignment_id, $student_user_id );
		$submission = $this->assessments->get_submission( $assignment_id, $student_user_id );

		if ( empty( $context ) || empty( $submission ) ) {
			return;
		}

		$graded_at    = ! empty( $submission['graded_at'] ) ? $submission['graded_at'] : ( $submission['updated_at'] ?? AcademicClock::mysql() );
		$graded_label         = $this->format_assignment_email_datetime( $graded_at );
		$score_label          = $this->format_assignment_score_label( $submission, $context );
		$late_details         = AssignmentLatePolicy::get_adjustment_details( $context, $submission );
		$contribution_details = $this->get_assignment_contribution_details( $submission, $context, $late_details );
		$feedback             = trim( wp_strip_all_tags( (string) ( $submission['feedback'] ?? '' ) ) );
		$section_label        = $this->format_section_label( $context );

		if ( '' === $feedback ) {
			$feedback = __( 'No feedback was entered.', 'simple-lms' );
		}

		$details = array(
			sprintf( __( 'Subject: %s', 'simple-lms' ), $context['subject_title'] ),
			sprintf( __( 'Assignment: %s', 'simple-lms' ), $context['assignment_title'] ),
		);

		if ( $section_label ) {
			$details[] = sprintf( __( 'Section/Class: %s', 'simple-lms' ), $section_label );
		}

		$details[] = sprintf( __( 'Score: %s', 'simple-lms' ), $score_label );

		if ( null !== $contribution_details['raw_contribution'] ) {
			$details[] = sprintf( __( 'Assignment Weight: %s%%', 'simple-lms' ), number_format_i18n( (float) $context['weight'], 2 ) );
			$details[] = sprintf( __( 'Raw Contribution: %s', 'simple-lms' ), number_format_i18n( (float) $contribution_details['raw_contribution'], 2 ) );

			if ( ! empty( $late_details['is_late'] ) ) {
				$details[] = sprintf( __( 'Late Penalty: %s', 'simple-lms' ), $late_details['policy_short_label'] );
			}

			$details[] = sprintf( __( 'Final Contribution: %s', 'simple-lms' ), number_format_i18n( (float) $contribution_details['final_contribution'], 2 ) );
		}

		$details[] = sprintf( __( 'Graded Time: %s', 'simple-lms' ), $graded_label );

		$message = wpautop(
			sprintf(
				/* translators: 1: student name, 2: assignment details block, 3: feedback */
				__( 'Dear %1$s,%4$sYour assignment has been graded.%4$s%2$s%4$sFeedback:%4$s%3$s%4$sPlease log in to the student portal to review your submission and feedback.%4$sRegards,%4$sAcademic Support Team', 'simple-lms' ),
				$context['student_name'],
				implode( "\n", $details ),
				$feedback,
				"\n\n"
			)
		);

		$this->notifications->queue(
			$student_user_id,
			$this->build_assignment_email_subject( __( 'Grade Posted', 'simple-lms' ), $context ),
			$message,
			array(
				'context'  => $this->build_notification_context( 'assignment_graded', $context, $student_user_id ),
				'send_now' => true,
			)
		);
	}

	private function get_assignment_email_context( $assignment_id, $student_user_id ) {
		$assignment_id   = absint( $assignment_id );
		$student_user_id = absint( $student_user_id );
		$student         = get_userdata( $student_user_id );
		$assignment_post = get_post( $assignment_id );

		if ( ! $student || ! is_email( $student->user_email ) || ! $assignment_post || 'slms_assignment' !== $assignment_post->post_type ) {
			return null;
		}

		$assignment = $this->assessments->get_assignment( $assignment_id, $student_user_id );

		return array(
			'student_name'     => $student->display_name ?: __( 'Student', 'simple-lms' ),
			'student_email'    => $student->user_email,
			'assignment_id'    => $assignment_id,
			'assignment_title' => sanitize_text_field( $assignment['title'] ?? $assignment_post->post_title ),
			'subject_title'    => sanitize_text_field( $assignment['subject_title'] ?? __( 'Subject not assigned', 'simple-lms' ) ),
			'section_title'    => sanitize_text_field( $assignment['section_title'] ?? '' ),
			'section_code'     => sanitize_text_field( $assignment['section_code'] ?? '' ),
			'points'           => isset( $assignment['points'] ) ? (float) $assignment['points'] : (float) get_post_meta( $assignment_id, '_slms_points', true ),
			'weight'           => isset( $assignment['weight'] ) ? (float) $assignment['weight'] : (float) get_post_meta( $assignment_id, '_slms_assignment_weight', true ),
			'due_at'           => sanitize_text_field( $assignment['due_at'] ?? get_post_meta( $assignment_id, '_slms_due_at', true ) ),
		);
	}

	private function build_assignment_email_subject( $prefix, array $context ) {
		$subject = sprintf(
			/* translators: 1: email purpose, 2: LMS subject name, 3: assignment title, 4: assignment ID */
			__( '%1$s: %2$s - %3$s [Assignment #%4$d]', 'simple-lms' ),
			sanitize_text_field( $prefix ),
			sanitize_text_field( $context['subject_title'] ?? __( 'Subject', 'simple-lms' ) ),
			sanitize_text_field( $context['assignment_title'] ?? __( 'Assignment', 'simple-lms' ) ),
			absint( $context['assignment_id'] ?? 0 )
		);

		return function_exists( 'mb_substr' ) ? mb_substr( $subject, 0, 240 ) : substr( $subject, 0, 240 );
	}

	private function build_notification_context( $type, array $context, $student_user_id ) {
		return array(
			'type'             => sanitize_key( $type ),
			'assignment_id'    => absint( $context['assignment_id'] ?? 0 ),
			'assignment_title' => sanitize_text_field( $context['assignment_title'] ?? '' ),
			'subject_title'    => sanitize_text_field( $context['subject_title'] ?? '' ),
			'section_title'    => sanitize_text_field( $context['section_title'] ?? '' ),
			'due_at'           => sanitize_text_field( $context['due_at'] ?? '' ),
			'weight'           => (float) ( $context['weight'] ?? 0 ),
			'student_user_id'  => absint( $student_user_id ),
		);
	}

	private function format_assignment_email_datetime( $value ) {
		$value = sanitize_text_field( (string) $value );

		if ( '' === $value ) {
			$value = AcademicClock::mysql();
		}

		$display = AssignmentLatePolicy::format_datetime_display( $value );

		return '' !== $display ? $display : $value;
	}

	private function format_assignment_submission_type( array $submission ) {
		$has_text = '' !== trim( wp_strip_all_tags( (string) ( $submission['submission_text'] ?? '' ) ) );
		$has_file = '' !== trim( (string) ( $submission['attachment_url'] ?? '' ) ) || '' !== trim( (string) ( $submission['attachment_name'] ?? '' ) );

		if ( $has_text && $has_file ) {
			return __( 'Typed answer and file upload', 'simple-lms' );
		}

		if ( $has_text ) {
			return __( 'Typed answer', 'simple-lms' );
		}

		if ( $has_file ) {
			return __( 'File upload', 'simple-lms' );
		}

		return __( 'Submission received', 'simple-lms' );
	}

	private function get_assignment_contribution_details( array $submission, array $context, array $late_details ) {
		if ( ! isset( $submission['score'] ) || null === $submission['score'] || '' === $submission['score'] ) {
			return array(
				'raw_contribution'   => null,
				'final_contribution' => null,
			);
		}

		$points = (float) ( $context['points'] ?? 0 );
		$weight = (float) ( $context['weight'] ?? 0 );

		if ( $points <= 0 || $weight <= 0 ) {
			return array(
				'raw_contribution'   => null,
				'final_contribution' => null,
			);
		}

		$raw_contribution = ( (float) $submission['score'] / $points ) * $weight;

		return array(
			'raw_contribution'   => $raw_contribution,
			'final_contribution' => $raw_contribution * (float) ( $late_details['late_multiplier'] ?? 1 ),
		);
	}

	private function format_assignment_score_label( array $submission, array $context ) {
		if ( ! isset( $submission['score'] ) || null === $submission['score'] || '' === $submission['score'] ) {
			return __( 'No score recorded', 'simple-lms' );
		}

		$score_label = number_format_i18n( (float) $submission['score'], 2 );
		$points      = (float) ( $context['points'] ?? 0 );

		if ( $points > 0 ) {
			return sprintf(
				/* translators: 1: score earned, 2: assignment total points */
				__( '%1$s / %2$s', 'simple-lms' ),
				$score_label,
				number_format_i18n( $points, 2 )
			);
		}

		return $score_label;
	}

	private function format_section_label( array $context ) {
		$section_label = sanitize_text_field( $context['section_title'] ?? '' );
		$section_code  = sanitize_text_field( $context['section_code'] ?? '' );

		if ( $section_code ) {
			return $section_label ? sprintf( '%1$s (%2$s)', $section_label, $section_code ) : $section_code;
		}

		return $section_label;
	}
}
