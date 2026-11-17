<?php
// Run using WP-CLI on a disposable local site. Every created order is removed.
if ( 'local' !== wp_get_environment_type() || ! class_exists( 'WP_CLI' ) ) { exit( 1 ); }
function report_assert( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } }
$original = array( get_option( 'woocommerce_currency' ), get_option( 'woocommerce_price_num_decimals' ), get_option( 'timezone_string' ) );
$ids = array();
try {
	update_option( 'woocommerce_currency', 'USD' ); update_option( 'woocommerce_price_num_decimals', 2 ); update_option( 'timezone_string', 'America/New_York' );
	wp_set_current_user( 1 );
	$create = static function( $status = 'completed', $currency = 'USD', $at = '2012-03-11 00:01:00' ) use ( &$ids ) {
		$order = wc_create_order(); $ids[] = $order->get_id();
		$order->set_currency( $currency ); $order->set_date_created( new WC_DateTime( $at, new DateTimeZone( 'America/New_York' ) ) );
		$item = new WC_Order_Item_Product(); $item->set_name( 'Report test item' ); $item->set_quantity( 1 ); $item->set_total( 100 ); $item->set_taxes( array( 'total' => array( 1 => 10 ), 'subtotal' => array( 1 => 10 ) ) ); $order->add_item( $item );
		$shipping = new WC_Order_Item_Shipping(); $shipping->set_total( 20 ); $shipping->set_taxes( array( 'total' => array( 1 => 2 ) ) ); $order->add_item( $shipping );
		$order->set_shipping_total( 20 ); $order->set_shipping_tax( 2 ); $order->set_cart_tax( 10 ); $order->set_total( 132 ); $order->set_status( $status ); $order->save(); return $order;
	};
	$paid = $create();
	$partial = $create(); $itemid = array_key_first( $partial->get_items() );
	wc_create_refund( array( 'order_id' => $partial->get_id(), 'amount' => 11, 'line_items' => array( $itemid => array( 'qty' => 0, 'refund_total' => 10, 'refund_tax' => array( 1 => 1 ) ) ), 'refund_payment' => false, 'restock_items' => false ) );
	$full = $create(); wc_create_refund( array( 'order_id' => $full->get_id(), 'amount' => 132, 'refund_payment' => false ) );
	$ship = $create(); $shippingid = array_key_first( $ship->get_items( 'shipping' ) );
	wc_create_refund( array( 'order_id' => $ship->get_id(), 'amount' => 22, 'line_items' => array( $shippingid => array( 'qty' => 0, 'refund_total' => 20, 'refund_tax' => array( 1 => 2 ) ) ), 'refund_payment' => false ) );
	$create( 'pending' ); $create( 'completed', 'EUR' );
	$create( 'completed', 'USD', '2012-03-10 23:59:59' );
	$create( 'completed', 'USD', '2012-03-12 00:00:00' );
	$range = Dexo\report_range( array( 'from' => '2012-03-11', 'to' => '2012-03-11' ) );
	report_assert( $range['end'] - $range['start'] + 1 === 23 * HOUR_IN_SECONDS, 'DST day must contain 23 hours.' );
	$job = Dexo\report_start( $range ); $job = Dexo\report_batch( $job );
	report_assert( $job['done'] && 5 === $job['orders'] && 1 === $job['foreign'], 'Date boundaries, status counts and currency exclusion.' );
	report_assert( 29000 === $job['sales'] && 11000 === $job['refunds'] && 4 === $job['paid'], 'Net sales: 100 + 90 + 0 + 100; refunds: 10 + 100. Shipping and tax excluded.' );
	report_assert( 29000 === $job['days']['2012-03-11']['sales'], 'Daily sales equal total sales.' );
	foreach ( array( array( 'from'=>'2026-02-30', 'to'=>'2026-03-01' ), array( 'from'=>'2010-01-01', 'to'=>'2012-01-01' ), array( 'from'=>'2012-01-02','to'=>'2012-01-01' ) ) as $invalid ) {
		try { Dexo\report_range( $invalid ); throw new RuntimeException( 'Accepted an invalid range.' ); } catch ( InvalidArgumentException $expected ) {}
	}
	// Verify bounded pagination without depending on the store's order count.
	for ( $i = 0; $i < 101; $i++ ) { $create( 'pending', 'USD', '2012-04-01 12:00:00' ); }
	$job = Dexo\report_start( Dexo\report_range( array( 'from'=>'2012-04-01', 'to'=>'2012-04-01' ) ) );
	$job = Dexo\report_batch( $job ); report_assert( ! $job['done'] && 100 === $job['processed'], 'Process at most 100 orders per batch.' );
	$job = Dexo\report_batch( $job ); report_assert( $job['done'] && 101 === $job['orders'] && 0 === $job['sales'], 'Complete all pages before publishing totals.' );
	$again = Dexo\report_batch( $job ); report_assert( $again === $job, 'Completed retries must not double count.' );
	foreach ( array( 'dexo_warehouse_test', 'dexo_catalog_test', 'dexo_orders_test', 'dexo_customer_test' ) as $login ) {
		$user = get_user_by( 'login', $login ); wp_set_current_user( $user->ID );
		report_assert( ! isset( Dexo\sections()['reports'] ) && isset( Dexo\sections()['overview'] ), 'Overview allowed; financial report hidden from ' . $login );
		try { Dexo\report_batch( $job ); throw new RuntimeException( 'Unauthorized batch accepted.' ); } catch ( RuntimeException $e ) { report_assert( 'Forbidden' === $e->getMessage(), 'Permission rechecked on every batch.' ); }
		ob_start(); Dexo\render_overview(); $html = ob_get_clean(); report_assert( ! str_contains( $html, 'report-metrics' ) && ! str_contains( $html, 'report-chart' ), 'No financial overview for ' . $login );
	}
	wp_set_current_user( 1 );
	$job['owner'] = -1;
	try { Dexo\report_batch( $job ); throw new RuntimeException( 'Another account accepted.' ); } catch ( RuntimeException $e ) { report_assert( 'Forbidden' === $e->getMessage(), 'Jobs belong to one account.' ); }
	echo 'Passed Dexo reports: DST boundaries, partial/full/shipping refunds, mixed currency, pagination, retry, and role/ownership restrictions. HPOS=' . ( wc_get_container()->get( Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled() ? 'yes' : 'no' ) . "\n";
} finally {
	foreach ( $ids as $id ) { $order = wc_get_order( $id ); if ( $order ) { $order->delete( true ); } }
	update_option( 'woocommerce_currency', $original[0] ); update_option( 'woocommerce_price_num_decimals', $original[1] ); update_option( 'timezone_string', $original[2] );
}
