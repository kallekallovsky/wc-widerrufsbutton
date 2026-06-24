<?php
/**
 * Bestelllogik: read-only, HPOS-kompatibel, datumsbasierte Fristprüfung,
 * flexibles Bestellnummern-Matching (Billbee/German Market/Rechnungsnummern).
 *
 * @package Widerrufsbutton
 */

namespace Widerrufsbutton;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Liest WooCommerce-Bestellungen, ohne sie zu verändern.
 */
class Orders {

	/**
	 * Widerrufsfenster in Tagen (0 = keine Begrenzung).
	 *
	 * @return int
	 */
	public static function window_days() {
		return max( 0, (int) Settings::get( 'withdrawal_days', 14 ) );
	}

	/**
	 * Berechnungsbasis für die Frist.
	 *
	 * Hinweis: bewusst datumsbasiert (nicht statusbasiert), da eine angebundene
	 * Warenwirtschaft (Billbee) den WooCommerce-Status umschreiben kann.
	 *
	 * @return string order_date|completed_date
	 */
	public static function date_basis() {
		$basis = Settings::get( 'date_basis', 'order_date' );
		return in_array( $basis, array( 'order_date', 'completed_date' ), true ) ? $basis : 'order_date';
	}

	/**
	 * Referenzdatum einer Bestellung gemäß Einstellung.
	 *
	 * @param \WC_Order $order Bestellung.
	 * @return \WC_DateTime|null
	 */
	public static function reference_date( $order ) {
		$date = null;

		if ( 'completed_date' === self::date_basis() && method_exists( $order, 'get_date_completed' ) ) {
			$date = $order->get_date_completed();
		}

		if ( ! $date ) {
			$date = $order->get_date_created();
		}

		return $date ? $date : null;
	}

	/**
	 * Prüft, ob eine Bestellung im Widerrufsfenster liegt (datumsbasiert).
	 *
	 * @param \WC_Order $order Bestellung.
	 * @return bool
	 */
	public static function is_within_window( $order ) {
		$days = self::window_days();
		if ( $days <= 0 ) {
			return true;
		}

		$date = self::reference_date( $order );
		if ( ! $date ) {
			// Im Zweifel anbieten – das Plugin blockiert nicht hart.
			return true;
		}

		$deadline = $date->getTimestamp() + ( $days * DAY_IN_SECONDS );

		return time() <= $deadline;
	}

	/**
	 * Prüft die Zugehörigkeit einer Bestellung zu einem Benutzer.
	 *
	 * @param \WC_Order $order   Bestellung.
	 * @param int       $user_id Benutzer-ID.
	 * @return bool
	 */
	public static function belongs_to_user( $order, $user_id ) {
		return $order && $user_id && (int) $order->get_customer_id() === (int) $user_id;
	}

	/**
	 * Liefert die widerrufbaren Bestellungen eines eingeloggten Nutzers.
	 *
	 * Es werden bewusst alle Status berücksichtigt (datumsbasierte Vorauswahl),
	 * nicht nach WooCommerce-Status gefiltert.
	 *
	 * @param int $user_id Benutzer-ID.
	 * @return array Liste aus id/number/date/label.
	 */
	public static function for_user( $user_id ) {
		$out = array();

		if ( ! $user_id || ! function_exists( 'wc_get_orders' ) ) {
			return $out;
		}

		$limit = (int) apply_filters( 'wdbtn_user_orders_limit', 50 );

		$orders = wc_get_orders(
			array(
				'customer_id' => $user_id,
				'limit'       => $limit,
				'orderby'     => 'date',
				'order'       => 'DESC',
				'return'      => 'objects',
			)
		);

		foreach ( $orders as $order ) {
			if ( ! self::is_within_window( $order ) ) {
				continue;
			}

			$date_str = $order->get_date_created() ? wc_format_datetime( $order->get_date_created() ) : '';

			$out[] = array(
				'id'     => $order->get_id(),
				'number' => $order->get_order_number(),
				'date'   => $date_str,
				'label'  => sprintf(
					/* translators: 1: Bestellnummer, 2: Datum */
					__( 'Bestellung #%1$s vom %2$s', 'widerrufsbutton-fuer-woocommerce' ),
					$order->get_order_number(),
					$date_str
				),
			);
		}

		return $out;
	}

	/**
	 * Prüft, ob ein Produkt vom Widerruf ausgeschlossen ist.
	 *
	 * @param int $product_id Produkt-ID.
	 * @return bool
	 */
	public static function is_product_excluded( $product_id ) {
		$product_id = (int) $product_id;
		if ( ! $product_id || ! function_exists( 'wc_get_product' ) ) {
			return false;
		}

		$types = (array) Settings::get( 'excluded_product_types', array() );
		$cats  = array_map( 'intval', (array) Settings::get( 'excluded_categories', array() ) );
		$prods = array_map( 'intval', (array) Settings::get( 'excluded_products', array() ) );

		if ( in_array( $product_id, $prods, true ) ) {
			return true;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return false;
		}

		foreach ( $types as $type ) {
			if ( 'virtual' === $type && $product->is_virtual() ) {
				return true;
			}
			if ( 'downloadable' === $type && $product->is_downloadable() ) {
				return true;
			}
			if ( in_array( $type, array( 'grouped', 'external' ), true ) && $product->is_type( $type ) ) {
				return true;
			}
		}

		if ( $cats && function_exists( 'wc_get_product_term_ids' ) ) {
			$term_ids = wc_get_product_term_ids( $product_id, 'product_cat' );
			if ( array_intersect( $cats, (array) $term_ids ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Flexibler Gast-Abgleich: E-Mail + Bestellnummer gegen mehrere Quellen.
	 *
	 * Geprüft werden WC-Bestellnummer, _order_number(_formatted)-Meta und die
	 * rohe Order-ID – case-insensitiv und tolerant gegenüber Präfixen/Format.
	 * Nur Bestellungen der angegebenen E-Mail werden betrachtet (Datenschutz).
	 *
	 * @param string $email        E-Mail.
	 * @param string $order_number Vom Kunden eingegebene Bestellnummer.
	 * @return \WC_Order|null
	 */
	public static function match_guest( $email, $order_number ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return null;
		}

		$email_l = strtolower( trim( (string) $email ) );
		$raw     = trim( (string) $order_number );

		if ( '' === $email_l || '' === $raw || ! is_email( $email_l ) ) {
			return null;
		}

		$limit = (int) apply_filters( 'wdbtn_guest_orders_limit', 50 );

		$orders = wc_get_orders(
			array(
				'billing_email' => $email_l,
				'limit'         => $limit,
				'orderby'       => 'date',
				'order'         => 'DESC',
				'return'        => 'objects',
			)
		);

		foreach ( $orders as $order ) {
			if ( self::number_matches( $order, $raw ) ) {
				return $order;
			}
		}

		// Fallback: rohe Order-ID + E-Mail-Abgleich.
		if ( ctype_digit( $raw ) ) {
			$order = wc_get_order( (int) $raw );
			if ( $order && strtolower( (string) $order->get_billing_email() ) === $email_l ) {
				return $order;
			}
		}

		return null;
	}

	/**
	 * Vergleicht die eingegebene Nummer mit den Nummern-Quellen einer Bestellung.
	 *
	 * @param \WC_Order $order Bestellung.
	 * @param string    $raw   Eingegebene Nummer.
	 * @return bool
	 */
	private static function number_matches( $order, $raw ) {
		$needle_norm = self::normalize( $raw );
		$needle_dig  = self::digits( $raw );

		$sources = array(
			(string) $order->get_order_number(),
			(string) $order->get_id(),
		);

		foreach ( array( '_order_number', '_order_number_formatted' ) as $meta_key ) {
			$meta = $order->get_meta( $meta_key );
			if ( $meta ) {
				$sources[] = (string) $meta;
			}
		}

		foreach ( $sources as $source ) {
			$norm = self::normalize( $source );
			if ( '' !== $needle_norm && $norm === $needle_norm ) {
				return true;
			}
			$dig = self::digits( $source );
			if ( '' !== $needle_dig && $dig === $needle_dig ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Normalisiert eine Nummer (lowercase, nur alphanumerisch).
	 *
	 * @param string $value Wert.
	 * @return string
	 */
	private static function normalize( $value ) {
		$value = strtolower( (string) $value );
		$value = preg_replace( '/[^a-z0-9]/', '', $value );
		return null === $value ? '' : $value;
	}

	/**
	 * Reduziert auf Ziffernfolge.
	 *
	 * @param string $value Wert.
	 * @return string
	 */
	private static function digits( $value ) {
		$value = preg_replace( '/\D/', '', (string) $value );
		return null === $value ? '' : $value;
	}
}
