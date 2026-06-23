<?php
/**
 * WC_Email: Eingangsbestätigung an die Verbraucher:in (gesetzlich verpflichtend).
 *
 * @package Widerrufsbutton
 */

namespace Widerrufsbutton;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\WC_Email' ) ) {
	return;
}

/**
 * Bestätigt der Verbraucher:in den Eingang des Widerrufs (dauerhafter Datenträger).
 */
class Email_Customer_Confirmation extends \WC_Email {

	/**
	 * Konstruktor.
	 */
	public function __construct() {
		$this->id             = 'wdbtn_customer_confirmation';
		$this->customer_email = true;
		$this->title          = __( 'Widerruf – Eingangsbestätigung (Kunde)', 'widerrufsbutton-fuer-woocommerce' );
		$this->description    = __( 'Wird nach Eingang eines Widerrufs automatisch an die Verbraucher:in gesendet (gesetzliche Pflicht).', 'widerrufsbutton-fuer-woocommerce' );

		$this->template_base  = WDBTN_PATH . 'templates/';
		$this->template_html  = 'emails/confirmation-customer.php';
		$this->template_plain = 'emails/confirmation-customer.php';

		$this->placeholders = array(
			'{site_title}' => $this->get_blogname(),
		);

		parent::__construct();
	}

	/**
	 * Standard-Betreff.
	 *
	 * @return string
	 */
	public function get_default_subject() {
		return __( 'Ihr Widerruf ist bei {site_title} eingegangen', 'widerrufsbutton-fuer-woocommerce' );
	}

	/**
	 * Standard-Überschrift.
	 *
	 * @return string
	 */
	public function get_default_heading() {
		return __( 'Widerruf eingegangen', 'widerrufsbutton-fuer-woocommerce' );
	}

	/**
	 * Versand auslösen.
	 *
	 * @param int $id Widerruf-ID.
	 * @return bool
	 */
	public function trigger( $id ) {
		$this->setup_locale();

		$record = Repository::get( $id );
		$sent   = false;

		if ( $record ) {
			$this->object                          = $record;
			$this->recipient                       = $record['email'];
			$this->placeholders['{order_number}']  = $record['order_number'];

			if ( $this->is_enabled() && $this->get_recipient() ) {
				$sent = $this->send(
					$this->get_recipient(),
					$this->get_subject(),
					$this->get_content(),
					$this->get_headers(),
					$this->get_attachments()
				);
			}
		}

		$this->restore_locale();

		return $sent;
	}

	/**
	 * HTML-Inhalt aus überschreibbarem Template.
	 *
	 * @return string
	 */
	public function get_content_html() {
		return wc_get_template_html(
			$this->template_html,
			array(
				'widerruf'      => $this->object,
				'email_heading' => $this->get_heading(),
				'sent_to_admin' => false,
				'plain_text'    => false,
				'email'         => $this,
			),
			'',
			$this->template_base
		);
	}

	/**
	 * Text-Inhalt aus demselben Template (Plain-Modus).
	 *
	 * @return string
	 */
	public function get_content_plain() {
		return wc_get_template_html(
			$this->template_plain,
			array(
				'widerruf'      => $this->object,
				'email_heading' => $this->get_heading(),
				'sent_to_admin' => false,
				'plain_text'    => true,
				'email'         => $this,
			),
			'',
			$this->template_base
		);
	}
}
