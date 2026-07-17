<?php
/**
 * Deinstallation: optionale Datenlöschung (DSGVO-Schalter).
 *
 * @package Widerrufsbutton
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Entfernt Tabellen und Optionen der aktuell aktiven Seite.
 *
 * Löscht nur, wenn der Betreiber das ausdrücklich eingestellt hat – die
 * Datensätze belegen den Zugang von Widerrufserklärungen.
 *
 * @return void
 */
function wdbtn_uninstall_site() {
	global $wpdb;

	$settings = get_option( 'wdbtn_settings' );
	$delete   = is_array( $settings )
		&& isset( $settings['delete_on_uninstall'] )
		&& 'yes' === $settings['delete_on_uninstall'];

	if ( ! $delete ) {
		return;
	}

	$tables = array(
		$wpdb->prefix . 'wc_widerrufe',
		$wpdb->prefix . 'wc_widerrufe_log',
	);

	foreach ( $tables as $table ) {
		// Tabellenname stammt aus $wpdb->prefix (vertrauenswürdig) und kann nicht
		// als Platzhalter geprepared werden.
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	$timestamp = wp_next_scheduled( 'wdbtn_daily_maintenance' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'wdbtn_daily_maintenance' );
	}

	delete_option( 'wdbtn_settings' );
	delete_option( 'wdbtn_db_version' );
}

/*
 * Im Netzwerkbetrieb jede Seite einzeln abräumen: $wpdb->prefix zeigt sonst
 * nur auf die Hauptseite, und die Tabellen der Unterseiten (wp_2_wc_widerrufe
 * und so fort) blieben mitsamt personenbezogener Daten stehen – obwohl der
 * Betreiber die Löschung ausdrücklich gewählt hat. Die Einstellung wird dabei
 * pro Seite gelesen, denn sie ist auch pro Seite gesetzt.
 */
if ( is_multisite() ) {
	$wdbtn_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $wdbtn_site_ids as $wdbtn_site_id ) {
		switch_to_blog( $wdbtn_site_id );
		wdbtn_uninstall_site();
		restore_current_blog();
	}
} else {
	wdbtn_uninstall_site();
}
