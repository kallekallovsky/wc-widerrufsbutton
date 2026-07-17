<?php
/**
 * AJAX-Submission, Bestell-Endpoint und Gast-Verifizierung.
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

	const NONCE_ACTION  = 'wdbtn_submit';
	const NONCE_ORDERS  = 'wdbtn_orders';

	/**
	 * Registriert die Endpunkte.
	 */
	public function __construct() {
		add_action( 'wp_ajax_wdbtn_submit', array( $this, 'handle' ) );
		add_action( 'wp_ajax_nopriv_wdbtn_submit', array( $this, 'handle' ) );
		add_action( 'wp_ajax_wdbtn_orders', array( $this, 'orders' ) );
		add_action( 'init', array( $this, 'maybe_handle_verification' ) );
	}

	/**
	 * Liefert die widerrufbaren Bestellungen des eingeloggten Nutzers.
	 *
	 * @return void
	 */
	public function orders() {
		if ( ! check_ajax_referer( self::NONCE_ORDERS, 'nonce', false ) ) {
			wp_send_json_error( array( 'orders' => array() ), 403 );
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_send_json_success( array( 'orders' => array() ) );
		}

		wp_send_json_success( array( 'orders' => Orders::for_user( $user_id ) ) );
	}

	/**
	 * Verarbeitet eine Widerrufs-Einreichung.
	 *
	 * @return void
	 */
	public function handle() {
		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Sicherheitsprüfung fehlgeschlagen. Bitte laden Sie die Seite neu.', 'widerrufsbutton-fuer-woocommerce' ) ),
				403
			);
		}

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

		// Strikte, read-only Auflösung der Bestellung.
		$order = null;

		if ( $user_id && $order_id_in && function_exists( 'wc_get_order' ) ) {
			$candidate = wc_get_order( $order_id_in );
			if ( Orders::belongs_to_user( $candidate, $user_id ) ) {
				$order = $candidate;
			}
		}

		if ( ! $order ) {
			// Gast-Pfad (auch Fallback für eingeloggte ohne gültige Auswahl).
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

			$order = Orders::match_guest( $email, $order_number );
		}

		/*
		 * Ohne zuordenbare Bestellung wurde die Erklärung früher mit HTTP 404
		 * verworfen. Das ist die riskanteste denkbare Voreinstellung: Der
		 * Widerruf wird mit Zugang wirksam (§ 130 BGB), nicht erst mit
		 * erfolgreicher Zuordnung – und § 356a BGB verlangt eine Bestätigung
		 * eben dieses Zugangs. Wer auf "verbindlich bestätigen" geklickt hat,
		 * hat widerrufen; ein Tippfehler in der Bestellnummer ändert daran
		 * nichts. Also: annehmen, dokumentieren, im Backend zur Klärung
		 * flaggen. Nebeneffekt: Die Antwort ist jetzt unabhängig davon, ob es
		 * einen Treffer gab – das beseitigt das Enumerations-Orakel.
		 */
		$unmatched = ( ! $order );

		if ( $unmatched && ! Settings::is_on( 'accept_unmatched' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Zu diesen Angaben wurde in diesem Shop keine Bestellung gefunden. Bitte prüfen Sie Bestellnummer und E-Mail-Adresse.', 'widerrufsbutton-fuer-woocommerce' ) ),
				404
			);
		}

		$resolved_id     = $order ? (int) $order->get_id() : 0;
		$resolved_number = $order ? (string) $order->get_order_number() : $order_number;
		$customer_uid    = $user_id ? $user_id : ( $order ? (int) $order->get_customer_id() : 0 );

		// Snapshot bei eingeloggten Nutzern aus dem Konto vervollständigen.
		if ( $user_id ) {
			$current = wp_get_current_user();

			/*
			 * Die Kontoadresse hat Vorrang vor dem POST-Wert. Vorher wurde sie
			 * nur ergänzt, falls das Feld leer war – ein eingeloggter Nutzer
			 * konnte damit eine beliebige Adresse mitschicken und sich die vom
			 * Shop signierte Eingangsbestätigung dorthin senden lassen.
			 */
			if ( $current && $current->user_email ) {
				$email = sanitize_email( $current->user_email );
			}
			if ( '' === $name && $current ) {
				$name = sanitize_text_field( trim( $current->first_name . ' ' . $current->last_name ) );
				if ( '' === $name ) {
					$name = sanitize_text_field( $current->display_name );
				}
			}
		}

		// Letzte Absicherung: Ohne gültige Adresse gäbe es keine
		// Eingangsbestätigung – und die ist gesetzlich vorgeschrieben.
		if ( ! is_email( $email ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Bitte geben Sie eine gültige E-Mail-Adresse an.', 'widerrufsbutton-fuer-woocommerce' ) ),
				400
			);
		}

		/*
		 * Artikelbezug nur übernehmen, wenn der Artikel auch wirklich Position
		 * der Bestellung ist. Sonst stünde in der gesetzlichen
		 * Eingangsbestätigung ein Artikel, der nie bestellt wurde – und der
		 * Duplikat-Check ließe sich durch Variieren der product_id aushebeln.
		 * Bei nicht zugeordneten Erklärungen gibt es keine Bestellung, gegen
		 * die geprüft werden könnte; dort bleibt die Kundenangabe stehen.
		 */
		if ( 'item' === $scope && $order && ( ! $product_id || ! Orders::order_has_product( $order, $product_id ) ) ) {
			$scope      = 'order';
			$product_id = 0;
			$sku        = '';
		}

		// Snapshot-Datensatz.
		$record = array(
			'order_id'            => $resolved_id,
			'order_number'        => $resolved_number,
			'product_id'          => ( 'item' === $scope ) ? $product_id : 0,
			'sku'                 => ( 'item' === $scope ) ? $sku : '',
			'scope'               => $scope,
			'customer_user_id'    => $customer_uid,
			'name'                => $name,
			'email'               => $email,
			'reason'              => $reason,
			'status'              => $unmatched ? 'nicht_zugeordnet' : 'eingegangen',
			'confirmation_sent'   => 0,
			'ip_hash'             => $ip ? wp_hash( $ip ) : '',
		);

		/*
		 * Produkt-Ausschlüsse dokumentieren statt blockieren. Vorher brach der
		 * Request mit HTTP 422 ab – im Widerspruch zur eigenen Zusage
		 * "blockiert nicht hart" und rechtlich heikel: "virtuell" trifft in
		 * WooCommerce auch Dienstleistungen, die regelmäßig gerade nicht vom
		 * Widerruf ausgenommen sind, und bei Downloads erlischt das Recht nur
		 * unter Bedingungen, die das Plugin nicht kennt. Die Entscheidung
		 * gehört zum Betreiber, nicht in eine Checkbox.
		 */
		$excluded = ( 'item' === $scope && $product_id && Orders::is_product_excluded( $product_id ) );

		// Duplikat-Prüfung nur bei zugeordneter Bestellung – ohne order_id
		// gibt es nichts, wogegen sinnvoll geprüft werden könnte.
		if ( ! $unmatched && Repository::has_open( $resolved_id, $scope, $record['product_id'] ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Für diese Bestellung liegt bereits ein Widerruf vor. Er ist bei uns erfasst – Sie müssen nichts weiter tun. Falls Sie noch auf eine Bestätigungs-E-Mail warten, prüfen Sie bitte auch Ihren Spam-Ordner.', 'widerrufsbutton-fuer-woocommerce' ) ),
				409
			);
		}

		// Gast-Verifizierung (Anti-Missbrauch), sofern aktiviert.
		$needs_verification = ( ! $user_id ) && Settings::is_on( 'guest_verification' );

		if ( $needs_verification ) {
			$token                          = wp_generate_password( 40, false, false );
			$record['verification_status']  = 'pending';
			$record['verification_token']   = $token;
			$record['token_expires_at']     = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );

			$id = Repository::insert( $record );
			if ( ! $id ) {
				wp_send_json_error(
					array( 'message' => __( 'Der Widerruf konnte nicht gespeichert werden. Bitte versuchen Sie es erneut.', 'widerrufsbutton-fuer-woocommerce' ) ),
					500
				);
			}

			Repository::add_log( $id, 'kunde', 'eingegangen_unbestaetigt', '' );
			$this->log_review_flags( $id, $unmatched, $excluded );

			/**
			 * Versand der Verifizierungs-E-Mail (Emails-Komponente).
			 *
			 * @param int    $id     Datensatz-ID.
			 * @param array  $record Snapshot.
			 * @param string $token  Verifizierungs-Token.
			 */
			do_action( 'wdbtn_verification_requested', $id, array_merge( $record, array( 'id' => $id ) ), $token );

			wp_send_json_success(
				array(
					'id'      => $id,
					'pending' => 1,
					'message' => __( 'Fast geschafft: Wir haben Ihnen eine E-Mail mit einem Bestätigungslink gesendet. Bitte bestätigen Sie Ihren Widerruf darüber.', 'widerrufsbutton-fuer-woocommerce' ),
				)
			);
		}

		// Direktes Speichern (eingeloggt oder Verifizierung deaktiviert).
		$record['verification_status'] = 'verified';

		$id = Repository::insert( $record );
		if ( ! $id ) {
			wp_send_json_error(
				array( 'message' => __( 'Der Widerruf konnte nicht gespeichert werden. Bitte versuchen Sie es erneut.', 'widerrufsbutton-fuer-woocommerce' ) ),
				500
			);
		}

		Repository::add_log( $id, 'kunde', 'eingegangen', '' );
		$this->log_review_flags( $id, $unmatched, $excluded );
		$this->finalize( $id, $order, $record );

		wp_send_json_success(
			array(
				'id'      => $id,
				'message' => __( 'Ihr Widerruf ist eingegangen. Eine Eingangsbestätigung wird an Ihre E-Mail-Adresse gesendet.', 'widerrufsbutton-fuer-woocommerce' ),
			)
		);
	}

	/**
	 * Vermerkt Punkte, die der Betreiber manuell prüfen muss.
	 *
	 * Beides sind bewusst keine Ablehnungsgründe: Der Widerruf ist zugegangen
	 * und damit wirksam. Ob er greift, entscheidet der Betreiber – das Plugin
	 * sorgt nur dafür, dass der Fall im Backend auffällt.
	 *
	 * @param int  $id        Datensatz-ID.
	 * @param bool $unmatched Keine Bestellung zuordenbar.
	 * @param bool $excluded  Artikel ist als ausgeschlossen konfiguriert.
	 * @return void
	 */
	private function log_review_flags( $id, $unmatched, $excluded ) {
		if ( $unmatched ) {
			Repository::add_log(
				$id,
				'system',
				'nicht_zugeordnet',
				__( 'Zu den Angaben wurde keine Bestellung gefunden. Der Widerruf wurde dennoch erfasst und bedarf der manuellen Zuordnung.', 'widerrufsbutton-fuer-woocommerce' )
			);
		}

		if ( $excluded ) {
			Repository::add_log(
				$id,
				'system',
				'ausschluss_geprueft',
				__( 'Der Artikel ist in den Einstellungen als ausgeschlossen konfiguriert. Der Widerruf wurde dennoch erfasst – bitte prüfen Sie, ob der Ausschluss rechtlich trägt.', 'widerrufsbutton-fuer-woocommerce' )
			);
		}
	}

	/**
	 * Verarbeitet den Klick auf den Verifizierungslink (Gast).
	 *
	 * @return void
	 */
	public function maybe_handle_verification() {
		if ( empty( $_GET['wdbtn_verify'] ) ) {
			return;
		}

		$token  = sanitize_text_field( wp_unslash( $_GET['wdbtn_verify'] ) );
		$record = Repository::get_by_token( $token );

		if ( ! $record || 'pending' !== $record['verification_status'] ) {
			wp_die(
				esc_html__( 'Dieser Bestätigungslink ist ungültig oder wurde bereits verwendet.', 'widerrufsbutton-fuer-woocommerce' ),
				esc_html__( 'Widerruf bestätigen', 'widerrufsbutton-fuer-woocommerce' ),
				array(
					'response'  => 200,
					'back_link' => true,
				)
			);
		}

		if ( ! empty( $record['token_expires_at'] ) && strtotime( $record['token_expires_at'] . ' UTC' ) < time() ) {
			wp_die(
				esc_html__( 'Dieser Bestätigungslink ist abgelaufen. Bitte starten Sie den Widerruf erneut.', 'widerrufsbutton-fuer-woocommerce' ),
				esc_html__( 'Widerruf bestätigen', 'widerrufsbutton-fuer-woocommerce' ),
				array(
					'response'  => 200,
					'back_link' => true,
				)
			);
		}

		$id = (int) $record['id'];
		Repository::mark_verified( $id );
		Repository::add_log( $id, 'kunde', 'bestaetigt', '' );

		$full  = Repository::get( $id );
		$order = ( $full && ! empty( $full['order_id'] ) && function_exists( 'wc_get_order' ) ) ? wc_get_order( (int) $full['order_id'] ) : null;

		$this->finalize( $id, $order ? $order : null, $full ? $full : $record );

		wp_die(
			esc_html__( 'Vielen Dank. Ihr Widerruf wurde bestätigt. Eine Eingangsbestätigung wurde an Ihre E-Mail-Adresse gesendet.', 'widerrufsbutton-fuer-woocommerce' ),
			esc_html__( 'Widerruf bestätigt', 'widerrufsbutton-fuer-woocommerce' ),
			array(
				'response'  => 200,
				'back_link' => true,
			)
		);
	}

	/**
	 * Abschluss: additive Bestellnotiz, Notifier, Eingangs-Mails.
	 *
	 * @param int            $id     Widerruf-ID.
	 * @param \WC_Order|null $order  Bestellung (oder null).
	 * @param array          $record Snapshot.
	 * @return void
	 */
	private function finalize( $id, $order, $record ) {
		if ( $order && Settings::is_on( 'add_order_note' ) ) {
			$this->maybe_add_order_note( $order, $id, $record );
		}

		$notifier = Plugin::instance()->notifier();
		if ( $notifier instanceof Notifier ) {
			$notifier->notify( array_merge( $record, array( 'id' => $id ) ) );
		}

		/**
		 * Wird nach finaler Erfassung ausgelöst (Eingangsbestätigung + Betreiber-Mail).
		 *
		 * @param int   $id     Datensatz-ID.
		 * @param array $record Snapshot.
		 */
		do_action( 'wdbtn_withdrawal_created', $id, $record );
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
			$noop = true;
			unset( $noop );
		}
	}

	/**
	 * Ermittelt die Client-IP.
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
	 * @return bool
	 */
	private function is_rate_limited( $ip ) {
		if ( '' === $ip ) {
			return false;
		}

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
