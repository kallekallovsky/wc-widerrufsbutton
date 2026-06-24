<?php
/**
 * Installation: DB-Tabellen, Standard-Einstellungen, Upgrades.
 *
 * @package Widerrufsbutton
 */

namespace Widerrufsbutton;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Legt Tabellen an und verwaltet die DB-Version.
 */
class Install {

	const OPT_DB_VERSION = 'wdbtn_db_version';
	const OPT_SETTINGS   = 'wdbtn_settings';

	/**
	 * Aktivierungs-Routine.
	 *
	 * @return void
	 */
	public static function activate() {
		self::create_tables();
		self::seed_default_settings();
		update_option( self::OPT_DB_VERSION, WDBTN_DB_VERSION );
	}

	/**
	 * Deaktivierung – bewusst ohne Datenlöschung (siehe uninstall.php).
	 *
	 * @return void
	 */
	public static function deactivate() {}

	/**
	 * Führt bei abweichender DB-Version ein Upgrade durch.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::OPT_DB_VERSION ) !== WDBTN_DB_VERSION ) {
			self::create_tables();
			self::seed_default_settings();
			update_option( self::OPT_DB_VERSION, WDBTN_DB_VERSION );
		}
	}

	/**
	 * Tabellenname der Widerrufe.
	 *
	 * @return string
	 */
	public static function table_withdrawals() {
		global $wpdb;
		return $wpdb->prefix . 'wc_widerrufe';
	}

	/**
	 * Tabellenname des Aktivitäts-Logs.
	 *
	 * @return string
	 */
	public static function table_log() {
		global $wpdb;
		return $wpdb->prefix . 'wc_widerrufe_log';
	}

	/**
	 * Erstellt/aktualisiert die Tabellen via dbDelta().
	 *
	 * @return void
	 */
	private static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$withdrawals     = self::table_withdrawals();
		$log             = self::table_log();

		$sql_withdrawals = "CREATE TABLE {$withdrawals} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  order_id bigint(20) unsigned DEFAULT NULL,
  order_number varchar(100) NOT NULL DEFAULT '',
  product_id bigint(20) unsigned DEFAULT NULL,
  sku varchar(100) DEFAULT NULL,
  scope varchar(20) NOT NULL DEFAULT 'order',
  customer_user_id bigint(20) unsigned DEFAULT NULL,
  name varchar(255) NOT NULL DEFAULT '',
  email varchar(255) NOT NULL DEFAULT '',
  reason text,
  status varchar(30) NOT NULL DEFAULT 'eingegangen',
  verification_status varchar(20) NOT NULL DEFAULT 'verified',
  verification_token varchar(64) DEFAULT NULL,
  token_expires_at datetime DEFAULT NULL,
  confirmation_sent tinyint(1) NOT NULL DEFAULT 0,
  ip_hash varchar(64) DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY order_id (order_id),
  KEY email (email),
  KEY status (status),
  KEY created_at (created_at),
  KEY verification_token (verification_token)
) {$charset_collate};";

		$sql_log = "CREATE TABLE {$log} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  widerruf_id bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL,
  actor varchar(100) NOT NULL DEFAULT '',
  action varchar(100) NOT NULL DEFAULT '',
  note text,
  PRIMARY KEY  (id),
  KEY widerruf_id (widerruf_id)
) {$charset_collate};";

		dbDelta( $sql_withdrawals );
		dbDelta( $sql_log );
	}

	/**
	 * Legt Standard-Einstellungen an (nur, falls noch nicht vorhanden).
	 *
	 * @return void
	 */
	private static function seed_default_settings() {
		if ( false === get_option( self::OPT_SETTINGS ) ) {
			add_option( self::OPT_SETTINGS, self::default_settings() );
		}
	}

	/**
	 * Standard-Einstellungen.
	 *
	 * @return array
	 */
	public static function default_settings() {
		return array(
			'button_text'         => __( 'Vertrag widerrufen', 'widerrufsbutton-fuer-woocommerce' ),
			'button_position'     => 'bottom-right',
			'enable_sitewide'     => 'yes',
			'enable_product'      => 'yes',
			'enable_dashboard'    => 'yes',
			'enable_footer_link'  => 'no',
			'add_order_note'      => 'yes',
			'guest_verification'  => 'yes',
			'rejection_email'     => 'no',
			'admin_recipients'    => get_option( 'admin_email' ),
			'withdrawal_days'        => 14,
			'date_basis'             => 'order_date',
			'excluded_product_types' => array(),
			'excluded_categories'    => array(),
			'excluded_products'      => array(),
			'delete_on_uninstall'    => 'no',
		);
	}
}
