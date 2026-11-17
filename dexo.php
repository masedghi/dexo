<?php
/**
 * Plugin Name: Dexo
 * Plugin URI: https://github.com/masedghi/dexo
 * Description: A standalone operations dashboard for WooCommerce orders, product inventory, and store teams.
 * Version: 0.1.0
 * Requires at least: 6.8
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * Author: Dexo
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI: https://github.com/masedghi/dexo
 * Text Domain: dexo
 * Domain Path: /languages
 */

namespace Dexo;

defined( 'ABSPATH' ) || exit;

const VERSION = '0.1.0';
const FILE = __FILE__;

require_once __DIR__ . '/includes/access.php';
require_once __DIR__ . '/includes/panel.php';
require_once __DIR__ . '/includes/orders.php';
require_once __DIR__ . '/includes/products.php';

register_activation_hook( FILE, __NAMESPACE__ . '\\install_roles' );
add_action( 'init', static function (): void {
	load_plugin_textdomain( 'dexo', false, dirname( plugin_basename( FILE ) ) . '/languages' );
} );
add_action( 'before_woocommerce_init', static function (): void {
	if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', FILE, true );
	}
} );
add_action( 'template_redirect', __NAMESPACE__ . '\\render_panel', -100 );
add_action( 'admin_init', __NAMESPACE__ . '\\redirect_staff', 1 );
add_filter( 'show_admin_bar', static fn( $show ) => is_dedicated_staff() ? false : $show );
add_filter( 'plugin_action_links_' . plugin_basename( FILE ), static function ( array $links ): array {
	array_unshift( $links, '<a href="' . esc_url( panel_url() ) . '">' . esc_html__( 'Open Dexo', 'dexo' ) . '</a>' );
	return $links;
} );

add_action( 'login_enqueue_scripts', static function (): void {
	if ( is_dexo_login() ) {
		wp_enqueue_style( 'dexo-login', plugins_url( 'assets/login.css', FILE ), array(), VERSION );
	}
} );
add_filter( 'login_headerurl', static fn( $url ) => is_dexo_login() ? panel_url() : $url );
add_filter( 'login_headertext', static fn( $text ) => is_dexo_login() ? 'DEXO' : $text );
add_filter( 'login_title', static fn( $title ) => is_dexo_login() ? __( 'Sign in · Dexo', 'dexo' ) : $title );
add_filter( 'lostpassword_url', static function ( $url ) {
	return is_dexo_login() ? add_query_arg( 'redirect_to', panel_url(), $url ) : $url;
} );
