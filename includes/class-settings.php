<?php
/**
 * Einstellungs-Zugriff (Lese-API).
 *
 * Die Admin-Oberfläche folgt in Phase 6; diese Klasse liefert bereits jetzt
 * die mit Standardwerten gemischten Einstellungen.
 *
 * @package Widerrufsbutton
 */

namespace Widerrufsbutton;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Zentraler, schreibgeschützter Zugriff auf die Plugin-Einstellungen.
 */
class Settings {

	/**
	 * Liefert alle Einstellungen (gespeicherte Werte über Standardwerte gelegt).
	 *
	 * @return array
	 */
	public static function all() {
		$defaults = Install::default_settings();
		$saved    = get_option( Install::OPT_SETTINGS, array() );

		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		return wp_parse_args( $saved, $defaults );
	}

	/**
	 * Liefert einen einzelnen Einstellungswert.
	 *
	 * @param string $key      Schlüssel.
	 * @param mixed  $fallback Rückgabewert, falls nicht gesetzt.
	 * @return mixed
	 */
	public static function get( $key, $fallback = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	/**
	 * Bequemer Boolean-Check für "yes"/"no"-Schalter.
	 *
	 * @param string $key Schlüssel.
	 * @return bool
	 */
	public static function is_on( $key ) {
		return 'yes' === self::get( $key );
	}
}
