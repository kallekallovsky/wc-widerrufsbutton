<?php
/**
 * Datenzugriff auf die Widerrufs-Tabellen (alle Queries via $wpdb->prepare()).
 *
 * @package Widerrufsbutton
 */

namespace Widerrufsbutton;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Kapselt Lese-/Schreibzugriffe auf wc_widerrufe und wc_widerrufe_log.
 */
class Repository {

	/**
	 * Legt einen Widerruf an (unabhängiger Snapshot beim Eingang).
	 *
	 * @param array $d Bereits bereinigte Felder.
	 * @return int Neue Datensatz-ID (0 bei Fehler).
	 */
	public static function insert( array $d ) {
		global $wpdb;

		$data = array(
			'created_at'          => current_time( 'mysql' ),
			'order_id'            => ! empty( $d['order_id'] ) ? (int) $d['order_id'] : null,
			'order_number'        => isset( $d['order_number'] ) ? (string) $d['order_number'] : '',
			'product_id'          => ! empty( $d['product_id'] ) ? (int) $d['product_id'] : null,
			'sku'                 => ( isset( $d['sku'] ) && '' !== $d['sku'] ) ? (string) $d['sku'] : null,
			'scope'               => isset( $d['scope'] ) ? (string) $d['scope'] : 'order',
			'customer_user_id'    => ! empty( $d['customer_user_id'] ) ? (int) $d['customer_user_id'] : null,
			'name'                => isset( $d['name'] ) ? (string) $d['name'] : '',
			'email'               => isset( $d['email'] ) ? (string) $d['email'] : '',
			'reason'              => ( isset( $d['reason'] ) && '' !== $d['reason'] ) ? (string) $d['reason'] : null,
			'status'              => isset( $d['status'] ) ? (string) $d['status'] : 'eingegangen',
			'verification_status' => isset( $d['verification_status'] ) ? (string) $d['verification_status'] : 'verified',
			'verification_token'  => ( isset( $d['verification_token'] ) && '' !== $d['verification_token'] ) ? (string) $d['verification_token'] : null,
			'token_expires_at'    => ! empty( $d['token_expires_at'] ) ? (string) $d['token_expires_at'] : null,
			'confirmation_sent'   => isset( $d['confirmation_sent'] ) ? (int) $d['confirmation_sent'] : 0,
			'ip_hash'             => ( isset( $d['ip_hash'] ) && '' !== $d['ip_hash'] ) ? (string) $d['ip_hash'] : null,
		);

		$format = array( '%s', '%d', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' );

		$ok = $wpdb->insert( Install::table_withdrawals(), $data, $format );

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Schreibt einen Eintrag ins Aktivitäts-Log.
	 *
	 * @param int    $widerruf_id Bezugs-ID.
	 * @param string $actor       Auslöser (z. B. "system", "kunde", Benutzername).
	 * @param string $action      Aktionsschlüssel.
	 * @param string $note        Optionaler Hinweis.
	 * @return void
	 */
	public static function add_log( $widerruf_id, $actor, $action, $note = '' ) {
		global $wpdb;

		$wpdb->insert(
			Install::table_log(),
			array(
				'widerruf_id' => (int) $widerruf_id,
				'created_at'  => current_time( 'mysql' ),
				'actor'       => (string) $actor,
				'action'      => (string) $action,
				'note'        => '' !== $note ? (string) $note : null,
			),
			array( '%d', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Aktualisiert den Status eines Widerrufs.
	 *
	 * @param int    $id     Datensatz-ID.
	 * @param string $status Neuer Status.
	 * @return bool
	 */
	public static function update_status( $id, $status ) {
		global $wpdb;

		return (bool) $wpdb->update(
			Install::table_withdrawals(),
			array( 'status' => (string) $status ),
			array( 'id' => (int) $id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Markiert die Eingangsbestätigung als versendet.
	 *
	 * @param int $id Datensatz-ID.
	 * @return void
	 */
	public static function mark_confirmation_sent( $id ) {
		global $wpdb;

		$wpdb->update(
			Install::table_withdrawals(),
			array( 'confirmation_sent' => 1 ),
			array( 'id' => (int) $id ),
			array( '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Liefert einen Widerruf anhand des Verifizierungs-Tokens.
	 *
	 * @param string $token Token.
	 * @return array|null
	 */
	public static function get_by_token( $token ) {
		global $wpdb;

		if ( '' === (string) $token ) {
			return null;
		}

		$table = Install::table_withdrawals();

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE verification_token = %s", (string) $token ),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * Markiert einen Widerruf als verifiziert und entfernt den Token.
	 *
	 * @param int $id Datensatz-ID.
	 * @return void
	 */
	public static function mark_verified( $id ) {
		global $wpdb;

		$wpdb->update(
			Install::table_withdrawals(),
			array(
				'verification_status' => 'verified',
				'verification_token'  => null,
				'token_expires_at'    => null,
			),
			array( 'id' => (int) $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Liefert einen Widerruf als assoziatives Array.
	 *
	 * @param int $id Datensatz-ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;

		$table = Install::table_withdrawals();

		// Tabellenname stammt aus $wpdb->prefix (vertrauenswürdig).
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ),
			ARRAY_A
		);

		return $row ? $row : null;
	}
}
