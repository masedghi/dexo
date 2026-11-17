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
	$content = static function () use ( $section, $id, $error, $is_print ): void {
		if ( $error ) {
			echo '<p class="notice error" role="alert">' . esc_html( $error->get_error_message() ) . '</p>';
		} elseif ( '1' === request_text( $_GET, 'saved' ) ) {
			echo '<p class="notice" role="status">' . esc_html__( 'Changes saved.', 'dexo' ) . '</p>';
		}
		$section['render']( $id, $is_print );
	};
	$body = $id && ! $is_print && in_array( $tab, array( 'orders', 'products' ), true )
		? static fn() => render_editor( $tab, $id, $section, $content )
		: $content;
	render_layout( $is_print ? __( 'Packing slip', 'dexo' ) : $section['label'], $body, $sections, $tab, $is_print );
	exit;
}

function icon( string $name, int $size = 18 ): void {
	$paths = array(
		'orders' => '<rect x="4" y="5" width="16" height="16" rx="2"/><path d="M4 9h16m-11 4a3 3 0 0 0 6 0"/>',
		'products' => '<path d="m12 3 8 4.5v9L12 21l-8-4.5v-9L12 3Zm8 4.5L12 12l-8-4.5m8 4.5v9M8 5.25l8 4.5"/>',
		'search' => '<circle cx="11" cy="11" r="6"/><path d="m16 16 4 4"/>',
		'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
		'close' => '<path d="m6 6 12 12M18 6 6 18"/>',
		'chevron' => '<path d="m9 6 6 6-6 6"/>',
		'external' => '<path d="M7 17 17 7M7 7h10v10"/>',
		'logout' => '<path d="M10 17l5-5-5-5m5 5H3m9-8h7a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-7"/>',
		'lock' => '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4m-4 4v2"/>',
	);
	if ( isset( $paths[ $name ] ) ) {
		printf( '<svg width="%d" height="%d" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.65" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">%s</svg>', $size, $size, $paths[ $name ] );
	}
}

function brand_wordmark(): void {
	echo '<span class="wordmark" dir="ltr">DEXO</span>';
}

function render_layout( string $title, callable $content, array $sections = array(), string $active = '', bool $print = false ): void {
	$locale = str_replace( '_', '-', get_locale() );
	$rtl = is_rtl() || preg_match( '/^(fa|ar|he|ur)(-|$)/i', $locale );
	$name = wp_get_current_user()->display_name;
	$initial = strtoupper( mb_substr( $name, 0, 1 ) );
	?>
<!doctype html>
<html lang="<?php echo esc_attr( $locale ); ?>" dir="<?php echo $rtl ? 'rtl' : 'ltr'; ?>">
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php echo esc_html( $title ); ?> · DEXO</title>
	<link rel="stylesheet" href="<?php echo esc_url( add_query_arg( 'ver', VERSION, plugins_url( 'assets/panel.css', FILE ) ) ); ?>">
	<script defer src="<?php echo esc_url( add_query_arg( 'ver', VERSION, plugins_url( 'assets/panel.js', FILE ) ) ); ?>"></script>
	<?php if ( $print ) : ?>
		<script defer src="<?php echo esc_url( add_query_arg( 'ver', VERSION, plugins_url( 'assets/print.js', FILE ) ) ); ?>"></script>
	<?php endif; ?>
	<?php do_action( 'dexo_head' ); ?>
</head>
<body<?php echo $print ? ' class="print-view"' : ''; ?>>
<a class="skip-link" href="#main"><?php esc_html_e( 'Skip to content', 'dexo' ); ?></a>
<div class="app-shell">
	<button type="button" class="sidebar-backdrop" aria-label="<?php esc_attr_e( 'Close menu', 'dexo' ); ?>" hidden></button>
	<aside class="sidebar" id="dexo-sidebar">
		<a class="brand" href="<?php echo esc_url( panel_url() ); ?>">
			<?php brand_wordmark(); ?>
			<span class="brand-label"><?php esc_html_e( 'Store management', 'dexo' ); ?></span>
		</a>
		<div class="workspace">
			<span class="workspace-mark" aria-hidden="true">D</span>
			<div><b><?php echo esc_html( get_bloginfo( 'name' ) ); ?></b><small><?php esc_html_e( 'Team workspace', 'dexo' ); ?></small></div>
			<span class="live-dot" aria-hidden="true"></span>
		</div>
		<nav aria-label="<?php esc_attr_e( 'Dashboard', 'dexo' ); ?>">
			<?php if ( $sections ) : ?><p class="nav-label"><?php esc_html_e( 'Store', 'dexo' ); ?></p><?php endif; ?>
			<?php foreach ( $sections as $slug => $section ) : ?>
				<a href="<?php echo esc_url( panel_url( array( 'tab' => $slug ) ) ); ?>"<?php echo $active === $slug ? ' aria-current="page"' : ''; ?>>
					<?php icon( $slug, 19 ); ?>
					<span><?php echo esc_html( $section['label'] ); ?></span>
					<?php if ( $active === $slug ) : ?><span class="nav-indicator" aria-hidden="true"></span><?php endif; ?>
				</a>
			<?php endforeach; ?>
		</nav>
		<?php if ( '' !== $name ) : ?>
			<div class="sidebar-foot">
				<span class="avatar" aria-hidden="true"><?php echo esc_html( $initial ); ?></span>
				<div><b><?php echo esc_html( $name ); ?></b><small><?php echo esc_html( is_manager() ? __( 'Store manager', 'dexo' ) : __( 'Team member', 'dexo' ) ); ?></small></div>
				<a class="icon-button sign-out" aria-label="<?php esc_attr_e( 'Sign out', 'dexo' ); ?>" href="<?php echo esc_url( wp_logout_url( panel_url() ) ); ?>"><?php icon( 'logout', 17 ); ?></a>
			</div>
		<?php endif; ?>
	</aside>
	<div class="main-shell">
		<header class="topbar">
			<div class="breadcrumb">
				<button type="button" class="icon-button mobile-menu" aria-controls="dexo-sidebar" aria-expanded="false" aria-label="<?php esc_attr_e( 'Open menu', 'dexo' ); ?>"><?php icon( 'menu' ); ?></button>
				<span><?php esc_html_e( 'Dexo workspace', 'dexo' ); ?></span>
				<span class="direction-icon"><?php icon( 'chevron', 14 ); ?></span>
				<b><?php echo esc_html( $title ); ?></b>
			</div>
			<div class="top-actions">
				<span class="today"><?php echo esc_html( wp_date( 'l, j F' ) ); ?></span>
				<a class="store-link" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'View store', 'dexo' ); ?><?php icon( 'external', 16 ); ?></a>
			</div>
		</header>
		<main class="main" id="main">
			<header class="page-heading">
				<div><span class="eyebrow">DEXO WORKSPACE</span><h1><?php echo esc_html( $title ); ?></h1></div>
				<?php if ( $sections ) : ?><p class="heading-note"><span class="live-dot" aria-hidden="true"></span><?php esc_html_e( 'Connected to your store', 'dexo' ); ?></p><?php endif; ?>
			</header>
			<?php $content(); ?>
		</main>
	</div>
</div>
</body>
</html>
	<?php
}

function render_editor( string $tab, int $id, array $section, callable $content ): void {
	$section['render']( 0, false );
	$close = panel_url( array( 'tab' => $tab ) );
	$label = 'orders' === $tab ? __( 'Order', 'dexo' ) : __( 'Product', 'dexo' );
	?>
	<dialog class="panel-dialog" id="dexo-editor" aria-labelledby="dexo-editor-title" data-return="<?php echo esc_url( $close ); ?>" open>
		<header>
			<h2 id="dexo-editor-title" tabindex="-1" autofocus><?php echo esc_html( $label ); ?> <bdi>#<?php echo esc_html( number_format_i18n( $id ) ); ?></bdi></h2>
			<a class="icon-button" href="<?php echo esc_url( $close ); ?>" aria-label="<?php esc_attr_e( 'Close details', 'dexo' ); ?>"><?php icon( 'close' ); ?></a>
		</header>
		<div class="editor-body"><?php $content(); ?></div>
	</dialog>
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
