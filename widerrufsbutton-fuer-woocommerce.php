<?php
/**
 * Plugin Name:          Widerrufsbutton für WooCommerce
 * Plugin URI:           https://github.com/kallekallovsky/wc-widerrufsbutton
 * Update URI:           https://github.com/kallekallovsky/wc-widerrufsbutton
 * Description:          Rechtskonforme digitale Widerrufsfunktion (§ 356a BGB / EU-Richtlinie 2023/2673) für WooCommerce: gut sichtbarer, loginfreier Widerrufsbutton mit zweistufiger Bestätigung und automatischer Eingangsbestätigung.
 * Version:              0.1.2
 * Requires at least:    6.0
 * Requires PHP:         7.4
 * Author:               Kallovsky
 * Text Domain:          widerrufsbutton-fuer-woocommerce
 * Domain Path:          /languages
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * WC requires at least: 6.0
 * WC tested up to:      9.0
 *
 * @package Widerrufsbutton
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WDBTN_VERSION', '0.1.2' );
define( 'WDBTN_DB_VERSION', '2' );
define( 'WDBTN_FILE', __FILE__ );
define( 'WDBTN_PATH', plugin_dir_path( __FILE__ ) );
define( 'WDBTN_URL', plugin_dir_url( __FILE__ ) );
define( 'WDBTN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Einfacher Autoloader für den Namespace "Widerrufsbutton".
 *
 * Klassen   -> includes/class-*.php
 * Interfaces-> includes/interface-*.php
 * Traits    -> includes/trait-*.php
 */
spl_autoload_register(
	function ( $class ) {
		$prefix = 'Widerrufsbutton\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$relative = substr( $class, strlen( $prefix ) );
		$relative = str_replace( '_', '-', strtolower( $relative ) );
		foreach ( array( 'class-', 'interface-', 'trait-' ) as $type ) {
			$file = WDBTN_PATH . 'includes/' . $type . $relative . '.php';
			if ( is_readable( $file ) ) {
				require_once $file;
				return;
			}
		}
	}
);

/**
 * Update-Prüfung gegen die GitHub-Releases des Projekts.
 *
 * Läuft unabhängig von WooCommerce, damit Updates auch dann noch
 * ausgeliefert werden, wenn das Plugin sich mangels WooCommerce
 * selbst deaktiviert hat.
 *
 * @return void
 */
function wdbtn_init_updater() {
	$loader = WDBTN_PATH . 'lib/plugin-update-checker/plugin-update-checker.php';

	if ( ! is_readable( $loader ) ) {
		return;
	}

	require_once $loader;

	if ( ! class_exists( '\YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
		return;
	}

	$checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/kallekallovsky/wc-widerrufsbutton/',
		WDBTN_FILE,
		'widerrufsbutton-fuer-woocommerce'
	);

	// Nur veröffentlichte Releases berücksichtigen, keine losen Tags.
	$checker->getVcsApi()->enableReleaseAssets();

	/**
	 * Optionaler GitHub-Token, falls das Repository (wieder) privat wird.
	 * In der wp-config.php setzen: define( 'WDBTN_GITHUB_TOKEN', '...' );
	 */
	if ( defined( 'WDBTN_GITHUB_TOKEN' ) && WDBTN_GITHUB_TOKEN ) {
		$checker->setAuthentication( WDBTN_GITHUB_TOKEN );
	}
}

wdbtn_init_updater();

// Aktivierung / Deaktivierung.
register_activation_hook( __FILE__, array( '\Widerrufsbutton\Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\Widerrufsbutton\Install', 'deactivate' ) );

/**
 * HPOS-Kompatibilität (High-Performance Order Storage) deklarieren.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

/**
 * Bootstrap nach dem Laden aller Plugins.
 */
add_action( 'plugins_loaded', 'wdbtn_bootstrap' );

/**
 * Startet das Plugin, sofern WooCommerce aktiv ist.
 *
 * @return void
 */
function wdbtn_bootstrap() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'wdbtn_notice_missing_wc' );
		return;
	}

	\Widerrufsbutton\Install::maybe_upgrade();
	\Widerrufsbutton\Plugin::instance()->init();
}

/**
 * Admin-Hinweis, wenn WooCommerce fehlt.
 *
 * @return void
 */
function wdbtn_notice_missing_wc() {
	echo '<div class="notice notice-error"><p>';
	echo esc_html__( 'Widerrufsbutton für WooCommerce benötigt ein aktives WooCommerce.', 'widerrufsbutton-fuer-woocommerce' );
	echo '</p></div>';
}
