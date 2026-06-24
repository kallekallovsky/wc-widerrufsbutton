<?php
/**
 * Einstellungsseite (WordPress Settings API).
 *
 * @package Widerrufsbutton
 */

namespace Widerrufsbutton;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin-Oberfläche zur Konfiguration des Plugins.
 */
class Settings_Page {

	const MENU_SLUG = 'wdbtn-settings';
	const GROUP     = 'wdbtn_settings_group';
	const CAP       = 'manage_woocommerce';

	/**
	 * Registriert Hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'register' ) );
	}

	/**
	 * Menüeintrag unter WooCommerce.
	 *
	 * @return void
	 */
	public function menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Widerruf-Einstellungen', 'widerrufsbutton-fuer-woocommerce' ),
			__( 'Widerruf-Einstellungen', 'widerrufsbutton-fuer-woocommerce' ),
			self::CAP,
			self::MENU_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Registriert die Option samt Sanitize-Callback.
	 *
	 * @return void
	 */
	public function register() {
		register_setting(
			self::GROUP,
			Install::OPT_SETTINGS,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => Install::default_settings(),
			)
		);
	}

	/**
	 * Bereinigt die eingereichten Einstellungen.
	 *
	 * @param mixed $input Rohdaten.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$d   = Install::default_settings();
		$in  = is_array( $input ) ? $input : array();
		$out = $d;

		$out['button_text']      = isset( $in['button_text'] ) ? sanitize_text_field( $in['button_text'] ) : $d['button_text'];
		$out['footer_link_text'] = isset( $in['footer_link_text'] ) ? sanitize_text_field( $in['footer_link_text'] ) : $d['footer_link_text'];

		$pos                    = isset( $in['button_position'] ) ? sanitize_key( $in['button_position'] ) : '';
		$out['button_position'] = in_array( $pos, array( 'bottom-right', 'bottom-left', 'bottom-center' ), true ) ? $pos : $d['button_position'];

		foreach ( array( 'enable_sitewide', 'enable_product', 'enable_dashboard', 'enable_footer_link', 'add_order_note', 'guest_verification', 'rejection_email', 'delete_on_uninstall' ) as $cb ) {
			$out[ $cb ] = ( isset( $in[ $cb ] ) && 'yes' === $in[ $cb ] ) ? 'yes' : 'no';
		}

		$recips = isset( $in['admin_recipients'] ) ? (string) $in['admin_recipients'] : '';
		$valid  = array();
		foreach ( array_map( 'trim', explode( ',', $recips ) ) as $mail ) {
			if ( '' !== $mail && is_email( $mail ) ) {
				$valid[] = sanitize_email( $mail );
			}
		}
		$out['admin_recipients'] = $valid ? implode( ',', $valid ) : get_option( 'admin_email' );

		$out['withdrawal_days'] = isset( $in['withdrawal_days'] ) ? max( 0, (int) $in['withdrawal_days'] ) : $d['withdrawal_days'];

		$basis              = isset( $in['date_basis'] ) ? sanitize_key( $in['date_basis'] ) : '';
		$out['date_basis']  = in_array( $basis, array( 'order_date', 'completed_date' ), true ) ? $basis : $d['date_basis'];

		$allowed_types               = array( 'virtual', 'downloadable', 'grouped', 'external' );
		$types                       = isset( $in['excluded_product_types'] ) ? (array) $in['excluded_product_types'] : array();
		$out['excluded_product_types'] = array_values( array_intersect( $allowed_types, array_map( 'sanitize_key', $types ) ) );

		$cats                       = isset( $in['excluded_categories'] ) ? (array) $in['excluded_categories'] : array();
		$out['excluded_categories'] = array_values( array_unique( array_filter( array_map( 'absint', $cats ) ) ) );

		$prods_raw                = isset( $in['excluded_products'] ) ? (string) $in['excluded_products'] : '';
		$prod_ids                 = array_filter( array_map( 'absint', preg_split( '/[\s,]+/', $prods_raw ) ) );
		$out['excluded_products'] = array_values( array_unique( $prod_ids ) );

		return $out;
	}

	/**
	 * Rendert die Einstellungsseite.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Sie haben keine Berechtigung für diese Seite.', 'widerrufsbutton-fuer-woocommerce' ) );
		}

		$s          = Settings::all();
		$name       = Install::OPT_SETTINGS;
		$positions  = array(
			'bottom-right'  => __( 'Unten rechts', 'widerrufsbutton-fuer-woocommerce' ),
			'bottom-left'   => __( 'Unten links', 'widerrufsbutton-fuer-woocommerce' ),
			'bottom-center' => __( 'Unten mittig', 'widerrufsbutton-fuer-woocommerce' ),
		);
		$bases      = array(
			'order_date'     => __( 'Bestelldatum', 'widerrufsbutton-fuer-woocommerce' ),
			'completed_date' => __( 'Abschluss-/Lieferdatum (mit Rückfall auf Bestelldatum)', 'widerrufsbutton-fuer-woocommerce' ),
		);
		$types      = array(
			'virtual'      => __( 'Virtuelle Produkte', 'widerrufsbutton-fuer-woocommerce' ),
			'downloadable' => __( 'Herunterladbare Produkte', 'widerrufsbutton-fuer-woocommerce' ),
			'grouped'      => __( 'Gruppierte Produkte', 'widerrufsbutton-fuer-woocommerce' ),
			'external'     => __( 'Externe/Affiliate-Produkte', 'widerrufsbutton-fuer-woocommerce' ),
		);
		$categories = function_exists( 'get_terms' ) ? get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		) : array();
		$excl_types = (array) $s['excluded_product_types'];
		$excl_cats  = array_map( 'intval', (array) $s['excluded_categories'] );
		$excl_prods = implode( ', ', (array) $s['excluded_products'] );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Widerruf-Einstellungen', 'widerrufsbutton-fuer-woocommerce' ); ?></h1>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>

				<h2><?php esc_html_e( 'Anzeige', 'widerrufsbutton-fuer-woocommerce' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Button-Text', 'widerrufsbutton-fuer-woocommerce' ); ?></th>
						<td><input type="text" class="regular-text" name="<?php echo esc_attr( $name ); ?>[button_text]" value="<?php echo esc_attr( $s['button_text'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Position', 'widerrufsbutton-fuer-woocommerce' ); ?></th>
						<td>
							<select name="<?php echo esc_attr( $name ); ?>[button_position]">
								<?php foreach ( $positions as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $s['button_position'], $key ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Sichtbarkeit', 'widerrufsbutton-fuer-woocommerce' ); ?></th>
						<td>
							<?php
							$this->checkbox( $name, 'enable_sitewide', $s, __( 'Sticky-Button auf allen Shop-Seiten anzeigen', 'widerrufsbutton-fuer-woocommerce' ) );
							$this->checkbox( $name, 'enable_product', $s, __( 'Auf Produktseiten Artikelnummer vorausfüllen', 'widerrufsbutton-fuer-woocommerce' ) );
							$this->checkbox( $name, 'enable_dashboard', $s, __( 'Im Kundenkonto („Bestellungen") einen Widerrufen-Button anzeigen', 'widerrufsbutton-fuer-woocommerce' ) );
							$this->checkbox( $name, 'enable_footer_link', $s, __( 'Zusätzlichen Textlink im Footer anzeigen', 'widerrufsbutton-fuer-woocommerce' ) );
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Footer-Link-Text', 'widerrufsbutton-fuer-woocommerce' ); ?></th>
						<td><input type="text" class="regular-text" name="<?php echo esc_attr( $name ); ?>[footer_link_text]" value="<?php echo esc_attr( $s['footer_link_text'] ); ?>"></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Ablauf & Benachrichtigung', 'widerrufsbutton-fuer-woocommerce' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Optionen', 'widerrufsbutton-fuer-woocommerce' ); ?></th>
						<td>
							<?php
							$this->checkbox( $name, 'guest_verification', $s, __( 'Gast-Widerrufe per E-Mail-Link bestätigen lassen (Anti-Missbrauch)', 'widerrufsbutton-fuer-woocommerce' ) );
							$this->checkbox( $name, 'add_order_note', $s, __( 'Additive Bestellnotiz an die WooCommerce-Bestellung anhängen (kein Statuswechsel)', 'widerrufsbutton-fuer-woocommerce' ) );
							$this->checkbox( $name, 'rejection_email', $s, __( 'Bei Ablehnung eine Mitteilung an die Kund:in senden', 'widerrufsbutton-fuer-woocommerce' ) );
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Empfänger Betreiber-Mail', 'widerrufsbutton-fuer-woocommerce' ); ?></th>
						<td>
							<input type="text" class="regular-text" name="<?php echo esc_attr( $name ); ?>[admin_recipients]" value="<?php echo esc_attr( $s['admin_recipients'] ); ?>">
							<p class="description"><?php esc_html_e( 'Mehrere Adressen mit Komma trennen. Betreff und Texte der E-Mails passen Sie unter WooCommerce → Einstellungen → E-Mails an.', 'widerrufsbutton-fuer-woocommerce' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Fristlogik', 'widerrufsbutton-fuer-woocommerce' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Widerrufsfrist (Tage)', 'widerrufsbutton-fuer-woocommerce' ); ?></th>
						<td>
							<input type="number" min="0" name="<?php echo esc_attr( $name ); ?>[withdrawal_days]" value="<?php echo esc_attr( $s['withdrawal_days'] ); ?>">
							<p class="description"><?php esc_html_e( '0 = keine Vorauswahl-Begrenzung. Das Plugin blockiert Widerrufe nicht hart.', 'widerrufsbutton-fuer-woocommerce' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Berechnungsbasis', 'widerrufsbutton-fuer-woocommerce' ); ?></th>
						<td>
							<select name="<?php echo esc_attr( $name ); ?>[date_basis]">
								<?php foreach ( $bases as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $s['date_basis'], $key ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Bewusst datumsbasiert – nicht anhand des WooCommerce-Status (Warenwirtschaft kann den Status umschreiben).', 'widerrufsbutton-fuer-woocommerce' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Produkt-Ausschlüsse', 'widerrufsbutton-fuer-woocommerce' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Nach Produkttyp', 'widerrufsbutton-fuer-woocommerce' ); ?></th>
						<td>
							<?php foreach ( $types as $key => $label ) : ?>
								<label style="display:block;margin-bottom:4px;">
									<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[excluded_product_types][]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $excl_types, true ) ); ?>>
									<?php echo esc_html( $label ); ?>
								</label>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Nach Kategorie', 'widerrufsbutton-fuer-woocommerce' ); ?></th>
						<td>
							<?php if ( ! empty( $categories ) && ! is_wp_error( $categories ) ) : ?>
								<select multiple size="6" name="<?php echo esc_attr( $name ); ?>[excluded_categories][]" style="min-width:280px;">
									<?php foreach ( $categories as $cat ) : ?>
										<option value="<?php echo esc_attr( $cat->term_id ); ?>" <?php selected( in_array( (int) $cat->term_id, $excl_cats, true ) ); ?>><?php echo esc_html( $cat->name ); ?></option>
									<?php endforeach; ?>
								</select>
							<?php else : ?>
								<em><?php esc_html_e( 'Keine Produktkategorien vorhanden.', 'widerrufsbutton-fuer-woocommerce' ); ?></em>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Einzelne Produkte (IDs)', 'widerrufsbutton-fuer-woocommerce' ); ?></th>
						<td>
							<input type="text" class="regular-text" name="<?php echo esc_attr( $name ); ?>[excluded_products]" value="<?php echo esc_attr( $excl_prods ); ?>">
							<p class="description"><?php esc_html_e( 'Produkt-IDs mit Komma getrennt.', 'widerrufsbutton-fuer-woocommerce' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Datenschutz', 'widerrufsbutton-fuer-woocommerce' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Deinstallation', 'widerrufsbutton-fuer-woocommerce' ); ?></th>
						<td><?php $this->checkbox( $name, 'delete_on_uninstall', $s, __( 'Beim Löschen des Plugins alle Daten (Tabellen + Einstellungen) entfernen', 'widerrufsbutton-fuer-woocommerce' ) ); ?></td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Hilfsausgabe einer Ja/Nein-Checkbox.
	 *
	 * @param string $name  Options-Name.
	 * @param string $key   Schlüssel.
	 * @param array  $s     Aktuelle Werte.
	 * @param string $label Beschriftung.
	 * @return void
	 */
	private function checkbox( $name, $key, $s, $label ) {
		printf(
			'<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="%1$s[%2$s]" value="yes" %3$s> %4$s</label>',
			esc_attr( $name ),
			esc_attr( $key ),
			checked( isset( $s[ $key ] ) && 'yes' === $s[ $key ], true, false ),
			esc_html( $label )
		);
	}
}
