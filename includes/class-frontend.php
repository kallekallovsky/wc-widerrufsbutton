<?php
/**
 * Frontend: Sticky-Button, Modal-Overlay, Shortcode, Asset-Einbindung.
 *
 * @package Widerrufsbutton
 */

namespace Widerrufsbutton;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stellt den loginfreien Widerrufsbutton und das Modal bereit.
 */
class Frontend {

	/**
	 * Merker: Modal-Markup wurde bereits ausgegeben.
	 *
	 * @var bool
	 */
	private static $modal_rendered = false;

	/**
	 * Merker: ein Shortcode-Trigger verlangt das Modal im Footer.
	 *
	 * @var bool
	 */
	private static $needs_modal = false;

	/**
	 * Registriert die Hooks.
	 */
	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_footer', array( $this, 'render_footer' ), 20 );
		add_shortcode( 'widerrufsbutton', array( $this, 'shortcode' ) );
		add_filter( 'woocommerce_my_account_my_orders_actions', array( $this, 'my_account_action' ), 10, 2 );
	}

	/**
	 * Fügt im Kundenkonto pro Bestellung einen Widerrufen-Button hinzu.
	 *
	 * @param array     $actions Vorhandene Aktionen.
	 * @param \WC_Order $order   Bestellung.
	 * @return array
	 */
	public function my_account_action( $actions, $order ) {
		if ( ! Settings::is_on( 'enable_dashboard' ) ) {
			return $actions;
		}

		if ( class_exists( '\Widerrufsbutton\Orders' ) && ! Orders::is_within_window( $order ) ) {
			return $actions;
		}

		// Sorgt dafür, dass das Modal im Footer ausgegeben wird.
		self::$needs_modal = true;

		$actions['wdbtn-trigger'] = array(
			'url'  => '#wdbtn-order-' . $order->get_id(),
			'name' => __( 'Widerrufen', 'widerrufsbutton-fuer-woocommerce' ),
		);

		return $actions;
	}

	/**
	 * Bindet CSS/JS ein und übergibt Konfiguration an das Script.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		wp_enqueue_style(
			'wdbtn-frontend',
			WDBTN_URL . 'assets/css/widerrufsbutton.css',
			array(),
			WDBTN_VERSION
		);

		$theme_css = self::theme_css();
		if ( '' !== $theme_css ) {
			wp_add_inline_style( 'wdbtn-frontend', $theme_css );
		}

		wp_enqueue_script(
			'wdbtn-frontend',
			WDBTN_URL . 'assets/js/widerrufsbutton.js',
			array(),
			WDBTN_VERSION,
			true
		);

		$config = array(
			'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
			'action'       => 'wdbtn_submit',
			'nonce'        => wp_create_nonce( 'wdbtn_submit' ),
			'ordersAction' => 'wdbtn_orders',
			'ordersNonce'  => wp_create_nonce( 'wdbtn_orders' ),
			'isLoggedIn'   => is_user_logged_in(),
			'prefillSku'   => $this->current_product_ref(),
			'i18n'         => array(
				'genericError'  => __( 'Es ist ein Fehler aufgetreten. Bitte versuchen Sie es erneut.', 'widerrufsbutton-fuer-woocommerce' ),
				'notReady'      => __( 'Das Absenden ist noch nicht verfügbar.', 'widerrufsbutton-fuer-woocommerce' ),
				'fillRequired'  => __( 'Bitte füllen Sie die Pflichtfelder aus.', 'widerrufsbutton-fuer-woocommerce' ),
				'invalidEmail'  => __( 'Bitte geben Sie eine gültige E-Mail-Adresse an.', 'widerrufsbutton-fuer-woocommerce' ),
				'sending'       => __( 'Wird gesendet …', 'widerrufsbutton-fuer-woocommerce' ),
				'ordersFailed'  => __( 'Ihre Bestellungen konnten nicht geladen werden. Bitte laden Sie die Seite neu oder geben Sie Ihre Bestellnummer manuell an.', 'widerrufsbutton-fuer-woocommerce' ),
				// Beschriftungen der Zusammenfassung in Schritt 2. Vorher im
				// JavaScript fest auf Deutsch verdrahtet und damit unübersetzbar.
				'labelName'     => __( 'Name', 'widerrufsbutton-fuer-woocommerce' ),
				'labelOrder'    => __( 'Bestellung', 'widerrufsbutton-fuer-woocommerce' ),
				'labelEmail'    => __( 'E-Mail', 'widerrufsbutton-fuer-woocommerce' ),
				'labelScope'    => __( 'Umfang', 'widerrufsbutton-fuer-woocommerce' ),
				'scopeItem'     => __( 'Nur dieser Artikel', 'widerrufsbutton-fuer-woocommerce' ),
				'scopeOrder'    => __( 'Die gesamte Bestellung', 'widerrufsbutton-fuer-woocommerce' ),
			),
		);

		/*
		 * Bewusst wp_add_inline_script statt wp_localize_script: Letzteres
		 * castet alle Werte der obersten Ebene per (string). Aus
		 * isLoggedIn => false wuerde "" bzw. aus 0 ein "0" — und "0" ist in
		 * JavaScript truthy. Gaeste galten dadurch als eingeloggt.
		 */
		wp_add_inline_script(
			'wdbtn-frontend',
			'window.WDBTN = ' . wp_json_encode( $config ) . ';',
			'before'
		);
	}

	/**
	 * Baut die CSS-Variablen aus den Erscheinungsbild-Einstellungen.
	 *
	 * Die Stylesheet-Regeln greifen durchgängig auf Variablen zu, deshalb genügt
	 * es, hier deren Werte zu überschreiben – kein Duplizieren von Regeln, keine
	 * Spezifitätskämpfe.
	 *
	 * @return string CSS oder leerer String.
	 */
	public static function theme_css() {
		$vars = array();

		$accent = self::color( 'color_accent' );
		if ( $accent ) {
			$vars['--wdbtn-accent'] = $accent;

			// Hover-Farbe ableiten, wenn nichts gesetzt ist: Wer eine Akzentfarbe
			// waehlt, soll nicht zwingend eine zweite pflegen muessen.
			$hover = self::color( 'color_accent_hover' );
			$vars['--wdbtn-accent-hover'] = $hover ? $hover : self::darken( $accent, 18 );
		}

		$map = array(
			'color_on_accent'  => '--wdbtn-text-on-accent',
			'color_modal_bg'   => '--wdbtn-modal-bg',
			'color_modal_text' => '--wdbtn-modal-text',
		);
		foreach ( $map as $key => $var ) {
			$value = self::color( $key );
			if ( $value ) {
				$vars[ $var ] = $value;
			}
		}

		$radius = Settings::get( 'radius', '' );
		if ( '' !== $radius && null !== $radius ) {
			$vars['--wdbtn-radius'] = max( 0, min( 40, (int) $radius ) ) . 'px';
		}

		$css = '';
		if ( $vars ) {
			$decls = '';
			foreach ( $vars as $var => $value ) {
				$decls .= $var . ':' . $value . ';';
			}
			$css .= ':root{' . $decls . '}';
		}

		$font_size = Settings::get( 'font_size', '' );
		if ( '' !== $font_size && null !== $font_size ) {
			$css .= '.wdbtn-trigger{font-size:' . max( 10, min( 32, (int) $font_size ) ) . 'px;}';
		}

		$font = trim( (string) Settings::get( 'button_font', '' ) );
		if ( '' !== $font ) {
			// Nur als Schriftfamilie verwendbare Zeichen zulassen.
			$font = preg_replace( '/[^a-zA-Z0-9 ,\'"\-]/', '', $font );
			if ( '' !== $font ) {
				$css .= '.wdbtn-trigger,.wdbtn-modal{font-family:' . $font . ';}';
			}
		}

		$custom = trim( (string) Settings::get( 'custom_css', '' ) );
		if ( '' !== $custom ) {
			$css .= $custom;
		}

		return $css;
	}

	/**
	 * Liest eine Farb-Einstellung und prüft sie auf ein gültiges Hex-Format.
	 *
	 * @param string $key Einstellungs-Schlüssel.
	 * @return string Hex-Farbe oder leerer String.
	 */
	private static function color( $key ) {
		$value = sanitize_hex_color( (string) Settings::get( $key, '' ) );
		return $value ? $value : '';
	}

	/**
	 * Verdunkelt eine Hex-Farbe um einen Prozentsatz.
	 *
	 * @param string $hex     Hex-Farbe (#rgb oder #rrggbb).
	 * @param int    $percent Prozent.
	 * @return string
	 */
	private static function darken( $hex, $percent ) {
		$hex = ltrim( $hex, '#' );

		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		if ( 6 !== strlen( $hex ) ) {
			return '#' . $hex;
		}

		$factor = ( 100 - max( 0, min( 100, (int) $percent ) ) ) / 100;
		$out    = '#';

		foreach ( array( 0, 2, 4 ) as $offset ) {
			$channel = (int) round( hexdec( substr( $hex, $offset, 2 ) ) * $factor );
			$out    .= str_pad( dechex( max( 0, min( 255, $channel ) ) ), 2, '0', STR_PAD_LEFT );
		}

		return $out;
	}

	/**
	 * Ermittelt SKU/Produkt-ID der aktuellen Produktseite (für Vorbefüllung).
	 *
	 * @return array
	 */
	private function current_product_ref() {
		$ref = array(
			'sku'   => '',
			'id'    => 0,
			'label' => '',
		);

		if ( ! Settings::is_on( 'enable_product' ) ) {
			return $ref;
		}

		if ( function_exists( 'is_product' ) && is_product() ) {
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( get_the_ID() ) : null;
			if ( $product && ! Orders::is_product_excluded( $product->get_id() ) ) {
				$ref['sku'] = (string) $product->get_sku();
				$ref['id']  = (int) $product->get_id();
				// Anzeigename: Nicht jedes Produkt hat eine Artikelnummer, einen
				// Namen hat es immer — und Kunden erkennen ihn eher wieder.
				$ref['label'] = (string) $product->get_name();
			}
		}

		return $ref;
	}

	/**
	 * Footer-Ausgabe: Sticky-Button (falls aktiv) und Modal.
	 *
	 * @return void
	 */
	public function render_footer() {
		$settings   = Settings::all();
		$show_modal = ( 'yes' === $settings['enable_sitewide'] ) || self::$needs_modal;

		if ( 'yes' === $settings['enable_sitewide'] ) {
			$this->output_trigger( $settings, true );
		}

		if ( 'yes' === $settings['enable_footer_link'] ) {
			printf(
				'<div class="wdbtn-footer-link"><button type="button" class="wdbtn-trigger wdbtn-textlink" aria-haspopup="dialog" aria-controls="wdbtn-modal">%s</button></div>',
				esc_html( $settings['footer_link_text'] )
			);
			$show_modal = true;
		}

		if ( $show_modal ) {
			$this->output_modal( $settings );
		}
	}

	/**
	 * Shortcode [widerrufsbutton]: Inline-Trigger.
	 *
	 * @param array $atts Attribute.
	 * @return string
	 */
	public function shortcode( $atts ) {
		self::$needs_modal = true;

		$settings = Settings::all();

		ob_start();
		$this->output_trigger( $settings, false );
		return ob_get_clean();
	}

	/**
	 * Gibt einen Auslöser-Button aus.
	 *
	 * @param array $settings Einstellungen.
	 * @param bool  $sticky   Als fixierter Sticky-Button (true) oder inline (false).
	 * @return void
	 */
	private function output_trigger( $settings, $sticky ) {
		$position = sanitize_html_class( $settings['button_position'] );
		$classes  = $sticky ? 'wdbtn-sticky wdbtn-pos-' . $position : 'wdbtn-inline';
		$ref      = $this->current_product_ref();

		printf(
			'<button type="button" class="wdbtn-trigger %1$s" data-sku="%2$s" data-product-id="%3$d" aria-haspopup="dialog" aria-controls="wdbtn-modal">%4$s</button>',
			esc_attr( $classes ),
			esc_attr( $ref['sku'] ),
			(int) $ref['id'],
			esc_html( $settings['button_text'] )
		);
	}

	/**
	 * Gibt das Modal-Markup genau einmal aus.
	 *
	 * @param array $settings Einstellungen.
	 * @return void
	 */
	private function output_modal( $settings ) {
		if ( self::$modal_rendered ) {
			return;
		}
		self::$modal_rendered = true;

		// Im Template verfügbar: $settings.
		include WDBTN_PATH . 'templates/modal.php';
	}
}
