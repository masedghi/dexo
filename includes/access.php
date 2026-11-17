<?php

namespace Dexo;

defined( 'ABSPATH' ) || exit;

function role_specs(): array {
	return array(
		'dexo_orders' => array(
			__( 'Dexo · Orders', 'dexo' ),
			array( 'dexo_orders_read', 'dexo_orders_edit', 'dexo_orders_contact', 'dexo_orders_totals', 'dexo_orders_fulfill' ),
		),
		'dexo_warehouse' => array(
			__( 'Dexo · Warehouse', 'dexo' ),
			array( 'dexo_orders_read', 'dexo_orders_fulfill', 'dexo_products_read', 'dexo_products_stock' ),
		),
		'dexo_catalog' => array(
			__( 'Dexo · Catalog', 'dexo' ),
			array( 'dexo_products_read', 'dexo_products_prices', 'dexo_products_stock' ),
		),
	);
}

function install_roles(): void {
	foreach ( role_specs() as $slug => [ $label, $capabilities ] ) {
		add_role( $slug, $label, array_fill_keys( array_merge( array( 'read', 'dexo_access' ), $capabilities ), true ) );
	}
}

function is_manager(): bool {
	return current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
}

function can( string $capability ): bool {
	if ( current_user_can( $capability ) ) {
		return true;
	}
	$core_capabilities = array( 'dexo_access' );
	foreach ( role_specs() as [ , $capabilities ] ) {
		$core_capabilities = array_merge( $core_capabilities, $capabilities );
	}
	return in_array( $capability, $core_capabilities, true ) && is_manager();
}

function is_dedicated_staff(): bool {
	return is_user_logged_in() && ! is_manager() && (bool) array_intersect(
		array_keys( role_specs() ),
		wp_get_current_user()->roles
	);
}

function redirect_staff(): void {
	global $pagenow;
	if ( wp_doing_ajax() || 'admin-post.php' === $pagenow || ! is_dedicated_staff() ) {
		return;
	}
	wp_safe_redirect( panel_url() );
	exit;
}

function is_dexo_login(): bool {
	$target = $_POST['redirect_to'] ?? $_GET['redirect_to'] ?? '';
	return is_string( $target ) && wp_unslash( $target ) === panel_url();
}
