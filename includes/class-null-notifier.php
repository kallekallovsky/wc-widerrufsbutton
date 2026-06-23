<?php
/**
 * No-op-Notifier (Standard im MVP).
 *
 * @package Widerrufsbutton
 */

namespace Widerrufsbutton;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tut bewusst nichts. Platzhalter, bis eine echte Anbindung (Phase 2) existiert.
 */
final class Null_Notifier implements Notifier {

	/**
	 * {@inheritDoc}
	 *
	 * @param array $widerruf Datensatz des Widerrufs.
	 * @return bool
	 */
	public function notify( array $widerruf ) {
		return true;
	}
}
