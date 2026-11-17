<?php

namespace Dexo;

defined( 'ABSPATH' ) || exit;

function valid_menu_url( string $url ): bool {
	if ( '' === $url || esc_url_raw( $url ) !== $url ) { return false; }
	if ( in_array( $url[0], array( '/', '#', '?' ), true ) ) { return true; }
	$parts = wp_parse_url( $url );
	if ( ! is_array( $parts ) || ! in_array( $parts['scheme'] ?? '', wp_allowed_protocols(), true ) ) { return false; }
	return in_array( $parts['scheme'], array( 'http', 'https' ), true ) ? ! empty( $parts['host'] ) : ! empty( $parts['path'] );
}

function menu_items( int $id ): array {
	return wp_get_nav_menu_items( $id, array( 'post_status' => 'publish' ) ) ?: array();
}

function classic_menu_state( int $id ): array {
	$menu = wp_get_nav_menu_object( $id );
	return array( $menu ? $menu->name : '', array_map( static fn( $item ) => array( $item->ID, $item->title, $item->url, $item->menu_item_parent, $item->menu_order ), menu_items( $id ) ), get_theme_mod( 'nav_menu_locations', array() ) );
}

function block_menu_nodes( array $blocks, string $prefix = '', string $parent = '' ): array|\WP_Error {
	$out = array();
	foreach ( $blocks as $index => $block ) {
		if ( null === $block['blockName'] && '' === trim( $block['innerHTML'] ) ) { continue; }
		if ( ! in_array( $block['blockName'], array( 'core/navigation-link', 'core/navigation-submenu' ), true ) || trim( $block['innerHTML'] ) ) {
			return form_error( __( 'This navigation contains advanced blocks. Use the Site Editor to preserve them.', 'dexo' ) );
		}
		$key = $prefix . $index;
		$out[ $key ] = array( 'parent' => $parent, 'attrs' => $block['attrs'], 'type' => $block['blockName'] );
		$children = block_menu_nodes( $block['innerBlocks'], $key . '.', $key );
		if ( is_wp_error( $children ) ) { return $children; }
		$out += $children;
	}
	return $out;
}

function build_navigation_blocks( array $nodes, string $parent = '' ): array {
	$blocks = array();
	foreach ( $nodes as $key => $node ) {
		if ( $node['parent'] !== $parent ) { continue; }
		$children = build_navigation_blocks( $nodes, (string) $key );
		$blocks[] = array(
			'blockName' => $children ? 'core/navigation-submenu' : 'core/navigation-link',
			'attrs' => $node['attrs'], 'innerBlocks' => $children, 'innerHTML' => '',
			'innerContent' => array_fill( 0, count( $children ), null ),
		);
	}
	return $blocks;
}

function render_menus( int $id ): void {
	$kind = 'block' === request_text( $_GET, 'kind' ) ? 'block' : 'classic';
	echo '<section class="card"><h2>' . esc_html__( 'Navigation menus', 'dexo' ) . '</h2><ul class="variations">';
	foreach ( wp_get_nav_menus() as $menu ) {
		echo '<li><a href="' . esc_url( panel_url( array( 'tab' => 'menus', 'id' => $menu->term_id ) ) ) . '">' . esc_html( $menu->name ) . ' · ' . esc_html__( 'Classic menu', 'dexo' ) . '</a></li>';
	}
	foreach ( get_posts( array( 'post_type' => 'wp_navigation', 'post_status' => 'publish', 'numberposts' => -1 ) ) as $menu ) {
		echo '<li><a href="' . esc_url( panel_url( array( 'tab' => 'menus', 'id' => $menu->ID, 'kind' => 'block' ) ) ) . '">' . esc_html( $menu->post_title ) . ' · ' . esc_html__( 'Block navigation', 'dexo' ) . '</a></li>';
	}
	echo '</ul></section>';
	if ( ! $id ) {
		begin_form( 'menus', 0, 'create' );
		input_field( 'name', __( 'Menu name', 'dexo' ), '', 'text', true );
		select_field( 'menu_kind', __( 'Menu type', 'dexo' ), wp_is_block_theme() ? 'block' : 'classic', array( 'classic' => __( 'Classic menu', 'dexo' ), 'block' => __( 'Block navigation', 'dexo' ) ) );
		end_form( __( 'Create menu', 'dexo' ) );
		return;
	}
	if ( 'block' === $kind ) { render_block_menu( $id ); return; }
	$menu = wp_get_nav_menu_object( $id );
	if ( ! $menu ) { return; }
	$items = menu_items( $id );
	$state = classic_menu_state( $id );
	begin_form( 'menus', $id, 'rename' );
	revision_input( $state );
	input_field( 'name', __( 'Menu name', 'dexo' ), $menu->name, 'text', true );
	end_form();
	$parents = array( 0 => __( 'Top level', 'dexo' ) );
	foreach ( $items as $item ) { $parents[ $item->ID ] = $item->title . ' (#' . $item->ID . ')'; }
	foreach ( $items as $item ) {
		begin_form( 'menus', $id, 'item' );
		revision_input( $state );
		echo '<input type="hidden" name="item_id" value="' . esc_attr( $item->ID ) . '">';
		input_field( 'title', __( 'Link text', 'dexo' ), $item->title, 'text', true );
		if ( 'custom' === $item->type ) { input_field( 'url', __( 'Link URL', 'dexo' ), $item->url, 'text', true ); }
		select_field( 'parent', __( 'Parent item', 'dexo' ), $item->menu_item_parent, array_diff_key( $parents, array( $item->ID => true ) ) );
		input_field( 'position', __( 'Position', 'dexo' ), $item->menu_order, 'number', true );
		checkbox_field( 'remove', __( 'Remove this menu item', 'dexo' ), false );
		end_form();
	}
	begin_form( 'menus', $id, 'item' );
	revision_input( $state );
	echo '<h3>' . esc_html__( 'Add link', 'dexo' ) . '</h3>';
	input_field( 'title', __( 'Link text', 'dexo' ), '', 'text', true );
	input_field( 'url', __( 'Link URL', 'dexo' ), '', 'text', true );
	select_field( 'parent', __( 'Parent item', 'dexo' ), 0, $parents );
	input_field( 'position', __( 'Position', 'dexo' ), count( $items ) + 1, 'number', true );
	end_form( __( 'Add link', 'dexo' ) );
	$locations = get_registered_nav_menus();
	if ( $locations ) {
		begin_form( 'menus', $id, 'location' );
		revision_input( $state );
		select_field( 'location', __( 'Theme location', 'dexo' ), '', array( '' => __( 'Select a location', 'dexo' ) ) + $locations );
		end_form( __( 'Assign menu', 'dexo' ) );
	} else {
		echo '<p class="card">' . esc_html__( 'This theme uses navigation blocks. Place its navigation in the Site Editor.', 'dexo' ) . '</p>';
	}
}

function render_block_menu( int $id ): void {
	$post = get_post( $id );
	if ( ! $post || 'wp_navigation' !== $post->post_type || ! current_user_can( 'edit_post', $id ) ) { return; }
	$nodes = block_menu_nodes( parse_blocks( $post->post_content ) );
	begin_form( 'menus', $id, 'rename' );
	revision_input( array( $post->post_title, $post->post_content ) );
	echo '<input type="hidden" name="menu_kind" value="block">';
	input_field( 'name', __( 'Menu name', 'dexo' ), $post->post_title, 'text', true );
	end_form();
	if ( is_wp_error( $nodes ) ) { echo '<p class="card">' . esc_html( $nodes->get_error_message() ) . '</p>'; }
	else {
		$parents = array( '' => __( 'Top level', 'dexo' ) );
		foreach ( $nodes as $key => $node ) { $parents[ $key ] = wp_strip_all_tags( $node['attrs']['label'] ?? '' ) . ' (' . $key . ')'; }
		$records = $nodes + array( 'new' => array( 'parent' => '', 'attrs' => array() ) );
		$positions = array();
		foreach ( $records as $key => $node ) {
			$position = ( $positions[ $node['parent'] ] ?? 0 ) + 1;
			if ( 'new' !== $key ) { $positions[ $node['parent'] ] = $position; }
			begin_form( 'menus', $id, 'block-item' );
			revision_input( array( $post->post_title, $post->post_content ) );
			echo '<input type="hidden" name="menu_kind" value="block"><input type="hidden" name="node" value="' . esc_attr( (string) $key ) . '">';
			input_field( 'title', __( 'Link text', 'dexo' ), wp_strip_all_tags( $node['attrs']['label'] ?? '' ), 'text', true );
			input_field( 'url', __( 'Link URL', 'dexo' ), $node['attrs']['url'] ?? '', 'text', true );
			$allowed = array_filter( $parents, static fn( $p ) => (string) $p !== (string) $key && ! str_starts_with( (string) $p, $key . '.' ), ARRAY_FILTER_USE_KEY );
			select_field( 'parent_key', __( 'Parent item', 'dexo' ), $node['parent'], $allowed );
			input_field( 'position', __( 'Position among siblings', 'dexo' ), $position, 'number', true );
			if ( 'new' !== $key ) { checkbox_field( 'remove', __( 'Remove item and its child links', 'dexo' ), false ); }
			end_form( 'new' === $key ? __( 'Add link', 'dexo' ) : '' );
		}
	}
	echo '<p class="card">' . esc_html__( 'Place this navigation in your header or footer using the Site Editor.', 'dexo' ) . ' <a class="button secondary" href="' . esc_url( admin_url( 'site-editor.php?postType=wp_navigation&postId=' . $id ) ) . '">' . esc_html__( 'Open Site Editor', 'dexo' ) . '</a></p>';
}

function save_menus( int $id, array $form ): int|\WP_Error|null {
	$action = request_text( $form, 'action' );
	$kind = request_text( $form, 'menu_kind' );
	if ( 'block' === $kind ) { return save_block_menu( $id, $form ); }
	if ( 'create' === $action && ! $id ) {
		$name = request_text( $form, 'name' );
		if ( '' === $name ) { return form_error( __( 'Enter a name.', 'dexo' ) ); }
		$result = wp_create_nav_menu( wp_slash( $name ) );
		return is_wp_error( $result ) ? admin_error( $result ) : $result;
	}
	$menu = wp_get_nav_menu_object( $id );
	if ( ! $menu ) { return form_error( __( 'This record is unavailable.', 'dexo' ), 404 ); }
	if ( $error = check_revision( $form, classic_menu_state( $id ) ) ) { return $error; }
	if ( 'rename' === $action ) {
		$name = request_text( $form, 'name' );
		if ( ! $name ) { return form_error( __( 'Enter a name.', 'dexo' ) ); }
		$result = wp_update_nav_menu_object( $id, wp_slash( array( 'menu-name' => $name ) ) );
		return is_wp_error( $result ) ? admin_error( $result ) : null;
	}
	if ( 'location' === $action ) {
		$location = request_text( $form, 'location' );
		if ( ! isset( get_registered_nav_menus()[ $location ] ) ) { return form_error( __( 'Select a valid option.', 'dexo' ) ); }
		$locations = get_theme_mod( 'nav_menu_locations', array() );
		$locations[ $location ] = $id;
		set_theme_mod( 'nav_menu_locations', $locations );
		return null;
	}
	if ( 'item' !== $action ) { return form_error( __( 'Select a valid option.', 'dexo' ) ); }
	$items = array();
	foreach ( menu_items( $id ) as $item ) { $items[ $item->ID ] = $item; }
	$item_id = request_id( $form, 'item_id' );
	if ( $item_id && ! isset( $items[ $item_id ] ) ) { return form_error( __( 'This record is unavailable.', 'dexo' ), 404 ); }
	if ( '1' === request_text( $form, 'remove' ) && $item_id ) {
		return wp_delete_post( $item_id, true ) ? null : form_error( __( 'WordPress could not save this record.', 'dexo' ), 500 );
	}
	$parent = request_id( $form, 'parent' );
	$seen = array();
	for ( $ancestor = $parent; $ancestor; $ancestor = (int) $items[ $ancestor ]->menu_item_parent ) {
		if ( ! isset( $items[ $ancestor ] ) || $ancestor === $item_id || isset( $seen[ $ancestor ] ) ) { return form_error( __( 'Select a valid parent item.', 'dexo' ) ); }
		$seen[ $ancestor ] = true;
	}
	$title = request_text( $form, 'title' );
	$url = request_text( $form, 'url' );
	$position = request_id( $form, 'position' );
	if ( ! $title || ! $position || $position > 10000 ) { return form_error( __( 'Enter a label and a valid position.', 'dexo' ) ); }
	$data = array();
	if ( $item_id ) {
		$item = $items[ $item_id ];
		$data = array( 'menu-item-object-id' => $item->object_id, 'menu-item-object' => $item->object, 'menu-item-type' => $item->type, 'menu-item-url' => $item->url, 'menu-item-target' => $item->target, 'menu-item-classes' => implode( ' ', $item->classes ), 'menu-item-xfn' => $item->xfn, 'menu-item-description' => $item->description, 'menu-item-attr-title' => $item->attr_title );
	}
	if ( ! $item_id || 'custom' === $items[ $item_id ]->type ) {
		if ( ! valid_menu_url( $url ) ) { return form_error( __( 'Enter a valid link URL.', 'dexo' ) ); }
		$data['menu-item-url'] = esc_url_raw( $url );
		if ( ! $item_id ) { $data['menu-item-type'] = 'custom'; }
	}
	$data += array( 'menu-item-title' => $title, 'menu-item-parent-id' => $parent, 'menu-item-position' => $position, 'menu-item-status' => 'publish' );
	$result = wp_update_nav_menu_item( $id, $item_id, wp_slash( $data ) );
	return is_wp_error( $result ) ? admin_error( $result ) : null;
}

function save_block_menu( int $id, array $form ): int|\WP_Error|null {
	$action = request_text( $form, 'action' );
	if ( ! $id && 'create' === $action ) {
		$name = request_text( $form, 'name' );
		if ( ! $name ) { return form_error( __( 'Enter a name.', 'dexo' ) ); }
		$result = wp_insert_post( wp_slash( array( 'post_type' => 'wp_navigation', 'post_status' => 'publish', 'post_title' => $name ) ), true );
		return is_wp_error( $result ) ? admin_error( $result ) : $result;
	}
	$post = get_post( $id );
	if ( ! $post || 'wp_navigation' !== $post->post_type || ! current_user_can( 'edit_post', $id ) ) { return form_error( __( 'This record is unavailable.', 'dexo' ), 404 ); }
	if ( $error = check_revision( $form, array( $post->post_title, $post->post_content ) ) ) { return $error; }
	$data = array( 'ID' => $id );
	if ( 'rename' === $action ) {
		$name = request_text( $form, 'name' );
		if ( ! $name ) { return form_error( __( 'Enter a name.', 'dexo' ) ); }
		$data['post_title'] = $name;
	} elseif ( 'block-item' === $action ) {
		$nodes = block_menu_nodes( parse_blocks( $post->post_content ) );
		if ( is_wp_error( $nodes ) ) { return $nodes; }
		$key = request_text( $form, 'node' );
		if ( 'new' !== $key && ! isset( $nodes[ $key ] ) ) { return form_error( __( 'This record is unavailable.', 'dexo' ), 404 ); }
		if ( '1' === request_text( $form, 'remove' ) && 'new' !== $key ) {
			$nodes = array_filter( $nodes, static fn( $node_key ) => (string) $node_key !== $key && ! str_starts_with( (string) $node_key, $key . '.' ), ARRAY_FILTER_USE_KEY );
		} else {
			$parent = request_text( $form, 'parent_key' );
			if ( '' !== $parent && ( ! isset( $nodes[ $parent ] ) || $parent === $key || str_starts_with( $parent, $key . '.' ) ) ) { return form_error( __( 'Select a valid parent item.', 'dexo' ) ); }
			$title = request_text( $form, 'title' );
			$url = request_text( $form, 'url' );
			$position = request_id( $form, 'position' );
			if ( ! $title || ! valid_menu_url( $url ) || ! $position || $position > 10000 ) { return form_error( __( 'Enter a label, valid link URL, and position.', 'dexo' ) ); }
			$node = $nodes[ $key ] ?? array( 'attrs' => array( 'kind' => 'custom', 'type' => 'custom' ) );
			$node['attrs']['label'] = $title;
			$node['attrs']['url'] = esc_url_raw( $url );
			$node['parent'] = $parent;
			unset( $nodes[ $key ] );
			$siblings = 0;
			$ordered = array();
			$inserted = false;
			foreach ( $nodes as $node_key => $existing ) {
				if ( $existing['parent'] === $parent && ++$siblings === $position ) { $ordered[ $key ] = $node; $inserted = true; }
				$ordered[ $node_key ] = $existing;
			}
			if ( ! $inserted ) { $ordered[ $key ] = $node; }
			$nodes = $ordered;
		}
		$data['post_content'] = serialize_blocks( build_navigation_blocks( $nodes ) );
	} else { return form_error( __( 'Select a valid option.', 'dexo' ) ); }
	$result = wp_update_post( wp_slash( $data ), true );
	return is_wp_error( $result ) ? admin_error( $result ) : null;
}
