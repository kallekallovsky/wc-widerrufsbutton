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
	 * Abfrage für die Admin-Liste (Filter, Suche, Sortierung, Pagination).
	 *
	 * @param array $args Argumente.
	 * @return array { items: array, total: int }
	 */
	public static function query( $args = array() ) {
		global $wpdb;

		$table = Install::table_withdrawals();

		$a = wp_parse_args(
			$args,
			array(
				'status'    => '',
				'search'    => '',
				'date_from' => '',
				'date_to'   => '',
				'orderby'   => 'created_at',
				'order'     => 'DESC',
				'per_page'  => 20,
				'paged'     => 1,
			)
		);

		$where  = 'WHERE 1=1';
		$params = array();

		if ( '' !== $a['status'] ) {
			$where   .= ' AND status = %s';
			$params[] = $a['status'];
		}
		if ( '' !== $a['search'] ) {
			$like     = '%' . $wpdb->esc_like( $a['search'] ) . '%';
			$where   .= ' AND ( order_number LIKE %s OR email LIKE %s OR name LIKE %s )';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}
		if ( '' !== $a['date_from'] ) {
			$where   .= ' AND created_at >= %s';
			$params[] = $a['date_from'] . ' 00:00:00';
		}
		if ( '' !== $a['date_to'] ) {
			$where   .= ' AND created_at <= %s';
			$params[] = $a['date_to'] . ' 23:59:59';
		}

		// Whitelist für Sortierung (sichere Interpolation).
		$allowed = array( 'created_at', 'order_number', 'email', 'status', 'id' );
		$orderby = in_array( $a['orderby'], $allowed, true ) ? $a['orderby'] : 'created_at';
		$order   = ( 'ASC' === strtoupper( $a['order'] ) ) ? 'ASC' : 'DESC';

		$per    = max( 1, (int) $a['per_page'] );
		$offset = max( 0, ( (int) $a['paged'] - 1 ) * $per );

		$total_sql = "SELECT COUNT(*) FROM {$table} {$where}";
		$total     = (int) $wpdb->get_var( $params ? $wpdb->prepare( $total_sql, $params ) : $total_sql );

		$items_sql = "SELECT * FROM {$table} {$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$iparams   = array_merge( $params, array( $per, $offset ) );
		$items     = $wpdb->get_results( $wpdb->prepare( $items_sql, $iparams ), ARRAY_A );

		return array(
			'items' => $items ? $items : array(),
			'total' => $total,
		);
	}

	/**
	 * Aggregierte Anzahl je Status seit einem Stichtag (für Statistik).
	 *
	 * @param string $since MySQL-Datetime (z. B. vor 30 Tagen) oder leer.
	 * @return array status => count
	 */
	public static function counts_by_status( $since = '' ) {
		global $wpdb;

		$table = Install::table_withdrawals();

		if ( '' !== $since ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT status, COUNT(*) AS c FROM {$table} WHERE created_at >= %s GROUP BY status", $since ),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS c FROM {$table} GROUP BY status", ARRAY_A );
		}

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ $row['status'] ] = (int) $row['c'];
		}

		return $out;
	}

	/**
	 * Liefert die Log-Einträge eines Widerrufs (chronologisch).
	 *
	 * @param int $widerruf_id Bezugs-ID.
	 * @return array
	 */
	public static function get_log( $widerruf_id ) {
		global $wpdb;

		$table = Install::table_log();

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE widerruf_id = %d ORDER BY id ASC", (int) $widerruf_id ),
			ARRAY_A
		);

		return $rows ? $rows : array();
	}

	/**
	 * Prüft, ob bereits ein offener/bestätigter Widerruf existiert (Duplikat).
	 *
	 * Berücksichtigt nur verifizierte Einträge in nicht-abgelehnten Status.
	 *
	 * @param int    $order_id   Bestell-ID.
	 * @param string $scope      order|item.
	 * @param int    $product_id Produkt-ID (bei scope=item).
	 * @return bool
	 */
	public static function has_open( $order_id, $scope = 'order', $product_id = 0 ) {
		global $wpdb;

		if ( ! $order_id ) {
			return false;
		}

		$table    = Install::table_withdrawals();
		$statuses = array( 'eingegangen', 'in_bearbeitung', 'bestaetigt' );
		$ph       = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

		if ( 'item' === $scope && $product_id ) {
			$sql    = "SELECT COUNT(*) FROM {$table} WHERE order_id = %d AND scope = 'item' AND product_id = %d AND verification_status = 'verified' AND status IN ($ph)";
			$params = array_merge( array( (int) $order_id, (int) $product_id ), $statuses );
		} else {
			$sql    = "SELECT COUNT(*) FROM {$table} WHERE order_id = %d AND scope = 'order' AND verification_status = 'verified' AND status IN ($ph)";
			$params = array_merge( array( (int) $order_id ), $statuses );
		}

		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) ) > 0;
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
