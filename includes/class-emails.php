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
			$confirmed = $this->wp_mail_fallback_customer( $record );
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

		$received = $this->format_now();
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

		return (bool) wp_mail( $record['email'], $subject, implode( "\n", $lines ) );
	}

	/**
	 * Aktuelles Datum + Uhrzeit gemäß Site-Einstellungen.
	 *
	 * @return string
	 */
	private function format_now() {
		return date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
	}
}
