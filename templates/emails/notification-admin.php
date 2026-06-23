<?php
/**
 * E-Mail-Template: Betreiber-Benachrichtigung über einen neuen Widerruf.
 *
 * Überschreibbar unter yourtheme/woocommerce/emails/notification-admin.php
 *
 * Variablen: $widerruf (array), $email_heading, $sent_to_admin, $plain_text, $email
 *
 * @package Widerrufsbutton
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$wdbtn_w        = is_array( $widerruf ) ? $widerruf : array();
$wdbtn_id       = isset( $wdbtn_w['id'] ) ? $wdbtn_w['id'] : '';
$wdbtn_name     = isset( $wdbtn_w['name'] ) ? $wdbtn_w['name'] : '';
$wdbtn_ordernum = isset( $wdbtn_w['order_number'] ) ? $wdbtn_w['order_number'] : '';
$wdbtn_orderid  = isset( $wdbtn_w['order_id'] ) ? $wdbtn_w['order_id'] : '';
$wdbtn_email    = isset( $wdbtn_w['email'] ) ? $wdbtn_w['email'] : '';
$wdbtn_reason   = isset( $wdbtn_w['reason'] ) ? $wdbtn_w['reason'] : '';
$wdbtn_is_item  = isset( $wdbtn_w['scope'] ) && 'item' === $wdbtn_w['scope'];
$wdbtn_article  = '';
if ( $wdbtn_is_item ) {
	$wdbtn_article = ! empty( $wdbtn_w['sku'] ) ? $wdbtn_w['sku'] : ( ! empty( $wdbtn_w['product_id'] ) ? '#' . $wdbtn_w['product_id'] : '' );
}
$wdbtn_scope_label = $wdbtn_is_item
	? __( 'Artikelbezogener Widerruf', 'widerrufsbutton-fuer-woocommerce' )
	: __( 'Widerruf der gesamten Bestellung', 'widerrufsbutton-fuer-woocommerce' );
$wdbtn_received = ! empty( $wdbtn_w['created_at'] )
	? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $wdbtn_w['created_at'] ) )
	: '';
$wdbtn_edit_link = ( $wdbtn_orderid && function_exists( 'admin_url' ) )
	? admin_url( 'post.php?post=' . (int) $wdbtn_orderid . '&action=edit' )
	: '';

if ( $plain_text ) {

	echo wp_strip_all_tags( $email_heading ) . "\n\n";
	echo esc_html__( 'Es ist ein neuer Widerruf eingegangen:', 'widerrufsbutton-fuer-woocommerce' ) . "\n\n";
	echo esc_html__( 'Umfang:', 'widerrufsbutton-fuer-woocommerce' ) . ' ' . $wdbtn_scope_label . "\n";
	echo esc_html__( 'Bestellnummer:', 'widerrufsbutton-fuer-woocommerce' ) . ' ' . $wdbtn_ordernum . "\n";
	if ( '' !== $wdbtn_article ) {
		echo esc_html__( 'Artikel:', 'widerrufsbutton-fuer-woocommerce' ) . ' ' . $wdbtn_article . "\n";
	}
	echo esc_html__( 'Name:', 'widerrufsbutton-fuer-woocommerce' ) . ' ' . $wdbtn_name . "\n";
	echo esc_html__( 'E-Mail:', 'widerrufsbutton-fuer-woocommerce' ) . ' ' . $wdbtn_email . "\n";
	echo esc_html__( 'Eingegangen am:', 'widerrufsbutton-fuer-woocommerce' ) . ' ' . $wdbtn_received . "\n";
	if ( '' !== $wdbtn_reason ) {
		echo esc_html__( 'Grund (optional):', 'widerrufsbutton-fuer-woocommerce' ) . ' ' . $wdbtn_reason . "\n";
	}
	echo "\n" . esc_html__( 'Hinweis: Es erfolgte kein automatischer Statuswechsel und keine Rückerstattung.', 'widerrufsbutton-fuer-woocommerce' ) . "\n";

} else {

	do_action( 'woocommerce_email_header', $email_heading, $email );
	?>
	<p><?php esc_html_e( 'Es ist ein neuer Widerruf eingegangen:', 'widerrufsbutton-fuer-woocommerce' ); ?></p>

	<table cellspacing="0" cellpadding="6" border="1" style="width:100%;border-collapse:collapse;border-color:#e5e5e5;">
		<tr>
			<th align="left"><?php esc_html_e( 'Vorgang', 'widerrufsbutton-fuer-woocommerce' ); ?></th>
			<td>#<?php echo esc_html( $wdbtn_id ); ?></td>
		</tr>
		<tr>
			<th align="left"><?php esc_html_e( 'Umfang', 'widerrufsbutton-fuer-woocommerce' ); ?></th>
			<td><?php echo esc_html( $wdbtn_scope_label ); ?></td>
		</tr>
		<tr>
			<th align="left"><?php esc_html_e( 'Bestellnummer', 'widerrufsbutton-fuer-woocommerce' ); ?></th>
			<td>
				<?php
				echo esc_html( $wdbtn_ordernum );
				if ( '' !== $wdbtn_edit_link ) {
					echo ' (<a href="' . esc_url( $wdbtn_edit_link ) . '">' . esc_html__( 'Bestellung öffnen', 'widerrufsbutton-fuer-woocommerce' ) . '</a>)';
				}
				?>
			</td>
		</tr>
		<?php if ( '' !== $wdbtn_article ) : ?>
		<tr>
			<th align="left"><?php esc_html_e( 'Artikel', 'widerrufsbutton-fuer-woocommerce' ); ?></th>
			<td><?php echo esc_html( $wdbtn_article ); ?></td>
		</tr>
		<?php endif; ?>
		<tr>
			<th align="left"><?php esc_html_e( 'Name', 'widerrufsbutton-fuer-woocommerce' ); ?></th>
			<td><?php echo esc_html( $wdbtn_name ); ?></td>
		</tr>
		<tr>
			<th align="left"><?php esc_html_e( 'E-Mail', 'widerrufsbutton-fuer-woocommerce' ); ?></th>
			<td><?php echo esc_html( $wdbtn_email ); ?></td>
		</tr>
		<tr>
			<th align="left"><?php esc_html_e( 'Eingegangen am', 'widerrufsbutton-fuer-woocommerce' ); ?></th>
			<td><?php echo esc_html( $wdbtn_received ); ?></td>
		</tr>
		<?php if ( '' !== $wdbtn_reason ) : ?>
		<tr>
			<th align="left"><?php esc_html_e( 'Grund (optional)', 'widerrufsbutton-fuer-woocommerce' ); ?></th>
			<td><?php echo esc_html( $wdbtn_reason ); ?></td>
		</tr>
		<?php endif; ?>
	</table>

	<p><em><?php esc_html_e( 'Hinweis: Es erfolgte kein automatischer Statuswechsel und keine Rückerstattung.', 'widerrufsbutton-fuer-woocommerce' ); ?></em></p>
	<?php
	do_action( 'woocommerce_email_footer', $email );

}
