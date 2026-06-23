<?php
/**
 * WC_Email: Benachrichtigung an den Shop-Betreiber.
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
 * Informiert den Betreiber über einen eingegangenen Widerruf.
 */
class Email_Admin_Notification extends \WC_Email {

	/**
	 * Konstruktor.
	 */
	public function __construct() {
		$this->id          = 'wdbtn_admin_notification';
		$this->title       = __( 'Widerruf – Benachrichtigung (Betreiber)', 'widerrufsbutton-fuer-woocommerce' );
		$this->description = __( 'Wird nach Eingang eines Widerrufs an den Shop-Betreiber gesendet.', 'widerrufsbutton-fuer-woocommerce' );

		$this->template_base  = WDBTN_PATH . 'templates/';
		$this->template_html  = 'emails/notification-admin.php';
		$this->template_plain = 'emails/notification-admin.php';

		$this->placeholders = array(
			'{site_title}' => $this->get_blogname(),
		);

		parent::__construct();

		// Standard-Empfänger aus den Plugin-Einstellungen (Default = WooCommerce-Admin-Mail).
		$this->recipient = Settings::get( 'admin_recipients', get_option( 'admin_email' ) );
	}

	/**
	 * Standard-Empfänger.
	 *
	 * @return string
	 */
	public function get_default_recipient() {
		return Settings::get( 'admin_recipients', get_option( 'admin_email' ) );
	}

	/**
	 * Standard-Betreff.
	 *
	 * @return string
	 */
	public function get_default_subject() {
		return __( '[{site_title}] Neuer Widerruf eingegangen (#{withdrawal_id})', 'widerrufsbutton-fuer-woocommerce' );
	}

	/**
	 * Standard-Überschrift.
	 *
	 * @return string
	 */
	public function get_default_heading() {
		return __( 'Neuer Widerruf eingegangen', 'widerrufsbutton-fuer-woocommerce' );
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
			$this->object                            = $record;
			$this->placeholders['{order_number}']    = $record['order_number'];
			$this->placeholders['{withdrawal_id}']   = $id;

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
				'sent_to_admin' => true,
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
				'sent_to_admin' => true,
				'plain_text'    => true,
				'email'         => $this,
			),
			'',
			$this->template_base
		);
	}
}
