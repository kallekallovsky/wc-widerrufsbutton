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
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );

		/*
		 * Ohne diesen Filter prüft options.php beim Speichern gegen den
		 * Standard manage_options, während das Menü mit manage_woocommerce
		 * sichtbar ist: Shop-Manager sahen das Formular, bekamen beim Speichern
		 * aber "keine Berechtigung". Hier wird beides angeglichen.
		 */
		add_filter( 'option_page_capability_' . self::GROUP, array( $this, 'settings_capability' ) );
	}

	/**
	 * Capability, die zum Speichern der Einstellungen berechtigt.
	 *
	 * @return string
	 */
	public function settings_capability() {
		return self::CAP;
	}

	/**
	 * Lädt den WordPress-Farbwähler – nur auf dieser Seite.
	 *
	 * @param string $hook Aktueller Admin-Hook.
	 * @return void
	 */
	public function enqueue( $hook ) {
		// Der Hook endet auf dem Menü-Slug; ein Vergleich darauf haelt die
		// Assets von allen anderen Admin-Seiten fern.
		if ( false === strpos( (string) $hook, self::MENU_SLUG ) ) {
			return;
		}

		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
		wp_add_inline_script(
			'wp-color-picker',
			'jQuery(function($){ $(".wdbtn-color").wpColorPicker(); });',
			'after'
		);
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

		foreach ( array( 'enable_sitewide', 'enable_product', 'enable_dashboard', 'enable_footer_link', 'add_order_note', 'guest_verification', 'rejection_email', 'accept_unmatched', 'delete_on_uninstall' ) as $cb ) {
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

		// Erscheinungsbild.
		foreach ( array( 'color_accent', 'color_accent_hover', 'color_on_accent', 'color_modal_bg', 'color_modal_text' ) as $ck ) {
			if ( ! isset( $in[ $ck ] ) || '' === trim( (string) $in[ $ck ] ) ) {
				// Leer ist erlaubt und bedeutet "Voreinstellung beibehalten".
				$out[ $ck ] = '';
				continue;
			}
			$color      = sanitize_hex_color( trim( (string) $in[ $ck ] ) );
			$out[ $ck ] = $color ? $color : $d[ $ck ];
		}

		$out['radius']    = isset( $in['radius'] ) ? max( 0, min( 40, (int) $in['radius'] ) ) : $d['radius'];
		$out['font_size'] = isset( $in['font_size'] ) ? max( 10, min( 32, (int) $in['font_size'] ) ) : $d['font_size'];

		// Nur Zeichen zulassen, die in einer font-family vorkommen – so kann das
		// Feld die Deklaration nicht verlassen und weitere Regeln anhaengen.
		$font                = isset( $in['button_font'] ) ? (string) $in['button_font'] : '';
		$out['button_font']  = trim( preg_replace( '/[^a-zA-Z0-9 ,\'"\-]/', '', $font ) );

		/*
		 * Eigenes CSS: strip_tags gegen </style><script>, und geschweifte
		 * Klammern muessen ausgeglichen sein, damit ein unbeabsichtigt offener
		 * Block nicht den Rest des Stylesheets verschluckt. Die Eingabe ist
		 * ohnehin nur fuer Rollen mit manage_woocommerce erreichbar.
		 */
		$css = isset( $in['custom_css'] ) ? trim( wp_strip_all_tags( (string) $in['custom_css'] ) ) : '';
		if ( '' !== $css && substr_count( $css, '{' ) !== substr_count( $css, '}' ) ) {
			add_settings_error(
				Install::OPT_SETTINGS,
				'wdbtn_custom_css',
				__( 'Das eigene CSS hat ungleich viele geschweifte Klammern und wurde nicht gespeichert. Der zuletzt gespeicherte Stand bleibt erhalten.', 'widerrufsbutton-fuer-woocommerce' )
			);
			// Bewusst der zuletzt gespeicherte Wert, nicht der Default: Sonst
			// wuerde ein Tippfehler das bestehende CSS loeschen.
			$css = (string) Settings::get( 'custom_css', '' );
		}
		$out['custom_css'] = $css;

		$out['withdrawal_days'] = isset( $in['withdrawal_days'] ) ? max( 0, (int) $in['withdrawal_days'] ) : $d['withdrawal_days'];

		// Nach oben gedeckelt, damit ein Tippfehler die Vorauswahl nicht
		// unbrauchbar macht; negativ wäre eine verkürzte gesetzliche Frist.
		$out['grace_days'] = isset( $in['grace_days'] ) ? min( 30, max( 0, (int) $in['grace_days'] ) ) : $d['grace_days'];

		$out['retention_days'] = isset( $in['retention_days'] ) ? max( 0, (int) $in['retention_days'] ) : $d['retention_days'];

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

				<h2><?php esc_html_e( 'Erscheinungsbild', 'widerrufsbutton-fuer-woocommerce' ); ?></h2>
				<p class="description" style="max-width: 46em;">
					<?php esc_html_e( 'Passt Button und Modal an Ihr Corporate Design an. Der Widerrufsbutton muss gut sichtbar bleiben – wählen Sie einen Farbton, der sich vom Seitenhintergrund deutlich abhebt.', 'widerrufsbutton-fuer-woocommerce' ); ?>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Akzentfarbe', 'widerrufsbutton-fuer-woocommerce' ); ?></th>
						<td>
							<input type="text" class="wdbtn-color" data-default-color="#b32d2e" name="<?php echo esc_attr( $name ); ?>[color_accent]" value="<?php echo esc_attr( $s['color_accent'] ); ?>">
							<p class="description"><?php esc_html_e( 'Hintergrund von Button und Bestätigen-Schaltfläche.', 'widerrufsbutton-fuer-woocommerce' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Akzentfarbe (Hover)', 'widerrufsbutton-fuer-woocommerce' ); ?></th>
						<td>
							<input type="text" class="wdbtn-color" name="<?php echo esc_attr( $name ); ?>[color_accent_hover]" value="<?php echo esc_attr( $s['color_accent_hover'] ); ?>">
							<p class="description"><?php esc_html_e( 'Leer lassen: wird automatisch aus der Akzentfarbe abgeleitet.', 'widerrufsbutton-fuer-woocommerce' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Schrift auf der Akzentfarbe', 'widerrufsbutton-fuer-woocommerce' ); ?></th>
						<td>
							<input type="text" class="wdbtn-color" data-default-color="#ffffff" name="<?php echo esc_attr( $name ); ?>[color_on_accent]" value="<?php echo esc_attr( $s['color_on_accent'] ); ?>">
							<p class="description"><?php esc_html_e( 'Muss ausreichend Kontrast zur Akzentfarbe haben (Barrierefreiheit).', 'widerrufsbutton-fuer-woocommerce' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Modal-Hintergrund', 'widerrufsbutton-fuer-woocommerce' ); ?></th>
						<td><input type="text" class="wdbtn-color" data-default-color="#ffffff" name="<?php echo esc_attr( $name ); ?>[color_modal_bg]" value="<?php echo esc_attr( $s['color_modal_bg'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Modal-Textfarbe', 'widerrufsbutton-fuer-woocommerce' ); ?></th>
						<td><input type="text" class="wdbtn-color" data-default-color="#1d2327" name="<?php echo esc_attr( $name ); ?>[color_modal_text]" value="<?php echo esc_attr( $s['color_modal_text'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Eckenrundung (px)', 'widerrufsbutton-fuer-woocommerce' ); ?></th>
						<td>
							<input type="number" min="0" max="40" name="<?php echo esc_attr( $name ); ?>[radius]" value="<?php echo esc_attr( $s['radius'] ); ?>">
							<p class="description"><?php esc_html_e( '0 = eckig. Gilt für Button und Modal.', 'widerrufsbutton-fuer-woocommerce' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Schriftgröße Button (px)', 'widerrufsbutton-fuer-woocommerce' ); ?></th>
						<td><input type="number" min="10" max="32" name="<?php echo esc_attr( $name ); ?>[font_size]" value="<?php echo esc_attr( $s['font_size'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Schriftfamilie', 'widerrufsbutton-fuer-woocommerce' ); ?></th>
						<td>
							<input type="text" class="regular-text" name="<?php echo esc_attr( $name ); ?>[button_font]" value="<?php echo esc_attr( $s['button_font'] ); ?>" placeholder="z. B. inherit">
							<p class="description"><?php esc_html_e( 'Leer lassen für die Plugin-Voreinstellung. „inherit" übernimmt die Schrift Ihres Themes.', 'widerrufsbutton-fuer-woocommerce' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Eigenes CSS', 'widerrufsbutton-fuer-woocommerce' ); ?></th>
						<td>
							<textarea class="large-text code" rows="6" name="<?php echo esc_attr( $name ); ?>[custom_css]" spellcheck="false"><?php echo esc_textarea( $s['custom_css'] ); ?></textarea>
							<p class="description">
								<?php esc_html_e( 'Für Feinheiten, die die Felder oben nicht abdecken. Wird nur im Frontend geladen.', 'widerrufsbutton-fuer-woocommerce' ); ?>
								<?php esc_html_e( 'Nutzbare Klassen: .wdbtn-trigger (Button), .wdbtn-modal, .wdbtn-overlay, .wdbtn-btn, .wdbtn-close.', 'widerrufsbutton-fuer-woocommerce' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Ablauf & Benachrichtigung', 'widerrufsbutton-fuer-woocommerce' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Optionen', 'widerrufsbutton-fuer-woocommerce' ); ?></th>
						<td>
							<?php
							$this->checkbox( $name, 'guest_verification', $s, __( 'Gästen einen E-Mail-Bestätigungslink anbieten (Vertrauens-Kennzeichen)', 'widerrufsbutton-fuer-woocommerce' ) );
							?>
							<p class="description" style="max-width: 46em;">
								<?php esc_html_e( 'Die Eingangsbestätigung enthält dann einen optionalen Link, über den Gäste ihre E-Mail-Adresse bestätigen können. Der Klick ändert nichts an der Wirksamkeit – der Widerruf ist mit dem Absenden bereits eingegangen –, er markiert den Vorgang im Backend als „per E-Mail bestätigt" und hilft Ihnen, echte von missbräuchlichen Eingaben zu unterscheiden.', 'widerrufsbutton-fuer-woocommerce' ); ?>
							</p>
							<?php
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

				<div class="notice notice-info inline" style="margin: 12px 0;">
					<p><strong><?php esc_html_e( 'Warum diese Voreinstellungen großzügig sind', 'widerrufsbutton-fuer-woocommerce' ); ?></strong></p>
					<p>
						<?php esc_html_e( 'Die Frist wird in Kalendertagen gerechnet: Der Tag der Bestellung zählt nicht mit, und die Frist endet um 24:00 Uhr des letzten Tages (§§ 187, 188 BGB).', 'widerrufsbutton-fuer-woocommerce' ); ?>
						<?php esc_html_e( 'Sie steuert ausschließlich, welche Bestellungen zur Auswahl angeboten werden – ein Widerruf wird nie hart blockiert.', 'widerrufsbutton-fuer-woocommerce' ); ?>
					</p>
					<p>
						<?php esc_html_e( 'Beim Kauf von Waren beginnt die Frist erst mit Erhalt der Ware (§ 356 Abs. 2 Nr. 1 BGB), nicht mit der Bestellung. Da WooCommerce kein verlässliches Lieferdatum kennt, endet die hier berechnete Frist tendenziell zu früh. Der Kulanzpuffer gleicht das aus: Zu lange anzubieten kostet Kulanz, zu kurz anzubieten verwehrt ein bestehendes Widerrufsrecht.', 'widerrufsbutton-fuer-woocommerce' ); ?>
					</p>
				</div>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Widerrufsfrist (Tage)', 'widerrufsbutton-fuer-woocommerce' ); ?></th>
						<td>
							<input type="number" min="0" name="<?php echo esc_attr( $name ); ?>[withdrawal_days]" value="<?php echo esc_attr( $s['withdrawal_days'] ); ?>">
							<p class="description"><?php esc_html_e( 'Gesetzlich 14 Tage. 0 = keine Vorauswahl-Begrenzung. Das Plugin blockiert Widerrufe nicht hart.', 'widerrufsbutton-fuer-woocommerce' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Kulanzpuffer (Tage)', 'widerrufsbutton-fuer-woocommerce' ); ?></th>
						<td>
							<input type="number" min="0" max="30" name="<?php echo esc_attr( $name ); ?>[grace_days]" value="<?php echo esc_attr( $s['grace_days'] ); ?>">
							<p class="description"><?php esc_html_e( 'Zusätzliche Tage über die Frist hinaus, in denen die Bestellung weiterhin zur Auswahl steht. Empfohlen: mindestens 1. Auf 0 setzen Sie nur, wenn Sie die Berechnungsbasis am tatsächlichen Lieferdatum ausgerichtet haben.', 'widerrufsbutton-fuer-woocommerce' ); ?></p>
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
					<tr>
						<th scope="row"><?php esc_html_e( 'Ohne passende Bestellung', 'widerrufsbutton-fuer-woocommerce' ); ?></th>
						<td>
							<?php
							$this->checkbox(
								$name,
								'accept_unmatched',
								$s,
								__( 'Widerrufe auch dann annehmen, wenn keine Bestellung zugeordnet werden kann (empfohlen)', 'widerrufsbutton-fuer-woocommerce' )
							);
							?>
							<p class="description">
								<?php esc_html_e( 'Ein Widerruf wird mit seinem Zugang wirksam – nicht erst damit, dass Sie ihn zuordnen können. Ein Tippfehler in der Bestellnummer ändert daran nichts. Diese Erklärungen erscheinen in der Liste als „Nicht zugeordnet" und bedürfen Ihrer manuellen Klärung.', 'widerrufsbutton-fuer-woocommerce' ); ?>
								<?php esc_html_e( 'Schalten Sie die Option ab, werden solche Widerrufe verworfen und nirgends dokumentiert – auch dann, wenn sie fristgerecht erklärt wurden.', 'widerrufsbutton-fuer-woocommerce' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Produkt-Ausschlüsse', 'widerrufsbutton-fuer-woocommerce' ); ?></h2>

				<div class="notice notice-warning inline" style="margin: 12px 0;">
					<p><strong><?php esc_html_e( 'Bitte mit Bedacht einsetzen', 'widerrufsbutton-fuer-woocommerce' ); ?></strong></p>
					<p>
						<?php esc_html_e( 'Ausschlüsse blockieren den Widerruf nicht. Betroffene Erklärungen werden weiterhin angenommen, dokumentiert und für Sie markiert – die Entscheidung liegt bei Ihnen, nicht beim Plugin.', 'widerrufsbutton-fuer-woocommerce' ); ?>
					</p>
					<p>
						<?php esc_html_e( 'Grund: Ein Produkttyp sagt wenig über das Widerrufsrecht aus. „Virtuell" trifft in WooCommerce auch Dienstleistungen, die regelmäßig gerade nicht ausgenommen sind. Bei digitalen Inhalten erlischt das Recht nur, wenn die Kundschaft vorher ausdrücklich zugestimmt und ihre Kenntnis bestätigt hat (§ 356 Abs. 5 BGB) – das kann das Plugin nicht prüfen.', 'widerrufsbutton-fuer-woocommerce' ); ?>
					</p>
					<p>
						<?php esc_html_e( 'Ob ein Ausschluss im Einzelfall trägt, sollten Sie rechtlich prüfen lassen. Über die gesetzlich vorgeschriebene Widerrufsfunktion ein bestehendes Widerrufsrecht zu verweigern, ist riskanter als ein zu viel bearbeiteter Widerruf.', 'widerrufsbutton-fuer-woocommerce' ); ?>
					</p>
				</div>

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
						<th scope="row"><?php esc_html_e( 'Aufbewahrung (Tage)', 'widerrufsbutton-fuer-woocommerce' ); ?></th>
						<td>
							<input type="number" min="0" name="<?php echo esc_attr( $name ); ?>[retention_days]" value="<?php echo esc_attr( $s['retention_days'] ); ?>">
							<p class="description">
								<?php esc_html_e( '0 = keine automatische Löschung (Voreinstellung). Die Datensätze belegen den Zugang der Widerrufserklärung und damit, dass Sie Ihre gesetzliche Bestätigungspflicht erfüllt haben – richten Sie eine Frist an Ihren Aufbewahrungspflichten aus.', 'widerrufsbutton-fuer-woocommerce' ); ?>
								<?php esc_html_e( 'Unabhängig davon werden unbestätigte Anfragen, deren Bestätigungslink abgelaufen ist, nach sieben Tagen automatisch entfernt.', 'widerrufsbutton-fuer-woocommerce' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Auskunft & Löschung', 'widerrufsbutton-fuer-woocommerce' ); ?></th>
						<td>
							<p class="description">
								<?php esc_html_e( 'Widerrufe sind an die WordPress-Werkzeuge unter Werkzeuge → Persönliche Daten angebunden. Bei einer Löschanfrage werden Name, E-Mail, Grund und IP-Kennung entfernt; der Vorgang selbst bleibt als Nachweis bestehen. Prüfen Sie, ob das für Ihren Shop so zutrifft.', 'widerrufsbutton-fuer-woocommerce' ); ?>
							</p>
						</td>
					</tr>
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
