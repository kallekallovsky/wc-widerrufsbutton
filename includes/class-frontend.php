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

		wp_enqueue_script(
			'wdbtn-frontend',
			WDBTN_URL . 'assets/js/widerrufsbutton.js',
			array(),
			WDBTN_VERSION,
			true
		);

		wp_localize_script(
			'wdbtn-frontend',
			'WDBTN',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'action'     => 'wdbtn_submit',
				'nonce'      => wp_create_nonce( 'wdbtn_submit' ),
				'isLoggedIn' => is_user_logged_in() ? 1 : 0,
				'prefillSku' => $this->current_product_ref(),
				'i18n'       => array(
					'genericError'  => __( 'Es ist ein Fehler aufgetreten. Bitte versuchen Sie es erneut.', 'widerrufsbutton-fuer-woocommerce' ),
					'notReady'      => __( 'Das Absenden ist noch nicht verfügbar.', 'widerrufsbutton-fuer-woocommerce' ),
					'fillRequired'  => __( 'Bitte füllen Sie die Pflichtfelder aus.', 'widerrufsbutton-fuer-woocommerce' ),
					'invalidEmail'  => __( 'Bitte geben Sie eine gültige E-Mail-Adresse an.', 'widerrufsbutton-fuer-woocommerce' ),
					'sending'       => __( 'Wird gesendet …', 'widerrufsbutton-fuer-woocommerce' ),
				),
			)
		);
	}

	/**
	 * Ermittelt SKU/Produkt-ID der aktuellen Produktseite (für Vorbefüllung).
	 *
	 * @return array
	 */
	private function current_product_ref() {
		$ref = array(
			'sku' => '',
			'id'  => 0,
		);

		if ( ! Settings::is_on( 'enable_product' ) ) {
			return $ref;
		}

		if ( function_exists( 'is_product' ) && is_product() ) {
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( get_the_ID() ) : null;
			if ( $product ) {
				$ref['sku'] = (string) $product->get_sku();
				$ref['id']  = (int) $product->get_id();
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
		$settings = Settings::all();

		if ( 'yes' === $settings['enable_sitewide'] ) {
			$this->output_trigger( $settings, true );
		}

		if ( 'yes' === $settings['enable_sitewide'] || self::$needs_modal ) {
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
