<?php
/**
 * Deinstallation: optionale Datenlöschung (DSGVO-Schalter).
 *
 * @package Widerrufsbutton
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$wdbtn_settings = get_option( 'wdbtn_settings' );
$wdbtn_delete   = is_array( $wdbtn_settings )
	&& isset( $wdbtn_settings['delete_on_uninstall'] )
	&& 'yes' === $wdbtn_settings['delete_on_uninstall'];

if ( ! $wdbtn_delete ) {
	return;
}

global $wpdb;

$wdbtn_tables = array(
	$wpdb->prefix . 'wc_widerrufe',
	$wpdb->prefix . 'wc_widerrufe_log',
);

foreach ( $wdbtn_tables as $wdbtn_table ) {
	// Tabellenname stammt aus $wpdb->prefix (vertrauenswürdig) und kann nicht
	// als Platzhalter geprepared werden.
	$wpdb->query( "DROP TABLE IF EXISTS `{$wdbtn_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
}

delete_option( 'wdbtn_settings' );
delete_option( 'wdbtn_db_version' );
