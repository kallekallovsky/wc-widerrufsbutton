<?php
/**
 * AJAX-Submission-Handling für den Widerruf.
 *
 * @package Widerrufsbutton
 */

namespace Widerrufsbutton;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Nimmt Widerrufe entgegen, validiert, speichert (Snapshot) und benachrichtigt.
 */
class Ajax {

	const NONCE_ACTION = 'wdbtn_submit';

	/**
	 * Registriert die AJAX-Endpunkte (eingeloggt und Gast).
	 */
	public function __construct() {
		add_action( 'wp_ajax_wdbtn_submit', array( $this, 'handle' ) );
		add_action( 'wp_ajax_nopriv_wdbtn_submit', array( $this, 'handle' ) );
	}

	/**
	 * Verarbeitet eine Widerrufs-Einreichung.
	 *
	 * @return void
	 */
	public function handle() {
		// Nonce prüfen.
		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Sicherheitsprüfung fehlgeschlagen. Bitte laden Sie die Seite neu.', 'widerrufsbutton-fuer-woocommerce' ) ),
				403
			);
		}

		// Einfaches Rate-Limiting pro IP (Missbrauchsschutz).
		$ip = $this->client_ip();
		if ( $this->is_rate_limited( $ip ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Zu viele Anfragen. Bitte versuchen Sie es in einigen Minuten erneut.', 'widerrufsbutton-fuer-woocommerce' ) ),
				429
			);
		}

		// Eingaben bereinigen.
		$name         = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$email        = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$order_number = isset( $_POST['order_number'] ) ? sanitize_text_field( wp_unslash( $_POST['order_number'] ) ) : '';
		$order_id_in  = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$product_id   = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$sku          = isset( $_POST['sku'] ) ? sanitize_text_field( wp_unslash( $_POST['sku'] ) ) : '';
		$reason       = isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '';
		$scope_in     = isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : 'order';
		$scope        = in_array( $scope_in, array( 'order', 'item' ), true ) ? $scope_in : 'order';

		$user_id = get_current_user_id();

		// Validierung: eingeloggt mit Bestellauswahl ODER Gast-Pflichtfelder.
		$has_order_selection = ( $user_id && $order_id_in );

		if ( ! $has_order_selection ) {
			if ( '' === $name || '' === $order_number || '' === $email ) {
				wp_send_json_error(
					array( 'message' => __( 'Bitte füllen Sie die Pflichtfelder aus (Name, Bestellnummer, E-Mail).', 'widerrufsbutton-fuer-woocommerce' ) ),
					400
				);
			}
			if ( ! is_email( $email ) ) {
				wp_send_json_error(
					array( 'message' => __( 'Bitte geben Sie eine gültige E-Mail-Adresse an.', 'widerrufsbutton-fuer-woocommerce' ) ),
					400
				);
			}
		}

		// Bestellung (rudimentär) auflösen – read-only. Strikter Abgleich folgt in Phase 4.
		$order            = $this->maybe_resolve_order( $user_id, $order_id_in, $order_number );
		$resolved_id      = $order ? (int) $order->get_id() : 0;
		$resolved_number  = $order ? (string) $order->get_order_number() : $order_number;
		$customer_user_id = $user_id ? $user_id : ( $order ? (int) $order->get_customer_id() : 0 );

		// Falls eingeloggt und Name/E-Mail leer: aus Konto ergänzen (Snapshot vervollständigen).
		if ( $user_id ) {
			$current = wp_get_current_user();
			if ( '' === $email && $current && $current->user_email ) {
				$email = sanitize_email( $current->user_email );
			}
			if ( '' === $name && $current ) {
				$name = sanitize_text_field( trim( $current->first_name . ' ' . $current->last_name ) );
				if ( '' === $name ) {
					$name = sanitize_text_field( $current->display_name );
				}
			}
		}

		// Snapshot-Datensatz aufbauen.
		$record = array(
			'order_id'            => $resolved_id,
			'order_number'        => $resolved_number,
			'product_id'          => ( 'item' === $scope ) ? $product_id : 0,
			'sku'                 => ( 'item' === $scope ) ? $sku : '',
			'scope'               => $scope,
			'customer_user_id'    => $customer_user_id,
			'name'                => $name,
			'email'               => $email,
			'reason'              => $reason,
			'status'              => 'eingegangen',
			'verification_status' => 'verified',
			'confirmation_sent'   => 0,
			'ip_hash'             => $ip ? wp_hash( $ip ) : '',
		);

		$id = Repository::insert( $record );

		if ( ! $id ) {
			wp_send_json_error(
				array( 'message' => __( 'Der Widerruf konnte nicht gespeichert werden. Bitte versuchen Sie es erneut.', 'widerrufsbutton-fuer-woocommerce' ) ),
				500
			);
		}

		Repository::add_log( $id, 'kunde', 'eingegangen', '' );

		// Optionale, rein additive Bestellnotiz (kein Statuswechsel) – abschaltbar.
		if ( $order && Settings::is_on( 'add_order_note' ) ) {
			$this->maybe_add_order_note( $order, $id, $record );
		}

		// Erweiterungsstelle Phase 2 (Notifier, im MVP No-op).
		$notifier = Plugin::instance()->notifier();
		if ( $notifier instanceof Notifier ) {
			$notifier->notify( array_merge( $record, array( 'id' => $id ) ) );
		}

		/**
		 * Wird nach erfolgreicher Speicherung ausgelöst.
		 * Die E-Mail-Komponente (Phase 3) hängt sich hier ein.
		 *
		 * @param int   $id     Datensatz-ID.
		 * @param array $record Snapshot-Daten.
		 */
		do_action( 'wdbtn_withdrawal_created', $id, $record );

		wp_send_json_success(
			array(
				'id'      => $id,
				'message' => __( 'Ihr Widerruf ist eingegangen. Eine Eingangsbestätigung wird an Ihre E-Mail-Adresse gesendet.', 'widerrufsbutton-fuer-woocommerce' ),
			)
		);
	}

	/**
	 * Löst eine Bestellung lose auf (read-only, keine strenge Zuordnung).
	 *
	 * @param int    $user_id      Aktuelle Benutzer-ID.
	 * @param int    $order_id_in  Übergebene Bestell-ID.
	 * @param string $order_number Übergebene Bestellnummer.
	 * @return \WC_Order|null
	 */
	private function maybe_resolve_order( $user_id, $order_id_in, $order_number ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return null;
		}

		$candidate = 0;
		if ( $order_id_in ) {
			$candidate = $order_id_in;
		} elseif ( '' !== $order_number && ctype_digit( $order_number ) ) {
			$candidate = (int) $order_number;
		}

		if ( ! $candidate ) {
			return null;
		}

		$order = wc_get_order( $candidate );

		return $order ? $order : null;
	}

	/**
	 * Hängt eine additive Bestellnotiz an (kein Statuswechsel, kein Refund).
	 *
	 * @param \WC_Order $order  Bestellung.
	 * @param int       $id     Widerruf-ID.
	 * @param array     $record Snapshot.
	 * @return void
	 */
	private function maybe_add_order_note( $order, $id, $record ) {
		$note = sprintf(
			/* translators: 1: Widerruf-ID, 2: Umfang */
			__( 'Widerruf eingegangen (Vorgang #%1$d, Umfang: %2$s). Dokumentiert im Plugin „Widerrufsbutton". Kein automatischer Statuswechsel.', 'widerrufsbutton-fuer-woocommerce' ),
			$id,
			'item' === $record['scope'] ? __( 'Artikel', 'widerrufsbutton-fuer-woocommerce' ) : __( 'gesamte Bestellung', 'widerrufsbutton-fuer-woocommerce' )
		);

		try {
			$order->add_order_note( $note );
		} catch ( \Exception $e ) {
			// Bestellnotiz ist optional; Fehler hier dürfen den Widerruf nicht verhindern.
			$noop = true;
			unset( $noop );
		}
	}

	/**
	 * Ermittelt die Client-IP (für gehashte Speicherung / Rate-Limit).
	 *
	 * @return string
	 */
	private function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return ( $ip && filter_var( $ip, FILTER_VALIDATE_IP ) ) ? $ip : '';
	}

	/**
	 * Einfaches IP-basiertes Rate-Limit über Transients.
	 *
	 * @param string $ip Client-IP.
	 * @return bool True, wenn das Limit überschritten ist.
	 */
	private function is_rate_limited( $ip ) {
		if ( '' === $ip ) {
			return false;
		}

		/**
		 * Maximale Einreichungen pro Zeitfenster.
		 *
		 * @param int $max Standard 5.
		 */
		$max    = (int) apply_filters( 'wdbtn_rate_limit_max', 5 );
		$window = (int) apply_filters( 'wdbtn_rate_limit_window', 10 * MINUTE_IN_SECONDS );

		$key   = 'wdbtn_rl_' . md5( $ip );
		$count = (int) get_transient( $key );

		if ( $count >= $max ) {
			return true;
		}

		set_transient( $key, $count + 1, $window );
		return false;
	}
}
