<?php
/**
 * Anbindung an die Datenschutz-Werkzeuge von WordPress.
 *
 * @package Widerrufsbutton
 */

namespace Widerrufsbutton;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Meldet die Widerrufsdaten bei Auskunft und Löschung an.
 *
 * Ohne diese Anbindung finden die WordPress-Standardwerkzeuge unter
 * Werkzeuge → Persönliche Daten exportieren/löschen die Widerrufe nicht:
 * Art. 15 und 17 DSGVO wären nur manuell über die Admin-Liste zu bedienen.
 */
final class Privacy {

	/**
	 * Registriert die Hooks.
	 */
	public function __construct() {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
	}

	/**
	 * Meldet den Exporter an.
	 *
	 * @param array $exporters Bestehende Exporter.
	 * @return array
	 */
	public function register_exporter( $exporters ) {
		$exporters['wdbtn-withdrawals'] = array(
			'exporter_friendly_name' => __( 'Widerrufe', 'widerrufsbutton-fuer-woocommerce' ),
			'callback'               => array( $this, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Meldet den Eraser an.
	 *
	 * @param array $erasers Bestehende Eraser.
	 * @return array
	 */
	public function register_eraser( $erasers ) {
		$erasers['wdbtn-withdrawals'] = array(
			'eraser_friendly_name' => __( 'Widerrufe', 'widerrufsbutton-fuer-woocommerce' ),
			'callback'             => array( $this, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Exportiert alle Widerrufe zu einer E-Mail-Adresse.
	 *
	 * @param string $email E-Mail-Adresse.
	 * @param int    $page  Seite (1-basiert).
	 * @return array
	 */
	public function export( $email, $page = 1 ) {
		$page     = max( 1, (int) $page );
		$per_page = 50;
		$rows     = Repository::find_by_email( $email, $per_page, ( $page - 1 ) * $per_page );
		$items    = array();

		foreach ( $rows as $row ) {
			$items[] = array(
				'group_id'    => 'wdbtn-withdrawals',
				'group_label' => __( 'Widerrufe', 'widerrufsbutton-fuer-woocommerce' ),
				'item_id'     => 'wdbtn-' . (int) $row['id'],
				'data'        => array(
					array(
						'name'  => __( 'Eingegangen am', 'widerrufsbutton-fuer-woocommerce' ),
						'value' => $row['created_at'],
					),
					array(
						'name'  => __( 'Bestellnummer', 'widerrufsbutton-fuer-woocommerce' ),
						'value' => $row['order_number'],
					),
					array(
						'name'  => __( 'Name', 'widerrufsbutton-fuer-woocommerce' ),
						'value' => $row['name'],
					),
					array(
						'name'  => __( 'E-Mail', 'widerrufsbutton-fuer-woocommerce' ),
						'value' => $row['email'],
					),
					array(
						'name'  => __( 'Grund', 'widerrufsbutton-fuer-woocommerce' ),
						'value' => isset( $row['reason'] ) ? (string) $row['reason'] : '',
					),
					array(
						'name'  => __( 'Status', 'widerrufsbutton-fuer-woocommerce' ),
						'value' => $row['status'],
					),
				),
			);
		}

		return array(
			'data' => $items,
			'done' => count( $rows ) < $per_page,
		);
	}

	/**
	 * Anonymisiert Widerrufe zu einer E-Mail-Adresse.
	 *
	 * Bewusst anonymisieren statt löschen: Der Datensatz belegt den Zugang
	 * einer Widerrufserklärung und damit die Erfüllung einer gesetzlichen
	 * Pflicht (§ 356a BGB). Für die Aufbewahrung spricht deshalb ein
	 * berechtigtes Interesse; die personenbezogenen Felder werden entfernt,
	 * der Vorgang als solcher bleibt nachweisbar. Ob das im Einzelfall trägt,
	 * entscheidet der Betreiber – der Hinweistext sagt das auch.
	 *
	 * @param string $email E-Mail-Adresse.
	 * @param int    $page  Seite (1-basiert).
	 * @return array
	 */
	public function erase( $email, $page = 1 ) {
		$page     = max( 1, (int) $page );
		$per_page = 50;
		$rows     = Repository::find_by_email( $email, $per_page, ( $page - 1 ) * $per_page );
		$removed  = 0;

		foreach ( $rows as $row ) {
			if ( Repository::anonymize( (int) $row['id'] ) ) {
				++$removed;
			}
		}

		return array(
			'items_removed'  => 0,
			'items_retained' => $removed,
			'messages'       => $removed
				? array( __( 'Widerrufe wurden anonymisiert. Der Vorgang selbst bleibt als Nachweis der gesetzlichen Eingangsbestätigung erhalten – bitte prüfen Sie, ob das für Ihren Shop so zutrifft.', 'widerrufsbutton-fuer-woocommerce' ) )
				: array(),
			'done'           => count( $rows ) < $per_page,
		);
	}
}
