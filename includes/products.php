<?php

namespace Dexo;

defined( 'ABSPATH' ) || exit;

function get_product( int $id ): ?\WC_Product {
	$product = $id ? wc_get_product( $id ) : false;
	if ( ! $product || ! in_array( $product->get_status(), array( 'publish', 'private', 'draft', 'pending', 'future' ), true ) ) {
		return null;
	}
	if ( $product->is_type( 'variation' ) && ! get_product( $product->get_parent_id() ) ) {
		return null;
	}
	return $product;
}

function render_products( int $id, bool $print ): void {
	if ( $id ) {
		$product = get_product( $id );
		if ( ! $product ) {
			echo '<p class="empty">' . esc_html__( 'Product unavailable.', 'dexo' ) . '</p>';
			return;
		}
		render_product( $product );
		return;
	}

	$page = max( 1, request_id( $_GET, 'dx_page' ) );
	$query = request_text( $_GET, 'q' );
	$sku_id = '' !== $query ? (int) wc_get_product_id_by_sku( $query ) : 0;
	$exact = get_product( $sku_id );
	$results = new \WP_Query( array(
		'post_type' => 'product',
		'post_status' => array( 'publish', 'private', 'draft', 'pending', 'future' ),
		'posts_per_page' => 20,
		'paged' => $page,
		's' => $query,
		'orderby' => 'date',
		'order' => 'DESC',
		'fields' => 'ids',
	) );
	$ids = $results->posts;
	if ( $exact && 1 === $page && ! in_array( $sku_id, $ids, true ) ) {
		array_unshift( $ids, $sku_id );
	}
	?>
<form method="get" action="<?php echo esc_url( panel_url() ); ?>" class="filters">
	<?php hidden_route( 'products' ); ?>
	<label><?php esc_html_e( 'Product name or exact SKU', 'dexo' ); ?><input name="q" type="search" value="<?php echo esc_attr( $query ); ?>"></label>
	<button><?php esc_html_e( 'Find products', 'dexo' ); ?></button>
</form>
<?php if ( ! $ids ) : ?>
	<p class="empty"><?php esc_html_e( 'No products found.', 'dexo' ); ?></p>
<?php else : ?>
	<div class="table-wrap"><table>
		<thead><tr>
			<th scope="col"><?php esc_html_e( 'Product', 'dexo' ); ?></th>
			<th scope="col"><?php esc_html_e( 'SKU', 'dexo' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Stock', 'dexo' ); ?></th>
			<?php if ( can( 'dexo_products_prices' ) ) : ?><th scope="col"><?php esc_html_e( 'Price', 'dexo' ); ?></th><?php endif; ?>
		</tr></thead>
		<tbody>
			<?php foreach ( $ids as $product_id ) : ?>
				<?php $product = get_product( $product_id ); if ( ! $product ) { continue; } ?>
				<tr>
					<th scope="row"><a href="<?php echo esc_url( panel_url( array( 'tab' => 'products', 'id' => $product_id ) ) ); ?>"><?php echo esc_html( $product->get_name() ); ?></a></th>
					<td><bdi><?php echo esc_html( $product->get_sku() ?: '—' ); ?></bdi></td>
					<td><?php echo esc_html( stock_label( $product ) ); ?></td>
					<?php if ( can( 'dexo_products_prices' ) ) : ?><td><?php echo '' !== $product->get_price() ? money( $product->get_price() ) : '—'; ?></td><?php endif; ?>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table></div>
<?php endif; ?>
	<?php
	pagination( array( 'tab' => 'products', 'q' => $query ), $page, (int) $results->max_num_pages );
}

function stock_label( \WC_Product $product ): string {
	$qty = $product->get_stock_quantity();
	$options = wc_get_product_stock_status_options();
	return null !== $qty ? number_format_i18n( $qty ) : ( $options[ $product->get_stock_status() ] ?? '—' );
}

function stock_inherited( \WC_Product $product ): bool {
	return $product->is_type( 'variation' ) && (int) $product->get_stock_managed_by_id() !== $product->get_id();
}

function render_product( \WC_Product $product ): void {
	$id = $product->get_id();
	$args = array( 'tab' => 'products', 'id' => $id );
	$can_price = can( 'dexo_products_prices' ) && $product->is_type( array( 'simple', 'variation' ) );
	$can_stock = can( 'dexo_products_stock' ) && $product->is_type( array( 'simple', 'variable', 'variation' ) );
	?>
<div class="detail-header">
	<div>
		<a class="back" href="<?php echo esc_url( panel_url( array( 'tab' => 'products' ) ) ); ?>"><?php esc_html_e( 'All products', 'dexo' ); ?></a>
		<h2><?php echo esc_html( $product->get_name() ); ?></h2>
		<?php if ( $product->get_sku() ) : ?><p class="muted"><?php esc_html_e( 'SKU', 'dexo' ); ?> <bdi><?php echo esc_html( $product->get_sku() ); ?></bdi></p><?php endif; ?>
	</div>
</div>
<div class="detail-grid">
	<?php if ( $can_price ) : ?>
		<form method="post" action="<?php echo esc_url( panel_url( $args ) ); ?>" class="card edit">
			<h3><?php esc_html_e( 'Pricing', 'dexo' ); ?></h3>
			<?php edit_form_nonce( 'products', $id ); ?>
			<input type="hidden" name="change" value="price">
			<input type="hidden" name="previous" value="<?php echo esc_attr( $product->get_regular_price( 'edit' ) ); ?>">
			<label><?php esc_html_e( 'Regular price', 'dexo' ); ?> <bdi>(<?php echo esc_html( get_woocommerce_currency() ); ?>)</bdi>
				<input name="price" inputmode="decimal" maxlength="22" value="<?php echo esc_attr( wc_format_localized_price( $product->get_regular_price( 'edit' ) ) ); ?>">
			</label>
			<button><?php esc_html_e( 'Save price', 'dexo' ); ?></button>
		</form>
	<?php endif; ?>
	<?php if ( $can_stock && stock_inherited( $product ) ) : ?>
		<section class="card"><h3><?php esc_html_e( 'Inventory', 'dexo' ); ?></h3>
			<p><?php esc_html_e( 'This variation uses the parent product’s stock.', 'dexo' ); ?></p>
			<a href="<?php echo esc_url( panel_url( array( 'tab' => 'products', 'id' => $product->get_parent_id() ) ) ); ?>"><?php esc_html_e( 'Open parent product', 'dexo' ); ?></a>
		</section>
	<?php elseif ( $can_stock && ( ! $product->is_type( 'variable' ) || $product->managing_stock() ) ) : ?>
		<form method="post" action="<?php echo esc_url( panel_url( $args ) ); ?>" class="card edit">
			<h3><?php esc_html_e( 'Inventory', 'dexo' ); ?></h3>
			<?php edit_form_nonce( 'products', $id ); ?>
			<?php if ( $product->managing_stock() ) : ?>
				<input type="hidden" name="change" value="quantity">
				<input type="hidden" name="previous" value="<?php echo esc_attr( $product->get_stock_quantity() ); ?>">
				<label><?php esc_html_e( 'Stock quantity', 'dexo' ); ?><input name="quantity" inputmode="numeric" maxlength="11" required value="<?php echo esc_attr( $product->get_stock_quantity() ); ?>"></label>
			<?php else : ?>
				<input type="hidden" name="change" value="stock_status">
				<input type="hidden" name="previous" value="<?php echo esc_attr( $product->get_stock_status() ); ?>">
				<label><?php esc_html_e( 'Stock status', 'dexo' ); ?><select name="stock_status">
					<?php foreach ( wc_get_product_stock_status_options() as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $product->get_stock_status(), $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select></label>
			<?php endif; ?>
			<button><?php esc_html_e( 'Save inventory', 'dexo' ); ?></button>
		</form>
	<?php endif; ?>
</div>
<?php if ( $product->is_type( 'variable' ) ) : ?>
	<section class="card">
		<h3><?php esc_html_e( 'Variations', 'dexo' ); ?></h3>
		<ul class="variations">
		<?php foreach ( $product->get_children() as $child_id ) : ?>
			<?php $child = get_product( $child_id ); if ( ! $child ) { continue; } ?>
			<li><a href="<?php echo esc_url( panel_url( array( 'tab' => 'products', 'id' => $child_id ) ) ); ?>"><?php echo esc_html( $child->get_name() ); ?></a></li>
		<?php endforeach; ?>
		</ul>
	</section>
<?php endif; ?>
	<?php
}

function submit_product( int $id, array $form ): ?\WP_Error {
	$change = request_text( $form, 'change' );
	$permissions = array(
		'price' => can( 'dexo_products_prices' ),
		'quantity' => can( 'dexo_products_stock' ),
		'stock_status' => can( 'dexo_products_stock' ),
	);
	if ( empty( $permissions[ $change ] ) ) {
		return form_error( __( 'You cannot make this change.', 'dexo' ), 403 );
	}
	$product = get_product( $id );
	if ( ! $product ) {
		return form_error( __( 'Product unavailable.', 'dexo' ), 404 );
	}

	$previous = request_text( $form, 'previous' );
	try {
		if ( 'price' === $change ) {
			if ( ! $product->is_type( array( 'simple', 'variation' ) ) ) {
				return form_error( __( 'Regular prices can be edited for simple products and individual variations.', 'dexo' ) );
			}
			$raw = str_replace( array( wc_get_price_decimal_separator(), '٫' ), '.', ascii_digits( request_text( $form, 'price' ) ) );
			if ( ! isset( $form['price'] ) || ! is_string( $form['price'] ) || ( '' !== $raw && ! preg_match( '/^\d{1,12}(?:\.\d{1,6})?$/D', $raw ) ) ) {
				return form_error( __( 'Enter a non-negative price without thousands separators.', 'dexo' ) );
			}
			$price = '' === $raw ? '' : wc_format_decimal( $raw, wc_get_price_decimals(), true );
			if ( $previous !== $product->get_regular_price( 'edit' ) ) {
				return product_conflict();
			}
			$sale = $product->get_sale_price( 'edit' );
			if ( '' !== $sale && ( '' === $price || (float) $price <= (float) $sale ) ) {
				return form_error( __( 'This product has a sale price. Its regular price must stay above that price.', 'dexo' ) );
			}
			$product->set_regular_price( $price );
			$product->save();
		} elseif ( 'quantity' === $change ) {
			$raw = ascii_digits( request_text( $form, 'quantity' ) );
			if ( ! in_array( $product->get_type(), array( 'simple', 'variable', 'variation' ), true ) || ! $product->managing_stock() || stock_inherited( $product ) ) {
				return form_error( __( 'This product does not manage its own stock quantity.', 'dexo' ) );
			}
			if ( ! preg_match( '/^-?\d{1,9}$/D', $raw ) ) {
				return form_error( __( 'Enter a whole stock quantity.', 'dexo' ) );
			}
			$current = $product->get_stock_quantity();
			if ( $previous !== (string) $current ) {
				return product_conflict();
			}
			$delta = (int) $raw - ( $current ?? 0 );
			// Apply the adjustment atomically so a checkout after our read is preserved.
			$updated = 0 === $delta && null !== $current ? $current :
				wc_update_product_stock( $product, abs( $delta ), $delta < 0 ? 'decrease' : 'increase' );
			if ( false === $updated || null === $updated ) {
				return form_error( __( 'The stock quantity could not be saved.', 'dexo' ), 500 );
			}
		} else {
			$status = request_text( $form, 'stock_status' );
			if ( ! $product->is_type( array( 'simple', 'variation' ) ) || $product->managing_stock() ||
				stock_inherited( $product ) || ! isset( wc_get_product_stock_status_options()[ $status ] ) ) {
				return form_error( __( 'This stock status cannot be applied.', 'dexo' ) );
			}
			if ( $previous !== $product->get_stock_status() ) {
				return product_conflict();
			}
			$product->set_stock_status( $status );
			$product->save();
		}
	} catch ( \Exception $exception ) {
		return form_error( __( 'WooCommerce could not save the change. Reload the product and check its current state.', 'dexo' ), 500 );
	}
	do_action( 'dexo_product_updated', $product, $change, get_current_user_id() );
	return null;
}

function product_conflict(): \WP_Error {
	return form_error( __( 'Another update changed this product. Review the current values and try again.', 'dexo' ), 409 );
}
