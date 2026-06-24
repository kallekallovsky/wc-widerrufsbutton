<?php
/**
 * Modal-Overlay für den Widerruf.
 *
 * Erwartet: $settings (array) aus Frontend::output_modal().
 *
 * @package Widerrufsbutton
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$wdbtn_settings = isset( $settings ) && is_array( $settings ) ? $settings : array();
?>
<div class="wdbtn-overlay" id="wdbtn-overlay" hidden>
	<div
		class="wdbtn-modal"
		id="wdbtn-modal"
		role="dialog"
		aria-modal="true"
		aria-labelledby="wdbtn-modal-title"
		aria-describedby="wdbtn-modal-intro"
	>
		<button type="button" class="wdbtn-close" data-wdbtn-close aria-label="<?php esc_attr_e( 'Schließen', 'widerrufsbutton-fuer-woocommerce' ); ?>">&times;</button>

		<h2 id="wdbtn-modal-title" class="wdbtn-title">
			<?php esc_html_e( 'Vertrag widerrufen', 'widerrufsbutton-fuer-woocommerce' ); ?>
		</h2>
		<p id="wdbtn-modal-intro" class="wdbtn-intro">
			<?php esc_html_e( 'Hier können Sie einen online geschlossenen Vertrag widerrufen. Der Widerruf erfolgt in zwei Schritten.', 'widerrufsbutton-fuer-woocommerce' ); ?>
		</p>

		<form class="wdbtn-form" id="wdbtn-form" novalidate>

			<?php /* ---------- Schritt 1: Identifikation ---------- */ ?>
			<div class="wdbtn-step" data-step="1">

				<div class="wdbtn-field wdbtn-loggedin-only" hidden>
					<label for="wdbtn-order-select"><?php esc_html_e( 'Bestellung auswählen', 'widerrufsbutton-fuer-woocommerce' ); ?></label>
					<select id="wdbtn-order-select" name="order_id">
						<option value=""><?php esc_html_e( '– Bitte wählen –', 'widerrufsbutton-fuer-woocommerce' ); ?></option>
					</select>
					<p class="wdbtn-hint"><?php esc_html_e( 'Es werden nur Bestellungen angezeigt, für die ein Widerruf noch möglich ist.', 'widerrufsbutton-fuer-woocommerce' ); ?></p>
					<p class="wdbtn-hint wdbtn-no-orders" hidden><?php esc_html_e( 'Für Ihr Konto wurden keine widerrufbaren Bestellungen gefunden.', 'widerrufsbutton-fuer-woocommerce' ); ?></p>
				</div>

				<div class="wdbtn-guest-only" hidden>
					<div class="wdbtn-field">
						<label for="wdbtn-name"><?php esc_html_e( 'Name', 'widerrufsbutton-fuer-woocommerce' ); ?> <span class="wdbtn-req" aria-hidden="true">*</span></label>
						<input type="text" id="wdbtn-name" name="name" autocomplete="name" required>
					</div>
					<div class="wdbtn-field">
						<label for="wdbtn-order-number"><?php esc_html_e( 'Bestellnummer', 'widerrufsbutton-fuer-woocommerce' ); ?> <span class="wdbtn-req" aria-hidden="true">*</span></label>
						<input type="text" id="wdbtn-order-number" name="order_number" inputmode="text" required>
					</div>
					<div class="wdbtn-field">
						<label for="wdbtn-email"><?php esc_html_e( 'E-Mail-Adresse', 'widerrufsbutton-fuer-woocommerce' ); ?> <span class="wdbtn-req" aria-hidden="true">*</span></label>
						<input type="email" id="wdbtn-email" name="email" autocomplete="email" required>
						<p class="wdbtn-hint"><?php esc_html_e( 'An diese Adresse senden wir die Eingangsbestätigung.', 'widerrufsbutton-fuer-woocommerce' ); ?></p>
					</div>
				</div>

				<?php /* Artikelbezug (Produktseiten-Vorbefüllung) */ ?>
				<div class="wdbtn-field wdbtn-sku-field" hidden>
					<label for="wdbtn-sku"><?php esc_html_e( 'Artikelnummer (optional)', 'widerrufsbutton-fuer-woocommerce' ); ?></label>
					<input type="text" id="wdbtn-sku" name="sku" readonly>
					<p class="wdbtn-hint"><?php esc_html_e( 'Sie widerrufen bezogen auf diesen Artikel. Der Artikel wird intern Ihrer Bestellung zugeordnet.', 'widerrufsbutton-fuer-woocommerce' ); ?></p>
				</div>
				<input type="hidden" name="product_id" id="wdbtn-product-id" value="0">
				<input type="hidden" name="scope" id="wdbtn-scope" value="order">

				<?php /* Optionaler Grund – KEIN Pflichtfeld (gesetzlich) */ ?>
				<div class="wdbtn-field">
					<label for="wdbtn-reason"><?php esc_html_e( 'Grund', 'widerrufsbutton-fuer-woocommerce' ); ?> <span class="wdbtn-optional">(<?php esc_html_e( 'optional', 'widerrufsbutton-fuer-woocommerce' ); ?>)</span></label>
					<textarea id="wdbtn-reason" name="reason" rows="3"></textarea>
					<p class="wdbtn-hint"><?php esc_html_e( 'Die Angabe eines Grundes ist freiwillig und keine Voraussetzung für den Widerruf.', 'widerrufsbutton-fuer-woocommerce' ); ?></p>
				</div>

				<p class="wdbtn-message" id="wdbtn-message-1" role="alert" hidden></p>

				<div class="wdbtn-actions">
					<button type="button" class="wdbtn-btn wdbtn-next" data-wdbtn-next>
						<?php esc_html_e( 'Weiter', 'widerrufsbutton-fuer-woocommerce' ); ?>
					</button>
				</div>
			</div>

			<?php /* ---------- Schritt 2: gesonderte Bestätigung ---------- */ ?>
			<div class="wdbtn-step" data-step="2" hidden>
				<p class="wdbtn-confirm-text">
					<?php esc_html_e( 'Bitte bestätigen Sie Ihren Widerruf verbindlich. Nach dem Absenden erhalten Sie eine Eingangsbestätigung per E-Mail.', 'widerrufsbutton-fuer-woocommerce' ); ?>
				</p>

				<div class="wdbtn-summary" id="wdbtn-summary" aria-live="polite"></div>

				<p class="wdbtn-message" id="wdbtn-message-2" role="alert" hidden></p>

				<div class="wdbtn-actions">
					<button type="button" class="wdbtn-btn wdbtn-secondary" data-wdbtn-back>
						<?php esc_html_e( 'Zurück', 'widerrufsbutton-fuer-woocommerce' ); ?>
					</button>
					<button type="submit" class="wdbtn-btn wdbtn-confirm" data-wdbtn-confirm>
						<?php esc_html_e( 'Widerruf verbindlich bestätigen', 'widerrufsbutton-fuer-woocommerce' ); ?>
					</button>
				</div>
			</div>

			<?php /* ---------- Abschluss ---------- */ ?>
			<div class="wdbtn-step" data-step="done" hidden>
				<div class="wdbtn-success" role="status">
					<h3 id="wdbtn-done-title"><?php esc_html_e( 'Vielen Dank', 'widerrufsbutton-fuer-woocommerce' ); ?></h3>
					<p id="wdbtn-done-text"><?php esc_html_e( 'Eine Eingangsbestätigung wurde an Ihre E-Mail-Adresse gesendet.', 'widerrufsbutton-fuer-woocommerce' ); ?></p>
				</div>
				<div class="wdbtn-actions">
					<button type="button" class="wdbtn-btn" data-wdbtn-close>
						<?php esc_html_e( 'Schließen', 'widerrufsbutton-fuer-woocommerce' ); ?>
					</button>
				</div>
			</div>

		</form>
	</div>
</div>
<?php
unset( $wdbtn_settings );
