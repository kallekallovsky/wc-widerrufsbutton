<?php
/**
 * Admin-Backend: Menü, Listenansicht, Detailansicht, Statuswechsel, CSV-Export.
 *
 * @package Widerrufsbutton
 */

namespace Widerrufsbutton;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Verwaltung der Widerrufe unter dem WooCommerce-Menü.
 */
class Admin {

	const MENU_SLUG = 'wdbtn-widerrufe';
	const CAP       = 'manage_woocommerce';

	/**
	 * Hook-Suffix der Menüseite.
	 *
	 * @var string
	 */
	private $hook = '';

	/**
	 * Registriert Hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_post_wdbtn_update_status', array( $this, 'handle_update_status' ) );
		add_action( 'admin_post_wdbtn_export_csv', array( $this, 'handle_export_csv' ) );
	}

	/**
	 * Status-Schlüssel und -Bezeichnungen.
	 *
	 * @return array
	 */
	public static function statuses() {
		return array(
			'eingegangen'    => __( 'Eingegangen', 'widerrufsbutton-fuer-woocommerce' ),
			'in_bearbeitung' => __( 'In Bearbeitung', 'widerrufsbutton-fuer-woocommerce' ),
			'bestaetigt'     => __( 'Bestätigt', 'widerrufsbutton-fuer-woocommerce' ),
			'abgelehnt'      => __( 'Abgelehnt', 'widerrufsbutton-fuer-woocommerce' ),
		);
	}

	/**
	 * Menüeintrag unter WooCommerce.
	 *
	 * @return void
	 */
	public function menu() {
		$this->hook = add_submenu_page(
			'woocommerce',
			__( 'Widerrufe', 'widerrufsbutton-fuer-woocommerce' ),
			__( 'Widerrufe', 'widerrufsbutton-fuer-woocommerce' ),
			self::CAP,
			self::MENU_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Lädt Styles nur auf der Plugin-Seite.
	 *
	 * @param string $hook Aktueller Hook-Suffix.
	 * @return void
	 */
	public function enqueue( $hook ) {
		if ( $hook !== $this->hook ) {
			return;
		}
		wp_enqueue_style( 'wdbtn-admin', WDBTN_URL . 'assets/css/admin.css', array(), WDBTN_VERSION );
	}

	/**
	 * Routing: Liste oder Detail.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Sie haben keine Berechtigung für diese Seite.', 'widerrufsbutton-fuer-woocommerce' ) );
		}

		$id = isset( $_GET['widerruf'] ) ? absint( $_GET['widerruf'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $id ) {
			$this->render_detail( $id );
			return;
		}

		$this->render_list();
	}

	/**
	 * Listenansicht.
	 *
	 * @return void
	 */
	private function render_list() {
		$table = new List_Table();
		$table->prepare_items();

		$export_url = wp_nonce_url(
			add_query_arg(
				array_filter(
					array(
						'action'    => 'wdbtn_export_csv',
						'status'    => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
						's'         => isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
						'date_from' => isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
						'date_to'   => isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					)
				),
				admin_url( 'admin-post.php' )
			),
			'wdbtn_export'
		);
		?>
		<div class="wrap wdbtn-admin">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Widerrufe', 'widerrufsbutton-fuer-woocommerce' ); ?></h1>
			<a href="<?php echo esc_url( $export_url ); ?>" class="page-title-action"><?php esc_html_e( 'CSV-Export', 'widerrufsbutton-fuer-woocommerce' ); ?></a>
			<hr class="wp-header-end">

			<?php do_action( 'wdbtn_admin_list_before' ); ?>

			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>">
				<?php
				$table->search_box( __( 'Suche (Bestellnummer/E-Mail)', 'widerrufsbutton-fuer-woocommerce' ), 'wdbtn' );
				$table->display();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Detailansicht eines Widerrufs.
	 *
	 * @param int $id Datensatz-ID.
	 * @return void
	 */
	private function render_detail( $id ) {
		$w = Repository::get( $id );

		if ( ! $w ) {
			echo '<div class="wrap"><p>' . esc_html__( 'Widerruf nicht gefunden.', 'widerrufsbutton-fuer-woocommerce' ) . '</p></div>';
			return;
		}

		$statuses   = self::statuses();
		$back_url    = add_query_arg( array( 'page' => self::MENU_SLUG ), admin_url( 'admin.php' ) );
		$received    = ! empty( $w['created_at'] ) ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $w['created_at'] ) ) : '';
		$scope_label = ( 'item' === $w['scope'] ) ? __( 'Artikel', 'widerrufsbutton-fuer-woocommerce' ) : __( 'Gesamte Bestellung', 'widerrufsbutton-fuer-woocommerce' );
		$order_link  = '';
		if ( ! empty( $w['order_id'] ) ) {
			$order_link = add_query_arg(
				array(
					'post'   => (int) $w['order_id'],
					'action' => 'edit',
				),
				admin_url( 'post.php' )
			);
		}
		?>
		<div class="wrap wdbtn-admin">
			<h1 class="wp-heading-inline">
				<?php
				/* translators: %d: Vorgangsnummer */
				echo esc_html( sprintf( __( 'Widerruf #%d', 'widerrufsbutton-fuer-woocommerce' ), (int) $w['id'] ) );
				?>
			</h1>
			<a href="<?php echo esc_url( $back_url ); ?>" class="page-title-action"><?php esc_html_e( '← Zurück zur Liste', 'widerrufsbutton-fuer-woocommerce' ); ?></a>
			<hr class="wp-header-end">

			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Status aktualisiert.', 'widerrufsbutton-fuer-woocommerce' ); ?></p></div>
			<?php endif; ?>

			<div class="wdbtn-detail">
				<table class="widefat striped">
					<tbody>
						<tr><th><?php esc_html_e( 'Eingegangen am', 'widerrufsbutton-fuer-woocommerce' ); ?></th><td><?php echo esc_html( $received ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Umfang', 'widerrufsbutton-fuer-woocommerce' ); ?></th><td><?php echo esc_html( $scope_label ); ?></td></tr>
						<tr>
							<th><?php esc_html_e( 'Bestellnummer', 'widerrufsbutton-fuer-woocommerce' ); ?></th>
							<td>
								<?php echo esc_html( $w['order_number'] ); ?>
								<?php if ( $order_link ) : ?>
									– <a href="<?php echo esc_url( $order_link ); ?>"><?php esc_html_e( 'Bestellung öffnen', 'widerrufsbutton-fuer-woocommerce' ); ?></a>
								<?php endif; ?>
							</td>
						</tr>
						<?php if ( 'item' === $w['scope'] ) : ?>
							<tr><th><?php esc_html_e( 'Artikel (SKU)', 'widerrufsbutton-fuer-woocommerce' ); ?></th><td><?php echo esc_html( $w['sku'] ? $w['sku'] : ( $w['product_id'] ? '#' . $w['product_id'] : '' ) ); ?></td></tr>
						<?php endif; ?>
						<tr><th><?php esc_html_e( 'Kunde', 'widerrufsbutton-fuer-woocommerce' ); ?></th><td><?php echo esc_html( $w['name'] ); ?></td></tr>
						<tr><th><?php esc_html_e( 'E-Mail', 'widerrufsbutton-fuer-woocommerce' ); ?></th><td><?php echo esc_html( $w['email'] ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Grund (optional)', 'widerrufsbutton-fuer-woocommerce' ); ?></th><td><?php echo $w['reason'] ? esc_html( $w['reason'] ) : '<em>' . esc_html__( 'keine Angabe', 'widerrufsbutton-fuer-woocommerce' ) . '</em>'; ?></td></tr>
						<tr><th><?php esc_html_e( 'Eingangsbestätigung', 'widerrufsbutton-fuer-woocommerce' ); ?></th><td><?php echo $w['confirmation_sent'] ? esc_html__( 'versendet', 'widerrufsbutton-fuer-woocommerce' ) : esc_html__( 'nicht versendet', 'widerrufsbutton-fuer-woocommerce' ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Verifizierung', 'widerrufsbutton-fuer-woocommerce' ); ?></th><td><?php echo esc_html( $w['verification_status'] ); ?></td></tr>
					</tbody>
				</table>

				<h2><?php esc_html_e( 'Status ändern', 'widerrufsbutton-fuer-woocommerce' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="wdbtn_update_status">
					<input type="hidden" name="widerruf" value="<?php echo (int) $w['id']; ?>">
					<?php wp_nonce_field( 'wdbtn_update_status' ); ?>
					<select name="status">
						<?php foreach ( $statuses as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $w['status'], $key ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<?php submit_button( __( 'Status speichern', 'widerrufsbutton-fuer-woocommerce' ), 'primary', 'submit', false ); ?>
				</form>

				<?php do_action( 'wdbtn_admin_detail_after', $w ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Verarbeitet die Statusänderung.
	 *
	 * @return void
	 */
	public function handle_update_status() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'widerrufsbutton-fuer-woocommerce' ) );
		}
		check_admin_referer( 'wdbtn_update_status' );

		$id     = isset( $_POST['widerruf'] ) ? absint( $_POST['widerruf'] ) : 0;
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';

		if ( $id && array_key_exists( $status, self::statuses() ) ) {
			Repository::update_status( $id, $status );
			$user = wp_get_current_user();
			Repository::add_log( $id, $user ? $user->user_login : 'admin', 'status_' . $status, '' );
			do_action( 'wdbtn_status_changed', $id, $status );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'     => self::MENU_SLUG,
					'widerruf' => $id,
					'updated'  => 1,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Exportiert die (gefilterten) Widerrufe als CSV.
	 *
	 * @return void
	 */
	public function handle_export_csv() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'widerrufsbutton-fuer-woocommerce' ) );
		}
		check_admin_referer( 'wdbtn_export' );

		$result = Repository::query(
			array(
				'status'    => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '',
				'search'    => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
				'date_from' => isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '',
				'date_to'   => isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '',
				'per_page'  => 100000,
				'paged'     => 1,
			)
		);

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=widerrufe-' . gmdate( 'Y-m-d' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );

		fputcsv(
			$out,
			array( 'id', 'created_at', 'order_number', 'order_id', 'scope', 'product_id', 'sku', 'name', 'email', 'reason', 'status', 'verification_status', 'confirmation_sent' )
		);

		foreach ( $result['items'] as $row ) {
			fputcsv(
				$out,
				array(
					$row['id'],
					$row['created_at'],
					$row['order_number'],
					$row['order_id'],
					$row['scope'],
					$row['product_id'],
					$row['sku'],
					$row['name'],
					$row['email'],
					$row['reason'],
					$row['status'],
					$row['verification_status'],
					$row['confirmation_sent'],
				)
			);
		}

		fclose( $out );
		exit;
	}
}
