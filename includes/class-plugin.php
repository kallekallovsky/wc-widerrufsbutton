<?php
/**
 * Zentrale Plugin-Klasse (Container/Bootstrap).
 *
 * @package Widerrufsbutton
 */

namespace Widerrufsbutton;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lädt und verbindet alle Plugin-Komponenten.
 */
final class Plugin {

	/**
	 * Singleton-Instanz.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Notifier-Erweiterungsstelle (Phase 2). Im MVP No-op.
	 *
	 * @var Notifier|null
	 */
	private $notifier = null;

	/**
	 * Liefert die Singleton-Instanz.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Konstruktor (privat, Singleton).
	 */
	private function __construct() {}

	/**
	 * Initialisiert Hooks und Komponenten.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'init', array( $this, 'load_textdomain' ) );

		// Phase-2-Erweiterungsstelle: austauschbar per Filter, Default = No-op.
		$this->notifier = apply_filters( 'wdbtn_notifier', new Null_Notifier() );

		// Komponenten laden.
		new Frontend();
		new Ajax();

		/**
		 * Einstiegspunkt für weitere Komponenten
		 * (E-Mails, Bestelllogik, Admin, Settings).
		 */
		do_action( 'wdbtn_init', $this );
	}

	/**
	 * Lädt die Übersetzungsdateien.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'widerrufsbutton-fuer-woocommerce',
			false,
			dirname( WDBTN_BASENAME ) . '/languages'
		);
	}

	/**
	 * Zugriff auf den aktuellen Notifier.
	 *
	 * @return Notifier
	 */
	public function notifier() {
		return $this->notifier;
	}

	/**
	 * Klonen unterbinden.
	 *
	 * @return void
	 */
	private function __clone() {}

	/**
	 * Deserialisierung unterbinden.
	 *
	 * @return void
	 */
	public function __wakeup() {
		throw new \Exception( 'Unserialisierung von ' . __CLASS__ . ' ist nicht erlaubt.' );
	}
}
