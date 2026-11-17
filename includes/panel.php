<?php

namespace Dexo;

defined( 'ABSPATH' ) || exit;

function panel_url( array $args = array() ): string {
	$base = get_option( 'permalink_structure' ) ? home_url( '/dexo/' ) : add_query_arg( 'dexo', '1', home_url( '/' ) );
	return $args ? add_query_arg( $args, $base ) : $base;
}

function request_text( array $source, string $key ): string {
	return isset( $source[ $key ] ) && is_string( $source[ $key ] ) ? sanitize_text_field( wp_unslash( $source[ $key ] ) ) : '';
}

function request_id( array $source, string $key = 'id' ): int {
	$value = request_text( $source, $key );
	return ctype_digit( $value ) ? (int) $value : 0;
}

function ascii_digits( string $value ): string {
	return strtr( $value, array_combine(
		preg_split( '//u', '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', -1, PREG_SPLIT_NO_EMPTY ),
		str_split( '01234567890123456789' )
	) );
}

function is_panel_request(): bool {
	$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
	if ( ! in_array( $method, array( 'GET', 'HEAD', 'POST' ), true ) ) {
		return false;
	}
	$path = wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH );
	$base = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
	$pretty = wp_parse_url( home_url( '/dexo/' ), PHP_URL_PATH );
	return is_string( $path ) && (
		untrailingslashit( $path ) === untrailingslashit( $pretty ) ||
		( untrailingslashit( $path ) === untrailingslashit( $base ) && '1' === request_text( $_GET, 'dexo' ) )
	);
}

function sections(): array {
	$sections = array(
		'orders' => array(
			'label' => __( 'Orders', 'dexo' ), 'capability' => 'dexo_orders_read',
			'render' => __NAMESPACE__ . '\\render_orders', 'submit' => __NAMESPACE__ . '\\submit_order',
		),
		'products' => array(
			'label' => __( 'Products', 'dexo' ), 'capability' => 'dexo_products_read',
			'render' => __NAMESPACE__ . '\\render_products', 'submit' => __NAMESPACE__ . '\\submit_product',
		),
	);
	$registered = apply_filters( 'dexo_sections', $sections );
	return array_filter( $registered, static function ( $section ): bool {
		return is_array( $section ) && ! empty( $section['label'] ) &&
			! empty( $section['capability'] ) && is_string( $section['capability'] ) &&
			is_callable( $section['render'] ?? null ) && can( $section['capability'] );
	} );
}

function render_panel(): void {
	if ( ! is_panel_request() ) {
		return;
	}

	nocache_headers();
	header( 'X-Robots-Tag: noindex, nofollow' );
	header( 'X-Frame-Options: SAMEORIGIN' );
	header( 'Referrer-Policy: same-origin' );
	header( 'X-Content-Type-Options: nosniff' );

	if ( ! is_user_logged_in() ) {
		wp_safe_redirect( wp_login_url( panel_url() ) );
		exit;
	}
	if ( ! can( 'dexo_access' ) ) {
		status_header( 403 );
		render_layout( __( 'Access restricted', 'dexo' ), static function (): void {
			echo '<p>' . esc_html__( 'Ask your store administrator to assign a Dexo role to your account.', 'dexo' ) . '</p>';
		} );
		exit;
	}
	if ( ! function_exists( 'WC' ) ) {
		status_header( 503 );
		render_layout( __( 'WooCommerce is required', 'dexo' ), static function (): void {
			echo '<p>' . esc_html__( 'Activate WooCommerce to use the dashboard.', 'dexo' ) . '</p>';
		} );
		exit;
	}

	$sections = sections();
	$tab = sanitize_key( request_text( $_GET, 'tab' ) ) ?: array_key_first( $sections );
	if ( ! isset( $sections[ $tab ] ) ) {
		status_header( 404 );
		render_layout( __( 'Page unavailable', 'dexo' ), static function (): void {
			echo '<p>' . esc_html__( 'This page is unavailable for your account.', 'dexo' ) . '</p>';
		}, $sections );
		exit;
	}

	$section = $sections[ $tab ];
	$id = request_id( $_GET );
	$error = null;
	status_header( 200 );
	if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
		$nonce = request_text( $_POST, '_dexo_nonce' );
		if ( ! wp_verify_nonce( $nonce, "dexo_{$tab}_{$id}" ) ) {
			$error = new \WP_Error( 'nonce', __( 'Your form expired. Reload the page and try again.', 'dexo' ), 403 );
		} elseif ( ! is_callable( $section['submit'] ?? null ) ) {
			$error = new \WP_Error( 'action', __( 'This page does not accept changes.', 'dexo' ), 405 );
		} else {
			$error = submit_section( $section['submit'], $tab, $id );
			if ( ! is_wp_error( $error ) ) {
				wp_safe_redirect( panel_url( array( 'tab' => $tab, 'id' => $id, 'saved' => '1' ) ), 303 );
				exit;
			}
		}
		status_header( (int) ( $error->get_error_data() ?: 400 ) );
	}

	$is_print = 'orders' === $tab && '1' === request_text( $_GET, 'print' );
	if ( $is_print && ( ! can( 'dexo_orders_fulfill' ) || ! get_order( $id ) ) ) {
		status_header( 404 );
		render_layout( __( 'Page unavailable', 'dexo' ), static function (): void {
			echo '<p>' . esc_html__( 'This page is unavailable for your account.', 'dexo' ) . '</p>';
		}, $sections, $tab );
		exit;
	}
	render_layout( $is_print ? __( 'Packing slip', 'dexo' ) : $section['label'], static function () use ( $section, $id, $error, $is_print ): void {
		if ( $error ) {
			echo '<p class="notice error" role="alert">' . esc_html( $error->get_error_message() ) . '</p>';
		} elseif ( '1' === request_text( $_GET, 'saved' ) ) {
			echo '<p class="notice" role="status">' . esc_html__( 'Changes saved.', 'dexo' ) . '</p>';
		}
		$section['render']( $id, $is_print );
	}, $sections, $tab, $is_print );
	exit;
}

function render_layout( string $title, callable $content, array $sections = array(), string $active = '', bool $print = false ): void {
	$locale = str_replace( '_', '-', get_locale() );
	$rtl = is_rtl() || preg_match( '/^(fa|ar|he|ur)(-|$)/i', $locale );
	?>
<!doctype html>
<html lang="<?php echo esc_attr( $locale ); ?>" dir="<?php echo $rtl ? 'rtl' : 'ltr'; ?>">
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php echo esc_html( $title ); ?> · DEXO</title>
	<link rel="stylesheet" href="<?php echo esc_url( add_query_arg( 'ver', VERSION, plugins_url( 'assets/panel.css', FILE ) ) ); ?>">
	<?php if ( $print ) : ?>
		<script defer src="<?php echo esc_url( add_query_arg( 'ver', VERSION, plugins_url( 'assets/print.js', FILE ) ) ); ?>"></script>
	<?php endif; ?>
	<?php do_action( 'dexo_head' ); ?>
</head>
<body<?php echo $print ? ' class="print-view"' : ''; ?>>
<a class="skip-link" href="#main"><?php esc_html_e( 'Skip to content', 'dexo' ); ?></a>
<div class="shell">
	<aside class="sidebar">
		<a class="brand" href="<?php echo esc_url( panel_url() ); ?>" aria-label="Dexo"><span class="mark" aria-hidden="true">D</span><span>DEXO</span></a>
		<div class="store"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></div>
		<nav aria-label="<?php esc_attr_e( 'Dashboard', 'dexo' ); ?>">
		<?php foreach ( $sections as $slug => $section ) : ?>
			<a href="<?php echo esc_url( panel_url( array( 'tab' => $slug ) ) ); ?>"<?php echo $active === $slug ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $section['label'] ); ?></a>
		<?php endforeach; ?>
		</nav>
		<div class="identity">
			<strong><?php echo esc_html( wp_get_current_user()->display_name ); ?></strong>
			<a href="<?php echo esc_url( wp_logout_url( panel_url() ) ); ?>"><?php esc_html_e( 'Sign out', 'dexo' ); ?></a>
		</div>
	</aside>
	<main class="main" id="main">
		<header class="page-header"><h1><?php echo esc_html( $title ); ?></h1></header>
		<?php $content(); ?>
	</main>
</div>
</body>
</html>
	<?php
}

function hidden_route( string $tab ): void {
	echo '<input type="hidden" name="tab" value="' . esc_attr( $tab ) . '">';
	if ( ! get_option( 'permalink_structure' ) ) {
		echo '<input type="hidden" name="dexo" value="1">';
	}
}

function edit_form_nonce( string $tab, int $id ): void {
	wp_nonce_field( "dexo_{$tab}_{$id}", '_dexo_nonce', false );
}

function form_error( string $message, int $status = 400 ): \WP_Error {
	return new \WP_Error( 'dexo_form', $message, $status );
}

function submit_section( callable $submit, string $tab, int $id ): ?\WP_Error {
	if ( ! $id || ! in_array( $tab, array( 'orders', 'products' ), true ) ) {
		return $submit( $id, $_POST );
	}
	$lock = sprintf( 'dexo_write_lock_%s_%d', $tab, $id );
	// The constant value lets the unique option key reject simultaneous writers.
	if ( ! add_option( $lock, '1', '', false ) ) {
		return form_error( __( 'This record is being updated. Try again in a moment.', 'dexo' ), 409 );
	}
	try {
		return $submit( $id, $_POST );
	} finally {
		delete_option( $lock );
	}
}

function pagination( array $args, int $page, int $pages ): void {
	if ( $page <= 1 && $pages <= 1 ) {
		return;
	}
	echo '<nav class="pagination" aria-label="' . esc_attr__( 'Pagination', 'dexo' ) . '">';
	if ( $page > 1 ) {
		echo '<a class="button secondary" href="' . esc_url( panel_url( $args + array( 'dx_page' => max( 1, min( $page - 1, $pages ) ) ) ) ) . '">' . esc_html__( 'Previous', 'dexo' ) . '</a>';
	}
	/* translators: 1: current page, 2: total pages. */
	echo '<span>' . esc_html( sprintf( __( 'Page %1$s of %2$s', 'dexo' ), number_format_i18n( $page ), number_format_i18n( max( 1, $pages ) ) ) ) . '</span>';
	if ( $page < $pages ) {
		echo '<a class="button secondary" href="' . esc_url( panel_url( $args + array( 'dx_page' => $page + 1 ) ) ) . '">' . esc_html__( 'Next', 'dexo' ) . '</a>';
	}
	echo '</nav>';
}

function money( string $amount, string $currency = '' ): string {
	$args = '' === $currency ? array() : array( 'currency' => $currency );
	return wp_kses( wc_price( $amount, $args ), array( 'span' => array( 'class' => true ), 'bdi' => array() ) );
}
