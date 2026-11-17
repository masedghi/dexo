<?php
/** Capability-scoped, storage-independent store overview and creation-cohort reports. */
namespace Dexo;
defined( 'ABSPATH' ) || exit;

function report_range( array $input ): array {
	$today = new \DateTimeImmutable( 'today', wp_timezone() );
	$from = request_text( $input, 'from' ) ?: $today->modify( '-29 days' )->format( 'Y-m-d' );
	$to = request_text( $input, 'to' ) ?: $today->format( 'Y-m-d' );
	$a = \DateTimeImmutable::createFromFormat( '!Y-m-d', $from, wp_timezone() );
	$b = \DateTimeImmutable::createFromFormat( '!Y-m-d', $to, wp_timezone() );
	if ( ! $a || ! $b || $a->format( 'Y-m-d' ) !== $from || $b->format( 'Y-m-d' ) !== $to || $a > $b || $a->diff( $b )->days > 365 || $b > $today ) {
		throw new \InvalidArgumentException( __( 'Choose a valid date range of up to 366 days, ending today or earlier.', 'dexo' ) );
	}
	return array( 'from' => $from, 'to' => $to, 'start' => $a->getTimestamp(), 'end' => min( time(), $b->modify( '+1 day' )->getTimestamp() - 1 ) );
}

function report_minor( $amount ): int {
	return (int) round( (float) $amount * pow( 10, wc_get_price_decimals() ) );
}
function report_money( int $minor ): string {
	return money( (string) ( $minor / pow( 10, wc_get_price_decimals() ) ), get_woocommerce_currency() );
}
function report_key( string $token ): string { return 'dexo_report_' . get_current_user_id() . '_' . $token; }
function report_start( array $range ): array {
	if ( ! current_user_can( 'view_woocommerce_reports' ) ) { throw new \RuntimeException( 'Forbidden' ); }
	$days = array();
	for ( $d = new \DateTimeImmutable( $range['from'], wp_timezone() ); $d->format( 'Y-m-d' ) <= $range['to']; $d = $d->modify( '+1 day' ) ) {
		$days[ $d->format( 'Y-m-d' ) ] = array( 'orders' => 0, 'sales' => 0, 'refunds' => 0 );
	}
	return array( 'range' => $range, 'owner' => get_current_user_id(), 'currency' => get_woocommerce_currency(), 'decimals' => wc_get_price_decimals(), 'page' => 1, 'processed' => 0, 'total' => 0, 'done' => false, 'orders' => 0, 'paid' => 0, 'sales' => 0, 'refunds' => 0, 'foreign' => 0, 'statuses' => array(), 'products' => array(), 'days' => $days, 'started' => time() );
}
function report_accumulate( array &$job, \WC_Order $order ): void {
	if ( $order->get_currency() !== $job['currency'] ) { ++$job['foreign']; return; }
	$date = $order->get_date_created();
	if ( ! $date ) { return; }
	$day = wp_date( 'Y-m-d', $date->getTimestamp(), wp_timezone() );
	if ( ! isset( $job['days'][ $day ] ) ) { return; }
	++$job['orders'];
	++$job['days'][ $day ]['orders'];
	$status = $order->get_status();
	$job['statuses'][ $status ] = ( $job['statuses'][ $status ] ?? 0 ) + 1;
	if ( ! in_array( $status, array_merge( wc_get_is_paid_statuses(), array( 'refunded' ) ), true ) ) { return; }
	++$job['paid'];
	$base = max( 0, report_minor( $order->get_total() ) - report_minor( $order->get_total_tax() ) - report_minor( $order->get_shipping_total() ) );
	$refund = max( 0, report_minor( $order->get_total_refunded() ) - report_minor( $order->get_total_tax_refunded() ) - report_minor( $order->get_total_shipping_refunded() ) );
	$full = report_minor( $order->get_total_refunded() ) >= report_minor( $order->get_total() ) && report_minor( $order->get_total_refunded() ) > 0;
	$refund = $full ? $base : min( $base, $refund );
	$job['sales'] += $base - $refund;
	$job['refunds'] += $refund;
	$job['days'][ $day ]['sales'] += $base - $refund;
	$job['days'][ $day ]['refunds'] += $refund;
	foreach ( $order->get_items() as $id => $item ) {
		$key = $item->get_variation_id() ?: $item->get_product_id();
		if ( ! $key ) { continue; }
		$qty = $full ? 0 : max( 0, $item->get_quantity() + $order->get_qty_refunded_for_item( $id ) );
		if ( ! isset( $job['products'][ $key ] ) ) { $job['products'][ $key ] = array( 'name' => $item->get_name(), 'qty' => 0 ); }
		$job['products'][ $key ]['qty'] += $qty;
	}
}
function report_batch( array $job ): array {
	if ( ! current_user_can( 'view_woocommerce_reports' ) || get_current_user_id() !== $job['owner'] ) { throw new \RuntimeException( 'Forbidden' ); }
	if ( $job['currency'] !== get_woocommerce_currency() || $job['decimals'] !== wc_get_price_decimals() ) { throw new \RuntimeException( __( 'Store currency changed. Refresh the report.', 'dexo' ) ); }
	if ( $job['done'] ) { return $job; }
	$result = wc_get_orders( array( 'type' => 'shop_order', 'status' => array_keys( wc_get_order_statuses() ), 'date_created' => $job['range']['start'] . '...' . $job['range']['end'], 'limit' => 100, 'page' => $job['page'], 'paginate' => true, 'orderby' => 'ID', 'order' => 'ASC' ) );
	$job['total'] = (int) $result->total;
	foreach ( $result->orders as $order ) { report_accumulate( $job, $order ); ++$job['processed']; }
	++$job['page'];
	$job['done'] = count( $result->orders ) < 100 || $job['page'] > $result->max_num_pages;
	if ( $job['done'] ) { $job['finished'] = time(); }
	return $job;
}
function report_ajax(): void {
	if ( ! current_user_can( 'view_woocommerce_reports' ) ) { wp_send_json_error( 'Forbidden', 403 ); }
	check_ajax_referer( 'dexo_report', 'security' );
	$token = request_text( $_POST, 'token' );
	if ( ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) { wp_send_json_error( 'Invalid report', 400 ); }
	$key = report_key( $token );
	$job = get_transient( $key );
	if ( ! is_array( $job ) ) { wp_send_json_error( __( 'Report expired. Reload the page.', 'dexo' ), 410 ); }
	// Serialise duplicate requests; a retry may safely resume the same job.
	$lock = $key . '_lock';
	if ( ! add_option( $lock, time(), '', false ) ) {
		if ( (int) get_option( $lock ) < time() - 120 ) { delete_option( $lock ); }
		wp_send_json_error( 'Report busy; retry.', 409 );
	}
	try {
		$job = report_batch( $job );
		set_transient( $key, $job, 15 * MINUTE_IN_SECONDS );
		delete_option( $lock );
		wp_send_json_success( array( 'done' => $job['done'], 'processed' => $job['processed'], 'total' => $job['total'] ) );
	} catch ( \Throwable $e ) {
		delete_option( $lock );
		wp_send_json_error( __( 'Could not finish the report. Reload to retry.', 'dexo' ), 500 );
	}
}
add_action( 'wp_ajax_dexo_report', __NAMESPACE__ . '\\report_ajax' );

function report_for_range( array $range ): array {
	$token = substr( hash_hmac( 'sha256', wp_json_encode( array( $range['from'], $range['to'], get_woocommerce_currency(), wc_get_price_decimals() ) ), wp_salt() ), 0, 32 );
	$key = report_key( $token );
	$job = get_transient( $key );
	if ( ! is_array( $job ) || ( $job['done'] && $job['finished'] < time() - 300 ) ) {
		$job = report_start( $range );
		// The same bounded worker handles small stores without a loading screen.
		$job = report_batch( $job );
		set_transient( $key, $job, 15 * MINUTE_IN_SECONDS );
	} elseif ( ! $job['done'] ) {
		$lock = $key . '_lock';
		if ( add_option( $lock, time(), '', false ) ) {
			try {
				$job = report_batch( get_transient( $key ) ?: $job );
				set_transient( $key, $job, 15 * MINUTE_IN_SECONDS );
			} finally { delete_option( $lock ); }
		} elseif ( (int) get_option( $lock ) < time() - 120 ) { delete_option( $lock ); }
	}
	$job['token'] = $token;
	return $job;
}
function report_metric( string $label, string $value, string $note = '' ): void {
	echo '<article class="report-metric"><span>' . esc_html( $label ) . '</span><strong>' . wp_kses_post( $value ) . '</strong><small>' . esc_html( $note ) . '</small></article>';
}
function render_report_results( array $job ): void {
	if ( ! $job['done'] ) {
		echo '<section class="report-card report-loading" data-report-token="' . esc_attr( $job['token'] ) . '" data-endpoint="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '" data-security="' . esc_attr( wp_create_nonce( 'dexo_report' ) ) . '"><h2>' . esc_html__( 'Preparing your report', 'dexo' ) . '</h2><p role="status">' . esc_html__( 'Processing orders in batches. Totals appear when all orders have been processed.', 'dexo' ) . '</p><progress max="' . esc_attr( $job['total'] ?: 1 ) . '" value="' . esc_attr( $job['processed'] ) . '"></progress><a href="">' . esc_html__( 'Reload to continue', 'dexo' ) . '</a></section>';
		return;
	}
	?><div class="report-metrics"><?php
	report_metric( __( 'Net sales', 'dexo' ), report_money( $job['sales'] ), __( 'Excluding shipping and tax', 'dexo' ) );
	report_metric( __( 'Orders placed', 'dexo' ), number_format_i18n( $job['orders'] ), __( 'All order statuses', 'dexo' ) );
	report_metric( __( 'Average net order', 'dexo' ), report_money( $job['paid'] ? (int) round( $job['sales'] / $job['paid'] ) : 0 ), __( 'Paid and refunded orders', 'dexo' ) );
	report_metric( __( 'Refunds', 'dexo' ), report_money( $job['refunds'] ), __( 'Excluding shipping and tax', 'dexo' ) );
	?></div><div class="report-grid"><section class="report-card report-trend"><div class="report-card-heading"><h2><?php esc_html_e( 'Sales trend', 'dexo' ); ?></h2><span><?php echo esc_html( $job['currency'] ); ?></span></div><?php
	$values = array_column( $job['days'], 'sales' ); $max = max( 1, max( $values ) ); $count = count( $values ); $points = array();
	foreach ( $values as $i => $value ) { $points[] = ( 20 + $i * 760 / max( 1, $count - 1 ) ) . ',' . ( 225 - $value * 185 / $max ); }
	$path = implode( ' ', $points );
	?><svg class="report-chart" viewBox="0 0 800 260" role="img" aria-label="<?php esc_attr_e( 'Daily net sales; exact values in the table below.', 'dexo' ); ?>" direction="ltr"><defs><linearGradient id="dexo-fill" x1="0" y1="0" x2="0" y2="1"><stop stop-color="#91aa70" stop-opacity=".32"/><stop offset="1" stop-color="#91aa70" stop-opacity="0"/></linearGradient></defs><path d="M20 40H780M20 100H780M20 160H780M20 225H780" stroke="#e9ede4" fill="none"/><polygon points="20,225 <?php echo esc_attr( $path ); ?> 780,225" fill="url(#dexo-fill)"/><polyline points="<?php echo esc_attr( $path ); ?>" stroke="#587945" stroke-width="3.5" stroke-linejoin="round" stroke-linecap="round" fill="none"/></svg><div class="report-axis"><span><?php echo esc_html( $job['range']['from'] ); ?></span><span><?php echo esc_html( $job['range']['to'] ); ?></span></div></section><section class="report-card"><h2><?php esc_html_e( 'Order status', 'dexo' ); ?></h2><div class="report-statuses"><?php
	foreach ( $job['statuses'] as $status => $amount ) {
		echo '<div><span>' . esc_html( wc_get_order_status_name( $status ) ) . '</span><b>' . esc_html( number_format_i18n( $amount ) ) . '</b><meter min="0" max="' . esc_attr( max( 1, $job['orders'] ) ) . '" value="' . esc_attr( $amount ) . '">' . esc_html( $amount ) . '</meter></div>';
	}
	if ( ! $job['orders'] ) { echo '<p>' . esc_html__( 'No orders in this period.', 'dexo' ) . '</p>'; }
	?></div></section></div><section class="report-card"><h2><?php esc_html_e( 'Best-selling products', 'dexo' ); ?></h2><div class="report-products"><?php
	uasort( $job['products'], static fn( $a, $b ) => $b['qty'] <=> $a['qty'] );
	foreach ( array_slice( $job['products'], 0, 5 ) as $i => $product ) {
		echo '<div><span class="report-rank">' . esc_html( $i + 1 ) . '</span><b>' . esc_html( $product['name'] ) . '</b><span>' . esc_html( sprintf( __( '%s units', 'dexo' ), number_format_i18n( $product['qty'] ) ) ) . '</span></div>';
	}
	if ( ! $job['products'] ) { echo '<p>' . esc_html__( 'No paid products in this period.', 'dexo' ) . '</p>'; }
	?></div></section><details class="report-card"><summary><?php esc_html_e( 'Daily figures and calculation details', 'dexo' ); ?></summary><p><?php esc_html_e( 'Orders are grouped by their creation date in the site timezone. Refunds recorded by refresh time reduce the original order period. This is a live operational report, not an accounting statement.', 'dexo' ); ?></p><p><?php echo esc_html( sprintf( __( 'Updated: %s · Site timezone: %s', 'dexo' ), wp_date( 'Y-m-d H:i', $job['finished'] ), wp_timezone_string() ) ); ?></p><?php if ( $job['foreign'] ) { ?><p><?php echo esc_html( sprintf( __( '%s orders in other currencies were excluded.', 'dexo' ), number_format_i18n( $job['foreign'] ) ) ); ?></p><?php } ?><div class="table-wrap"><table><thead><tr><th><?php esc_html_e( 'Date', 'dexo' ); ?></th><th><?php esc_html_e( 'Orders', 'dexo' ); ?></th><th><?php esc_html_e( 'Net sales', 'dexo' ); ?></th><th><?php esc_html_e( 'Refunds', 'dexo' ); ?></th></tr></thead><tbody><?php foreach ( $job['days'] as $date => $day ) { echo '<tr><td>' . esc_html( $date ) . '</td><td>' . esc_html( number_format_i18n( $day['orders'] ) ) . '</td><td>' . report_money( $day['sales'] ) . '</td><td>' . report_money( $day['refunds'] ) . '</td></tr>'; } ?></tbody></table></div></details><?php
}
function render_reports(): void {
	if ( ! current_user_can( 'view_woocommerce_reports' ) ) { return; }
	try { $range = report_range( $_GET ); } catch ( \InvalidArgumentException $e ) { echo '<p class="notice error">' . esc_html( $e->getMessage() ) . '</p>'; $range = report_range( array() ); }
	?><div class="report-intro"><div><span class="report-eyebrow"><?php esc_html_e( 'Your store, in perspective', 'dexo' ); ?></span><h2><?php esc_html_e( 'See what is moving your business.', 'dexo' ); ?></h2></div><form method="get" class="report-filters"><input type="hidden" name="dexo" value="1"><input type="hidden" name="tab" value="reports"><label><?php esc_html_e( 'From', 'dexo' ); ?><input type="date" name="from" value="<?php echo esc_attr( $range['from'] ); ?>" required></label><label><?php esc_html_e( 'To', 'dexo' ); ?><input type="date" name="to" value="<?php echo esc_attr( $range['to'] ); ?>" required max="<?php echo esc_attr( wp_date( 'Y-m-d' ) ); ?>"></label><button class="button primary"><?php esc_html_e( 'Apply', 'dexo' ); ?></button></form></div><nav class="report-presets" aria-label="<?php esc_attr_e( 'Report period', 'dexo' ); ?>"><?php foreach ( array( 7, 30, 90 ) as $days ) { echo '<a href="' . esc_url( panel_url( array( 'tab' => 'reports', 'from' => wp_date( 'Y-m-d', ( new \DateTimeImmutable( 'today', wp_timezone() ) )->modify( '-' . ( $days - 1 ) . ' days' )->getTimestamp() ), 'to' => wp_date( 'Y-m-d' ) ) ) ) . '">' . esc_html( sprintf( __( 'Last %s days', 'dexo' ), number_format_i18n( $days ) ) ) . '</a>'; } ?></nav><?php
	try { render_report_results( report_for_range( $range ) ); } catch ( \Throwable $e ) { echo '<p class="notice error">' . esc_html__( 'Could not load the report. Please try again.', 'dexo' ) . '</p>'; }
}
function render_overview(): void {
	?><section class="overview-welcome"><div><span class="report-eyebrow"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></span><h2><?php echo esc_html( sprintf( __( 'Hello, %s.', 'dexo' ), wp_get_current_user()->display_name ) ); ?></h2><p><?php esc_html_e( 'A clear view. A calmer workday.', 'dexo' ); ?></p></div><span class="overview-date"><?php echo esc_html( wp_date( 'l، j F Y' ) ); ?></span></section><nav class="overview-shortcuts" aria-label="<?php esc_attr_e( 'Quick access', 'dexo' ); ?>"><?php foreach ( sections() as $slug => $section ) { if ( ! in_array( $slug, array( 'orders', 'products', 'reports', 'posts', 'users', 'general', 'profile' ), true ) ) { continue; } echo '<a href="' . esc_url( panel_url( array( 'tab' => $slug ) ) ) . '">'; icon( $slug, 19 ); echo '<span>' . esc_html( $section['label'] ) . '</span>'; icon( 'chevron', 14 ); echo '</a>'; } ?></nav><?php
	if ( current_user_can( 'view_woocommerce_reports' ) ) {
		echo '<div class="report-card-heading overview-period"><h2>' . esc_html__( 'The last 30 days at a glance', 'dexo' ) . '</h2><a href="' . esc_url( panel_url( array( 'tab' => 'reports' ) ) ) . '">' . esc_html__( 'View reports', 'dexo' ) . ' ←</a></div>';
		try { render_report_results( report_for_range( report_range( array() ) ) ); } catch ( \Throwable $e ) { echo '<p class="notice error">' . esc_html__( 'Could not load the report. Please try again.', 'dexo' ) . '</p>'; }
	}
	?><div class="report-grid overview-work"><?php if ( can( 'dexo_orders_read' ) ) { ?><section class="report-card"><div class="report-card-heading"><h2><?php esc_html_e( 'Orders to work on', 'dexo' ); ?></h2><a href="<?php echo esc_url( panel_url( array( 'tab' => 'orders' ) ) ); ?>"><?php esc_html_e( 'View all', 'dexo' ); ?></a></div><?php
		$orders = wc_get_orders( array( 'type' => 'shop_order', 'status' => array( 'wc-processing', 'wc-on-hold', 'wc-pending' ), 'limit' => 5, 'orderby' => 'date', 'order' => 'DESC' ) );
		foreach ( $orders as $order ) { echo '<a class="overview-row" href="' . esc_url( panel_url( array( 'tab' => 'orders', 'id' => $order->get_id() ) ) ) . '"><b>#' . esc_html( $order->get_order_number() ) . '</b><span>' . esc_html( wc_get_order_status_name( $order->get_status() ) ) . '</span>'; if ( can( 'dexo_orders_totals' ) ) { echo '<strong>' . money( $order->get_total(), $order->get_currency() ) . '</strong>'; } echo '</a>'; }
		if ( ! $orders ) { echo '<p>' . esc_html__( 'Everything is up to date.', 'dexo' ) . '</p>'; }
	?></section><?php } if ( can( 'dexo_products_stock' ) ) { ?><section class="report-card"><h2><?php esc_html_e( 'Stock to check', 'dexo' ); ?></h2><?php
		$products = wc_get_products( array( 'limit' => 5, 'stock_status' => 'outofstock', 'status' => 'publish' ) );
		foreach ( $products as $product ) { echo '<a class="overview-row" href="' . esc_url( panel_url( array( 'tab' => 'products', 'id' => $product->get_id() ) ) ) . '"><b>' . esc_html( $product->get_name() ) . '</b><span>' . esc_html__( 'Out of stock', 'dexo' ) . '</span></a>'; }
		if ( ! $products ) { echo '<p>' . esc_html__( 'No published products are out of stock.', 'dexo' ) . '</p>'; }
	?></section><?php } ?></div><?php
}
