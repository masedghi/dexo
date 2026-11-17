<?php

namespace Dexo;

defined( 'ABSPATH' ) || exit;

function order_statuses(): array {
	return wc_get_order_statuses();
}

function get_order( int $id ): ?\WC_Order {
	$order = $id ? wc_get_order( $id ) : false;
	return $order instanceof \WC_Order && 'shop_order' === $order->get_type() &&
		isset( order_statuses()[ 'wc-' . $order->get_status() ] ) ? $order : null;
}

function render_orders( int $id, bool $print ): void {
	if ( $id ) {
		$order = get_order( $id );
		if ( ! $order ) {
			echo '<p class="empty">' . esc_html__( 'Order unavailable.', 'dexo' ) . '</p>';
			return;
		}
		render_order( $order, $print );
		return;
	}
	$page = max( 1, request_id( $_GET, 'dx_page' ) );
	$lookup = ascii_digits( ltrim( request_text( $_GET, 'order' ), '# ' ) );
	$status = sanitize_key( request_text( $_GET, 'status' ) );
	$statuses = order_statuses();
	if ( ! isset( $statuses[ $status ] ) ) {
		$status = '';
	}
	if ( '' !== $lookup ) {
		$order = ctype_digit( $lookup ) ? get_order( (int) $lookup ) : null;
		$orders = $order && ( '' === $status || 'wc-' . $order->get_status() === $status ) ? array( $order ) : array();
		$pages = 1;
	} else {
		$results = wc_get_orders( array(
			'type' => 'shop_order',
			'status' => $status ?: array_keys( $statuses ),
			'limit' => 20,
			'page' => $page,
			'paginate' => true,
			'orderby' => 'date',
			'order' => 'DESC',
		) );
		$orders = $results->orders;
		$pages = (int) $results->max_num_pages;
	}
	?>
<form method="get" action="<?php echo esc_url( panel_url() ); ?>" class="filters">
	<?php hidden_route( 'orders' ); ?>
	<label><?php esc_html_e( 'Order ID', 'dexo' ); ?><input name="order" type="search" inputmode="numeric" value="<?php echo esc_attr( $lookup ); ?>"></label>
	<label><?php esc_html_e( 'Status', 'dexo' ); ?><select name="status">
		<option value=""><?php esc_html_e( 'All statuses', 'dexo' ); ?></option>
		<?php foreach ( $statuses as $key => $label ) : ?>
			<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $status, $key ); ?>><?php echo esc_html( $label ); ?></option>
		<?php endforeach; ?>
	</select></label>
	<button><?php esc_html_e( 'Filter orders', 'dexo' ); ?></button>
</form>
<?php if ( ! $orders ) : ?>
	<p class="empty"><?php esc_html_e( 'No orders found.', 'dexo' ); ?></p>
<?php else : ?>
	<div class="table-wrap"><table>
		<thead><tr><th scope="col"><?php esc_html_e( 'Order', 'dexo' ); ?></th><th scope="col"><?php esc_html_e( 'Date', 'dexo' ); ?></th><th scope="col"><?php esc_html_e( 'Status', 'dexo' ); ?></th><?php if ( can( 'dexo_orders_totals' ) ) : ?><th scope="col"><?php esc_html_e( 'Total', 'dexo' ); ?></th><?php endif; ?></tr></thead>
		<tbody>
		<?php foreach ( $orders as $order ) : ?>
			<tr>
				<th scope="row"><a href="<?php echo esc_url( panel_url( array( 'tab' => 'orders', 'id' => $order->get_id() ) ) ); ?>">#<?php echo esc_html( $order->get_order_number() ); ?></a></th>
				<td><?php echo esc_html( wc_format_datetime( $order->get_date_created() ) ); ?></td>
				<td><?php order_status_badge( $order ); ?></td>
				<?php if ( can( 'dexo_orders_totals' ) ) : ?><td><?php echo money( $order->get_total(), $order->get_currency() ); ?></td><?php endif; ?>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table></div>
<?php endif; ?>
	<?php
	pagination( array( 'tab' => 'orders', 'order' => $lookup, 'status' => $status ), $page, $pages );
}

function order_status_badge( \WC_Order $order ): void {
	printf( '<span class="badge %s">%s</span>', esc_attr( 'status-' . $order->get_status() ), esc_html( wc_get_order_status_name( $order->get_status() ) ) );
}

function render_order( \WC_Order $order, bool $print ): void {
	$id = $order->get_id();
	$contact = can( 'dexo_orders_contact' );
	$totals = can( 'dexo_orders_totals' );
	$fulfill = can( 'dexo_orders_fulfill' );
	$edit = can( 'dexo_orders_edit' );
	$tracking = (string) $order->get_meta( '_dexo_tracking' );
	$args = array( 'tab' => 'orders', 'id' => $id );
	?>
<div class="detail-header">
	<div>
		<?php if ( ! $print ) : ?><a class="back" href="<?php echo esc_url( panel_url( array( 'tab' => 'orders' ) ) ); ?>"><?php esc_html_e( 'All orders', 'dexo' ); ?></a><?php endif; ?>
		<h2>#<?php echo esc_html( $order->get_order_number() ); ?></h2>
		<p class="muted"><?php echo esc_html( wc_format_datetime( $order->get_date_created() ) ); ?></p>
	</div>
	<div class="actions">
		<?php order_status_badge( $order ); ?>
		<?php if ( $print ) : ?>
			<button type="button" id="dexo-print"><?php esc_html_e( 'Print', 'dexo' ); ?></button>
			<a class="button secondary" href="<?php echo esc_url( panel_url( $args ) ); ?>"><?php esc_html_e( 'Back to order', 'dexo' ); ?></a>
		<?php elseif ( $fulfill ) : ?>
			<a class="button secondary" href="<?php echo esc_url( panel_url( $args + array( 'print' => '1' ) ) ); ?>"><?php esc_html_e( 'Packing slip', 'dexo' ); ?></a>
		<?php endif; ?>
	</div>
</div>
<section class="card">
	<h3><?php esc_html_e( 'Order items', 'dexo' ); ?></h3>
	<div class="table-wrap"><table>
		<thead><tr><th scope="col"><?php esc_html_e( 'Item', 'dexo' ); ?></th><th scope="col"><?php esc_html_e( 'Quantity', 'dexo' ); ?></th><?php if ( ! $print && $totals ) : ?><th scope="col"><?php esc_html_e( 'Amount', 'dexo' ); ?></th><?php endif; ?></tr></thead>
		<tbody>
		<?php foreach ( $order->get_items() as $item ) : ?>
			<tr><th scope="row"><?php echo esc_html( $item->get_name() ); ?></th><td><?php echo esc_html( number_format_i18n( $item->get_quantity() ) ); ?></td>
			<?php if ( ! $print && $totals ) : ?><td><?php echo money( $item->get_total(), $order->get_currency() ); ?></td><?php endif; ?></tr>
		<?php endforeach; ?>
		</tbody>
	</table></div>
	<?php if ( ! $print && $totals ) : ?>
		<p class="total"><?php esc_html_e( 'Order total', 'dexo' ); ?> <strong><?php echo money( $order->get_total(), $order->get_currency() ); ?></strong></p>
	<?php endif; ?>
</section>
<?php if ( $contact ) : ?>
	<section class="card">
		<h3><?php esc_html_e( 'Customer', 'dexo' ); ?></h3>
		<?php $address = $order->get_formatted_shipping_address() ?: $order->get_formatted_billing_address(); ?>
		<?php if ( $address ) : ?><address><?php echo wp_kses( $address, array( 'br' => array() ) ); ?></address><?php endif; ?>
		<?php if ( ! $print ) : ?>
			<p><?php echo esc_html( $order->get_billing_email() ); ?> <bdi><?php echo esc_html( $order->get_billing_phone() ); ?></bdi></p>
		<?php endif; ?>
	</section>
<?php endif; ?>
<?php if ( $print ) { return; } ?>
<div class="detail-grid">
	<?php if ( $edit ) : ?>
		<form class="card edit" method="post" action="<?php echo esc_url( panel_url( $args ) ); ?>">
			<h3><?php esc_html_e( 'Order status', 'dexo' ); ?></h3>
			<?php edit_form_nonce( 'orders', $id ); ?>
			<input type="hidden" name="change" value="status">
			<input type="hidden" name="previous" value="<?php echo esc_attr( $order->get_status() ); ?>">
			<label><?php esc_html_e( 'New status', 'dexo' ); ?>
				<select name="status">
					<option value="<?php echo esc_attr( $order->get_status() ); ?>"><?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?></option>
					<?php foreach ( editable_order_statuses() as $key => $label ) : ?>
						<?php if ( $key !== 'wc-' . $order->get_status() ) : ?><option value="<?php echo esc_attr( substr( $key, 3 ) ); ?>"><?php echo esc_html( $label ); ?></option><?php endif; ?>
					<?php endforeach; ?>
				</select>
			</label>
			<button><?php esc_html_e( 'Save status', 'dexo' ); ?></button>
		</form>
	<?php endif; ?>
	<?php if ( $fulfill ) : ?>
		<form class="card edit" method="post" action="<?php echo esc_url( panel_url( $args ) ); ?>">
			<h3><?php esc_html_e( 'Shipment', 'dexo' ); ?></h3>
			<?php edit_form_nonce( 'orders', $id ); ?>
			<input type="hidden" name="change" value="tracking">
			<input type="hidden" name="previous" value="<?php echo esc_attr( $tracking ); ?>">
			<label><?php esc_html_e( 'Tracking code', 'dexo' ); ?><input name="tracking" maxlength="120" value="<?php echo esc_attr( $tracking ); ?>"></label>
			<?php $link = apply_filters( 'dexo_tracking_url', '', $tracking, $order ); ?>
			<?php if ( is_string( $link ) && esc_url( $link, array( 'http', 'https' ) ) ) : ?><a href="<?php echo esc_url( $link, array( 'http', 'https' ) ); ?>"><?php esc_html_e( 'Track shipment', 'dexo' ); ?></a><?php endif; ?>
			<button><?php esc_html_e( 'Save tracking code', 'dexo' ); ?></button>
		</form>
	<?php endif; ?>
</div>
<?php if ( $edit && $contact && $totals ) : ?>
	<section class="card">
		<h3><?php esc_html_e( 'Internal notes', 'dexo' ); ?></h3>
		<ul class="notes">
		<?php foreach ( wc_get_order_notes( array( 'order_id' => $id, 'type' => 'internal', 'limit' => 20 ) ) as $note ) : ?>
			<li><strong><?php echo esc_html( $note->added_by ); ?></strong> <span class="muted"><?php echo esc_html( wc_format_datetime( $note->date_created ) ); ?></span><div><?php echo nl2br( esc_html( $note->content ) ); ?></div></li>
		<?php endforeach; ?>
		</ul>
		<form class="edit" method="post" action="<?php echo esc_url( panel_url( $args ) ); ?>">
			<?php edit_form_nonce( 'orders', $id ); ?>
			<input type="hidden" name="change" value="note">
			<label><?php esc_html_e( 'Add an internal note', 'dexo' ); ?><textarea name="note" required maxlength="2000" rows="3"></textarea></label>
			<button><?php esc_html_e( 'Add note', 'dexo' ); ?></button>
		</form>
	</section>
<?php endif; ?>
	<?php
}

function editable_order_statuses(): array {
	return array_intersect_key( order_statuses(), array_flip( array( 'wc-pending', 'wc-processing', 'wc-on-hold', 'wc-completed', 'wc-cancelled' ) ) );
}

function submit_order( int $id, array $form ): ?\WP_Error {
	$change = request_text( $form, 'change' );
	$permissions = array(
		'status' => can( 'dexo_orders_edit' ),
		'tracking' => can( 'dexo_orders_fulfill' ),
		'note' => can( 'dexo_orders_edit' ) && can( 'dexo_orders_contact' ) && can( 'dexo_orders_totals' ),
	);
	if ( empty( $permissions[ $change ] ) ) {
		return form_error( __( 'You cannot make this change.', 'dexo' ), 403 );
	}
	$order = get_order( $id );
	if ( ! $order ) {
		return form_error( __( 'Order unavailable.', 'dexo' ), 404 );
	}

	try {
		switch ( $change ) {
			case 'status':
				$status = request_text( $form, 'status' );
				if ( $status === $order->get_status() ) {
					return null;
				}
				if ( 'refunded' === $order->get_status() || ! isset( editable_order_statuses()[ 'wc-' . $status ] ) ) {
					return form_error( __( 'This status change is unavailable in Dexo.', 'dexo' ) );
				}
				if ( request_text( $form, 'previous' ) !== $order->get_status() ) {
					return form_error( __( 'Another update changed this order. Review the current values and try again.', 'dexo' ), 409 );
				}
				if ( ! $order->update_status( $status, __( 'Updated in Dexo.', 'dexo' ), true ) ) {
					return form_error( __( 'The order status could not be saved.', 'dexo' ), 500 );
				}
				break;
			case 'tracking':
				$tracking = request_text( $form, 'tracking' );
				$previous = (string) $order->get_meta( '_dexo_tracking' );
				if ( $tracking === $previous ) {
					return null;
				}
				if ( ! isset( $form['tracking'] ) || ! is_string( $form['tracking'] ) || mb_strlen( $tracking ) > 120 ) {
					return form_error( __( 'Enter a tracking code of up to 120 characters.', 'dexo' ) );
				}
				if ( request_text( $form, 'previous' ) !== $previous ) {
					return form_error( __( 'Another update changed this order. Review the current values and try again.', 'dexo' ), 409 );
				}
				if ( '' === $tracking ) {
					$order->delete_meta_data( '_dexo_tracking' );
				} else {
					$order->update_meta_data( '_dexo_tracking', $tracking );
				}
				$order->save_meta_data();
				$order->add_order_note( __( 'Shipment tracking updated in Dexo.', 'dexo' ), false, true );
				break;
			case 'note':
				$note = isset( $form['note'] ) && is_string( $form['note'] ) ? sanitize_textarea_field( wp_unslash( $form['note'] ) ) : '';
				if ( '' === $note || mb_strlen( $note ) > 2000 ) {
					return form_error( __( 'Enter a note of up to 2,000 characters.', 'dexo' ) );
				}
				if ( ! $order->add_order_note( $note, false, true ) ) {
					return form_error( __( 'The note could not be saved.', 'dexo' ), 500 );
				}
				break;
		}
	} catch ( \Exception $exception ) {
		return form_error( __( 'WooCommerce could not save the change. Reload the order and check its current state.', 'dexo' ), 500 );
	}
	do_action( 'dexo_order_updated', $order, $change, get_current_user_id() );
	return null;
}
