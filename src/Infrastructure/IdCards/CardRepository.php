<?php

namespace SimpleLMS\Infrastructure\IdCards;

use SimpleLMS\Core\AcademicClock;
use SimpleLMS\Infrastructure\Database\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

class CardRepository {
	public function issue_card( array $person, $actor_user_id = 0 ) {
		global $wpdb;

		$actor_user_id = absint( $actor_user_id ?: get_current_user_id() );
		$user_id       = absint( $person['user_id'] ?? 0 );

		if ( ! $user_id ) {
			return new WP_Error( 'slms_id_invalid_person', __( 'Invalid person.', 'simple-lms' ) );
		}

		$table    = Schema::table( 'id_cards' );
		$existing = $this->get_latest_card_for_person( $user_id, $person['person_code'] ?? ( $person['id_number'] ?? '' ), $person['email'] ?? '', false );
		$token    = $existing['verification_token'] ?? $this->generate_token( $user_id );
		$now      = AcademicClock::mysql();
		$data     = array(
			'person_user_id'     => $user_id,
			'person_type'        => sanitize_key( $person['person_type'] ?? 'student' ),
			'person_code'        => sanitize_text_field( $person['id_number'] ?? ( $person['person_code'] ?? '' ) ),
			'full_name'          => sanitize_text_field( $person['full_name'] ?? '' ),
			'role_label'         => sanitize_text_field( $person['role'] ?? ( $person['role_label'] ?? '' ) ),
			'program_label'      => sanitize_text_field( $person['program'] ?? ( $person['program_label'] ?? '' ) ),
			'department_label'   => sanitize_text_field( $person['department'] ?? ( $person['department_label'] ?? '' ) ),
			'nrc_number'         => sanitize_text_field( $person['nrc_number'] ?? '' ),
			'phone'              => sanitize_text_field( $person['phone'] ?? '' ),
			'email'              => sanitize_email( $person['email'] ?? '' ),
			'photo_url'          => esc_url_raw( $person['photo_url'] ?? '' ),
			'verification_token' => $token,
			'status'             => 'active',
			'issued_by'          => $actor_user_id,
			'issued_at'          => $existing['issued_at'] ?? $now,
			'updated_at'         => $now,
		);

		$formats = array(
			'%d', // person_user_id.
			'%s', // person_type.
			'%s', // person_code.
			'%s', // full_name.
			'%s', // role_label.
			'%s', // program_label.
			'%s', // department_label.
			'%s', // nrc_number.
			'%s', // phone.
			'%s', // email.
			'%s', // photo_url.
			'%s', // verification_token.
			'%s', // status.
			'%d', // issued_by.
			'%s', // issued_at.
			'%s', // updated_at.
		);

		if ( $existing ) {
			$updated = $wpdb->update( $table, $data, array( 'id' => absint( $existing['id'] ) ), $formats, array( '%d' ) );
			if ( false === $updated ) {
				return new WP_Error( 'slms_id_update_failed', __( 'The card record could not be updated.', 'simple-lms' ) );
			}
			$card_id = absint( $existing['id'] );
			$this->log( $card_id, $user_id, 'regenerated', $actor_user_id, __( 'ID card regenerated.', 'simple-lms' ), $data );
		} else {
			$inserted = $wpdb->insert( $table, $data, $formats );
			if ( false === $inserted ) {
				return new WP_Error( 'slms_id_insert_failed', __( 'The card record could not be created.', 'simple-lms' ) );
			}
			$card_id = (int) $wpdb->insert_id;
			$this->log( $card_id, $user_id, 'issued', $actor_user_id, __( 'ID card issued.', 'simple-lms' ), $data );
		}

		return $this->get_card( $card_id );
	}


	public function repair_corrupted_statuses() {
		global $wpdb;

		$option_key = 'slms_id_card_status_format_repair_334';
		if ( get_option( $option_key ) ) {
			return 0;
		}

		$table = Schema::table( 'id_cards' );
		$updated = $wpdb->query( "UPDATE {$table} SET status = 'active', updated_at = '" . esc_sql( AcademicClock::mysql() ) . "' WHERE status = '0' OR status = ''" );
		update_option( $option_key, AcademicClock::mysql(), false );

		return false === $updated ? 0 : (int) $updated;
	}

	public function get_card( $card_id ) {
		global $wpdb;

		$card_id = absint( $card_id );
		if ( ! $card_id ) {
			return null;
		}

		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'id_cards' ) . ' WHERE id = %d LIMIT 1', $card_id ), ARRAY_A );
	}

	public function get_latest_card_for_user( $user_id ) {
		return $this->get_latest_card_for_person( $user_id, '', '', false );
	}

	public function get_latest_active_card_for_person( $user_id, $person_code = '', $email = '' ) {
		return $this->get_latest_card_for_person( $user_id, $person_code, $email, true );
	}

	public function get_latest_card_for_person( $user_id, $person_code = '', $email = '', $active_only = false ) {
		global $wpdb;

		$user_id     = absint( $user_id );
		$person_code = sanitize_text_field( $person_code );
		$email       = sanitize_email( $email );

		$where  = array();
		$params = array();

		if ( $user_id ) {
			$where[]  = 'person_user_id = %d';
			$params[] = $user_id;
		}

		if ( '' !== $person_code ) {
			$where[]  = 'person_code = %s';
			$params[] = $person_code;
		}

		if ( '' !== $email ) {
			$where[]  = 'email = %s';
			$params[] = $email;
		}

		if ( empty( $where ) ) {
			return null;
		}

		$sql = 'SELECT * FROM ' . Schema::table( 'id_cards' ) . ' WHERE (' . implode( ' OR ', $where ) . ')';

		if ( $active_only ) {
			$sql .= " AND status = 'active'";
		}

		$sql .= ' ORDER BY CASE'
			. ' WHEN person_user_id = %d THEN 0'
			. " WHEN person_code = %s AND %s <> '' THEN 1"
			. " WHEN email = %s AND %s <> '' THEN 2"
			. ' ELSE 9 END ASC, id DESC LIMIT 1';

		$params[] = $user_id;
		$params[] = $person_code;
		$params[] = $person_code;
		$params[] = $email;
		$params[] = $email;

		return $wpdb->get_row( $wpdb->prepare( $sql, $params ), ARRAY_A );
	}

	public function get_card_by_token( $token ) {
		global $wpdb;

		$token = sanitize_text_field( $token );
		if ( '' === $token ) {
			return null;
		}

		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'id_cards' ) . ' WHERE verification_token = %s LIMIT 1', $token ), ARRAY_A );
	}

	public function revoke_card( $card_id, $actor_user_id = 0 ) {
		global $wpdb;

		$card = $this->get_card( $card_id );
		if ( ! $card ) {
			return new WP_Error( 'slms_id_missing_card', __( 'Card not found.', 'simple-lms' ) );
		}

		$updated = $wpdb->update(
			Schema::table( 'id_cards' ),
			array(
				'status'     => 'revoked',
				'updated_at' => AcademicClock::mysql(),
			),
			array( 'id' => absint( $card_id ) ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error( 'slms_id_revoke_failed', __( 'Card could not be revoked.', 'simple-lms' ) );
		}

		$this->log( absint( $card_id ), absint( $card['person_user_id'] ), 'revoked', absint( $actor_user_id ?: get_current_user_id() ), __( 'ID card revoked.', 'simple-lms' ), array() );

		return $this->get_card( $card_id );
	}

	public function count_cards( $status = '' ) {
		global $wpdb;

		$sql    = 'SELECT COUNT(*) FROM ' . Schema::table( 'id_cards' );
		$params = array();

		if ( '' !== $status ) {
			$sql     .= ' WHERE status = %s';
			$params[] = sanitize_key( $status );
		}

		if ( $params ) {
			$sql = $wpdb->prepare( $sql, $params );
		}

		return (int) $wpdb->get_var( $sql );
	}

	public function log( $card_id, $person_user_id, $action, $actor_user_id = 0, $message = '', array $context = array() ) {
		global $wpdb;

		return $wpdb->insert(
			Schema::table( 'id_card_logs' ),
			array(
				'card_id'        => $card_id ? absint( $card_id ) : null,
				'person_user_id' => $person_user_id ? absint( $person_user_id ) : null,
				'action'         => sanitize_key( $action ),
				'actor_user_id'  => $actor_user_id ? absint( $actor_user_id ) : null,
				'message'        => sanitize_textarea_field( $message ),
				'context_json'   => wp_json_encode( $context ),
				'ip_address'     => sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' ),
				'created_at'     => AcademicClock::mysql(),
			),
			array( '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s' )
		);
	}

	private function generate_token( $user_id ) {
		return substr( wp_hash( $user_id . '|' . wp_generate_uuid4() . '|' . microtime( true ), 'nonce' ), 0, 40 );
	}
}
