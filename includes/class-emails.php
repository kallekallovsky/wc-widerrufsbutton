<?php
/**
 * E-Mail-Manager: registriert die WC_Email-Klassen und löst den Versand aus.
 *
 * @package Widerrufsbutton
 */

namespace Widerrufsbutton;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Verbindet die Widerrufs-Mails mit der WooCommerce-Mailer-Infrastruktur.
 */
class Emails {

	const KEY_CUSTOMER = 'WDBTN_Email_Customer_Confirmation';
	const KEY_ADMIN    = 'WDBTN_Email_Admin_Notification';

	/**
	 * Registriert Hooks.
	 */
	public function __construct() {
		add_filter( 'woocommerce_email_classes', array( $this, 'register' ) );
		add_action( 'wdbtn_withdrawal_created', array( $this, 'on_created' ), 10, 2 );
		add_action( 'wdbtn_status_changed', array( $this, 'on_status_changed' ), 10, 3 );
	}

	/**
	 * Baut den optionalen Bestätigungslink für einen Datensatz.
	 *
	 * Liefert nur dann eine URL, wenn eine E-Mail-Bestätigung angefordert wurde
	 * (Status "unconfirmed") und ein Token vorliegt. Der Link bestätigt nur die
	 * E-Mail-Adresse – der Widerruf ist unabhängig davon bereits wirksam.
	 *
	 * @param array $record Datensatz.
	 * @return string URL oder leerer String.
	 */
	public static function confirmation_link( $record ) {
		if ( empty( $record['verification_token'] ) || 'unconfirmed' !== ( isset( $record['verification_status'] ) ? $record['verification_status'] : '' ) ) {
			return '';
		}

		return add_query_arg(
			array( 'wdbtn_verify' => rawurlencode( $record['verification_token'] ) ),
			home_url( '/' )
		);
	}

	/**
	 * Optionale Mitteilung an die Kund:in bei Ablehnung (mit Grund).
	 *
	 * @param int    $id     Widerruf-ID.
	 * @param string $status Neuer Status.
	 * @param string $note   Notiz / Ablehnungsgrund.
	 * @return void
	 */
	public function on_status_changed( $id, $status, $note = '' ) {
		if ( 'abgelehnt' !== $status || ! Settings::is_on( 'rejection_email' ) ) {
			return;
		}

		$record = Repository::get( $id );
		if ( ! $record || empty( $record['email'] ) || ! is_email( $record['email'] ) ) {
			return;
		}

		$subject = sprintf(
			/* translators: %s: Shop-Name */
			__( 'Information zu Ihrem Widerruf bei %s', 'widerrufsbutton-fuer-woocommerce' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
		);

		$lines   = array();
		$lines[] = sprintf( __( 'Hallo %s,', 'widerrufsbutton-fuer-woocommerce' ), $record['name'] );
		$lines[] = '';
		$lines[] = sprintf(
			/* translators: %s: Bestellnummer */
			__( 'zu Ihrem Widerruf für die Bestellung %s haben wir eine Rückmeldung für Sie.', 'widerrufsbutton-fuer-woocommerce' ),
			$record['order_number']
		);
		if ( '' !== $note ) {
			$lines[] = '';
			$lines[] = __( 'Anmerkung:', 'widerrufsbutton-fuer-woocommerce' ) . ' ' . $note;
		}
		$lines[] = '';
		$lines[] = __( 'Bei Rückfragen können Sie sich jederzeit an uns wenden. Ihre gesetzlichen Rechte bleiben unberührt.', 'widerrufsbutton-fuer-woocommerce' );

		wp_mail( $record['email'], $subject, implode( "\n", $lines ) );
		Repository::add_log( $id, 'system', 'rejection_email_sent', '' );
	}

	/**
	 * Meldet die eigenen E-Mail-Klassen bei WooCommerce an.
	 *
	 * @param array $emails Vorhandene E-Mail-Instanzen.
	 * @return array
	 */
	public function register( $emails ) {
		$emails[ self::KEY_CUSTOMER ] = new Email_Customer_Confirmation();
		$emails[ self::KEY_ADMIN ]    = new Email_Admin_Notification();
		return $emails;
	}

	/**
	 * Versendet nach Eingang die Bestätigung (Pflicht) und die Betreiber-Mail.
	 *
	 * @param int   $id     Widerruf-ID.
	 * @param array $record Snapshot.
	 * @return void
	 */
	public function on_created( $id, $record ) {
		$confirmed = false;

		if ( function_exists( 'WC' ) && WC()->mailer() ) {
			$emails = WC()->mailer()->get_emails();

			if ( isset( $emails[ self::KEY_CUSTOMER ] ) ) {
				$confirmed = (bool) $emails[ self::KEY_CUSTOMER ]->trigger( $id );
			}
			if ( isset( $emails[ self::KEY_ADMIN ] ) ) {
				$emails[ self::KEY_ADMIN ]->trigger( $id );
			}
		}

		// Fallback für die gesetzlich verpflichtende Eingangsbestätigung.
		if ( ! $confirmed ) {
			/*
			 * Gespeicherten Datensatz bevorzugen: $record stammt aus dem
			 * Absende-Pfad und kennt created_at noch gar nicht – das entsteht
			 * erst beim Insert. Ohne diesen Umweg müsste die Mail den
			 * Zugangszeitpunkt raten.
			 */
			$stored    = Repository::get( $id );
			$confirmed = $this->wp_mail_fallback_customer( $stored ? $stored : $record );
		}

		if ( $confirmed ) {
			Repository::mark_confirmation_sent( $id );
			Repository::add_log( $id, 'system', 'confirmation_sent', '' );
		} else {
			Repository::add_log( $id, 'system', 'confirmation_failed', '' );
		}
	}

	/**
	 * Einfacher wp_mail()-Fallback, falls die WC-Mail nicht greift.
	 *
	 * @param array $record Snapshot.
	 * @return bool
	 */
	private function wp_mail_fallback_customer( $record ) {
		if ( empty( $record['email'] ) || ! is_email( $record['email'] ) ) {
			return false;
		}

		$received = self::format_received( $record );
		$subject  = sprintf(
			/* translators: %s: Shop-Name */
			__( 'Ihr Widerruf ist bei %s eingegangen', 'widerrufsbutton-fuer-woocommerce' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
		);

		$lines   = array();
		$lines[] = sprintf( __( 'Hallo %s,', 'widerrufsbutton-fuer-woocommerce' ), $record['name'] );
		$lines[] = '';
		$lines[] = __( 'wir bestätigen den Eingang Ihres Widerrufs.', 'widerrufsbutton-fuer-woocommerce' );
		$lines[] = '';
		$lines[] = sprintf( __( 'Bestellnummer: %s', 'widerrufsbutton-fuer-woocommerce' ), $record['order_number'] );
		if ( 'item' === $record['scope'] && ! empty( $record['sku'] ) ) {
			$lines[] = sprintf( __( 'Artikel: %s', 'widerrufsbutton-fuer-woocommerce' ), $record['sku'] );
		}
		$lines[] = sprintf( __( 'Name: %s', 'widerrufsbutton-fuer-woocommerce' ), $record['name'] );
		$lines[] = sprintf( __( 'Eingegangen am: %s', 'widerrufsbutton-fuer-woocommerce' ), $received );
		$lines[] = '';
		$lines[] = __( 'Wir werden Ihren Widerruf zeitnah bearbeiten.', 'widerrufsbutton-fuer-woocommerce' );

		$link = self::confirmation_link( $record );
		if ( '' !== $link ) {
			$lines[] = '';
			$lines[] = __( 'Zur Bestätigung Ihrer E-Mail-Adresse können Sie optional den folgenden Link aufrufen. Ihr Widerruf ist bereits eingegangen – das ist nicht erforderlich:', 'widerrufsbutton-fuer-woocommerce' );
			$lines[] = esc_url_raw( $link );
		}

		return (bool) wp_mail( $record['email'], $subject, implode( "\n", $lines ) );
	}

	/**
	 * Formatiert den Zugangszeitpunkt eines Widerrufs gemäß Site-Einstellungen.
	 *
	 * Zentral, weil derselbe Wert in beiden E-Mail-Templates und im
	 * wp_mail()-Fallback erscheint – und dort überall denselben Zeitpunkt
	 * nennen muss.
	 *
	 * @param array $record Datensatz (erwartet created_at_gmt bzw. created_at).
	 * @return string
	 */
	public static function format_received( $record ) {
		$fmt = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		/*
		 * Der Zugangszeitpunkt der Erklärung, nicht der Sendezeitpunkt der
		 * Mail. Vorher wurde hier schlicht "jetzt" formatiert – im Gast-Pfad
		 * läuft diese Methode aber erst beim Klick auf den Bestätigungslink,
		 * also bis zu 24 Stunden später. Die Eingangsbestätigung nannte damit
		 * eine andere Uhrzeit als der Datensatz, der sie belegen soll.
		 */
		if ( ! empty( $record['created_at_gmt'] ) ) {
			// Eindeutiger UTC-Zeitstempel: die verlässlichste Quelle.
			$ts = strtotime( $record['created_at_gmt'] . ' UTC' );
			if ( $ts ) {
				return wp_date( $fmt, $ts ) . ' (' . wp_date( 'T', $ts ) . ')';
			}
		}

		if ( ! empty( $record['created_at'] ) ) {
			// Datensätze aus DB-Version 1: created_at ist Ortszeit ohne Offset.
			// date_i18n() dreht genau diesen Altfall korrekt zurück.
			$ts = strtotime( $record['created_at'] );
			if ( $ts ) {
				return date_i18n( $fmt, $ts );
			}
		}

		return date_i18n( $fmt );
	}
}
