<?php
/**
 * Geplante Aufräumarbeiten (Cron).
 *
 * @package Widerrufsbutton
 */

namespace Widerrufsbutton;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Räumt abgelaufene Verifizierungen und – falls konfiguriert – alte
 * Datensätze weg.
 */
final class Maintenance {

	/**
	 * Name des Cron-Hooks.
	 */
	const CRON_HOOK = 'wdbtn_daily_maintenance';

	/**
	 * Registriert die Hooks.
	 */
	public function __construct() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run' ) );
	}

	/**
	 * Plant den täglichen Lauf ein (bei Aktivierung).
	 *
	 * @return void
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Entfernt den geplanten Lauf (bei Deaktivierung).
	 *
	 * @return void
	 */
	public static function unschedule() {
		$next = wp_next_scheduled( self::CRON_HOOK );
		if ( $next ) {
			wp_unschedule_event( $next, self::CRON_HOOK );
		}
	}

	/**
	 * Führt die Aufräumarbeiten aus.
	 *
	 * @return void
	 */
	public static function run() {
		self::clear_expired_tokens();
		self::purge_by_retention();
	}

	/**
	 * Entfernt abgelaufene Bestätigungs-Token, ohne den Datensatz zu löschen.
	 *
	 * Seit der Entkopplung ist ein unbestätigter Widerruf ein vollständig
	 * wirksamer Widerruf – er darf niemals automatisch gelöscht werden, sonst
	 * ginge der Nachweis einer gültigen Erklärung verloren. Aufgeräumt wird nur
	 * das tote Token, damit der Link nach Ablauf nicht mehr greift; der Eintrag
	 * bleibt und behält seinen Status "unconfirmed".
	 *
	 * @return int Anzahl bereinigter Zeilen.
	 */
	public static function clear_expired_tokens() {
		global $wpdb;

		$table = Install::table_withdrawals();
		$now   = gmdate( 'Y-m-d H:i:s', time() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET verification_token = NULL, token_expires_at = NULL WHERE verification_status = 'unconfirmed' AND token_expires_at IS NOT NULL AND token_expires_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$now
			)
		);
	}

	/**
	 * Löscht Datensätze, die älter als die konfigurierte Aufbewahrungsfrist sind.
	 *
	 * Standard ist 0 (= keine automatische Löschung), weil die Datensätze den
	 * Zugang der Widerrufserklärung belegen. Wer eine Frist setzt, sollte sie
	 * an seinen Aufbewahrungspflichten ausrichten.
	 *
	 * @return int Anzahl gelöschter Zeilen.
	 */
	public static function purge_by_retention() {
		global $wpdb;

		$days = (int) Settings::get( 'retention_days', 0 );
		if ( $days <= 0 ) {
			return 0;
		}

		$table  = Install::table_withdrawals();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		// Gegen created_at_gmt vergleichen: created_at ist Ortszeit und nach
		// einer Zeitzonenumstellung nicht mehr eindeutig.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE created_at_gmt IS NOT NULL AND created_at_gmt < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$cutoff
			)
		);

		return self::delete_ids( $ids );
	}

	/**
	 * Löscht Datensätze samt zugehörigem Log.
	 *
	 * @param array $ids Datensatz-IDs.
	 * @return int Anzahl gelöschter Zeilen.
	 */
	private static function delete_ids( $ids ) {
		global $wpdb;

		$ids = array_filter( array_map( 'intval', (array) $ids ) );
		if ( ! $ids ) {
			return 0;
		}

		$table = Install::table_withdrawals();
		$log   = Install::table_log();
		$ph    = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$log} WHERE widerruf_id IN ($ph)", $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ($ph)", $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return (int) $deleted;
	}
}
