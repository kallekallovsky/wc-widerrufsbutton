<?php
/**
 * Listenansicht der Widerrufe (WP_List_Table).
 *
 * @package Widerrufsbutton
 */

namespace Widerrufsbutton;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Tabellarische Übersicht aller Widerrufe mit Filter, Suche und Sortierung.
 */
class List_Table extends \WP_List_Table {

	/**
	 * Konstruktor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'widerruf',
				'plural'   => 'widerrufe',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Spalten.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'created_at'   => __( 'Datum/Uhrzeit', 'widerrufsbutton-fuer-woocommerce' ),
			'order_number' => __( 'Bestellnummer', 'widerrufsbutton-fuer-woocommerce' ),
			'name'         => __( 'Kunde', 'widerrufsbutton-fuer-woocommerce' ),
			'email'        => __( 'E-Mail', 'widerrufsbutton-fuer-woocommerce' ),
			'scope'        => __( 'Umfang', 'widerrufsbutton-fuer-woocommerce' ),
			'status'       => __( 'Status', 'widerrufsbutton-fuer-woocommerce' ),
		);
	}

	/**
	 * Sortierbare Spalten.
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'created_at'   => array( 'created_at', true ),
			'order_number' => array( 'order_number', false ),
			'email'        => array( 'email', false ),
			'status'       => array( 'status', false ),
		);
	}

	/**
	 * Lädt die Datensätze.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$per_page = 20;

		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'created_at'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order   = isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'desc'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status  = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search  = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$from    = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$to      = isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$result = Repository::query(
			array(
				'status'    => $status,
				'search'    => $search,
				'date_from' => $from,
				'date_to'   => $to,
				'orderby'   => $orderby,
				'order'     => $order,
				'per_page'  => $per_page,
				'paged'     => $this->get_pagenum(),
			)
		);

		$this->items           = $result['items'];
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );

		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
			)
		);
	}

	/**
	 * Standard-Ausgabe einer Zelle.
	 *
	 * @param array  $item        Datensatz.
	 * @param string $column_name Spaltenschlüssel.
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		$value = isset( $item[ $column_name ] ) ? $item[ $column_name ] : '';
		return esc_html( $value );
	}

	/**
	 * Spalte Datum/Uhrzeit mit Link zur Detailansicht.
	 *
	 * @param array $item Datensatz.
	 * @return string
	 */
	public function column_created_at( $item ) {
		$formatted = ! empty( $item['created_at'] )
			? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $item['created_at'] ) )
			: '';

		$url = add_query_arg(
			array(
				'page'     => 'wdbtn-widerrufe',
				'widerruf' => (int) $item['id'],
			),
			admin_url( 'admin.php' )
		);

		return sprintf( '<a href="%1$s"><strong>%2$s</strong></a>', esc_url( $url ), esc_html( $formatted ) );
	}

	/**
	 * Spalte Umfang.
	 *
	 * @param array $item Datensatz.
	 * @return string
	 */
	public function column_scope( $item ) {
		$label = ( isset( $item['scope'] ) && 'item' === $item['scope'] )
			? __( 'Artikel', 'widerrufsbutton-fuer-woocommerce' )
			: __( 'Bestellung', 'widerrufsbutton-fuer-woocommerce' );
		return esc_html( $label );
	}

	/**
	 * Spalte Status (mit Label).
	 *
	 * @param array $item Datensatz.
	 * @return string
	 */
	public function column_status( $item ) {
		$labels = Admin::statuses();
		$key    = isset( $item['status'] ) ? $item['status'] : '';
		$label  = isset( $labels[ $key ] ) ? $labels[ $key ] : $key;
		return '<span class="wdbtn-status wdbtn-status-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</span>';
	}

	/**
	 * Zusätzliche Filter über der Tabelle.
	 *
	 * @param string $which top|bottom.
	 * @return void
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$from   = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$to     = isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="alignleft actions">
			<label class="screen-reader-text" for="wdbtn-filter-status"><?php esc_html_e( 'Nach Status filtern', 'widerrufsbutton-fuer-woocommerce' ); ?></label>
			<select name="status" id="wdbtn-filter-status">
				<option value=""><?php esc_html_e( 'Alle Status', 'widerrufsbutton-fuer-woocommerce' ); ?></option>
				<?php foreach ( Admin::statuses() as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $status, $key ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<input type="date" name="date_from" value="<?php echo esc_attr( $from ); ?>" aria-label="<?php esc_attr_e( 'Von', 'widerrufsbutton-fuer-woocommerce' ); ?>">
			<input type="date" name="date_to" value="<?php echo esc_attr( $to ); ?>" aria-label="<?php esc_attr_e( 'Bis', 'widerrufsbutton-fuer-woocommerce' ); ?>">
			<?php submit_button( __( 'Filtern', 'widerrufsbutton-fuer-woocommerce' ), '', 'filter_action', false ); ?>
		</div>
		<?php
	}

	/**
	 * Ausgabe bei leerer Liste.
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'Keine Widerrufe gefunden.', 'widerrufsbutton-fuer-woocommerce' );
	}
}
