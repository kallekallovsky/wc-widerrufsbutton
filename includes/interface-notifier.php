<?php
/**
 * Erweiterungsstelle für die Rückkommunikation an externe Systeme (Phase 2).
 *
 * @package Widerrufsbutton
 */

namespace Widerrufsbutton;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Vertrag für Notifier-Implementierungen.
 *
 * Im MVP wird ausschließlich die No-op-Implementierung {@see Null_Notifier}
 * verwendet. Eine spätere Billbee-Anbindung (Phase 2) kann diese Schnittstelle
 * implementieren und per Filter "wdbtn_notifier" einhängen.
 */
interface Notifier {

	/**
	 * Meldet einen eingegangenen Widerruf an ein externes System.
	 *
	 * @param array $widerruf Datensatz des Widerrufs.
	 * @return bool True bei Erfolg.
	 */
	public function notify( array $widerruf );
}
