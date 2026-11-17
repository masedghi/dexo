<?php
// Run via WP-CLI on a disposable WordPress site.
if ( 'local' !== wp_get_environment_type() || ! class_exists( 'WP_CLI' ) ) {
	exit( 1 );
}

$users = array(
	'dexo_orders_test' => 'dexo_orders',
	'dexo_warehouse_test' => 'dexo_warehouse',
	'dexo_catalog_test' => 'dexo_catalog',
	'dexo_customer_test' => 'customer',
);
foreach ( $users as $login => $role ) {
	if ( username_exists( $login ) ) {
		WP_CLI::error( 'Use a fresh database.' );
	}
	$user = wp_insert_user( array(
		'user_login' => $login,
		'user_pass' => 'dexo-fixture-password-test-only',
		'user_email' => "$login@example.test",
		'role' => $role,
	) );
	if ( is_wp_error( $user ) ) {
		WP_CLI::error( $user->get_error_message() );
	}
}

update_option( 'woocommerce_manage_stock', 'yes' );
$product = new WC_Product_Simple();
$product->set_name( 'Canvas utility bag' );
$product->set_sku( 'DX-BAG' );
$product->set_regular_price( '45' );
$product->set_manage_stock( true );
$product->set_stock_quantity( 24 );
$product->save();

$parent = new WC_Product_Variable();
$parent->set_name( 'Field notebook' );
$parent->set_manage_stock( true );
$parent->set_stock_quantity( 40 );
$parent->save();

$variation = new WC_Product_Variation();
$variation->set_parent_id( $parent->get_id() );
$variation->set_sku( 'DX-NOTE' );
$variation->set_regular_price( '12' );
$variation->save();

$order = wc_create_order();
$order->set_billing_first_name( 'Sample' );
$order->set_billing_last_name( 'Contact' );
$order->set_billing_email( 'person@example.test' );
$order->set_billing_phone( '555-0109' );
$order->set_shipping_address_1( '22 Example Road' );
$order->set_shipping_city( 'Example City' );
$order->add_product( $product, 2 );
$order->calculate_totals();
$order->update_status( 'on-hold' );
$order->add_order_note( 'Synthetic private note' );

echo wp_json_encode( array(
	'order' => $order->get_id(),
	'product' => $product->get_id(),
	'parent' => $parent->get_id(),
	'variation' => $variation->get_id(),
	'stock' => wc_get_product( $product->get_id() )->get_stock_quantity(),
	'hpos' => \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(),
) ), "\n";
